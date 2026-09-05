<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminTenantController extends Controller
{
    /**
     * List and search tenants.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Tenant::with(['plan', 'currency']);

        // Search by company name
        if ($request->has('search') && !empty($request->search)) {
            $query->where('company_name', 'like', '%' . $request->search . '%');
        }

        // Filter by status
        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        $tenants = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'tenants' => $tenants
        ]);
    }

    /**
     * Show tenant details and associated users.
     */
    public function show(string $id): JsonResponse
    {
        $tenant = Tenant::with(['plan', 'currency'])->find($id);

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        $users = User::where('tenant_id', $id)->get();

        return response()->json([
            'tenant' => $tenant,
            'users' => $users,
        ]);
    }

    /**
     * Suspend or reactivate a tenant.
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::find($id);

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        $request->validate([
            'status' => ['required', 'string', 'in:active,suspended,trial'],
        ]);

        $oldStatus = $tenant->status;
        $tenant->status = $request->status;
        $tenant->save();

        // Audit Log
        AuditLog::create([
            'actor_type' => 'App\Models\Admin',
            'actor_id' => $request->user('admin')->id,
            'action' => 'update_tenant_status',
            'subject_type' => 'App\Models\Tenant',
            'subject_id' => $tenant->id,
            'meta' => [
                'old_status' => $oldStatus,
                'new_status' => $request->status,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Tenant status updated successfully.',
            'tenant' => $tenant,
        ]);
    }

    /**
     * Impersonate a tenant's owner user.
     */
    public function impersonate(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::find($id);

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        // Find the first user (the main tenant admin)
        $user = User::where('tenant_id', $id)
            ->orderBy('id', 'asc')
            ->first();

        if (!$user) {
            return response()->json(['message' => 'This tenant has no active users to impersonate.'], 400);
        }

        $admin = $request->user('admin');

        // Audit Log
        AuditLog::create([
            'actor_type' => 'App\Models\Admin',
            'actor_id' => $admin->id,
            'action' => 'impersonate_start',
            'subject_type' => 'App\Models\User',
            'subject_id' => $user->id,
            'meta' => [
                'tenant_id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'user_email' => $user->email,
            ],
            'ip_address' => $request->ip(),
        ]);

        // Save admin state in session
        session(['impersonator_admin_id' => $admin->id]);

        // Login as the tenant user under 'web' guard
        Auth::guard('web')->login($user);

        // Regenerate session
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Impersonation started successfully.',
            'redirect_url' => '/dashboard',
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'tenant_id' => $user->tenant_id,
            ]
        ])
        ->cookie('whatsomni_logged_in', '1', 120, '/', null, false, false);
    }

    /**
     * Stop impersonating and return to admin account.
     */
    public function stopImpersonate(Request $request): JsonResponse
    {
        $adminId = session('impersonator_admin_id');

        if (!$adminId) {
            return response()->json(['message' => 'Not currently impersonating a tenant.'], 400);
        }

        // Audit Log for impersonate_stop
        \App\Models\AuditLog::create([
            'actor_type' => 'App\Models\Admin',
            'actor_id' => $adminId,
            'action' => 'impersonate_stop',
            'subject_type' => null,
            'subject_id' => null,
            'meta' => [
                'admin_id' => $adminId,
            ],
            'ip_address' => $request->ip(),
        ]);

        // Log out of the tenant user session
        Auth::guard('web')->logout();

        // Clear user session indicator
        $request->session()->forget('impersonator_admin_id');

        // Log back in as admin
        $admin = \App\Models\Admin::find($adminId);
        if ($admin) {
            Auth::guard('admin')->login($admin);
        }

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Returned to administrator session successfully.',
            'redirect_url' => '/superadmin/dashboard'
        ])
        ->cookie('whatsomni_logged_in', '', -1, '/', null, false, false)
        ->cookie('whatsomni_admin_logged_in', '1', 120, '/', null, false, false);
    }

    /**
     * Create a new tenant and its initial owner user account.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'company_name' => ['required', 'string', 'max:100'],
            'team_size' => ['required', 'string', 'max:50'],
            'industry_category' => ['required', 'string', 'max:100'],
            'plan_id' => ['nullable', 'exists:plans,id'],
            'currency_id' => ['nullable', 'exists:currencies,id'],
            'status' => ['required', 'string', 'in:active,suspended,trial'],
            'first_name' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:tenant_users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $planId = $request->plan_id ?: (\App\Models\Plan::where('is_active', true)->orderBy('sort_order', 'asc')->first()?->id);
        $currencyId = $request->currency_id ?: (\App\Models\Currency::where('is_default', true)->first()?->id);

        $tenant = Tenant::create([
            'company_name' => $request->company_name,
            'team_size' => $request->team_size,
            'industry_category' => $request->industry_category,
            'plan_id' => $planId,
            'currency_id' => $currencyId,
            'status' => $request->status,
            'onboarding_step' => 'complete',
        ]);

        // Look up default system role (owner)
        $role = DB::table('roles')
            ->where('tenant_id', null)
            ->where('name', 'owner')
            ->first();

        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role_id' => $role?->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Audit Log
        AuditLog::create([
            'actor_type' => 'App\Models\Admin',
            'actor_id' => $request->user('admin')->id,
            'action' => 'create_tenant',
            'subject_type' => 'App\Models\Tenant',
            'subject_id' => $tenant->id,
            'meta' => [
                'company_name' => $tenant->company_name,
                'owner_email' => $user->email,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Tenant and owner account created successfully.',
            'tenant' => $tenant,
            'user' => $user,
        ], 201);
    }

    /**
     * Update tenant details.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::find($id);

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        $request->validate([
            'company_name' => ['required', 'string', 'max:100'],
            'plan_id' => ['nullable', 'exists:plans,id'],
            'currency_id' => ['nullable', 'exists:currencies,id'],
            'status' => ['required', 'string', 'in:active,suspended,trial'],
        ]);

        $tenant->company_name = $request->company_name;
        if ($request->has('plan_id')) {
            $tenant->plan_id = $request->plan_id;
        }
        if ($request->has('currency_id')) {
            $tenant->currency_id = $request->currency_id;
        }
        $tenant->status = $request->status;
        $tenant->save();

        // Audit Log
        AuditLog::create([
            'actor_type' => 'App\Models\Admin',
            'actor_id' => $request->user('admin')->id,
            'action' => 'update_tenant',
            'subject_type' => 'App\Models\Tenant',
            'subject_id' => $tenant->id,
            'meta' => [
                'company_name' => $tenant->company_name,
                'plan_id' => $tenant->plan_id,
                'status' => $tenant->status,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Tenant updated successfully.',
            'tenant' => $tenant,
        ]);
    }

    /**
     * Delete tenant and its owners.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::find($id);

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        $companyName = $tenant->company_name;

        // Cleanup associated users and the tenant
        User::where('tenant_id', $id)->delete();
        $tenant->delete();

        // Audit Log
        AuditLog::create([
            'actor_type' => 'App\Models\Admin',
            'actor_id' => $request->user('admin')->id,
            'action' => 'delete_tenant',
            'subject_type' => 'App\Models\Tenant',
            'subject_id' => $id,
            'meta' => [
                'company_name' => $companyName,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Tenant deleted successfully.',
        ]);
    }
}
