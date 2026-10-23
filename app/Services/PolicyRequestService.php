<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class PolicyRequestService
{
    public function __construct(
        private readonly PremiumCalculator $premiumCalculator,
        private readonly StateTransitionService $stateTransitionService
    ) {}

    /**
     * Ingest a policy request with strict idempotency and database-level concurrency protection.
     *
     * @param  array<string, mixed>  $data
     * @return array{policy_request: PolicyRequest, is_duplicate: bool}
     */
    public function intake(array $data): array
    {
        $agentId = trim((string) $data['agent_id']);
        $clientRequestId = trim((string) $data['client_request_id']);

        // 1. Fast check for existing request
        $existing = PolicyRequest::query()
            ->where('agent_id', $agentId)
            ->where('client_request_id', $clientRequestId)
            ->first();

        if ($existing) {
            return [
                'policy_request' => $existing,
                'is_duplicate' => true,
            ];
        }

        // 2. Calculate immutable premium snapshot
        $calc = $this->premiumCalculator->calculate(
            planCode: $data['plan_code'],
            destination: $data['destination'],
            sumInsured: (int) $data['sum_insured'],
            dateOfBirth: $data['date_of_birth'],
            tripStart: $data['trip_start'],
            tripEnd: $data['trip_end']
        );

        // 3. Atomically persist new request and transition RECEIVED -> VALIDATED
        try {
            $policyRequest = DB::transaction(function () use ($data, $calc, $agentId, $clientRequestId) {
                $record = PolicyRequest::create([
                    'client_request_id' => $clientRequestId,
                    'agent_id' => $agentId,
                    'customer_name' => trim((string) $data['customer_name']),
                    'date_of_birth' => $data['date_of_birth'],
                    'destination' => strtoupper(trim((string) $data['destination'])),
                    'trip_start' => $data['trip_start'],
                    'trip_end' => $data['trip_end'],
                    'plan_code' => strtoupper(trim((string) $data['plan_code'])),
                    'sum_insured' => (int) $data['sum_insured'],
                    'travel_days' => $calc['travel_days'],
                    'premium' => $calc['premium'],
                    'status' => PolicyStatus::RECEIVED,
                    'attempt_count' => 0,
                ]);

                // Transition to VALIDATED
                return $this->stateTransitionService->transition(
                    $record,
                    PolicyStatus::VALIDATED,
                    'Initial intake validation passed and premium calculated'
                );
            });

            return [
                'policy_request' => $policyRequest,
                'is_duplicate' => false,
            ];
        } catch (UniqueConstraintViolationException|QueryException $e) {
            // Concurrent intake conflict caught by DB composite unique index (agent_id, client_request_id)
            $existing = PolicyRequest::query()
                ->where('agent_id', $agentId)
                ->where('client_request_id', $clientRequestId)
                ->first();

            if ($existing) {
                return [
                    'policy_request' => $existing,
                    'is_duplicate' => true,
                ];
            }

            throw $e;
        }
    }
}
