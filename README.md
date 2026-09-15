# SaaS Billing

A usage-based billing backend: merchants define plans (flat price + included usage + overage rate), their customers subscribe, usage gets logged per subscription, and invoices are generated automatically at the end of each billing cycle — with proration for customers who joined or switched plans mid-cycle.

This is an API backend only. There's no UI.

## Domain model

```
Merchant ──┬──< Customer ──< CustomerSubscription >── Plan
           │                        │
           └───< UsageRecord ───────┴──< CustomerSubscriptionPlanChange
                     ▲
                     │ (daily aggregate, fed by)
                UsageEvent (raw ledger, one row per logged event)
```

- **Merchant** — the tenant. Owns plans and customers.
- **Plan** — price, included usage, overage rate. Editing a plan's price never retroactively changes what existing subscribers were already billed (see [Idempotency & correctness](#idempotency--correctness)).
- **Customer** — belongs to a merchant.
- **CustomerSubscription** — a customer's subscription to a plan. Tracks the *current* billing period (`current_period_start`/`current_period_end`).
- **CustomerSubscriptionPlanChange** — one row per period a subscription spent on a given plan, with that plan's terms *snapshotted* at the time. This is what makes plan switches and price changes billing-safe.
- **UsageEvent** — the raw, deduplicated ledger of every usage event logged via the API.
- **UsageRecord** — one row per subscription per day, the daily aggregate usage_events roll up into.
- **UsageRollup** — one row per (plan-change segment, billing period), a running total kept fresh by a daily batch job. This is what invoicing and the dashboard actually read from.
- **Invoice / InvoiceLine** — one line per plan-change segment that overlapped the invoiced period.

## How a request actually flows

1. A merchant's backend calls `POST /api/usage-events` with a `customer_subscription_id`, an `idempotency_key`, and a `quantity`. This lands in `usage_events` (deduplicated) and atomically increments that day's `usage_records` row.
2. Once a day, `RollUpDailyUsage` (scheduled 01:00) folds each subscription's `usage_records` into `usage_rollups` — advancing a watermark so it only ever adds usage for days not yet counted.
3. Once a day, `GenerateInvoicesAtCycleEnd` (scheduled 02:00) finds subscriptions whose billing period just ended, prices them (base + overage, prorated per plan segment), writes one invoice, and advances the subscription to its next period.
4. `GET /api/merchants/{merchant}/dashboard` reads `usage_rollups` and `invoice_lines` — never the raw `usage_events`/`usage_records` tables — to show top customers by usage, projected overage revenue, and churn-risk customers, cached for an hour.

## Running it

