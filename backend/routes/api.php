<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SessionController;
use App\Events\HealthCheckEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['api', 'auth:sanctum']]);

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health Check Endpoints (Phase 0)
Route::get('/health', function () {
    $dbStatus = 'OK';
    $redisStatus = 'OK';

    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        $dbStatus = 'FAILED: ' . $e->getMessage();
    }

    try {
        Redis::connection()->ping();
    } catch (\Throwable $e) {
        $redisStatus = 'FAILED: ' . $e->getMessage();
    }

    return response()->json([
        'status' => ($dbStatus === 'OK' && $redisStatus === 'OK') ? 'healthy' : 'unhealthy',
        'database' => $dbStatus,
        'redis' => $redisStatus,
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::middleware(['throttle:5,1'])->group(function () {
    Route::post('/health/reverb', function (Request $request) {
        if (!app()->environment(['local', 'testing']) && !$request->user('admin')) {
            return response()->json(['message' => 'Health test endpoints are restricted in production.'], 403);
        }
        $message = $request->input('message', 'Hello from Laravel Reverb health check!');
        
        try {
            event(new HealthCheckEvent($message));

            return response()->json([
                'status' => 'dispatched',
                'message' => $message,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    });

    Route::post('/health/s3', function (Request $request) {
        if (!app()->environment(['local', 'testing']) && !$request->user('admin')) {
            return response()->json(['message' => 'Health test endpoints are restricted in production.'], 403);
        }
        try {
            $disk = Storage::disk('s3');
            $fileName = 'health-checks/' . uniqid() . '.txt';
            $fileContent = 'WhatsOmni Phase 0 Health Check - ' . now()->toIso8601String();

            $disk->put($fileName, $fileContent);
            $signedUrl = $disk->temporaryUrl($fileName, now()->addMinutes(10));
            $disk->delete($fileName);

            return response()->json([
                'status' => 'success',
                'message' => 'S3 signed URL generated successfully.',
                'signed_url' => $signedUrl,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'note' => 'If S3 is not configured in .env, this is expected.',
            ], 200);
        }
    });

    Route::post('/health/mail', function (Request $request) {
        if (!app()->environment(['local', 'testing']) && !$request->user('admin')) {
            return response()->json(['message' => 'Health test endpoints are restricted in production.'], 403);
        }
        $request->validate([
            'email' => 'required|email',
            'mailer' => 'nullable|string|in:smtp,resend',
        ]);

        $toEmail = $request->input('email');
        $mailer = $request->input('mailer', config('mail.default'));

        try {
            Mail::mailer($mailer)->raw('This is a test email sent from WhatsOmni Phase 0 verification system.', function ($message) use ($toEmail) {
                $message->to($toEmail)
                    ->subject('WhatsOmni Phase 0 Health Check');
            });

            return response()->json([
                'status' => 'success',
                'message' => "Health check email successfully sent to {$toEmail} using the {$mailer} driver.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    });
});

// Authentication & Session Management Endpoints (Phase 1)
Route::middleware('web')->group(function () {
    // Guest Auth
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::get('/public/plans', [\App\Http\Controllers\Billing\BillingController::class, 'publicPlans']);
    Route::get('/public/pages/{slug}', [\App\Http\Controllers\Admin\AdminCustomPageController::class, 'publicShow']);
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'sendResetLinkEmail']);
    Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');

    // Signed Email Verification Link
    Route::get('/email/verify/{id}/{hash}', [VerificationController::class, 'verify'])->name('verification.verify');
    Route::post('/email/verification-notification', [VerificationController::class, 'resend']);

    // SaaS Admin Auth (Guest)
    Route::post('/admin/login', [\App\Http\Controllers\Admin\AdminAuthController::class, 'login']);

    // SaaS Admin Protected Routes
    Route::middleware('auth:admin')->group(function () {
        Route::post('/admin/logout', [\App\Http\Controllers\Admin\AdminAuthController::class, 'logout']);
        Route::get('/admin/me', [\App\Http\Controllers\Admin\AdminAuthController::class, 'me']);
        Route::patch('/admin/profile', [\App\Http\Controllers\Admin\AdminAuthController::class, 'updateProfile']);

        // Tenants
        Route::get('/admin/tenants', [\App\Http\Controllers\Admin\AdminTenantController::class, 'index']);
        Route::post('/admin/tenants', [\App\Http\Controllers\Admin\AdminTenantController::class, 'store']);
        Route::get('/admin/tenants/{id}', [\App\Http\Controllers\Admin\AdminTenantController::class, 'show']);
        Route::put('/admin/tenants/{id}', [\App\Http\Controllers\Admin\AdminTenantController::class, 'update']);
        Route::delete('/admin/tenants/{id}', [\App\Http\Controllers\Admin\AdminTenantController::class, 'destroy']);
        Route::post('/admin/tenants/{id}/status', [\App\Http\Controllers\Admin\AdminTenantController::class, 'updateStatus']);
        Route::post('/admin/tenants/{id}/impersonate', [\App\Http\Controllers\Admin\AdminTenantController::class, 'impersonate']);

        // Dashboard & Analytics
        Route::get('/admin/dashboard', [\App\Http\Controllers\Admin\AdminAnalyticsController::class, 'getAnalytics']);
        Route::get('/admin/analytics', [\App\Http\Controllers\Admin\AdminAnalyticsController::class, 'getAnalytics']);
        Route::get('/admin/analytics/ai', [\App\Http\Controllers\Admin\AdminAnalyticsController::class, 'getAiAnalytics']);

        // Plans
        Route::get('/admin/plans', [\App\Http\Controllers\Admin\AdminPlanController::class, 'index']);
        Route::get('/admin/plans/{id}', [\App\Http\Controllers\Admin\AdminPlanController::class, 'show']);
        Route::post('/admin/plans', [\App\Http\Controllers\Admin\AdminPlanController::class, 'store']);
        Route::put('/admin/plans/{id}', [\App\Http\Controllers\Admin\AdminPlanController::class, 'update']);
        Route::post('/admin/plans/{id}/archive', [\App\Http\Controllers\Admin\AdminPlanController::class, 'archive']);
        Route::post('/admin/plans/{id}/unarchive', [\App\Http\Controllers\Admin\AdminPlanController::class, 'unarchive']);
        Route::delete('/admin/plans/{id}', [\App\Http\Controllers\Admin\AdminPlanController::class, 'destroy']);

        // Currencies
        Route::get('/admin/currencies', [\App\Http\Controllers\Admin\AdminCurrencyController::class, 'index']);
        Route::post('/admin/currencies', [\App\Http\Controllers\Admin\AdminCurrencyController::class, 'store']);
        Route::put('/admin/currencies/{id}', [\App\Http\Controllers\Admin\AdminCurrencyController::class, 'update']);
        Route::delete('/admin/currencies/{id}', [\App\Http\Controllers\Admin\AdminCurrencyController::class, 'destroy']);

        Route::get('/admin/settings', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'index']);
        Route::post('/admin/settings', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'update']);
        Route::post('/admin/settings/logo', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'uploadLogo']);
        Route::post('/admin/settings/mailer/test', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'testMailer']);
        
        // Payment Gateways Management
        Route::get('/admin/payment-gateways', [\App\Http\Controllers\Admin\AdminPaymentGatewayController::class, 'index']);
        Route::post('/admin/payment-gateways', [\App\Http\Controllers\Admin\AdminPaymentGatewayController::class, 'update']);
        Route::post('/admin/payment-gateways/test', [\App\Http\Controllers\Admin\AdminPaymentGatewayController::class, 'test']);

        // Admin Team Settings
        Route::get('/admin/settings/team', [\App\Http\Controllers\Admin\AdminTeamController::class, 'index']);
        Route::post('/admin/settings/team/invite', [\App\Http\Controllers\Admin\AdminTeamController::class, 'invite']);
        Route::delete('/admin/settings/team/invite/{id}', [\App\Http\Controllers\Admin\AdminTeamController::class, 'revokeInvite']);
        Route::delete('/admin/settings/team/members/{id}', [\App\Http\Controllers\Admin\AdminTeamController::class, 'removeMember']);
        Route::put('/admin/settings/team/members/{id}', [\App\Http\Controllers\Admin\AdminTeamController::class, 'updateMemberRole']);
        Route::post('/admin/settings/team/roles', [\App\Http\Controllers\Admin\AdminTeamController::class, 'createRole']);
        Route::put('/admin/settings/team/roles/{id}', [\App\Http\Controllers\Admin\AdminTeamController::class, 'updateRole']);
        Route::delete('/admin/settings/team/roles/{id}', [\App\Http\Controllers\Admin\AdminTeamController::class, 'deleteRole']);

        // Channels & SMS Configuration
        Route::get('/admin/channels', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'getChannelsConfig']);
        Route::put('/admin/channels', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'updateChannelsConfig']);
        Route::post('/admin/channels/test-sms', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'testSmsGateway']);

        // AI Providers Configuration
        Route::get('/admin/ai-providers', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'getAiProvidersConfig']);
        Route::put('/admin/ai-providers', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'updateAiProvidersConfig']);
        Route::post('/admin/ai-providers/test-key', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'testAdminAiKey']);

        // Integrations Configuration
        Route::get('/admin/integrations', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'getIntegrationsConfig']);
        Route::put('/admin/integrations', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'updateIntegrationsConfig']);

        // Support Tickets Management
        Route::get('/admin/tickets', [\App\Http\Controllers\Admin\AdminSupportTicketController::class, 'index']);
        Route::get('/admin/tickets/{id}', [\App\Http\Controllers\Admin\AdminSupportTicketController::class, 'show']);
        Route::post('/admin/tickets/{id}/reply', [\App\Http\Controllers\Admin\AdminSupportTicketController::class, 'reply']);
        Route::put('/admin/tickets/{id}/status', [\App\Http\Controllers\Admin\AdminSupportTicketController::class, 'updateStatus']);

        // Admin Notifications Management
        Route::get('/admin/notifications', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'index']);
        Route::patch('/admin/notifications/{id}/read', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'markAsRead']);
        Route::patch('/admin/notifications/mark-all-read', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'markAllRead']);
        Route::delete('/admin/notifications/{id}', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'destroy']);

        // Custom Legal & Public Pages Management
        Route::get('/admin/pages', [\App\Http\Controllers\Admin\AdminCustomPageController::class, 'index']);
        Route::get('/admin/pages/{slug}', [\App\Http\Controllers\Admin\AdminCustomPageController::class, 'show']);
        Route::put('/admin/pages/{slug}', [\App\Http\Controllers\Admin\AdminCustomPageController::class, 'update']);

        // Dynamic Flow Templates Management
        Route::apiResource('/admin/flow-templates', \App\Http\Controllers\Admin\AdminFlowTemplateController::class);
        Route::post('/admin/flow-templates/{id}/toggle-publish', [\App\Http\Controllers\Admin\AdminFlowTemplateController::class, 'togglePublish']);

        // Users Management
        Route::apiResource('/admin/users', \App\Http\Controllers\Admin\AdminUserController::class);
    });

    // Authenticated Session context
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        
        Route::get('/auth/me', function (Request $request) {
            return response()->json([
                'user' => $request->user()->load(['tenant.currency', 'tenant.plan', 'role']),
                'is_impersonated' => session()->has('impersonator_admin_id'),
                'ai_operational_model' => app(\App\Services\AIProviderService::class)->getAiOperationalModel(),
            ]);
        });

        Route::patch('/auth/profile', function (Request $request) {
            $user = $request->user();
            $request->validate([
                'language' => ['nullable', 'string', 'max:5'],
                'first_name' => ['nullable', 'string', 'max:50'],
                'last_name' => ['nullable', 'string', 'max:50'],
                'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            ]);

            if ($request->has('language')) {
                $user->language = $request->language;
            }
            if ($request->has('first_name')) {
                $user->first_name = $request->first_name;
            }
            if ($request->has('last_name')) {
                $user->last_name = $request->last_name;
            }
            if ($request->filled('password')) {
                $user->password = \Illuminate\Support\Facades\Hash::make($request->password);
            }

            $user->save();

            return response()->json([
                'message' => 'Profile updated successfully.',
                'user' => $user->load(['tenant', 'role'])
            ]);
        });

        // Two-Step Sensitive Email Verification Endpoints
        Route::post('/auth/profile/email/send-current-code', function (Request $request) {
            $user = $request->user();
            $code = sprintf("%06d", mt_rand(100000, 999999));

            \Illuminate\Support\Facades\Cache::put('email_update_current_code_' . $user->id, $code, now()->addMinutes(15));
            
            try {
                \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\EmailVerificationCodeMail($code, 'current'));
            } catch (\Exception $e) {
                // Return success anyway for testing fallbacks if mailer is not configured
                if (config('app.env') === 'production') {
                    return response()->json(['message' => 'Failed to dispatch email.'], 500);
                }
            }

            return response()->json(['message' => 'Verification code sent to your active email address.']);
        });

        Route::post('/auth/profile/email/verify-current-code', function (Request $request) {
            $user = $request->user();
            $request->validate(['code' => ['required', 'string', 'size:6']]);

            $cached = \Illuminate\Support\Facades\Cache::get('email_update_current_code_' . $user->id);

            if (!$cached || $cached !== $request->code) {
                return response()->json(['message' => 'Invalid or expired verification code.'], 422);
            }

            \Illuminate\Support\Facades\Cache::put('email_update_current_verified_' . $user->id, true, now()->addMinutes(15));
            \Illuminate\Support\Facades\Cache::forget('email_update_current_code_' . $user->id);

            return response()->json(['message' => 'Current email verified. Please provide your new email address.']);
        });

        Route::post('/auth/profile/email/send-new-code', function (Request $request) {
            $user = $request->user();
            $request->validate([
                'new_email' => ['required', 'email', 'max:100', 'unique:tenant_users,email,' . $user->id],
            ]);

            if (!\Illuminate\Support\Facades\Cache::get('email_update_current_verified_' . $user->id)) {
                return response()->json(['message' => 'Unauthorized. Please verify your current email first.'], 403);
            }

            $code = sprintf("%06d", mt_rand(100000, 999999));

            \Illuminate\Support\Facades\Cache::put('email_update_new_email_' . $user->id, $request->new_email, now()->addMinutes(15));
            \Illuminate\Support\Facades\Cache::put('email_update_new_code_' . $user->id, $code, now()->addMinutes(15));

            try {
                \Illuminate\Support\Facades\Mail::to($request->new_email)->send(new \App\Mail\EmailVerificationCodeMail($code, 'new'));
            } catch (\Exception $e) {
                if (config('app.env') === 'production') {
                    return response()->json(['message' => 'Failed to dispatch email.'], 500);
                }
            }

            return response()->json(['message' => 'Verification code sent to your proposed new email address.']);
        });

        Route::post('/auth/profile/email/confirm-update', function (Request $request) {
            $user = $request->user();
            $request->validate(['code' => ['required', 'string', 'size:6']]);

            if (!\Illuminate\Support\Facades\Cache::get('email_update_current_verified_' . $user->id)) {
                return response()->json(['message' => 'Unauthorized. Please verify your current email first.'], 403);
            }

            $cachedCode = \Illuminate\Support\Facades\Cache::get('email_update_new_code_' . $user->id);
            $newEmail = \Illuminate\Support\Facades\Cache::get('email_update_new_email_' . $user->id);

            if (!$cachedCode || $cachedCode !== $request->code || !$newEmail) {
                return response()->json(['message' => 'Invalid or expired verification code.'], 422);
            }

            // Perform Update
            $user->update(['email' => $newEmail]);

            // Clear cache state
            \Illuminate\Support\Facades\Cache::forget('email_update_current_verified_' . $user->id);
            \Illuminate\Support\Facades\Cache::forget('email_update_new_code_' . $user->id);
            \Illuminate\Support\Facades\Cache::forget('email_update_new_email_' . $user->id);

            return response()->json([
                'message' => 'Email address updated successfully.',
                'user' => $user->load(['tenant', 'role'])
            ]);
        });

        Route::post('/auth/settings/delete-account/send-code', function (Request $request) {
            $user = $request->user();
            $code = sprintf("%06d", mt_rand(100000, 999999));

            \Illuminate\Support\Facades\Cache::put('account_deletion_code_' . $user->id, $code, now()->addMinutes(15));

            try {
                \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\AccountDeletionVerificationMail($code));
            } catch (\Exception $e) {
                if (config('app.env') === 'production') {
                    return response()->json(['message' => 'Failed to dispatch verification email.'], 500);
                }
            }

            return response()->json(['message' => 'Verification code dispatched to your email address.']);
        });

        Route::post('/auth/settings/delete-account/confirm', function (Request $request) {
            $user = $request->user();
            $request->validate(['code' => ['required', 'string', 'size:6']]);

            $cached = \Illuminate\Support\Facades\Cache::get('account_deletion_code_' . $user->id);

            if (!$cached || $cached !== $request->code) {
                return response()->json(['message' => 'Invalid or expired verification code.'], 422);
            }

            \Illuminate\Support\Facades\Cache::forget('account_deletion_code_' . $user->id);

            $user->tokens()->delete();
            $user->delete();

            return response()->json(['message' => 'Account permanently deleted successfully.']);
        });

        Route::patch('/auth/workspace', function (Request $request) {
            $user = $request->user();
            
            if (!$user->role || $user->role->name !== 'owner') {
                return response()->json(['message' => 'Unauthorized. Only the workspace owner can modify company settings.'], 403);
            }

            $tenant = $user->tenant;
            if (!$tenant) {
                return response()->json(['message' => 'Workspace not found.'], 404);
            }

            $request->validate([
                'company_name' => ['required', 'string', 'max:100'],
                'team_size' => ['nullable', 'string', 'max:50'],
                'industry_category' => ['nullable', 'string', 'max:50'],
                'default_language' => ['nullable', 'string', 'max:5'],
                'currency' => ['nullable', 'string', 'size:3'],
            ]);

            if ($request->has('currency')) {
                $code = strtoupper($request->currency);
                $symbols = [
                    'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'INR' => '₹', 'CAD' => '$',
                    'AUD' => '$', 'NZD' => '$', 'JPY' => '¥', 'SGD' => '$', 'CHF' => 'CHF',
                    'HKD' => '$', 'SEK' => 'kr', 'NOK' => 'kr', 'DKK' => 'kr', 'MXN' => '$',
                    'BRL' => 'R$', 'ZAR' => 'R', 'AED' => 'AED', 'PLN' => 'zł', 'TRY' => '₺',
                    'CNY' => '¥'
                ];
                $symbol = $symbols[$code] ?? $code;

                $currencyObj = \App\Models\Currency::firstOrCreate(
                    ['code' => $code],
                    [
                        'name' => $code,
                        'symbol' => $symbol,
                        'is_active' => true,
                        'is_default' => false
                    ]
                );
                $tenant->currency_id = $currencyObj->id;
            }

            $tenant->update($request->only([
                'company_name',
                'team_size',
                'industry_category',
                'default_language',
            ]));
            $tenant->save();

            return response()->json([
                'message' => 'Workspace settings updated successfully.',
                'tenant' => $tenant->load('currency')
            ]);
        });

        // Workspace Analytics Dashboard
        Route::get('/dashboard/analytics', [\App\Http\Controllers\CRM\DashboardAnalyticsController::class, 'getAnalytics']);

        // Notifications Center
        Route::get('/notifications', [\App\Http\Controllers\CRM\NotificationController::class, 'index']);
        Route::patch('/notifications/mark-all-read', [\App\Http\Controllers\CRM\NotificationController::class, 'markAllAsRead']);
        Route::patch('/notifications/{id}/read', [\App\Http\Controllers\CRM\NotificationController::class, 'markAsRead']);

        // Support Tickets Help Center
        Route::get('/help/tickets', [\App\Http\Controllers\Help\SupportTicketController::class, 'index']);
        Route::post('/help/tickets', [\App\Http\Controllers\Help\SupportTicketController::class, 'store']);
        Route::get('/help/tickets/{id}', [\App\Http\Controllers\Help\SupportTicketController::class, 'show']);
        Route::post('/help/tickets/{id}/reply', [\App\Http\Controllers\Help\SupportTicketController::class, 'reply']);
        Route::delete('/notifications/{id}', [\App\Http\Controllers\CRM\NotificationController::class, 'destroy']);

        // Workspace Onboarding
        Route::post('/onboarding/step-1', [\App\Http\Controllers\Auth\OnboardingController::class, 'saveStep1']);
        Route::post('/onboarding/step-2', [\App\Http\Controllers\Auth\OnboardingController::class, 'saveStep2']);
        Route::post('/onboarding/step-3', [\App\Http\Controllers\Auth\OnboardingController::class, 'saveStep3']);

        // Settings -> Sessions
        Route::get('/settings/sessions', [SessionController::class, 'index']);
        Route::delete('/settings/sessions/{id}', [SessionController::class, 'destroy']);

        // Settings -> AI Providers
        Route::get('/settings/ai-providers', [\App\Http\Controllers\Settings\AIProviderController::class, 'index']);
        Route::get('/settings/ai-providers/usage', [\App\Http\Controllers\Settings\AIProviderController::class, 'getUsage']);
        Route::get('/settings/ai-providers/settings', [\App\Http\Controllers\Settings\AIProviderController::class, 'getSettings']);
        Route::post('/settings/ai-providers/settings', [\App\Http\Controllers\Settings\AIProviderController::class, 'updateSettings']);
        Route::post('/settings/ai-providers', [\App\Http\Controllers\Settings\AIProviderController::class, 'store']);
        Route::post('/settings/ai-providers/validate', [\App\Http\Controllers\Settings\AIProviderController::class, 'validateKey']);
        Route::delete('/settings/ai-providers/{provider}', [\App\Http\Controllers\Settings\AIProviderController::class, 'destroy']);

        // Impersonation Stop handler
        Route::post('/admin/impersonate/stop', [\App\Http\Controllers\Admin\AdminTenantController::class, 'stopImpersonate']);

        // Settings -> Billing & Subscription
        Route::get('/billing/subscription', [\App\Http\Controllers\Billing\BillingController::class, 'subscription']);
        Route::get('/billing/plans', [\App\Http\Controllers\Billing\BillingController::class, 'plans']);
        Route::post('/billing/checkout', [\App\Http\Controllers\Billing\BillingController::class, 'checkout']);
        Route::post('/billing/razorpay/verify', [\App\Http\Controllers\Billing\BillingController::class, 'verifyRazorpay']);
        Route::post('/billing/paystack/verify', [\App\Http\Controllers\Billing\BillingController::class, 'verifyPaystack']);
        Route::post('/billing/flutterwave/verify', [\App\Http\Controllers\Billing\BillingController::class, 'verifyFlutterwave']);
        Route::post('/billing/portal', [\App\Http\Controllers\Billing\BillingController::class, 'portal']);
        Route::post('/billing/cancel', [\App\Http\Controllers\Billing\BillingController::class, 'cancel']);

        // RBAC & Teams
        Route::get('/teams', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'listTeams']);
        Route::post('/teams', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'createTeam']);
        Route::delete('/teams/{id}', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'deleteTeam']);

        Route::get('/roles', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'listRoles']);
        Route::post('/roles', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'createRole']);
        Route::delete('/roles/{id}', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'deleteRole']);

        Route::get('/members', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'listMembers']);
        Route::delete('/members/{id}', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'removeMember']);
        Route::post('/members/{id}/role', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'updateMemberRole']);
        Route::post('/members/{id}/teams', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'updateMemberTeams']);
        Route::post('/invitations', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'inviteMember']);
        Route::get('/invitations', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'listInvitations']);
        Route::delete('/invitations/{id}', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'cancelInvitation']);

        // Contacts & CRM
        Route::get('/contacts', [\App\Http\Controllers\CRM\ContactController::class, 'index']);
        Route::post('/contacts', [\App\Http\Controllers\CRM\ContactController::class, 'store']);
        Route::get('/contacts/{id}', [\App\Http\Controllers\CRM\ContactController::class, 'show']);
        Route::put('/contacts/{id}', [\App\Http\Controllers\CRM\ContactController::class, 'update']);
        Route::delete('/contacts/{id}', [\App\Http\Controllers\CRM\ContactController::class, 'destroy']);
        Route::post('/contacts/import', [\App\Http\Controllers\CRM\ContactController::class, 'importCsv']);
        Route::post('/contacts/{id}/notes', [\App\Http\Controllers\CRM\ContactController::class, 'addNote']);
        Route::put('/contacts/{id}/mute', [\App\Http\Controllers\CRM\ContactController::class, 'mute']);

        // Deals
        Route::get('/deals', [\App\Http\Controllers\CRM\DealController::class, 'index']);
        Route::post('/deals', [\App\Http\Controllers\CRM\DealController::class, 'store']);
        Route::post('/deals/stages', [\App\Http\Controllers\CRM\DealController::class, 'storeStage']);
        Route::put('/deals/stages', [\App\Http\Controllers\CRM\DealController::class, 'updateStageOrder']);
        Route::delete('/deals/{id}', [\App\Http\Controllers\CRM\DealController::class, 'destroy']);

        // Staff Directory
        Route::get('/staff', [\App\Http\Controllers\CRM\StaffController::class, 'index']);
        Route::post('/staff', [\App\Http\Controllers\CRM\StaffController::class, 'store']);
        Route::post('/staff/verify-google-calendar', [\App\Http\Controllers\CRM\StaffController::class, 'verifyGoogleCalendar']);
        Route::get('/staff/{id}', [\App\Http\Controllers\CRM\StaffController::class, 'show']);
        Route::put('/staff/{id}', [\App\Http\Controllers\CRM\StaffController::class, 'update']);
        Route::delete('/staff/{id}', [\App\Http\Controllers\CRM\StaffController::class, 'destroy']);
        Route::post('/staff/{id}/toggle-status', [\App\Http\Controllers\CRM\StaffController::class, 'toggleStatus']);
        Route::post('/staff/{id}/test-google-sync', [\App\Http\Controllers\CRM\StaffController::class, 'testGoogleCalendar']);

        // Booking & Calendar
        Route::get('/appointments', [\App\Http\Controllers\CRM\BookingController::class, 'index']);
        Route::post('/appointments', [\App\Http\Controllers\CRM\BookingController::class, 'storeAppointment']);
        Route::put('/appointments/{id}/cancel', [\App\Http\Controllers\CRM\BookingController::class, 'cancelAppointment']);
        Route::get('/booking-links', [\App\Http\Controllers\CRM\BookingController::class, 'listBookingLinks']);
        Route::post('/booking-links', [\App\Http\Controllers\CRM\BookingController::class, 'storeBookingLink']);
        Route::delete('/booking-links/{id}', [\App\Http\Controllers\CRM\BookingController::class, 'deleteBookingLink']);

        // RAG Knowledge Base
        Route::get('/knowledge-bases', [\App\Http\Controllers\CRM\KnowledgeBaseController::class, 'index']);
        Route::post('/knowledge-bases', [\App\Http\Controllers\CRM\KnowledgeBaseController::class, 'store']);
        Route::get('/knowledge-bases/{id}', [\App\Http\Controllers\CRM\KnowledgeBaseController::class, 'show']);
        Route::put('/knowledge-bases/{id}', [\App\Http\Controllers\CRM\KnowledgeBaseController::class, 'update']);
        Route::delete('/knowledge-bases/{id}', [\App\Http\Controllers\CRM\KnowledgeBaseController::class, 'destroy']);
        Route::post('/knowledge-bases/{id}/sources', [\App\Http\Controllers\CRM\KnowledgeBaseController::class, 'addSource']);
        Route::delete('/knowledge-bases/sources/{sourceId}', [\App\Http\Controllers\CRM\KnowledgeBaseController::class, 'deleteSource']);
        Route::post('/knowledge-bases/sources/{sourceId}/reindex', [\App\Http\Controllers\CRM\KnowledgeBaseController::class, 'reindexSource']);
        Route::post('/knowledge-bases/{id}/query', [\App\Http\Controllers\CRM\KnowledgeBaseController::class, 'query'])->middleware('throttle:30,1');

        // Visual Conversation Flow Builder
        Route::get('/flows', [\App\Http\Controllers\CRM\FlowController::class, 'index']);
        Route::get('/flows/templates', [\App\Http\Controllers\CRM\FlowController::class, 'getTemplates']);
        Route::post('/flows/from-template', [\App\Http\Controllers\CRM\FlowController::class, 'fromTemplate']);
        Route::get('/flows/flow-credits', [\App\Http\Controllers\CRM\FlowController::class, 'getFlowCredits']);
        Route::post('/flows', [\App\Http\Controllers\CRM\FlowController::class, 'store']);
        Route::get('/flows/{id}', [\App\Http\Controllers\CRM\FlowController::class, 'show']);
        Route::put('/flows/{id}', [\App\Http\Controllers\CRM\FlowController::class, 'update']);
        Route::delete('/flows/{id}', [\App\Http\Controllers\CRM\FlowController::class, 'destroy']);
        Route::post('/flows/{id}/toggle-active', [\App\Http\Controllers\CRM\FlowController::class, 'toggleActive']);
        Route::post('/flows/{id}/duplicate', [\App\Http\Controllers\CRM\FlowController::class, 'duplicate']);
        Route::get('/flows/{id}/versions', [\App\Http\Controllers\CRM\FlowController::class, 'versions']);
        Route::post('/flows/{id}/versions', [\App\Http\Controllers\CRM\FlowController::class, 'saveVersion']);
        Route::post('/flows/{id}/publish', [\App\Http\Controllers\CRM\FlowController::class, 'publish']);
        Route::post('/flows/{id}/simulator', [\App\Http\Controllers\CRM\FlowController::class, 'runSimulator'])->middleware('throttle:30,1');
        Route::post('/flows/{id}/prompt-to-flow', [\App\Http\Controllers\CRM\FlowController::class, 'promptToFlow'])->middleware('throttle:20,1');

        // AI ChatBots Management (Phase 1)
        Route::get('/chatbot', [\App\Http\Controllers\CRM\AiChatbotController::class, 'index']);
        Route::get('/chatbot/provider-configs', [\App\Http\Controllers\CRM\AiChatbotController::class, 'providerConfigs']);
        Route::post('/chatbot', [\App\Http\Controllers\CRM\AiChatbotController::class, 'store']);
        Route::get('/chatbot/{id}', [\App\Http\Controllers\CRM\AiChatbotController::class, 'show']);
        Route::put('/chatbot/{id}', [\App\Http\Controllers\CRM\AiChatbotController::class, 'update']);
        Route::delete('/chatbot/{id}', [\App\Http\Controllers\CRM\AiChatbotController::class, 'destroy']);
        Route::get('/chatbot/{id}/logs', [\App\Http\Controllers\CRM\AiChatbotController::class, 'logs']);
        Route::post('/chatbot/{id}/test', [\App\Http\Controllers\CRM\AiChatbotController::class, 'test'])->middleware('throttle:30,1');

        // AI Agents Management (Legacy Compatibility)
        Route::get('/agents', [\App\Http\Controllers\CRM\AiAgentController::class, 'index']);
        Route::get('/agents/provider-configs', [\App\Http\Controllers\CRM\AiAgentController::class, 'providerConfigs']);
        Route::post('/agents', [\App\Http\Controllers\CRM\AiAgentController::class, 'store']);
        Route::get('/agents/{id}', [\App\Http\Controllers\CRM\AiAgentController::class, 'show']);
        Route::put('/agents/{id}', [\App\Http\Controllers\CRM\AiAgentController::class, 'update']);
        Route::delete('/agents/{id}', [\App\Http\Controllers\CRM\AiAgentController::class, 'destroy']);
        Route::get('/agents/{id}/logs', [\App\Http\Controllers\CRM\AiAgentController::class, 'logs']);
        Route::post('/agents/{id}/trigger', [\App\Http\Controllers\CRM\AiAgentController::class, 'trigger'])->middleware('throttle:30,1');

        // Message Templates (WhatsApp + Email)
        Route::get('/templates', [\App\Http\Controllers\CRM\MessageTemplateController::class, 'index']);
        Route::post('/templates', [\App\Http\Controllers\CRM\MessageTemplateController::class, 'store']);
        Route::get('/templates/{id}', [\App\Http\Controllers\CRM\MessageTemplateController::class, 'show']);
        Route::put('/templates/{id}', [\App\Http\Controllers\CRM\MessageTemplateController::class, 'update']);
        Route::delete('/templates/{id}', [\App\Http\Controllers\CRM\MessageTemplateController::class, 'destroy']);
        Route::post('/templates/{id}/submit-whatsapp', [\App\Http\Controllers\CRM\MessageTemplateController::class, 'submitToMeta']);
        Route::post('/templates/{id}/test-send-email', [\App\Http\Controllers\CRM\MessageTemplateController::class, 'sendTestEmail']);

        // Campaigns (Outbound Broadcast & Scheduling)
        Route::get('/campaigns', [\App\Http\Controllers\CRM\CampaignController::class, 'index']);
        Route::post('/campaigns', [\App\Http\Controllers\CRM\CampaignController::class, 'store']);
        Route::post('/campaigns/upload-media', [\App\Http\Controllers\CRM\CampaignController::class, 'uploadMedia']);
        Route::get('/campaigns/{id}', [\App\Http\Controllers\CRM\CampaignController::class, 'show']);
        Route::delete('/campaigns/{id}', [\App\Http\Controllers\CRM\CampaignController::class, 'destroy']);

        // Channels listing helper
        Route::get('/channels', function (Request $request) {
            return response()->json(
                \App\Models\ChannelConnection::where('tenant_id', $request->user()->tenant_id)->get()
            );
        });
        Route::patch('/channels/{id}/toggle', function (Request $request, $id) {
            $conn = \App\Models\ChannelConnection::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
            $validated = $request->validate([
                'status' => 'nullable|string|in:connected,active,disabled,inactive,disconnected',
                'is_active' => 'nullable|boolean',
            ]);
            if (isset($validated['status'])) {
                $conn->status = $validated['status'];
            } elseif (isset($validated['is_active'])) {
                $conn->status = $validated['is_active'] ? 'connected' : 'disabled';
            } else {
                $conn->status = ($conn->status === 'connected' || $conn->status === 'active') ? 'disabled' : 'connected';
            }
            $conn->save();
            return response()->json([
                'success' => true,
                'connection' => $conn,
                'status' => $conn->status,
            ]);
        });
        Route::delete('/channels/{id}', function (Request $request, $id) {
            $conn = \App\Models\ChannelConnection::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
            $conn->delete();
            return response()->json(['success' => true, 'message' => 'Channel connection deleted successfully.']);
        });

        // Google Sheets Integration
        Route::get('/integrations/google/status', [\App\Http\Controllers\Integrations\GoogleSheetsController::class, 'status']);
        Route::get('/integrations/google/auth-url', [\App\Http\Controllers\Integrations\GoogleSheetsController::class, 'getAuthUrl']);
        Route::delete('/integrations/google/disconnect', [\App\Http\Controllers\Integrations\GoogleSheetsController::class, 'disconnect']);
        Route::get('/integrations/google/spreadsheets', [\App\Http\Controllers\Integrations\GoogleSheetsController::class, 'listSpreadsheets']);
        Route::get('/integrations/google/spreadsheets/{spreadsheetId}/sheets', [\App\Http\Controllers\Integrations\GoogleSheetsController::class, 'listSheets']);
        Route::get('/integrations/google/spreadsheets/{spreadsheetId}/sheets/{sheetName}/preview', [\App\Http\Controllers\Integrations\GoogleSheetsController::class, 'previewSheet']);
        Route::post('/integrations/google/import', [\App\Http\Controllers\Integrations\GoogleSheetsController::class, 'import']);

        // Ecommerce Integration
        Route::get('/integrations/ecommerce/status', [\App\Http\Controllers\Integrations\EcommerceIntegrationController::class, 'status']);
        Route::post('/integrations/ecommerce/connect', [\App\Http\Controllers\Integrations\EcommerceIntegrationController::class, 'connect']);
        Route::delete('/integrations/ecommerce/disconnect', [\App\Http\Controllers\Integrations\EcommerceIntegrationController::class, 'disconnect']);
        Route::post('/integrations/ecommerce/sync', [\App\Http\Controllers\Integrations\EcommerceIntegrationController::class, 'syncNow']);
        Route::get('/integrations/ecommerce/coupons', [\App\Http\Controllers\Integrations\EcommerceIntegrationController::class, 'coupons']);
        Route::get('/integrations/ecommerce/analytics', [\App\Http\Controllers\Integrations\EcommerceIntegrationController::class, 'analytics']);
        Route::post('/integrations/ecommerce/abandoned-carts/{id}/trigger', [\App\Http\Controllers\Integrations\EcommerceIntegrationController::class, 'triggerManualRecovery']);
        Route::get('/integrations/ecommerce/customer-context', [\App\Http\Controllers\Integrations\EcommerceIntegrationController::class, 'customerContext']);

        // Meeting Integrations (Zoom, Teams)
        Route::get('/integrations/meetings/status', [\App\Http\Controllers\Integrations\MeetingIntegrationController::class, 'status']);
        Route::get('/integrations/meetings/auth-url', [\App\Http\Controllers\Integrations\MeetingIntegrationController::class, 'getAuthUrl']);
        Route::delete('/integrations/meetings/disconnect', [\App\Http\Controllers\Integrations\MeetingIntegrationController::class, 'disconnect']);

        // CRM Integrations (Salesforce, HubSpot, Zoho)
        Route::get('/integrations/crm/status', [\App\Http\Controllers\Integrations\CrmIntegrationController::class, 'status']);
        Route::get('/integrations/crm/connect/{provider}', [\App\Http\Controllers\Integrations\CrmIntegrationController::class, 'connect']);
        Route::delete('/integrations/crm/disconnect/{provider}', [\App\Http\Controllers\Integrations\CrmIntegrationController::class, 'disconnect']);
        Route::post('/integrations/crm/mapping/{provider}', [\App\Http\Controllers\Integrations\CrmIntegrationController::class, 'updateMapping']);
        Route::post('/integrations/crm/sync/{provider}', [\App\Http\Controllers\Integrations\CrmIntegrationController::class, 'syncNow']);
        Route::get('/integrations/crm/sync-logs/{provider}', [\App\Http\Controllers\Integrations\CrmIntegrationController::class, 'logs']);
        Route::post('/integrations/crm/retry-log/{logId}', [\App\Http\Controllers\Integrations\CrmIntegrationController::class, 'retry']);

        // WhatsApp Official Cloud API Integration
        Route::get('/integrations/whatsapp/status', [\App\Http\Controllers\Integrations\WhatsAppConnectionController::class, 'status']);
        Route::post('/integrations/whatsapp/connect', [\App\Http\Controllers\Integrations\WhatsAppConnectionController::class, 'connect']);
        Route::delete('/integrations/whatsapp/disconnect/{id?}', [\App\Http\Controllers\Integrations\WhatsAppConnectionController::class, 'disconnect']);

        // WhatsApp Baileys Unofficial Integration
        Route::get('/integrations/baileys/status', [\App\Http\Controllers\Integrations\BaileysConnectionController::class, 'status']);
        Route::post('/integrations/baileys/connect', [\App\Http\Controllers\Integrations\BaileysConnectionController::class, 'connect']);
        Route::delete('/integrations/baileys/disconnect/{id?}', [\App\Http\Controllers\Integrations\BaileysConnectionController::class, 'disconnect']);

        // Instagram Integration
        Route::get('/integrations/instagram/status', [\App\Http\Controllers\Integrations\InstagramConnectionController::class, 'status']);
        Route::get('/integrations/instagram/oauth/redirect', [\App\Http\Controllers\Integrations\InstagramConnectionController::class, 'redirect']);
        Route::delete('/integrations/instagram/disconnect/{id?}', [\App\Http\Controllers\Integrations\InstagramConnectionController::class, 'disconnect']);

        // Facebook Messenger Integration
        Route::get('/integrations/messenger/status', [\App\Http\Controllers\Integrations\FacebookMessengerConnectionController::class, 'status']);
        Route::get('/integrations/messenger/oauth/redirect', [\App\Http\Controllers\Integrations\FacebookMessengerConnectionController::class, 'redirect']);
        Route::delete('/integrations/messenger/disconnect/{id?}', [\App\Http\Controllers\Integrations\FacebookMessengerConnectionController::class, 'disconnect']);

        // Telegram Integration
        Route::get('/integrations/telegram/status', [\App\Http\Controllers\Integrations\TelegramConnectionController::class, 'status']);
        Route::post('/integrations/telegram/connect', [\App\Http\Controllers\Integrations\TelegramConnectionController::class, 'connect']);
        Route::delete('/integrations/telegram/disconnect/{id?}', [\App\Http\Controllers\Integrations\TelegramConnectionController::class, 'disconnect']);

        // SMS Integration
        Route::get('/integrations/sms/status', [\App\Http\Controllers\Integrations\SmsConnectionController::class, 'status']);
        Route::post('/integrations/sms/connect', [\App\Http\Controllers\Integrations\SmsConnectionController::class, 'connect']);
        Route::delete('/integrations/sms/disconnect/{id?}', [\App\Http\Controllers\Integrations\SmsConnectionController::class, 'disconnect']);

        // Email Integration
        Route::get('/integrations/email/status', [\App\Http\Controllers\Integrations\EmailConnectionController::class, 'status']);
        Route::post('/integrations/email/connect', [\App\Http\Controllers\Integrations\EmailConnectionController::class, 'connect']);
        Route::delete('/integrations/email/disconnect/{id?}', [\App\Http\Controllers\Integrations\EmailConnectionController::class, 'disconnect']);

        // Shared Inbox & Conversations
        Route::get('/conversations', [\App\Http\Controllers\CRM\ConversationController::class, 'index']);
        Route::get('/conversations/{id}', [\App\Http\Controllers\CRM\ConversationController::class, 'show']);
        Route::patch('/conversations/{id}', [\App\Http\Controllers\CRM\ConversationController::class, 'update']);
        Route::get('/conversations/{id}/messages', [\App\Http\Controllers\CRM\ConversationController::class, 'messages']);
        Route::post('/conversations/{id}/send', [\App\Http\Controllers\CRM\ConversationController::class, 'sendMessage']);
        Route::post('/conversations/{id}/notes', [\App\Http\Controllers\CRM\ConversationController::class, 'storeNote']);
        Route::post('/conversations/start', [\App\Http\Controllers\CRM\ConversationController::class, 'startConversation']);
        Route::delete('/conversations/{id}', [\App\Http\Controllers\CRM\ConversationController::class, 'destroy']);


    });

    // Public Google OAuth Callback
    Route::get('/integrations/google/callback', [\App\Http\Controllers\Integrations\GoogleSheetsController::class, 'callback']);

    // Public Meeting OAuth Callback
    Route::get('/integrations/meetings/callback', [\App\Http\Controllers\Integrations\MeetingIntegrationController::class, 'callback']);

    // Public CRM Integration OAuth Callback
    Route::get('/integrations/crm/callback/{provider}', [\App\Http\Controllers\Integrations\CrmIntegrationController::class, 'callback'])->name('crm.oauth.callback');

    // Public Platform configuration
    Route::get('/platform/settings', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'publicSettings']);

    // Public Invitations Verification & Acceptance
    Route::get('/invitations/verify/{token}', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'verifyInvitation']);
    Route::post('/invitations/accept', [\App\Http\Controllers\RBAC\TeamAndRoleController::class, 'acceptInvitation']);

    // Public Worker / Baileys Integration endpoints
    Route::get('/integrations/baileys/sessions', [\App\Http\Controllers\Integrations\BaileysConnectionController::class, 'sessions']);
    Route::post('/integrations/baileys/webhook', [\App\Http\Controllers\Integrations\BaileysConnectionController::class, 'webhook']);

    // Public Instagram OAuth callback endpoint
    Route::get('/integrations/instagram/oauth/callback', [\App\Http\Controllers\Integrations\InstagramConnectionController::class, 'callback']);

    // Public Facebook Messenger OAuth callback endpoint
    Route::get('/integrations/messenger/oauth/callback', [\App\Http\Controllers\Integrations\FacebookMessengerConnectionController::class, 'callback']);

    // Public Booking link page endpoints
    Route::get('/public/booking-links/{slug}', [\App\Http\Controllers\CRM\BookingController::class, 'publicShowLink']);
    Route::post('/public/booking-links/{slug}/book', [\App\Http\Controllers\CRM\BookingController::class, 'publicBook'])->middleware('throttle:20,1');
});

