<?php
/**
 * Shared helpers: credentials, environment, currencies, signature and products.
 *
 * @package Payment_Gateway_For_Bachs_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PGBW_Helpers {

	/**
	 * Maximum acceptable drift (seconds) between the Bachs signing timestamp and server clock.
	 */
	const MAX_TIMESTAMP_DRIFT = 300;

	/**
	 * Order-meta key holding the Bachs checkout ID.
	 */
	const CHECKOUT_ID_META_KEY = '_pgbw_checkout_id';

	/**
	 * @return array The gateway settings option.
	 */
	public static function get_settings() {
		return get_option( 'woocommerce_pgbw_bachs_settings', array() );
	}

	/**
	 * @param string $key
	 * @param string $default
	 * @return string
	 */
	public static function get_setting( $key, $default = '' ) {
		$settings = self::get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Active environment: 'sandbox' when test mode is on, otherwise 'live'.
	 *
	 * @return string
	 */
	public static function get_mode() {
		return 'no' === self::get_setting( 'testmode', 'yes' ) ? 'live' : 'sandbox';
	}

	/**
	 * @return bool
	 */
	public static function is_test_mode() {
		return 'sandbox' === self::get_mode();
	}

	/**
	 * @return string
	 */
	public static function get_api_key() {
		$key = self::is_test_mode() ? self::get_setting( 'test_api_key' ) : self::get_setting( 'live_api_key' );
		return trim( $key );
	}

	/**
	 * @return string
	 */
	public static function get_webhook_secret() {
		$secret = self::is_test_mode() ? self::get_setting( 'test_webhook_secret' ) : self::get_setting( 'live_webhook_secret' );
		return trim( $secret );
	}

	/**
	 * @return bool
	 */
	public static function has_keys() {
		return '' !== self::get_api_key();
	}

	/**
	 * @return string
	 */
	public static function api_base_url() {
		return self::is_test_mode() ? 'https://sandbox-api.bachs.io' : 'https://api.bachs.io';
	}

	/**
	 * The Bachs dashboard is a single app for both environments.
	 *
	 * @return string
	 */
	public static function dashboard_base_url() {
		return 'https://app.bachs.io';
	}

	/**
	 * Currencies Bachs can collect payments in.
	 *
	 * @return array
	 */
	public static function supported_currencies() {
		return apply_filters( 'pgbw_supported_currencies', array( 'USD', 'NGN' ) );
	}

	/**
	 * @param string|null $currency
	 * @return bool
	 */
	public static function is_currency_supported( $currency = null ) {
		if ( null === $currency ) {
			$currency = get_woocommerce_currency();
		}
		return in_array( $currency, self::supported_currencies(), true );
	}

	/**
	 * Bachs expects money as a decimal string in the currency's major unit, e.g. "29.00".
	 *
	 * @param mixed $amount
	 * @return string
	 */
	public static function format_amount( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * Verify a Bachs webhook signature.
	 *
	 * Bachs signs each delivery with two headers: X-Bachs-Timestamp (unix seconds) and
	 * X-Bachs-Signature (HMAC-SHA256 hex digest of "{timestamp}.{raw_body}").
	 *
	 * @param string $raw_body
	 * @param string $timestamp_header
	 * @param string $signature_header
	 * @param string $secret
	 * @return bool
	 */
	public static function verify_webhook_signature( $raw_body, $timestamp_header, $signature_header, $secret ) {

		if ( empty( $secret ) || empty( $timestamp_header ) || empty( $signature_header ) ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp_header ) > self::MAX_TIMESTAMP_DRIFT ) {
			return false;
		}

		$computed = hash_hmac( 'sha256', $timestamp_header . '.' . $raw_body, $secret );

		return hash_equals( $computed, $signature_header );
	}

	/**
	 * Get (or create + cache) a fixed-price Bachs product matching this order's total.
	 *
	 * A Bachs checkout session must reference real product IDs, so a fixed-price product
	 * carrying the exact order total is created per order. A fixed price locks the amount
	 * on the hosted page, and the product ID is cached on the order (per mode) so a retry
	 * reuses it instead of creating a duplicate.
	 *
	 * @param WC_Order $order
	 * @return string Product ID.
	 *
	 * @throws Exception On an unexpected API response.
	 */
	public static function get_or_create_product( $order ) {

		$meta_key = '_pgbw_product_id_' . self::get_mode();

		$cached = $order->get_meta( $meta_key );

		if ( ! empty( $cached ) && self::product_is_active( $cached ) ) {
			return $cached;
		}

		$product_args = apply_filters(
			'pgbw_create_product_args',
			array(
				/* translators: 1: order number, 2: site name */
				'name'  => sprintf( __( 'Order %1$s — %2$s', 'payment-gateway-for-bachs-for-woocommerce' ), $order->get_order_number(), get_bloginfo( 'name' ) ),
				'price' => array(
					'price_type' => 'fixed',
					'currency'   => $order->get_currency(),
					'amount'     => self::format_amount( $order->get_total() ),
				),
			),
			$order
		);

		$response = PGBW_API::get_client()->make_request( 'products', $product_args );

		if ( ! isset( $response->id ) ) {
			throw new Exception( sprintf( 'Unexpected response when creating Bachs product: %s', esc_html( wp_json_encode( $response ) ) ) );
		}

		$order->update_meta_data( $meta_key, $response->id );
		$order->save();

		return $response->id;
	}

	/**
	 * @param string $product_id
	 * @return bool
	 */
	protected static function product_is_active( $product_id ) {
		try {
			$response = PGBW_API::get_client()->make_request( 'products/' . rawurlencode( $product_id ), array(), array(), 'GET' );
			return isset( $response->status ) && 'active' === $response->status;
		} catch ( Exception $e ) {
			return false;
		}
	}
}
