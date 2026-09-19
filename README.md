# Payment Gateway for Bachs for WooCommerce

Accept card, bank transfer, mobile money and crypto payments on your WooCommerce store through the [Bachs](https://bachs.io) hosted checkout.

- **WordPress.org:** https://wordpress.org/plugins/payment-gateway-for-bachs-for-woocommerce/
- **Documentation:** https://ibrahim.ng/payment-gateway-for-bachs-for-woocommerce
- **Bachs API docs:** https://docs.bachs.io

## Features

- **Redirect or popup checkout** — send customers to the Bachs hosted page, or open it in an on-site modal via `bachs.js`. No card data touches your server either way.
- **Multiple payment methods** — cards, bank transfer, mobile money, and crypto stablecoins (USDT, USDC).
- **Sell in USD or NGN** — with Bachs Adaptive Pricing, customers can pay in their local currency (GHS, KES, UGX, TZS, XAF, XOF, ZMW, RWF and more). Bachs settles in USD or NGN.
- **Webhook-verified fulfilment** — orders complete on the signed `collection.succeeded` event, never on the browser redirect.
- **Failed, expired and short payments**: failed payments mark the order failed, short payments put it on hold, and customers whose checkout expired can pay again or cancel.
- **Refunds** from the WooCommerce order screen, and **sandbox mode**.
- **WooCommerce Blocks** support and **HPOS** compatibility.

## Requirements

- WordPress 6.2+, WooCommerce 8.0+, PHP 7.4+
- Store currency set to USD or NGN
- A [Bachs account](https://bachs.io) and API keys

## How it works

1. `process_payment()` sends the customer back to the order's Bachs checkout session if it's still open. Otherwise it creates a Bachs product for the order total and a new checkout session, storing the `checkout_id` and checkout URL on the order.
2. The customer pays on the Bachs checkout — by redirect, or in a modal opened by `bachs.js` on the order-pay page.
3. Bachs delivers a signed webhook to the WooCommerce API endpoint (`?wc-api=pgbw_bachs`). The signature is verified (HMAC-SHA256 over `{timestamp}.{body}`, from `X-Bachs-Signature-V2` or `X-Bachs-Signature`) before `collection.succeeded` completes the order. The plugin also handles `collection.failed`, `collection.underpaid`, `checkout.expired`, `refund.paid` and `refund.failed`.

## Project layout

```
includes/class-pgbw-gateway.php         WC_Payment_Gateway: settings, process_payment,
                                        process_refund, webhook dispatch
includes/class-pgbw-api.php             Bachs REST API client
includes/class-pgbw-helpers.php         Mode/keys, currencies, signature verify, products
includes/class-pgbw-blocks-support.php  WooCommerce Blocks integration
assets/js/pgbw-bachs-popup.js           Opens the Bachs overlay checkout
assets/js/pgbw-bachs-admin.js           Toggles sandbox/live key fields
.wordpress-org/                         wp.org banners, icons, screenshots (not shipped)
```

## Building

```bash
./build.sh         # plain install zip for local/InstaWP testing
./build-wporg.sh   # validated zip (readme validation + Plugin Check) for WordPress.org
```

Both write to `~/Downloads/bachs-test/` and name the file from the plugin header version.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
