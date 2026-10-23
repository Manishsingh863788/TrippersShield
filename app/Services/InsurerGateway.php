<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class InsurerGateway
{
    private string $baseUrl;
    private bool $isInternal;

    public function __construct(
        private readonly MockInsurerService $mockInsurerService
    ) {
        $this->baseUrl = config('services.mock_insurer.base_url', 'http://127.0.0.1:8000/api/mock-insurer');
        $this->isInternal = (bool) config('services.mock_insurer.internal', true);
    }

    /**
     * Submit an issuance request to the insurer gateway.
     *
     * @param  array<string, mixed>  $payload
     * @param  string|null  $scenarioHeader
     * @return array{status_code: int, data: array<string, mixed>}
     */
    public function submitIssuance(array $payload, ?string $scenarioHeader = null): array
    {
        // When running in local single-threaded server mode, dispatch internally to prevent self-deadlock
        if ($this->isInternal) {
            return $this->mockInsurerService->processIssuance($payload, $scenarioHeader);
        }

        $headers = [];
        if ($scenarioHeader) {
            $headers['X-Mock-Scenario'] = $scenarioHeader;
        }

        try {
            $response = Http::timeout(3)
                ->withHeaders($headers)
                ->post("{$this->baseUrl}/issue", $payload);

            return [
                'status_code' => $response->status(),
                'data' => $response->json() ?? [],
            ];
        } catch (ConnectionException $e) {
            return [
                'status_code' => 504,
                'data' => [
                    'success' => false,
                    'error_category' => 'NETWORK_TIMEOUT',
                    'message' => 'Connection timed out while communicating with insurer gateway: ' . $e->getMessage(),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status_code' => 500,
                'data' => [
                    'success' => false,
                    'error_category' => 'GATEWAY_ERROR',
                    'message' => 'Unexpected error communicating with insurer gateway: ' . $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Lookup policy status on the insurer gateway using the stable issuance reference.
     *
     * @return array{status_code: int, data: array<string, mixed>}
     */
    public function lookupStatus(string $reference): array
    {
        if ($this->isInternal) {
            return $this->mockInsurerService->lookup($reference);
        }

        try {
            $response = Http::timeout(3)
                ->get("{$this->baseUrl}/policies/{$reference}");

            return [
                'status_code' => $response->status(),
                'data' => $response->json() ?? [],
            ];
        } catch (Throwable $e) {
            return [
                'status_code' => 500,
                'data' => [
                    'found' => false,
                    'message' => 'Failed to query insurer gateway: ' . $e->getMessage(),
                ],
            ];
        }
    }
}
