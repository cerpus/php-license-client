<?php

namespace Cerpus\LicenseClient\Providers;

use Cerpus\Helper\Clients\Client;
use Cerpus\Helper\Clients\JWT;
use Cerpus\Helper\Clients\Oauth1Client;
use Cerpus\Helper\Clients\Oauth2Client;
use Cerpus\Helper\DataObjects\OauthSetup;
use Cerpus\LicenseClient\Adapter\LicenseApiAdapter;
use Cerpus\LicenseClient\Contracts\LicenseClientContract;
use Cerpus\LicenseClient\Contracts\LicenseContract;
use Cerpus\LicenseClient\LicenseClient;
use Illuminate\Support\ServiceProvider;

class LicenseClientProvider extends ServiceProvider
{
    protected $defer = true;

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            LicenseClient::getConfigPath() => config_path('license.php'),
        ]);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(LicenseClientContract::class, function($app, $params) {
            $licenseclient = $app['config']->get("license");
            $client = $params['authClient'] ?? strtolower($licenseclient['authClient']);
            /** @var LicenseClientContract $clientClass */
            switch ($client) {
                case "oauth1":
                case Oauth1Client::class:
                    $clientClass = Oauth1Client::class;
                    break;
                case "oauth2":
                case Oauth2Client::class:
                    $clientClass = Oauth2Client::class;
                    break;
                case "jwt":
                case JWT::class:
                    $clientClass = JWT::class;
                    break;
                default:
                    $clientClass = Client::class;
                    break;
            }

            return $clientClass::getClient(OauthSetup::create([
                'coreUrl' => $licenseclient['server'],
                'key' => $licenseclient['auth']['user'] ?? '',
                'secret' => $licenseclient['auth']['secret'] ?? '',
                'authUrl' => $licenseclient['auth']['url'],
                'cacheKey' => $licenseclient['cacheKey'],
                'token' => $licenseclient['auth']['token'] ?? null,
                'tokenSecret' => $licenseclient['auth']['token_secret'] ?? null,
            ]));

        });

        $this->app->bind(LicenseContract::class, function($app, $params){
            $licenseclient = $app['config']->get("license");
            $client = $params['authentication'] ?? $app->make(LicenseClientContract::class, $params);
            return new LicenseApiAdapter($client, $licenseclient['site']);
        });

        $this->mergeConfigFrom(LicenseClient::getConfigPath(), LicenseClient::$alias);
    }

    public function provides()
    {
        return [
            LicenseContract::class,
            LicenseClientContract::class,
        ];
    }
}
