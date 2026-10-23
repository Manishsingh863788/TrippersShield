# PolicyFlow — Fault-Tolerant Insurance Issuance & Reconciliation Engine

PolicyFlow is an enterprise-grade, fault-tolerant REST API built with Laravel 11 and PHP 8.3. It coordinates travel insurance policy intake, decimal-safe premium calculations, idempotent issuance, and automated reconciliation against an upstream insurer.

---

## 1. Project Overview & Architecture

When issuing financial insurance policies over distributed networks, transient network partitions, timeouts, and duplicate client retries can cause double charging or duplicate policy generation. 

PolicyFlow solves this using:
- **Intake Idempotency**: An immutable composite database constraint (`agent_id`, `client_request_id`) guaranteeing exactly one logical policy request.
- **Decimal-Safe Financial Arithmetic**: BCMath-backed premium calculations avoiding IEEE 754 floating-point inaccuracies.
- **Provider-Level Idempotency**: Stable issuance references (`PF-REQ-{id}-ATTEMPT-{n}`) allowing upstream insurers to recognize duplicate issuance attempts.
- **Pessimistic Row-Level Locking**: Concurrency safety around state transitions to prevent regressions from final states (`ISSUED`).
- **Active Reconciliation Engine**: Status lookups that recover policies issued upstream even if the client experienced a network timeout.

### Data Flow Diagram

```
[Client / Agent]
       │
       ▼ POST /api/policy-requests (Intake)
┌───────────────────────────────────────────────┐
│ Database Composite Unique Index               │
│ (agent_id + client_request_id)                │
│ -> Returns 201 (Created) or 200 (Idempotent)  │
└──────────────────────┬────────────────────────┘
                       │
                       ▼ POST /api/policy-requests/{id}/submit
┌───────────────────────────────────────────────┐
│ Persist IssuanceAttempt (IN_PROGRESS)         │
│ Transition State: VALIDATED -> SUBMITTED      │
└──────────────────────┬────────────────────────┘
                       │
       ┌───────────────┴───────────────┐
       ▼                               ▼
[200 OK: Issued]              [504 Gateway Timeout]
       │                               │
       │                               ▼ Transition: PENDING_CONFIRMATION
       │                               │
       │                               ▼ Query: GET /mock-insurer/policies/{ref}
       │                               │
       └───────────────┬───────────────┘
                       │ (Recovered)
                       ▼
┌───────────────────────────────────────────────┐
│ Transition State: -> ISSUED                   │
│ Store Policy Number & Provider Reference      │
└───────────────────────────────────────────────┘
```

---

## 2. Database Schema

PolicyFlow includes 5 dedicated database tables with foreign keys and indexes:

### 1. `policy_requests`
Primary record representing the customer's policy application.
- `id`: Auto-incrementing primary key.
- `client_request_id`: Client-supplied idempotency key (string).
- `agent_id`: Identifier of the submitting agent (string).
- `customer_name`: Full name of the insured traveler.
- `date_of_birth`: Date of birth (used to compute age at `trip_start`).
- `destination`: Destination enum (`DOMESTIC`, `INTERNATIONAL`).
- `trip_start`, `trip_end`: Trip validity dates.
- `plan_code`: Plan tier (`BASIC`, `PLUS`, `PREMIUM`).
- `sum_insured`: Coverage amount (`100000`, `300000`, `500000`).
- `travel_days`: Inclusive duration in days.
- `premium`: Immutable snapshot calculated at intake (`DECIMAL(12, 2)`).
- `status`: State machine status (`RECEIVED`, `VALIDATED`, `SUBMITTED`, `PENDING_CONFIRMATION`, `ISSUED`, `FAILED`, `NEEDS_RECONCILIATION`).
- `provider_reference`: Nullable reference issued by the insurer.
- `policy_number`: Nullable unique policy number.
- `attempt_count`: Counter of issuance attempts.
- `last_provider_response`: Auditable JSON response from provider.
- `error_message`: Nullable description of failures.
- **Constraints**:
  - `UNIQUE (agent_id, client_request_id)`: Enforces intake idempotency.
  - `UNIQUE (policy_number)`: Nullable unique constraint ensuring no two requests share a policy number.

