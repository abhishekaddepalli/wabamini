<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\Channels\ChannelManager::class, function ($app) {
            return new \App\Services\Channels\ChannelManager();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enforce APP_URL and HTTPS scheme from environment/config for all generated and signed URLs
        $appUrl = config('app.url') ?: env('APP_URL');
        if (!empty($appUrl)) {
            \Illuminate\Support\Facades\URL::forceRootUrl($appUrl);
            if (str_starts_with($appUrl, 'https://')) {
                \Illuminate\Support\Facades\URL::forceScheme('https');
            }
        }

        // Customize the password reset link to point directly to the frontend URL
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $frontendUrl = self::getFrontendUrl($notifiable);
            $locale = $notifiable->language_preference ?? 'en';
            return "{$frontendUrl}/{$locale}/reset-password?token={$token}&email=" . urlencode($notifiable->getEmailForVerification());
        });

        // Customize email verification URL to guarantee it uses APP_URL from .env
        \Illuminate\Auth\Notifications\VerifyEmail::createUrlUsing(function (object $notifiable) {
            $appUrl = rtrim(config('app.url') ?: env('APP_URL'), '/');
            if (!empty($appUrl)) {
                \Illuminate\Support\Facades\URL::forceRootUrl($appUrl);
                if (str_starts_with($appUrl, 'https://')) {
                    \Illuminate\Support\Facades\URL::forceScheme('https');
                }
            }

            return \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'verification.verify',
                \Illuminate\Support\Carbon::now()->addMinutes(\Illuminate\Support\Facades\Config::get('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
        });

        // Register CRM Contact Observers
        \App\Models\Contact::observe(\App\Observers\ContactObserver::class);
        \App\Models\Tenant::observe(\App\Observers\TenantObserver::class);
        \App\Models\User::observe(\App\Observers\UserObserver::class);

        // Dynamically configure platform mailer from PlatformSettings table
        self::loadDynamicMailConfiguration();

        // Reload configuration before processing queue jobs
        try {
            \Illuminate\Support\Facades\Queue::before(function (\Illuminate\Queue\Events\JobProcessing $event) {
                self::loadDynamicMailConfiguration();
            });
        } catch (\Exception $e) {
            // Fallback if Queue manager fails to bind during CLI start
        }
    }

    /**
     * Dynamically configure platform mailer from PlatformSettings table.
     */
    public static function loadDynamicMailConfiguration(): void
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('platform_settings')) {
                $settings = \App\Models\PlatformSetting::where('key', 'mailer')->first();
                if ($settings && !empty($settings->value)) {
                    $config = $settings->value;
                    $type = $config['type'] ?? 'smtp';
                    
                    if ($type === 'smtp' && !empty($config['smtp'])) {
                        $smtp = $config['smtp'];
                        $port = (int)($smtp['port'] ?? 1025);
                        
                        $encryption = $smtp['encryption'] ?? null;
                        if ($encryption === 'none') {
                            $encryption = null;
                        }
                        if ($encryption === null) {
                            if ($port === 465) {
                                $encryption = 'ssl';
                            } elseif ($port === 587) {
                                $encryption = 'tls';
                            }
                        }
                        
                        $scheme = null;
                        if ($encryption === 'ssl' || $port === 465) {
                            $scheme = 'smtps';
                        }
                        
                        config([
                            'mail.default' => 'smtp',
                            'mail.mailers.smtp.scheme' => $scheme,
                            'mail.mailers.smtp.host' => $smtp['host'] ?? '127.0.0.1',
                            'mail.mailers.smtp.port' => $port,
                            'mail.mailers.smtp.encryption' => $encryption,
                            'mail.mailers.smtp.username' => $smtp['username'] ?? '',
                            'mail.mailers.smtp.password' => $smtp['password'] ?? '',
                            'mail.from.address' => $smtp['from_address'] ?? 'noreply@whatsomni.com',
                            'mail.from.name' => $smtp['from_name'] ?? 'WhatsOmni',
                        ]);
                    } elseif ($type === 'resend' && !empty($config['resend'])) {
                        $resend = $config['resend'];
                        config([
                            'mail.default' => 'resend',
                            'services.resend.key' => $resend['api_key'] ?? '',
                            'mail.from.address' => $resend['from_address'] ?? 'noreply@whatsomni.com',
                            'mail.from.name' => $resend['from_name'] ?? 'WhatsOmni',
                        ]);
                    }

                    // Flush resolved mailer cache to pick up the updated settings
                    if (app()->resolved('mail.manager')) {
                        app()->make('mail.manager')->forgetMailers();
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fall back if migration hasn't run or database is offline
        }
    }

    /**
     * Dynamically retrieve the active frontend URL from environment/config or request headers.
     */
    public static function getFrontendUrl($user = null): string
    {
        if ($user && !empty($user->frontend_url)) {
            return rtrim($user->frontend_url, '/');
        }

        // 1. If incoming request has an Origin or Referer matching allowed frontend URLs
        if (app()->runningInConsole() === false && request()) {
            $origin = request()->header('Origin') ?: request()->header('Referer');
            if ($origin) {
                $parsed = parse_url($origin);
                if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
                    $reqHost = $parsed['scheme'] . '://' . $parsed['host'] . (!empty($parsed['port']) ? ':' . $parsed['port'] : '');
                    $configured = array_map('trim', explode(',', env('FRONTEND_URL', '')));
                    foreach ($configured as $allowedUrl) {
                        if ($allowedUrl === $reqHost) {
                            return rtrim($reqHost, '/');
                        }
                    }
                }
            }
        }

        // 2. Resolve from FRONTEND_URL or APP_URL
        $rawFrontendUrl = env('FRONTEND_URL') ?: config('app.frontend_url', 'http://localhost:3000');
        $urls = array_filter(array_map('trim', explode(',', $rawFrontendUrl)));

        if (!empty($urls)) {
            // Check reachable port if multiple local URLs provided
            foreach ($urls as $candidate) {
                $port = parse_url($candidate, PHP_URL_PORT);
                if ($port) {
                    $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.05);
                    if ($connection) {
                        fclose($connection);
                        return rtrim($candidate, '/');
                    }
                }
            }
            return rtrim(reset($urls), '/');
        }

        return rtrim(config('app.url', env('APP_URL', 'http://localhost:3000')), '/');
    }
}
