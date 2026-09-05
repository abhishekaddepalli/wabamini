<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\SaasAdminInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminTeamController extends Controller
{
    /**
     * Get list of admin team members, pending invitations, and available roles.
     */
    public function index(): JsonResponse
    {
        // Ensure default roles exist
        if (AdminRole::count() === 0) {
            AdminRole::create([
                'name' => 'super_admin',
                'display_name' => 'Super Administrator',
                'permissions' => ['*'],
            ]);
            AdminRole::create([
                'name' => 'support_agent',
                'display_name' => 'Support Agent',
                'permissions' => ['tickets', 'settings.general'],
            ]);
        }

        $members = Admin::with('role')->get();
        $invitations = SaasAdminInvitation::with('role')->get();
        $roles = AdminRole::all();

        return response()->json([
            'members' => $members,
            'invitations' => $invitations,
            'roles' => $roles,
        ]);
    }

    /**
     * Invite a new administrator.
     */
    public function invite(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role_id' => ['required', 'exists:saas_admin_roles,id'],
        ]);

        // Check if already an admin member
        if (Admin::where('email', $request->email)->exists()) {
            return response()->json([
                'message' => 'This email is already registered as an administrator.',
            ], 422);
        }

        // Check if already invited
        if (SaasAdminInvitation::where('email', $request->email)->exists()) {
            return response()->json([
                'message' => 'An active invitation is already pending for this email.',
            ], 422);
        }

        $invitation = SaasAdminInvitation::create([
            'email' => $request->email,
            'role_id' => $request->role_id,
            'token' => Str::random(40),
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json([
            'message' => 'Administrator invitation dispatched successfully.',
            'invitation' => $invitation->load('role'),
        ]);
    }

    /**
     * Revoke a pending administrator invitation.
     */
    public function revokeInvite($id): JsonResponse
    {
        $invitation = SaasAdminInvitation::findOrFail($id);
        $invitation->delete();

        return response()->json([
            'message' => 'Administrator invitation revoked successfully.',
        ]);
    }

    /**
     * Remove an administrative team member.
     */
    public function removeMember($id): JsonResponse
    {
        // Don't allow an admin to delete themselves
        if (auth('admin')->id() == $id) {
            return response()->json([
                'message' => 'You cannot remove your own administrative account.',
            ], 422);
        }

        $member = Admin::findOrFail($id);
        $member->delete();

        return response()->json([
            'message' => 'Administrator removed from team successfully.',
        ]);
    }

    /**
     * Update member permission role.
     */
    public function updateMemberRole(Request $request, $id): JsonResponse
    {
        $request->validate([
            'role_id' => ['required', 'exists:saas_admin_roles,id'],
        ]);

        if (auth('admin')->id() == $id) {
            return response()->json([
                'message' => 'You cannot change your own administrative role.',
            ], 422);
        }

        $member = Admin::findOrFail($id);
        $member->role_id = $request->role_id;
        $member->save();

        return response()->json([
            'message' => 'Administrator role updated successfully.',
            'member' => $member->load('role'),
        ]);
    }

    /**
     * Create custom administrative role.
     */
    public function createRole(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:saas_admin_roles,name'],
            'permissions' => ['required', 'array'],
        ]);

        $role = AdminRole::create([
            'name' => $request->name,
            'permissions' => $request->permissions,
        ]);

        return response()->json([
            'message' => 'Administrative role created successfully.',
            'role' => $role,
        ]);
    }

    /**
     * Update custom administrative role.
     */
    public function updateRole(Request $request, $id): JsonResponse
    {
        $role = AdminRole::findOrFail($id);

        // Prevent modification of base system roles
        $systemRoles = ['super_admin', 'support_agent', 'Super Admin', 'Support Agent'];
        if (in_array(strtolower($role->name), array_map('strtolower', $systemRoles))) {
            return response()->json([
                'message' => 'System base roles cannot be modified.',
            ], 422);
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:saas_admin_roles,name,' . $role->id],
            'permissions' => ['required', 'array'],
        ]);

        $role->update([
            'name' => $request->name,
            'permissions' => $request->permissions,
        ]);

        return response()->json([
            'message' => 'Administrative role updated successfully.',
            'role' => $role,
        ]);
    }

    /**
     * Delete custom administrative role.
     */
    public function deleteRole($id): JsonResponse
    {
        $role = AdminRole::findOrFail($id);

        // Prevent deletion of base system roles
        $systemRoles = ['super_admin', 'support_agent', 'Super Admin', 'Support Agent'];
        if (in_array(strtolower($role->name), array_map('strtolower', $systemRoles))) {
            return response()->json([
                'message' => 'System base roles cannot be modified or deleted.',
            ], 422);
        }

        // Dissolve role assignments (optional or throw error if actively assigned)
        if ($role->admins()->exists()) {
            return response()->json([
                'message' => 'This role is actively assigned to team members and cannot be deleted.',
            ], 422);
        }

        $role->delete();

        return response()->json([
            'message' => 'Administrative role deleted successfully.',
        ]);
    }
}
