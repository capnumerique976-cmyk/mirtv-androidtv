<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\MirTV\MirTV;

Route::middleware('web')->group(function () {
    // Page de paiement de la famille (lien du QR code) : formules, puis Orange Money
    Route::match(['get', 'post'], '/payer/{jeton}', [MirTV::class, 'payer'])
        ->where('jeton', '[a-f0-9]{32}')->middleware('throttle:30,1')->name('mirtv.payer');
});

// Lu par l'appli MirTV (autre origine : mirtv.rahiss.com) — pas de session, pas de jeton anti-falsification
Route::get('/abonnement/{jeton}', [MirTV::class, 'abonnement'])->where('jeton', '[a-f0-9]{32}')->middleware('throttle:60,1');
Route::get('/mirtv/etat', [MirTV::class, 'etatEcran'])->middleware('throttle:60,1');
Route::post('/mirtv/activer', [MirTV::class, 'activer'])->withoutMiddleware([PreventRequestForgery::class])->middleware('throttle:10,1');
Route::options('/mirtv/{chemin}', [MirTV::class, 'options'])->where('chemin', 'etat|activer');
Route::options('/abonnement/{jeton}', [MirTV::class, 'options']);