**Requirements:** PHP 8.3+ with the `bcmath` extension, Composer, and a reachable Redis server (cache + queues both point at Redis; the app uses `predis/predis` so no `ext-redis` build is required). On Windows, `winget install Redis.Redis` gets a real, working local Redis (an older native port, but functionally complete for this) running as an auto-starting service in under a minute — that's what this was actually developed and verified against, not a stand-in.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # required: Laravel's SQLite driver errors if this file doesn't already exist, it won't create it for you
php artisan migrate
```

The database is SQLite (`database/database.sqlite`, gitignored) in every environment, including this app's own idea of "production" — that was an explicit early choice, not an oversight; see [Assumptions](#assumptions--open-questions) for the caveat.

**Running the app:**

```bash
php artisan serve                 # HTTP
php artisan queue:work            # queued jobs (there's no Horizon here — see below)
php artisan schedule:work         # runs the two daily jobs on their schedule, for local dev
```

In production, `schedule:work` is replaced by a single cron entry (`* * * * * php artisan schedule:run`) rather than a long-running process.

Laravel Horizon isn't installed: it requires the `pcntl` extension, which doesn't exist on Windows PHP builds at all. `queue:work` is the portable fallback; add Horizon back in a Linux/container deployment if you want the dashboard.

**Getting an API token to call the endpoints:**

There's no signup flow or admin UI yet, so tokens are minted directly:

```bash
php artisan tinker
>>> $user = \App\Models\User::factory()->create();
>>> $user->createToken('some-integration')->plainTextToken
```

Send that as `Authorization: Bearer <token>`. See [Assumptions](#assumptions--open-questions) for what this token does and doesn't prove right now.

**Tests:**

```bash
php artisan test
```

Tests run against in-memory SQLite with the `array` cache and `sync` queue (configured in `phpunit.xml`), so they need no Redis or queue worker running. See `tests/Unit` for pure-math tests (no DB) and `tests/Feature` for everything that touches the database.

## Key decisions, and why

### Indexing

`usage_records` (the table expected to grow into millions of rows) has `unique(customer_subscription_id, usage_date)` as its leading index — every hot query (ingest one day's row, sum a date range for one subscription) is a range scan against that one composite index, never a full-table scan. `merchant_id`/`customer_id` are denormalized onto both `usage_records` and `usage_events` specifically so merchant- or customer-scoped queries don't need a join through subscriptions to reach the busiest table in the schema.

`usage_rollups` — the real answer to "keep this fast forever" — is keyed by `(plan_change_id, period_start)`, not just by plan-change segment. A segment can span many billing periods if the customer never switches plans; keying by segment alone would make its running total grow across periods instead of resetting each cycle. Every read (dashboard, invoicing) hits this small, indexed summary table, never the raw event ledger.

### Idempotency & correctness

The rule followed everywhere duplicates matter: **detect duplicates with a database unique index during the write itself, never with a `SELECT` beforehand.** A `SELECT`-then-`INSERT` check has a race window — two concurrent requests can both see "not taken" and both proceed. `insertOrIgnore` against a unique index doesn't have that window, because the uniqueness check and the write are the same atomic operation as far as the storage engine is concerned:

- `usage_events` — unique on `(customer_subscription_id, idempotency_key)`. Proven with an actual concurrency test (two real OS processes racing the same key) during development, not just a sequential retry.
- `invoices` — unique on `(customer_subscription_id, period_start)`. A rerun (cron misfire, queue retry) that reaches invoice generation for an already-invoiced period is a no-op, not a duplicate.
- Daily usage totals are updated with one atomic `INSERT ... ON CONFLICT DO UPDATE quantity = quantity + ?` statement, not "read, add, write back" — which would lose increments under concurrent writes to the same row.
- Plan pricing history works the same way conceptually: `CustomerSubscriptionPlanChange` snapshots a plan's terms at the moment they took effect, so editing `Plan.price` only affects *future* snapshots. Past invoices stay correct without needing a full versioned-catalog system.

### Caching

Two caches exist, with **deliberately different invalidation strategies**, because the two things being cached have different write patterns:

- `PlanCache` — bust-on-write (a model observer clears the specific plan's cache entry on save/delete/restore). This fits because plans change rarely, and staleness there is directly visible to whoever reads the price.
- `MerchantDashboardCache` — a plain 1-hour TTL, no observer. `usage_rollups` (the dashboard's main input) is written once per subscription *every single day* by the batch job — wiring instant invalidation to that would mean the cache barely survives between requests, which defeats the point. A TTL is also just the honest answer to what the data is: the dashboard is never fresher than "as of the last rollup" regardless of caching, so bounding staleness to an hour costs nothing real.

Both are single classes owning their own cache-key format — nothing else in the app constructs a `plan:{id}` or `dashboard:merchant:{id}` string by hand.

**A real bug this caught, worth naming directly:** both caches store PHP objects (a `Plan` model, a `MerchantDashboardData` DTO), and the first time this ran against an actual Redis server — as opposed to the `array` driver every automated test and all earlier local checks used, which never serializes at all — every dashboard/plan-cache read failed with `TypeError: ... __PHP_Incomplete_Class returned`. The cause: Laravel 11+ defaults `config('cache.serializable_classes')` to `false`, which makes `unserialize()` refuse to reconstruct *any* object from cache (a hardening default against gadget-chain attacks if `APP_KEY` ever leaks) — it silently downgrades every cached object to a useless placeholder instead of throwing where the cache was written. The fix is the one the framework actually expects: an explicit allow-list in `config/cache.php` naming exactly the classes this app caches (`Plan`, `MerchantDashboardData`, `TopCustomerUsage`, `ChurnRiskCustomer`, `CarbonImmutable`), not disabling the protection wholesale. `tests/Feature/CacheSerializationTest.php` forces the `file` cache driver (which does serialize, unlike `array`, and needs no live Redis) specifically to keep this caught in CI — reverting the allow-list makes that test fail with the exact same error, which is how it was verified to actually catch the regression rather than passing by coincidence.

### Background jobs

`RollUpDailyUsage` and `GenerateInvoicesAtCycleEnd` both `chunkById` through subscriptions rather than loading a table wholesale, and each subscription's rollup is one narrow, indexed `SUM()` query — memory footprint stays flat regardless of table size, "batches" means batches of subscriptions, not rows.

`GenerateInvoiceForSubscription` calls the rollup logic itself (through the closing day specifically) before reading totals, rather than trusting that the two scheduled jobs ran in the right order — it's correct even if run standalone.

The proration math (`InvoiceCalculator`, `DashboardCalculator`) is deliberately isolated from anything that touches the database or a queue — plain classes, `bcmath` arithmetic over plain string inputs, no Eloquent. This wasn't just a style preference: it's what let bugs get caught by fast, deterministic unit tests during development (a `bcmath` scale-truncation bug and a precision-loss bug from computing a day-fraction before multiplying, both in `InvoiceCalculator`; see its test file for the fixed numbers) rather than surviving into a slow, DB-backed test or production.

### Rate limiting

The usage-events endpoint's limiter is keyed by the caller's Sanctum token (`currentAccessToken()->id`), not IP — a merchant's backend can legitimately call the endpoint from many source IPs behind one token, and IP-based limiting would either let that traffic dodge the limit across IPs or wrongly throttle unrelated tokens sharing a corporate egress IP. The limit itself (`config/billing.php`, env-backed) is read fresh on every request, not fixed at boot.

Laravel also ships a Redis-native throttle implementation (`ThrottleRequestsWithRedis`) that's more atomic than the default cache-based one. It was deliberately *not* used here: it bypasses the app's `CACHE_STORE` config entirely and hard-depends on a live Redis connection via a separate connection path, which would make the test suite require a running Redis server for no real benefit — the default limiter's tiny boundary-race tolerance is standard, accepted behavior for abuse-prevention throttling (as opposed to, say, the usage-dedup logic above, which does need hard atomicity and gets it from the schema, not the rate limiter).

## What I'd improve with more time

Roughly in priority order:

1. **Tenant-scoped auth.** This is the biggest real gap. `auth:sanctum` currently only proves *some* token is valid — nothing ties a token to a merchant, so `POST /api/usage-events` and `GET /api/merchants/{merchant}/dashboard` trust the caller's request body/URL rather than verifying ownership. Every route that touches merchant data has a `// TODO` marking this. Fixing it properly means a merchant-scoped API key concept (a `Merchant` a token belongs to, and middleware that checks the route's `{merchant}`/`customer_subscription_id` actually belongs to the authenticated token) before this is safe to expose publicly.
2. **A `SwitchCustomerSubscriptionPlan` action.** The schema and the invoicing math both fully support mid-cycle plan switches (close the old `CustomerSubscriptionPlanChange`, open a new one, update the subscription's plan pointer), and the tests simulate that flow directly — but the actual action a merchant would call to *perform* a switch was never built.
3. **`usage_records` retention.** The table is designed to index and query well at scale, but nothing yet archives or rolls up old daily rows once a period is invoiced. At real volume, this table grows forever. The natural next step is summarizing a closed period's raw rows and purging them, once `usage_rollups`/`invoice_lines` already hold everything downstream consumers need.
4. **Revisit SQLite for production.** It was an explicit, deliberate choice (see below), but it has a single-writer lock — daily usage ingestion from many merchants writing concurrently could eventually serialize on that. The schema is already portable (Postgres/MySQL-compatible SQL throughout); this is a config change, not a rewrite, if usage volume gets real.
5. **Soft-delete vs. unique constraints.** A soft-deleted `Merchant`/`Plan`/`Customer` still occupies its unique index slot (`slug`, `email`), so the same value can't be reused immediately after deletion. Rare in practice, fixable with a partial unique index scoped to `deleted_at IS NULL` if it matters (Postgres/SQLite support it cleanly; MySQL doesn't).
6. **Actually wire up Cashier.** It's installed (Stripe subscriptions/invoicing) but nothing uses it yet — see the assumption below about what it's even for in this system.
7. Model factories for `Merchant`/`Customer`/`Plan`/etc. — every test currently builds its fixtures by hand with `Model::create()`. Fine at the current test count, would get repetitive at 3-4x the size.

