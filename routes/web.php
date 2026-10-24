<?php

use Chat\Http\Controllers\AssetController;
use Chat\Http\Controllers\ChatPageController;
use Chat\Http\Middleware\SetFrameAncestors;
use Illuminate\Support\Facades\Route;

Route::middleware(config('chat.asset_middleware', []))->group(function () {
    Route::get(config('chat.asset_route_prefix', 'chat/assets').'/app.js', [AssetController::class, 'js']);
    Route::get(config('chat.asset_route_prefix', 'chat/assets').'/app.css', [AssetController::class, 'css']);
});

Route::middleware(config('chat.web_middleware', ['web', 'auth']))
    ->prefix(config('chat.chat_route_prefix', 'chat'))
    ->group(function () {
        Route::get('/', [ChatPageController::class, 'show'])
            ->middleware(SetFrameAncestors::class)
            ->name('chat.page');
    });
