<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ScannerController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PruebaBDController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Laravel adds the /api prefix automatically. The web middleware keeps the
| existing session-based login compatible with the React client.
|
*/

Route::middleware('web')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);

    Route::middleware('auth')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/scan', [ScannerController::class, 'scan']);
        Route::get('/products', [ScannerController::class, 'products']);
        Route::get('/products/{product}', [ScannerController::class, 'product']);
        Route::get('/monitors', [ScannerController::class, 'monitors']);
        Route::put('/products/{product}/monitor', [ScannerController::class, 'monitor']);
        Route::get('/scans', [ScannerController::class, 'history']);
        Route::get('/alerts', [ScannerController::class, 'alerts']);
        Route::patch('/alerts/{alert}/read', [ScannerController::class, 'readAlert']);
        Route::put('/stores/{store}/selectors', [ScannerController::class, 'updateStoreSelectors']);
        Route::post('/stores/preview-html', [ScannerController::class, 'previewHtml']);
    });
});

Route::get('/test', function () {
    return response()->json(['mensaje' => 'API funcionando']);
});

Route::get('/status', function () {
    return response()->json([
        'ok' => true,
        'message' => 'API funcionando correctamente.',
        'service' => 'EscanerParcial2 API',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::get('/prueba-bd', [PruebaBDController::class, 'index']);

Route::get('/debug-cors', function () {
    return response()->json([
        'cors_config' => config('cors'),
        'entorno' => app()->environment(),
        'config_cacheada' => app()->configurationIsCached(),
    ]);
});