/**
 * Notifications for HivePress - header bell icon picker.
 *
 * Upgrades the plain "Bell Icon" select on the settings screen into a dropdown that shows a preview
 * of each icon. The native select is kept in the DOM and stays the thing that submits, so if this
 * script does not run, or cannot find the field, the select works on its own.
 */
( function() {
	'use strict';

	var icons = window.hpBellIcons || {};

	/**
	 * Builds the inline SVG markup for an icon definition.
	 *
	 * @param {Object} def Icon definition with a view box and path.
	 * @return {string}
	 */
	function svg( def ) {
		if ( ! def || ! def.view || ! def.path ) {
			return '';
		}

		return '<svg viewBox="' + def.view + '" width="18" height="18" aria-hidden="true" focusable="false">' +
			'<path fill="currentColor" d="' + def.path + '"></path></svg>';
	}

	/**
	 * Enhances a single bell icon select.
	 *
	 * @param {HTMLSelectElement} select The native select.
	 */
	function enhance( select ) {
		if ( select.dataset.hpPicker ) {
			return;
		}

		select.dataset.hpPicker = '1';

		// Read the choices from the select itself, so the picker always matches what will be saved.
		var options = Array.prototype.map.call( select.options, function( option ) {
			return {
				value: option.value,
				label: option.textContent,
				def: icons[ option.value ]
			};
		} );

		if ( ! options.length ) {
			return;
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'hp-bell-picker';

		var toggle = document.createElement( 'button' );
		toggle.type = 'button';
		toggle.className = 'hp-bell-picker__toggle';
		toggle.setAttribute( 'aria-haspopup', 'listbox' );
		toggle.setAttribute( 'aria-expanded', 'false' );

		var list = document.createElement( 'div' );
		list.className = 'hp-bell-picker__list';
		list.setAttribute( 'role', 'listbox' );
		list.hidden = true;

		function currentOption() {
			var match = options.filter( function( option ) {
				return option.value === select.value;
			} );

			return match.length ? match[0] : options[0];
		}

		function renderToggle() {
			var option = currentOption();

			toggle.innerHTML = '<span class="hp-bell-picker__icon"></span>' +
				'<span class="hp-bell-picker__text"></span>' +
				'<span class="hp-bell-picker__caret" aria-hidden="true"></span>';
			toggle.querySelector( '.hp-bell-picker__icon' ).innerHTML = svg( option && option.def );
			toggle.querySelector( '.hp-bell-picker__text' ).textContent = option ? option.label : '';

			Array.prototype.forEach.call( list.children, function( item ) {
				item.setAttribute( 'aria-selected', item.dataset.value === select.value ? 'true' : 'false' );
			} );
		}

		function choose( value ) {
			if ( select.value !== value ) {
				select.value = value;

				// Let HivePress and anything else listening know the value changed.
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}

			renderToggle();
		}

		function open() {
			list.hidden = false;
			toggle.setAttribute( 'aria-expanded', 'true' );

			var selected = list.querySelector( '[aria-selected="true"]' );

			if ( selected ) {
				selected.focus();
			}
		}

		function close() {
			list.hidden = true;
			toggle.setAttribute( 'aria-expanded', 'false' );
		}

		options.forEach( function( option ) {
			var item = document.createElement( 'button' );
			item.type = 'button';
			item.className = 'hp-bell-picker__option';
			item.setAttribute( 'role', 'option' );
			item.dataset.value = option.value;

			item.innerHTML = '<span class="hp-bell-picker__icon"></span><span class="hp-bell-picker__text"></span>';
			item.querySelector( '.hp-bell-picker__icon' ).innerHTML = svg( option.def );
			item.querySelector( '.hp-bell-picker__text' ).textContent = option.label;

			item.addEventListener( 'click', function() {
				choose( option.value );
				close();
				toggle.focus();
			} );

			list.appendChild( item );
		} );

		toggle.addEventListener( 'click', function() {
			if ( list.hidden ) {
				open();
			} else {
				close();
			}
		} );

		document.addEventListener( 'click', function( event ) {
			if ( ! wrap.contains( event.target ) ) {
				close();
			}
		} );

		list.addEventListener( 'keydown', function( event ) {
			var items = Array.prototype.slice.call( list.children );
			var index = items.indexOf( document.activeElement );

			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				( items[ index + 1 ] || items[0] ).focus();
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				( items[ index - 1 ] || items[ items.length - 1 ] ).focus();
			} else if ( 'Escape' === event.key ) {
				close();
				toggle.focus();
			}
		} );

		// Keep the trigger in step if the value is changed elsewhere.
		select.addEventListener( 'change', renderToggle );

		// Hide the native select and any enhanced control a theme or HivePress placed next to it.
		select.style.display = 'none';

		var sibling = select.nextElementSibling;

		if ( sibling && sibling.classList && sibling.classList.contains( 'select2-container' ) ) {
			sibling.style.display = 'none';
		}

		wrap.appendChild( toggle );
		wrap.appendChild( list );
		select.parentNode.insertBefore( wrap, select.nextSibling );

		renderToggle();
	}

	/**
	 * Finds and enhances the bell icon select.
	 */
	function init() {
		var select = document.querySelector( 'select[name*="notification_bell_icon"]' );

		if ( select ) {
			enhance( select );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function() {

			// Give HivePress a tick to set up its own field scripts first.
			window.setTimeout( init, 0 );
		} );
	} else {
		window.setTimeout( init, 0 );
	}
}() );
