<?php

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Models\ChannelConnection;
use App\Services\Channels\ChannelManager;
use App\Jobs\ProcessInboundMessageJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Exception;

class ChannelWebhookController extends Controller
{
    protected ChannelManager $channelManager;

    public function __construct(ChannelManager $channelManager)
    {
        $this->channelManager = $channelManager;
    }

    /**
     * Handle webhook requests (GET/POST) from message providers.
     */
    public function handle(Request $request, $connectionId)
    {
        $connection = ChannelConnection::find($connectionId);
        if (!$connection) {
            return response()->json(['message' => 'Connection not found.'], 404);
        }

        $credentials = $connection->decrypted_credentials;
        $driver = $this->channelManager->driver($connection->channel_type);

        // GET request challenge verification (e.g. WhatsApp, Facebook Hub Subscription challenge)
        if ($request->isMethod('GET')) {
            $mode = $request->query('hub_mode') ?: $request->query('hub.mode');
            $token = $request->query('hub_verify_token') ?: $request->query('hub.verify_token');
            $challenge = $request->query('hub_challenge') ?: $request->query('hub.challenge');

            $expectedToken = $credentials['webhook_verify_token'] ?? $credentials['webhook_token'] ?? null;

            if ($mode === 'subscribe' && $token === $expectedToken) {
                return response($challenge, 200)->header('Content-Type', 'text/plain');
            }

            // Mock challenge fallback
            if ($request->query('verify_token') === $expectedToken) {
                return response($request->query('challenge'), 200)->header('Content-Type', 'text/plain');
            }

            return response()->json(['message' => 'Forbidden verification token.'], 403);
        }

        // POST request event processing
        $payload = $request->getContent();
        $headers = collect($request->headers->all())->mapWithKeys(function ($item, $key) {
            return [$key => is_array($item) ? ($item[0] ?? '') : $item];
        })->toArray();

        try {
            if (!$driver->verifyWebhookSignature($headers, $payload, $credentials)) {
                Log::warning("Webhook signature verification failed for connection ID: {$connectionId}");
                return response()->json(['message' => 'Invalid signature.'], 401);
            }
        } catch (Exception $e) {
            Log::error("Error checking webhook signature: " . $e->getMessage());
            return response()->json(['message' => 'Signature verification error.'], 401);
        }

        $data = $request->all();

        // Dispatch background processing job
        ProcessInboundMessageJob::dispatch($connection->id, $data);

        return response()->json(['status' => 'queued']);
    }
}
