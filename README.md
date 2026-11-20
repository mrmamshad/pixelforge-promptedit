# Pixelforge Studio — Image Generation & Accurate Cost Accounting

A robust, full-stack application built with **Laravel 12**, **Inertia.js**, **Vue 3**, and **Tailwind CSS** integrating the Pixelforge simulated AI image-generation API.

Engineered for PromptEdit's Senior Product Engineer assessment, featuring transparent upfront token-rate price estimation, HMAC-SHA256 verified idempotent webhooks, and exact decimal cost reconciliation.

---

## Key Highlights & Architectural Decisions

### 1. Correct Money & Mathematical Precision
* **Zero Floating-Point Drift:** All money calculations are computed via PHP's `BCMath` extension with 9 decimal places (`scale = 9`). Amounts are never stored as native floats.
* **Cost Formula:**
  $$\text{Provider Cost} = \sum \frac{\text{Tokens} \times \text{Rate}}{1,000,000}$$
  $$\text{User Charged} = \text{Provider Cost} \times (1 + \frac{25}{100})$$
  $$\text{Operating Profit} = \text{User Charged} - \text{Provider Cost}$$
* **Free Failed Generations:** Generations that fail (`[fail]`, timeouts, or internal errors) are strictly billed at **$0.000000000** with zero charge made to the user.
* **100% Reconciliation:** Successfully verified down to the last decimal digit against Pixelforge's official `GET /v1/usage` endpoint.

### 2. Webhook Robustness & Idempotency
* **HMAC-SHA256 Verification:** Validates the `Pixelforge-Signature` header (`t=<timestamp>,v1=<signature>`) against the raw payload before decoding. Rejects replay attacks older than 300 seconds.
* **Idempotent Deduplication:** Stores and indexes `Pixelforge-Event-Id` in the `webhook_events` table. Duplicate deliveries (e.g. triggered by the `[duplicate]` test tag or network retries) are recognized and skipped so users are never double-billed.
* **Out-of-Order Safety:** State transitions enforce monotonic lifecycle rules: a delayed `generation.processing` webhook will never downgrade an already `succeeded` or `failed` generation.
* **Race Condition Handled:** If a webhook arrives before the initial `POST /v1/generations` response returns, the webhook service creates and links the generation via metadata attributes.

### 3. Stretch Goals Implemented
* **Operator Admin Report (`/admin/report`):**
  * Aggregated daily spend (what users were charged), provider costs, and net profit.
  * Individual user account breakdown.
  * Side-by-side reconciliation table with live data from `GET /v1/usage`.
* **User Credit Balances & Balance Guards:**
  * Tracks user credit balances.
  * Pre-submission check blocks generation if the user's balance is below the maximum possible cost estimate.
  * Interactive "Buy Credits" top-up modal with simulated purchases.

---

## Automated Test Suite

Comprehensive Feature & Unit tests covering the pricing formula, webhook HMAC verification, deduplication, failed generation billing, and balance guards:

```bash
php artisan test
```

Test results:
* `test_pricing_service_calculates_exact_cost_and_markup`: Validates against documentation worked example ($0.373875000 provider cost & $0.467343750 user price).
* `test_webhook_signature_verification`: Validates signature checks and tampered payload rejection.
* `test_webhook_idempotency_prevents_duplicate_billing`: Verifies duplicate deliveries never double charge.
* `test_failed_generations_cost_zero`: Verifies failed jobs are $0.
* `test_out_of_order_webhooks_do_not_downgrade_status`: Verifies state protection.
* `test_insufficient_balance_blocks_generating`: Verifies credit limits.

---

## Tech Stack

* **Backend:** Laravel 12 (PHP 8.2+)
* **Frontend:** Inertia.js + Vue 3 (Composition API, `<script setup>`)
* **Styling:** Tailwind CSS v4
* **Database:** SQLite / PostgreSQL / MySQL (ACID transactions with row-level locks for balance adjustments)
* **API Client:** Guzzle / Laravel HTTP client with timeout & retry handling

---

## Local Setup

1. **Clone repository & install dependencies:**
   ```bash
   composer install
   npm install
   ```

2. **Environment configuration:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Configure Pixelforge credentials in `.env`:**
   ```env
   PIXELFORGE_API_KEY=your_api_key_here
   PIXELFORGE_WEBHOOK_SECRET=your_webhook_secret_here
   PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
   PIXELFORGE_MARKUP_PERCENT=25
   ```

4. **Run database migrations:**
   ```bash
   php artisan migrate
   ```

5. **Build assets & start local server:**
   ```bash
   npm run build
   php artisan serve
   ```
