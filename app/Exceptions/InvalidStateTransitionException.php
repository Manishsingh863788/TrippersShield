<?php

namespace App\Exceptions;

use App\Enums\PolicyStatus;
use Exception;
use Illuminate\Http\JsonResponse;

class InvalidStateTransitionException extends Exception
{
    public function __construct(
        public readonly string|PolicyStatus $fromState,
        public readonly string|PolicyStatus $toState,
        string $message = ''
    ) {
        $from = $fromState instanceof PolicyStatus ? $fromState->value : (string) $fromState;
        $to = $toState instanceof PolicyStatus ? $toState->value : (string) $toState;

        parent::__construct(
            $message ?: "Cannot transition policy request from state [{$from}] to [{$to}]."
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'error_code' => 'INVALID_STATE_TRANSITION',
        ], 409);
    }
}
