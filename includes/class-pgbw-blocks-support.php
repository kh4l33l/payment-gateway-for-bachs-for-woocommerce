<?php
/**
 * WooCommerce Blocks (checkout blocks) integration for the Bachs gateway.
 *
 * @package Payment_Gateway_For_Bachs_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class PGBW_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * @var string
	 */
	protected $name = 'pgbw_bachs';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_pgbw_bachs_settings', array() );
	}

	/**
	 * @return bool
	 */
	public function is_active() {
		$gateways = WC()->payment_gateways ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['pgbw_bachs'] ) && $gateways['pgbw_bachs']->is_available();
	}

	/**
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {

		$asset_path = PGBW_PATH . '/assets/js/blocks/pgbw-bachs-blocks.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
				'version'      => PGBW_VERSION,
			);

		wp_register_script(
			'pgbw-bachs-blocks',
			PGBW_URL . '/assets/js/blocks/pgbw-bachs-blocks.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'pgbw-bachs-blocks', 'payment-gateway-for-bachs-for-woocommerce', PGBW_PATH . '/languages' );
		}

		return array( 'pgbw-bachs-blocks' );
	}

	/**
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateways = WC()->payment_gateways ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = isset( $gateways['pgbw_bachs'] ) ? $gateways['pgbw_bachs'] : null;

		return array(
			'title'       => $gateway ? $gateway->get_title() : $this->get_setting( 'title', __( 'Bachs', 'payment-gateway-for-bachs-for-woocommerce' ) ),
			'description' => $gateway ? $gateway->get_description() : $this->get_setting( 'description' ),
			'supports'    => $gateway ? array_filter( $gateway->supports, array( $gateway, 'supports' ) ) : array( 'products', 'refunds' ),
			'icon'        => PGBW_URL . '/assets/images/bachs-icon.png',
		);
	}
}
