<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ProviderCallbackService
{
    public function __construct(
        private readonly StateTransitionService $stateTransitionService
    ) {}

    /**
     * Process an asynchronous callback event from the insurer.
     * Transactionally deduplicates event_id to prevent double-processing.
     *
     * @param  array<string, mixed>  $data
     * @return array{status_code: int, message: string, duplicate: bool}
     */
    public function handleCallback(array $data): array
    {
        $eventId = trim((string) ($data['event_id'] ?? ''));
        $eventType = trim((string) ($data['event_type'] ?? 'POLICY_ISSUED'));

        if (empty($eventId)) {
            return [
                'status_code' => 422,
                'message' => 'Missing event_id in callback payload.',
                'duplicate' => false,
            ];
        }

        try {
            return DB::transaction(function () use ($eventId, $eventType, $data) {
                // 1. Check for existing event (Idempotency)
                $existingEvent = ProviderEvent::where('event_id', $eventId)->lockForUpdate()->first();
                if ($existingEvent) {
                    return [
                        'status_code' => 200,
                        'message' => 'Duplicate callback ignored idempotently.',
                        'duplicate' => true,
                    ];
                }

                // 2. Persist provider event
                ProviderEvent::create([
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'payload' => $data,
                    'processing_status' => 'PROCESSED',
                ]);

                // 3. Locate policy request via issuance_reference
                $reference = $data['issuance_reference'] ?? ($data['payload']['issuance_reference'] ?? null);
                $policyRequest = null;

                if ($reference) {
                    $attempt = IssuanceAttempt::where('idempotency_key', $reference)->first();
                    if ($attempt) {
                        $policyRequest = PolicyRequest::where('id', $attempt->policy_request_id)->lockForUpdate()->first();
                    }
                }

                if (! $policyRequest && isset($data['client_request_id'])) {
                    $policyRequest = PolicyRequest::where('client_request_id', $data['client_request_id'])->lockForUpdate()->first();
                }

                if (! $policyRequest) {
                    return [
                        'status_code' => 200,
                        'message' => 'Event recorded, but no matching policy request was found.',
                        'duplicate' => false,
                    ];
                }

                // 4. State updates under lock
                $currentStatus = $policyRequest->status instanceof PolicyStatus
                    ? $policyRequest->status
                    : PolicyStatus::from((string) $policyRequest->status);

                // If already ISSUED, do NOT overwrite or regress
                if ($currentStatus === PolicyStatus::ISSUED) {
                    return [
                        'status_code' => 200,
                        'message' => 'Policy already confirmed as ISSUED; callback received without modification.',
                        'duplicate' => false,
                    ];
                }

                $callbackStatus = strtoupper((string) ($data['status'] ?? ($data['payload']['status'] ?? 'ISSUED')));
                $policyNumber = $data['policy_number'] ?? ($data['payload']['policy_number'] ?? null);
                $providerRef = $data['provider_reference'] ?? ($data['payload']['provider_reference'] ?? null);

                if ($callbackStatus === 'ISSUED' && $policyNumber) {
                    $this->stateTransitionService->transition(
                        $policyRequest,
                        PolicyStatus::ISSUED,
                        "Confirmed issued via provider callback [Event: {$eventId}]",
                        [
                            'policy_number' => $policyNumber,
                            'provider_reference' => $providerRef,
                            'last_provider_response' => $data,
                            'error_message' => null,
                        ]
                    );
                } elseif ($callbackStatus === 'FAILED') {
                    $this->stateTransitionService->transition(
                        $policyRequest,
                        PolicyStatus::FAILED,
                        "Marked failed via provider callback [Event: {$eventId}]",
                        [
                            'last_provider_response' => $data,
                            'error_message' => $data['message'] ?? 'Insurer callback reported issuance failure.',
                        ]
                    );
                }

                return [
                    'status_code' => 200,
                    'message' => 'Callback processed successfully.',
                    'duplicate' => false,
                ];
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            // Concurrent duplicate callback with identical event_id caught by DB unique index
            return [
                'status_code' => 200,
                'message' => 'Duplicate callback caught and ignored idempotently.',
                'duplicate' => true,
            ];
        }
    }
}
