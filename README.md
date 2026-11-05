# Kunaki WooCommerce

[![CI](https://github.com/nickmendoza/kunaki-woocommerce/actions/workflows/ci.yml/badge.svg)](https://github.com/nickmendoza/kunaki-woocommerce/actions/workflows/ci.yml)

Reliable [Kunaki](https://kunaki.com) disc fulfillment for WooCommerce -- live shipping rates, idempotent order submission, and concurrency-safe tracking synchronization.

## Why This Plugin Exists

Kunaki manufactures and ships CDs, DVDs, and Blu-ray discs on demand. Their integration surface is a stateless XML API with no webhooks, no order deduplication, and no delivery callbacks. Every order submitted is manufactured. There is no undo. In production stores, even a single duplicate submission represents real manufacturing cost and customer confusion.

This creates a set of problems that naive implementations get wrong:

- **Double submissions.** WooCommerce fires `woocommerce_order_status_processing` on every transition to "processing." Payment gateways, admin actions, and webhook retries can all trigger it concurrently. Without locking, the same order gets manufactured twice.
- **Lost tracking numbers.** Kunaki provides tracking data only through status polling. Without a reliable sync loop, customers never receive shipping confirmation.
- **Duplicate emails.** If the status sync runs concurrently (overlapping cron executions), the same tracking note gets added twice, sending the customer two identical emails.
- **Mixed carts.** Orders containing both Kunaki and non-Kunaki products need partial fulfillment -- only the disc items go to Kunaki, and the order should not auto-complete until the store owner handles the rest.

This plugin addresses these concerns through atomic locking, idempotent submission, save-before-notify ordering, and per-order concurrency control.

## Features

- **Live Shipping Rates** -- Real-time Kunaki shipping options and prices at checkout, cached via transients.
- **Idempotent Order Submission** -- Orders are submitted to Kunaki exactly once, protected by atomic locks and multi-layer duplicate prevention.
- **Order Tracking** -- Cron-based polling syncs Kunaki status and emails customers their tracking number.
- **Product Linking** -- Link any WooCommerce product or individual variation to a Kunaki disc via its 10-character Product ID. Variations without their own ID use the parent product's.
- **Mixed Cart Handling** -- Splits checkout into separate shipments for Kunaki and non-Kunaki products, each with its own shipping method selection. Submits only Kunaki items to Kunaki; defers order completion when non-Kunaki items are present.
- **Retry Queue** -- Admin page listing failed submissions with error details, attempt counts, and one-click retry buttons. Per-order retry on the order edit page.
- **HPOS Compatible** -- Declares compatibility with WooCommerce High-Performance Order Storage.
- **Automatic Retry** -- Failed submissions are retried automatically via cron with linear backoff and a configurable attempt limit.
- **API Circuit Breaker** -- Detects sustained Kunaki API failures and temporarily halts outbound requests, preserving retry budgets and surfacing an admin notice. Automatically probes for recovery.
- **Structured Logging** -- JSON-formatted log entries with contextual fields (order ID, error details) for machine-parseable log aggregation.
- **WP-CLI Support** -- `wp kunaki sync` command for on-demand bulk status sync with `--dry-run` and `--limit` options.
- **Predictive Delivery Estimates** -- Displays estimated delivery date ranges at checkout based on historical manufacturing times and parsed transit durations.
- **Dashboard Widget** -- At-a-glance Kunaki order summary on the WordPress dashboard: fulfilling, action needed, and retrying counts with circuit breaker status and average manufacturing time.
- **Credential Encryption** -- Passwords encrypted at rest with AES-256-GCM.

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+ with OpenSSL extension

## Installation

1. Download `kunaki-woocommerce-x.x.x.zip` from the [latest GitHub release](https://github.com/nickmendoza/kunaki-woocommerce/releases/latest) (under **Assets**, not the auto-generated "Source code" archives).
2. In WordPress, go to **Plugins > Add New > Upload Plugin** and upload the ZIP.
3. Activate the plugin.
4. Navigate to **WooCommerce > Settings > Kunaki** and enter your Kunaki publisher email and password. Set mode to **Test** for development or **Live** for production.
5. Under **WooCommerce > Settings > Shipping > Shipping Zones**, add "Kunaki Shipping" as a shipping method to the appropriate zone(s).
6. Edit any WooCommerce product and enter its Kunaki Product ID in the General tab.

### Screenshots

**Settings** -- Configure credentials, mode, sync interval, and debug logging under WooCommerce > Settings > Kunaki.

![Kunaki Settings](assets/screenshots/settings.png)

**Shipping Zone** -- Add Kunaki Shipping as a method in any WooCommerce shipping zone for live rates at checkout.

![Kunaki Shipping Zone](assets/screenshots/shipping.png)

**Product Linking** -- Enter the 10-character Kunaki Product ID on any product's General tab.

![Kunaki Product ID](assets/screenshots/simple-product.png)

### Settings

| Setting | Description |
|---------|-------------|
| Kunaki Email | Your Kunaki publisher account email |
| Password | Your Kunaki account password (encrypted at rest, never shown after saving; leave blank to keep the saved one) |
| Mode | `Test` (no real orders manufactured) or `Live` (production) |
| Sync Interval | How often to poll Kunaki for status updates (default: 15 min, range: 5--1440 min) |
| Max Retry Attempts | Maximum total attempts for a failed submission before requiring manual intervention (default: 3, range: 1--10) |
| Debug Logging | Log full API requests/responses (credentials redacted) to WooCommerce > Status > Logs |
| Failure Threshold | Number of consecutive API failures before the circuit breaker trips (default: 5, range: 2--20) |
| Cooldown Period | Minutes to wait before allowing a probe request after the circuit trips (default: 5, range: 1--60 min) |
| Remove Data on Uninstall | Also delete Kunaki order IDs, tracking details, and product IDs when the plugin is deleted (default: off, so order history is kept) |

### Shipping Countries

Kunaki only ships to the [countries on its list](https://kunaki.com/XMLService.htm), about 45 of WooCommerce's 250. Customers elsewhere see WooCommerce's "no shipping options" message, because Kunaki returns no options for them. To avoid that, limit **WooCommerce > Settings > General > Selling location(s)** or your shipping zones to those countries.

## Architecture Overview

The plugin is organized into thirteen single-responsibility components, coordinated by a singleton orchestrator. No component performs direct SQL outside controlled locking primitives.

```
kunaki-woocommerce.php                   Bootstrap, constants, encryption filters, cron setup
includes/
  class-kunaki-woocommerce.php           Orchestrator: loads components, registers hooks
  class-kunaki-api-client.php            Stateless XML API client (shipping, orders, status)
  class-kunaki-shipping-method.php       WC_Shipping_Method: live rates with transient caching
  class-kunaki-order-fulfillment.php     Event-driven order submission with atomic locking
  class-kunaki-order-status-sync.php     Cron-based status polling with per-order locks
  class-kunaki-retry-queue.php           Failed order admin page with manual retry
  class-kunaki-product-meta.php          Product editor field, validation, admin column
  class-kunaki-settings.php              WooCommerce settings tab
  class-kunaki-circuit-breaker.php       API circuit breaker with state machine
  class-kunaki-delivery-estimator.php    Predictive delivery date estimates from historical data
  class-kunaki-dashboard-widget.php      Dashboard widget (order summary counts)
  class-kunaki-cli-command.php           WP-CLI commands (bulk status sync)
  class-kunaki-logger.php                Structured JSON log wrapper with debug gating
  encryption.php                         AES-256-GCM encrypt/decrypt helpers
uninstall.php                            Cleanup on plugin deletion (order and product data only if opted in)
```

### Event-Driven Order Submission

Order submission is triggered by the `woocommerce_order_status_processing` hook. Because that hook fires inside checkout and payment-gateway webhooks, it only queues a background `kunaki_wc_submit_order` action via Action Scheduler (bundled with WooCommerce); the Kunaki API call happens outside the payment request. The fulfillment handler collects Kunaki-linked line items, resolves the shipping description stored at checkout, maps the shipping address to Kunaki's format, and submits via the API client. The API client is stateless and injectable -- it reads credentials from `wp_options` by default but accepts constructor overrides for testing.

### Cron-Based Status Polling

A WP-Cron event (`kunaki_wc_sync_order_status`) runs on a configurable interval (default: 15 minutes, range: 5--1440). It queries all WooCommerce orders in "processing" status that have a stored Kunaki Order ID, polls the Kunaki API for each, and handles status transitions: SHIPPED triggers tracking delivery and optional auto-completion, DECLINED moves the order to on-hold, and intermediate statuses (PROCESSING, APPROVED, PENDING) log admin notes.

### Performance Considerations

- Status polling queries only orders in "processing" status with a stored Kunaki Order ID -- not the full order table.
- Locks are short-lived and self-cleaning; expired locks are pruned atomically before each acquisition attempt.
- Shipping rates are cached via transients (30-minute TTL) to minimize outbound API calls during checkout.

### Separation of Concerns

The API client knows nothing about WooCommerce orders. The status sync knows nothing about order submission. The fulfillment handler owns both event-driven submission and cron-based retry, but knows nothing about status polling. Each component is independently testable and interacts only through order meta and the API client interface.

## Reliability and Concurrency Guarantees

### Atomic Locking

Both order submission and status sync acquire per-order locks using `INSERT IGNORE` into `wp_options`. This is a single atomic SQL statement -- only one process wins the insert. Locks carry an expiry timestamp. Before acquiring, expired locks are cleaned up with a `DELETE WHERE option_value < NOW()` query (also atomic, no SELECT-then-DELETE race).

- Submission locks expire after 5 minutes.
- Sync locks expire after 2 minutes.

### Idempotent Submission

Order submission is protected by four layers:

1. **Meta check** -- `_kunaki_submitted = yes` in order meta skips immediately.
2. **Fallback marker check** -- If a previous submission succeeded at Kunaki but `$order->save()` failed catastrophically, a durable marker in `wp_options` preserves the Kunaki Order ID. On the next trigger, the marker is detected, meta is restored, and the order is not resubmitted.
3. **Atomic lock** -- `INSERT IGNORE` ensures only one concurrent process proceeds.
4. **Post-lock re-check** -- After acquiring the lock, the order is reloaded from the database to catch any submission that completed between the initial check and lock acquisition.

### Failure Recovery

If the Kunaki API accepts an order but `$order->save()` throws, the plugin attempts `$order->save_meta_data()` as a narrower fallback. If that also fails, a durable marker (`_kunaki_submitted_{order_id}`) is written directly to `wp_options` via `INSERT IGNORE`, storing the Kunaki Order ID. This marker survives lock expiration and prevents resubmission on any subsequent trigger. If even the marker write fails, the lock is deliberately retained to block further attempts until manual intervention.

### Automatic Retry

When the Kunaki API returns an error or the HTTP request fails, the plugin records structured failure metadata on the order (error message, timestamp, attempt count) and releases the lock. A separate WP-Cron event (`kunaki_wc_retry_failed_orders`) runs on the same interval as the status sync and retries eligible orders automatically.

- **Linear backoff** -- Each retry waits `attempts * 15 minutes` since the last failure before retrying.
- **Configurable limit** -- After the configured max attempts (default: 3), the order is marked exhausted and an order note alerts the admin.
- **Permanent failure detection** -- Errors that will never self-resolve (e.g., missing Kunaki shipping method on the order) are immediately marked as exhausted without consuming retry attempts.
- **Credential validation** -- If the stored password cannot be decrypted (e.g., after salt rotation), submission, retry, and status sync all bail early without making API calls or burning retry budgets.
- **Durable failure markers** -- If failure metadata fails to persist (e.g., database instability), a `_kunaki_failed_{order_id}` marker is written to `wp_options`. The retry cron recovers these orphaned markers at the start of each run.
- **Idempotent** -- Retries go through the same `handle_order_processing` path with its full locking and deduplication guarantees.

### API Circuit Breaker

When the Kunaki API suffers sustained outages, the circuit breaker prevents the plugin from hammering the endpoint with requests that will time out. It tracks consecutive infrastructure failures (HTTP errors, non-200 status codes, XML parse errors) across all API callers — order submission, status sync, and shipping rate lookups.

- **Closed** (normal): all requests proceed. A counter tracks consecutive failures.
- **Open** (tripped): after the configured failure threshold (default: 5), all API calls return immediately with a `WP_Error`. Cron handlers (retry queue, status sync) stop their batch loops until the cooldown has passed, avoiding pointless lock churn. An admin notice alerts the store owner.
- **Half-open** (probe): after the cooldown period (default: 5 minutes), one request is allowed through. Cron handlers send it too, so a store with no checkout traffic still recovers. An atomic claim ensures concurrent requests can't all probe at once. If it succeeds, the circuit closes. If it fails, the circuit re-opens for another cooldown period.

API business logic errors (Kunaki `ErrorCode != 0`) do not count toward the threshold — they indicate the API is responding, not that it's down.

When the circuit is open, order submission failures do not increment the retry attempt counter, preserving the retry budget for when the API recovers.

### Email Duplication Prevention

The status sync saves all meta and status changes via `$order->save()` before adding any customer-facing order notes. If `save()` fails, no notes are persisted and the next cron run retries from a clean state. To handle the crash window between a successful `save()` and `add_order_note()`, pending notes are stored in order meta atomically with the status change. If the process dies after save but before the note is sent, the next cron run detects the pending note, delivers it, and clears the flag.

All critical state transitions are deterministic and replay-safe.

## Security Considerations

### Encryption at Rest

The Kunaki password is encrypted using AES-256-GCM before storage in `wp_options`. The encryption key is derived from WordPress's `AUTH_SALT` via SHA-256. Each encryption generates a fresh 12-byte random nonce. The stored value is `base64(nonce || tag || ciphertext)`.

If decryption fails (typically after salt rotation during site migration), the plugin sets a persistent flag and displays an admin notice prompting the user to re-enter their password. The flag clears itself if the password can be decrypted again, for example after the previous salts are restored.

Encryption and decryption are applied transparently via `pre_update_option` and `option_{name}` filters. No plaintext password is stored in the database at any point after initial save.

### Credential Handling

- Credentials appear in API payloads only for authenticated operations (order submission and status polling). Shipping rate lookups are unauthenticated.
- All API request/response logging automatically redacts `<UserId>` and `<Password>` XML elements before writing to the WooCommerce log.
- The settings page never sends the saved password to the browser. The field is always blank, and submitting it blank keeps the saved password.

## Testing Strategy

The test suite runs entirely outside WordPress. No WordPress installation, no database, no WooCommerce runtime. All WordPress and WooCommerce functions are stubbed using [Brain Monkey](https://github.com/Brain-WP/BrainMonkey), and object dependencies are mocked with [Mockery](https://github.com/mockery/mockery).

This isolation strategy means tests execute in seconds, run in CI without infrastructure, and validate plugin logic without conflating it with WordPress behavior.

261 unit tests cover:

| Area | What's Validated |
|------|-----------------|
| API Client | XML request construction, response parsing, HTTP error handling, HTML-wrapped response extraction, credential injection |
| Order Fulfillment | Duplicate submission prevention across all four layers, atomic lock acquisition and release, meta storage, mixed cart detection, fallback marker recovery, failure meta recording and cleanup, auto-retry with backoff, exhaustion handling, orphaned failure recovery, retry logic |
| Order Status Sync | SHIPPED/DECLINED/PENDING transitions, tracking info extraction, auto-completion logic for pure-Kunaki vs. mixed orders, save-before-notify ordering |
| Shipping Method | Rate calculation, Kunaki product filtering, transient cache hit/miss, state/province handling for US/CA vs. international, country name resolution |
| Product Meta | 10-character alphanumeric validation, capability checks, admin column rendering, safe handling of absent POST fields |
| Logger | Debug gating based on settings, log level routing, structured JSON context, invalid UTF-8 fallback |
| Retry Queue | AJAX handler permission and input validation, `prepare_for_retry` guard clauses, admin page hook registration |
| Circuit Breaker | State transitions (closed/open/half_open), threshold and cooldown configuration, error classification (infrastructure vs. business logic), probe recovery, admin state reporting |
| CLI Command | Dry-run listing, limit enforcement, sync dispatch, empty-queue handling |
| Delivery Estimator | Transit day parsing, business-to-calendar conversion, percentile statistics, transient caching, date range formatting, end-to-end estimate generation |
| Dashboard Widget | Hook registration, capability gating, zero/mixed-state count rendering, circuit breaker warning display, manufacturing time stats, URL generation |
| Settings | Settings field definitions, sanitization callbacks, defaults and range enforcement |
| Shipping Packages | Mixed cart splitting, per-package rate filtering, destination preservation, package naming, contents_cost recalculation, multi-package splitting |
| Bootstrap | Hook registration, component loading, cron scheduling, HPOS declaration |
| Encryption | AES-256-GCM round-trip, invalid/corrupt input handling, salt rotation detection |

```bash
composer install
composer test
```

Run a specific test file or filter by name:

```bash
composer test -- tests/Unit/APIClientTest.php
composer test -- --filter "shipped"
```

## Roadmap

These are planned or under consideration. No guarantees on timeline.

- **Webhook receiver** -- If Kunaki adds push notifications for status changes, replace polling with a webhook endpoint to eliminate sync latency.

## Contributing

Contributions are welcome. Please open an issue before submitting a pull request for non-trivial changes.

```bash
composer install
composer test
```

All pull requests must pass the existing test suite. New features should include corresponding tests.

## License

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version.

See the [GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html) for the full text.

## Disclaimer

This software is provided "as is" without warranty of any kind, express or implied. Use at your own risk. This project is not affiliated with, endorsed by, or sponsored by Kunaki, WordPress, or WooCommerce.
