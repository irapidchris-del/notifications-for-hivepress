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

	var settings = window.hpNotificationsData;

	// Pop-ups already shown this page view, so polling never repeats one.
	var seen = {};

	// The tab title before any unread count is prefixed to it.
	var baseTitle = document.title.replace( /^\(\d+\)\s+/, '' );

	/**
	 * Sends a request to the HivePress API.
	 *
	 * @param {string} path Endpoint path.
	 * @param {Object} data Request payload.
	 * @param {string} method Request method.
	 * @return {Promise}
	 */
	function request( path, data, method ) {
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

		return window.fetch( settings.apiURL + path, args ).then( function( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Request failed' );
			}

			return response.json();
		} );
	}

	/**
	 * Updates the unread badges in the account menu and the header bell.
	 *
	 * @param {number} count Unread count.
	 */
	function setBadge( count ) {

		// Put the unread count in the tab title, so a backgrounded tab still says so.
		document.title = count ? '(' + count + ') ' + baseTitle : baseTitle;

		[
			document.querySelector( '.hp-menu__item--notifications-view a' ),
			document.querySelector( '.hp-notification-bell__toggle' )
		].forEach( function( item ) {
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

		if ( notification.image ) {
			var image = document.createElement( 'img' );
			image.src = notification.image;
			image.alt = '';
			image.loading = 'lazy';

			visual.appendChild( image );
		} else {
			var icon = document.createElement( 'i' );
			icon.className = 'hp-icon fas fa-' + ( notification.icon || 'bell' );

			visual.appendChild( icon );
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
			if ( seen[ notification.id ] ) {
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
			// happened, so this drops the reader straight into the conversation or booking.
			if ( notification.url ) {
				var link = document.createElement( 'a' );
				link.className = 'hp-notification-toast__link';
				link.href = notification.url;
				link.innerHTML = '<span></span><i class="hp-icon fas fa-chevron-right"></i>';
				link.querySelector( 'span' ).textContent = notification.link_label || settings.viewText;

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
			close.innerHTML = '<i class="hp-icon fas fa-times"></i>';

			close.addEventListener( 'click', function() {
				self.hide( toast );
			} );

			toast.appendChild( close );

			// Add the countdown.
			if ( settings.autohide ) {
				var progress = document.createElement( 'div' );
				progress.className = 'hp-notification-toast__progress';
				progress.style.animationDuration = settings.duration + 's';

				progress.addEventListener( 'animationend', function() {
					self.hide( toast );
				} );

				toast.appendChild( progress );
			}

			this.getContainer().appendChild( toast );

			Chime.play();

			// Trigger the entry transition once the pop-up is in the document.
			window.requestAnimationFrame( function() {
				toast.classList.add( 'hp-notification-toast--visible' );
			} );
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

				if ( self.waiting.length ) {
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
			request( '/notifications/read', {
				ids: [ id ],
				clicked: clicked ? 1 : 0
			} ).then( function( response ) {
				setBadge( response.data.unread );
			} ).catch( function() {} );
		}
	};

	/**
	 * Fetches and shows the pending pop-ups.
	 */
	function checkQueue() {
		request( '/notifications/queue' ).then( function( response ) {
			setBadge( response.data.unread );

			response.data.notifications.forEach( function( notification ) {
				Toasts.add( notification );
			} );
		} ).catch( function() {} );
	}

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

		var last = Date.now();

		window.setInterval( function() {
			if ( 'visible' !== document.visibilityState ) {
				return;
			}

			last = Date.now();

			checkQueue();
		}, interval * 1000 );

		document.addEventListener( 'visibilitychange', function() {
			if ( 'visible' === document.visibilityState && Date.now() - last > interval * 1000 ) {
				last = Date.now();

				checkQueue();
			}
		} );
	}

	/**
	 * Manages the header bell.
	 */
	var Bell = {
		wrap: null,
		toggle: null,
		panel: null,
		loaded: false,

		init: function() {
			var self = this;

			this.wrap = document.querySelector( '[data-component="notification-bell"]' );

			if ( ! this.wrap ) {
				return;
			}

			this.toggle = this.wrap.querySelector( '.hp-notification-bell__toggle' );
			this.panel = this.wrap.querySelector( '.hp-notification-bell__panel' );

			// The bell stays a plain link without scripting, so the panel only takes over here.
			this.toggle.addEventListener( 'click', function( event ) {
				event.preventDefault();

				if ( self.panel.hidden ) {
					self.open();
				} else {
					self.close();
				}
			} );

			document.addEventListener( 'click', function( event ) {
				if ( ! self.panel.hidden && ! self.wrap.contains( event.target ) ) {
					self.close();
				}
			} );

			document.addEventListener( 'keydown', function( event ) {
				if ( 'Escape' === event.key && ! self.panel.hidden ) {
					self.close();
					self.toggle.focus();
				}
			} );
		},

		open: function() {
			this.panel.hidden = false;
			this.toggle.setAttribute( 'aria-expanded', 'true' );

			this.load();
		},

		close: function() {
			this.panel.hidden = true;
			this.toggle.setAttribute( 'aria-expanded', 'false' );
		},

		load: function() {
			var self = this;

			if ( this.loaded ) {
				return;
			}

			request( '/notifications/recent', null, 'GET' ).then( function( response ) {
				self.loaded = true;

				setBadge( response.data.unread );
				self.render( response.data.notifications );

				// Let the next open fetch fresh data after a while.
				window.setTimeout( function() {
					self.loaded = false;
				}, 30000 );
			} ).catch( function() {} );
		},

		render: function( notifications ) {
			var body = this.wrap.querySelector( '[data-component="notification-bell-body"]' );

			body.innerHTML = '';

			if ( ! notifications.length ) {
				var empty = document.createElement( 'div' );
				empty.className = 'hp-notification-bell__empty';
				empty.textContent = settings.emptyText;

				body.appendChild( empty );

				return;
			}

			notifications.forEach( function( notification ) {
				var item = document.createElement( notification.url ? 'a' : 'div' );
				item.className = 'hp-notification-bell__item' + ( notification.read ? '' : ' hp-notification-bell__item--unread' );

				if ( notification.url ) {
					item.href = notification.url;

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
				if ( notification.url ) {
					var go = document.createElement( 'i' );
					go.className = 'hp-icon fas fa-chevron-right hp-notification-bell__go';

					item.appendChild( go );
				}

				// Every unread item gets its own dismiss, so the badge can be cleared from the
				// panel without visiting anything.
				if ( ! notification.read ) {
					var dismiss = document.createElement( 'button' );
					dismiss.type      = 'button';
					dismiss.className = 'hp-notification-bell__dismiss';
					dismiss.setAttribute( 'aria-label', settings.readText );
					dismiss.innerHTML = '<i class="hp-icon fas fa-times"></i>';

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
	 * Registers the worker and subscribes the browser to push.
	 */
	function subscribePush() {
		return window.navigator.serviceWorker.register( settings.push.worker, { scope: '/' } ).then( function( registration ) {
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

		// Count the visit.
		var name = settings.push.views + '=';
		var views = 0;

		document.cookie.split( ';' ).forEach( function( cookie ) {
			cookie = cookie.trim();

			if ( 0 === cookie.indexOf( name ) ) {
				views = parseInt( cookie.substring( name.length ), 10 ) || 0;
			}
		} );

		views += 1;

		document.cookie = settings.push.views + '=' + views + ';path=/;max-age=31536000;samesite=lax';

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
	 * Makes the theme header stick, without a flash.
	 *
	 * The swap to fixed happens at the exact scroll position where the fixed and static
	 * placements coincide, so there is nothing to animate and nothing to see. A placeholder
	 * keeps the page height steady, and the observer costs nothing while idle.
	 */
	function initSticky() {
		if ( ! settings.sticky || ! ( 'IntersectionObserver' in window ) ) {
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

		function offset() {
			var admin = document.getElementById( 'wpadminbar' );

			return admin && 'fixed' === window.getComputedStyle( admin ).position ? admin.offsetHeight : 0;
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
				bar.classList.add( 'hp-nfh-sticky' );
			} else {
				bar.classList.remove( 'hp-nfh-sticky' );
				bar.style.top        = '';
				holder.style.display = 'none';
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

		window.addEventListener( 'resize', function() {
			if ( fixed ) {
				holder.style.height = bar.offsetHeight + 'px';
				bar.style.top       = offset() + 'px';
			}

			observe();
		} );
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

			if ( 'enabled' === state ) {
				button.hidden      = true;
				status.textContent = box.dataset.labelEnabled;
			} else if ( 'blocked' === state ) {
				button.hidden      = true;
				status.textContent = box.dataset.labelBlocked;
			} else {
				button.hidden      = false;
				button.disabled    = false;
				button.textContent = box.dataset.labelEnable;
				status.textContent = '';
			}
		}

		// Someone who has already said yes might still lack a subscription on this device, so
		// the button stays available until one actually exists.
		if ( 'denied' === window.Notification.permission ) {
			show( 'blocked' );
		} else if ( 'granted' === window.Notification.permission ) {
			window.navigator.serviceWorker.getRegistration( '/' ).then( function( registration ) {
				return registration ? registration.pushManager.getSubscription() : null;
			} ).then( function( subscription ) {
				show( subscription ? 'enabled' : 'default' );
			} ).catch( function() {
				show( 'default' );
			} );
		} else {
			show( 'default' );
		}

		button.addEventListener( 'click', function() {
			button.disabled    = true;
			button.textContent = box.dataset.labelWorking;

			window.Notification.requestPermission().then( function( permission ) {
				if ( 'granted' !== permission ) {
					show( 'denied' === permission ? 'blocked' : 'default' );

					return null;
				}

				return subscribePush().then( function() {
					show( 'enabled' );
				} );
			} ).catch( function() {
				show( 'default' );
			} );
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

		// Mark everything as read.
		var readAll = list.querySelector( '[data-component="notifications-read-all"]' );

		if ( readAll ) {
			readAll.addEventListener( 'click', function() {
				readAll.disabled = true;

				request( '/notifications/read', {} ).then( function( response ) {
					setBadge( response.data.unread );

					list.querySelectorAll( '.hp-notification--unread' ).forEach( function( item ) {
						item.classList.remove( 'hp-notification--unread' );
					} );

					list.querySelectorAll( '.hp-notification__toggle i' ).forEach( function( icon ) {
						icon.className = 'hp-icon fas fa-envelope';
					} );

					readAll.remove();
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
				clearRead.disabled = true;

				request( '/notifications/delete', { read: 1 } ).then( function() {
					window.location.reload();
				} ).catch( function() {
					clearRead.disabled = false;
				} );
			} );
		}

		list.addEventListener( 'click', function( event ) {

			// Delete a notification.
			var remove = event.target.closest( '[data-component="notification-delete"]' );

			if ( remove ) {
				var item = remove.closest( '.hp-notification' );
				var id = parseInt( item.getAttribute( 'data-id' ), 10 );

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
					toggle.querySelector( 'i' ).className = 'hp-icon fas fa-' + ( unread ? 'envelope' : 'check' );
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
	 * Starts once the document is ready.
	 */
	function init() {
		if ( ! settings || ! settings.apiURL || ! window.fetch ) {
			return;
		}

		bindList();
		Bell.init();
		initPush();
		initPushButton();
		initReviewDeepLink();
		initSticky();

		if ( settings.sound ) {
			document.addEventListener( 'pointerdown', function unlock() {
				document.removeEventListener( 'pointerdown', unlock );

				Chime.unlock();
			} );
		}

		if ( ! settings.toasts ) {
			return;
		}

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
