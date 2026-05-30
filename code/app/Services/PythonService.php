<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the FastAPI processing service.
 * All Laravel→Python communication should go through here — never hardcode URLs.
 */
class PythonService
{
    private function client(): PendingRequest
    {
        return Http::baseUrl(config('services.python.base_url'))
            ->acceptJson();
    }

    /**
     * Health check (used by /api/test-python).
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->client()->get('/health')->json();
    }
}
