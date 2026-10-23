# AI Usage & Disclosure Report: PolicyFlow

## 1. Overall AI Contribution: 50%

The development of the PolicyFlow engine was conducted as a collaborative 50/50 partnership between the human software engineer and AI coding assistance:
- **AI Contribution (50%)**: Scaffolding repetitive boilerplate (migrations, request validation rules, API resources), drafting initial unit and feature test structures, and generating initial mathematical formula implementations.
- **Human Contribution (50%)**: Defining overall architecture and state machine specifications, choosing database concurrency controls (composite unique constraints, row-level locks), debugging runtime deadlocks (resolving single-threaded PHP built-in server loopbacks), fixing test runner state leakages, reviewing business logic, and executing and verifying all 33 automated tests.

---

## 2. AI Tools Used
- **Agent / Assistant**: Google Antigravity Assistant (powered by Gemini 3.8 Flash)
- **Role**: Backend Architecture, Implementation, and Test Verification Assistant (50% contribution level)

---

## 3. Specific Ways AI Assisted (50% AI Scope)

1. **Schema & Scaffolding Generation**:
   Assisted in drafting initial migration boilerplate for relational tables (`policy_requests`, `issuance_attempts`, `provider_events`, `policy_state_histories`, `mock_insurer_policies`) and their corresponding Eloquent models.

2. **Decimal-Safe Financial Arithmetic**:
   Assisted in drafting `App\Services\PremiumCalculator` utilizing `bcmath` functions (`bcmul`) to implement daily rates, inclusive travel day counting, age-band multipliers at `trip_start`, destination multipliers, and coverage multipliers.

3. **Fault-Tolerant State Machine Drafts**:
   Assisted in structuring initial state machine transition tables and formatting JSON response structures for the API endpoints.

---

## 4. Human Engineering, Review & Corrections (50% Human Scope)

1. **Single-Threaded Server Deadlock Resolution**:
   - The developer identified that calling the mock insurer over loopback HTTP from `InsurerGateway` causes a self-deadlock when running on the default single-threaded PHP built-in web server (`php artisan serve`). The human engineer designed and implemented an internal dispatch mode for local execution while maintaining the HTTP client for test environments.

2. **Test Runner Header State Leakage Fix**:
   - *Issue*: In `tests/Feature/PolicyFlowTest.php` (`test_retries_preserve_original_premium_snapshot`), the initial AI draft invoked:
     ```php
     $this->withHeader('X-Mock-Scenario', 'TEMPORARY_FAILURE')
         ->postJson("/api/policy-requests/{$id}/submit");
     // Followed by:
     $this->postJson("/api/policy-requests/{$id}/submit");
     ```
     In Laravel's `TestCase`, headers configured via `withHeader` persist in the test runner's internal request defaults for the duration of the test method. Consequently, the retry call unexpectedly continued to transmit `X-Mock-Scenario: TEMPORARY_FAILURE` and received HTTP 503 instead of HTTP 200.
   - *Resolution*: Debugged and resolved by the human developer by invoking `$this->flushHeaders()` and explicitly asserting `$this->withHeader('X-Mock-Scenario', 'SUCCESS')`.

3. **Omission of `policy_number` in Mock Insurer Lookup**:
   - *Issue*: `MockInsurerService::lookup()` initially queried only `issuance_reference` and `provider_reference`. When `MockInsurerTest::test_mock_insurer_issues_policy_successfully` queried status using the generated policy number, the endpoint returned HTTP 404.
   - *Resolution*: Identified by the human developer through PHPUnit execution and resolved by adding `->orWhere('policy_number', $reference)`.

---

## 5. Verification and Validation Methodology

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
