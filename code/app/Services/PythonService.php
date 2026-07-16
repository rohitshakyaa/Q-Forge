<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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

    /**
     * Extract question candidates from a document on the shared volume.
     *
     * @param  string  $pythonPath  Absolute path as the Python container sees it.
     * @return array{pages:int, ocr_pages:int, candidates:array<int, array<string, mixed>>, meta:array<string, mixed>}
     *
     * @throws RuntimeException when the service is unreachable or reports failure.
     */
    public function extract(string $pythonPath, string $type): array
    {
        $response = $this->client()
            ->timeout((int) config('services.python.extract_timeout'))
            ->post('/extract', ['path' => $pythonPath, 'type' => $type]);

        if ($response->failed()) {
            throw new RuntimeException("python /extract returned HTTP {$response->status()}");
        }

        $body = $response->json();

        // Python reports parse failures in-band with a 200 so the caller can record
        // the reason rather than retry a file that will never parse.
        if (($body['status'] ?? null) !== 'success') {
            $errors = $body['errors'] ?? ['python /extract reported an unknown error'];

            throw new RuntimeException(implode('; ', $errors));
        }

        return $body['data'];
    }

    /**
     * Ask Python to author `count` candidate questions from a Laravel-assembled
     * grounding block (M5). `$unitNames` (0–2) names the target units in the
     * prompt — two names ask for questions spanning both; the response never
     * echoes units, Laravel stays authoritative and stamps them on save.
     * Returns the valid subset in `data` and any malformed items in `errors` —
     * partial success is normal and never discarded.
     *
     * @param  string[]  $unitNames
     * @return array{data: array<int, array<string, mixed>>, errors: array<int, string>}
     *
     * @throws RuntimeException when the service is unreachable or reports failure.
     */
    public function generateQuestions(string $grounding, string $type, int $marks, int $count, array $unitNames = []): array
    {
        $response = $this->client()
            ->timeout((int) config('services.python.generate_timeout'))
            ->post('/generate-questions', [
                'grounding' => $grounding,
                'type' => $type,
                'marks' => $marks,
                'count' => $count,
                'units' => $unitNames,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("python /generate-questions returned HTTP {$response->status()}");
        }

        $body = $response->json();

        if (($body['status'] ?? null) !== 'success') {
            $errors = $body['errors'] ?? ['python /generate-questions reported an unknown error'];

            throw new RuntimeException(implode('; ', $errors));
        }

        return [
            'data' => $body['data'] ?? [],
            'errors' => $body['errors'] ?? [],
        ];
    }
}
