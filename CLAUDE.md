# Payment Gateway for Bachs for WooCommerce

A WooCommerce payment gateway for the [Bachs](https://bachs.io) hosted checkout. Customers pay by card, bank transfer, mobile money or crypto on Bachs, either after a redirect or in an on-site modal. The plugin is published on WordPress.org as `payment-gateway-for-bachs-for-woocommerce`. The source is on GitHub at `kh4l33l/payment-gateway-for-bachs-for-woocommerce`.

- Bachs API docs: https://docs.bachs.io (machine-readable index: https://docs.bachs.io/llms.txt)
- Bachs API changelog: https://docs.bachs.io/changelog/api. Check it before changing API calls or webhook handling, because Bachs renames and removes things.
- Prefix: `pgbw_` for functions, hooks and option keys, `PGBW_` for classes and constants.
- Text domain: `payment-gateway-for-bachs-for-woocommerce`

## Requirements and compatibility

| | Minimum | Tested up to |
| --- | --- | --- |
| WordPress | 6.2 | 7.0 (current release is 7.1.1) |
| WooCommerce | 8.0 | 10.9 (current release is 11.1.1) |
| PHP | 7.4 | |

The plugin declares compatibility with HPOS (`custom_order_tables`) and the Cart and Checkout blocks (`cart_checkout_blocks`). Order data is always read and written through `WC_Order` methods, never post meta, so it works with HPOS on or off.

Store currency must be USD or NGN (filterable with `pgbw_supported_currencies`). Bachs Adaptive Pricing lets the customer pay in other currencies on the Bachs side.

## Layout

```
payment-gateway-for-bachs-for-woocommerce.php  Bootstrap: constants, gateway registration, Blocks
                                               registration, HPOS/Blocks compatibility, admin notices
includes/class-pgbw-gateway.php                PGBW_Gateway (WC_Payment_Gateway): settings, icon,
                                               process_payment, session reuse, refunds, webhooks,
                                               order-pay receipt page, cancel link, script/style enqueues
includes/class-pgbw-api.php                    PGBW_API: thin wp_remote_request client for /v1/
includes/class-pgbw-helpers.php                PGBW_Helpers: settings, mode and keys, currencies,
                                               amount formatting, webhook signature, per-order products
includes/class-pgbw-blocks-support.php         PGBW_Blocks_Support: Checkout block integration
assets/js/blocks/pgbw-bachs-blocks.js          Checkout block payment method (no build step, plain JS)
assets/js/pgbw-bachs-popup.js                  Opens the Bachs overlay with bachs.js on the order-pay page
assets/js/pgbw-bachs-admin.js                  Shows sandbox or live key fields in the settings screen
assets/css/pgbw-bachs-popup.css                Order-pay page styles for popup mode
languages/*.pot                                Translation template
.wordpress-org/                                WordPress.org banners, icons, screenshots (SVN assets, not shipped)
build.sh, build-wporg.sh                       Release zips (see Building and releasing)
```

The gateway ID is `pgbw_bachs`, and its settings live in the `woocommerce_pgbw_bachs_settings` option.

## Payment flow

1. **`process_payment()`**
   - If the order already has a Bachs checkout session that is `open` and not within 5 minutes (`SESSION_REUSE_MARGIN`) of expiring, the customer is sent back to it.
   - Otherwise the plugin gets or creates a fixed-price Bachs product for the order total (`PGBW_Helpers::get_or_create_product()`), then creates a checkout session (`POST /v1/checkout-sessions`) with:
     - `reference`: the order key, with `-2`, `-3` and so on added on later attempts
     - `metadata`: `order_id` and `order_key`
     - `success_url`: the order-received page
     - `cancel_url`: the order-pay form
   - The session ID, checkout URL and attempt count are saved on the order, and the order is set to pending.
2. **Where the customer goes**
   - **Redirect mode:** straight to the session's `checkout_url`.
   - **Popup mode:** to the order-pay receipt page (`get_checkout_payment_url( true )`). There, `receipt_page()` renders the opener markup and `payment_scripts()` loads `https://checkout.bachs.io/bachs.js` and `pgbw-bachs-popup.js`, which call `Bachs.Checkout.open()`.
3. **Fulfilment only happens on the webhook.** Bachs posts to `WC()->api_request_url( 'pgbw_bachs' )`, which is `/wc-api/pgbw_bachs/`. `process_webhook()` verifies the signature, then dispatches on `type` through `webhook_events()`. Browser events and redirects only drive the UI.
4. **Popup events:**
   - `checkout.completed`: go to the order-received page.
   - `checkout.expired`: go to the order-pay form, where placing the order creates a new session.
   - `checkout.closed` and `checkout.failed`: show a message and let the customer reopen the modal.

### Webhooks handled

| Event | Handler | Effect |
| --- | --- | --- |
| `collection.succeeded` | `handle_collection_succeeded()` | `payment_complete( $charge_id )`, stores the charge ID and the Bachs customer ID |
| `collection.failed` | `handle_collection_failed()` | Order set to failed (ignored for replaced sessions) |
| `collection.abandoned` | `handle_collection_abandoned()` | Order cancelled. **Bachs removed this event on 2026-07-21.** See the backlog. |
| `refund.paid` | `handle_refund_paid()` | Order note; order found by transaction ID (the charge ID) |

`resolve_order()` finds the order from `metadata.order_id`, falling back to `reference`, with any `-N` suffix removed, looked up as an order key. It returns null for orders that don't use this gateway.

Signature: `X-Bachs-Timestamp` plus `X-Bachs-Signature`, an HMAC-SHA256 hex digest of `"{timestamp}.{raw_body}"`, with a 300-second timestamp tolerance (`MAX_TIMESTAMP_DRIFT`). Always verify against the raw body.

### Refunds

`process_refund()` posts to `/v1/refunds` with `charge_id` and a unique reference (`wc_refund_{order_id}_{time}`). It omits `amount` for a full refund. Bachs allows one refund per charge, including partial refunds, and can't refund NGN bank transfers. Refunds settle asynchronously and are confirmed by `refund.*` webhooks.

### Order meta

| Key | Holds |
| --- | --- |
| `_pgbw_checkout_id` | Current Bachs checkout session ID (`PGBW_Helpers::CHECKOUT_ID_META_KEY`) |
| `_pgbw_checkout_url` | Current session's checkout URL. Stored in both modes because `GET /v1/checkout-sessions/{id}` doesn't return it. |
| `_pgbw_checkout_attempts` | Number of sessions created for the order |
| `_pgbw_charge_id` | Bachs charge ID (also saved as the order transaction ID) |
| `_pgbw_product_id_{sandbox\|live}` | The per-order Bachs product |

User meta `_pgbw_customer_id_{sandbox|live}` stores the Bachs customer ID so returning customers are sent as `customer_id`.

### Extension points

- Filters:
  - `pgbw_supported_currencies`
  - `pgbw_gateway_icon_url` (classic checkout only; the Checkout block uses a fixed icon)
  - `pgbw_create_checkout_session_args`
  - `pgbw_create_product_args`
- Action: `pgbw_webhook_event( $type, $payload )` runs after a handled event.
- Core filters respected: `woocommerce_gateway_icon`, `woocommerce_valid_order_statuses_for_cancel`

## Decisions

- **Unique session references.** Bachs requires `reference` to be unique per organisation. The first session uses the bare order key, so existing orders and `resolve_order()` keep working. Later sessions add `-N`. Using the bare key for every session caused "Duplicate reference" errors when a customer tried to pay again (order #41 on wiggledoo.com, 2026-09-19).
- **Reuse the open session before creating a new one.** This avoids a second live session for the same order, which could lead to a double charge. It also keeps a customer with a payment in progress on the same checkout. The 5-minute margin stops the plugin sending customers to a session that is about to expire.
- **Ignore failed and abandoned events from replaced sessions.** `is_stale_session_event()` compares the event's `checkout_id` with the order's current session. `collection.succeeded` is never ignored, so money collected on an old session still completes the order.
- **Cancel link on the order-pay form.** WooCommerce's `form-pay.php` template has no cancel link, and that form is where customers land after cancelling on Bachs or coming back later. `pay_order_cancel_link()` adds one on `woocommerce_pay_order_after_submit` for pending and failed Bachs orders.
- **Gateway icon in the classic checkout.** `WC_Payment_Gateway::get_icon()` outputs the image with no size, and some themes don't size gateway icons, so the 256px PNG showed at full size. `get_icon()` sets a 24px height inline to match the Checkout block. Core's `payment-method.php` template prints the title before the icon, so `checkout_styles()` adds a small inline stylesheet that uses flexbox `order` to put the icon first. This keeps the gateway title free of HTML, because the title also appears in orders, emails and the WordPress Admin. The Checkout block renders the icon first in JS.
- **Fixed-price product per order.** When the plugin was written, checkout sessions needed product IDs. A fixed price locks the amount on the Bachs checkout. Bachs now accepts a raw `pricing` object (see the backlog).
- **Popup `baseUrl`.** `pgbw-bachs-popup.js` takes the `bachs.js` `baseUrl` from the checkout URL's origin so sandbox sessions pass the SDK's origin check. The current docs say this isn't needed when a full `checkoutUrl` is passed. It's harmless, so it's kept.
- **Errors are escaped in exceptions** to satisfy Plugin Check (`ExceptionNotEscaped`). As a side effect, log lines show entities such as `&#039;`.
- **Logging.** `log()` always writes `error` and `critical` levels, and writes everything else only when the Debug log setting is on. The log source is `pgbw_bachs`.

## Conventions

- **Coding standards:** Follow the WordPress Coding Standards (tabs, Yoda conditions, spaces inside parentheses). Match the comment density of the surrounding code.
- **Escaping and input:** Escape all output. Use `wp_unslash()` and sanitize every superglobal. Add a `phpcs:ignore` only with a reason.
- **Translations:** Wrap user-facing strings for translation and add translator comments for placeholders.
- **Dependencies:** There's no Composer or npm build. The Checkout block JS is hand-written, and its dependencies are listed in `pgbw-bachs-blocks.asset.php`.
- **Security:** Never trust client-side payment events. Fulfilment comes only from verified webhooks.
- **Money:** Bachs amounts are decimal strings in the currency's major unit (`PGBW_Helpers::format_amount()`), never minor units.

## Building and releasing

- `./build.sh` writes a plain install zip to `~/Downloads/bachs-test/`.
- `./build-wporg.sh` runs Pressship, which validates the readme and runs Plugin Check, then writes the WordPress.org zip.
- The dev-only files that `build.sh`, `.distignore` and `.pressshipignore` exclude must stay in sync. That includes this file.
- To release:
  1. Bump the version in the plugin header, `PGBW_VERSION` and `Stable tag` in `readme.txt`.
  2. Add changelog and upgrade notice entries.
  3. Update `Tested up to` and `WC tested up to`.
  4. Regenerate the `.pot` file.
  5. Run `./build-wporg.sh`.
  6. Commit to WordPress.org SVN.
- Test against the Bachs sandbox (`https://sandbox-api.bachs.io`, `sk_sandbox_` keys) before live.

## Backlog

This list comes from a review on 2026-09-19 against the Bachs API changelog, WordPress 7.1.1 and WooCommerce 11.1.1. Work through it before the next WordPress.org release.

### Fix before release

1. **Replace `collection.abandoned` with `checkout.expired`.** Bachs removed `collection.abandoned` on 2026-07-21, so expired checkouts currently do nothing. Also update `webhook_events()`, the settings text and the readme FAQ. Suggested behaviour: add an order note and leave the order pending so the customer can still retry, and let WooCommerce's Hold stock setting cancel unpaid orders. The trade-off is that stores without stock management keep abandoned orders pending. Ignore events from replaced sessions (`is_stale_session_event()`).
2. **Handle `refund.failed`.** Today WooCommerce records the refund as done even when Bachs fails to deliver it, and the merchant isn't told. At a minimum, add an order note saying the refund failed and can be retried.
3. **Fix the refund ID in order notes.** `process_refund()` reads `$response->id`, but Bachs returns `refund_id`, so the note always shows a blank ID.

### Should fix

4. **Handle `collection.underpaid`.** Put the order on hold with `amount_paid`, `amount_expected` and `amount_remaining` in the note. Today a short bank transfer leaves the order pending.
5. **Send an `Idempotency-Key` header** on POST requests (checkout sessions, products, refunds). Use the session reference for checkout sessions. Without it, a timeout after Bachs has created the session leads to "Duplicate reference" on the next attempt. Keys are cached for 24 hours on 2xx responses. Reusing a key with a different body returns `409 IDEMPOTENCY_CONFLICT`. For refunds, Bachs also accepts an `idempotency_key` body field.
6. **Verify `X-Bachs-Signature-V2`** (`t=...,v1=...,v1=...`), accepting any matching `v1`. Fall back to the V1 header. This keeps webhooks working during a signing-secret rotation.
7. **Handle `Bachs.Checkout.open()` rejection** in `pgbw-bachs-popup.js`, for example a bad token or wrong origin. Today the customer sees the spinner and no message.

### Release housekeeping

- Bump to 1.1.0, because the webhook changes alter which events merchants need to subscribe to. Write changelog and upgrade notice entries covering the icon fixes, retrying payment, the cancel link and the new events.
- Change `Tested up to` to 7.1 and `WC tested up to` to 11.1.
- Update the event list in the settings screen, the readme FAQ, the readme feature list and `README.md` to: `collection.succeeded`, `collection.failed`, `collection.underpaid`, `checkout.expired`, `refund.paid`, `refund.failed`.
- Regenerate `languages/payment-gateway-for-bachs-for-woocommerce.pot`. It dates from 2026-07-16 and is missing the new strings.
- Run `./build-wporg.sh` and fix anything Plugin Check reports.

### Later

- Consider replacing per-order Bachs products with a raw `pricing` object on the checkout session. This saves one or two API calls per payment and stops the Bachs catalog filling with one product per order. Check first in the sandbox how the hosted page looks without a product name.
- Make the Webhook URL setting display-only. It's currently saved as an option, so it goes out of date if the site URL changes.
- Log Bachs `error_code` and the `x-request-id` response header in `PGBW_API::make_request()`.
- Make the Checkout block icon respect `pgbw_gateway_icon_url`.
