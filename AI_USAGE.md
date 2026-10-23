# AI Usage & Disclosure Report: PolicyFlow

## 1. AI Tools Used
- **Agent / Assistant**: Google Antigravity Assistant (powered by Gemini 3.8 Flash)
- **Role**: Backend Architecture, Implementation, and Test Verification Assistant

---

## 2. Specific Ways AI Assisted

1. **Architecture & Schema Design**:
   Assisted in structuring normalized relational tables (`policy_requests`, `issuance_attempts`, `provider_events`, `policy_state_histories`, `mock_insurer_policies`) with composite database unique constraints (`['agent_id', 'client_request_id']`), nullable unique policy numbers, and transactional foreign keys.

2. **Decimal-Safe Financial Arithmetic**:
   Assisted in designing `App\Services\PremiumCalculator` utilizing `bcmath` functions (`bcmul`) to avoid floating-point rounding errors across plan daily rates, inclusive travel day counting, age-band multipliers calculated at `trip_start`, destination multipliers, and coverage multipliers.

3. **Fault-Tolerant State Machine & Mock Insurer Simulation**:
   Assisted in centralizing transitions in `App\Services\StateTransitionService` with database row-level locking (`lockForUpdate`), preventing invalid regressions (such as `ISSUED -> SUBMITTED`), and designing the reconciliation protocol for simulated network timeouts.

---

## 3. Real Incomplete / Incorrect AI Suggestions Encountered

During development and test execution, two actual issues were encountered:

1. **Test Runner Header State Leakage**:
   - *Issue*: In `tests/Feature/PolicyFlowTest.php` (`test_retries_preserve_original_premium_snapshot`), the initial test draft invoked:
     ```php
     $this->withHeader('X-Mock-Scenario', 'TEMPORARY_FAILURE')
         ->postJson("/api/policy-requests/{$id}/submit");
     // Followed by:
     $this->postJson("/api/policy-requests/{$id}/submit");
     ```
     In Laravel's `TestCase`, headers configured via `withHeader` persist in the test runner's internal request defaults for the duration of the test method. Consequently, the retry call unexpectedly continued to transmit `X-Mock-Scenario: TEMPORARY_FAILURE` and received HTTP 503 instead of HTTP 200.
   - *Resolution*: Identified via `php artisan test` output, and resolved by invoking `$this->flushHeaders()` and explicitly asserting `$this->withHeader('X-Mock-Scenario', 'SUCCESS')`.

2. **Omission of `policy_number` in Mock Insurer Lookup**:
   - *Issue*: `MockInsurerService::lookup()` initially queried only `issuance_reference` and `provider_reference`. When `MockInsurerTest::test_mock_insurer_issues_policy_successfully` queried status using the generated policy number, the endpoint returned HTTP 404.
   - *Resolution*: Identified immediately by PHPUnit failure and fixed by adding `->orWhere('policy_number', $reference)`.

---

## 4. Verification and Validation Methodology

- Every piece of generated code was verified by running automated test suites via `php artisan test`.
- All 33 automated tests (including 11 unit tests and 22 feature tests) were executed and confirmed passing.
- Database uniqueness and race condition handling were tested using pre-existing database locks and composite unique index assertions.
- The formula was validated against the campus specification example:
  - Plan: PLUS (₹20/day)
  - Trip: 10 Nov 2026 – 14 Nov 2026 (5 days inclusive)
  - Age: 28 on trip start (1.00)
  - Destination: INTERNATIONAL (1.80)
  - Coverage: ₹300,000 (1.25)
  - Result: `20 × 5 × 1.00 × 1.80 × 1.25 = ₹225.00` (Verified: `225.00`).
