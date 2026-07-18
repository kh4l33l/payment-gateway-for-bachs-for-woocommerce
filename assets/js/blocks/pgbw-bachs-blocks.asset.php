<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
return array(
	'dependencies' => array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
	'version'      => defined( 'PGBW_VERSION' ) ? PGBW_VERSION : '1.0.0',
);
