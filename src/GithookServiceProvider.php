<?php

namespace Zzktz\LaravelGithook;

use Illuminate\Support\ServiceProvider;
use Zzktz\LaravelGithook\Services\DeploymentService;
use Zzktz\LaravelGithook\Services\GitClient;

class GithookServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/githook.php', 'githook');

        $this->app->singleton(GitClient::class, function () {
            return new GitClient();
        });

        $this->app->singleton(DeploymentService::class, function ($app) {
            return new DeploymentService($app->make(GitClient::class));
        });
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/githook.php' => config_path('githook.php'),
        ], 'githook-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/webhook.php');
    }
}
