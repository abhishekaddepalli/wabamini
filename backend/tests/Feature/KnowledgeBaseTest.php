<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Tenant;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeChunk;
use App\Jobs\IngestKnowledgeSourceJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'company_name' => 'Test Tenant Company'
        ]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Agent',
            'last_name' => 'Test',
            'email' => 'agent@test.com',
            'password' => bcrypt('password'),
            'status' => 'active'
        ]);
    }

    public function test_can_create_knowledge_base(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/knowledge-bases', [
                'name' => 'Company Handbook',
                'description' => 'Official rules and policies.'
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('name', 'Company Handbook');
        
        $this->assertDatabaseHas('knowledge_bases', [
            'name' => 'Company Handbook',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_can_add_source_and_run_ingest_job(): void
    {
        $kb = KnowledgeBase::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Product FAQ',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/knowledge-bases/{$kb->id}/sources", [
                'source_type' => 'qa',
                'question' => 'How to reset password?',
                'answer' => 'Click forgot password link on the login screen.'
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('knowledge_sources', [
            'knowledge_base_id' => $kb->id,
            'source_type' => 'qa',
        ]);
        $source = KnowledgeSource::first();

        // Run the ingestion job inline to simulate queue worker
        $job = new IngestKnowledgeSourceJob($source->id);
        app()->call([$job, 'handle']);

        // Assert chunks were generated and status updated to indexed
        $source->refresh();
        $this->assertEquals('indexed', $source->status);
        $this->assertGreaterThan(0, $source->chunks()->count());
    }

    public function test_can_query_knowledge_base_similarity(): void
    {
        $kb = KnowledgeBase::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Technical Manual',
        ]);

        $source1 = KnowledgeSource::create([
            'knowledge_base_id' => $kb->id,
            'source_type' => 'qa',
            'source_name' => 'SSH connection policy',
            'status' => 'indexed',
        ]);

        // Seed chunks
        $job = new IngestKnowledgeSourceJob($source1->id);
        
        KnowledgeChunk::create([
            'knowledge_source_id' => $source1->id,
            'chunk_index' => 0,
            'content' => 'To connect via SSH, use port 22 and your private key file.',
            'embedding' => app(\App\Services\EmbeddingService::class)->getEmbedding($this->tenant->id, 'To connect via SSH, use port 22 and your private key file.')
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/knowledge-bases/{$kb->id}/query", [
                'query' => 'To connect via SSH, use port 22 and your private key file.',
                'limit' => 2
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'query',
            'matches' => [
                '*' => ['chunk_id', 'source_name', 'source_type', 'content', 'similarity']
            ]
        ]);
        
        $matches = $response->json('matches');
        $this->assertCount(1, $matches);
        $this->assertGreaterThan(0.9, $matches[0]['similarity']);
    }

    public function test_can_update_knowledge_base(): void
    {
        $kb = KnowledgeBase::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Original Name',
            'description' => 'Original Description',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/knowledge-bases/{$kb->id}", [
                'name' => 'Updated Name',
                'description' => 'Updated Description',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('name', 'Updated Name');
        $response->assertJsonPath('description', 'Updated Description');

        $this->assertDatabaseHas('knowledge_bases', [
            'id' => $kb->id,
            'name' => 'Updated Name',
            'description' => 'Updated Description',
        ]);
    }
}
