/**
 * Notifications for HivePress.
 *
 * The pop-up payload is fetched after the page has loaded rather than printed into the HTML,
 * because a full-page cache would otherwise serve one user's notifications to another. The
 * request reads a single user meta value on the server, so it stays cheap enough to run once
 * per page view, and cheap enough to poll while the tab is visible.
 */
( function() {
	'use strict';

	// The payload lives one level down, under "config": wp_localize_script() turns every
	// top-level scalar into a string, so booleans and numbers only keep their types when nested.
	var settings = window.hpNotificationsData && window.hpNotificationsData.config;

	/*
	 * Icons.
	 *
	 * Two sources. The chrome glyphs this script draws itself -- chevrons, the
	 * close and tick controls, the read/unread flip, the fallback bell -- arrive
	 * once in settings.icons. Icons belonging to a NOTIFICATION cannot be known
	 * up front, because any type can name one and hivepress/v1/notification_types
	 * is a public filter, so each REST response carries a deduplicated map of the
	 * icons it references and mergeIcons() folds it in.
	 *
	 * Values are "viewBox|path" pairs, not markup. They contain no angle
	 * brackets at all, so nothing here can be mistaken for HTML.
	 */
	var icons = ( settings && settings.icons ) || {};

	function mergeIcons( map ) {
		if ( ! map ) {
			return;
		}

		Object.keys( map ).forEach( function( name ) {
			icons[ name ] = map[ name ];
		} );
	}

	/**
	 * Builds an <svg> for one icon name, or returns null.
	 *
	 * hasOwnProperty rather than a bare lookup: an icon named "constructor" or
	 * "__proto__" would otherwise return a truthy non-string off the prototype
	 * chain and throw on .split().
	 */
	function glyph( name ) {
		if ( ! name || ! Object.prototype.hasOwnProperty.call( icons, name ) ) {
			return null;
		}

		var parts = String( icons[ name ] ).split( "|" );

		if ( 2 !== parts.length || ! parts[ 0 ] || ! parts[ 1 ] ) {
			return null;
		}

		var ns  = "http://www.w3.org/2000/svg";
		var svg = document.createElementNS( ns, "svg" );

		svg.setAttribute( "viewBox", parts[ 0 ] );
		svg.setAttribute( "class", "fafh-icon__svg" );
		svg.setAttribute( "aria-hidden", "true" );
		svg.setAttribute( "focusable", "false" );

		var path = document.createElementNS( ns, "path" );

		// Without this a stroke-width in px is read in USER units (1/512 em,
		// every Font Awesome viewBox being "0 0 W 512"), so the bell weight
		// setting would render invisibly.
		path.setAttribute( "vector-effect", "non-scaling-stroke" );
		path.setAttribute( "d", parts[ 1 ] );
		svg.appendChild( path );

		return svg;
	}

	/**
	 * Builds the <i> wrapper for one icon.
	 *
	 * Keeps hp-icon, which is core's class and carries the sizing and spacing
	 * these icons have always inherited. Falls back to the Font Awesome classes
	 * when there is no glyph data, which is what a site without the library, or
	 * a payload cached before an icon was added to the map, gets.
	 */
	function makeIcon( name, extra ) {
		var element = document.createElement( "i" );
		var svg     = glyph( name );

		element.className = "hp-icon " + ( svg ? "fafh-icon" : "fas fa-" + name ) + ( extra ? " " + extra : "" );
		element.setAttribute( "aria-hidden", "true" );

		if ( svg ) {
			element.appendChild( svg );
		}

		return element;
	}

	/**
	 * Swaps the glyph inside an existing <i>, keeping its other classes.
	 *
	 * The read/unread toggle used to do this by rewriting className, which sets
	 * the glyph and the layout classes in one assignment. With an SVG child the
	 * two have to be separated, or flipping the icon would strip the layout.
	 */
	function swapIcon( element, name ) {
		if ( ! element ) {
			return;
		}

		var replacement = makeIcon( name );

		// Carry over anything that is not ours, so a caller's layout class rides
		// through the swap.
		String( element.className ).split( /\s+/ ).forEach( function( token ) {
			if ( token && "hp-icon" !== token && "fafh-icon" !== token && "fas" !== token && 0 !== token.indexOf( "fa-" ) ) {
				replacement.className += " " + token;
			}
		} );

		if ( element.parentNode ) {
			element.parentNode.replaceChild( replacement, element );
		}
	}


	// Pop-ups already shown this page view, so polling never repeats one.
	var seen = {};

	// Notifications the service worker has already announced through the operating system.
	//
	// This is kept in localStorage rather than a variable because the suppression has to outlive
	// the page that was told about it. The worker messages whichever tabs happen to exist the
	// instant the push arrives, but the pop-up for that same notification is rendered later - after
	// an idle callback, after a hidden tab comes back, or by a different tab entirely - and a
	// reload in between wiped the record. The result was the OS and the page both announcing one
	// notification. localStorage is shared by every tab on the origin and survives a reload, so
	// whoever ends up rendering knows it has already been announced.
	var SHOWN_KEY = 'hp_notification_shown';
	var SHOWN_TTL = 3600000;

	// Mirrors the stored set, so suppression still works where storage is unavailable.
	var shown = {};

	// The tab title before any unread count is prefixed to it.
	var baseTitle = document.title.replace( /^\(\d+\)\s+/, '' );

	/**
	 * Reads the set of notifications the operating system has announced.
	 *
	 * @return {Object}
	 */
	function readShown() {
		try {
			var stored = JSON.parse( window.localStorage.getItem( SHOWN_KEY ) || '{}' );

			return stored && 'object' === typeof stored ? stored : {};
		} catch ( error ) {

			// Storage can be unavailable or hold something else entirely. Neither is worth an error.
			return {};
		}
	}

	/**
	 * Records that the operating system has announced a notification.
	 *
	 * @param {number} id Notification ID.
	 */
	function markShown( id ) {
		shown[ id ] = true;

		var stored = readShown();
		var cutoff = Date.now() - SHOWN_TTL;

		// Pruned on every write, so the entry can't grow without bound on a busy site.
		Object.keys( stored ).forEach( function( key ) {
			if ( ! stored[ key ] || stored[ key ] < cutoff ) {
				delete stored[ key ];
			}
		} );

		stored[ id ] = Date.now();

		try {
			window.localStorage.setItem( SHOWN_KEY, JSON.stringify( stored ) );
		} catch ( error ) {}
	}

	/**
	 * Says whether the operating system has already announced a notification.
	 *
	 * @param {number} id Notification ID.
	 * @return {boolean}
	 */
	function wasShown( id ) {
		if ( shown[ id ] ) {
			return true;
		}

		var stored = readShown();

		return !! stored[ id ] && stored[ id ] > Date.now() - SHOWN_TTL;
	}

	/**
	 * Sends a request to the HivePress API.
	 *
	 * @param {string} path Endpoint path.
	 * @param {Object} data Request payload.
	 * @param {string} method Request method.
	 * @return {Promise}
	 */
	function request( path, data, method, keepalive ) {
		var args = {
			method: method || 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': settings.apiNonce
			}
		};

		if ( 'GET' !== args.method ) {
			args.headers['Content-Type'] = 'application/json';
			args.body = JSON.stringify( data || {} );
		}

		// Requests sent as someone follows a link have to outlive the page. Without this the
		// browser cancels the fetch the moment it navigates, so the notification was never marked
		// read and the open was never counted - which is why opens always showed as zero.
		if ( keepalive ) {
			args.keepalive = true;
		}

		return window.fetch( settings.apiURL + path, args ).then( function( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Request failed' );
			}

			return response.json();
		} );
	}

	/**
	 * Drops a URL that carries anything other than an http(s) scheme.
	 *
	 * The server stores only sanitised permalinks, so this is defence in depth: a value with a
	 * javascript:, data: or similar scheme is never placed in an href. ASCII whitespace and control
	 * characters are removed first, because a browser ignores them when reading a scheme and would
	 * otherwise run a "java\tscript:" style value.
	 *
	 * @param {string} url URL to check.
	 * @return {string} The URL, or an empty string when its scheme isn't allowed.
	 */
	function safeUrl( url ) {
		if ( 'string' !== typeof url || '' === url ) {
			return '';
		}

		var scheme = /^([a-z][a-z0-9+.-]*):/i.exec( url.replace( /[\u0000-\u0020]+/g, '' ) );

		if ( scheme && 'http' !== scheme[1].toLowerCase() && 'https' !== scheme[1].toLowerCase() ) {
			return '';
		}

		return url;
	}

	/**
	 * Updates the unread badges in the account menu and the header bell.
	 *
	 * @param {number} count Unread count.
	 */
	/**
	 * Builds the selection box a row carries for the Clear selected action.
	 *
	 * Labelled for screen readers and bound by delegation on the list, so a row built here for
	 * a notification that arrives while the page is open behaves like one the template printed.
	 *
	 * @param {number} id Notification ID.
	 * @return {Element}
	 */
	function buildSelect( id ) {
		var label = document.createElement( 'label' );
		label.className = 'hp-notification__select';

		var box = document.createElement( 'input' );
		box.type = 'checkbox';
		box.value = String( id );
		box.setAttribute( 'data-component', 'notification-select' );

		var text = document.createElement( 'span' );
		text.className = 'screen-reader-text';
		text.textContent = settings.selectText || '';

		label.appendChild( box );
		label.appendChild( text );

		return label;
	}

	function setBadge( count ) {

		// Put the unread count in the tab title, so a backgrounded tab still says so.
		document.title = count ? '(' + count + ') ' + baseTitle : baseTitle;

		// Every instance, not the first. Themes render the account menu several times over - a
		// desktop menu, a mobile one, an off-canvas one and the account sidebar - so updating only
		// the first match left the others showing the old number.
		var targets = [];

		Array.prototype.forEach.call( document.querySelectorAll( '.hp-menu__item--notifications-view a' ), function( item ) {
			targets.push( item );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '.hp-notification-bell__toggle' ), function( item ) {
			targets.push( item );
		} );

		targets.forEach( function( item ) {
			if ( ! item ) {
				return;
			}

			var badge = item.querySelector( 'small' );

			if ( ! count ) {
				if ( badge ) {
					badge.remove();
				}

				return;
			}

			if ( ! badge ) {
				badge = document.createElement( 'small' );
				item.appendChild( badge );
			}

			badge.textContent = String( count );
		} );

		setActionBarBadge( count );

		syncListHeader( count );
	}

	/**
	 * Keeps the Action Bar's notification badge in step with the count.
	 *
	 * The bar renders its badge once, server-side, so without this the number sits stale until the
	 * next page load - which on a phone, where the bar is the main way people move around, can be a
	 * long time. The bar hides the badge with the "hidden" attribute at zero rather than removing
	 * it, and this does the same, so the two agree on what an empty badge looks like.
	 *
	 * Only items whose badge counts notifications are touched. An item set to unread messages or to
	 * HivePress's own activity counter is counting something else, and quietly overwriting it with
	 * this number would be worse than leaving it a page out of date.
	 *
	 * @param {number} count Unread count.
	 */
	function setActionBarBadge( count ) {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.hp-action-bar__item[data-badge="notifications"] .hp-action-bar__badge' ),
			function( badge ) {
				if ( ! count ) {
					badge.setAttribute( 'hidden', 'hidden' );

					return;
				}

				badge.textContent = count > 99 ? '99+' : String( count );
				badge.removeAttribute( 'hidden' );
			}
		);
	}

	/**
	 * Brings the notification page's own header into line with the count.
	 *
	 * The page prints "7 unread" and a "Mark all as read" button server-side. Marking something
	 * read without reloading used to leave both untouched, so the page contradicted its own badge.
	 *
	 * @param {number} count Unread count.
	 */
	function syncListHeader( count ) {
		var summary = document.querySelector( '[data-component="notifications-count"]' );

		if ( summary ) {
			if ( count ) {
				summary.textContent = ( 1 === count ? settings.unreadOneText : settings.unreadText ).replace( '%s', String( count ) );
				summary.classList.remove( 'hp-notifications__count--clear' );
			} else {
				summary.textContent = settings.caughtUpText;
				summary.classList.add( 'hp-notifications__count--clear' );
			}
		}

		// Nothing left to mark, so the button goes - hidden rather than removed, because a
		// notification arriving while the page is open makes it useful again and a removed button
		// would have taken its click handler with it.
		var readAll = document.querySelector( '[data-component="notifications-read-all"]' );

		if ( readAll ) {
			readAll.hidden = ! count;
		}
	}

	/**
	 * Builds the leading visual for a notification: the image if there is one, the type icon
	 * otherwise.
	 *
	 * @param {Object} notification Notification data.
	 * @param {string} className Class name.
	 * @return {Element}
	 */
	function buildVisual( notification, className ) {
		var visual = document.createElement( 'div' );
		visual.className = className;

		// A notification can carry its own colour, which is how a badge award shows the badge's own
		// colour rather than the shared accent. Only a six digit hex is accepted, so nothing from
		// the server can turn into arbitrary CSS here.
		if ( notification.color && /^#[0-9a-f]{6}$/i.test( notification.color ) ) {
			visual.style.backgroundColor = notification.color;
			visual.style.color = '#ffffff';
		}

		if ( notification.image ) {
			var image = document.createElement( 'img' );
			image.src = notification.image;
			image.alt = '';
			image.loading = 'lazy';

			visual.appendChild( image );
		} else {
			var iconElement = makeIcon( notification.icon || 'bell' );

			visual.appendChild( iconElement );
		}

		return visual;
	}

	/**
	 * Plays the notification chime.
	 *
	 * Browsers only allow sound after the person has interacted with the page, so the audio
	 * context is created on the first click or key press, and a pop-up that arrives before that
	 * stays silent.
	 */
	var Chime = {
		context: null,
		ready: false,

		unlock: function() {
			if ( ! settings.sound || this.ready || ! ( window.AudioContext || window.webkitAudioContext ) ) {
				return;
			}

			this.context = new ( window.AudioContext || window.webkitAudioContext )();
			this.ready = true;
		},

		play: function() {
			if ( ! this.ready || 'running' !== this.context.state && 'suspended' !== this.context.state ) {
				return;
			}

			var context = this.context;

			var play = function() {
				var now = context.currentTime;

				// Each style is a list of notes: frequency, start offset, length, peak volume,
				// wave shape, and an optional ending frequency for a pitch slide.
				var styles = {
					chime: [ [ 880, 0, 0.3, 0.05, 'sine' ], [ 1174.66, 0.09, 0.21, 0.05, 'sine' ] ],
					ping:  [ [ 1318.5, 0, 0.14, 0.06, 'sine' ] ],
					pop:   [ [ 520, 0, 0.12, 0.07, 'triangle', 180 ] ],
					bell:  [ [ 880, 0, 0.55, 0.06, 'sine' ], [ 1760, 0, 0.4, 0.02, 'sine' ] ],
					soft:  [ [ 392, 0, 0.35, 0.04, 'sine' ], [ 523.25, 0.06, 0.3, 0.04, 'sine' ] ]
				};

				( styles[ settings.soundStyle ] || styles.chime ).forEach( function( note ) {
					var oscillator = context.createOscillator();
					var gain = context.createGain();
					var start = now + note[1];
					var end = start + note[2];

					oscillator.type = note[4];
					oscillator.frequency.setValueAtTime( note[0], start );

					if ( note[5] ) {
						oscillator.frequency.exponentialRampToValueAtTime( note[5], end );
					}

					gain.gain.setValueAtTime( 0.0001, start );
					gain.gain.exponentialRampToValueAtTime( note[3], start + 0.02 );
					gain.gain.exponentialRampToValueAtTime( 0.0001, end );

					oscillator.connect( gain );
					gain.connect( context.destination );

					oscillator.start( start );
					oscillator.stop( end + 0.02 );
				} );
			};

			if ( 'suspended' === context.state ) {
				context.resume().then( play ).catch( function() {} );
			} else {
				play();
			}
		}
	};

	/**
	 * Manages the pop-up stack.
	 */
	var Toasts = {
		container: null,
		visible: 0,
		waiting: [],

		/**
		 * Gets the pop-up container, creating it on first use.
		 *
		 * @return {Element}
		 */
		getContainer: function() {
			if ( ! this.container ) {
				this.container = document.createElement( 'div' );
				this.container.className = 'hp-notification-toasts hp-notification-toasts--' + settings.position + ' hp-notification-toasts--m-' + ( settings.positionMobile || 'bottom' );
				this.container.setAttribute( 'role', 'region' );
				this.container.setAttribute( 'aria-live', 'polite' );

				document.body.appendChild( this.container );
			}

			return this.container;
		},

		/**
		 * Adds a notification to the stack, holding it back if the limit is reached.
		 *
		 * @param {Object} notification Notification data.
		 */
		add: function( notification ) {
			if ( seen[ notification.id ] || wasShown( notification.id ) ) {
				return;
			}

			seen[ notification.id ] = true;

			if ( this.visible >= settings.limit ) {
				this.waiting.push( notification );

				return;
			}

			this.show( notification );
		},

		/**
		 * Builds and shows a pop-up.
		 *
		 * @param {Object} notification Notification data.
		 */
		show: function( notification ) {
			var self = this;

			// Checked again here, not only when the pop-up was queued. A pop-up waits when the
			// on-screen limit is reached, and a queue deferred by a hidden tab is drained whenever
			// that tab comes back, so minutes can pass between the two - long enough for the
			// operating system to have announced this one in the meantime.
			if ( wasShown( notification.id ) ) {
				return;
			}

			this.visible += 1;

			// Build the pop-up.
			var toast = document.createElement( 'div' );
			toast.className = 'hp-notification-toast';

			toast.appendChild( buildVisual( notification, 'hp-notification-toast__icon' ) );

			var body = document.createElement( 'div' );
			body.className = 'hp-notification-toast__body';

			if ( notification.type ) {
				var type = document.createElement( 'span' );
				type.className = 'hp-notification-toast__type';
				type.textContent = notification.type;

				body.appendChild( type );
			}

			// Text is set with textContent, never innerHTML, because it can contain values a
			// visitor supplied such as a listing title.
			var text = document.createElement( 'span' );
			text.className = 'hp-notification-toast__text';
			text.textContent = notification.text;

			body.appendChild( text );

			// Add the deep link. HivePress link tokens already point at the exact thing that
			// happened, so this drops the reader straight into the conversation or booking. The
			// URL is re-checked here even though the server stores only sanitised permalinks.
			var url = safeUrl( notification.url );

			if ( url ) {
				var link = document.createElement( 'a' );
				link.className = 'hp-notification-toast__link';
				link.href = url;

				var linkLabel = document.createElement( 'span' );
				linkLabel.textContent = notification.link_label || settings.viewText;
				link.appendChild( linkLabel );
				link.appendChild( makeIcon( 'chevron-right' ) );

				link.addEventListener( 'click', function() {
					self.markRead( notification.id, true );
				} );

				body.appendChild( link );
			}

			toast.appendChild( body );

			// Add the close button.
			var close = document.createElement( 'button' );
			close.type = 'button';
			close.className = 'hp-notification-toast__close';
			close.setAttribute( 'aria-label', settings.closeText );
			close.appendChild( makeIcon( 'times' ) );

			close.addEventListener( 'click', function() {
				self.hide( toast );
			} );

			toast.appendChild( close );

			// Add the countdown.
			if ( settings.autohide ) {
				var progress = document.createElement( 'div' );
				progress.className = 'hp-notification-toast__progress';
				progress.style.animationDuration = settings.duration + 's';

				/*
				 * A timer hides the pop-up, and the bar above is only the picture of it.
				 *
				 * This used to hang off the bar's own animationend, which broke twice over. Under
				 * "prefers-reduced-motion: reduce" the stylesheet sets "animation: none" on the bar
				 * (frontend.css:390), so the event never fired at all and pop-ups stayed on screen
				 * for ever - and once the on-screen limit was reached the whole feature went silent
				 * for the rest of the page. That is a system setting, not one of ours, so it never
				 * showed up in ordinary testing.
				 *
				 * The obvious repair, a plain setTimeout beside the listener, quietly broke the
				 * other half: the CSS pauses the bar while the pointer is on a pop-up
				 * (frontend.css:164-167), and a timer that ignores that whips the notification away
				 * from somebody in the middle of reading it.
				 *
				 * So the timer pauses too, on hover and on keyboard focus, matching the CSS
				 * exactly. One thing decides when a pop-up goes, it works whether or not the
				 * animation runs, and the promise the setting makes is true again.
				 */
				var remaining = Math.max( 1, settings.duration ) * 1000;
				var startedAt = 0;
				var timer     = null;

				var resume = function() {
					if ( timer || remaining <= 0 ) {
						return;
					}

					startedAt = Date.now();

					timer = window.setTimeout( function() {
						timer = null;

						self.hide( toast );
					}, remaining );
				};

				var pause = function() {
					if ( ! timer ) {
						return;
					}

					window.clearTimeout( timer );

					timer      = null;
					remaining -= Date.now() - startedAt;
				};

				toast.addEventListener( 'mouseenter', pause );
				toast.addEventListener( 'mouseleave', resume );
				toast.addEventListener( 'focusin', pause );
				toast.addEventListener( 'focusout', resume );

				resume();

				toast.appendChild( progress );
			}

			this.getContainer().appendChild( toast );

			Chime.play();

			// Trigger the entry transition once the pop-up is in the document. The timer is a
			// safety net: requestAnimationFrame does not run in a background tab, and without it a
			// pop-up could sit at opacity 0 for its whole life and then be removed unseen.
			var reveal = function() {
				toast.classList.add( 'hp-notification-toast--visible' );
			};

			window.requestAnimationFrame( reveal );
			window.setTimeout( reveal, 50 );
		},

		/**
		 * Hides a pop-up and releases its slot.
		 *
		 * @param {Element} toast Pop-up element.
		 */
		hide: function( toast ) {
			var self = this;

			if ( toast.classList.contains( 'hp-notification-toast--leaving' ) ) {
				return;
			}

			toast.classList.add( 'hp-notification-toast--leaving' );
			toast.classList.remove( 'hp-notification-toast--visible' );

			window.setTimeout( function() {
				toast.remove();

				self.visible -= 1;

				// A waiting pop-up may have been announced by the operating system while it sat in
				// the queue, in which case show() drops it. Looping means the freed slot goes to
				// the next one that still needs showing instead of being left idle.
				while ( self.waiting.length && self.visible < settings.limit ) {
					self.show( self.waiting.shift() );
				}
			}, 240 );
		},

		/**
		 * Marks a notification as read.
		 *
		 * @param {number} id Notification ID.
		 * @param {boolean} clicked Whether it was followed.
		 */
		markRead: function( id, clicked ) {

			// A click is usually a navigation, so the request is sent keepalive to survive it.
			request( '/notifications/read', {
				ids: [ id ],
				clicked: clicked ? 1 : 0
			}, 'POST', !! clicked ).then( function( response ) {
				setBadge( response.data.unread );
			} ).catch( function() {} );
		}
	};

	// Set when a queue check was skipped because the tab was hidden, so it runs on return.
	var queueDeferred = false;

	// When the queue was last actually requested. Kept here rather than inside startPolling()
	// because two listeners fire on one visibilitychange: the flush in initToasts() runs first,
	// then the poller's. With the timestamp local to the poller, the flush's request was invisible
	// to it, so returning to a tab hidden for longer than the poll interval read the queue twice.
	var queueChecked = 0;

	/**
	 * Fetches and shows the pending pop-ups.
	 *
	 * Reading the queue empties it server-side, so it is only read while the tab can actually paint.
	 * Fetching it in a hidden tab used to consume the notifications and then discard them unseen,
	 * losing them permanently for anyone who left the site open in a second tab.
	 */
	function checkQueue() {
		if ( 'visible' !== document.visibilityState ) {
			queueDeferred = true;

			return;
		}

		queueDeferred = false;
		queueChecked  = Date.now();

		request( '/notifications/queue' ).then( function( response ) {
			// Before the rows are built, or the first one draws no icon.
			mergeIcons( response.data.icons );

			response.data.notifications.forEach( function( notification ) {
				Toasts.add( notification );
				List.insert( notification );
			} );

			// After the rows, so the header count and the rows below it agree.
			setBadge( response.data.unread );
		} ).catch( function() {} );
	}

	/**
	 * Refreshes the unread count on its own.
	 *
	 * Reading the queue is the other way to get this number, but that empties the queue, so it can
	 * only be done by a tab that can actually paint the pop-ups. This endpoint reads the same single
	 * meta value and changes nothing, which is what a hidden tab needs: the badge and the tab title
	 * stay honest while its pop-ups keep waiting for it to come back.
	 */
	function refreshCount() {
		request( '/notifications/count', null, 'GET' ).then( function( response ) {
			setBadge( response.data.unread );
		} ).catch( function() {} );
	}

	/**
	 * Keeps the notifications page in step with what arrives while it is open.
	 *
	 * Someone sitting on their notifications page used to watch the count go up while the list
	 * below it stayed exactly as it was, so the page contradicted itself until it was reloaded.
	 */
	var List = {
		root: null,
		ready: false,

		/**
		 * Finds the list, once.
		 */
		init: function() {
			this.ready = true;
			this.root  = document.querySelector( '[data-component="notifications-list"]' );
		},

		/**
		 * Adds a notification to the top of the list.
		 *
		 * @param {Object} notification Notification data.
		 */
		insert: function( notification ) {
			if ( ! this.ready ) {
				this.init();
			}

			// Only a plain first page can take a new row. A filtered or searched list might
			// legitimately exclude this notification, and the newest thing does not belong on page
			// two of a list ordered newest first; in both cases it appears on the next load, as it
			// always has. The server decides, because only it knows which page this is.
			if ( ! this.root || '1' !== this.root.getAttribute( 'data-live' ) ) {
				return;
			}

			var id = parseInt( notification.id, 10 );

			if ( ! id || this.root.querySelector( '.hp-notification[data-id="' + id + '"]' ) ) {
				return;
			}

			var list = this.getTodayList();

			if ( ! list ) {
				return;
			}

			list.insertBefore( this.buildRow( notification, id ), list.firstChild );

			// There is now something to clear, on a page that may have loaded with nothing.
			var clear = this.root.querySelector( '[data-component="notifications-delete-read"]' );

			if ( clear ) {
				clear.hidden = false;
			}
		},

		/**
		 * Gets today's group, creating it when the page has none.
		 *
		 * @return {Element|null}
		 */
		getTodayList: function() {

			// A list that was empty is not empty any more.
			var empty = this.root.querySelector( '.hp-notifications__empty' );

			if ( empty ) {
				empty.remove();
			}

			var section = this.root.querySelector( '.hp-notifications__group[data-today="1"]' );

			if ( section ) {
				return section.querySelector( '.hp-notifications__list' );
			}

			section = document.createElement( 'section' );
			section.className = 'hp-notifications__group';
			section.setAttribute( 'data-today', '1' );

			var heading = document.createElement( 'h3' );
			heading.className = 'hp-notifications__date';
			heading.textContent = settings.todayText;

			var list = document.createElement( 'ul' );
			list.className = 'hp-notifications__list';

			section.appendChild( heading );
			section.appendChild( list );

			// Above every other group, because the list runs newest first.
			var first = this.root.querySelector( '.hp-notifications__group' );

			if ( first ) {
				this.root.insertBefore( section, first );
			} else {
				this.root.appendChild( section );
			}

			return list;
		},

		/**
		 * Builds a row matching the ones the template renders.
		 *
		 * @param {Object} notification Notification data.
		 * @param {number} id Notification ID.
		 * @return {Element}
		 */
		buildRow: function( notification, id ) {
			var row = document.createElement( 'li' );
			row.className = 'hp-notification hp-notification--unread';
			row.setAttribute( 'data-id', String( id ) );
			row.appendChild( buildSelect( id ) );

			// The same builder the pop-ups use, so a badge award shows its own icon and colour here
			// too rather than the shared accent.
			row.appendChild( buildVisual( notification, 'hp-notification__icon' ) );

			var body = document.createElement( 'div' );
			body.className = 'hp-notification__body';

			var type = document.createElement( 'span' );
			type.className = 'hp-notification__type';
			type.textContent = notification.type || '';

			body.appendChild( type );

			// textContent throughout, never innerHTML: the text carries values a visitor supplied,
			// such as a listing title or a display name.
			var url = safeUrl( notification.url );
			var text = document.createElement( url ? 'a' : 'span' );
			text.className = 'hp-notification__text';
			text.textContent = notification.text;

			if ( url ) {
				text.href = url;
			}

			body.appendChild( text );

			if ( url ) {
				var link = document.createElement( 'a' );
				link.className = 'hp-notification__link';
				link.href = url;

				var rowLabel = document.createElement( 'span' );
				rowLabel.textContent = notification.link_label || settings.viewText;
				link.appendChild( rowLabel );
				link.appendChild( makeIcon( 'chevron-right' ) );

				body.appendChild( link );
			}

			if ( notification.time ) {
				var time = document.createElement( 'time' );
				time.className = 'hp-notification__time';
				time.textContent = notification.time;

				if ( notification.datetime ) {
					time.setAttribute( 'datetime', notification.datetime );
				}

				body.appendChild( time );
			}

			row.appendChild( body );

			var controls = document.createElement( 'div' );
			controls.className = 'hp-notification__controls';

			// The list binds its controls by delegation, so a row built here answers to the tick
			// and the cross with no extra wiring.
			var toggle = document.createElement( 'button' );
			toggle.type = 'button';
			toggle.className = 'hp-notification__toggle';
			toggle.setAttribute( 'data-component', 'notification-toggle' );
			toggle.setAttribute( 'aria-label', settings.readText );
			toggle.setAttribute( 'title', settings.readText );
			toggle.appendChild( makeIcon( 'check' ) );

			var remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'hp-notification__delete';
			remove.setAttribute( 'data-component', 'notification-delete' );
			remove.setAttribute( 'aria-label', settings.deleteText );
			remove.appendChild( makeIcon( 'times' ) );

			controls.appendChild( toggle );
			controls.appendChild( remove );
			row.appendChild( controls );

			return row;
		}
	};

	/**
	 * Checks for new notifications while the tab is open.
	 *
	 * The check is paused while the tab is in the background and runs once as soon as it comes
	 * back, so a hidden tab costs the server nothing.
	 */
	function startPolling() {
		var interval = parseInt( settings.poll, 10 );

		if ( ! interval || interval < 15 ) {
			return;
		}

		queueChecked = Date.now();

		window.setInterval( function() {
			if ( 'visible' !== document.visibilityState ) {
				return;
			}

			checkQueue();
		}, interval * 1000 );

		document.addEventListener( 'visibilitychange', function() {
			if ( 'visible' !== document.visibilityState ) {
				return;
			}

			// Run on return if the interval lapsed while the tab was hidden. A check deferred
			// during that time is not tested for here: the flush listener in initToasts() runs
			// first on this same event and has already made the request, and checkQueue() stamps
			// queueChecked, so the comparison below now sees it and stays quiet. Reading the queue
			// empties it, so a wasted round trip here is not free.
			if ( Date.now() - queueChecked > interval * 1000 ) {
				checkQueue();
			}
		} );
	}

	/**
	 * Manages every header bell on the page.
	 *
	 * Plural on purpose. The Action Bar extension renders a second bell with the same markup
	 * inside its bottom bar, and this used to bind querySelector's first match only, so whichever
	 * bell rendered second was a dead link. Each instance now gets its own panel, open state and
	 * positioning, while the unread count and the recent-notifications fetch stay shared: one
	 * request serves every panel, and setBadge() already updates every toggle it can find.
	 */
	var Bells = {
		instances: [],

		// The shared fetch, cached as a promise so two bells opened back to back cost one request.
		promise: null,

		init: function() {
			var self = this;

			Array.prototype.forEach.call( document.querySelectorAll( '[data-component="notification-bell"]' ), function( wrap ) {
				var toggle = wrap.querySelector( '.hp-notification-bell__toggle' );
				var panel = wrap.querySelector( '.hp-notification-bell__panel' );

				if ( ! toggle || ! panel ) {
					return;
				}

				// Keep the shared header area a single row. The stylesheet does this with :has();
				// this is the same fix for browsers that don't support it.
				var area = wrap.parentElement;

				if ( area && ! ( window.CSS && window.CSS.supports && window.CSS.supports( 'selector(:has(> *))' ) ) ) {
					area.style.display = 'flex';
					area.style.alignItems = 'center';
					area.style.flexWrap = 'nowrap';
				}

				var instance = {
					wrap: wrap,
					toggle: toggle,
					panel: panel,
				};

				// The bell stays a plain link without scripting, so the panel only takes over here.
				toggle.addEventListener( 'click', function( event ) {
					event.preventDefault();

					if ( panel.hidden ) {
						self.open( instance );
					} else {
						self.close( instance );
					}
				} );

				self.instances.push( instance );
			} );

			if ( ! this.instances.length ) {
				return;
			}

			// One set of document and window listeners walks every instance, rather than one set
			// per bell piling up handlers.
			document.addEventListener( 'click', function( event ) {
				self.instances.forEach( function( instance ) {
					if ( ! instance.panel.hidden && ! instance.wrap.contains( event.target ) ) {
						self.close( instance );
					}
				} );
			} );

			document.addEventListener( 'keydown', function( event ) {
				if ( 'Escape' !== event.key ) {
					return;
				}

				self.instances.forEach( function( instance ) {
					if ( ! instance.panel.hidden ) {
						self.close( instance );
						instance.toggle.focus();
					}
				} );
			} );

			// Re-measure while a panel is open. place() ran only on open, so turning a phone with
			// the dropdown showing left a coordinate taken in the other orientation: the panel
			// detached from the bell and floated below it. Crossing the breakpoint the other way
			// left "top" set on a panel that is no longer fixed, which place() now clears.
			[ 'resize', 'orientationchange' ].forEach( function( name ) {
				window.addEventListener( name, function() {
					self.instances.forEach( function( instance ) {
						if ( ! instance.panel.hidden ) {
							self.place( instance );
						}
					} );
				} );
			} );
		},

		open: function( instance ) {
			var self = this;

			// One panel at a time: two bells showing the same list twice is clutter, not choice.
			this.instances.forEach( function( other ) {
				if ( other !== instance && ! other.panel.hidden ) {
					self.close( other );
				}
			} );

			instance.panel.hidden = false;
			instance.toggle.setAttribute( 'aria-expanded', 'true' );

			this.place( instance );
			this.load( instance );
		},

		close: function( instance ) {
			instance.panel.hidden = true;
			instance.toggle.setAttribute( 'aria-expanded', 'false' );

			// Drop the measurement with the panel. It was taken against one viewport, and leaving
			// it behind means the next open starts from a stale number before place() runs.
			instance.panel.style.top = '';
		},

		/**
		 * Pins the panel below the bell on small screens.
		 *
		 * The stylesheet switches the panel to position: fixed on phones so it can span the screen
		 * instead of hanging off a bell that sits well in from the edge. Fixed positioning loses the
		 * vertical anchor, though - "top: auto" falls back to the static position, which is not
		 * dependably under the bell once a theme has laid the header out its own way. So the top is
		 * measured from the bell itself, which is right whatever the theme does.
		 *
		 * Only for a bell in the TOP half of the screen. A bell in the bottom half - the Action
		 * Bar's - opens its panel upwards, positioned by that bar's own scoped stylesheet, and an
		 * inline "top" written here would both outrank those rules and pin the panel off the
		 * bottom of the screen. Leaving the property alone is what lets the bar's CSS win.
		 */
		place: function( instance ) {
			if ( ! window.matchMedia || ! window.matchMedia( '(max-width: 47.99em)' ).matches ) {
				instance.panel.style.top = '';

				return;
			}

			var rect = instance.toggle.getBoundingClientRect();

			if ( rect.bottom > window.innerHeight / 2 ) {
				instance.panel.style.top = '';

				return;
			}

			instance.panel.style.top = Math.round( rect.bottom + 8 ) + 'px';
		},

		load: function( instance ) {
			var self = this;

			// One fetch feeds every panel. The cached promise stands for 30 seconds, so a second
			// bell opened moments later renders the same data without another round trip.
			if ( ! this.promise ) {
				this.promise = request( '/notifications/recent', null, 'GET' ).then( function( response ) {
					setBadge( response.data.unread );

					// Let the next open fetch fresh data after a while.
					window.setTimeout( function() {
						self.promise = null;
					}, 30000 );

					mergeIcons( response.data.icons );

					return response.data.notifications;
				} ).catch( function( error ) {

					// A failed fetch must not be cached, or every bell would show the error until
					// the page was reloaded. Clearing it means closing and reopening tries again.
					self.promise = null;

					throw error;
				} );
			}

			this.promise.then( function( notifications ) {
				self.render( instance, notifications );
			} ).catch( function() {

				// Say so rather than sitting on "Loading…" for ever. An expired nonce, an offline
				// phone or a 500 all land here, and this was the one place in the file that entered
				// a visible in-progress state with no way out of it.
				var body = instance.wrap.querySelector( '[data-component="notification-bell-body"]' );

				if ( body ) {
					body.innerHTML = '';

					var failed = document.createElement( 'div' );
					failed.className = 'hp-notification-bell__empty';
					failed.textContent = settings.errorText;

					body.appendChild( failed );
				}
			} );
		},

		render: function( instance, notifications ) {
			var body = instance.wrap.querySelector( '[data-component="notification-bell-body"]' );

			body.innerHTML = '';

			if ( ! notifications.length ) {
				var empty = document.createElement( 'div' );
				empty.className = 'hp-notification-bell__empty';
				empty.textContent = settings.emptyText;

				body.appendChild( empty );

				return;
			}

			notifications.forEach( function( notification ) {
				var url = safeUrl( notification.url );
				var item = document.createElement( url ? 'a' : 'div' );
				item.className = 'hp-notification-bell__item' + ( notification.read ? '' : ' hp-notification-bell__item--unread' );

				if ( url ) {
					item.href = url;

					item.addEventListener( 'click', function() {
						Toasts.markRead( notification.id, true );
					} );
				} else if ( ! notification.read ) {

					// A notification with nowhere to go, like an announcement without a link,
					// can still be read: tapping it anywhere dismisses it.
					item.addEventListener( 'click', function() {
						Toasts.markRead( notification.id );

						item.classList.remove( 'hp-notification-bell__item--unread' );
					} );
				}

				item.appendChild( buildVisual( notification, 'hp-notification-bell__visual' ) );

				var content = document.createElement( 'span' );
				content.className = 'hp-notification-bell__content';

				var text = document.createElement( 'span' );
				text.className = 'hp-notification-bell__text';
				text.textContent = notification.text;

				content.appendChild( text );

				// The second line names the type and how long ago it happened.
				if ( notification.type || notification.time_text || notification.time_short ) {
					var meta = document.createElement( 'span' );
					meta.className = 'hp-notification-bell__meta';
					meta.textContent = [ notification.type, notification.time_text || notification.time_short ].filter( Boolean ).join( ' \u00b7 ' );

					content.appendChild( meta );
				}

				item.appendChild( content );

				// A quiet cue that this row goes somewhere.
				if ( url ) {
					var go = makeIcon( 'chevron-right', 'hp-notification-bell__go' );

					item.appendChild( go );
				}

				// Every unread item gets its own dismiss, so the badge can be cleared from the
				// panel without visiting anything.
				if ( ! notification.read ) {
					var dismiss = document.createElement( 'button' );
					dismiss.type      = 'button';
					dismiss.className = 'hp-notification-bell__dismiss';
					dismiss.setAttribute( 'aria-label', settings.readText );
					dismiss.appendChild( makeIcon( 'times' ) );

					dismiss.addEventListener( 'click', function( event ) {
						event.preventDefault();
						event.stopPropagation();

						Toasts.markRead( notification.id );

						item.classList.remove( 'hp-notification-bell__item--unread' );
						dismiss.remove();
					} );

					item.appendChild( dismiss );
				}

				body.appendChild( item );
			} );
		}
	};

	/**
	 * Stores a value for the service worker.
	 *
	 * The worker can't read the page, so the token it needs to identify the user goes into
	 * IndexedDB, which is the storage both sides can reach.
	 *
	 * @param {string} key Key.
	 * @param {*} value Value.
	 * @return {Promise}
	 */
	function storeForWorker( key, value ) {
		return new Promise( function( resolve, reject ) {
			var open = window.indexedDB.open( 'hp-notifications', 1 );

			open.onupgradeneeded = function() {
				open.result.createObjectStore( 'settings' );
			};

			open.onsuccess = function() {
				var transaction = open.result.transaction( 'settings', 'readwrite' );

				transaction.objectStore( 'settings' ).put( value, key );

				transaction.oncomplete = function() {
					resolve();
				};

				transaction.onerror = function() {
					reject( transaction.error );
				};
			};

			open.onerror = function() {
				reject( open.error );
			};

			// Without this the promise could hang for ever. A version change waits for every other
			// connection to the database to close, and the service worker holds one of its own, so
			// with a second tab open neither onsuccess nor onerror ever fires. Nothing downstream
			// settled either, which is how the push button was left reading "Waiting for your
			// browser" minutes after the subscription had actually succeeded.
			open.onblocked = function() {
				reject( new Error( 'Blocked' ) );
			};
		} );
	}

	/**
	 * Converts the VAPID public key into the form the browser expects.
	 *
	 * @param {string} key Base64url encoded key.
	 * @return {Uint8Array}
	 */
	function decodeKey( key ) {
		var padded = key + '='.repeat( ( 4 - key.length % 4 ) % 4 );
		var raw = window.atob( padded.replace( /-/g, '+' ).replace( /_/g, '/' ) );
		var bytes = new Uint8Array( raw.length );

		for ( var i = 0; i < raw.length; i++ ) {
			bytes[ i ] = raw.charCodeAt( i );
		}

		return bytes;
	}

	/**
	 * Waits until a registration has an active worker.
	 *
	 * register() resolves as soon as the registration exists, while the worker itself can still be
	 * installing - and pushManager.subscribe() on a registration with no active worker rejects.
	 * That is what made enabling push a two-click affair: the first click registered the worker and
	 * then failed to subscribe, and only the second click, finding the worker active by then,
	 * succeeded. Waiting here makes the first click carry all the way through.
	 *
	 * @param {ServiceWorkerRegistration} registration Registration to wait on.
	 * @return {Promise}
	 */
	function waitForActiveWorker( registration ) {
		if ( registration.active ) {
			return Promise.resolve( registration );
		}

		return new Promise( function( resolve ) {
			var worker = registration.installing || registration.waiting;

			// Nothing to wait on; let subscribe() report whatever is really wrong.
			if ( ! worker ) {
				resolve( registration );

				return;
			}

			var check = function() {
				if ( 'activated' === worker.state || 'redundant' === worker.state ) {
					worker.removeEventListener( 'statechange', check );
					resolve( registration );
				}
			};

			worker.addEventListener( 'statechange', check );
			check();
		} );
	}

	/**
	 * Registers the worker and subscribes the browser to push.
	 */
	function subscribePush() {
		return window.navigator.serviceWorker.register( settings.push.worker, { scope: '/' } ).then( waitForActiveWorker ).then( function( registration ) {
			return registration.pushManager.subscribe( {
				userVisibleOnly: true,
				applicationServerKey: decodeKey( settings.push.key )
			} );
		} ).then( function( subscription ) {
			return request( '/notifications/push', { endpoint: subscription.endpoint } );
		} ).then( function( response ) {
			if ( response.data && response.data.token ) {
				return storeForWorker( 'token', response.data.token );
			}

			return null;
		} );
	}

	/**
	 * Sets up push, waiting for a few visits before ever asking.
	 *
	 * A permission prompt on someone's first visit gets refused, and a refusal is permanent, so
	 * the prompt waits until they've been back a few times, and is only made from a click, which
	 * is when browsers treat it most kindly.
	 */
	function initPush() {
		if ( ! settings.push || ! ( 'serviceWorker' in window.navigator ) || ! ( 'PushManager' in window ) || ! ( 'Notification' in window ) ) {
			return;
		}

		if ( 'denied' === window.Notification.permission ) {
			return;
		}

		// Someone who has already said yes is resubscribed quietly, which also replaces a
		// subscription the browser has dropped.
		if ( 'granted' === window.Notification.permission ) {
			subscribePush().catch( function() {} );

			return;
		}

		// Count the visit. A visit is one browsing session, not one page view, so the counter
		// only moves once per session; without this, "ask after 3 visits" would really mean
		// "ask three page loads in". Where sessionStorage is unavailable the count falls back
		// to page views, which only means the prompt waits less.
		var name = settings.push.views + '=';
		var views = 0;
		var counted = false;

		document.cookie.split( ';' ).forEach( function( cookie ) {
			cookie = cookie.trim();

			if ( 0 === cookie.indexOf( name ) ) {
				views = parseInt( cookie.substring( name.length ), 10 ) || 0;
			}
		} );

		try {
			counted = !! window.sessionStorage.getItem( settings.push.views );

			if ( ! counted ) {
				window.sessionStorage.setItem( settings.push.views, '1' );
			}
		} catch ( e ) {
			counted = false;
		}

		if ( ! counted ) {
			views += 1;

			document.cookie = settings.push.views + '=' + views + ';path=/;max-age=31536000;samesite=lax';
		}

		if ( views <= settings.push.delay ) {
			return;
		}

		// Ask on the next click.
		var ask = function() {
			document.removeEventListener( 'click', ask );

			window.Notification.requestPermission().then( function( permission ) {
				if ( 'granted' === permission ) {
					return subscribePush();
				}

				return null;
			} ).catch( function() {} );
		};

		document.addEventListener( 'click', ask );
	}

	/**
	 * Opens the Write a Review window when a booking-completed link lands here.
	 *
	 * Core binds the real trigger, so clicking it inherits exactly what a person clicking the
	 * sidebar link gets. When the modal isn't on the page, because the person can't review,
	 * nothing happens and they simply see the listing.
	 */
	function initReviewDeepLink() {
		if ( '#hp-review' !== window.location.hash ) {
			return;
		}

		if ( ! document.getElementById( 'review_submit_modal' ) ) {
			return;
		}

		var link = document.querySelector( 'a[href="#review_submit_modal"]' );

		if ( ! link ) {
			return;
		}

		var open = function() {
			window.setTimeout( function() {
				link.click();
			}, 150 );
		};

		if ( 'complete' === document.readyState ) {
			open();
		} else {
			window.addEventListener( 'load', open );
		}
	}

	/**
	 * Wires the push button on the settings page.
	 *
	 * The button is server-rendered hidden and only revealed where the browser supports push,
	 * so nobody is offered a switch that can't work.
	 */
	function initPushButton() {
		var box = document.querySelector( '[data-component="notification-push-setup"]' );

		if ( ! box || ! box.querySelector( 'button' ) ) {
			return;
		}

		if ( ! settings.push || ! ( 'serviceWorker' in window.navigator ) || ! ( 'PushManager' in window ) || ! ( 'Notification' in window ) ) {
			box.remove();

			return;
		}

		var button = box.querySelector( 'button' );
		var status = box.querySelector( '.hp-notification-push__status' );

		function show( state ) {
			box.hidden = false;

			// A hidden button keeps whatever label it had, and "Waiting for your browser…" left
			// behind on a button that has done its job reads as the wrong state to anything going
			// by button text - a tester, a screen reader walking hidden content, or a script. The
			// label goes back to the offer it represents whenever the button leaves the screen.
			if ( 'enabled' === state ) {
				button.hidden      = true;
				button.textContent = box.dataset.labelEnable;
				status.textContent = box.dataset.labelEnabled;
			} else if ( 'blocked' === state ) {
				button.hidden      = true;
				button.textContent = box.dataset.labelEnable;
				status.textContent = box.dataset.labelBlocked;
			} else {
				button.hidden      = false;
				button.disabled    = false;
				button.textContent = box.dataset.labelEnable;
				status.textContent = '';
			}
		}

		/**
		 * Sets the state from what the browser actually reports, rather than from what the last
		 * step happened to return.
		 */
		function refresh() {
			if ( 'denied' === window.Notification.permission ) {
				show( 'blocked' );

				return;
			}

			if ( 'granted' !== window.Notification.permission ) {
				show( 'default' );

				return;
			}

			// Someone who has already said yes might still lack a subscription on this device, so
			// the button stays available until one actually exists.
			window.navigator.serviceWorker.getRegistration( '/' ).then( function( registration ) {
				return registration ? registration.pushManager.getSubscription() : null;
			} ).then( function( subscription ) {
				show( subscription ? 'enabled' : 'default' );
			} ).catch( function() {
				show( 'default' );
			} );
		}

		refresh();

		button.addEventListener( 'click', function() {
			button.disabled    = true;
			button.textContent = box.dataset.labelWorking;

			// However this ends, the button ends up showing the state the browser is really in.
			// Reporting success straight from the promise chain meant that a step which neither
			// resolved nor rejected left the button on its working label for good: a blocked
			// IndexedDB upgrade did exactly that, and the button still read "Waiting for your
			// browser" eight minutes after the subscription had in fact succeeded.
			var settled = false;

			var settle = function() {
				if ( settled ) {
					return;
				}

				settled = true;

				refresh();
			};

			window.Notification.requestPermission().then( function( permission ) {
				if ( 'granted' !== permission ) {
					return null;
				}

				// Only the machine steps get a deadline. The prompt itself has none, because how
				// long someone takes to answer it is their business.
				window.setTimeout( settle, 15000 );

				return subscribePush();
			} ).then( settle, settle );
		} );
	}

	/**
	 * Binds the notification list controls.
	 */
	function bindList() {
		var list = document.querySelector( '[data-component="notifications-list"]' );

		if ( ! list ) {
			return;
		}

		// Submit the filter on change, and hide the button that scripting makes redundant.
		var filter = list.querySelector( '[data-component="notifications-filter"]' );

		if ( filter ) {
			filter.addEventListener( 'change', function() {
				filter.form.submit();
			} );
		}

		/**
		 * Points a row's tick at whatever it will do next.
		 *
		 * The icon was being flipped without the label, so a read row still announced itself as
		 * "Mark as read" while clicking it marked the row unread. A screen reader had no way to
		 * tell, and it misled a sighted tester too.
		 *
		 * @param {Element} toggle Toggle button.
		 * @param {boolean} read Whether the row is now read.
		 */
		function setToggleState( toggle, read ) {
			var label = read ? settings.markUnreadText : settings.readText;

			toggle.setAttribute( 'aria-label', label );
			toggle.setAttribute( 'title', label );
			swapIcon( toggle.querySelector( 'i' ), read ? 'envelope' : 'check' );
		}

		// Mark everything as read.
		var readAll = list.querySelector( '[data-component="notifications-read-all"]' );

		if ( readAll ) {
			readAll.addEventListener( 'click', function() {
				// Asked first: it touches every notification the person has, not just the page.
				if ( ! window.confirm( settings.confirmAll ) ) {
					return;
				}

				readAll.disabled = true;

				request( '/notifications/read', {} ).then( function( response ) {
					setBadge( response.data.unread );

					list.querySelectorAll( '.hp-notification--unread' ).forEach( function( item ) {
						item.classList.remove( 'hp-notification--unread' );
					} );

					list.querySelectorAll( '.hp-notification__toggle' ).forEach( function( toggle ) {
						setToggleState( toggle, true );
					} );

					readAll.disabled = false;
				} ).catch( function() {
					readAll.disabled = false;
				} );
			} );
		}

		// Clear everything that's been read. The page reloads afterwards so the grouping and the
		// page count stay honest.
		var clearRead = list.querySelector( '[data-component="notifications-delete-read"]' );

		if ( clearRead ) {
			clearRead.addEventListener( 'click', function() {
				// Asked first: there is no Undo for a bulk delete, unlike the single-row cross.
				if ( ! window.confirm( settings.confirmClear ) ) {
					return;
				}

				clearRead.disabled = true;

				request( '/notifications/delete', { read: 1 } ).then( function() {
					window.location.reload();
				} ).catch( function() {
					clearRead.disabled = false;
				} );
			} );
		}

		// Select rows and clear them together. The state lives in the boxes themselves: Clear
		// selected shows while any is ticked, and Select all follows the rows (indeterminate for
		// a partial selection). Rows built for arriving notifications carry the same box, and the
		// list binds them by delegation like every other row control.
		var selectAll = list.querySelector( '[data-component="notifications-select-all"]' );
		var clearSelected = list.querySelector( '[data-component="notifications-delete-selected"]' );

		function selectBoxes() {
			return Array.prototype.slice.call( list.querySelectorAll( '[data-component="notification-select"]' ) ).filter( function( box ) {
				// A row hidden by a pending single delete is not on offer.
				return box.closest( '.hp-notification' ) && 'none' !== box.closest( '.hp-notification' ).style.display;
			} );
		}

		function syncSelection() {
			var boxes = selectBoxes();
			var ticked = boxes.filter( function( box ) {
				return box.checked;
			} );

			if ( clearSelected ) {
				clearSelected.hidden = ! ticked.length;
			}

			if ( selectAll ) {
				selectAll.checked = boxes.length > 0 && ticked.length === boxes.length;
				selectAll.indeterminate = ticked.length > 0 && ticked.length < boxes.length;

				var wrapper = selectAll.closest( 'label' );

				if ( wrapper ) {
					wrapper.hidden = ! boxes.length;
				}
			}
		}

		if ( selectAll ) {
			selectAll.addEventListener( 'change', function() {
				selectBoxes().forEach( function( box ) {
					box.checked = selectAll.checked;
				} );

				syncSelection();
			} );
		}

		list.addEventListener( 'change', function( event ) {
			if ( event.target && event.target.matches && event.target.matches( '[data-component="notification-select"]' ) ) {
				syncSelection();
			}
		} );

		if ( clearSelected ) {
			clearSelected.addEventListener( 'click', function() {
				var ids = selectBoxes().filter( function( box ) {
					return box.checked;
				} ).map( function( box ) {
					return parseInt( box.value, 10 );
				} ).filter( function( id ) {
					return id > 0;
				} );

				if ( ! ids.length || ! window.confirm( settings.confirmPicked ) ) {
					return;
				}

				clearSelected.disabled = true;

				// The page reloads afterwards, as Clear read does, so the grouping and the page
				// count stay honest.
				request( '/notifications/delete', { ids: ids } ).then( function() {
					window.location.reload();
				} ).catch( function() {
					clearSelected.disabled = false;
				} );
			} );
		}

		syncSelection();

		list.addEventListener( 'click', function( event ) {

			// Delete a notification.
			var remove = event.target.closest( '[data-component="notification-delete"]' );

			if ( remove ) {
				var item = remove.closest( '.hp-notification' );
				var id = parseInt( item.getAttribute( 'data-id' ), 10 );

				// One deletion at a time per row. Two taps used to send two requests and build two
				// Undo chips: the user clicks Undo on the one they can see, the row comes back, and
				// the other chip's eight-second timer still runs item.remove() - so the row they
				// just restored vanishes again while the notification is alive on the server.
				if ( item.classList.contains( 'hp-notification--removing' ) ) {
					return;
				}

				item.classList.add( 'hp-notification--removing' );

				request( '/notifications/delete', { ids: [ id ] } ).then( function( response ) {
					setBadge( response.data.unread );

					// The row hides rather than disappears, so Undo can bring it straight
					// back. After eight seconds the deletion is accepted and the row goes.
					item.classList.remove( 'hp-notification--removing' );
					item.style.display = 'none';

					var chip = document.createElement( 'div' );
					chip.className = 'hp-notification-undo';

					var label = document.createElement( 'span' );
					label.textContent = settings.deletedText;

					var undo = document.createElement( 'button' );
					undo.type = 'button';

					// The theme's own button classes, so Undo matches every other button on the
					// page rather than being styled by us.
					undo.className = 'hp-button button button--secondary';
					undo.textContent = settings.undoText;

					chip.appendChild( label );
					chip.appendChild( undo );
					item.parentNode.insertBefore( chip, item.nextSibling );

					var timer = window.setTimeout( function() {
						chip.remove();
						item.remove();
					}, 8000 );

					undo.addEventListener( 'click', function() {
						window.clearTimeout( timer );
						undo.disabled = true;

						request( '/notifications/restore', { ids: [ id ] } ).then( function( restored ) {
							setBadge( restored.data.unread );

							item.style.display = '';
							chip.remove();
						} ).catch( function() {
							undo.disabled = false;
						} );
					} );
				} ).catch( function() {
					item.classList.remove( 'hp-notification--removing' );
				} );

				return;
			}

			// Flip a notification between read and unread.
			var toggle = event.target.closest( '[data-component="notification-toggle"]' );

			if ( toggle ) {
				var row = toggle.closest( '.hp-notification' );
				var rowId = parseInt( row.getAttribute( 'data-id' ), 10 );
				var unread = row.classList.contains( 'hp-notification--unread' );

				toggle.disabled = true;

				request( '/notifications/read', {
					ids: [ rowId ],
					read: unread ? 1 : 0
				} ).then( function( response ) {
					setBadge( response.data.unread );

					row.classList.toggle( 'hp-notification--unread', ! unread );
					setToggleState( toggle, unread );
					toggle.disabled = false;
				} ).catch( function() {
					toggle.disabled = false;
				} );

				return;
			}

			// Mark a notification as read and count the click when it's followed.
			var link = event.target.closest( '.hp-notification__text, .hp-notification__link' );

			if ( link && 'A' === link.tagName ) {
				var parent = link.closest( '.hp-notification' );

				if ( parent ) {
					Toasts.markRead( parseInt( parent.getAttribute( 'data-id' ), 10 ), true );
				}
			}
		} );
	}

	/**
	 * Folds each notification group on the settings page behind its own heading.
	 *
	 * The Notification Settings form is one checkbox group per source - Listings, Bookings,
	 * Messages and so on - and with a dozen sources the page was a wall of tick boxes with the
	 * quiet hours and the save button somewhere below it. Each group collapses to its heading
	 * with a ticked-count, so the page reads as a list of areas and only the one being changed
	 * is open. Everything stays in the DOM, merely hidden, so the form still posts every value
	 * and nothing changes server-side.
	 *
	 * Without scripting the form renders fully expanded, which is the right fallback.
	 */
	function initSettingsCollapse() {
		var form = document.querySelector( 'form[action*="/notifications/preferences"]' );

		if ( ! form ) {
			return;
		}

		Array.prototype.forEach.call( form.querySelectorAll( '.hp-form__field--checkboxes' ), function( field ) {
			var label = field.querySelector( '.hp-field__label' );

			if ( ! label ) {
				return;
			}

			// Everything after the heading folds: the description where a group has one, and the
			// checkbox list itself.
			var parts = [];
			var node = label.nextElementSibling;

			while ( node ) {
				parts.push( node );

				node = node.nextElementSibling;
			}

			var boxes = field.querySelectorAll( 'input[type="checkbox"]' );

			if ( ! parts.length || ! boxes.length ) {
				return;
			}

			field.classList.add( 'hp-nfh-fold' );

			// "3/4" beside the heading, so a closed group still says how much of it is on. Digits
			// only, deliberately: a worded phrase here would need translating, and the count is
			// legible in any language as it stands.
			var count = document.createElement( 'span' );
			count.className = 'hp-nfh-fold__count';

			var chevron = makeIcon( 'chevron-down', 'hp-nfh-fold__chevron' );

			label.appendChild( count );
			label.appendChild( chevron );

			// The label is not bound to an input (no "for"), so making it the toggle takes nothing
			// away; role and tabindex give keyboards and screen readers the same control.
			label.setAttribute( 'role', 'button' );
			label.setAttribute( 'tabindex', '0' );

			function recount() {
				count.textContent = field.querySelectorAll( 'input[type="checkbox"]:checked' ).length + '/' + boxes.length;
			}

			function setOpen( open ) {
				label.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				field.classList.toggle( 'hp-nfh-fold--open', open );

				parts.forEach( function( part ) {
					part.hidden = ! open;
				} );
			}

			recount();
			setOpen( false );

			label.addEventListener( 'click', function() {
				setOpen( 'false' === label.getAttribute( 'aria-expanded' ) );
			} );

			label.addEventListener( 'keydown', function( event ) {
				if ( 'Enter' === event.key || ' ' === event.key ) {
					event.preventDefault();

					label.click();
				}
			} );

			field.addEventListener( 'change', recount );
		} );
	}

	/**
	 * Starts once the document is ready.
	 */
	function init() {
		if ( ! settings || ! settings.apiURL || ! window.fetch ) {
			return;
		}

		bindList();
		Bells.init();
		initPush();
		initPushButton();
		initReviewDeepLink();
		initSettingsCollapse();

		if ( settings.sound ) {
			document.addEventListener( 'pointerdown', function unlock() {
				document.removeEventListener( 'pointerdown', unlock );

				Chime.unlock();
			} );
		}

		// The worker announces every push through the operating system, because a subscription that
		// stays silent gets throttled by the browser. It then says which notification it showed, so
		// the page skips its own pop-up for that one and nothing is announced twice.
		//
		// Everything except the pop-up still has to happen. Bumping the count and adding the row
		// used to ride along with the pop-up render, so skipping the pop-up silently stopped both
		// and the page sat on a stale number - five behind, in one staging run - until it was
		// reloaded. This listener is also outside the pop-ups check below, because a site with
		// pop-ups switched off still has a count to keep honest.
		if ( 'serviceWorker' in window.navigator ) {
			window.navigator.serviceWorker.addEventListener( 'message', function( event ) {
				if ( ! event.data || 'hp-notification-shown' !== event.data.type || ! event.data.id ) {
					return;
				}

				markShown( event.data.id );

				// Reading the queue empties it, so a hidden tab takes the count only and leaves its
				// pop-ups waiting for it to come back.
				if ( 'visible' === document.visibilityState ) {
					checkQueue();
				} else {
					queueDeferred = true;

					refreshCount();
				}
			} );
		}

		if ( ! settings.toasts ) {
			return;
		}

		// Flush a deferred queue whenever the tab comes back, whatever the poll setting. Polling
		// adds its own interval on top; without this listener a page opened in a background tab
		// with polling switched off would never show its pop-ups at all.
		document.addEventListener( 'visibilitychange', function() {
			if ( 'visible' === document.visibilityState && queueDeferred ) {
				checkQueue();
			}
		} );

		// Wait for an idle moment so the pop-up request never competes with rendering.
		if ( window.requestIdleCallback ) {
			window.requestIdleCallback( checkQueue, { timeout: 3000 } );
		} else {
			window.setTimeout( checkQueue, 800 );
		}

		startPolling();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
