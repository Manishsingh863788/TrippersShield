<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePolicyRequestRequest;
use App\Http\Resources\PolicyRequestResource;
use App\Models\PolicyRequest;
use App\Services\PolicyIssuanceService;
use App\Services\PolicyRequestService;
use App\Services\ProviderCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PolicyRequestController extends Controller
{
    public function __construct(
        private readonly PolicyRequestService $policyRequestService,
        private readonly PolicyIssuanceService $policyIssuanceService,
        private readonly ProviderCallbackService $providerCallbackService
    ) {}

    /**
     * Intake a new policy request or return existing if repeated idempotently.
     */
    public function store(StorePolicyRequestRequest $request): JsonResponse
    {
        $result = $this->policyRequestService->intake($request->validated());

        $statusCode = $result['is_duplicate'] ? 200 : 201;

        return (new PolicyRequestResource($result['policy_request']))
            ->additional([
                'success' => true,
                'is_duplicate' => $result['is_duplicate'],
                'message' => $result['is_duplicate']
                    ? 'Existing policy request returned idempotently.'
                    : 'Policy request accepted and validated successfully.',
            ])
            ->response()
            ->setStatusCode($statusCode);
    }

    /**
     * Display a specific policy request.
     */
    public function show(int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::with(['attempts', 'stateHistories'])->find($id);

        if (! $policyRequest) {
            return response()->json([
                'success' => false,
                'message' => "Policy request with ID [{$id}] not found.",
                'error_code' => 'NOT_FOUND',
            ], 404);
        }

        return (new PolicyRequestResource($policyRequest))
            ->additional([
                'success' => true,
                'state_history' => $policyRequest->stateHistories->map(fn ($h) => [
                    'from' => $h->from_state,
                    'to' => $h->to_state,
                    'reason' => $h->reason,
                    'timestamp' => $h->created_at->toISOString(),
                ]),
            ])
            ->response();
    }

    /**
     * Submit an accepted policy request for insurer issuance.
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::find($id);

        if (! $policyRequest) {
            return response()->json([
                'success' => false,
                'message' => "Policy request with ID [{$id}] not found.",
                'error_code' => 'NOT_FOUND',
            ], 404);
        }

        $scenarioHeader = $request->header('X-Mock-Scenario', $request->input('scenario'));

        $result = $this->policyIssuanceService->submit($policyRequest, $scenarioHeader);

        return (new PolicyRequestResource($result['policy_request']))
            ->additional([
                'success' => in_array($result['status_code'], [200, 201, 202], true),
                'message' => $result['message'],
            ])
            ->response()
            ->setStatusCode($result['status_code']);
    }

    /**
     * List policy requests with status filtering and pagination.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PolicyRequest::query()->latest();

        if ($request->filled('status')) {
            $status = strtoupper(trim((string) $request->input('status')));
            $query->where('status', $status);
        }

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->input('agent_id'));
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 15)));
        $paginated = $query->paginate($perPage);

        return PolicyRequestResource::collection($paginated);
    }

    /**
     * Provider callback webhook endpoint.
     */
    public function callback(Request $request): JsonResponse
    {
        $result = $this->providerCallbackService->handleCallback($request->all());

        return response()->json([
            'success' => in_array($result['status_code'], [200, 201, 202], true),
            'message' => $result['message'],
            'duplicate' => $result['duplicate'] ?? false,
        ], $result['status_code']);
    }
}
