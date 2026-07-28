/**
 * Notifications for HivePress - admin colour pickers.
 *
 * Upgrades the plugin's colour fields on the settings screen to the WordPress colour picker
 * (Iris), which offers a palette, a saturation map and a text box that takes a hex code such as
 * #000000 directly. Without this script the fields still work as plain browser colour inputs.
 */
( function() {
	'use strict';

	if ( ! window.jQuery ) {
		return;
	}

	window.jQuery( function( $ ) {
		if ( ! $.fn.wpColorPicker ) {
			return;
		}

		$( 'input[name^="hp_notification"][name*="color"]' ).each( function() {
			var input = this;

			// Iris works on text inputs; a native colour input has to be converted first, keeping
			// its current value.
			if ( 'color' === input.type ) {
				try {
					input.type = 'text';
				} catch ( e ) {
					return;
				}
			}

			$( input ).wpColorPicker();
		} );
	} );
}() );