## Assumptions & open questions

Places where the brief left a real decision open and I picked something reasonable rather than stopping to ask:

- **What Cashier is for.** `laravel/cashier` was installed on the assumption that *merchants* might eventually pay this platform (Stripe subscriptions billing the merchant), which is a separate concern from the usage-based billing this system runs for *merchants' own customers* (plain `decimal` columns, no Stripe involved). Nothing currently wires Cashier's `Billable` trait onto any model, because it was never clear whether that's `Merchant` or something else — I left it installed but unwired rather than guessing at a data model for a feature that wasn't specified.
- **Billing cycles are calendar-anchored, not signup-anchored.** "Prorate if they joined mid-cycle" only makes sense as a requirement if a merchant's billing periods have fixed boundaries independent of when a given customer signed up (otherwise a fresh subscription's first period is just a normal full period by construction, nothing to prorate). I built proration on that assumption — the same day-overlap formula handles a mid-cycle join and a mid-cycle plan switch identically, which is the schema's central idea.
- **Usage is logged daily, not per raw event, in the summary layer.** `usage_records` is one row per subscription per day; `UsageEvent` (the deduplicated raw ledger) exists underneath it. This was sized against "grows into millions of rows" — thousands of subscriptions × 365 rows/year, not thousands of subscriptions × every API call.
- **Merchant is the tenant model**, separate from the `spatie/laravel-permission` "teams" feature that's enabled in `config/permission.php`. That package is set up for potential internal-staff RBAC (different staff members having different access to a merchant's dashboard), which is a distinct concern from the Merchant/Customer domain model — I didn't connect the two without a concrete requirement to.
- **The churn-risk drop threshold (50%) is a placeholder**, defined once in `DashboardCalculator::isChurnRisk()` as a default parameter — not specified anywhere, easy to tune. The top-customers list length is *not* a guess: it's 5, per the brief's explicit spec, not a rounder default I'd picked before seeing it.
- **The dashboard's wireframe panels were matched deliberately, with one adaptation.** "Current cycle usage" (a merchant-wide usage-vs-allowance total) and "% of allowance" per customer aren't in the brief's numbered functional requirements, only in its suggested wireframe — they're included anyway since they're cheap given data already in hand. The wireframe's single "Active Plan" tile was *not* replicated as-is: this schema lets one merchant run several plans concurrently, so "the" active plan doesn't always exist — shown as an active-subscription count instead, with the substitution called out in the UI itself rather than silently reinterpreted. The wireframe's "Daily Usage Trend" chart was left out: it needs a new day-by-day aggregation this schema doesn't currently expose (the summary tables are period-level, not daily), and the brief is explicit that UI polish isn't what's being evaluated here — schema and aggregation decisions are.
- **A merchant's "current plan" pointer (`CustomerSubscription.plan_id`) and `usage_records.plan_id`** both reflect whichever plan was active *when last touched*, not necessarily "right now" if a switch happens mid-day — documented as a known limitation in `LogUsageEvent`, not silently glossed over.
