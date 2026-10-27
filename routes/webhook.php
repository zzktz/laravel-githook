<?php

use Illuminate\Support\Facades\Route;
use Zzktz\LaravelGithook\Http\Controllers\WebhookController;

$route = config('githook.route', []);

Route::group([
    'prefix' => isset($route['prefix']) ? $route['prefix'] : 'githook',
    'middleware' => isset($route['middleware']) ? $route['middleware'] : ['api'],
], function () use ($route) {
    Route::post(isset($route['uri']) ? $route['uri'] : 'webhook', [WebhookController::class, 'handleGitee'])
        ->name('githook.webhook');

    Route::post(isset($route['github_uri']) ? $route['github_uri'] : 'github', [WebhookController::class, 'handleGithub'])
        ->name('githook.github');
});
