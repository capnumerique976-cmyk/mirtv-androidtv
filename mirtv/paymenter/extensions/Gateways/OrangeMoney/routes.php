<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\OrangeMoney\OrangeMoney;

// Avis d'Orange : sans jeton anti-falsification (c'est Orange qui appelle), débit limité
Route::post('/extensions/gateways/orangemoney/avis', [OrangeMoney::class, 'avis'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->middleware('throttle:60,1')
    ->name('extensions.gateways.orangemoney.avis');

Route::middleware('web')->group(function () {
    Route::get('/extensions/gateways/orangemoney/retour/{commande}', [OrangeMoney::class, 'retour'])
        ->middleware('throttle:120,1')
        ->name('extensions.gateways.orangemoney.retour');
    Route::match(['get', 'post'], '/extensions/gateways/orangemoney/simulation/{commande}', [OrangeMoney::class, 'simulation'])
        ->middleware('throttle:30,1')
        ->name('extensions.gateways.orangemoney.simulation');
});
