# Payment Gateway for Bachs for WooCommerce

A WooCommerce payment gateway for the [Bachs](https://bachs.io) hosted checkout. Customers pay by card, bank transfer, mobile money or crypto on Bachs, either after a redirect or in an on-site modal. The plugin is published on WordPress.org as `payment-gateway-for-bachs-for-woocommerce`. The source is on GitHub at `kh4l33l/payment-gateway-for-bachs-for-woocommerce`.

- Bachs API docs: https://docs.bachs.io (machine-readable index: https://docs.bachs.io/llms.txt)
- Bachs API changelog: https://docs.bachs.io/changelog/api. Check it before changing API calls or webhook handling, because Bachs renames and removes things.
- Prefix: `pgbw_` for functions, hooks and option keys, `PGBW_` for classes and constants.
- Text domain: `payment-gateway-for-bachs-for-woocommerce`

## Requirements and compatibility

| | Minimum | Tested up to |
| --- | --- | --- |
| WordPress | 6.2 | 7.1 |
| WooCommerce | 8.0 | 11.1 |
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
tests/run.php                                  Stand-alone logic checks (see Testing, not shipped)
```

The gateway ID is `pgbw_bachs`, and its settings live in the `woocommerce_pgbw_bachs_settings` option.

## Payment flow

1. **`process_payment()`**
   - If the order already has a Bachs checkout session that is `open` and not within 5 minutes (`SESSION_REUSE_MARGIN`) of expiring, the customer is sent back to it.
   - Otherwise the plugin gets or creates a fixed-price Bachs product for the order total (`PGBW_Helpers::get_or_create_product()`), then `create_checkout_session()` posts to `/v1/checkout-sessions` with an `Idempotency-Key` of `pgbw-checkout-{reference}` and:
     - `reference`: the order key, with `-2`, `-3` and so on added on later attempts
     - `metadata`: `order_id` and `order_key`
     - `success_url`: the order-received page
     - `cancel_url`: the order-pay form
   - If Bachs reports the reference is taken ("Duplicate reference", or `IDEMPOTENCY_CONFLICT`), the plugin retries once with the next reference. Any other error fails the payment.
   - The session ID, checkout URL and attempt count are saved on the order, and the order is set to pending.
2. **Where the customer goes**
   - **Redirect mode:** straight to the session's `checkout_url`.
   - **Popup mode:** to the order-pay receipt page (`get_checkout_payment_url( true )`). There, `receipt_page()` renders the opener markup and `payment_scripts()` loads `https://checkout.bachs.io/bachs.js` and `pgbw-bachs-popup.js`, which call `Bachs.Checkout.open()`.
3. **Fulfilment only happens on the webhook.** Bachs posts to `WC()->api_request_url( 'pgbw_bachs' )`, which is `/wc-api/pgbw_bachs/`. `process_webhook()` verifies the signature, then dispatches on `type` through `webhook_events()`. Browser events and redirects only drive the UI.
4. **Popup events:**
   - `checkout.completed`: go to the order-received page.
   - `checkout.expired`: go to the order-pay form, where placing the order creates a new session.
   - `checkout.closed` and `checkout.failed`: show a message and let the customer reopen the modal.
   - If `Bachs.Checkout.open()` rejects (bad token or wrong origin), show the error message.

### Webhooks handled

| Event | Handler | Effect |
| --- | --- | --- |
| `collection.succeeded` | `handle_collection_succeeded()` | `payment_complete( $charge_id )`, stores the charge ID and the Bachs customer ID. Works from pending, failed and on-hold. |
| `collection.failed` | `handle_collection_failed()` | Order set to failed (ignored for replaced sessions) |
| `collection.underpaid` | `handle_collection_underpaid()` | Order set to on hold with the amounts in the note; the charge ID is stored as the transaction ID so the payment can be refunded. Not ignored for replaced sessions. |
| `checkout.expired` | `handle_checkout_expired()` | Order note only, for pending or failed orders on the current session. The order stays pending. |
| `refund.paid` | `handle_refund_paid()` | Order note |
| `refund.failed` | `handle_refund_failed()` | Order note telling the merchant the customer wasn't refunded; logged as an error |

Refund events find the order by transaction ID (the charge ID) through `get_order_by_charge()`, which ignores orders from other gateways. Bachs removed `collection.abandoned` on 2026-07-21; `checkout.expired` replaces it.

`resolve_order()` finds the order from `metadata.order_id`, falling back to `reference`, with any `-N` suffix removed, looked up as an order key. It returns null for orders that don't use this gateway.

Signature: an HMAC-SHA256 hex digest of `"{timestamp}.{raw_body}"`, with a 300-second timestamp tolerance (`MAX_TIMESTAMP_DRIFT`). The plugin accepts either header:

- `X-Bachs-Signature-V2` (`t={timestamp},v1={sig}[,v1={sig}]`), checked by `verify_webhook_signature_v2()`. Any matching `v1` passes, so deliveries keep verifying while a signing secret is rotated.
- `X-Bachs-Signature` with `X-Bachs-Timestamp`, checked by `verify_webhook_signature()`.

Always verify against the raw body.

### Refunds

`process_refund()` posts to `/v1/refunds` with `charge_id` and a unique reference (`wc_refund_{order_id}_{time}`). It omits `amount` for a full refund, and the order note shows the `refund_id` from the response. There's no idempotency key on refunds: Bachs already rejects a second refund on a charge, and a key would stop a retry after a failed refund for 24 hours. Bachs allows one refund per charge, including partial refunds, and can't refund NGN bank transfers. Refunds settle asynchronously and are confirmed by `refund.*` webhooks.

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
- **Expired checkouts leave the order pending.** Before Bachs removed `collection.abandoned`, abandoned checkouts cancelled the order. Now that customers can pay again, `checkout.expired` only adds a note, and WooCommerce's Hold stock setting cancels unpaid orders. The trade-off is that stores without stock management keep abandoned orders pending until someone cancels them.
- **Short payments go on hold, even from a replaced session.** Money was received, so the merchant has to decide whether to collect the balance or refund. A later `collection.succeeded` for the same order still completes it.
- **Idempotency key only on checkout sessions.** The key is the session reference, so a request that timed out after Bachs created the session returns that session on the next identical request. If the body changed (for example new billing details), Bachs answers `IDEMPOTENCY_CONFLICT` and the plugin moves to the next reference. Products don't use a key, because a duplicate product is harmless and a cached response could return an archived product. Refunds don't use one either (see Refunds).
- **Errors are escaped in exceptions** to satisfy Plugin Check (`ExceptionNotEscaped`). As a side effect, log lines show entities such as `&#039;`. `PGBW_API` keeps the unescaped `last_error_code` and `last_error_detail` for logic, and error messages include the Bachs `error_code` and `x-request-id`.
- **Logging.** `log()` always writes `error` and `critical` levels, and writes everything else only when the Debug log setting is on. The log source is `pgbw_bachs`.

## Conventions

- **Coding standards:** Follow the WordPress Coding Standards (tabs, Yoda conditions, spaces inside parentheses). Match the comment density of the surrounding code.
- **Escaping and input:** Escape all output. Use `wp_unslash()` and sanitize every superglobal. Add a `phpcs:ignore` only with a reason.
- **Translations:** Wrap user-facing strings for translation and add translator comments for placeholders.
- **Dependencies:** There's no Composer or npm build. The Checkout block JS is hand-written, and its dependencies are listed in `pgbw-bachs-blocks.asset.php`.
- **Security:** Never trust client-side payment events. Fulfilment comes only from verified webhooks.
- **Money:** Bachs amounts are decimal strings in the currency's major unit (`PGBW_Helpers::format_amount()`), never minor units.

## Testing

`php tests/run.php` (PHP 8.0+) runs stand-alone checks for the webhook handlers, both signature headers, refund notes and checkout session creation, including the reference-conflict retry. It stubs just enough of WordPress and WooCommerce to load the plugin classes, so it needs no site. Add a check there when you change any of that logic.

wiggledoo.com has no Bachs sandbox keys, so live-site checks are read-only.

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

The 2026-09-19 review found three fixes needed before release and four should-fix items. All are done in 1.1.0: `checkout.expired`, `collection.underpaid` and `refund.failed` handling, the refund ID fix, the checkout session idempotency key, V2 signatures and the popup `open()` rejection.

### Released

1.1.0 was released to WordPress.org on 2026-09-19 (SVN r3703239, git tag `v1.1.0`) and deployed to wiggledoo.com the same day. It was tested there without a sandbox: checkout rendering, the cancel link, session reuse and both webhook signature headers. No payment has gone through a checkout session created by 1.1.0 yet, so watch the first live payment.

SVN releases are done by hand: check out the repo, sync the Pressship package into `trunk/` and `.wordpress-org/` into `assets/`, `svn cp trunk tags/{version}`, then commit. `svn` is installed with Homebrew.

### Later

- Consider replacing per-order Bachs products with a raw `pricing` object on the checkout session. This saves one or two API calls per payment and stops the Bachs catalog filling with one product per order. Check first in the sandbox how the hosted page looks without a product name.
- Make the Webhook URL setting display-only. It's currently saved as an option, so it goes out of date if the site URL changes.
- Make the Checkout block icon respect `pgbw_gateway_icon_url`.
