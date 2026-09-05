<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignDispatch;
use App\Models\ChannelConnection;
use App\Jobs\ProcessCampaignBroadcastJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Exception;

class CampaignController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $campaigns = Campaign::where('tenant_id', $tenantId)
            ->with(['channelConnection', 'messageTemplate', 'flow'])
            ->latest()
            ->get();

        return response()->json($campaigns);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'channel_connection_id' => [
                'required',
                'integer',
                Rule::exists('channel_connections', 'id')->where('tenant_id', $tenantId)
            ],
            'audience_filter' => 'required|array',
            'audience_filter.type' => 'required|string|in:all,lifecycle_stage,tags,contacts',
            'audience_filter.value' => 'nullable',
            'source_type' => 'required|string|in:template,compose,custom,agent,flow',
            'message_template_id' => [
                'nullable',
                'integer',
                Rule::exists('message_templates', 'id')->where('tenant_id', $tenantId)
            ],
            'custom_subject' => 'nullable|string|max:255',
            'custom_message' => 'nullable|string',
            'media_url' => 'nullable|string|max:1000',
            'media_type' => 'nullable|string|in:image,video,document',
            'cta_button_text' => 'nullable|string|max:100',
            'cta_button_url' => 'nullable|string|max:1000',
            'ai_agent_id' => [
                'nullable',
                'integer',
            ],
            'flow_id' => [
                'nullable',
                'integer',
                Rule::exists('flows', 'id')->where('tenant_id', $tenantId)
            ],
            'schedule_type' => 'required|string|in:immediate,scheduled',
            'scheduled_at' => 'nullable|date',
        ]);

        $tenant = $request->user()->tenant;
        $currentCount = Campaign::where('tenant_id', $tenantId)->count();
        if ($tenant && !app(\App\Services\PlanLimitService::class)->canUseFeature($tenant, 'campaigns', $currentCount)) {
            $maxCampaigns = $tenant->plan ? $tenant->plan->max_campaigns : ($tenant->status === 'trial' ? 2 : 0);
            return response()->json([
                'message' => \App\Services\PlanLimitService::trans('MAX_CAMPAIGNS_LIMIT_REACHED', ['limit' => $maxCampaigns]),
                'code' => 'MAX_CAMPAIGNS_LIMIT_REACHED',
                'limit' => $maxCampaigns,
                'current' => $currentCount,
            ], 403);
        }

        try {
            $validated['tenant_id'] = $tenantId;
            $validated['status'] = $validated['schedule_type'] === 'immediate' ? 'sending' : 'scheduled';

            $campaign = Campaign::create($validated);

            if ($campaign->schedule_type === 'immediate') {
                ProcessCampaignBroadcastJob::dispatch($campaign->id);
            }

            return response()->json($campaign->load(['channelConnection', 'messageTemplate', 'flow']), 201);

        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to create campaign: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Upload an image or media asset for outbound campaign compose.
     */
    public function uploadMedia(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:jpeg,png,jpg,gif,webp,svg,mp4,pdf,doc,docx|max:20480',
        ]);

        try {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());
            $filename = 'campaign_' . uniqid() . '_' . time() . '.' . $extension;

            $destinationPath = public_path('uploads/campaigns');
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $file->move($destinationPath, $filename);
            $url = rtrim(config('app.url') ?: url('/'), '/') . '/uploads/campaigns/' . $filename;

            $mediaType = in_array($extension, ['jpeg', 'png', 'jpg', 'gif', 'webp', 'svg']) ? 'image' : (in_array($extension, ['mp4', 'mov', 'avi']) ? 'video' : 'document');

            return response()->json([
                'url' => $url,
                'media_type' => $mediaType,
                'filename' => $file->getClientOriginalName(),
            ]);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to upload media: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $campaign = Campaign::where('tenant_id', $tenantId)
            ->with(['channelConnection', 'messageTemplate', 'flow'])
            ->findOrFail($id);

        $dispatches = CampaignDispatch::where('campaign_id', $campaign->id)
            ->with(['contact', 'message'])
            ->latest()
            ->paginate(50);

        return response()->json([
            'campaign' => $campaign,
            'dispatches' => $dispatches,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $campaign = Campaign::where('tenant_id', $tenantId)->findOrFail($id);
        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted successfully.']);
    }
}
