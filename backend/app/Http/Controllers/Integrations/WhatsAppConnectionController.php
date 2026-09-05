<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ChannelConnection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Exception;

class WhatsAppConnectionController extends Controller
{
    /**
     * Get active connection status and all WhatsApp connections for the tenant.
     */
    public function status(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $connections = ChannelConnection::where('tenant_id', $tenant->id)
            ->where('channel_type', 'whatsapp')
            ->orderBy('id', 'desc')
            ->get();

        $connectionsList = $connections->map(function ($conn) {
            $creds = $conn->decrypted_credentials;
            return [
                'id' => $conn->id,
                'name' => $conn->name,
                'status' => $conn->status,
                'phone_number_id' => $creds['phone_number_id'] ?? '',
                'whatsapp_business_account_id' => $creds['whatsapp_business_account_id'] ?? '',
                'webhook_url' => rtrim(config('app.url'), '/') . "/api/webhooks/channel/{$conn->id}",
                'webhook_verify_token' => $creds['webhook_verify_token'] ?? '',
                'webhook_secret' => $creds['webhook_secret'] ?? '',
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
            'phone_number_id' => $firstCreds['phone_number_id'] ?? '',
            'whatsapp_business_account_id' => $firstCreds['whatsapp_business_account_id'] ?? '',
            'webhook_url' => $firstConn ? rtrim(config('app.url'), '/') . "/api/webhooks/channel/{$firstConn->id}" : '',
            'webhook_verify_token' => $firstCreds['webhook_verify_token'] ?? '',
            'webhook_secret' => $firstCreds['webhook_secret'] ?? '',
            'raw_credentials' => $firstCreds,
        ]);
    }

    /**
     * Connect a new WhatsApp number or update an existing connection.
     */
    public function connect(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:100',
            'phone_number_id' => 'required|string|max:100',
            'whatsapp_business_account_id' => 'required|string|max:100',
            'system_user_access_token' => 'required|string',
            'webhook_verify_token' => 'nullable|string|max:100',
            'webhook_secret' => 'nullable|string|max:100',
        ]);

        $connectionId = $request->input('id');
        if ($connectionId) {
            $connection = ChannelConnection::where('tenant_id', $tenant->id)
                ->where('channel_type', 'whatsapp')
                ->findOrFail($connectionId);
        } else {
            $limitCheck = app(\App\Services\PlanLimitService::class)->canConnectChannel($tenant, 'whatsapp');
            if (!$limitCheck['allowed']) {
                return response()->json([
                    'message' => $limitCheck['reason'],
                    'error_code' => $limitCheck['code']
                ], 403);
            }

            $connection = new ChannelConnection();
            $connection->tenant_id = $tenant->id;
            $connection->channel_type = 'whatsapp';
        }

        $existingCreds = $connection->exists ? $connection->decrypted_credentials : [];
        $verifyToken = $request->input('webhook_verify_token') ?: ($existingCreds['webhook_verify_token'] ?? Str::random(16));
        $webhookSecret = $request->input('webhook_secret') ?: ($existingCreds['webhook_secret'] ?? '');

        $credentials = [
            'phone_number_id' => $request->input('phone_number_id'),
            'whatsapp_business_account_id' => $request->input('whatsapp_business_account_id'),
            'system_user_access_token' => $request->input('system_user_access_token'),
            'webhook_verify_token' => $verifyToken,
            'webhook_secret' => $webhookSecret,
        ];

        $connection->name = $request->input('name');
        $connection->status = 'connected';
        $connection->credentials = $credentials;
        $connection->save();

        return response()->json([
            'connected' => true,
            'id' => $connection->id,
            'name' => $connection->name,
            'status' => $connection->status,
            'phone_number_id' => $request->input('phone_number_id'),
            'whatsapp_business_account_id' => $request->input('whatsapp_business_account_id'),
            'webhook_url' => rtrim(config('app.url'), '/') . "/api/webhooks/channel/{$connection->id}",
            'webhook_verify_token' => $verifyToken,
            'webhook_secret' => $webhookSecret,
            'raw_credentials' => $credentials,
        ]);
    }

    /**
     * Delete/Disconnect a WhatsApp connection by ID.
     */
    public function disconnect(Request $request, $id = null): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $targetId = $id ?: $request->input('id');

        $query = ChannelConnection::where('tenant_id', $tenant->id)
            ->where('channel_type', 'whatsapp');

        if ($targetId) {
            $query->where('id', $targetId);
        }

        $query->delete();

        $remaining = ChannelConnection::where('tenant_id', $tenant->id)
            ->where('channel_type', 'whatsapp')
            ->count();

        return response()->json([
            'connected' => $remaining > 0,
            'remaining_count' => $remaining,
            'message' => 'WhatsApp Cloud API connection has been disconnected successfully.'
        ]);
    }
}
