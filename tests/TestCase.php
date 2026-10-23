<?php

namespace Tests;

use App\Services\MockInsurerService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Set up Http::fake mapping to MockInsurerService for fault-tolerant integration testing.
     */
    protected function setupMockInsurerGateway(): void
    {
        Http::fake([
            '*/api/mock-insurer/issue' => function (Request $request) {
                $scenario = $request->header('X-Mock-Scenario')[0] ?? ($request->data()['scenario'] ?? null);
                $result = app(MockInsurerService::class)->processIssuance($request->data(), $scenario);

                return Http::response($result['data'], $result['status_code']);
            },
            '*/api/mock-insurer/policies/*' => function (Request $request) {
                $ref = basename($request->url());
                $result = app(MockInsurerService::class)->lookup($ref);

                return Http::response($result['data'], $result['status_code']);
            },
        ]);
    }
}
