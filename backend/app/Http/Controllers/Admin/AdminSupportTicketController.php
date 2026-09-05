<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminSupportTicketController extends Controller
{
    /**
     * Display a listing of all support tickets across all tenants.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::with(['user:id,first_name,last_name', 'tenant:id,company_name'])
            ->orderBy('updated_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('id', $search);
            });
        }

        $tickets = $query->paginate(15);

        return response()->json([
            'tickets' => $tickets,
        ]);
    }

    /**
     * Display the specified support ticket with messages.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $ticket = SupportTicket::with(['user:id,first_name,last_name', 'tenant:id,company_name', 'messages.user:id,first_name,last_name'])
            ->findOrFail($id);

        return response()->json([
            'ticket' => $ticket,
        ]);
    }

    /**
     * Add a message reply to the specified support ticket thread.
     */
    public function reply(Request $request, $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $request->validate([
            'message' => ['required', 'string'],
        ]);

        $message = DB::transaction(function () use ($request, $ticket) {
            // Keep ticket status open or update if needed, but reply defaults status to open if resolved
            if ($ticket->status === 'resolved' || $ticket->status === 'closed') {
                $ticket->update(['status' => 'open']);
            } else {
                $ticket->touch(); // Update the updated_at timestamp
            }

            return SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => null, // admin replies have null user_id as per model and guidelines
                'message' => $request->message,
                'is_admin_reply' => true,
            ]);
        });

        // Create user/tenant notification & broadcast for admin reply
        try {
            \App\Services\NotificationService::createAndBroadcast(
                $ticket->tenant_id,
                $ticket->user_id,
                "Support Ticket Reply: #{$ticket->id}",
                "Administrator replied: " . \Illuminate\Support\Str::limit($request->message, 80),
                "support_ticket_reply",
                [
                    'ticket_id' => $ticket->id,
                    'action_url' => "/help/tickets/{$ticket->id}",
                ]
            );

            // Send ticket reply email to user
            $ticketUser = \App\Models\User::find($ticket->user_id);
            if ($ticketUser && $ticketUser->email) {
                $frontendUrl = \App\Providers\AppServiceProvider::getFrontendUrl($ticketUser);
                $actionUrl = "{$frontendUrl}/help/tickets/{$ticket->id}";

                \Illuminate\Support\Facades\Mail::to($ticketUser->email)->send(
                    new \App\Mail\SupportTicketReplyMail($ticket->id, $request->message, $actionUrl)
                );
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to dispatch user notification or email for admin ticket reply: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Reply sent successfully.',
            'reply' => $message,
        ], 201);
    }

    /**
     * Update the status of the specified support ticket.
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $request->validate([
            'status' => ['required', 'string', 'in:open,in_progress,resolved,closed'],
        ]);

        $ticket->update([
            'status' => $request->status,
        ]);

        return response()->json([
            'message' => 'Ticket status updated successfully.',
            'ticket' => $ticket,
        ]);
    }
}
