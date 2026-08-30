/**
 * Notifications for HivePress - settings screen live preview.
 *
 * Keeps the sample pop-up in the Live preview panel in step with the settings on screen, before
 * anything is saved.
 *
 * The pop-up is the real front-end markup styled by the real front-end stylesheet, so this script
 * never touches its look directly. It writes the same CSS custom properties that
 * Hpnf_Notification::get_inline_styles() prints on the front end, onto the stage wrapper instead of
 * :root, and toggles the same container classes that frontend.js sets. Preview and production
 * therefore resolve through identical CSS, and a variable renamed in one place shows up as a dead
 * control here rather than as a wrong colour on somebody's site.
 */
( function() {
	'use strict';

	if ( ! window.jQuery ) {
		return;
	}

	/**
	 * Option names of the settings this panel reflects.
	 *
	 * HivePress names every settings input after its option, so the option name is the binding.
	 *
	 * Deliberately absent, so the gaps read as decisions rather than oversights:
	 * - the bell and dropdown settings (bell_*, notification_badge_color, notification_panel_width)
	 *   change the header bell and its panel, neither of which is what this stage shows;
	 * - Button Radius shapes the buttons on the account pages, and the stage shows a pop-up,
	 *   which has no buttons of that kind on it;
	 * - Maximum Pop-ups changes how many arrive at once, and the stage shows one;
	 * - Sound and Sound Style are not visual at all.
	 */
	var OPTIONS = {
		background:     'hp_notification_toast_background_color',
		text:           'hp_notification_toast_text_color',
		accent:         'hp_notification_toast_accent_color',
		shape:          'hp_notification_icon_shape',
		radius:         'hp_notification_toast_radius',
		size:           'hp_notification_toast_text_size',
		weight:         'hp_notification_toast_text_weight',
		position:       'hp_notification_toast_position',
		positionMobile: 'hp_notification_toast_position_mobile',
		autohide:       'hp_notification_toast_autohide',
		duration:       'hp_notification_toast_duration'
	};

	// The Icon Shape choices, carried as radii. Same map as get_inline_styles(), which stores the
	// radius rather than the shape name so the stylesheet needs no knowledge of the choices.
	var SHAPES = {
		circle: '50%',
		rounded: '8px',
		square: '0px'
	};

	// The container classes frontend.js builds from the two position settings.
	var POSITIONS = [ 'top-right', 'top-left', 'bottom-right', 'bottom-left' ];
	var POSITIONS_MOBILE = [ 'top', 'center', 'bottom' ];

	window.jQuery( function( $ ) {
		var panel = document.querySelector( '.hpnf-preview' );

		if ( ! panel ) {
			return;
		}

		var stage    = panel.querySelector( '.hpnf-preview__stage' );
		var toasts   = panel.querySelector( '.hp-notification-toasts' );
		var toast    = panel.querySelector( '.hp-notification-toast' );
		var progress = panel.querySelector( '.hp-notification-toast__progress' );
		var replay   = panel.querySelector( '.hpnf-preview__replay' );

		if ( ! stage || ! toasts || ! toast || ! progress ) {
			return;
		}

		var replayTimer = null;

		/**
		 * Gets a settings input by its option name.
		 *
		 * @param {string} option Option name.
		 * @return {Element|null}
		 */
		function input( option ) {
			return document.querySelector( '[name="' + option + '"]' );
		}

		/**
		 * Reads the current value of a settings input.
		 *
		 * A row hidden by HivePress's "_parent" argument is read exactly like a visible one, on
		 * purpose. Hiding only calls .hide() on the row; the input stays in the DOM, still posts on
		 * save and still takes effect, so a preview that ignored hidden rows would show something
		 * the site will not do.
		 *
		 * @param {string} option Option name.
		 * @return {string}
		 */
		function value( option ) {
			var field = input( option );

			if ( ! field ) {
				return '';
			}

			if ( 'checkbox' === field.type ) {
				return field.checked ? '1' : '';
			}

			return ( field.value || '' ).trim();
		}

		/**
		 * Sets or clears a custom property on the stage.
		 *
		 * Clearing rather than writing a default matters: get_inline_styles() runs its whole map
		 * through array_filter(), so an empty setting emits nothing and the fallback written into
		 * frontend.css applies. Removing the property reproduces that precisely.
		 *
		 * @param {string} name Property name.
		 * @param {string} val Property value.
		 */
		function setProperty( name, val ) {
			if ( val ) {
				stage.style.setProperty( name, val );
			} else {
				stage.style.removeProperty( name );
			}
		}

		/**
		 * Accepts a colour only in the forms sanitize_hex_color() accepts.
		 *
		 * The field is a text input once the picker has converted it, so it can hold half-typed
		 * rubbish. Anything else clears the property, which is also what the server would store.
		 *
		 * @param {string} val Colour value.
		 * @return {string}
		 */
		function hex( val ) {
			return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( val ) ? val : '';
		}

		/**
		 * Mirrors absint() on a settings value.
		 *
		 * @param {string} val Number value.
		 * @return {number}
		 */
		function absint( val ) {
			var number = parseInt( val, 10 );

			return isNaN( number ) ? 0 : Math.abs( number );
		}

		/**
		 * Swaps one class out of a known set on the pop-up container.
		 *
		 * @param {Array} names Possible values.
		 * @param {string} prefix Class prefix.
		 * @param {string} current Chosen value.
		 */
		function setClass( names, prefix, current ) {
			names.forEach( function( name ) {
				toasts.classList.toggle( prefix + name, name === current );
			} );
		}

		/**
		 * Restarts the countdown bar.
		 *
		 * The bar runs a keyframe animation with fill-mode forwards, so once it has emptied it stays
		 * emptied and simply changing its duration does not bring it back: the animation has to come
		 * off the element and the element be reflowed before it goes back on.
		 */
		function restartProgress() {
			if ( progress.hidden ) {
				return;
			}

			progress.style.animationName = 'none';

			// Reading a layout property forces the reflow that separates the two writes; without it
			// the browser coalesces them and the animation never restarts.
			void progress.offsetWidth;

			progress.style.animationName = '';
		}

		/**
		 * Replays the pop-up's arrival.
		 *
		 * frontend.css animates arrival with a transition on the --visible class, not a keyframe
		 * animation, so there is no animation to restart: the honest replay is to genuinely leave the
		 * visible state, let the 0.24s transition finish, and come back. Anything shorter than that
		 * catches the pop-up mid-fade and the second entry starts from wherever it had got to.
		 *
		 * Under "prefers-reduced-motion: reduce" frontend.css switches the transition off, so this
		 * is instant. That is correct rather than broken: the site behaves the same way for the same
		 * reader.
		 */
		function replayEntry() {
			window.clearTimeout( replayTimer );

			toast.classList.remove( 'hp-notification-toast--visible' );

			replayTimer = window.setTimeout( function() {
				toast.classList.add( 'hp-notification-toast--visible' );

				restartProgress();
			}, 260 );
		}

		/**
		 * Applies every setting on screen to the sample pop-up.
		 */
		function paint() {

			// Colours. Same three properties, same order, as get_inline_styles().
			setProperty( '--hp-notification-background', hex( value( OPTIONS.background ) ) );
			setProperty( '--hp-notification-text', hex( value( OPTIONS.text ) ) );
			setProperty( '--hp-notification-accent', hex( value( OPTIONS.accent ) ) );

			// Icon shape. Circle emits nothing on the front end and leans on the 50% fallback in
			// frontend.css, so it emits nothing here either.
			var shape = value( OPTIONS.shape );

			setProperty( '--hp-notification-icon-radius', SHAPES[ shape ] && 'circle' !== shape ? SHAPES[ shape ] : '' );

			// Corner radius. An empty box means "use the standard 3px" and a typed 0 means square
			// corners, which is why the emptiness test comes before absint() rather than after: 0 and
			// "" are the same number and two different answers.
			var radius = value( OPTIONS.radius );

			setProperty( '--hp-notification-radius', '' === radius ? '' : absint( radius ) + 'px' );

			// Text size and weight.
			var size = absint( value( OPTIONS.size ) );

			setProperty( '--hp-notification-font-size', size ? size + 'px' : '' );

			var weight = absint( value( OPTIONS.weight ) );

			setProperty( '--hp-notification-font-weight', weight ? String( weight ) : '' );

			// Position. Both classes are set, though the mobile one only takes effect when the
			// browser window itself is under 480px, because that is the media query frontend.css
			// puts them behind - the same query the site uses.
			setClass( POSITIONS, 'hp-notification-toasts--', value( OPTIONS.position ) );
			setClass( POSITIONS_MOBILE, 'hp-notification-toasts--m-', value( OPTIONS.positionMobile ) );

			// Auto-hiding and the countdown. frontend.js builds no bar at all when auto-hiding is
			// off, so hiding it here is the same picture.
			var autohide = '' !== value( OPTIONS.autohide );

			progress.hidden = ! autohide;

			if ( autohide ) {
				progress.style.animationDuration = Math.max( 1, absint( value( OPTIONS.duration ) ) || 6 ) + 's';
			}
		}

		/*
		 * Bind every input this panel reads.
		 *
		 * jQuery rather than addEventListener throughout, for two reasons. HivePress turns some of
		 * these selects into Select2, which writes the chosen value and then triggers a jQuery
		 * "change" that a native listener never sees. And the colour pickers are worse: Iris writes
		 * every palette, square and strip pick straight into the input with .val(), which fires no
		 * DOM event whatsoever, and announces it only as the jQuery-only "irischange". Colours are
		 * the main thing anyone comes to this tab to change, so missing that event would leave the
		 * panel looking broken exactly where it matters most. admin-colors.js records the same trap
		 * at length; the binding is repeated here rather than folded into that file because the two
		 * scripts answer different questions about the same inputs, and admin-colors.js only listens
		 * while a field started out empty.
		 */
		Object.keys( OPTIONS ).forEach( function( key ) {
			var field = input( OPTIONS[ key ] );

			if ( ! field ) {
				return;
			}

			$( field ).on( 'input change irischange', function() {
				paint();

				// Replay only where the arrival itself changed, which is the two position settings:
				// they decide which direction the pop-up slides in from. Replaying on the appearance
				// settings would strobe the whole panel while somebody holds down a number spinner
				// or drags across the colour map.
				if ( 'position' === key || 'positionMobile' === key ) {
					replayEntry();
				} else {
					restartProgress();
				}
			} );
		} );

		/*
		 * The picker's Clear button is the one control that changes a colour without any event at
		 * all: it calls .val( '' ) directly and stops (wp-admin/js/color-picker.js). Its sibling
		 * Default button does call .change(), so that one is already covered above. The zero-delay
		 * timer lets the button's own handler finish writing before the value is read.
		 */
		$( document ).on( 'click', '.wp-picker-clear', function() {
			window.setTimeout( paint, 0 );
		} );

		if ( replay ) {
			$( replay ).on( 'click', replayEntry );
		}

		// First paint. The pop-up is already in the page with its saved position and countdown, so
		// this is what fills in the colours and sizing before anybody touches a control.
		paint();
	} );
}() );
