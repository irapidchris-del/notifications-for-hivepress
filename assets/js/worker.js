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
	return self.registration.showNotification( hpSettings.title, {
		body: data && data.text ? data.text : hpSettings.text,
		icon: hpSettings.icon || undefined,
		badge: hpSettings.icon || undefined,
		tag: data && data.id ? 'hp-notification-' + data.id : 'hp-notification',
		data: { url: data && data.url ? data.url : '' }
	} );
}

self.addEventListener( 'push', function( event ) {

	// A browser that wakes the worker expects a notification to appear. If the fetch fails, a
	// plain one is shown rather than none, because otherwise the browser shows its own
	// "site updated in the background" message instead.
	event.waitUntil(
		hpGetToken().then( function( token ) {
			if ( ! token ) {
				return hpShow( null );
			}

			return fetch( hpSettings.api + '?token=' + encodeURIComponent( token ), { cache: 'no-store' } )
				.then( function( response ) {
					return response.ok ? response.json() : null;
				} )
				.then( function( body ) {
					return hpShow( body && body.data ? body.data : null );
				} );
		} ).catch( function() {
			return hpShow( null );
		} )
	);
} );

self.addEventListener( 'notificationclick', function( event ) {
	event.notification.close();

	var url = event.notification.data && event.notification.data.url;

	if ( ! url ) {
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
