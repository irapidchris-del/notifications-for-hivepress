/**
 * Notifications for HivePress - admin colour pickers.
 *
 * HivePress's Color field is a plain input and core ships no picker of its own, so this adds the
 * WordPress one (Iris): a palette, a saturation map and a box that takes a hex code directly.
 * Without this script the fields still work and save as ordinary colour inputs.
 */
( function() {
	'use strict';

	if ( ! window.jQuery ) {
		return;
	}

	/**
	 * Expands a three digit hex to six.
	 *
	 * The field carries pattern="#[0-9a-fA-F]{6}", which lies dormant while the input is
	 * type="color" and arms itself the moment this script converts it to text. HivePress enforces
	 * the same pattern server-side, where a rejected value returns the normal "saved" redirect and
	 * silently keeps the old colour - indistinguishable from the change not taking. Since
	 * sanitize_hex_color() accepts #fff but the pattern does not, shorthand is expanded here
	 * rather than left to fail.
	 *
	 * @param {string} value Colour value.
	 * @return {string}
	 */
	function expandHex( value ) {
		var match = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/i.exec( ( value || '' ).trim() );

		if ( ! match ) {
			return value;
		}

		return '#' + match[1] + match[1] + match[2] + match[2] + match[3] + match[3];
	}

	window.jQuery( function( $ ) {
		if ( ! $.fn.wpColorPicker ) {
			return;
		}

		$( 'input[name^="hp_notification"][name*="color"], input[name^="hp_notification"][name*="background"]' ).each( function() {
			var input = this;

			// Whether the admin has actually chosen a colour. A native colour input reports
			// "#000000" when it is empty, so the server-rendered attribute is the only honest
			// source: it is absent or empty until someone picks something.
			var wasEmpty = '' === ( input.getAttribute( 'value' ) || '' );

			// Iris works on text inputs; a native colour input has to be converted first, keeping
			// its current value.
			if ( 'color' === input.type ) {
				try {
					input.type = 'text';

					if ( wasEmpty ) {
						input.value = '';
					}
				} catch ( e ) {
					return;
				}
			}

			// Constraint validation runs before any submit handler, so the expansion cannot wait
			// for submit: it happens as the value settles, and on Enter before the form is sent.
			$( input ).on( 'change blur', function() {
				var expanded = expandHex( input.value );

				if ( expanded !== input.value ) {
					input.value = expanded;
				}
			} ).on( 'keydown', function( event ) {
				if ( 13 === event.which ) {
					input.value = expandHex( input.value );
				}
			} );

			$( input ).wpColorPicker();

			// Iris seeds an empty field with its own starting colour, black. Saving the settings
			// page without touching the field would then store #000000 - so a bell background
			// nobody asked for turned solid black simply by pressing Save. Blank it again after
			// the picker has initialised, and keep it blank until the admin actually picks.
			//
			// "Actually picks" is detected on irischange, because that is the only signal Iris
			// gives: it writes every palette, square and strip pick into the input with jQuery
			// .val(), which fires no DOM event at all, so an input/change listener misses the
			// picker's primary interaction entirely. That exact miss shipped in another plugin as
			// a guard like this one wiping palette-picked colours on save. (This block previously
			// also bound "irisAftershow", which does not exist - Iris fires nothing by that name -
			// and survived only because opening the picker takes a click on the swatch button.)
			if ( wasEmpty ) {
				input.value = '';

				var chosen = false;

				$( input ).on( 'irischange', function() {
					chosen = true;
				} );

				$( input ).closest( '.wp-picker-container' ).on( 'click', '.wp-color-result, .iris-picker, .wp-picker-clear', function() {
					chosen = true;
				} );

				$( input ).closest( 'form' ).on( 'submit', function() {
					if ( ! chosen && '#000000' === input.value.toLowerCase() ) {
						input.value = '';
					}
				} );
			}
		} );
	} );
}() );
