/**
 * Notifications for HivePress service worker.
 *
 * The push itself carries no payload, so the worker asks the site what happened. That needs a token
 * rather than a cookie: a push wakes this worker when no page is open, and WordPress ignores a REST
 * cookie without a nonce, which only exists while a page is open. The token is stored by the page
 * when the browser subscribes.
 */
/* global hpSettings */

( function() {
	'use strict';

var HP_DB = 'hp-notifications';
var HP_STORE = 'settings';

// Take over immediately on update. By default a new worker waits for every tab on the site to
// close before activating, so a fix shipped in a plugin update could sit unused for days on the
// machines of the people who keep the site open.
self.addEventListener( 'install', function() {
	self.skipWaiting();
} );

self.addEventListener( 'activate', function( event ) {
	event.waitUntil( self.clients.claim() );
} );

/**
 * Opens the token store.
 *
 * @return {Promise}
 */
function hpOpen() {
	return new Promise( function( resolve, reject ) {
		var request = indexedDB.open( HP_DB, 1 );

		request.onupgradeneeded = function() {
			request.result.createObjectStore( HP_STORE );
		};

		request.onsuccess = function() {
			resolve( request.result );
		};

		request.onerror = function() {
			reject( request.error );
		};
	} );
}

/**
 * Reads the token.
 *
 * @return {Promise}
 */
function hpGetToken() {
	return hpOpen().then( function( db ) {
		return new Promise( function( resolve, reject ) {
			var request = db.transaction( HP_STORE, 'readonly' ).objectStore( HP_STORE ).get( 'token' );

			request.onsuccess = function() {
				resolve( request.result );
			};

			request.onerror = function() {
				reject( request.error );
			};
		} );
	} );
}

/**
 * Shows a notification.
 *
 * @param {Object} data Notification data.
 * @return {Promise}
 */
function hpShow( data ) {

	// The notification's own picture wins where it has one, so a badge award shows the badge and a
	// message shows the sender rather than the site icon for everything. Only an http(s) address is
	// used, and the site icon remains the fallback and the small monochrome badge either way.
	var image = data && data.image && /^https?:\/\//i.test( data.image ) ? data.image : '';

	// A title the admin wrote for this notification type wins; otherwise the site name stays, which
	// is what identifies the sender on an operating-system notification.
	var title = data && data.title ? data.title : hpSettings.title;

	return self.registration.showNotification( title, {
		body: data && data.text ? data.text : hpSettings.text,
		icon: image || hpSettings.icon || undefined,
		badge: hpSettings.icon || undefined,
		tag: data && data.id ? 'hp-notification-' + data.id : 'hp-notification',
		data: { url: data && data.url ? data.url : '' }
	} );
}

/**
 * Shows the notification and tells any open tab that it has been shown.
 *
 * The same notification used to be announced twice when the site was already on screen: once by
 * the operating system and again as a pop-up from the page. The fix is deliberately this way round
 * rather than skipping the notification when a tab is visible, which is what an earlier build did.
 * A subscription made with userVisibleOnly must show something for every push, and a browser that
 * sees pushes handled silently first warns the user that the site updated in the background and
 * then throttles delivery to the origin - which presented as push working with a visible tab and
 * then going completely dead once backgrounded. So the notification is always shown, and the page
 * is told to skip its own pop-up for that notification instead.
 *
 * @param {Object} data Notification data.
 * @return {Promise}
 */
function hpAnnounce( data ) {
	return hpShow( data ).then( function() {
		return self.clients.matchAll( { type: 'window', includeUncontrolled: true } ).then( function( list ) {
			list.forEach( function( client ) {
				client.postMessage( {
					type: 'hp-notification-shown',
					id: data && data.id ? data.id : 0
				} );
			} );
		} );
	} ).catch( function() {} );
}

/**
 * Fetches the newest notification and shows it.
 *
 * @return {Promise}
 */
function hpFetchAndShow() {

	// A browser that wakes the worker expects a notification to appear. If the fetch fails, a
	// plain one is shown rather than none, because otherwise the browser shows its own
	// "site updated in the background" message instead.
	return hpGetToken().then( function( token ) {
		if ( ! token ) {
			return hpAnnounce( null );
		}

		// The API URL already carries a query string on sites without pretty permalinks
		// ("?rest_route=..."), so the token joins with the right separator either way.
		return fetch( hpSettings.api + ( -1 === hpSettings.api.indexOf( '?' ) ? '?' : '&' ) + 'token=' + encodeURIComponent( token ), { cache: 'no-store' } )
			.then( function( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function( body ) {
				return hpAnnounce( body && body.data ? body.data : null );
			} );
	} ).catch( function() {
		return hpAnnounce( null );
	} );
}

self.addEventListener( 'push', function( event ) {
	event.waitUntil( hpFetchAndShow() );
} );

self.addEventListener( 'notificationclick', function( event ) {
	event.notification.close();

	var url = event.notification.data && event.notification.data.url;

	// Only follow absolute http(s) links. Notification URLs are always full permalinks, so this
	// drops anything with another scheme rather than handing it to openWindow.
	if ( ! url || ! /^https?:\/\//i.test( url ) ) {
		return;
	}

	// Focus a tab that's already on the page rather than opening another one.
	event.waitUntil(
		clients.matchAll( { type: 'window', includeUncontrolled: true } ).then( function( list ) {
			for ( var i = 0; i < list.length; i++ ) {
				if ( list[ i ].url === url && 'focus' in list[ i ] ) {
					return list[ i ].focus();
				}
			}

			return clients.openWindow ? clients.openWindow( url ) : null;
		} )
	);
} );
}() );
