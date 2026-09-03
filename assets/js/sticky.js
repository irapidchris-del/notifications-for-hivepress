/**
 * Sticky header.
 *
 * Its own file, loaded for EVERYONE when the Sticky Header setting is on, and that is the point of
 * the split. It used to live in frontend.js, which only signed-in users receive (the notification
 * data in that script is per user and the stylesheet and script are dropped for visitors in
 * alter_assets()), so the header stuck for a signed-in owner testing the site and never for a
 * visitor. Chris found it "not working in an incognito browser" on 3 September 2026. Nothing here
 * needs a user: it reads the theme's bar, a handful of settings and the admin bar height.
 *
 * @package HivePress
 */

( function() {
	'use strict';

	var config = window.hpNotificationsSticky && window.hpNotificationsSticky.config;

	if ( ! config ) {
		return;
	}

	/**
	 * Makes the theme header stick, without a flash.
	 *
	 * The swap to fixed happens at the exact scroll position where the fixed and static
	 * placements coincide, so there is nothing to animate and nothing to see. A placeholder
	 * keeps the page height steady, and the observer costs nothing while idle.
	 */
	function initSticky() {
		if ( ! config.sticky || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		var bar = document.querySelector( '.header-navbar' );

		if ( ! bar ) {
			return;
		}

		var holder = document.createElement( 'div' );
		holder.style.display = 'none';

		var sentinel = document.createElement( 'div' );
		sentinel.style.cssText = 'position:absolute;width:1px;height:1px;pointer-events:none;';

		bar.parentNode.insertBefore( sentinel, bar );
		bar.parentNode.insertBefore( holder, bar );

		var fixed    = false;
		var observer = null;

		/**
		 * Works out what colour the bar needs while it is fixed.
		 *
		 * Fixing the bar lifts it out of its ancestors' paint order, so the page scrolls underneath
		 * whatever part of it is transparent. Themes split this two ways: some paint .header-navbar
		 * itself, others leave it clear and paint .site-header behind it.
		 *
		 * A bar that already has its own opaque colour needs nothing, so it gets nothing - this
		 * used to paint white unconditionally, which turned JobHive's white navigation text
		 * invisible against a newly white bar. Only a see-through bar borrows a colour, and it
		 * borrows the one the theme was already showing behind it rather than a guess.
		 *
		 * Read once, before the class is added, or it would read back our own value.
		 *
		 * @return {string} A colour to apply, or an empty string to leave the bar alone.
		 */
		function background() {
			var node = bar;

			function opaque( color ) {
				if ( ! color || 'transparent' === color ) {
					return false;
				}

				// rgba() with a zero alpha is transparent too, and it is what themes actually emit.
				var parts = color.match( /^rgba?\(([^)]+)\)$/ );

				return ! parts || 0 !== parseFloat( parts[1].split( ',' )[3] || '1' );
			}

			if ( opaque( window.getComputedStyle( node ).backgroundColor ) ) {
				return '';
			}

			while ( node && node !== document.documentElement ) {
				var color = window.getComputedStyle( node ).backgroundColor;

				if ( opaque( color ) ) {
					return color;
				}

				node = node.parentNode;
			}

			// Nothing opaque anywhere above it. White is the last resort rather than the default.
			return '#ffffff';
		}

		var barBackground = background();

		/**
		 * Works out the translucent colour the glass effect tints the header with.
		 *
		 * Built from the colour the header is actually showing, not from a value of our own, so a
		 * dark theme gets a dark pane and a light one a light pane. The solid colour is still set
		 * alongside it: the stylesheet only reaches for this behind an @supports test, so a browser
		 * without backdrop-filter keeps the opaque header rather than a see-through one it cannot
		 * blur.
		 *
		 * @return {string} An rgba() colour, or an empty string to leave the header solid.
		 */
		function glass() {
			if ( ! config.stickyGlass ) {
				return '';
			}

			/*
			 * Somebody who has asked their device for less transparency gets no glass at all, and
			 * the check has to be here rather than in the stylesheet. The CSS media query could
			 * only switch the BLUR off; the 72%-opaque tint is applied by a rule carrying
			 * !important, so it survived, and the visitors who asked for less transparency were
			 * left as the only ones reading navigation text over raw page content scrolling
			 * behind it, while everybody else got the blur that makes it legible. Returning ''
			 * here means the glass class is never added, the opaque colour set just above stands,
			 * and the header is simply solid - which is what the setting promises.
			 */
			if ( window.matchMedia && window.matchMedia( '(prefers-reduced-transparency: reduce)' ).matches ) {
				return '';
			}

			var source = barBackground || window.getComputedStyle( bar ).backgroundColor;
			var parts  = ( source || '' ).match( /^rgba?\(([^)]+)\)$/ );
			var alpha  = Math.max( 10, Math.min( 100, parseInt( config.glassOpacity, 10 ) || 72 ) ) / 100;

			if ( parts ) {
				var values = parts[1].split( ',' );

				return 'rgba(' + parseInt( values[0], 10 ) + ',' + parseInt( values[1], 10 ) + ',' + parseInt( values[2], 10 ) + ',' + alpha + ')';
			}

			var hex = ( source || '' ).match( /^#([0-9a-f]{6})$/i );

			if ( hex ) {
				var n = parseInt( hex[1], 16 );

				return 'rgba(' + ( ( n >> 16 ) & 255 ) + ',' + ( ( n >> 8 ) & 255 ) + ',' + ( n & 255 ) + ',' + alpha + ')';
			}

			// A colour we cannot read is left alone rather than guessed at.
			return '';
		}

		var glassTint = glass();

		function offset() {
			var admin = document.getElementById( 'wpadminbar' );

			return admin && 'fixed' === window.getComputedStyle( admin ).position ? admin.offsetHeight : 0;
		}

		/**
		 * Keeps anchor links clear of the sticky header.
		 *
		 * A fixed header covers the top of the page, so following a link to "#reviews" leaves the
		 * thing you were sent to hidden underneath it. That includes our own notifications: a
		 * review notification links to "#review-123", which is exactly the case this breaks.
		 *
		 * scroll-padding-top is the browser's own answer - it offsets every scroll-into-view,
		 * including the jump the browser makes for a fragment in the address bar, so it fixes links
		 * arriving from anywhere without intercepting clicks or fighting the browser for the scroll
		 * position. It is set on the scrolling element, and only while the header is actually
		 * sticky, so a site with this switched off behaves exactly as before.
		 *
		 * @param {boolean} on Whether the header is currently fixed.
		 */
		function padScroll( on ) {
			var root = document.documentElement;

			if ( ! on ) {
				root.style.scrollPaddingTop = '';

				return;
			}

			// A little breathing room under the header, so the target is not flush against it.
			root.style.scrollPaddingTop = ( bar.offsetHeight + offset() + 12 ) + 'px';
		}

		function apply( on ) {
			if ( on === fixed ) {
				return;
			}

			fixed = on;

			if ( on ) {
				holder.style.height  = bar.offsetHeight + 'px';
				holder.style.display = 'block';
				bar.style.top        = offset() + 'px';

				if ( barBackground ) {
					bar.style.backgroundColor = barBackground;

					// Published as a variable as well, so the reduced-transparency rules in the
					// stylesheet have an opaque colour to fall back to. The glass rule carries
					// !important, so the inline colour above cannot outrank it on its own.
					bar.style.setProperty( '--hp-nfh-opaque-background', barBackground );
				}

				if ( glassTint ) {
					bar.style.setProperty( '--hp-nfh-glass-background', glassTint );
					bar.style.setProperty( '--hp-nfh-glass-blur', config.glassBlur + 'px' );
					bar.classList.add( 'hp-nfh-sticky--glass' );
				}

				bar.classList.add( 'hp-nfh-sticky' );
			} else {
				bar.classList.remove( 'hp-nfh-sticky' );
				bar.classList.remove( 'hp-nfh-sticky--glass' );
				bar.style.removeProperty( '--hp-nfh-glass-background' );
				bar.style.removeProperty( '--hp-nfh-glass-blur' );
				bar.style.removeProperty( '--hp-nfh-opaque-background' );
				bar.style.top             = '';
				bar.style.backgroundColor = '';
				holder.style.display      = 'none';
			}
		}

		function observe() {
			if ( observer ) {
				observer.disconnect();
			}

			observer = new window.IntersectionObserver( function( entries ) {
				apply( ! entries[0].isIntersecting );
			}, { rootMargin: '-' + offset() + 'px 0px 0px 0px' } );

			observer.observe( sentinel );
		}

		observe();

		// Set once, up front, rather than only while the header is stuck. Landing on a link with a
		// fragment scrolls the page immediately, which is what makes the header stick in the first
		// place - so waiting for it to stick would be too late for the jump that needed it.
		padScroll( true );

		window.addEventListener( 'resize', function() {
			padScroll( true );

			if ( fixed ) {
				holder.style.height = bar.offsetHeight + 'px';
				bar.style.top       = offset() + 'px';
			}

			observe();
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initSticky );
	} else {
		initSticky();
	}
}() );
