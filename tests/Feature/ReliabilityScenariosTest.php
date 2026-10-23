<?php

namespace Tests\Feature;

use App\Enums\PolicyStatus;
use App\Models\IssuanceAttempt;
use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use App\Services\PolicyRequestService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReliabilityScenariosTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => 'CR-REL-2001',
            'agent_id' => 'AGT-REL-01',
            'customer_name' => 'Kavita Patel',
            'date_of_birth' => '1998-11-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ], $overrides);
    }

    /**
     * Requirement 5: Concurrent duplicate intake handled by database composite unique constraint.
     */
    public function test_concurrent_duplicate_intake_handled_by_unique_constraint(): void
    {
        $payload = $this->validPayload();
        $service = app(PolicyRequestService::class);

        // Pre-create record simulating race winner in another concurrent thread
        PolicyRequest::create([
            'client_request_id' => $payload['client_request_id'],
            'agent_id' => $payload['agent_id'],
            'customer_name' => 'Kavita Patel',
            'date_of_birth' => '1998-11-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
            'travel_days' => 5,
            'premium' => '225.00',
            'status' => PolicyStatus::VALIDATED,
        ]);

        // Attempt intake with identical composite key (agent_id + client_request_id)
        $result = $service->intake($payload);

        $this->assertTrue($result['is_duplicate']);
        $this->assertSame('CR-REL-2001', $result['policy_request']->client_request_id);
        $this->assertDatabaseCount('policy_requests', 1);
    }

    /**
     * Requirement 6: Successful issuance produces exactly one policy number.
     */
    public function test_successful_issuance_produces_exactly_one_policy_number(): void
    {
        $this->setupMockInsurerGateway();

        // 1. Ingest request
        $res = $this->postJson('/api/policy-requests', $this->validPayload());
        $res->assertStatus(201);
        $id = $res->json('data.id');

        // 2. Submit for issuance (default SUCCESS scenario)
        $submitRes = $this->postJson("/api/policy-requests/{$id}/submit");

        $submitRes->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED');

        $policyNumber = $submitRes->json('data.policy_number');
        $this->assertNotEmpty($policyNumber);
        $this->assertStringStartsWith('POL-', $policyNumber);

        // Assert database state
        $request = PolicyRequest::find($id);
        $this->assertSame(PolicyStatus::ISSUED, $request->status);
        $this->assertSame($policyNumber, $request->policy_number);
        $this->assertSame(1, $request->attempt_count);

        // Assert mock insurer table has exactly 1 policy
        $this->assertDatabaseCount('mock_insurer_policies', 1);
        $this->assertDatabaseHas('mock_insurer_policies', [
            'policy_number' => $policyNumber,
            'status' => 'ISSUED',
        ]);

        // Assert issuance attempts has 1 attempt with outcome SUCCESS
        $this->assertDatabaseCount('issuance_attempts', 1);
        $this->assertDatabaseHas('issuance_attempts', [
            'policy_request_id' => $id,
            'attempt_number' => 1,
            'outcome' => 'SUCCESS',
        ]);
    }

    /**
     * Requirement 7 / Scenario B:
     * Timeout after successful provider issuance reconciles safely.
     *
     * Expected:
     * - The mock insurer creates a policy internally.
     * - The application experiences a simulated timeout.
     * - The application does not assume the issuance failed.
     * - The request enters PENDING_CONFIRMATION or NEEDS_RECONCILIATION.
     * - Reconciliation queries the mock insurer using the stable issuance reference.
     * - The existing policy is recovered.
     * - Exactly one policy number is associated with the request.
     * - No second policy is created.
     */
    public function test_timeout_after_successful_provider_issuance_reconciles_safely(): void
    {
        $this->setupMockInsurerGateway();

        // 1. Create intake request
        $res = $this->postJson('/api/policy-requests', $this->validPayload([
            'client_request_id' => 'CR-TIMEOUT-01',
        ]));
        $res->assertStatus(201);
        $id = $res->json('data.id');

        // 2. Submit with TIMEOUT_AFTER_ISSUE scenario
        // The mock insurer records policy in mock_insurer_policies, but returns a 504 simulated timeout.
        // The issuance service catches the timeout, enters PENDING_CONFIRMATION, and reconciles via lookup.
        $submit = $this->withHeader('X-Mock-Scenario', 'TIMEOUT_AFTER_ISSUE')
            ->postJson("/api/policy-requests/{$id}/submit");

        // Reconciliation successfully recovers the policy issued prior to the timeout
        $submit->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED');

        $recoveredPolicyNumber = $submit->json('data.policy_number');
        $this->assertNotEmpty($recoveredPolicyNumber);

        // Verify that the mock insurer only created exactly ONE policy
        $this->assertDatabaseCount('mock_insurer_policies', 1);
        $this->assertDatabaseHas('mock_insurer_policies', [
            'policy_number' => $recoveredPolicyNumber,
            'status' => 'ISSUED',
        ]);

        // Verify that the policy request is associated with exactly ONE policy number
        $reloaded = PolicyRequest::find($id);
        $this->assertSame(PolicyStatus::ISSUED, $reloaded->status);
        $this->assertSame($recoveredPolicyNumber, $reloaded->policy_number);

        // Verify state history captured the transition to PENDING_CONFIRMATION and then ISSUED
        $this->assertDatabaseHas('policy_state_histories', [
            'policy_request_id' => $id,
            'from_state' => 'SUBMITTED',
            'to_state' => 'PENDING_CONFIRMATION',
        ]);
        $this->assertDatabaseHas('policy_state_histories', [
            'policy_request_id' => $id,
            'from_state' => 'PENDING_CONFIRMATION',
            'to_state' => 'ISSUED',
        ]);

        // 3. Submitting again must not generate a new attempt or policy (409 Conflict)
        $repeatSubmit = $this->postJson("/api/policy-requests/{$id}/submit");
        $repeatSubmit->assertStatus(409);

        $this->assertDatabaseCount('mock_insurer_policies', 1);
    }

    /**
     * Requirement 8: Duplicate callbacks are processed only once and idempotently.
     */
    public function test_duplicate_callbacks_are_processed_only_once(): void
    {
        // 1. Create a policy request in PENDING_CONFIRMATION state
        $policy = PolicyRequest::create([
            'client_request_id' => 'CR-CALLBACK-01',
            'agent_id' => 'AGT-01',
            'customer_name' => 'Suresh Menon',
            'date_of_birth' => '1995-05-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-20',
            'trip_end' => '2026-11-25',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'travel_days' => 6,
            'premium' => '72.00',
            'status' => PolicyStatus::PENDING_CONFIRMATION,
            'attempt_count' => 1,
        ]);

        $idempotencyKey = "PF-REQ-{$policy->id}-ATTEMPT-1";
        IssuanceAttempt::create([
            'policy_request_id' => $policy->id,
            'attempt_number' => 1,
            'idempotency_key' => $idempotencyKey,
            'request_payload' => ['sample' => 'data'],
            'outcome' => 'PENDING_CALLBACK',
        ]);

        $callbackPayload = [
            'event_id' => 'EVT-CB-9999',
            'event_type' => 'POLICY_ISSUED',
            'issuance_reference' => $idempotencyKey,
            'policy_number' => 'POL-ASYNC-555',
            'provider_reference' => 'REF-ASYNC-555',
            'status' => 'ISSUED',
        ];

        // 2. First callback delivery -> 200 OK, marks request as ISSUED
        $firstCb = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $firstCb->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('duplicate', false);

        $this->assertDatabaseHas('provider_events', [
            'event_id' => 'EVT-CB-9999',
            'processing_status' => 'PROCESSED',
        ]);

        $this->assertDatabaseHas('policy_requests', [
            'id' => $policy->id,
            'status' => 'ISSUED',
            'policy_number' => 'POL-ASYNC-555',
        ]);

        // 3. Second callback delivery (duplicate event) -> 200 OK, duplicate=true
        $secondCb = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $secondCb->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('duplicate', true);

        // Ensure exactly ONE provider event record exists
        $this->assertDatabaseCount('provider_events', 1);

        // Ensure policy is still ISSUED and unchanged
        $reloaded = PolicyRequest::find($policy->id);
        $this->assertSame(PolicyStatus::ISSUED, $reloaded->status);
        $this->assertSame('POL-ASYNC-555', $reloaded->policy_number);
    }

    /**
     * Test reconciliation Artisan command.
     */
    public function test_artisan_reconciliation_command_recovers_pending_policies(): void
    {
        $this->setupMockInsurerGateway();

        // 1. Create a request stuck in PENDING_CONFIRMATION with an attempt
        $policy = PolicyRequest::create([
            'client_request_id' => 'CR-ARTISAN-01',
            'agent_id' => 'AGT-01',
            'customer_name' => 'Meera Nair',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'travel_days' => 5,
            'premium' => '60.00',
            'status' => PolicyStatus::PENDING_CONFIRMATION,
            'attempt_count' => 1,
        ]);

        $ref = "PF-REQ-{$policy->id}-ATTEMPT-1";
        IssuanceAttempt::create([
            'policy_request_id' => $policy->id,
            'attempt_number' => 1,
            'idempotency_key' => $ref,
            'request_payload' => ['plan' => 'BASIC'],
            'outcome' => 'TIMEOUT',
        ]);

        // Mock insurer has this policy recorded as ISSUED
        MockInsurerPolicy::create([
            'issuance_reference' => $ref,
            'policy_number' => 'POL-ARTISAN-RECOVERED',
            'provider_reference' => 'REF-ARTISAN-123',
            'client_request_id' => 'CR-ARTISAN-01',
            'agent_id' => 'AGT-01',
            'customer_name' => 'Meera Nair',
            'plan_code' => 'BASIC',
            'destination' => 'DOMESTIC',
            'sum_insured' => 100000,
            'premium' => '60.00',
            'status' => 'ISSUED',
        ]);

        // Run Artisan command
        $this->artisan('policyflow:reconcile')
            ->expectsOutputToContain('Found 1 requests requiring reconciliation.')
            ->expectsOutputToContain('SUCCESS: Policy #' . $policy->id . ' reconciled and confirmed ISSUED')
            ->assertSuccessful();

        $reloaded = PolicyRequest::find($policy->id);
        $this->assertSame(PolicyStatus::ISSUED, $reloaded->status);
        $this->assertSame('POL-ARTISAN-RECOVERED', $reloaded->policy_number);
    }
}
