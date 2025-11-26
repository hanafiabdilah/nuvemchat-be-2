<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/chat/{id}', function($id){
    Log::info("Webhook chat received for connection ID: {$id}", request()->all());
})->name('webhook.chat');