### 2. `issuance_attempts`
Audits each dispatch attempt sent to the insurer.
- `id`: Primary key.
- `policy_request_id`: Foreign key referencing `policy_requests.id`.
- `attempt_number`: Sequential attempt number (1, 2, ...).
- `idempotency_key`: Stable provider reference (`PF-REQ-{id}-ATTEMPT-{n}`).
- `request_payload`: Snapshot JSON sent to insurer.
- `outcome`: Outcome (`SUCCESS`, `TIMEOUT`, `TEMPORARY_FAILURE`, `RECONCILED`, `PENDING_CALLBACK`).
- `provider_response`: Upstream JSON response.
- `error_category`: Categorized failure reason.

### 3. `provider_events`
Stores webhook and callback events delivered by the insurer.
- `id`: Primary key.
- `event_id`: Unique provider event ID (enforces callback deduplication).
- `event_type`: Event type (e.g., `POLICY_ISSUED`).
- `payload`: Full JSON callback payload.
- `processing_status`: Status (`PROCESSED`, `DUPLICATE_SKIPPED`, `FAILED`).

### 4. `policy_state_histories`
Immutable audit log of all legitimate state machine transitions.
- `id`: Primary key.
- `policy_request_id`: Foreign key referencing `policy_requests.id`.
- `from_state`: Previous state.
- `to_state`: New target state.
- `reason`: Explanation for transition.
- `created_at`: Exact transition timestamp.

### 5. `mock_insurer_policies`
Simulated insurer internal persistence store. Demonstrates upstream idempotency enforcement on `issuance_reference`.

---

## 3. Premium Calculation Service

Premiums are calculated using `App\Services\PremiumCalculator` with decimal-safe arithmetic (`bcmul`).

### Business Rules
1. **Daily Rates**:
   - `BASIC`: ₹12 / day
   - `PLUS`: ₹20 / day
   - `PREMIUM`: ₹30 / day
2. **Age Multiplier** (Age on `trip_start`, not current date):
   - Age 0–17: `1.10`
   - Age 18–60: `1.00`
   - Age 61–70: `1.50`
   - Age 71+: `2.00`
3. **Destination Multiplier**:
   - `DOMESTIC`: `1.00`
   - `INTERNATIONAL`: `1.80`
4. **Coverage Multiplier**:
   - ₹100,000: `1.00`
   - ₹300,000: `1.25`
   - ₹500,000: `1.50`
5. **Travel Days**: Inclusive calculation: `(trip_end - trip_start) + 1`.
6. **Formula**:
   $$\text{Premium} = \text{Daily Rate} \times \text{Travel Days} \times \text{Age Multiplier} \times \text{Destination Multiplier} \times \text{Coverage Multiplier}$$
   Only the final result is rounded to two decimal places.

### Example Walkthrough (Verified in Unit Tests)
- Plan: `PLUS` (₹20/day)
- Trip: 10 November 2026 through 14 November 2026 (5 days inclusive)
- Date of Birth: 10 November 1998 (Age on trip start: 28 -> Multiplier `1.00`)
- Destination: `INTERNATIONAL` (Multiplier `1.80`)
- Coverage: ₹300,000 (Multiplier `1.25`)
- Calculation:
  - Base: $20 \times 5 = 100$
  - Age multiplier: $100 \times 1.00 = 100$
  - Destination multiplier: $100 \times 1.80 = 180$
  - Coverage multiplier: $180 \times 1.25 = 225.00$
  - **Final Premium**: **₹225.00**

---

## 4. State Machine & Transition Rules

Managed centrally via `App\Services\StateTransitionService`:

