<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class BrowserScanner
{
    public function scan(string $url, ?array $selectors = null): array
    {
        $temporaryDirectory = storage_path('app/playwright-temp');
        if (!is_dir($temporaryDirectory) && !mkdir($temporaryDirectory, 0775, true) && !is_dir($temporaryDirectory)) {
            throw new RuntimeException('No se pudo crear directorio temporal del navegador.');
        }

        $environment = [
            'TEMP' => $temporaryDirectory,
            'TMP' => $temporaryDirectory,
            'TMPDIR' => $temporaryDirectory,
        ];
        $accessToken = config('scanner.mercadolibre_access_token');
        if (is_string($accessToken) && trim($accessToken) !== '') {
            $environment['MERCADOLIBRE_ACCESS_TOKEN'] = trim($accessToken);
        }

        $result = Process::env(array_merge(getenv(), $environment))
            ->timeout((int) config('scanner.timeout', 45))
            ->run(['node', base_path('browser-scanner/scan.mjs'), $url, json_encode($selectors ?? [])]);

        $payload = json_decode(trim($result->output()), true);
        if (!is_array($payload)) {
            $details = trim($result->errorOutput()) ?: trim($result->output());
            throw new RuntimeException($details ? "El navegador no devolvió JSON válido: {$details}" : 'El navegador no devolvió datos válidos.');
        }
        if (($payload['status'] ?? null) === 'challenge') {
            $diagnostics = isset($payload['diagnostics']) ? ' Diagnóstico: '.json_encode($payload['diagnostics'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            throw new RuntimeException(($payload['error'] ?? 'La tienda requiere verificación humana.').$diagnostics);
        }
        if (!$result->successful() || ($payload['status'] ?? null) !== 'completed') {
            throw new RuntimeException($payload['error'] ?? trim($result->errorOutput()) ?: 'No se pudo cargar la página.');
        }

        return [
            'name' => trim((string) ($payload['name'] ?? '')) ?: null,
            'price' => $payload['price'] ?? null,
            'currency' => $payload['currency'] ?? 'MXN',
            'image' => $payload['image'] ?? null,
            'custom_fields' => $payload['custom_fields'] ?? [],
            'diagnostics' => $payload['diagnostics'] ?? [],
        ];
    }

    public function preview(string $url): string
    {
        $temporaryDirectory = storage_path('app/playwright-temp');
        if (!is_dir($temporaryDirectory) && !mkdir($temporaryDirectory, 0775, true) && !is_dir($temporaryDirectory)) {
            throw new RuntimeException('No se pudo crear directorio temporal del navegador.');
        }

        $result = Process::env(array_merge(getenv(), [
            'TEMP' => $temporaryDirectory,
            'TMP' => $temporaryDirectory,
            'TMPDIR' => $temporaryDirectory,
        ]))->timeout((int) config('scanner.timeout', 45))
            ->run(['node', base_path('browser-scanner/scan.mjs'), $url, '{}', '--preview']);

        $payload = json_decode(trim($result->output()), true);
        if (!$result->successful() || !is_array($payload) || ($payload['status'] ?? null) !== 'completed') {
            throw new RuntimeException($payload['error'] ?? 'No se pudo cargar el HTML de la tienda.');
        }

        return (string) ($payload['html'] ?? '');
    }
}
