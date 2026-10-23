<?php

namespace Tests\Feature;

use App\Models\MockInsurerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MockInsurerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_insurer_issues_policy_successfully(): void
    {
        $payload = [
            'issuance_reference' => 'TEST-REF-001',
            'client_request_id' => 'CR-001',
            'agent_id' => 'AGT-01',
            'customer_name' => 'Deepak Verma',
            'plan_code' => 'PLUS',
            'destination' => 'DOMESTIC',
            'sum_insured' => 300000,
            'premium' => '100.00',
        ];

        $res = $this->postJson('/api/mock-insurer/issue', $payload);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'ISSUED');

        $policyNumber = $res->json('policy_number');
        $this->assertNotEmpty($policyNumber);

        // Status lookup endpoint
        $lookup = $this->getJson("/api/mock-insurer/policies/{$policyNumber}");
        $lookup->assertStatus(200)
            ->assertJsonPath('found', true)
            ->assertJsonPath('status', 'ISSUED')
            ->assertJsonPath('policy_number', $policyNumber);
    }

    public function test_mock_insurer_enforces_idempotency_on_issuance_reference(): void
    {
        $payload = [
            'issuance_reference' => 'IDEM-REF-100',
            'client_request_id' => 'CR-100',
            'agent_id' => 'AGT-01',
            'customer_name' => 'Deepak Verma',
            'plan_code' => 'PLUS',
            'destination' => 'DOMESTIC',
            'sum_insured' => 300000,
            'premium' => '100.00',
        ];

        $first = $this->postJson('/api/mock-insurer/issue', $payload);
        $first->assertStatus(200);
        $firstPolicy = $first->json('policy_number');

        // Repeated call with same issuance reference
        $second = $this->postJson('/api/mock-insurer/issue', $payload);
        $second->assertStatus(200)
            ->assertJsonPath('policy_number', $firstPolicy);

        $this->assertDatabaseCount('mock_insurer_policies', 1);
    }

    public function test_mock_insurer_simulates_timeout_after_issuance(): void
    {
        $payload = [
            'issuance_reference' => 'TIMEOUT-REF-200',
            'client_request_id' => 'CR-200',
            'agent_id' => 'AGT-01',
            'customer_name' => 'Pooja Hegde',
            'plan_code' => 'PREMIUM',
            'destination' => 'INTERNATIONAL',
            'sum_insured' => 500000,
            'premium' => '300.00',
        ];

        $res = $this->withHeader('X-Mock-Scenario', 'TIMEOUT_AFTER_ISSUE')
            ->postJson('/api/mock-insurer/issue', $payload);

        // Insurer created policy internally, but simulated 504 gateway timeout
        $res->assertStatus(504)
            ->assertJsonPath('error_category', 'SIMULATED_TIMEOUT');

        // Insurer record exists!
        $this->assertDatabaseCount('mock_insurer_policies', 1);
        $policy = MockInsurerPolicy::where('issuance_reference', 'TIMEOUT-REF-200')->first();
        $this->assertNotNull($policy);
        $this->assertSame('ISSUED', $policy->status);
    }

    public function test_mock_insurer_simulates_temporary_failure(): void
    {
        $payload = [
            'issuance_reference' => 'FAIL-REF-300',
            'client_request_id' => 'CR-300',
            'agent_id' => 'AGT-01',
            'customer_name' => 'Pooja Hegde',
            'plan_code' => 'PREMIUM',
            'destination' => 'INTERNATIONAL',
            'sum_insured' => 500000,
            'premium' => '300.00',
        ];

        $res = $this->withHeader('X-Mock-Scenario', 'TEMPORARY_FAILURE')
            ->postJson('/api/mock-insurer/issue', $payload);

        $res->assertStatus(503)
            ->assertJsonPath('error_category', 'TEMPORARY_NETWORK_FAILURE');

        // No policy created
        $this->assertDatabaseCount('mock_insurer_policies', 0);
    }
}
