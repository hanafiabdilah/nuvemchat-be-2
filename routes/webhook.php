<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/webhook/chat/{id}', function($id){
    Log::info("Webhook subscribed for connection ID: {$id}", request()->all());

    return request()->hub_challenge;
})->name('webhook.chat');

Route::post('/webhook/chat/{id}', function($id){
    Log::info("Webhook chat received for connection ID: {$id}", request()->all());
})->name('webhook.chat');
