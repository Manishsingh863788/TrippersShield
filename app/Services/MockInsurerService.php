<?php

namespace App\Services;

use App\Models\MockInsurerPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class MockInsurerService
{
    /**
     * Process an issuance request on the Mock Insurer side.
     * Enforces provider-level idempotency on issuance_reference.
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $scenario
     */
    public function processIssuance(array $data, ?string $scenario = null): array
    {
        $reference = trim((string) $data['issuance_reference']);
        $scenario = strtoupper(trim((string) ($scenario ?: ($data['scenario'] ?? 'SUCCESS'))));

        // 1. Enforce Provider-Level Idempotency: Return existing policy if already issued
        $existing = MockInsurerPolicy::where('issuance_reference', $reference)->first();
        if ($existing) {
            return [
                'status_code' => 200,
                'data' => [
                    'success' => true,
                    'status' => $existing->status,
                    'policy_number' => $existing->policy_number,
                    'provider_reference' => $existing->provider_reference,
                    'issuance_reference' => $existing->issuance_reference,
                    'message' => 'Policy returned idempotently by insurer',
                ],
            ];
        }

        // 2. Scenario Handling
        if ($scenario === 'TEMPORARY_FAILURE') {
            return [
                'status_code' => 503,
                'data' => [
                    'success' => false,
                    'error_category' => 'TEMPORARY_NETWORK_FAILURE',
                    'message' => 'Insurer issuing engine temporarily unavailable. Please retry.',
                ],
            ];
        }

        // Generate stable mock provider credentials
        $policyNumber = 'POL-' . strtoupper(Str::random(10));
        $providerRef = 'REF-' . strtoupper(Str::random(12));

        if ($scenario === 'TIMEOUT_AFTER_ISSUE') {
            // Persist policy on insurer side, but simulate network/response timeout to the caller
            MockInsurerPolicy::create([
                'issuance_reference' => $reference,
                'policy_number' => $policyNumber,
                'provider_reference' => $providerRef,
                'client_request_id' => $data['client_request_id'] ?? 'UNKNOWN',
                'agent_id' => $data['agent_id'] ?? 'UNKNOWN',
                'customer_name' => $data['customer_name'] ?? 'UNKNOWN',
                'plan_code' => $data['plan_code'] ?? 'UNKNOWN',
                'destination' => $data['destination'] ?? 'UNKNOWN',
                'sum_insured' => (int) ($data['sum_insured'] ?? 0),
                'premium' => (string) ($data['premium'] ?? '0.00'),
                'status' => 'ISSUED',
                'payload' => $data,
            ]);

            return [
                'status_code' => 504,
                'data' => [
                    'success' => false,
                    'error_category' => 'SIMULATED_TIMEOUT',
                    'message' => 'Gateway timed out waiting for upstream insurer response.',
                ],
            ];
        }

        if ($scenario === 'DELAYED_CALLBACK') {
            MockInsurerPolicy::create([
                'issuance_reference' => $reference,
                'policy_number' => $policyNumber,
                'provider_reference' => $providerRef,
                'client_request_id' => $data['client_request_id'] ?? 'UNKNOWN',
                'agent_id' => $data['agent_id'] ?? 'UNKNOWN',
                'customer_name' => $data['customer_name'] ?? 'UNKNOWN',
                'plan_code' => $data['plan_code'] ?? 'UNKNOWN',
                'destination' => $data['destination'] ?? 'UNKNOWN',
                'sum_insured' => (int) ($data['sum_insured'] ?? 0),
                'premium' => (string) ($data['premium'] ?? '0.00'),
                'status' => 'PENDING',
                'payload' => $data,
            ]);

            return [
                'status_code' => 202,
                'data' => [
                    'success' => true,
                    'status' => 'PENDING',
                    'provider_reference' => $providerRef,
                    'issuance_reference' => $reference,
                    'message' => 'Policy issuance is pending; outcome will be delivered via callback.',
                ],
            ];
        }

        // Default SUCCESS
        $mockPolicy = MockInsurerPolicy::create([
            'issuance_reference' => $reference,
            'policy_number' => $policyNumber,
            'provider_reference' => $providerRef,
            'client_request_id' => $data['client_request_id'] ?? 'UNKNOWN',
            'agent_id' => $data['agent_id'] ?? 'UNKNOWN',
            'customer_name' => $data['customer_name'] ?? 'UNKNOWN',
            'plan_code' => $data['plan_code'] ?? 'UNKNOWN',
            'destination' => $data['destination'] ?? 'UNKNOWN',
            'sum_insured' => (int) ($data['sum_insured'] ?? 0),
            'premium' => (string) ($data['premium'] ?? '0.00'),
            'status' => 'ISSUED',
            'payload' => $data,
        ]);

        return [
            'status_code' => 200,
            'data' => [
                'success' => true,
                'status' => 'ISSUED',
                'policy_number' => $mockPolicy->policy_number,
                'provider_reference' => $mockPolicy->provider_reference,
                'issuance_reference' => $mockPolicy->issuance_reference,
                'message' => 'Policy issued successfully.',
            ],
        ];
    }

    /**
     * Query policy status on the Mock Insurer using the stable issuance reference.
     */
    public function lookup(string $reference): array
    {
        $policy = MockInsurerPolicy::where('issuance_reference', $reference)
            ->orWhere('provider_reference', $reference)
            ->first();

        if (! $policy) {
            return [
                'status_code' => 404,
                'data' => [
                    'found' => false,
                    'message' => "No policy found for reference [{$reference}] on insurer gateway.",
                ],
            ];
        }

        return [
            'status_code' => 200,
            'data' => [
                'found' => true,
                'status' => $policy->status,
                'policy_number' => $policy->policy_number,
                'provider_reference' => $policy->provider_reference,
                'issuance_reference' => $policy->issuance_reference,
                'customer_name' => $policy->customer_name,
            ],
        ];
    }
}
