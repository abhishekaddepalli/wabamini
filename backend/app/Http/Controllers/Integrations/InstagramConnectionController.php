<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ChannelConnection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class InstagramConnectionController extends Controller
{
    /**
     * Get active Instagram connection status and all connected Instagram accounts for the tenant.
     */
    public function status(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $connections = ChannelConnection::where('tenant_id', $tenantId)
            ->where('channel_type', 'instagram')
            ->orderBy('id', 'desc')
            ->get();

        $connectionsList = $connections->map(function ($conn) {
            $creds = $conn->decrypted_credentials;
            return [
                'id' => $conn->id,
                'name' => $conn->name,
                'status' => $conn->status,
                'instagram_business_account_id' => $creds['instagram_business_account_id'] ?? '',
                'page_id' => $creds['page_id'] ?? '',
                'webhook_url' => rtrim(config('app.url'), '/') . "/api/webhooks/channel/{$conn->id}",
                'webhook_verify_token' => $creds['webhook_verify_token'] ?? '',
                'raw_credentials' => $creds,
                'created_at' => $conn->created_at?->toIso8601String(),
            ];
        });

        $firstConn = $connections->first();
        $firstCreds = $firstConn ? $firstConn->decrypted_credentials : [];

        return response()->json([
            'connected' => $connections->isNotEmpty(),
            'connections' => $connectionsList,
            'id' => $firstConn?->id,
            'name' => $firstConn?->name,
            'status' => $firstConn?->status,
            'instagram_business_account_id' => $firstCreds['instagram_business_account_id'] ?? '',
            'webhook_url' => $firstConn ? rtrim(config('app.url'), '/') . "/api/webhooks/channel/{$firstConn->id}" : '',
            'webhook_verify_token' => $firstCreds['webhook_verify_token'] ?? '',
        ]);
    }

    /**
     * Redirect to Facebook Login OAuth page.
     */
    public function redirect(Request $request)
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $limitCheck = app(\App\Services\PlanLimitService::class)->canConnectChannel($tenant, 'instagram');
            if (!$limitCheck['allowed']) {
                return redirect('/channels?error=' . urlencode($limitCheck['reason']));
            }
        }

        $appId = \App\Models\PlatformSetting::get('facebook_client_id') ?: config('services.facebook.client_id');
        $redirectUri = rtrim(config('app.url') ?: url('/'), '/') . '/api/integrations/instagram/oauth/callback';
        $scope = 'instagram_basic,instagram_manage_messages,pages_manage_metadata,pages_show_list';
        $state = csrf_token();

        if (!$appId) {
            return redirect('/channels?error=' . urlencode('Facebook App ID (FACEBOOK_CLIENT_ID) is missing in server environment or platform settings.'));
        }

        $url = "https://www.facebook.com/v20.0/dialog/oauth?" . http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $state,
        ]);

        return redirect($url);
    }

    /**
     * Handle the Facebook OAuth Callback.
     */
    public function callback(Request $request)
    {
        $code = $request->query('code');
        if (!$code) {
            return redirect('/channels?error=No+oauth+code+returned+from+Facebook');
        }

        $appId = \App\Models\PlatformSetting::get('facebook_client_id') ?: config('services.facebook.client_id');
        $appSecret = \App\Models\PlatformSetting::get('facebook_client_secret') ?: config('services.facebook.client_secret');
        $redirectUri = rtrim(config('app.url') ?: url('/'), '/') . '/api/integrations/instagram/oauth/callback';

        try {
            // 1. Exchange authorization code for short-lived User Access Token
            $tokenRes = Http::asForm()->post('https://graph.facebook.com/v20.0/oauth/access_token', [
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]);

            if (!$tokenRes->successful() || !$tokenRes->json('access_token')) {
                throw new Exception("Failed to exchange OAuth code with Facebook: " . $tokenRes->body());
            }

            $userAccessToken = $tokenRes->json('access_token');

            // 2. Exchange short-lived token for Long-Lived User Access Token
            $longLivedRes = Http::get('https://graph.facebook.com/v20.0/oauth/access_token', [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'fb_exchange_token' => $userAccessToken,
            ]);

            $finalUserToken = $longLivedRes->successful() && $longLivedRes->json('access_token')
                ? $longLivedRes->json('access_token')
                : $userAccessToken;

            // 3. Fetch user's Facebook Pages
            $pagesRes = Http::withToken($finalUserToken)->get('https://graph.facebook.com/v20.0/me/accounts');
            if (!$pagesRes->successful() || !is_array($pagesRes->json('data'))) {
                throw new Exception("Failed to retrieve Facebook pages associated with account.");
            }

            $pages = $pagesRes->json('data');
            if (empty($pages)) {
                throw new Exception("No Facebook Pages found on this account. Instagram Business Messaging requires a linked Facebook page.");
            }

            // 4. Find the first page with a connected Instagram Business Account
            $targetPage = null;
            $instagramAccountId = null;

            foreach ($pages as $page) {
                $pageId = $page['id'];
                $pageDetails = Http::withToken($page['access_token'])->get("https://graph.facebook.com/v20.0/{$pageId}", [
                    'fields' => 'instagram_business_account',
                ]);

                if ($pageDetails->successful() && $pageDetails->json('instagram_business_account.id')) {
                    $targetPage = $page;
                    $instagramAccountId = $pageDetails->json('instagram_business_account.id');
                    break;
                }
            }

            if (!$targetPage || !$instagramAccountId) {
                throw new Exception("Could not find any Facebook Page linked to an Instagram Business Account.");
            }

            $tenant = $request->user()?->tenant;
            $tenantId = $tenant?->id ?? ($request->user()->tenant_id ?? 1);

            // Look for existing connection matching this instagram account ID or page ID
            $existing = ChannelConnection::where('tenant_id', $tenantId)
                ->where('channel_type', 'instagram')
                ->get();

            $connection = null;
            foreach ($existing as $conn) {
                $creds = $conn->decrypted_credentials;
                if (($creds['instagram_business_account_id'] ?? '') === $instagramAccountId || ($creds['page_id'] ?? '') === $targetPage['id']) {
                    $connection = $conn;
                    break;
                }
            }

            if (!$connection) {
                if ($tenant) {
                    $limitCheck = app(\App\Services\PlanLimitService::class)->canConnectChannel($tenant, 'instagram');
                    if (!$limitCheck['allowed']) {
                        return redirect('/channels?error=' . urlencode($limitCheck['reason']));
                    }
                }

                $connection = new ChannelConnection();
                $connection->tenant_id = $tenantId;
                $connection->channel_type = 'instagram';
            }

            $existingCreds = $connection->exists ? $connection->decrypted_credentials : [];
            $webhookVerifyToken = $existingCreds['webhook_verify_token'] ?? Str::random(32);

            $connection->name = $targetPage['name'] . " (Instagram)";
            $connection->status = 'connected';
            $connection->credentials = [
                'page_access_token' => $targetPage['access_token'],
                'page_id' => $targetPage['id'],
                'instagram_business_account_id' => $instagramAccountId,
                'webhook_verify_token' => $webhookVerifyToken,
            ];
            $connection->save();

            return redirect('/channels?success=Instagram+channel+connected+successfully');
        } catch (Exception $e) {
            Log::error("Instagram OAuth callback failed: " . $e->getMessage());
            return redirect('/channels?error=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Delete/Disconnect Instagram channel by ID.
     */
    public function disconnect(Request $request, $id = null): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $targetId = $id ?: $request->input('id');

        $query = ChannelConnection::where('tenant_id', $tenantId)
            ->where('channel_type', 'instagram');

        if ($targetId) {
            $query->where('id', $targetId);
        }

        $query->delete();

        $remaining = ChannelConnection::where('tenant_id', $tenantId)
            ->where('channel_type', 'instagram')
            ->count();

        return response()->json([
            'connected' => $remaining > 0,
            'remaining_count' => $remaining,
            'message' => 'Instagram connection has been disconnected successfully.'
        ]);
    }
}
