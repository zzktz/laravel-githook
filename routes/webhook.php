<?php

use Illuminate\Support\Facades\Route;
use Zzktz\LaravelGithook\Http\Controllers\WebhookController;

$route = config('githook.route', []);

Route::group([
    'prefix' => isset($route['prefix']) ? $route['prefix'] : 'githook',
    'middleware' => isset($route['middleware']) ? $route['middleware'] : ['api'],
], function () use ($route) {
    Route::post(isset($route['uri']) ? $route['uri'] : 'webhook', [WebhookController::class, 'handle'])
        ->name('githook.webhook');
});