| From State | Allowed Target States | Disallowed / Illegal Transitions |
|---|---|---|
| `RECEIVED` | `VALIDATED`, `FAILED` | `SUBMITTED`, `ISSUED` |
| `VALIDATED` | `SUBMITTED` | `ISSUED`, `RECEIVED` |
| `SUBMITTED` | `ISSUED`, `PENDING_CONFIRMATION`, `FAILED` | `VALIDATED`, `RECEIVED` |
| `PENDING_CONFIRMATION` | `ISSUED`, `NEEDS_RECONCILIATION`, `FAILED` | `VALIDATED`, `SUBMITTED` |
| `NEEDS_RECONCILIATION` | `ISSUED`, `FAILED`, `SUBMITTED` | `VALIDATED`, `RECEIVED` |
| `FAILED` | `SUBMITTED` (Safe retry path) | `ISSUED` |
| `ISSUED` | *(Terminal state — no outgoing transitions)* | `SUBMITTED`, `FAILED`, etc. (Returns 409 Conflict) |

---

## 5. Mandatory Reliability Scenarios

### Scenario A: Duplicate Intake Protection
- **Problem**: Client or agent resends the same intake request due to network instability.
- **Solution**: The database composite unique constraint on `(agent_id, client_request_id)` rejects duplicate inserts at the database level.
- **Outcome**: The existing record is returned with HTTP 200 OK. Original premium snapshot and accepted customer details are preserved and never overwritten.

### Scenario B: Timeout After Successful Issuance
- **Problem**: Insurer creates the policy, but the network connection times out before PolicyFlow receives the response.
- **Solution**:
  1. The application does not assume failure; it transitions to `PENDING_CONFIRMATION`.
  2. The issuance service queries `GET /api/mock-insurer/policies/{reference}` using the stable `issuance_reference`.
  3. The upstream policy is recovered, and the request transitions to `ISSUED`.
  4. Exactly one policy number is associated with the request; no second policy is ever generated.

### Scenario C: Duplicate Callback Deduplication
- **Problem**: Webhook provider delivers the same event multiple times.
- **Solution**: `ProviderCallbackService` validates `event_id` against `provider_events`. Duplicate events return HTTP 200 without executing secondary state changes or regressing `ISSUED` requests.

---

## 6. Installation & Setup

### Prerequisites
- PHP 8.2 or newer (PHP 8.3 installed) with `bcmath`, `pdo`, `pdo_sqlite`, `pdo_mysql` extensions enabled.
- Composer 2.x
- Git

### Quick Setup Steps

```bash
# 1. Clone repository and navigate to folder
cd trippershield

# 2. Copy environment file
cp .env.example .env

# 3. Generate application key
php artisan key:generate

# 4. Run database migrations
php artisan migrate

# 5. Execute automated test suite
php artisan test
```

### Starting the Local Development Server
```bash
php artisan serve
```
The server will start at `http://127.0.0.1:8000`.

---

## 7. API Reference & Sample cURL Commands

### 1. Intake Policy Request
`POST /api/policy-requests`

```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "REQ-CAMPUS-001",
    "agent_id": "AGT-NORTH-01",
    "customer_name": "Aditi Sharma",
    "date_of_birth": "1998-11-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
```
**Response (201 Created)**:
```json
{
  "data": {
    "id": 1,
    "client_request_id": "REQ-CAMPUS-001",
    "agent_id": "AGT-NORTH-01",
    "customer_name": "Aditi Sharma",
    "date_of_birth": "1998-11-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000,
    "travel_days": 5,
    "premium": "225.00",
    "status": "VALIDATED",
    "policy_number": null,
    "attempt_count": 0
  },
  "success": true,
  "is_duplicate": false,
  "message": "Policy request accepted and validated successfully."
}
```

Repeating this request returns **200 OK** with `"is_duplicate": true`.

---

### 2. Submit Request for Issuance
`POST /api/policy-requests/{id}/submit`

```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests/1/submit \
  -H "Content-Type: application/json"
```
**Response (200 OK)**:
```json
{
  "data": {
    "id": 1,
    "client_request_id": "REQ-CAMPUS-001",
    "status": "ISSUED",
    "policy_number": "POL-9A8B7C6D5E",
    "provider_reference": "REF-11223344",
    "attempt_count": 1
  },
  "success": true,
  "message": "Policy issued successfully."
}
```

