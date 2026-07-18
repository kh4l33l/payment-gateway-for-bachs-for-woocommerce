<?php
/**
 * Thin wrapper around the Bachs REST API.
 *
 * @package Payment_Gateway_For_Bachs_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PGBW_API {

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * @var string
	 */
	private $api_url;

	/**
	 * Response code from the last request.
	 *
	 * @var int
	 */
	public $last_response_code = 0;

	/**
	 * @param string $api_key Bachs API key.
	 * @param string $api_url Environment base URL (no version suffix).
	 *
	 * @throws Exception When no key is configured.
	 */
	public function __construct( $api_key, $api_url ) {
		if ( empty( $api_key ) ) {
			throw new Exception( 'Bachs API key is not configured.' );
		}

		$this->api_key = $api_key;
		$this->api_url = rtrim( $api_url, '/' ) . '/v1/';
	}

	/**
	 * Make a request to the Bachs API.
	 *
	 * @param string $endpoint Endpoint relative to the /v1/ base (no leading slash).
	 * @param array  $body     Request body, or query string for GET.
	 * @param array  $headers  Extra headers.
	 * @param string $method   HTTP method.
	 *
	 * @return mixed Decoded JSON response.
	 *
	 * @throws Exception On transport errors or a >= 400 response.
	 */
	public function make_request( $endpoint, $body = array(), $headers = array(), $method = 'POST' ) {

		$headers = wp_parse_args(
			$headers,
			array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Authorization' => sprintf( 'Bearer %s', $this->api_key ),
			)
		);

		$request_args = array(
			'method'     => $method,
			'timeout'    => 30,
			'headers'    => $headers,
			'user-agent' => 'PaymentGatewayForBachsForWooCommerce/' . PGBW_VERSION . '; ' . home_url(),
		);

		$url = ( 'https://' === substr( $endpoint, 0, 8 ) ) ? $endpoint : $this->api_url . ltrim( $endpoint, '/' );

		if ( ! empty( $body ) ) {
			if ( 'GET' === $method ) {
				$url = add_query_arg( array_map( 'rawurlencode', $body ), $url );
			} else {
				$request_args['body'] = wp_json_encode( $body );
			}
		}

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( esc_html( $response->get_error_message() ) );
		}

		$this->last_response_code = (int) wp_remote_retrieve_response_code( $response );

		$decoded = json_decode( wp_remote_retrieve_body( $response ) );

		if ( $this->last_response_code >= 400 ) {
			$message = isset( $decoded->detail ) ? $decoded->detail : wp_remote_retrieve_body( $response );
			throw new Exception( sprintf( 'Bachs API error (%d): %s', (int) $this->last_response_code, esc_html( $message ) ) );
		}

		return $decoded;
	}

	/**
	 * Shared client built from the active gateway credentials.
	 *
	 * @return PGBW_API
	 *
	 * @throws Exception When no key is configured.
	 */
	public static function get_client() {
		static $instance = null;

		if ( is_null( $instance ) ) {
			$instance = new self( PGBW_Helpers::get_api_key(), PGBW_Helpers::api_base_url() );
		}

		return $instance;
	}
}
