
<?php

namespace maxviex\TuyaLaravel;

use Illuminate\Support\ServiceProvider;

class TuyaServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/tuya.php', 'tuya'
        );

        $this->app->singleton(TuyaClient::class, function ($app) {
            return new TuyaClient(
                config('tuya.access_id'),
                config('tuya.access_secret'),
                config('tuya.api_host')
            );
        });

        $this->app->alias(TuyaClient::class, 'tuya');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tuya.php' => config_path('tuya.php'),
            ], 'tuya-config');
        }
    }
}