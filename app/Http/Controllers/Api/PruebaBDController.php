<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class PruebaBDController extends Controller
{
    public function index()
    {
        $tablas = DB::select("
            SELECT name
            FROM sqlite_master
            WHERE type = 'table'
            AND name NOT LIKE 'sqlite_%'
        ");

        $datos = [];

        foreach ($tablas as $tabla) {
            $nombre = $tabla->name;

            $datos[$nombre] = DB::table($nombre)
                ->limit(10)
                ->get();
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'SQLite conectado correctamente',
            'tablas' => $tablas,
            'datos' => $datos
        ]);
    }
}