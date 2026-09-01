/**
 * FAFH admin shim: draws Font Awesome icons in wp-admin without the webfont.
 *
 * The webfont used to ship purely so icon PICKERS could preview a glyph. That
 * was 315 KB in every plugin carrying the library, for a handful of icons on
 * one screen. This replaces it: anything in wp-admin that renders an
 * `<i class="fas fa-star">` gets the matching inline SVG swapped in.
 *
 * It deliberately converts markup it did not write. HivePress core builds
 * picker options through a select2 template that hardcodes
 * `<i class="fas fa-fw fa-{id}">` (hivepress/assets/js/common.js:233), and
 * several plugins build their own previews the same way. Converting at the
 * element level means none of them has to change, including the ones not yet
 * migrated to FAFH.
 *
 * Glyphs are fetched in batches as they are needed, never all at once: the
 * whole library is 1.4 MB, which would have been a worse trade than the font
 * it replaces. A dropdown showing thirty results asks for thirty paths.
 */
( function() {
	'use strict';

	var config = window.fafhAdmin;

	if ( ! config || ! config.ajaxUrl ) {
		return;
	}

	// Canonical or aliased name => "viewBox|path". Also holds false for a name
	// the server said it does not have, so it is never asked for twice.
	var cache = {};

	// Names waiting to go out in the next batch, and the elements waiting on them.
	var pending = {};
	var waiting = [];
	var scheduled = null;

	var SVG_NS = 'http://www.w3.org/2000/svg';

	// Class tokens that are Font Awesome plumbing rather than an icon name.
	var NOT_A_NAME = {
		'fa-fw': 1,
		'fa-solid': 1,
		'fa-regular': 1,
		'fa-brands': 1,
		'fa-lg': 1,
		'fa-xs': 1,
		'fa-sm': 1,
		'fa-2x': 1,
		'fa-3x': 1,
		'fa-spin': 1,
		'fa-pulse': 1,
		'fa-border': 1,
		'fa-fixed-width': 1,
		'fa-inverse': 1,
		'fa-stack': 1,
		'fa-ul': 1,
		'fa-li': 1,
		'fa-rotate-90': 1,
		'fa-rotate-180': 1,
		'fa-rotate-270': 1,
		'fa-flip-horizontal': 1,
		'fa-flip-vertical': 1
	};

	/**
	 * Reads the icon name out of an element's classes.
	 *
	 * Returns null when there is no name, which is the common case: `fas` on its
	 * own, or a sizing helper with no icon.
	 */
	function nameOf( element ) {
		var tokens = String( element.className || '' ).split( /\s+/ );

		for ( var i = 0; i < tokens.length; i++ ) {
			var token = tokens[ i ];

			if ( 0 === token.indexOf( 'fa-' ) && ! NOT_A_NAME[ token ] ) {
				return token.slice( 3 );
			}
		}

		return null;
	}

	/**
	 * Builds an <svg> from a "viewBox|path" pair.
	 *
	 * Nodes are constructed, never parsed: no markup crosses into the page, so
	 * innerHTML is not involved anywhere in this file.
	 */
	function build( pair ) {
		var parts = String( pair ).split( '|' );

		if ( 2 !== parts.length || ! parts[ 0 ] || ! parts[ 1 ] ) {
			return null;
		}

		var svg = document.createElementNS( SVG_NS, 'svg' );

		svg.setAttribute( 'viewBox', parts[ 0 ] );
		svg.setAttribute( 'class', 'fafh-icon__svg' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );

		var path = document.createElementNS( SVG_NS, 'path' );

		path.setAttribute( 'vector-effect', 'non-scaling-stroke' );
		path.setAttribute( 'd', parts[ 1 ] );
		svg.appendChild( path );

		return svg;
	}

	/**
	 * Puts the glyph inside an element, replacing anything already there.
	 */
	function draw( element, pair ) {
		var svg = build( pair );

		if ( ! svg ) {
			return;
		}

		while ( element.firstChild ) {
			element.removeChild( element.firstChild );
		}

		element.classList.add( 'fafh-icon' );
		element.appendChild( svg );
		element.setAttribute( 'data-fafh', 'drawn' );
	}

	/**
	 * Sends everything queued up as one request.
	 */
	function flush() {
		scheduled = null;

		var names = Object.keys( pending );

		if ( ! names.length ) {
			return;
		}

		pending = {};

		var batch = waiting;
		waiting = [];

		var body = new window.FormData();

		body.append( 'action', 'fafh_icons' );
		body.append( 'nonce', config.nonce );

		names.forEach( function( name ) {
			body.append( 'names[]', name );
		} );

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function( response ) {
			return response.json();
		} ).then( function( result ) {
			var icons = ( result && result.data && result.data.icons ) || {};

			// Record misses too, so a name the library does not have is asked
			// for once rather than on every redraw of the dropdown.
			names.forEach( function( name ) {
				cache[ name ] = Object.prototype.hasOwnProperty.call( icons, name ) ? icons[ name ] : false;
			} );

			// The server answers under the CANONICAL name, so an alias like
			// map-marker-alt has to be pointed at location-dot's pair.
			Object.keys( icons ).forEach( function( canonical ) {
				cache[ canonical ] = icons[ canonical ];
			} );

			batch.forEach( function( item ) {
				var pair = cache[ item.name ];

				if ( pair ) {
					draw( item.element, pair );
				}
			} );
		} ).catch( function() {
			// A failed fetch leaves the elements as they were. Nothing is retried
			// automatically: a picker that silently hammered admin-ajax on every
			// keystroke would be worse than a missing preview.
			batch.forEach( function( item ) {
				delete cache[ item.name ];
			} );
		} );
	}

	/**
	 * Converts one element, fetching its glyph if this is the first sighting.
	 */
	function convert( element ) {
		if ( 'drawn' === element.getAttribute( 'data-fafh' ) ) {
			return;
		}

		var name = nameOf( element );

		if ( ! name ) {
			return;
		}

		if ( Object.prototype.hasOwnProperty.call( cache, name ) ) {
			if ( cache[ name ] ) {
				draw( element, cache[ name ] );
			}

			return;
		}

		pending[ name ] = 1;
		waiting.push( { name: name, element: element } );

		if ( ! scheduled ) {
			// One tick of coalescing, so a dropdown rendering thirty results
			// makes one request rather than thirty.
			scheduled = window.setTimeout( flush, 16 );
		}
	}

	/**
	 * Converts every icon inside a subtree, including the root itself.
	 */
	function scan( root ) {
		if ( ! root || 1 !== root.nodeType ) {
			return;
		}

		if ( 'I' === root.tagName ) {
			convert( root );
		}

		var found = root.querySelectorAll( 'i[class*="fa-"]' );

		for ( var i = 0; i < found.length; i++ ) {
			convert( found[ i ] );
		}
	}

	function start() {
		scan( document.body );

		// select2 builds its dropdown fresh on every open and rerenders it on
		// every keystroke, so there is nothing to hook -- the elements simply
		// appear. Watching the document is the only reliable way to catch them,
		// and the work per mutation is a tagName test.
		if ( ! window.MutationObserver ) {
			return;
		}

		new window.MutationObserver( function( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				var added = mutations[ i ].addedNodes;

				for ( var j = 0; j < added.length; j++ ) {
					scan( added[ j ] );
				}
			}
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