// Public Payment Gateway Webhook Receivers
Route::post('/webhooks/billing/stripe', [\App\Http\Controllers\Billing\StripeWebhookController::class, 'handle']);
Route::post('/billing/webhook', [\App\Http\Controllers\Billing\StripeWebhookController::class, 'handle']);
Route::post('/webhooks/billing/razorpay', [\App\Http\Controllers\Billing\RazorpayWebhookController::class, 'handle']);
Route::post('/webhooks/billing/paystack', [\App\Http\Controllers\Billing\PaystackWebhookController::class, 'handle']);
Route::post('/webhooks/billing/flutterwave', [\App\Http\Controllers\Billing\FlutterwaveWebhookController::class, 'handle']);

// Public Channel Webhook Ingress (supports GET challenge verification and POST message ingress)
Route::match(['get', 'post'], '/webhooks/channel/{connection_id}', [\App\Http\Controllers\Channels\ChannelWebhookController::class, 'handle']);

// Public Ecommerce Webhook Ingress (Shopify & WooCommerce)
Route::prefix('webhooks/ecommerce')->group(function () {
    Route::post('shopify', [\App\Http\Controllers\Integrations\EcommerceWebhookController::class, 'handleShopify']);
    Route::post('woocommerce', [\App\Http\Controllers\Integrations\EcommerceWebhookController::class, 'handleWooCommerce']);
});

