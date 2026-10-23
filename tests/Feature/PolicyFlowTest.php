<?php

namespace Tests\Feature;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Services\StateTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyFlowTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => 'CR-10001',
            'agent_id' => 'AGT-01',
            'customer_name' => 'Aditi Sharma',
            'date_of_birth' => '1998-11-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ], $overrides);
    }

    /**
     * Requirement 1: Valid intake and expected premium snapshot.
     */
    public function test_valid_intake_creates_request_with_correct_premium(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_duplicate', false)
            ->assertJsonPath('data.client_request_id', 'CR-10001')
            ->assertJsonPath('data.agent_id', 'AGT-01')
            ->assertJsonPath('data.customer_name', 'Aditi Sharma')
            ->assertJsonPath('data.travel_days', 5)
            ->assertJsonPath('data.premium', '225.00')
            ->assertJsonPath('data.status', 'VALIDATED');

        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'CR-10001',
            'agent_id' => 'AGT-01',
            'travel_days' => 5,
            'premium' => '225.00',
            'status' => 'VALIDATED',
        ]);

        $this->assertDatabaseHas('policy_state_histories', [
            'from_state' => 'RECEIVED',
            'to_state' => 'VALIDATED',
        ]);
    }

    /**
     * Requirement 2: Invalid trip dates.
     */
    public function test_rejects_trip_end_before_trip_start(): void
    {
        $payload = $this->validPayload([
            'trip_start' => '2026-11-15',
            'trip_end' => '2026-11-10',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end'])
            ->assertJsonPath('errors.trip_end.0', 'Trip end date must be on or after trip start date.');

        $this->assertDatabaseCount('policy_requests', 0);
    }

    public function test_rejects_date_of_birth_after_trip_start(): void
    {
        $payload = $this->validPayload([
            'date_of_birth' => '2027-01-01',
            'trip_start' => '2026-11-10',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    /**
     * Requirement 3: Unsupported plan and coverage.
     */
    public function test_rejects_unsupported_plan_code(): void
    {
        $payload = $this->validPayload(['plan_code' => 'TITANIUM_SUPER']);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code'])
            ->assertJsonPath('errors.plan_code.0', 'Supported plans are BASIC, PLUS, or PREMIUM.');
    }

    public function test_rejects_unsupported_sum_insured(): void
    {
        $payload = $this->validPayload(['sum_insured' => 250000]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sum_insured'])
            ->assertJsonPath('errors.sum_insured.0', 'Supported coverage values are 100000, 300000, or 500000.');
    }

    public function test_rejects_unsupported_destination(): void
    {
        $payload = $this->validPayload(['destination' => 'MOON']);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination']);
    }

    /**
     * Requirement 4: Duplicate intake returns the same request (Idempotency Scenario A).
     */
    public function test_duplicate_intake_returns_existing_record_with_200(): void
    {
        // First submission -> 201 Created
        $first = $this->postJson('/api/policy-requests', $this->validPayload());
        $first->assertStatus(201);
        $originalId = $first->json('data.id');
        $originalPremium = $first->json('data.premium');

        // Repeated submission with modified payload attempt (e.g. tampering customer_name or coverage)
        $tamperedPayload = $this->validPayload([
            'customer_name' => 'Someone Else',
            'sum_insured' => 500000,
        ]);

        $second = $this->postJson('/api/policy-requests', $tamperedPayload);

        // Must return 200 OK and the ORIGINAL unchanged request
        $second->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_duplicate', true)
            ->assertJsonPath('data.id', $originalId)
            ->assertJsonPath('data.customer_name', 'Aditi Sharma')
            ->assertJsonPath('data.premium', $originalPremium);

        // Database must still have only 1 record
        $this->assertDatabaseCount('policy_requests', 1);
    }

    /**
     * Requirement 9: Invalid state regressions are rejected.
     */
    public function test_cannot_regress_from_issued_state(): void
    {
        $policy = PolicyRequest::create([
            'client_request_id' => 'CR-ISSUED-01',
            'agent_id' => 'AGT-01',
            'customer_name' => 'Rahul Verma',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'travel_days' => 5,
            'premium' => '60.00',
            'status' => PolicyStatus::ISSUED,
            'policy_number' => 'POL-EXISTING-123',
        ]);

        // Attempting to submit an already ISSUED policy must return 409 Conflict
        $response = $this->postJson("/api/policy-requests/{$policy->id}/submit");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Policy has already been issued.');

        // Attempting programmatic illegal transition ISSUED -> SUBMITTED throws exception
        $transitionService = app(StateTransitionService::class);
        $this->assertFalse($transitionService->canTransition(PolicyStatus::ISSUED, PolicyStatus::SUBMITTED));
        $this->assertFalse($transitionService->canTransition(PolicyStatus::ISSUED, PolicyStatus::FAILED));
    }

    /**
     * Requirement 10: Retries preserve the original premium snapshot.
     */
    public function test_retries_preserve_original_premium_snapshot(): void
    {
        $this->setupMockInsurerGateway();

        // 1. Create policy request
        $res = $this->postJson('/api/policy-requests', $this->validPayload());
        $res->assertStatus(201);
        $id = $res->json('data.id');
        $originalPremium = $res->json('data.premium');

        // 2. First submit fails with TEMPORARY_FAILURE (503)
        $submitFail = $this->withHeader('X-Mock-Scenario', 'TEMPORARY_FAILURE')
            ->postJson("/api/policy-requests/{$id}/submit");

        $submitFail->assertStatus(503)
            ->assertJsonPath('data.status', 'FAILED');

        $this->assertDatabaseHas('policy_requests', [
            'id' => $id,
            'status' => 'FAILED',
            'attempt_count' => 1,
            'premium' => $originalPremium,
        ]);

        // 3. Retry submit with SUCCESS scenario (clear failure scenario header)
        $this->flushHeaders();
        $submitSuccess = $this->withHeader('X-Mock-Scenario', 'SUCCESS')
            ->postJson("/api/policy-requests/{$id}/submit");

        $submitSuccess->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.attempt_count', 2)
            ->assertJsonPath('data.premium', $originalPremium); // Preserved unchanged!

        // Confirm database values
        $reloaded = PolicyRequest::find($id);
        $this->assertSame($originalPremium, (string) $reloaded->premium);
        $this->assertSame(PolicyStatus::ISSUED, $reloaded->status);
        $this->assertSame(2, $reloaded->attempt_count);
    }

    /**
     * Requirement 11: Pagination and status filtering.
     */
    public function test_pagination_and_status_filtering(): void
    {
        // Seed records with different statuses
        PolicyRequest::create([
            'client_request_id' => 'CR-LIST-1',
            'agent_id' => 'AGT-A',
            'customer_name' => 'Alice',
            'date_of_birth' => '1995-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-03',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'travel_days' => 3,
            'premium' => '36.00',
            'status' => PolicyStatus::VALIDATED,
        ]);

        PolicyRequest::create([
            'client_request_id' => 'CR-LIST-2',
            'agent_id' => 'AGT-A',
            'customer_name' => 'Bob',
            'date_of_birth' => '1992-01-01',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-03',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
            'travel_days' => 3,
            'premium' => '135.00',
            'status' => PolicyStatus::ISSUED,
            'policy_number' => 'POL-LIST-2',
        ]);

        PolicyRequest::create([
            'client_request_id' => 'CR-LIST-3',
            'agent_id' => 'AGT-B',
            'customer_name' => 'Charlie',
            'date_of_birth' => '1985-01-01',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-03',
            'plan_code' => 'PREMIUM',
            'sum_insured' => 500000,
            'travel_days' => 3,
            'premium' => '243.00',
            'status' => PolicyStatus::ISSUED,
            'policy_number' => 'POL-LIST-3',
        ]);

        // Filter by status=ISSUED
        $resIssued = $this->getJson('/api/policy-requests?status=ISSUED');
        $resIssued->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // Filter by status=VALIDATED
        $resValidated = $this->getJson('/api/policy-requests?status=VALIDATED');
        $resValidated->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Pagination per_page=1
        $resPaginated = $this->getJson('/api/policy-requests?per_page=1');
        $resPaginated->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 3);
    }

    /**
     * Requirement 12: Missing request returns 404.
     */
    public function test_missing_request_returns_404(): void
    {
        $responseShow = $this->getJson('/api/policy-requests/999999');
        $responseShow->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $responseSubmit = $this->postJson('/api/policy-requests/999999/submit');
        $responseSubmit->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }
}
