<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Facades\DB;

class StateTransitionService
{
    /**
     * Map of allowed state transitions.
     *
     * @var array<string, array<string>>
     */
    public const ALLOWED_TRANSITIONS = [
        'RECEIVED' => ['VALIDATED', 'FAILED'],
        'VALIDATED' => ['SUBMITTED'],
        'SUBMITTED' => ['ISSUED', 'PENDING_CONFIRMATION', 'FAILED'],
        'PENDING_CONFIRMATION' => ['ISSUED', 'NEEDS_RECONCILIATION', 'FAILED'],
        'NEEDS_RECONCILIATION' => ['ISSUED', 'FAILED', 'SUBMITTED'],
        'FAILED' => ['SUBMITTED'],
        'ISSUED' => [], // Final state. Never regress or transition out.
    ];

    /**
     * Check if a transition between two states is permitted.
     */
    public function canTransition(string|PolicyStatus $from, string|PolicyStatus $to): bool
    {
        $fromStr = $from instanceof PolicyStatus ? $from->value : (string) $from;
        $toStr = $to instanceof PolicyStatus ? $to->value : (string) $to;

        if ($fromStr === $toStr) {
            return true;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$fromStr] ?? [];

        return in_array($toStr, $allowed, true);
    }

    /**
     * Transition a policy request to a new state with database row locking and audit history.
     *
     * @throws InvalidStateTransitionException
     */
    public function transition(
        PolicyRequest $policyRequest,
        PolicyStatus|string $toState,
        ?string $reason = null,
        array $additionalAttributes = []
    ): PolicyRequest {
        $targetState = $toState instanceof PolicyStatus ? $toState : PolicyStatus::from((string) $toState);

        return DB::transaction(function () use ($policyRequest, $targetState, $reason, $additionalAttributes) {
            /** @var PolicyRequest $lockedRequest */
            $lockedRequest = PolicyRequest::query()
                ->where('id', $policyRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentState = $lockedRequest->status instanceof PolicyStatus
                ? $lockedRequest->status
                : PolicyStatus::from((string) $lockedRequest->status);

            // If already in target state, return without duplicate transitions
            if ($currentState === $targetState) {
                if (! empty($additionalAttributes)) {
                    $lockedRequest->update($additionalAttributes);
                }

                return $lockedRequest;
            }

            if (! $this->canTransition($currentState, $targetState)) {
                throw new InvalidStateTransitionException($currentState, $targetState);
            }

            // Record state transition history
            PolicyStateHistory::create([
                'policy_request_id' => $lockedRequest->id,
                'from_state' => $currentState->value,
                'to_state' => $targetState->value,
                'reason' => $reason,
            ]);

            // Update policy request status and additional attributes
            $attributes = array_merge($additionalAttributes, [
                'status' => $targetState,
            ]);

            $lockedRequest->update($attributes);

            return $lockedRequest->fresh();
        });
    }
}
