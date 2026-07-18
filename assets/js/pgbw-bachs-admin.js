/**
 * Bachs gateway settings: show sandbox keys in test mode and live keys otherwise.
 */
jQuery( function ( $ ) {
	'use strict';

	var $testmode = $( '#woocommerce_pgbw_bachs_testmode' );

	function toggleKeyFields() {
		var isTest = $testmode.is( ':checked' );
		$( '.pgbw-test-field' ).closest( 'tr' ).toggle( isTest );
		$( '.pgbw-live-field' ).closest( 'tr' ).toggle( ! isTest );
	}

	if ( $testmode.length ) {
		$testmode.on( 'change', toggleKeyFields );
		toggleKeyFields();
	}
} );
