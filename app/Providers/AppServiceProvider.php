<?php

namespace App\Providers;

use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\SemaphoreSmsGateway;
use App\Services\Sms\SmsGatewayInterface;
use App\Support\Supabase\LockDown;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmsGatewayInterface::class, function () {
            return match (config('services.sms.driver', 'log')) {
                'semaphore' => new SemaphoreSmsGateway(),
                default => new LogSmsGateway(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A live HTTPS site never links its CSS/JS over http:// (browsers block
        // it), whatever a proxy header says.
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
        if (config('hosting.serverless')) {
            config(['dompdf.options.font_dir' => storage_path('fonts'),
                'dompdf.options.font_cache' => storage_path('fonts'),
                'dompdf.options.temp_dir' => sys_get_temp_dir(),
                'dompdf.options.chroot' => [base_path(), sys_get_temp_dir()]]);
        }
        // Every record id in a URL is a number. Without this, /incidents/abc
        // reaches PostgreSQL as a type error (500) instead of a plain 404.
        foreach (self::NUMERIC_ROUTE_PARAMETERS as $parameter) {
            Route::pattern($parameter, '[0-9]+');
        }

        // Supabase: after every migrate, no table is readable through the
        // public Data API (see App\Support\Supabase\LockDown).
        Event::listen(MigrationsEnded::class, fn () => LockDown::run());
    }

    /** Route parameters that are always a record's numeric id. */
    public const NUMERIC_ROUTE_PARAMETERS = [
        'advisory', 'alert', 'attachment', 'backup', 'building', 'damage_report', 'drill',
        'group', 'id', 'incident', 'inspection', 'personnel', 'school', 'report', 'sos', 'campus', 'notification',
        'rollCall', 'responder', 'assignment', 'broadcast',
    ];
}
