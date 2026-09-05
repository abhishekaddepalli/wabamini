<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ChannelConnection;
use App\Events\WhatsAppBaileysQrCodeEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class BaileysConnectionController extends Controller
{
    /**
     * Start/Configure Baileys Unofficial WhatsApp connection.
     */
    public function connect(Request $request)
    {
        $request->validate([
            'id' => 'nullable|integer',
            'name' => 'nullable|string|max:255',
        ]);

        $tenantId = $request->user()->tenant_id ?? 1;
        $connectionId = $request->input('id');
        $workerUrl = config('services.baileys.worker_url') ?? 'http://localhost:5001';

        if ($connectionId) {
            $connection = ChannelConnection::where('tenant_id', $tenantId)
                ->where('channel_type', 'whatsapp_baileys')
                ->findOrFail($connectionId);

            if ($request->has('name')) {
                $connection->name = $request->input('name') ?: 'WhatsApp Baileys';
                $connection->save();
            }

            if ($connection->status === 'connected') {
                return response()->json([
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'channel_type' => $connection->channel_type,
                    'status' => $connection->status,
                ]);
            }
        } else {
            $tenant = $request->user()->tenant;
            $limitCheck = app(\App\Services\PlanLimitService::class)->canConnectChannel($tenant, 'whatsapp_baileys');
            if (!$limitCheck['allowed']) {
                return response()->json([
                    'message' => $limitCheck['reason'],
                    'error_code' => $limitCheck['code']
                ], 403);
            }

            $webhookVerifyToken = Str::random(32);
            $connection = new ChannelConnection();
            $connection->tenant_id = $tenantId;
            $connection->channel_type = 'whatsapp_baileys';
            $connection->status = 'disconnected';
            $connection->name = $request->input('name') ?: 'WhatsApp Baileys';
            $connection->credentials = [
                'webhook_verify_token' => $webhookVerifyToken,
            ];
            $connection->save();
        }

        $webhookVerifyToken = $connection->decrypted_credentials['webhook_verify_token'] ?? Str::random(32);
        $secretToken = config('services.baileys.secret_token') ?? 'whatsomni_baileys_secret_key';

        try {
            // Tell worker to spin up/start the session with auth header
            $response = Http::timeout(10)
                ->withHeaders(['X-Baileys-Secret' => $secretToken])
                ->post("{$workerUrl}/sessions/start", [
                    'connection_id' => $connection->id,
                    'webhook_verify_token' => $webhookVerifyToken,
                ]);

            if (!$response->successful()) {
                Log::error("Baileys worker rejected connect session: " . $response->body());
                return response()->json(['message' => 'Baileys worker rejected the connection request.'], 500);
            }
        } catch (Exception $e) {
            Log::error("Failed to contact Baileys worker on connect: " . $e->getMessage());
            return response()->json(['message' => 'Failed to contact Baileys worker. Please ensure the worker service is running.'], 500);
        }

        return response()->json([
            'id' => $connection->id,
            'name' => $connection->name,
            'channel_type' => $connection->channel_type,
            'status' => $connection->status,
            'webhook_verify_token' => $webhookVerifyToken,
        ]);
    }

    /**
     * Disconnect/Remove Baileys connection by ID.
     */
    public function disconnect(Request $request, $id = null)
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $targetId = $id ?: $request->input('id');

        $query = ChannelConnection::where('tenant_id', $tenantId)
            ->where('channel_type', 'whatsapp_baileys');

        if ($targetId) {
            $query->where('id', $targetId);
        }

        $connectionsToDelete = $query->get();

        if ($connectionsToDelete->isEmpty()) {
            return response()->json(['message' => 'Active Baileys channel not found.'], 404);
        }

        $workerUrl = config('services.baileys.worker_url') ?? 'http://localhost:5001';
        $secretToken = config('services.baileys.secret_token') ?? 'whatsomni_baileys_secret_key';

        foreach ($connectionsToDelete as $connection) {
            try {
                // Tell worker to stop/close the session with auth header
                Http::timeout(10)
                    ->withHeaders(['X-Baileys-Secret' => $secretToken])
                    ->post("{$workerUrl}/sessions/stop", [
                        'connection_id' => $connection->id,
                    ]);
            } catch (Exception $e) {
                Log::warning("Could not tell Baileys worker to stop session: " . $e->getMessage());
            }

            $connection->delete();
        }

        $remaining = ChannelConnection::where('tenant_id', $tenantId)
            ->where('channel_type', 'whatsapp_baileys')
            ->count();

        return response()->json([
            'success' => true,
            'connected' => $remaining > 0,
            'remaining_count' => $remaining,
        ]);
    }

    /**
     * Fetch active connections list (used by worker recovery on start).
     * Protected by shared internal microservice secret token.
     */
    public function sessions(Request $request)
    {
        $expectedSecret = config('services.baileys.secret_token') ?? 'whatsomni_baileys_secret_key';
        $incomingSecret = $request->header('X-Baileys-Worker-Secret') ?: $request->header('X-Baileys-Secret');

        if (!$incomingSecret || !hash_equals((string)$expectedSecret, (string)$incomingSecret)) {
            return response()->json(['message' => 'Unauthorized worker request.'], 401);
        }

        $connections = ChannelConnection::where('channel_type', 'whatsapp_baileys')
            ->get()
            ->map(function ($conn) {
                return [
                    'connection_id' => $conn->id,
                    'name' => $conn->name,
                    'status' => $conn->status,
                    'credentials' => $conn->decrypted_credentials,
                ];
            });

        return response()->json(['sessions' => $connections]);
    }

    /**
     * Get Baileys connection details/status endpoint with connections list.
     */
    public function status(Request $request)
    {
        $tenantId = $request->user()->tenant_id ?? 1;

        $connections = ChannelConnection::where('tenant_id', $tenantId)
            ->where('channel_type', 'whatsapp_baileys')
            ->orderBy('id', 'desc')
            ->get();

        $connectionsList = $connections->map(function ($conn) {
            return [
                'id' => $conn->id,
                'name' => $conn->name,
                'status' => $conn->status,
                'webhook_verify_token' => $conn->decrypted_credentials['webhook_verify_token'] ?? null,
                'created_at' => $conn->created_at?->toIso8601String(),
            ];
        });

        $firstConn = $connections->first();

        return response()->json([
            'connected' => $connections->where('status', 'connected')->isNotEmpty(),
            'connections' => $connectionsList,
            'id' => $firstConn?->id,
            'name' => $firstConn?->name,
            'status' => $firstConn?->status,
            'webhook_verify_token' => $firstConn ? ($firstConn->decrypted_credentials['webhook_verify_token'] ?? null) : null,
        ]);
    }

    /**
     * Callback Webhook from Baileys Worker notifying Laravel about QR updates and state changes.
     */
    public function webhook(Request $request)
    {
        $request->validate([
            'connection_id' => 'required|integer',
            'event' => 'required|string',
        ]);

        $connectionId = $request->input('connection_id');
        $event = $request->input('event');

        $connection = ChannelConnection::find($connectionId);
        if (!$connection) {
            return response()->json(['message' => 'Connection not found.'], 404);
        }

        // Verify request signature token
        $expectedToken = $connection->decrypted_credentials['webhook_verify_token'] ?? null;
        $incomingToken = $request->header('X-Baileys-Token');

        if ($expectedToken && $incomingToken !== $expectedToken) {
            return response()->json(['message' => 'Invalid validation token.'], 401);
        }

        if ($event === 'qr') {
            $qr = $request->input('qr');
            if ($qr) {
                WhatsAppBaileysQrCodeEvent::dispatch($connectionId, $qr);
            }
        } elseif ($event === 'connected') {
            $connection->status = 'connected';
            $connection->save();
        } elseif ($event === 'disconnected') {
            $connection->status = 'disconnected';
            $connection->save();
        }

        return response()->json(['success' => true]);
    }
}
