<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiStatusTest extends TestCase
{
    public function test_status_endpoint_returns_api_message(): void
    {
        $this->getJson('/api/status')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => 'API funcionando correctamente.',
                'service' => 'EscanerParcial2 API',
            ])
            ->assertJsonStructure(['timestamp']);
    }
}
