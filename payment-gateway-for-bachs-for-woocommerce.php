<?php
/**
 * Plugin Name: Payment Gateway for Bachs for WooCommerce
 * Plugin URI: https://ibrahim.ng/payment-gateway-for-bachs-for-woocommerce
 * Description: Accept card, bank transfer, mobile money and crypto payments on your WooCommerce store with Bachs hosted checkout.
 * Version: 1.0.0
 * Author: Ibrahim Nasir
 * Author URI: https://ibrahim.ng
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires Plugins: woocommerce
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.9
 * Text Domain: payment-gateway-for-bachs-for-woocommerce
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PGBW_MAIN_FILE', __FILE__ );
define( 'PGBW_URL', untrailingslashit( plugins_url( '/', __FILE__ ) ) );
define( 'PGBW_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'PGBW_VERSION', '1.0.0' );

/**
 * Bootstrap the gateway once all plugins are loaded.
 */
function pgbw_init() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'pgbw_missing_wc_notice' );
		return;
	}

	require_once PGBW_PATH . '/includes/class-pgbw-api.php';
	require_once PGBW_PATH . '/includes/class-pgbw-helpers.php';
	require_once PGBW_PATH . '/includes/class-pgbw-gateway.php';

	add_filter( 'woocommerce_payment_gateways', 'pgbw_add_bachs_gateway', 99 );
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pgbw_plugin_action_links' );
	add_action( 'admin_notices', 'pgbw_testmode_notice' );
}
add_action( 'plugins_loaded', 'pgbw_init', 99 );

/**
 * Register the WooCommerce Blocks (checkout blocks) integration.
 */
function pgbw_blocks_support() {
	if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once PGBW_PATH . '/includes/class-pgbw-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new PGBW_Blocks_Support() );
			}
		);
	}
}
add_action( 'woocommerce_blocks_loaded', 'pgbw_blocks_support' );

/**
 * Declare compatibility with HPOS (custom order tables) and cart/checkout blocks.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PGBW_MAIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PGBW_MAIN_FILE, true );
		}
	}
);

/**
 * @param array $methods
 * @return array
 */
function pgbw_add_bachs_gateway( $methods ) {
	$methods[] = 'PGBW_Gateway';
	return $methods;
}

/**
 * @param array $links
 * @return array
 */
function pgbw_plugin_action_links( $links ) {
	$settings_link = array(
		'settings' => '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=pgbw_bachs' ) ) . '">' . esc_html__( 'Settings', 'payment-gateway-for-bachs-for-woocommerce' ) . '</a>',
	);
	return array_merge( $settings_link, $links );
}

/**
 * Notice shown when WooCommerce is not active.
 */
function pgbw_missing_wc_notice() {
	if ( wp_doing_ajax() || ! is_admin() ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
		return;
	}
	/* translators: %s: WooCommerce download link */
	echo '<div class="error"><p><strong>' . wp_kses_post( sprintf( __( 'Payment Gateway for Bachs requires WooCommerce to be installed and active. You can download %s here.', 'payment-gateway-for-bachs-for-woocommerce' ), '<a href="https://woocommerce.com/" target="_blank" rel="noopener">WooCommerce</a>' ) ) . '</strong></p></div>';
}

/**
 * Warn the admin while the gateway is still in test mode.
 */
function pgbw_testmode_notice() {
	if ( wp_doing_ajax() || ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'woocommerce_page_wc-settings', 'plugins' ), true ) ) {
		return;
	}
	$settings = get_option( 'woocommerce_pgbw_bachs_settings' );
	if ( isset( $settings['enabled'], $settings['testmode'] ) && 'yes' === $settings['enabled'] && 'yes' === $settings['testmode'] ) {
		/* translators: %s: gateway settings link */
		echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses_post( sprintf( __( 'Bachs for WooCommerce is in <strong>test mode</strong>. Disable it <a href="%s">here</a> when you are ready to accept live payments.', 'payment-gateway-for-bachs-for-woocommerce' ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=pgbw_bachs' ) ) ) ) . '</p></div>';
	}
}
