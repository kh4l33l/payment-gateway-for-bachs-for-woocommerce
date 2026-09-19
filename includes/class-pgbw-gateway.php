<?php
/**
 * Bachs WooCommerce payment gateway (hosted redirect checkout).
 *
 * @package Payment_Gateway_For_Bachs_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PGBW_Gateway extends WC_Payment_Gateway {

	/**
	 * Order-meta key for the Bachs charge ID (used to look an order up from refund webhooks).
	 */
	const CHARGE_ID_META_KEY = '_pgbw_charge_id';

	/**
	 * Order-meta key for the hosted checkout URL (used by the popup receipt page).
	 */
	const CHECKOUT_URL_META_KEY = '_pgbw_checkout_url';

	/**
	 * Order-meta key for the number of checkout sessions created for the order.
	 */
	const CHECKOUT_ATTEMPTS_META_KEY = '_pgbw_checkout_attempts';

	/**
	 * Don't reuse an open session that expires within this many seconds.
	 */
	const SESSION_REUSE_MARGIN = 300;

	/**
	 * @var bool
	 */
	public $testmode;

	/**
	 * @var bool
	 */
	public $debug;

	/**
	 * 'redirect' or 'popup'.
	 *
	 * @var string
	 */
	public $checkout_type;

	public function __construct() {

		$this->id                 = 'pgbw_bachs';
		$this->method_title       = __( 'Bachs', 'payment-gateway-for-bachs-for-woocommerce' );
		$this->method_description = __( 'Accept card, bank transfer, mobile money and crypto payments through the Bachs hosted checkout. Customers pay on Bachs (via redirect or an on-site popup) and are returned to your store once done.', 'payment-gateway-for-bachs-for-woocommerce' );
		$this->icon               = apply_filters( 'pgbw_gateway_icon_url', PGBW_URL . '/assets/images/bachs-icon.png' );
		$this->has_fields         = false;

		$this->supports = array(
			'products',
			'refunds',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title         = $this->get_option( 'title' );
		$this->description   = $this->get_option( 'description' );
		$this->enabled       = $this->get_option( 'enabled' );
		$this->testmode      = 'yes' === $this->get_option( 'testmode', 'yes' );
		$this->debug         = 'yes' === $this->get_option( 'debug', 'no' );
		$this->checkout_type = $this->get_option( 'checkout_type', 'redirect' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_' . $this->id, array( $this, 'process_webhook' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'checkout_styles' ) );
		add_action( 'woocommerce_pay_order_after_submit', array( $this, 'pay_order_cancel_link' ) );

		if ( ! $this->is_valid_for_use() ) {
			$this->enabled = 'no';
		}
	}

	/**
	 * Whether the store currency is supported by Bachs.
	 *
	 * @return bool
	 */
	public function is_valid_for_use() {
		return PGBW_Helpers::is_currency_supported( get_woocommerce_currency() );
	}

	/**
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( ! PGBW_Helpers::has_keys() ) {
			return false;
		}
		return $this->is_valid_for_use();
	}

	/**
	 * Gateway icon for the classic checkout.
	 *
	 * The core implementation outputs the image with no dimensions, so themes that
	 * don't constrain payment method icons render the 256px PNG at full size. Size it
	 * inline to match the checkout block's label icon. checkout_styles() moves it in
	 * front of the title.
	 *
	 * @return string
	 */
	public function get_icon() {
		$icon = '';

		if ( $this->icon ) {
			$icon = sprintf(
				'<img src="%1$s" alt="%2$s" height="24" style="height:24px;width:auto;max-width:100px;margin:0;" />',
				esc_url( WC_HTTPS::force_https_url( $this->icon ) ),
				esc_attr( $this->get_title() )
			);
		}

		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * The WooCommerce API (wc-api) URL Bachs delivers webhooks to.
	 *
	 * @return string
	 */
	public function get_webhook_url() {
		return WC()->api_request_url( $this->id );
	}

	public function init_form_fields() {

		$this->form_fields = array(
			'enabled'             => array(
				'title'   => __( 'Enable/Disable', 'payment-gateway-for-bachs-for-woocommerce' ),
				'label'   => __( 'Enable Bachs', 'payment-gateway-for-bachs-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'               => array(
				'title'       => __( 'Title', 'payment-gateway-for-bachs-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to the customer during checkout.', 'payment-gateway-for-bachs-for-woocommerce' ),
				'default'     => __( 'Bachs', 'payment-gateway-for-bachs-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'         => array(
				'title'       => __( 'Description', 'payment-gateway-for-bachs-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown to the customer during checkout.', 'payment-gateway-for-bachs-for-woocommerce' ),
				'default'     => __( 'Pay securely via Bachs — card, bank transfer, mobile money and crypto.', 'payment-gateway-for-bachs-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'checkout_type'       => array(
				'title'       => __( 'Checkout type', 'payment-gateway-for-bachs-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Redirect sends the customer to the Bachs hosted checkout page. Popup opens the Bachs checkout in a modal on your site (the customer stays on your store).', 'payment-gateway-for-bachs-for-woocommerce' ),
				'default'     => 'redirect',
				'desc_tip'    => true,
				'options'     => array(
					'redirect' => __( 'Redirect (hosted checkout page)', 'payment-gateway-for-bachs-for-woocommerce' ),
					'popup'    => __( 'Popup (on-site modal)', 'payment-gateway-for-bachs-for-woocommerce' ),
				),
			),
			'testmode'            => array(
				'title'       => __( 'Test mode', 'payment-gateway-for-bachs-for-woocommerce' ),
				'label'       => __( 'Enable test (sandbox) mode', 'payment-gateway-for-bachs-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Uses the Bachs sandbox environment and your sandbox keys. Uncheck to accept live payments.', 'payment-gateway-for-bachs-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'test_api_key'        => array(
				'title'       => __( 'Sandbox API Key', 'payment-gateway-for-bachs-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Your Bachs sandbox API key (starts with sk_sandbox_). Find it under Developer → API keys in your Bachs dashboard.', 'payment-gateway-for-bachs-for-woocommerce' ),
				'default'     => '',
				'class'       => 'pgbw-test-field',
			),
			'test_webhook_secret' => array(
				'title'       => __( 'Sandbox Webhook Signing Secret', 'payment-gateway-for-bachs-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'The signing secret for your sandbox webhook endpoint, from the Bachs developer portal.', 'payment-gateway-for-bachs-for-woocommerce' ),
				'default'     => '',
				'class'       => 'pgbw-test-field',
			),
			'live_api_key'        => array(
				'title'       => __( 'Live API Key', 'payment-gateway-for-bachs-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Your Bachs live API key (starts with sk_live_). Find it under Developer → API keys in your Bachs dashboard.', 'payment-gateway-for-bachs-for-woocommerce' ),
				'default'     => '',
				'class'       => 'pgbw-live-field',
			),
			'live_webhook_secret' => array(
				'title'       => __( 'Live Webhook Signing Secret', 'payment-gateway-for-bachs-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'The signing secret for your live webhook endpoint, from the Bachs developer portal.', 'payment-gateway-for-bachs-for-woocommerce' ),
				'default'     => '',
				'class'       => 'pgbw-live-field',
			),
			'webhook_url'         => array(
				'title'             => __( 'Webhook URL', 'payment-gateway-for-bachs-for-woocommerce' ),
				'type'              => 'text',
				'description'       => sprintf(
					/* translators: 1: comma-separated list of webhook event names, 2: Bachs dashboard URL */
					__( 'Add this URL as a webhook endpoint in your %2$sBachs developer portal%3$s and subscribe to these events: %1$s.', 'payment-gateway-for-bachs-for-woocommerce' ),
					'<code>' . implode( ', ', array_keys( self::webhook_events() ) ) . '</code>',
					'<a href="' . esc_url( PGBW_Helpers::dashboard_base_url() ) . '" target="_blank" rel="noopener">',
					'</a>'
				),
				'default'           => $this->get_webhook_url(),
				'custom_attributes' => array( 'readonly' => 'readonly' ),
			),
			'debug'               => array(
				'title'       => __( 'Debug log', 'payment-gateway-for-bachs-for-woocommerce' ),
				'label'       => __( 'Enable logging', 'payment-gateway-for-bachs-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => sprintf(
					/* translators: %s: log location */
					__( 'Log gateway events to %s. Turn off on production sites.', 'payment-gateway-for-bachs-for-woocommerce' ),
					'<code>WooCommerce → Status → Logs</code>'
				),
				'default'     => 'no',
			),
		);
	}

	/**
	 * Bachs webhook events this gateway handles.
	 *
	 * @return array
	 */
	public static function webhook_events() {
		return array(
			'collection.succeeded' => 'handle_collection_succeeded',
			'collection.failed'    => 'handle_collection_failed',
			'collection.abandoned' => 'handle_collection_abandoned',
			'refund.paid'          => 'handle_refund_paid',
		);
	}

	/**
	 * Create the Bachs checkout session and redirect the customer to it.
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		try {

			if ( ! PGBW_Helpers::is_currency_supported( $order->get_currency() ) ) {
				/* translators: %s: currency code */
				throw new Exception( sprintf( __( 'Bachs does not support the %s currency.', 'payment-gateway-for-bachs-for-woocommerce' ), $order->get_currency() ) );
			}

			$session = $this->get_open_session( $order );

			if ( $session ) {
				$this->log( sprintf( 'Reusing open checkout session %s for order #%d.', $session->checkout_id, $order_id ) );
				return $this->payment_redirect( $order, $session->checkout_url );
			}

			$product_id = PGBW_Helpers::get_or_create_product( $order );
			$attempts   = $this->get_checkout_attempts( $order );

			$args = apply_filters(
				'pgbw_create_checkout_session_args',
				array(
					'product_cart' => array(
						array(
							'product_id' => $product_id,
							'quantity'   => 1,
						),
					),
					'customer'     => $this->get_customer_payload( $order ),
					'success_url'  => $this->get_return_url( $order ),
					'cancel_url'   => $order->get_checkout_payment_url( false ),
					'reference'    => $this->get_checkout_reference( $order, $attempts + 1 ),
					'metadata'     => array(
						'order_id'  => (string) $order->get_id(),
						'order_key' => $order->get_order_key(),
					),
				),
				$order
			);

			$response = PGBW_API::get_client()->make_request( 'checkout-sessions', $args );

			if ( ! isset( $response->checkout_url ) ) {
				throw new Exception( sprintf( 'Unexpected response when creating Bachs checkout session: %s', wp_json_encode( $response ) ) );
			}

			if ( isset( $response->checkout_id ) ) {
				$order->update_meta_data( PGBW_Helpers::CHECKOUT_ID_META_KEY, $response->checkout_id );
			}

			$order->update_meta_data( self::CHECKOUT_URL_META_KEY, $response->checkout_url );
			$order->update_meta_data( self::CHECKOUT_ATTEMPTS_META_KEY, $attempts + 1 );

			$this->log( sprintf( 'Checkout session created for order #%d (%s).', $order_id, $this->checkout_type ) );

			return $this->payment_redirect( $order, $response->checkout_url );

		} catch ( Exception $e ) {

			$this->log( sprintf( 'process_payment error for order #%d: %s', $order_id, $e->getMessage() ), 'error' );

			wc_add_notice( __( 'Unable to start the Bachs payment. Please try again.', 'payment-gateway-for-bachs-for-woocommerce' ), 'error' );

			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Mark the order as awaiting payment and send the customer to the checkout.
	 *
	 * Popup: land on the on-site order-pay page, where bachs.js opens the checkout in a modal.
	 * Redirect: send the customer straight to the hosted checkout URL.
	 *
	 * @param WC_Order $order
	 * @param string   $checkout_url
	 * @return array
	 */
	protected function payment_redirect( $order, $checkout_url ) {

		$is_popup = 'popup' === $this->checkout_type;

		$order->update_status( 'pending', __( 'Awaiting Bachs payment.', 'payment-gateway-for-bachs-for-woocommerce' ) );
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $is_popup ? $order->get_checkout_payment_url( true ) : $checkout_url,
		);
	}

	/**
	 * The order's current Bachs checkout session, if the customer can still pay on it.
	 *
	 * Bachs rejects a second session with the same reference, so a customer who comes back to
	 * pay (from the order-pay page or the Bachs cancel URL) is sent to their open session.
	 *
	 * @param WC_Order $order
	 * @return object|null Session with checkout_id and checkout_url, or null.
	 */
	protected function get_open_session( $order ) {

		$checkout_id = $order->get_meta( PGBW_Helpers::CHECKOUT_ID_META_KEY );

		if ( empty( $checkout_id ) ) {
			return null;
		}

		try {
			$session = PGBW_API::get_client()->make_request( 'checkout-sessions/' . rawurlencode( $checkout_id ), array(), array(), 'GET' );
		} catch ( Exception $e ) {
			$this->log( sprintf( 'Could not retrieve checkout session %s for order #%d: %s', $checkout_id, $order->get_id(), $e->getMessage() ), 'warning' );
			return null;
		}

		if ( ! is_object( $session ) || ! isset( $session->status ) || 'open' !== $session->status ) {
			return null;
		}

		if ( ! empty( $session->expires_at ) ) {
			$expires = strtotime( $session->expires_at );
			if ( $expires && $expires - time() < self::SESSION_REUSE_MARGIN ) {
				return null;
			}
		}

		$checkout_url = ! empty( $session->checkout_url ) ? $session->checkout_url : $order->get_meta( self::CHECKOUT_URL_META_KEY );

		if ( empty( $checkout_url ) ) {
			return null;
		}

		return (object) array(
			'checkout_id'  => $checkout_id,
			'checkout_url' => $checkout_url,
		);
	}

	/**
	 * Number of checkout sessions already created for the order.
	 *
	 * @param WC_Order $order
	 * @return int
	 */
	protected function get_checkout_attempts( $order ) {

		$attempts = absint( $order->get_meta( self::CHECKOUT_ATTEMPTS_META_KEY ) );

		// Orders from before the counter was stored have had one session if a checkout ID is set.
		if ( ! $attempts && $order->get_meta( PGBW_Helpers::CHECKOUT_ID_META_KEY ) ) {
			$attempts = 1;
		}

		return $attempts;
	}

	/**
	 * Bachs reference for a checkout session. References must be unique, so sessions after the
	 * first add the attempt number to the order key (wc_order_abc123-2).
	 *
	 * @param WC_Order $order
	 * @param int      $attempt 1-based session number.
	 * @return string
	 */
	protected function get_checkout_reference( $order, $attempt ) {
		return $attempt > 1 ? $order->get_order_key() . '-' . $attempt : $order->get_order_key();
	}

	/**
	 * Whether a collection event belongs to a session the order has since replaced.
	 *
	 * @param WC_Order $order
	 * @param object   $data Event data.
	 * @return bool
	 */
	protected function is_stale_session_event( $order, $data ) {

		$current = $order->get_meta( PGBW_Helpers::CHECKOUT_ID_META_KEY );

		return ! empty( $data->checkout_id ) && ! empty( $current ) && $data->checkout_id !== $current;
	}

	/**
	 * Build the checkout-session customer payload, reusing a stored Bachs customer when possible.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	protected function get_customer_payload( $order ) {

		$user_id = $order->get_customer_id();

		if ( $user_id ) {
			$stored = get_user_meta( $user_id, '_pgbw_customer_id_' . PGBW_Helpers::get_mode(), true );
			if ( ! empty( $stored ) ) {
				return array( 'customer_id' => $stored );
			}
		}

		$email = $order->get_billing_email();
		$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		if ( '' === $name ) {
			$name = $email;
		}

		$payload = array(
			'email' => $email,
			'name'  => $name,
		);

		$phone = $order->get_billing_phone();
		if ( ! empty( $phone ) ) {
			$payload['phone_number'] = $phone;
		}

		return $payload;
	}

	/**
	 * Refund a Bachs payment. Bachs allows a single refund per charge.
	 *
	 * @param int    $order_id
	 * @param float  $amount
	 * @param string $reason
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'pgbw_refund_error', __( 'Order not found.', 'payment-gateway-for-bachs-for-woocommerce' ) );
		}

		$charge_id = $order->get_transaction_id();
		if ( empty( $charge_id ) ) {
			$charge_id = $order->get_meta( self::CHARGE_ID_META_KEY );
		}

		if ( empty( $charge_id ) ) {
			return new WP_Error( 'pgbw_refund_error', __( 'No Bachs charge ID is stored on this order, so it cannot be refunded through Bachs.', 'payment-gateway-for-bachs-for-woocommerce' ) );
		}

		$payload = array(
			'charge_id' => $charge_id,
			'reference' => 'wc_refund_' . $order_id . '_' . time(),
		);

		// A partial amount is sent in the charge settlement currency; a full refund omits it.
		if ( ! is_null( $amount ) && (float) $amount > 0 && (float) $amount < (float) $order->get_total() ) {
			$payload['amount'] = PGBW_Helpers::format_amount( $amount );
		}

		if ( '' !== (string) $reason ) {
			$payload['reason'] = $reason;
		}

		try {

			$response = PGBW_API::get_client()->make_request( 'refunds', $payload );

			$refund_id = isset( $response->id ) ? $response->id : '';

			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: Bachs refund ID */
					__( 'Bachs refund initiated for %1$s. Refund ID: %2$s. Refunds settle asynchronously and are confirmed by a refund.paid webhook.', 'payment-gateway-for-bachs-for-woocommerce' ),
					wc_price( is_null( $amount ) ? $order->get_total() : $amount, array( 'currency' => $order->get_currency() ) ),
					$refund_id
				)
			);

			$this->log( sprintf( 'Refund initiated for order #%d (refund %s).', $order_id, $refund_id ) );

			return true;

		} catch ( Exception $e ) {

			// Bachs rejects a second refund on the same charge; surface the message to the merchant.
			$this->log( sprintf( 'process_refund error for order #%d: %s', $order_id, $e->getMessage() ), 'error' );

			return new WP_Error( 'pgbw_refund_error', $e->getMessage() );
		}
	}

	/**
	 * Webhook entry point (wc-api). Verifies the signature then dispatches by event type.
	 */
	public function process_webhook() {

		$raw_body  = file_get_contents( 'php://input' );
		$timestamp = isset( $_SERVER['HTTP_X_BACHS_TIMESTAMP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_BACHS_TIMESTAMP'] ) ) : '';
		$signature = isset( $_SERVER['HTTP_X_BACHS_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_BACHS_SIGNATURE'] ) ) : '';

		if ( empty( $raw_body ) || ! PGBW_Helpers::verify_webhook_signature( $raw_body, $timestamp, $signature, PGBW_Helpers::get_webhook_secret() ) ) {
			status_header( 400 );
			exit;
		}

		$payload = json_decode( $raw_body );

		if ( ! is_object( $payload ) || empty( $payload->type ) ) {
			status_header( 400 );
			exit;
		}

		$events = self::webhook_events();

		if ( isset( $events[ $payload->type ] ) && isset( $payload->data ) ) {
			$handler = $events[ $payload->type ];
			$this->{$handler}( $payload->data );
			do_action( 'pgbw_webhook_event', $payload->type, $payload );
		}

		status_header( 200 );
		exit;
	}

	/**
	 * @param object $data
	 */
	protected function handle_collection_succeeded( $data ) {

		$order = $this->resolve_order( $data );

		if ( ! $order || $order->is_paid() || $order->has_status( array( 'processing', 'completed', 'refunded' ) ) ) {
			return;
		}

		$charge_id = isset( $data->charge_id ) ? $data->charge_id : '';

		if ( ! empty( $charge_id ) ) {
			$order->update_meta_data( self::CHARGE_ID_META_KEY, $charge_id );
		}

		$this->maybe_store_customer_id( $order, $data );

		$order->payment_complete( $charge_id );

		$order->add_order_note(
			sprintf(
				/* translators: %s: Bachs charge ID */
				__( 'Bachs payment completed. Charge ID: %s', 'payment-gateway-for-bachs-for-woocommerce' ),
				! empty( $charge_id ) ? $charge_id : __( 'n/a', 'payment-gateway-for-bachs-for-woocommerce' )
			)
		);

		$order->save();

		$this->log( sprintf( 'collection.succeeded completed order #%d.', $order->get_id() ) );
	}

	/**
	 * @param object $data
	 */
	protected function handle_collection_failed( $data ) {

		$order = $this->resolve_order( $data );

		if ( ! $order || $order->is_paid() || $this->is_stale_session_event( $order, $data ) ) {
			return;
		}

		$reason = isset( $data->reason ) ? $data->reason : __( 'Payment failed at Bachs.', 'payment-gateway-for-bachs-for-woocommerce' );

		/* translators: %s: failure reason */
		$order->update_status( 'failed', sprintf( __( 'Bachs payment failed: %s', 'payment-gateway-for-bachs-for-woocommerce' ), $reason ) );
	}

	/**
	 * @param object $data
	 */
	protected function handle_collection_abandoned( $data ) {

		$order = $this->resolve_order( $data );

		if ( ! $order || $order->is_paid() || ! $order->has_status( array( 'pending', 'on-hold' ) ) || $this->is_stale_session_event( $order, $data ) ) {
			return;
		}

		$reason = isset( $data->reason ) ? $data->reason : __( 'Checkout abandoned or expired.', 'payment-gateway-for-bachs-for-woocommerce' );

		/* translators: %s: abandonment reason */
		$order->update_status( 'cancelled', sprintf( __( 'Bachs checkout abandoned: %s', 'payment-gateway-for-bachs-for-woocommerce' ), $reason ) );
	}

	/**
	 * @param object $data
	 */
	protected function handle_refund_paid( $data ) {

		$charge_id = isset( $data->charge_id ) ? $data->charge_id : '';

		if ( empty( $charge_id ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'          => 1,
				'transaction_id' => $charge_id,
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		$order           = $orders[0];
		$refunded_amount = isset( $data->refunded_amount ) ? $data->refunded_amount : '';

		$order->add_order_note(
			sprintf(
				/* translators: %s: refunded amount */
				__( 'Bachs refund settled. Amount refunded: %s', 'payment-gateway-for-bachs-for-woocommerce' ),
				'' !== $refunded_amount ? $refunded_amount : __( 'n/a', 'payment-gateway-for-bachs-for-woocommerce' )
			)
		);
	}

	/**
	 * Resolve the WooCommerce order behind a collection.* event.
	 *
	 * @param object $data Event data.
	 * @return WC_Order|null
	 */
	protected function resolve_order( $data ) {

		$order = false;

		if ( isset( $data->metadata->order_id ) ) {
			$order = wc_get_order( absint( $data->metadata->order_id ) );
		}

		if ( ! $order && ! empty( $data->reference ) ) {
			$order_id = wc_get_order_id_by_order_key( preg_replace( '/-\d+$/', '', $data->reference ) );
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
			}
		}

		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return null;
		}

		return $order;
	}

	/**
	 * Persist the Bachs customer ID against the WooCommerce user for reuse.
	 *
	 * @param WC_Order $order
	 * @param object   $data
	 */
	protected function maybe_store_customer_id( $order, $data ) {
		$user_id           = $order->get_customer_id();
		$bachs_customer_id = isset( $data->customer->id ) ? $data->customer->id : '';

		if ( $user_id && ! empty( $bachs_customer_id ) ) {
			update_user_meta( $user_id, '_pgbw_customer_id_' . PGBW_Helpers::get_mode(), $bachs_customer_id );
		}
	}

	/**
	 * Admin notice when the gateway is enabled but not yet configured.
	 */
	public function admin_notices() {
		if ( wp_doing_ajax() || ! is_admin() || 'yes' !== $this->enabled ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-settings', 'plugins' ), true ) ) {
			return;
		}
		if ( ! PGBW_Helpers::has_keys() ) {
			/* translators: %s: settings URL */
			echo '<div class="error"><p>' . wp_kses_post( sprintf( __( 'Bachs is enabled but no API key is set. Add your keys <a href="%s">here</a>.', 'payment-gateway-for-bachs-for-woocommerce' ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=pgbw_bachs' ) ) ) ) . '</p></div>';
		}
	}

	/**
	 * Order-pay ("receipt") page for the popup flow: renders the opener markup that bachs.js
	 * uses to open the hosted checkout in a modal.
	 *
	 * @param int $order_id
	 */
	public function receipt_page( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( ! $order || 'popup' !== $this->checkout_type ) {
			return;
		}

		$checkout_url = $order->get_meta( self::CHECKOUT_URL_META_KEY );

		echo '<div id="pgbw-bachs-popup">';

		if ( empty( $checkout_url ) ) {
			echo '<p>' . esc_html__( 'We could not load the Bachs checkout. Please try again.', 'payment-gateway-for-bachs-for-woocommerce' ) . '</p>';
		} else {
			echo '<div class="pgbw-spinner" aria-hidden="true"></div>';
			echo '<p>' . esc_html__( 'Your secure Bachs checkout is opening. If it does not appear, use the button below.', 'payment-gateway-for-bachs-for-woocommerce' ) . '</p>';
			echo '<p id="pgbw-bachs-status" role="status" aria-live="polite"></p>';
			echo '<p><button type="button" id="pgbw-bachs-pay" class="button alt">' . esc_html__( 'Pay with Bachs', 'payment-gateway-for-bachs-for-woocommerce' ) . '</button></p>';
		}

		echo '<p><a class="pgbw-cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . esc_html__( 'Cancel order &amp; restore cart', 'payment-gateway-for-bachs-for-woocommerce' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Add the cancel link to the order-pay form, which WooCommerce doesn't show there.
	 *
	 * Customers land on this form when they cancel on the Bachs checkout or come back to pay
	 * later, so without it they can't cancel the order and restore their cart.
	 */
	public function pay_order_cancel_link() {

		global $wp;

		$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || $order->get_payment_method() !== $this->id || ! $order->has_status( apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending', 'failed' ), $order ) ) ) {
			return;
		}

		echo '<p class="pgbw-cancel-order"><a class="pgbw-cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . esc_html__( 'Cancel order &amp; restore cart', 'payment-gateway-for-bachs-for-woocommerce' ) . '</a></p>';
	}

	/**
	 * Show the gateway icon before the title on the classic checkout.
	 *
	 * The core payment-method.php template prints the title and then the icon, so
	 * reorder them with flexbox rather than altering the gateway title itself.
	 */
	public function checkout_styles() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_register_style( 'pgbw-bachs-checkout', false, array(), PGBW_VERSION );
		wp_enqueue_style( 'pgbw-bachs-checkout' );
		wp_add_inline_style(
			'pgbw-bachs-checkout',
			'.wc_payment_method.payment_method_' . $this->id . ' > label{display:inline-flex;align-items:center;gap:0.5em;}'
			. '.wc_payment_method.payment_method_' . $this->id . ' > label > img{order:-1;}'
		);
	}

	/**
	 * Enqueue bachs.js and the popup opener on the order-pay page for a popup-mode Bachs order.
	 */
	public function payment_scripts() {

		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}

		global $wp;
		$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;

		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_payment_method() !== $this->id || 'popup' !== $this->checkout_type ) {
			return;
		}

		$checkout_url = $order->get_meta( self::CHECKOUT_URL_META_KEY );

		if ( empty( $checkout_url ) ) {
			return;
		}

		wp_enqueue_style( 'pgbw-bachs-popup', PGBW_URL . '/assets/css/pgbw-bachs-popup.css', array(), PGBW_VERSION );
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Bachs SDK; its version is controlled by Bachs, not this plugin.
		wp_enqueue_script( 'pgbw-bachs-js', 'https://checkout.bachs.io/bachs.js', array(), null, true );
		wp_enqueue_script( 'pgbw-bachs-popup', PGBW_URL . '/assets/js/pgbw-bachs-popup.js', array( 'pgbw-bachs-js' ), PGBW_VERSION, true );

		wp_localize_script(
			'pgbw-bachs-popup',
			'pgbw_popup',
			array(
				'checkout_url' => $checkout_url,
				'return_url'   => $this->get_return_url( $order ),
				'cancel_url'   => $order->get_cancel_order_url(),
				'retry_url'    => $order->get_checkout_payment_url( false ),
				'debug'        => $this->debug ? '1' : '0',
				'i18n'         => array(
					'closed'  => __( 'Checkout closed. Click "Pay with Bachs" to try again.', 'payment-gateway-for-bachs-for-woocommerce' ),
					'failed'  => __( 'The payment failed. Click "Pay with Bachs" to try again.', 'payment-gateway-for-bachs-for-woocommerce' ),
					'expired' => __( 'This checkout session expired. Taking you back to start a new one…', 'payment-gateway-for-bachs-for-woocommerce' ),
					'blocked' => __( 'The Bachs checkout could not load. Please disable any ad/script blocker for this page and try again.', 'payment-gateway-for-bachs-for-woocommerce' ),
					'error'   => __( 'Something went wrong opening the checkout. Please try again, or cancel to start over.', 'payment-gateway-for-bachs-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Enqueue the admin toggle script on the gateway settings screen only.
	 *
	 * @param string $hook
	 */
	public function admin_scripts( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		$section = filter_input( INPUT_GET, 'section', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( $this->id !== $section ) {
			return;
		}
		wp_enqueue_script( 'pgbw-bachs-admin', PGBW_URL . '/assets/js/pgbw-bachs-admin.js', array( 'jquery' ), PGBW_VERSION, true );
	}

	/**
	 * @param string $message
	 * @param string $level info|error|debug
	 */
	public function log( $message, $level = 'info' ) {
		if ( ! $this->debug && ! in_array( $level, array( 'error', 'critical' ), true ) ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'pgbw_bachs' ) );
		}
	}
}
