# Disc Fulfillment for WooCommerce

A WooCommerce plugin that integrates with the [Kunaki XML API](https://kunaki.com/XMLService.htm) for automated CD/DVD manufacturing and fulfillment.

## Goal

- Send WooCommerce paid orders to Kunaki XML API
- Store OrderId
- Poll for tracking
- Mark completed

## How It Works

1. **Product Linking** — Add a Kunaki Product ID to any WooCommerce product via the General tab. Only products with a Kunaki ID are fulfilled through Kunaki.
2. **Live Shipping Rates** — At checkout, the plugin calls Kunaki's ShippingOptions API to display real-time shipping methods and prices.
3. **Auto-Submit on Payment** — When an order transitions to "processing" (payment received), it's automatically submitted to Kunaki's Order API.
4. **Status Polling** — A WP-Cron job periodically checks Kunaki for order status updates. When shipped, the tracking number is added as a customer-visible order note and the order is marked completed.

## Setup

1. Download the ZIP from GitHub and upload it via **Plugins > Add New > Upload Plugin** in WordPress
2. Activate the plugin in WordPress
3. Go to **WooCommerce > Settings > Kunaki**
4. Enter your Kunaki publisher email and password
5. Set mode to **Test** for development or **Live** for production
6. Go to **WooCommerce > Settings > Shipping > Shipping Zones** and add "Kunaki Shipping" to your zones
7. Edit any WooCommerce product and enter its 10-character Kunaki Product ID in the General tab

## Settings

| Setting | Description |
|---------|-------------|
| Kunaki Email | Your Kunaki publisher account email |
| Password | Your Kunaki account password |
| Mode | `Test` (no real orders) or `Live` (production) |
| Sync Interval | How often to poll Kunaki for status updates (default: 15 min) |
| Debug Logging | Log API requests/responses to WooCommerce > Status > Logs |

## Open Questions

### Shipping strategy?

Currently the plugin fetches live rates from Kunaki's ShippingOptions API and displays them at checkout. The customer selects a Kunaki shipping method, and that exact description is passed to the Order API. For mixed carts (Kunaki + non-Kunaki products), a separate shipping method must be configured for the non-Kunaki items.

### Multi-product bundling?

Multiple Kunaki products in a single order are submitted together in one Kunaki order request. Quantities are aggregated per Kunaki Product ID. Kunaki handles the bundling and ships them together. Variable/bundle WooCommerce products are not yet supported — the Kunaki Product ID is set on the parent product only.

### International handling?

Kunaki supports 48 countries. The plugin converts WooCommerce's ISO country codes to full country names (which Kunaki requires) using WooCommerce's built-in country list. For US/Canada orders, the two-letter state/province code is passed. For other countries, the state field is left empty per Kunaki's spec.

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
assets/css/
  kunaki-admin.css                  → Admin styles
uninstall.php                       → Cleanup on plugin deletion
```

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+

## License

Proprietary. All rights reserved. Copyright &copy; 2026 Mobile Code Media.
