=== Kunaki WooCommerce ===
Contributors: mobilecodemedia
Tags: kunaki, woocommerce, fulfillment, cd, shipping
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.4.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrates Kunaki CD/DVD manufacturing and fulfillment with WooCommerce — live shipping rates, automatic order submission, and tracking.

== Description ==

Kunaki WooCommerce connects your WooCommerce store to [Kunaki's](https://kunaki.com) on-demand disc manufacturing service. When a customer places an order containing a Kunaki-linked product, the plugin automatically submits it to Kunaki for manufacturing and shipping, then tracks the order and emails your customer their tracking number.

**Key Features:**

* **Live Shipping Rates** — Real-time Kunaki shipping options and prices displayed at checkout.
* **Automatic Order Submission** — Orders are submitted to Kunaki automatically when payment is received.
* **Order Tracking** — Polls Kunaki for status updates and emails customers their tracking number when shipped.
* **Product Linking** — Link any WooCommerce product to a Kunaki disc via its 10-character Product ID.
* **Mixed Cart Handling** — Handles orders with both Kunaki and non-Kunaki products, splitting them into separate shipments with independent shipping method selection.
* **Retry Queue** — Failed submissions are retried automatically with backoff. An admin page shows failed orders with error details and one-click retry.
* **API Circuit Breaker** — Detects sustained API outages and pauses requests to preserve retry budgets. Automatically recovers when the API comes back.
* **Email and Slack Alerts** — Optional alerts when an order needs you (it failed every retry, can't be sent until something is fixed, or Kunaki declined it), and when Kunaki's API goes down and comes back.
* **Dashboard Widget** — At-a-glance order summary on the WordPress dashboard: fulfilling, action needed, and retrying counts.
* **Credential Encryption** — Your Kunaki password is encrypted at rest with AES-256-GCM.
* **WP-CLI Support** — `wp kunaki sync` for on-demand bulk status sync.
* **Delivery Estimates** — Shows estimated delivery dates at checkout, based on past manufacturing times.
* **Classic and Block Checkout** — Works with the classic cart and checkout pages and with the cart and checkout blocks.
* **HPOS and Legacy Storage** — Works with WooCommerce High-Performance Order Storage and with legacy order storage.

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

= Can I be told when an order fails? =

Yes. Under **WooCommerce > Settings > Kunaki > Alerts**, turn on alerts and choose email, Slack, or both. You'll get one message per order that needs you, and one when Kunaki's API goes down and when it recovers. Alerts are off by default.

= Is my password stored securely? =

Yes. Your Kunaki password is encrypted using AES-256-GCM before being stored in the database. It is never stored in plaintext.

= Does this plugin work with HPOS? =

Yes. The plugin works with WooCommerce High-Performance Order Storage (custom order tables) and with legacy post-based order storage.

== Screenshots ==

1. **Settings** — Configure credentials, mode, sync interval, and debug logging under WooCommerce > Settings > Kunaki.
2. **Shipping Zone** — Add Kunaki Shipping as a method in any WooCommerce shipping zone for live rates at checkout.
3. **Product Linking** — Enter the 10-character Kunaki Product ID on any product's General tab.

== Changelog ==

= 1.4.0 =
* Feat: Predictive delivery estimates at checkout, based on past manufacturing times and transit days.
* Feat: Customers with discs going to a country Kunaki doesn't ship to now see "Sorry, discs can't be shipped to <country>" instead of WooCommerce's generic no-shipping message. The plugin no longer calls Kunaki for these countries.
* Feat: During a Kunaki outage, carts with discs say shipping rates are temporarily unavailable.
* Feat: Support for the WooCommerce cart and checkout blocks.
* Feat: Variations can have their own Kunaki Product ID, or use the parent's.
* Feat: Orders declined by Kunaki can be resubmitted with an explicit retry. This creates a new Kunaki order, so it never happens automatically.
* Change: Deleting the plugin now keeps order history and product links. A new "Remove Data on Uninstall" setting deletes them.
* Change: The saved password is never sent to the browser. The field is always blank, and leaving it blank keeps the saved password.
* Change: Order submission runs in the background through Action Scheduler, so checkout no longer waits on Kunaki.
* Fix: Order queries returned the wrong orders on legacy (non-HPOS) order storage, which skewed dashboard counts and retries.
* Fix: Shipping rates for Turkey and Vatican City.
* Fix: The circuit breaker could stay tripped on stores with no checkout traffic.
* Fix: Shipped and declined orders no longer crowd newer orders out of status sync.
* Fix: Addresses sent to Kunaki use English country names whatever the site language.
* Fix: The order page warns when an order is cancelled, refunded, or has its address changed while still pending at Kunaki.
* Tested up to WordPress 7.1 and WooCommerce 11.2.

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

= 1.4.0 =
Deleting the plugin now keeps order history and product links unless you turn on "Remove Data on Uninstall". Adds block checkout support, delivery estimates, and a clear message for countries Kunaki doesn't ship to.

= 1.3.0 =
Adds mixed cart support — orders with both Kunaki and non-Kunaki products now get separate shipping packages at checkout.