#### Simulating Scenarios via Header:
- `X-Mock-Scenario: TEMPORARY_FAILURE` -> Returns 503, sets status to `FAILED`.
- `X-Mock-Scenario: TIMEOUT_AFTER_ISSUE` -> Simulates timeout, safely reconciles and returns 200 `ISSUED`.
- `X-Mock-Scenario: DELAYED_CALLBACK` -> Returns 202 `PENDING_CONFIRMATION`.

---

### 3. Retrieve Policy Request Details & History
`GET /api/policy-requests/{id}`

```bash
curl -X GET http://127.0.0.1:8000/api/policy-requests/1
```

---

### 4. List Policy Requests with Filtering & Pagination
`GET /api/policy-requests?status=ISSUED&per_page=15`

```bash
curl -X GET "http://127.0.0.1:8000/api/policy-requests?status=ISSUED"
```

---

### 5. Provider Webhook Callback
`POST /api/mock-insurer/callback`

```bash
curl -X POST http://127.0.0.1:8000/api/mock-insurer/callback \
  -H "Content-Type: application/json" \
  -d '{
    "event_id": "EVT-MOCK-9001",
    "event_type": "POLICY_ISSUED",
    "issuance_reference": "PF-REQ-1-ATTEMPT-1",
    "policy_number": "POL-ASYNC-12345",
    "provider_reference": "REF-ASYNC-67890",
    "status": "ISSUED"
  }'
```

---

### 6. Artisan Reconciliation Command
For background reconciliation of stale pending requests:

```bash
php artisan policyflow:reconcile
```
Or for a specific ID:
```bash
php artisan policyflow:reconcile --id=1
```

---

## 8. Test Suite Verification

PolicyFlow contains 33 automated tests across unit and feature suites:

```bash
php artisan test
```

### Verified Test Coverage Summary:
- **Unit Tests (`tests/Unit/PremiumCalculatorTest.php`)**:
  - Exact specification example (PLUS, 5 days, age 28, international, ₹300,000 -> ₹225.00).
  - Inclusive travel day calculation.
  - Calculation of age at `trip_start` rather than current date.
  - All daily rates across BASIC, PLUS, PREMIUM.
  - All age bands (0–17, 18–60, 61–70, 71+).
  - All destination and coverage multipliers.
  - Validation exceptions on invalid inputs.
- **Feature Tests (`tests/Feature/PolicyFlowTest.php`)**:
  - Valid intake and snapshot persistence.
  - Field-level validation on dates, plans, destinations, and coverage.
  - Intake duplicate idempotency (Scenario A).
  - Prohibition of state regressions from `ISSUED`.
  - Preservation of premium snapshots across retries.
  - Status filtering, agent filtering, and pagination.
  - Missing resource 404 responses.
- **Reliability Tests (`tests/Feature/ReliabilityScenariosTest.php`)**:
  - Composite unique constraint race condition protection.
  - Single policy number generation guarantee.
  - Timeout after successful issuance with safe reconciliation recovery (Scenario B).
  - Transactional deduplication of webhook callbacks.
  - Background CLI reconciliation command execution.
- **Mock Insurer Tests (`tests/Feature/MockInsurerTest.php`)**:
  - Provider-level idempotency on `issuance_reference`.
  - Deterministic simulation of temporary failure, timeout, and delayed callbacks.
  - Status lookup endpoint.

---

## 9. Assumptions & Limitations

1. **Test Environment**: Automated tests run on an in-memory SQLite database for high speed and zero external setup. In production, MySQL 8 or PostgreSQL is recommended.
2. **Pessimistic Locking**: `lockForUpdate()` is supported across MySQL, PostgreSQL, and SQLite transactions.
3. **Queue Processing**: Webhooks and reconciliations run synchronously by default for deterministic placement evaluation. In high-throughput production, jobs can be dispatched to asynchronous queues (`php artisan queue:work`).
