<?php

namespace App\Http\Controllers\RBAC;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\PlanLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class TeamAndRoleController extends Controller
{
    protected PlanLimitService $planLimitService;

    public function __construct(PlanLimitService $planLimitService)
    {
        $this->planLimitService = $planLimitService;
    }

    /**
     * Lists all teams of the tenant.
     */
    public function listTeams(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $teams = Team::where('tenant_id', $tenant->id)->withCount('users')->get();

        return response()->json(['teams' => $teams]);
    }

    /**
     * Creates a team.
     */
    public function createTeam(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        $tenant = $request->user()->tenant;
        $team = Team::create([
            'tenant_id' => $tenant->id,
            'name' => $request->name,
        ]);

        return response()->json(['message' => 'Team created successfully.', 'team' => $team], 201);
    }

    /**
     * Deletes a team.
     */
    public function deleteTeam(Request $request, $id): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $team = Team::where('tenant_id', $tenant->id)->where('id', $id)->first();

        if (!$team) {
            return response()->json(['message' => 'Team not found.'], 404);
        }

        $team->delete();
        return response()->json(['message' => 'Team deleted successfully.']);
    }

    /**
     * Lists all system and custom roles of the tenant.
     */
    public function listRoles(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $roles = Role::whereNull('tenant_id')
            ->orWhere('tenant_id', $tenant->id)
            ->get();

        return response()->json(['roles' => $roles]);
    }

    /**
     * Creates a custom role.
     */
    public function createRole(Request $request): JsonResponse
    {
        $request->validate([
            'display_name' => ['required', 'string', 'max:50'],
            'permissions' => ['required', 'array'],
        ]);

        $tenant = $request->user()->tenant;
        $roleName = Str::slug($request->display_name);

        $role = Role::create([
            'tenant_id' => $tenant->id,
            'name' => $roleName,
            'display_name' => $request->display_name,
            'permissions' => $request->permissions,
        ]);

        return response()->json(['message' => 'Custom role created successfully.', 'role' => $role], 201);
    }

    /**
     * Deletes a custom role.
     */
    public function deleteRole(Request $request, $id): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $role = Role::where('tenant_id', $tenant->id)->where('id', $id)->first();

        if (!$role) {
            return response()->json(['message' => 'Role not found or cannot be deleted.'], 404);
        }

        // Re-assign users under this role to 'agent' system role before deletion
        $agentRole = Role::whereNull('tenant_id')->where('name', 'agent')->first();
        User::where('role_id', $role->id)->update(['role_id' => $agentRole ? $agentRole->id : null]);

        $role->delete();
        return response()->json(['message' => 'Custom role deleted successfully.']);
    }

    /**
     * Lists all members in the tenant workspace.
     */
    public function listMembers(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $members = User::where('tenant_id', $tenant->id)
            ->with(['role', 'teams'])
            ->get();

        return response()->json(['members' => $members]);
    }

    /**
     * Updates a member's role.
     */
    public function updateMemberRole(Request $request, $id): JsonResponse
    {
        $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $tenant = $request->user()->tenant;
        $member = User::where('tenant_id', $tenant->id)->where('id', $id)->first();

        if (!$member) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        // Owner role is fixed and cannot be changed
        if ($member->role && $member->role->name === 'owner') {
            return response()->json(['message' => 'The workspace Owner role cannot be changed.'], 422);
        }

        $member->update(['role_id' => $request->role_id]);
        return response()->json(['message' => 'Member role updated successfully.', 'member' => $member->load('role')]);
    }

    /**
     * Updates a member's team associations.
     */
    public function updateMemberTeams(Request $request, $id): JsonResponse
    {
        $request->validate([
            'team_ids' => ['required', 'array'],
            'team_ids.*' => ['exists:teams,id'],
        ]);

        $tenant = $request->user()->tenant;
        $member = User::where('tenant_id', $tenant->id)->where('id', $id)->first();

        if (!$member) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        $member->teams()->sync($request->team_ids);
        return response()->json(['message' => 'Member teams updated successfully.', 'member' => $member->load('teams')]);
    }

    /**
     * Removes a user from the workspace.
     */
    public function removeMember(Request $request, $id): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $member = User::where('tenant_id', $tenant->id)->where('id', $id)->first();

        if (!$member) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        if ($member->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot remove yourself from the workspace.'], 422);
        }

        if ($member->role && $member->role->name === 'owner') {
            return response()->json(['message' => 'The workspace Owner cannot be removed.'], 422);
        }

        $member->delete();
        return response()->json(['message' => 'Member removed from workspace successfully.']);
    }

    /**
     * Lists all pending invitations.
     */
    public function listInvitations(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $invitations = TeamInvitation::where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->with(['role', 'team'])
            ->get();

        return response()->json(['invitations' => $invitations]);
    }

    /**
     * Cancels a pending invitation.
     */
    public function cancelInvitation(Request $request, $id): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $invitation = TeamInvitation::where('tenant_id', $tenant->id)->where('id', $id)->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        $invitation->delete();
        return response()->json(['message' => 'Invitation cancelled successfully.']);
    }

    /**
     * Creates and sends a workspace invitation.
     */
    public function inviteMember(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'role_id' => ['required', 'exists:roles,id'],
            'team_id' => ['nullable', 'exists:teams,id'],
        ]);

        $tenant = $request->user()->tenant;

        // Check if user is already a member
        if (User::where('tenant_id', $tenant->id)->where('email', $request->email)->exists()) {
            return response()->json(['message' => 'User is already a member of this workspace.'], 422);
        }

        // Check if invitation is already pending
        if (TeamInvitation::where('tenant_id', $tenant->id)->where('email', $request->email)->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'An invitation has already been sent to this email.'], 422);
        }

        // Verify Seat Limits using PlanLimitService (counting active members + pending invitations)
        $activeCount = User::where('tenant_id', $tenant->id)->count();
        $pendingCount = TeamInvitation::where('tenant_id', $tenant->id)->where('status', 'pending')->count();
        $totalCount = $activeCount + $pendingCount;

        if (!$this->planLimitService->canUseFeature($tenant, 'team_members', $totalCount)) {
            return response()->json([
                'message' => \App\Services\PlanLimitService::trans('SEAT_LIMIT_REACHED'),
                'code' => 'SEAT_LIMIT_REACHED'
            ], 422);
        }

        $token = Str::random(40);

        $invitation = TeamInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => $request->email,
            'role_id' => $request->role_id,
            'team_id' => $request->team_id,
            'token' => $token,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        // Send Email
        try {
            $frontendUrl = \App\Providers\AppServiceProvider::getFrontendUrl();
            $acceptUrl = "{$frontendUrl}/verify-email/success?token={$token}&invite=1"; // Route to accept password setup

            \Illuminate\Support\Facades\Mail::to($request->email)->send(
                new \App\Mail\TeamInvitationMail($tenant->company_name, $acceptUrl)
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Invitation email delivery failed: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Invitation sent successfully.', 'invitation' => $invitation->load(['role', 'team'])]);
    }

    /**
     * Public route to verify invitation token details.
     */
    public function verifyInvitation($token): JsonResponse
    {
        $invitation = TeamInvitation::where('token', $token)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->with(['tenant', 'role'])
            ->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invitation is invalid or has expired.'], 404);
        }

        return response()->json([
            'email' => $invitation->email,
            'company_name' => $invitation->tenant->company_name,
            'role_name' => $invitation->role->display_name,
        ]);
    }

    /**
     * Public route to accept invitation, create user, and sign in.
     */
    public function acceptInvitation(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'first_name' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $invitation = TeamInvitation::where('token', $request->token)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invitation is invalid or has expired.'], 404);
        }

        try {
            DB::beginTransaction();

            // Create user
            $user = User::create([
                'tenant_id' => $invitation->tenant_id,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $invitation->email,
                'password' => Hash::make($request->password),
                'role_id' => $invitation->role_id,
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            // Add to team pivot if specified
            if ($invitation->team_id) {
                $user->teams()->attach($invitation->team_id);
            }

            // Mark invitation accepted
            $invitation->update(['status' => 'accepted']);

            DB::commit();

            // Login
            Auth::login($user);

            // Sync indicators
            $onboardingComplete = '1'; // Since the tenant has already onboarded!
            $planSelected = $user->tenant->plan_id ? '1' : '0';

            return response()->json([
                'message' => 'Invitation accepted successfully.',
                'user' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'tenant_id' => $user->tenant_id,
                    'tenant' => $user->tenant,
                    'role' => $user->role,
                ]
            ])->cookie('whatsomni_logged_in', '1', 120, '/', null, false, false);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Accept failed: ' . $e->getMessage()], 500);
        }
    }
}
