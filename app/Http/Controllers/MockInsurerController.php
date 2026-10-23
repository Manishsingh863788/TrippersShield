<?php

namespace App\Http\Controllers;

use App\Services\MockInsurerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MockInsurerController extends Controller
{
    public function __construct(
        private readonly MockInsurerService $mockInsurerService
    ) {}

    /**
     * Mock insurer issue endpoint.
     */
    public function issue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'issuance_reference' => ['required', 'string'],
            'client_request_id' => ['required', 'string'],
            'agent_id' => ['required', 'string'],
            'customer_name' => ['required', 'string'],
            'plan_code' => ['required', 'string'],
            'destination' => ['required', 'string'],
            'sum_insured' => ['required'],
            'premium' => ['required'],
            'scenario' => ['nullable', 'string'],
        ]);

        $scenario = $request->header('X-Mock-Scenario', $request->input('scenario'));

        $result = $this->mockInsurerService->processIssuance($validated, $scenario);

        return response()->json($result['data'], $result['status_code']);
    }

    /**
     * Mock insurer status lookup endpoint.
     */
    public function lookup(string $reference): JsonResponse
    {
        $result = $this->mockInsurerService->lookup($reference);

        return response()->json($result['data'], $result['status_code']);
    }
}
