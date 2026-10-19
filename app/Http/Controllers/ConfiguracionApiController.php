<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ConfiguracionApiController extends Controller
{
    public function index(Request $request)
    {
        $cors = config('cors', []);

        $origenesPermitidos = $cors['allowed_origins'] ?? [];
        $patronesPermitidos = $cors['allowed_origins_patterns'] ?? [];
        $origenSolicitante = $request->header('Origin');

        $origenPermitido = false;

        if ($origenSolicitante) {
            $origenPermitido = in_array(
                '*',
                $origenesPermitidos,
                true
            ) || in_array(
                $origenSolicitante,
                $origenesPermitidos,
                true
            );

            if (!$origenPermitido) {
                foreach ($patronesPermitidos as $patron) {
                    if (@preg_match('~' . $patron . '~', $origenSolicitante)) {
                        $origenPermitido = true;
                        break;
                    }
                }
            }
        }

        return response()->json([
            'diagnostico' => [
                'estado' => 'API funcionando',
                'entorno' => app()->environment(),
                'debug_activo' => (bool) config('app.debug'),
                'url_backend' => config('app.url'),
                'url_frontend' => env('FRONTEND_URL'),
                'configuracion_cacheada' => app()->configurationIsCached(),
            ],

            'cors' => [
                'configuracion_cargada' => !empty($cors),
                'paths' => $cors['paths'] ?? [],
                'metodos_permitidos' => $cors['allowed_methods'] ?? [],
                'origenes_permitidos' => $origenesPermitidos,
                'patrones_permitidos' => $patronesPermitidos,
                'encabezados_permitidos' => $cors['allowed_headers'] ?? [],
                'credenciales_permitidas' => $cors['supports_credentials'] ?? false,
                'max_age' => $cors['max_age'] ?? null,
            ],

            'peticion' => [
                'metodo' => $request->method(),
                'ruta' => $request->path(),
                'origen_recibido' => $origenSolicitante,
                'origen_coincide_con_configuracion' => $origenPermitido,
            ],

            'middleware' => [
                'HandleCors_existe' => class_exists(
                    \Illuminate\Http\Middleware\HandleCors::class
                ),
            ],
        ]);
    }
}
