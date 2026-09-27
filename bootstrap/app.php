<?php

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\APIGuestMiddleware;
use App\Http\Middleware\APILocalizationMiddleware;
use App\Http\Middleware\CustomerMiddleware;
use App\Http\Middleware\DatabaseRefreshMiddleware;
use App\Http\Middleware\DeliveryManAuth;
use App\Http\Middleware\GuestMiddleware;
use App\Http\Middleware\HideVendorLegacyFeaturesMiddleware;
use App\Http\Middleware\HidePublicSellerInformationMiddleware;
use App\Http\Middleware\HideAdminDeliveryManFeatureMiddleware;
use App\Http\Middleware\InstallationMiddleware;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Http\Middleware\SellerMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->use([
            TrustProxies::class,
            CheckForMaintenanceMode::class,
            ValidatePostSize::class,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            DatabaseRefreshMiddleware::class,
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        $middleware->group('web', [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\Localization::class,
            \App\Http\Middleware\DetectMobile::class,
            \App\Http\Middleware\RecordPrivacySafePlatformMetric::class,
        ]);
        $middleware->group('api', [
            'throttle:3000,1',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\RecordPrivacySafePlatformMetric::class,
        ]);
        /*
        |--------------------------------------------------------------------------
        | Route Middleware (Aliases)
        |--------------------------------------------------------------------------
        */
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'bindings' => SubstituteBindings::class,
            'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
            'can' => \Illuminate\Auth\Middleware\Authorize::class,
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
            'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'admin' => AdminMiddleware::class,
            'seller' => SellerMiddleware::class,
            'customer' => CustomerMiddleware::class,
            'module' => ModulePermissionMiddleware::class,
            'installation-check' => InstallationMiddleware::class,
            'actch' => ActivationCheckMiddleware::class,
            'api_lang' => APILocalizationMiddleware::class,
            'maintenance_mode' => MaintenanceModeMiddleware::class,
            'delivery_man_auth' => DeliveryManAuth::class,
            'seller_api_auth' => SellerApiAuthMiddleware::class,
            'guestCheck' => GuestMiddleware::class,
            'vendor_hidden_feature' => HideVendorLegacyFeaturesMiddleware::class,
            'hide_public_seller_information' => HidePublicSellerInformationMiddleware::class,
            'admin_delivery_man_hidden' => HideAdminDeliveryManFeatureMiddleware::class,
            'apiGuestCheck' => APIGuestMiddleware::class,
            'logUserBrowsingNavigation' => \App\Http\Middleware\LogUserBrowsingNavigationMiddleware::class,
            'detectMobile' => \App\Http\Middleware\DetectMobile::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('seller-products:expire-entitlements')
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command('seller-promotions:expire')
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command('products:enforce-expiry')
            ->hourly()
            ->withoutOverlapping();
        $schedule->command('packages:expire-subscriptions')
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command('seller-settlements:release-due')
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command('customer-insurance:release-due')
            ->hourly()
            ->withoutOverlapping();
        $schedule->command('seller-order-insurances:release-reusable')
            ->dailyAt('02:25')
            ->withoutOverlapping();
        $schedule->command('support-tickets:archive-closed')
            ->dailyAt('02:15')
            ->withoutOverlapping();
        $schedule->command('orders:monitor-seller-delays')
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command('orders:expire-customer-confirmations')
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command('orders:expire-customer-insurance-payments')
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command('orders:release-customer-purchase-refunds')
            ->everyFiveMinutes()
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // You can customize exception handling here if needed
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('post-purchase-invoices:expire')->everyFiveMinutes()->withoutOverlapping();
        $schedule->call(fn () => app(\App\Services\SellerShippingResponseService::class)->monitorOverdueResponses())
            ->name('seller-shipping-response-overdue-monitor')
            ->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('commerce:release-matured-refunds')->hourly()->withoutOverlapping();
    })
    ->create();
