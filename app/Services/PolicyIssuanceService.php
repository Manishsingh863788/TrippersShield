<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use Illuminate\Support\Facades\DB;

class PolicyIssuanceService
{
    public function __construct(
        private readonly InsurerGateway $insurerGateway,
        private readonly StateTransitionService $stateTransitionService
    ) {}

    /**
     * Submit a policy request for insurer issuance with fault tolerance.
     *
     * @return array{status_code: int, message: string, policy_request: PolicyRequest, attempt?: IssuanceAttempt}
     */
    public function submit(PolicyRequest $policyRequest, ?string $scenarioHeader = null): array
    {
        // 1. Transactionally lock request, validate state, and persist attempt record before network call
        $prep = DB::transaction(function () use ($policyRequest) {
            /** @var PolicyRequest $locked */
            $locked = PolicyRequest::query()
                ->where('id', $policyRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $status = $locked->status instanceof PolicyStatus ? $locked->status : PolicyStatus::from((string) $locked->status);

            // If already issued, return 409 conflict
            if ($status === PolicyStatus::ISSUED) {
                return [
                    'action' => 'ALREADY_ISSUED',
                    'locked' => $locked,
                ];
            }

            // If in pending or reconciliation, route directly to reconciliation
            if (in_array($status, [PolicyStatus::PENDING_CONFIRMATION, PolicyStatus::NEEDS_RECONCILIATION], true)) {
                return [
                    'action' => 'RECONCILE',
                    'locked' => $locked,
                ];
            }

            // Only VALIDATED or FAILED can be submitted
            if (! in_array($status, [PolicyStatus::VALIDATED, PolicyStatus::FAILED], true)) {
                throw new InvalidStateTransitionException($status, PolicyStatus::SUBMITTED);
            }

            $nextAttemptNumber = $locked->attempt_count + 1;
            $idempotencyKey = sprintf('PF-REQ-%d-ATTEMPT-%d', $locked->id, $nextAttemptNumber);

            $payload = [
                'issuance_reference' => $idempotencyKey,
                'client_request_id' => $locked->client_request_id,
                'agent_id' => $locked->agent_id,
                'customer_name' => $locked->customer_name,
                'plan_code' => $locked->plan_code,
                'destination' => $locked->destination,
                'sum_insured' => (int) $locked->sum_insured,
                'premium' => (string) $locked->premium,
            ];

            $attempt = IssuanceAttempt::create([
                'policy_request_id' => $locked->id,
                'attempt_number' => $nextAttemptNumber,
                'idempotency_key' => $idempotencyKey,
                'request_payload' => $payload,
                'outcome' => 'IN_PROGRESS',
            ]);

            $transitioned = $this->stateTransitionService->transition(
                $locked,
                PolicyStatus::SUBMITTED,
                "Issuance attempt #{$nextAttemptNumber} dispatched",
                ['attempt_count' => $nextAttemptNumber]
            );

            return [
                'action' => 'PROCEED',
                'locked' => $transitioned,
                'attempt' => $attempt,
                'payload' => $payload,
                'idempotency_key' => $idempotencyKey,
            ];
        });

        if ($prep['action'] === 'ALREADY_ISSUED') {
            return [
                'status_code' => 409,
                'message' => 'Policy has already been issued.',
                'policy_request' => $prep['locked'],
            ];
        }

        if ($prep['action'] === 'RECONCILE') {
            return $this->reconcile($prep['locked']);
        }

        /** @var IssuanceAttempt $attempt */
        $attempt = $prep['attempt'];
        $payload = $prep['payload'];
        $idempotencyKey = $prep['idempotency_key'];

        // 2. Make outbound HTTP call to insurer gateway outside DB transaction
        $response = $this->insurerGateway->submitIssuance($payload, $scenarioHeader);
        $statusCode = $response['status_code'];
        $data = $response['data'];

        // 3. Process outcome
        if ($statusCode === 200 && ($data['success'] ?? false)) {
            $attempt->update([
                'outcome' => 'SUCCESS',
                'provider_response' => $data,
            ]);

            $issued = $this->stateTransitionService->transition(
                $prep['locked'],
                PolicyStatus::ISSUED,
                'Policy successfully issued by insurer',
                [
                    'policy_number' => $data['policy_number'] ?? null,
                    'provider_reference' => $data['provider_reference'] ?? null,
                    'last_provider_response' => $data,
                    'error_message' => null,
                ]
            );

            return [
                'status_code' => 200,
                'message' => 'Policy issued successfully.',
                'policy_request' => $issued,
                'attempt' => $attempt,
            ];
        }

        if ($statusCode === 504 || ($data['error_category'] ?? '') === 'SIMULATED_TIMEOUT' || ($data['error_category'] ?? '') === 'NETWORK_TIMEOUT') {
            // Timeout scenario: Application does not assume failure; enters PENDING_CONFIRMATION
            $attempt->update([
                'outcome' => 'TIMEOUT',
                'error_category' => 'NETWORK_TIMEOUT',
                'provider_response' => $data,
            ]);

            $pending = $this->stateTransitionService->transition(
                $prep['locked'],
                PolicyStatus::PENDING_CONFIRMATION,
                'Gateway timeout while waiting for insurer. Marked pending confirmation for reconciliation.',
                [
                    'last_provider_response' => $data,
                    'error_message' => 'Gateway timeout; awaiting reconciliation.',
                ]
            );

            // Reconcile immediately using stable issuance reference
            return $this->reconcile($pending);
        }

        if ($statusCode === 202) {
            // Asynchronous / delayed callback scenario
            $attempt->update([
                'outcome' => 'PENDING_CALLBACK',
                'provider_response' => $data,
            ]);

            $pending = $this->stateTransitionService->transition(
                $prep['locked'],
                PolicyStatus::PENDING_CONFIRMATION,
                'Insurer accepted request for asynchronous processing.',
                [
                    'provider_reference' => $data['provider_reference'] ?? null,
                    'last_provider_response' => $data,
                ]
            );

            return [
                'status_code' => 202,
                'message' => 'Issuance pending confirmation via provider callback.',
                'policy_request' => $pending,
                'attempt' => $attempt,
            ];
        }

        if ($statusCode === 503) {
            // Temporary retriable failure
            $attempt->update([
                'outcome' => 'TEMPORARY_FAILURE',
                'error_category' => 'TEMPORARY_FAILURE',
                'provider_response' => $data,
            ]);

            $failed = $this->stateTransitionService->transition(
                $prep['locked'],
                PolicyStatus::FAILED,
                'Temporary upstream failure: ' . ($data['message'] ?? 'Service unavailable'),
                [
                    'last_provider_response' => $data,
                    'error_message' => $data['message'] ?? 'Service unavailable',
                ]
            );

            return [
                'status_code' => 503,
                'message' => 'Insurer temporarily unavailable. Request marked FAILED for safe retry.',
                'policy_request' => $failed,
                'attempt' => $attempt,
            ];
        }

        // Default failure
        $attempt->update([
            'outcome' => 'FAILED',
            'error_category' => 'PROVIDER_REJECTED',
            'provider_response' => $data,
        ]);

        $failed = $this->stateTransitionService->transition(
            $prep['locked'],
            PolicyStatus::FAILED,
            'Issuance failed: ' . ($data['message'] ?? 'Unknown error'),
            [
                'last_provider_response' => $data,
                'error_message' => $data['message'] ?? 'Unknown error',
            ]
        );

        return [
            'status_code' => 500,
            'message' => 'Issuance failed.',
            'policy_request' => $failed,
            'attempt' => $attempt,
        ];
    }

    /**
     * Reconcile a pending or uncertain policy request against the insurer.
     *
     * @return array{status_code: int, message: string, policy_request: PolicyRequest}
     */
    public function reconcile(PolicyRequest $policyRequest): array
    {
        $latestAttempt = $policyRequest->attempts()->latest('attempt_number')->first();

        if (! $latestAttempt) {
            return [
                'status_code' => 400,
                'message' => 'No issuance attempts found to reconcile.',
                'policy_request' => $policyRequest,
            ];
        }

        $lookup = $this->insurerGateway->lookupStatus($latestAttempt->idempotency_key);

        if ($lookup['status_code'] === 200 && ($lookup['data']['found'] ?? false)) {
            $data = $lookup['data'];

            if ($data['status'] === 'ISSUED') {
                $latestAttempt->update([
                    'outcome' => 'RECONCILED',
                    'provider_response' => $data,
                ]);

                $reconciled = $this->stateTransitionService->transition(
                    $policyRequest,
                    PolicyStatus::ISSUED,
                    'Policy verified and recovered via insurer reconciliation',
                    [
                        'policy_number' => $data['policy_number'],
                        'provider_reference' => $data['provider_reference'],
                        'last_provider_response' => $data,
                        'error_message' => null,
                    ]
                );

                return [
                    'status_code' => 200,
                    'message' => 'Policy reconciled successfully and issued.',
                    'policy_request' => $reconciled,
                ];
            }
        }

        // Could not confirm issuance; mark as NEEDS_RECONCILIATION if not already
        $currentStatus = $policyRequest->status instanceof PolicyStatus
            ? $policyRequest->status
            : PolicyStatus::from((string) $policyRequest->status);

        if ($currentStatus === PolicyStatus::PENDING_CONFIRMATION) {
            $updated = $this->stateTransitionService->transition(
                $policyRequest,
                PolicyStatus::NEEDS_RECONCILIATION,
                'Insurer status lookup did not confirm issuance. Flagged for manual/scheduled reconciliation.'
            );

            return [
                'status_code' => 202,
                'message' => 'Policy outcome remains unconfirmed; moved to NEEDS_RECONCILIATION.',
                'policy_request' => $updated,
            ];
        }

        return [
            'status_code' => 202,
            'message' => 'Policy remains unconfirmed after status check.',
            'policy_request' => $policyRequest->fresh(),
        ];
    }
}
