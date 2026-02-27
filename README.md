# Kunaki WooCommerce

A WooCommerce plugin for automatic [Kunaki](https://kunaki.com) CD, DVD, and Blu-ray fulfillment — live shipping rates, automatic order submission, and tracking.

## Features

- **Live Shipping Rates** — Real-time Kunaki shipping options and prices at checkout.
- **Automatic Order Submission** — Orders are sent to Kunaki automatically when payment is received.
- **Order Tracking** — Polls Kunaki for status updates and emails the customer their tracking number.
- **Product Linking** — Link any WooCommerce product to a Kunaki disc via its Product ID.
- **Mixed Carts** — Works alongside other shipping methods for non-Kunaki products.

## Setup

1. Download the ZIP from GitHub and upload it via **Plugins > Add New > Upload Plugin** in WordPress
2. Activate the plugin in WordPress
3. Go to **WooCommerce > Settings > Kunaki** and enter your Kunaki publisher email and password. Set mode to **Test** for development or **Live** for production.

![Kunaki API Settings](assets/screenshots/settings.png)

4. Go to **WooCommerce > Settings > Shipping > Shipping Zones**, create a zone, and add "Kunaki Shipping" as a shipping method.

![Shipping Zone Setup](assets/screenshots/shipping.png)

5. Edit any WooCommerce product and enter its Kunaki Product ID in the General tab.

![Product Kunaki ID](assets/screenshots/simple-product.png)

## Settings

| Setting | Description |
|---------|-------------|
| Kunaki Email | Your Kunaki publisher account email |
| Password | Your Kunaki account password |
| Mode | `Test` (no real orders) or `Live` (production) |
| Sync Interval | How often to poll Kunaki for status updates (default: 15 min) |
| Debug Logging | Log API requests/responses to WooCommerce > Status > Logs |

## Plugin Structure

```
kunaki-woocommerce.php              → Plugin bootstrap, constants, cron scheduling, password encryption
includes/
  class-kunaki-woocommerce.php      → Orchestrator, hooks all components together
  class-kunaki-api-client.php       → XML API client (shipping, orders, status)
  class-kunaki-shipping-method.php  → WC_Shipping_Method with live Kunaki rates
  class-kunaki-order-fulfillment.php → Auto-submits orders on payment
  class-kunaki-order-status-sync.php → WP-Cron polling for tracking updates
  class-kunaki-product-meta.php     → Kunaki Product ID field on products
  class-kunaki-settings.php         → WooCommerce settings tab
  class-kunaki-logger.php           → Logging wrapper
  encryption.php                    → AES-256-GCM encryption helpers
assets/css/
  kunaki-admin.css                  → Admin styles
uninstall.php                       → Cleanup on plugin deletion
```

## Development

### Running Tests

```bash
composer install
composer test
```

No WordPress or WooCommerce installation required — all tests run in isolation using Brain Monkey and Mockery.

79 unit tests cover the plugin's core functionality:

| Area | What's tested |
|------|---------------|
| API Client | XML request/response parsing, HTTP errors, HTML-wrapped responses, credential injection |
| Order Fulfillment | Duplicate submission prevention, atomic locking, meta storage, mixed cart handling |
| Order Status Sync | SHIPPED/DECLINED/PENDING transitions, tracking info, auto-completion logic |
| Shipping Method | Rate calculation, Kunaki product filtering, transient caching, state/country handling |
| Product Meta | ID validation (10-char alphanumeric), capability checks, column rendering |
| Logger | Debug gating, log level routing |
| Encryption | AES-256-GCM encrypt/decrypt round-trip, AES-256-CBC legacy fallback, invalid input handling |

To run a specific test file or filter by name, pass arguments via `composer test`:

```bash
composer test -- tests/Unit/APIClientTest.php
composer test -- --filter "shipped"
```

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+

## License

Proprietary. All rights reserved. Copyright &copy; 2026 Mobile Code Media.

## Disclaimer

This software is provided "as is" without warranty of any kind, express or implied. Use at your own risk. This project is not affiliated with, endorsed by, or sponsored by Kunaki, WordPress, or WooCommerce.
