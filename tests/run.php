<?php
/**
 * Stand-alone checks for webhook handling, signatures, refunds and checkout session creation.
 *
 * Stubs just enough of WordPress and WooCommerce to load the plugin classes, so it runs
 * without a site: php tests/run.php (PHP 8.0+). Not shipped in the plugin zip.
 */
define( 'ABSPATH', __DIR__ );
define( 'PGBW_URL', 'https://example.test/pgbw' );
define( 'PGBW_PATH', dirname( __DIR__ ) );
define( 'PGBW_VERSION', 'test' );

$GLOBALS['options']   = array( 'woocommerce_pgbw_bachs_settings' => array( 'testmode' => 'no', 'live_api_key' => 'sk_live_x', 'live_webhook_secret' => 'whsec_test', 'enabled' => 'yes', 'debug' => 'yes', 'checkout_type' => 'popup' ) );
$GLOBALS['orders']    = array();
$GLOBALS['http_queue'] = array();
$GLOBALS['http_log']  = array();
$GLOBALS['logs']      = array();

function __( $s ) { return $s; }
function WC() { return new class { function api_request_url( $id ) { return 'https://example.test/wc-api/' . $id . '/'; } }; }
function admin_url( $p ) { return $p; }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html__( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function apply_filters( $tag, $v ) { return $v; }
function add_action() {}
function add_filter() {}
function do_action() {}
function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function get_woocommerce_currency() { return 'USD'; }
function home_url() { return 'https://example.test'; }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function add_query_arg( $a, $u ) { return $u . '?' . http_build_query( $a ); }
function is_wp_error( $x ) { return false; }
function wc_add_notice() {}
function absint( $x ) { return abs( (int) $x ); }
function get_user_meta() { return ''; }
function wc_price( $a ) { return (string) $a; }
function wc_get_logger() { return new class { function log( $l, $m ) { $GLOBALS['logs'][] = "$l: $m"; } }; }
function wp_remote_request( $url, $args ) {
	$GLOBALS['http_log'][] = array( 'url' => $url, 'args' => $args );
	return array_shift( $GLOBALS['http_queue'] );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return json_encode( $r['body'] ); }
function wp_remote_retrieve_header( $r, $h ) { return isset( $r['headers'][ $h ] ) ? $r['headers'][ $h ] : ''; }
function wc_get_order( $id ) { return isset( $GLOBALS['orders'][ $id ] ) ? $GLOBALS['orders'][ $id ] : false; }
function wc_get_order_id_by_order_key( $key ) { foreach ( $GLOBALS['orders'] as $o ) { if ( $o->get_order_key() === $key ) { return $o->get_id(); } } return 0; }
function wc_get_orders( $q ) { foreach ( $GLOBALS['orders'] as $o ) { if ( $o->get_transaction_id() === $q['transaction_id'] ) { return array( $o ); } } return array(); }

class WC_Payment_Gateway {
	public $id, $method_title, $method_description, $has_fields, $description, $settings = array(), $form_fields = array(), $supports = array(), $icon, $title, $enabled;
	function init_settings() { $this->settings = get_option( 'woocommerce_pgbw_bachs_settings' ); }
	function get_option( $k, $d = '' ) { return isset( $this->settings[ $k ] ) ? $this->settings[ $k ] : $d; }
	function get_return_url( $o ) { return 'https://example.test/received/' . $o->get_id(); }
	function get_title() { return 'Bachs'; }
}

class Fake_Order {
	public $meta = array(), $notes = array(), $status = 'pending', $txn = '', $saved = 0;
	function __construct( public $id, public $key, public $method = 'pgbw_bachs' ) {}
	function get_id() { return $this->id; }
	function get_order_key() { return $this->key; }
	function get_order_number() { return $this->id; }
	function get_payment_method() { return $this->method; }
	function get_currency() { return 'USD'; }
	function get_total() { return '15.00'; }
	function get_customer_id() { return 0; }
	function get_billing_email() { return 'a@b.test'; }
	function get_billing_first_name() { return 'A'; }
	function get_billing_last_name() { return 'B'; }
	function get_billing_phone() { return ''; }
	function get_meta( $k ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	function is_paid() { return in_array( $this->status, array( 'processing', 'completed' ), true ); }
	function has_status( $s ) { return in_array( $this->status, (array) $s, true ); }
	function update_status( $s, $note = '' ) { $this->status = $s; if ( $note ) { $this->notes[] = $note; } }
	function add_order_note( $n ) { $this->notes[] = $n; }
	function set_transaction_id( $t ) { $this->txn = $t; }
	function get_transaction_id() { return $this->txn; }
	function payment_complete( $t ) { $this->status = 'processing'; $this->txn = $t; }
	function save() { $this->saved++; }
	function get_checkout_payment_url( $on ) { return 'https://example.test/pay/' . $this->id . ( $on ? '' : '?pay_for_order=true' ); }
}

require PGBW_PATH . '/includes/class-pgbw-api.php';
require PGBW_PATH . '/includes/class-pgbw-helpers.php';
require PGBW_PATH . '/includes/class-pgbw-gateway.php';

$fails = 0;
function check( $label, $cond ) { global $fails; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fails++; } }
function call( $obj, $m, ...$a ) { $r = new ReflectionMethod( $obj, $m ); $r->setAccessible( true ); return $r->invoke( $obj, ...$a ); }

$g = new PGBW_Gateway();

// --- Signature V2 ---
$body = '{"type":"x"}';
$t    = (string) time();
$sig  = hash_hmac( 'sha256', "$t.$body", 'whsec_test' );
$bad  = hash_hmac( 'sha256', "$t.$body", 'whsec_old' );
check( 'V2 accepts a matching v1', PGBW_Helpers::verify_webhook_signature_v2( $body, "t=$t,v1=$sig", 'whsec_test' ) );
check( 'V2 accepts any v1 during rotation', PGBW_Helpers::verify_webhook_signature_v2( $body, "t=$t,v1=$bad,v1=$sig", 'whsec_test' ) );
check( 'V2 rejects wrong signatures', ! PGBW_Helpers::verify_webhook_signature_v2( $body, "t=$t,v1=$bad", 'whsec_test' ) );
$old = (string) ( time() - 1000 );
check( 'V2 rejects stale timestamps', ! PGBW_Helpers::verify_webhook_signature_v2( $body, "t=$old,v1=" . hash_hmac( 'sha256', "$old.$body", 'whsec_test' ), 'whsec_test' ) );
check( 'V2 rejects empty header', ! PGBW_Helpers::verify_webhook_signature_v2( $body, '', 'whsec_test' ) );
check( 'V1 still works', PGBW_Helpers::verify_webhook_signature( $body, $t, $sig, 'whsec_test' ) );

// --- Events map ---
$events = PGBW_Gateway::webhook_events();
check( 'collection.abandoned removed', ! isset( $events['collection.abandoned'] ) );
check( 'new events registered', isset( $events['checkout.expired'], $events['collection.underpaid'], $events['refund.failed'] ) );
foreach ( $events as $e => $h ) { check( "handler exists for $e", method_exists( $g, $h ) ); }

// --- checkout.expired ---
$o = new Fake_Order( 50, 'wc_order_abc' );
$o->meta['_pgbw_checkout_id'] = 'chk_current';
$GLOBALS['orders'][50] = $o;
call( $g, 'handle_checkout_expired', (object) array( 'checkout_id' => 'chk_current', 'metadata' => (object) array( 'order_id' => '50' ) ) );
check( 'expired: order stays pending', 'pending' === $o->status );
check( 'expired: note added', 1 === count( $o->notes ) && false !== strpos( $o->notes[0], 'chk_current' ) );
call( $g, 'handle_checkout_expired', (object) array( 'checkout_id' => 'chk_old', 'metadata' => (object) array( 'order_id' => '50' ) ) );
check( 'expired: replaced session ignored', 1 === count( $o->notes ) );
$o->status = 'processing';
call( $g, 'handle_checkout_expired', (object) array( 'checkout_id' => 'chk_current', 'reference' => 'wc_order_abc-2' ) );
check( 'expired: paid order ignored (resolved via suffixed reference)', 1 === count( $o->notes ) );

// --- collection.underpaid ---
$u = new Fake_Order( 51, 'wc_order_def' );
$u->meta['_pgbw_checkout_id'] = 'chk_new';
$GLOBALS['orders'][51] = $u;
call( $g, 'handle_collection_underpaid', (object) array( 'charge_id' => 'ch_1', 'checkout_id' => 'chk_old', 'amount_paid' => '5.00', 'amount_expected' => '15.00', 'amount_remaining' => '10.00', 'currency' => 'USD', 'metadata' => (object) array( 'order_id' => '51' ) ) );
check( 'underpaid: on hold even from an older session', 'on-hold' === $u->status );
check( 'underpaid: charge stored for refunds', 'ch_1' === $u->txn && 'ch_1' === $u->meta['_pgbw_charge_id'] );
check( 'underpaid: note has amounts', false !== strpos( $u->notes[0], 'Paid 5.00 of 15.00 USD, leaving 10.00 USD' ) );
call( $g, 'handle_collection_succeeded', (object) array( 'charge_id' => 'ch_1', 'metadata' => (object) array( 'order_id' => '51' ) ) );
check( 'underpaid then succeeded: completes', 'processing' === $u->status );

// --- refund.failed / refund.paid ---
$r = new Fake_Order( 52, 'wc_order_ghi' );
$r->txn = 'ch_9';
$r->status = 'refunded';
$GLOBALS['orders'][52] = $r;
call( $g, 'handle_refund_failed', (object) array( 'refund_id' => 'rfnd_1', 'charge_id' => 'ch_9', 'requested_amount' => '15.00', 'reason' => 'Card closed' ) );
check( 'refund.failed: note added', 1 === count( $r->notes ) && false !== strpos( $r->notes[0], 'rfnd_1' ) && false !== strpos( $r->notes[0], 'Card closed' ) );
call( $g, 'handle_refund_paid', (object) array( 'charge_id' => 'ch_9', 'refunded_amount' => '15.00' ) );
check( 'refund.paid: still works', 2 === count( $r->notes ) );
call( $g, 'handle_refund_failed', (object) array( 'charge_id' => 'ch_unknown' ) );
check( 'refund.failed: unknown charge ignored', 2 === count( $r->notes ) );
$other = new Fake_Order( 53, 'wc_order_jkl', 'bacs' );
$other->txn = 'ch_other';
$GLOBALS['orders'][53] = $other;
call( $g, 'handle_refund_failed', (object) array( 'charge_id' => 'ch_other' ) );
check( 'refund.failed: other gateways ignored', 0 === count( $other->notes ) );

// --- process_refund reads refund_id ---
$GLOBALS['http_queue'][] = array( 'code' => 201, 'body' => array( 'refund_id' => 'rfnd_abc', 'status' => 'processing' ) );
$res = $g->process_refund( 52, null, '' );
check( 'refund note shows refund_id', true === $res && false !== strpos( end( $r->notes ), 'Refund ID: rfnd_abc' ) );

// --- process_payment: idempotency key + reference conflict retry ---
$p = new Fake_Order( 60, 'wc_order_pay' );
$p->meta['_pgbw_product_id_live'] = 'prod_1';
$p->meta['_pgbw_checkout_id'] = 'chk_first';
$p->meta['_pgbw_checkout_url'] = 'https://checkout.bachs.io/c/first';
$GLOBALS['orders'][60] = $p;
$GLOBALS['http_log'] = array();
$GLOBALS['http_queue'] = array(
	array( 'code' => 200, 'body' => array( 'status' => 'expired' ) ),                           // GET existing session
	array( 'code' => 200, 'body' => array( 'status' => 'active' ) ),                            // GET product
	array( 'code' => 400, 'body' => array( 'detail' => "Duplicate reference 'wc_order_pay-2'", 'error_code' => 'BAD_REQUEST' ), 'headers' => array( 'x-request-id' => 'req-1' ) ),
	array( 'code' => 201, 'body' => array( 'checkout_id' => 'chk_third', 'checkout_url' => 'https://checkout.bachs.io/c/third' ) ),
);
$res = $g->process_payment( 60 );
$posts = array_values( array_filter( $GLOBALS['http_log'], function ( $c ) { return 'POST' === $c['args']['method']; } ) );
check( 'payment: succeeded after conflict', 'success' === $res['result'] );
check( 'payment: first try used -2 with matching idempotency key', 'pgbw-checkout-wc_order_pay-2' === $posts[0]['args']['headers']['Idempotency-Key'] && false !== strpos( $posts[0]['args']['body'], '"reference":"wc_order_pay-2"' ) );
check( 'payment: retry used -3', 'pgbw-checkout-wc_order_pay-3' === $posts[1]['args']['headers']['Idempotency-Key'] );
check( 'payment: meta updated', 'chk_third' === $p->meta['_pgbw_checkout_id'] && 3 === $p->meta['_pgbw_checkout_attempts'] );
check( 'payment: error log has code and request id', (bool) array_filter( $GLOBALS['logs'], function ( $l ) { return false !== strpos( $l, '400, BAD_REQUEST' ) && false !== strpos( $l, '[request req-1]' ); } ) );

// Non-conflict errors are not retried.
$q = new Fake_Order( 61, 'wc_order_q' );
$q->meta['_pgbw_product_id_live'] = 'prod_1';
$GLOBALS['orders'][61] = $q;
$GLOBALS['http_log'] = array();
$GLOBALS['http_queue'] = array(
	array( 'code' => 200, 'body' => array( 'status' => 'active' ) ),
	array( 'code' => 422, 'body' => array( 'detail' => 'Bad email', 'error_code' => 'VALIDATION_ERROR' ) ),
);
$res = $g->process_payment( 61 );
check( 'payment: validation error fails without retry', 'failure' === $res['result'] && 2 === count( $GLOBALS['http_log'] ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
