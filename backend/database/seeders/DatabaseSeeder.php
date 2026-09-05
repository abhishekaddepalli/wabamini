<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed USD Currency
        $usd = DB::table('currencies')->where('code', 'USD')->first();
        if (!$usd) {
            $usdId = DB::table('currencies')->insertGetId([
                'code' => 'USD',
                'symbol' => '$',
                'name' => 'US Dollar',
                'is_active' => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $usdId = $usd->id;
        }

        // 2. Seed default system roles (tenant_id = null)
        $roles = [
            'owner' => [
                'display_name' => 'Owner',
                'permissions' => [
                    'dashboard' => ['view'],
                    'inbox' => ['read', 'write'],
                    'contacts' => ['read', 'write'],
                    'campaigns' => ['read', 'write'],
                    'flows' => ['read', 'write'],
                    'settings' => ['read', 'write'],
                ]
            ],
            'admin' => [
                'display_name' => 'Admin',
                'permissions' => [
                    'dashboard' => ['view'],
                    'inbox' => ['read', 'write'],
                    'contacts' => ['read', 'write'],
                    'campaigns' => ['read', 'write'],
                    'flows' => ['read', 'write'],
                    'settings' => ['read', 'write'],
                ]
            ],
            'manager' => [
                'display_name' => 'Manager',
                'permissions' => [
                    'dashboard' => ['view'],
                    'inbox' => ['read', 'write'],
                    'contacts' => ['read', 'write'],
                    'campaigns' => ['read', 'write'],
                    'flows' => ['read', 'write'],
                    'settings' => ['read'],
                ]
            ],
            'agent' => [
                'display_name' => 'Agent',
                'permissions' => [
                    'dashboard' => ['view'],
                    'inbox' => ['read', 'write'],
                    'contacts' => ['read'],
                    'campaigns' => [],
                    'flows' => [],
                    'settings' => [],
                ]
            ],
        ];

        foreach ($roles as $rName => $rMeta) {
            DB::table('roles')->updateOrInsert(
                ['tenant_id' => null, 'name' => $rName],
                [
                    'display_name' => $rMeta['display_name'],
                    'permissions' => json_encode($rMeta['permissions']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 4. Seed Super Admin Role & User
        $superAdminRole = DB::table('saas_admin_roles')->where('name', 'Super Admin')->first();
        if (!$superAdminRole) {
            $adminRoleId = DB::table('saas_admin_roles')->insertGetId([
                'name' => 'Super Admin',
                'permissions' => json_encode(['*']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $adminRoleId = $superAdminRole->id;
        }

        DB::table('saas_admins')->updateOrInsert(
            ['email' => 'admin@whatsomni.com'],
            [
                'role_id' => $adminRoleId,
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'password' => Hash::make('Password123!'),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 5. Seed Platform Settings
        $defaultPlatformSettings = [
            'ai_operational_model' => 'byok',
            'ai_providers_keys' => [
                'openai' => '',
                'anthropic' => '',
                'gemini' => '',
                'groq' => '',
                'deepseek' => '',
                'xai' => '',
                'mistral' => '',
                'openrouter' => '',
            ],
            'ai_feature_routing' => [
                'prompt_to_flow' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                'flow_ai_condition' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                'flow_ai_prompt' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                'flow_rag_query' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                'ai_agents' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                'inbox_smart_reply' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                'inbox_conversation_summary' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                'contact_sentiment_analysis' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                'knowledge_base_rag' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                'campaign_copywriter' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
            ],
            'branding_name' => 'WhatsOmni',
            'branding_tagline' => 'All-in-One Omnichannel Marketing, CRM & AI Automation Platform',
            'support_email' => 'support@whatsomni.com',
        ];

        foreach ($defaultPlatformSettings as $sKey => $sVal) {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => $sKey],
                [
                    'value' => is_array($sVal) ? json_encode($sVal) : json_encode($sVal),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 6. Seed Custom CMS Legal Pages
        $defaultPages = [
            [
                'slug' => 'terms',
                'title' => 'Terms & Conditions',
                'content' => '<h2>1. Acceptance of Terms</h2><p>By accessing and using WhatsOmni, you agree to be bound by these Terms of Service. If you do not agree to these terms, please do not use our services.</p><h2>2. Use of Service</h2><p>You agree to use WhatsOmni only for lawful purposes and in accordance with all applicable laws and regulations.</p>',
                'meta_description' => 'Official terms and conditions for using WhatsOmni platform services.',
                'is_published' => true,
            ],
            [
                'slug' => 'privacy',
                'title' => 'Privacy Policy',
                'content' => '<h2>1. Data We Collect</h2><p>We collect information you provide directly to us when creating an account, connecting messaging channels, or communicating with customer support.</p><h2>2. Data Security</h2><p>All sensitive credentials and API keys are protected using AES-256 encryption at rest.</p>',
                'meta_description' => 'Learn how WhatsOmni protects and manages your data privacy.',
                'is_published' => true,
            ],
            [
                'slug' => 'about',
                'title' => 'About Us',
                'content' => '<h2>About WhatsOmni</h2><p>WhatsOmni is the next-generation omnichannel marketing, sales CRM, and AI automation platform built for modern businesses, agencies, and SaaS operators.</p>',
                'meta_description' => 'Discover the story, mission, and technology behind WhatsOmni.',
                'is_published' => true,
            ],
        ];

        foreach ($defaultPages as $page) {
            DB::table('custom_pages')->updateOrInsert(
                ['slug' => $page['slug']],
                [
                    'title' => $page['title'],
                    'content' => $page['content'],
                    'meta_description' => $page['meta_description'],
                    'is_published' => $page['is_published'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}