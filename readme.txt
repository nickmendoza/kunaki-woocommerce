=== Kunaki Fulfillment ===
Contributors: mobilecodemedia
Tags: kunaki, woocommerce, fulfillment, cd, shipping
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.3.4
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrates Kunaki CD/DVD manufacturing and fulfillment with WooCommerce — live shipping rates, automatic order submission, and tracking.

== Description ==

Kunaki Fulfillment connects your WooCommerce store to [Kunaki's](https://kunaki.com) on-demand disc manufacturing service. When a customer places an order containing a Kunaki-linked product, the plugin automatically submits it to Kunaki for manufacturing and shipping, then tracks the order and emails your customer their tracking number.

**Key Features:**

* **Live Shipping Rates** — Real-time Kunaki shipping options and prices displayed at checkout.
* **Automatic Order Submission** — Orders are submitted to Kunaki automatically when payment is received.
* **Order Tracking** — Polls Kunaki for status updates and emails customers their tracking number when shipped.
* **Product Linking** — Link any WooCommerce product to a Kunaki disc via its 10-character Product ID.
* **Mixed Cart Handling** — Handles orders with both Kunaki and non-Kunaki products, splitting them into separate shipments with independent shipping method selection.
* **Retry Queue** — Failed submissions are retried automatically with backoff. An admin page shows failed orders with error details and one-click retry.
* **API Circuit Breaker** — Detects sustained API outages and pauses requests to preserve retry budgets. Automatically recovers when the API comes back.
* **Dashboard Widget** — At-a-glance order summary on the WordPress dashboard: fulfilling, action needed, and retrying counts.
* **Credential Encryption** — Your Kunaki password is encrypted at rest with AES-256-GCM.
* **WP-CLI Support** — `wp kunaki sync` for on-demand bulk status sync.
* **HPOS Compatible** — Fully compatible with WooCommerce High-Performance Order Storage.

**Reliability:**

Kunaki has no order deduplication — every order submitted is manufactured, and there is no undo. This plugin uses atomic database locking and multi-layer duplicate prevention to ensure each order is submitted exactly once, even under concurrent cron executions or webhook retries.

**Third-Party Service: Kunaki**

This plugin connects to the [Kunaki](https://kunaki.com) API to submit orders for disc manufacturing, retrieve shipping rates, and check order status. The following data is sent to Kunaki's servers:

* Your Kunaki account credentials (email and password) for authenticated requests
* Customer shipping address for shipping rate quotes and order fulfillment
* Product IDs and quantities for order submission

No customer data is sent to Kunaki until an order is placed or shipping rates are requested at checkout. Shipping rate lookups do not require authentication and do not include customer identity.

Kunaki's website: [https://kunaki.com](https://kunaki.com)

== Installation ==

1. Upload the `kunaki-woocommerce` folder to `/wp-content/plugins/`, or install directly through the WordPress plugin screen.
2. Activate the plugin.
3. Go to **WooCommerce > Settings > Kunaki** and enter your Kunaki publisher email and password. Set mode to **Test** for development or **Live** for production.
4. Under **WooCommerce > Settings > Shipping > Shipping Zones**, add "Kunaki Shipping" as a shipping method to the appropriate zone(s).
5. Edit any WooCommerce product and enter its Kunaki Product ID in the General tab.

== Frequently Asked Questions ==

= Do I need a Kunaki account? =

Yes. You need a [Kunaki](https://kunaki.com) publisher account with at least one product set up. The plugin uses your Kunaki credentials to submit orders and check status.

= What is Test mode? =

Test mode lets you verify your setup without manufacturing real discs. Orders are submitted to Kunaki's test endpoint. Make sure your payment gateway is also in test/sandbox mode when testing.

= How does shipping work? =

When you add "Kunaki Shipping" to a WooCommerce shipping zone, the plugin queries Kunaki's API at checkout for real-time shipping rates based on the customer's address and cart contents. Rates are cached for 30 minutes to keep checkout fast.

= What happens with mixed carts (Kunaki + non-Kunaki products)? =

The plugin splits the cart into separate shipping packages — one for Kunaki items (with Kunaki shipping rates) and one for everything else (with your regular shipping methods). Only Kunaki items are submitted to Kunaki. The order won't auto-complete until you handle the non-Kunaki portion.

= What if an order fails to submit? =

Failed orders are automatically retried with linear backoff (attempts × 15 minutes). After the configured maximum attempts (default: 3), the order is flagged for manual review. You can also retry manually from the order edit page or the retry queue admin page.

= What if Kunaki's API goes down? =

The built-in circuit breaker detects sustained failures and pauses all API calls. An admin notice alerts you. Once the API recovers, the circuit breaker automatically resumes normal operation and queued retries continue.

= Is my password stored securely? =

Yes. Your Kunaki password is encrypted using AES-256-GCM before being stored in the database. It is never stored in plaintext.

= Does this plugin work with HPOS? =

Yes. The plugin declares full compatibility with WooCommerce High-Performance Order Storage (custom order tables).

== Screenshots ==

1. **Settings** — Configure credentials, mode, sync interval, and debug logging under WooCommerce > Settings > Kunaki.
2. **Shipping Zone** — Add Kunaki Shipping as a method in any WooCommerce shipping zone for live rates at checkout.
3. **Product Linking** — Enter the 10-character Kunaki Product ID on any product's General tab.

== Changelog ==

= 1.3.4 =
* Fix: Add delete_site_transient stub for PHPStan compatibility.
* CI: Add release workflow for automated ZIP builds.

= 1.3.3 =
* Fix: Replace HPOS NOT EXISTS meta queries with PHP-side filtering for broader compatibility.
* Feat: Surface Kunaki XML billing info for PENDING orders.

= 1.3.2 =
* Feat: Add admin notice when Kunaki is in test mode.

= 1.3.1 =
* Fix: Harden retry handler, add server-side settings validation, warn on encrypt failure.

= 1.3.0 =
* Feat: Split shipping packages for Kunaki vs non-Kunaki products (mixed cart support).
* Fix: Recalculate contents_cost when splitting shipping packages.
* Fix: Remove legacy CBC fallback and clean up order/product meta on uninstall.

= 1.2.0 =
* Feat: API circuit breaker with automatic recovery.
* Feat: Dashboard widget with order summary counts.
* Fix: Short-circuit bad credentials, mark permanent failures exhausted, harden batch loops.
* Fix: Reset retry budget when admin manually retries an exhausted order.

== Upgrade Notice ==

= 1.3.0 =
Adds mixed cart support — orders with both Kunaki and non-Kunaki products now get separate shipping packages at checkout.
