<?php

namespace Cerpus\LicenseClient;

use Illuminate\Support\ServiceProvider;

class LicenseClientProvider extends ServiceProvider
{
    //protected $defer = true;

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/config/license.php' => config_path('license.php'),
        ]);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('cerpus-license-client', function ($app) {
            $licenseConfig = config('license');
            return new LicenseClient(config('license'));
        });
    }
}
