<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExtensionApiTest extends TestCase
{
    /**
     * Test health endpoint returns successful status.
     */
    public function test_health_check_returns_success(): void
    {
        $response = $this->getJson('/api/v1/extension/health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'services',
                'supported_languages',
                'version',
            ]);
    }
}
