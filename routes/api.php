<?php

use App\Http\Controllers\Api\AuthPosteController;
use App\Http\Controllers\Api\EnrolementController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\VersionController;
use Illuminate\Support\Facades\Route;

// API des postes de site. C'est toujours le poste qui appelle le serveur.

// Version du poste publiee et heure du serveur ; le jar se telecharge a part (voir VersionController)
Route::get('/version', [VersionController::class, 'version'])->middleware('throttle:60,1');
Route::get('/poste/telecharger', [VersionController::class, 'telecharger'])
    ->name('api.poste.telecharger')->middleware('throttle:10,1');

Route::post('/postes/enroler', [EnrolementController::class, 'enroler'])->middleware('throttle:10,1');

Route::middleware('poste')->group(function () {
    Route::post('/auth/verifier', [AuthPosteController::class, 'verifier'])->middleware('throttle:20,1');

    Route::get('/sync/instantane', [SyncController::class, 'instantane']);
    Route::get('/sync/tirer', [SyncController::class, 'tirer']);
    Route::post('/sync/pousser', [SyncController::class, 'pousser']);
});
