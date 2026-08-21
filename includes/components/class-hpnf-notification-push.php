<?php
/**
 * Notification push component.
 *
 * @package HivePress\Notifications\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sends web push notifications.
 *
 * Pushes are sent without a payload. The browser wakes the service worker, which then asks the site
 * what happened. That avoids implementing the payload encryption the Web Push protocol requires
 * (ECDH, HKDF and AES-GCM), which is a lot of cryptography to get wrong for no gain here, since the
 * service worker can fetch the notification over the session it already has.
 */
final class Hpnf_Notification_Push extends Component {

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Serve the service worker.
		add_action( 'init', [ $this, 'serve_worker' ], 1 );

		// Queue pushes.
		add_action( 'hivepress/v1/notification_add', [ $this, 'send_push' ], 10, 1 );

		// Send the queued pushes.
		add_action( 'hivepress/v1/notifications/push', [ $this, 'send_pushes' ], 10, 1 );

		parent::__construct( $args );
	}

	/**
	 * Checks whether web push is set up and switched on.
	 *
	 * @return bool
	 */
	public function is_enabled() {

		// Push is on unless it's been switched off. A saved "off" is stored as an empty value, so
		// only a never-saved option falls back to the default.
		if ( ! get_option( 'hp_notification_push', true ) ) {
			return false;
		}

		return (bool) $this->get_keys();
	}

	/**
	 * Gets the VAPID key pair, generating it once on first use.
	 *
	 * @return array
	 */
	public function get_keys() {
		$keys = get_option( 'hp_notification_push_keys' );

		if ( is_array( $keys ) && ! empty( $keys['public'] ) && ! empty( $keys['private'] ) ) {
			return $keys;
		}

		return $this->generate_keys();
	}

	/**
	 * Generates a VAPID key pair.
	 *
	 * @return array
	 */
	public function generate_keys() {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return [];
		}

		$args = [
			'curve_name'       => 'prime256v1',
			'private_key_type' => OPENSSL_KEYTYPE_EC,
		];

		// Generate key. A host without a usable system openssl.cnf - common on Windows and in
		// minimal containers - fails here outright, so the plugin's bundled minimal configuration
		// is retried before giving up. It changes nothing about the keys themselves.
		$resource = openssl_pkey_new( $args );

		if ( ! $resource ) {
			$args['config'] = plugin_dir_path( HP_NOTIFICATIONS_FILE ) . 'assets/openssl.cnf';

			$resource = openssl_pkey_new( $args );
		}

		if ( ! $resource ) {
			return [];
		}

		// Get details.
		$details = openssl_pkey_get_details( $resource );

		if ( ! $details || ! isset( $details['ec']['x'], $details['ec']['y'] ) ) {
			return [];
		}

		// Export the private key, with the same configuration fallback the generation used.
		$private = '';

		if ( ! openssl_pkey_export( $resource, $private, null, isset( $args['config'] ) ? [ 'config' => $args['config'] ] : null ) ) {
			return [];
		}

		// The public key goes over the wire as an uncompressed point: a 0x04 marker followed by the
		// X and Y coordinates, each padded to 32 bytes.
		$public = "\x04" . str_pad( $details['ec']['x'], 32, "\x00", STR_PAD_LEFT ) . str_pad( $details['ec']['y'], 32, "\x00", STR_PAD_LEFT );

		$keys = [
			'public'  => $this->encode( $public ),
			'private' => $private,
		];

		update_option( 'hp_notification_push_keys', $keys, false );

		return $keys;
	}

	/**
	 * Builds a signed VAPID token for a push service.
	 *
	 * @param string $audience Push service origin.
	 * @return string
	 */
	public function get_token( $audience ) {
		$keys = $this->get_keys();

		if ( ! $keys ) {
			return '';
		}

		// Get the signing input.
		$header = $this->encode(
			wp_json_encode(
				[
					'typ' => 'JWT',
					'alg' => 'ES256',
				]
			)
		);

		$payload = $this->encode(
			wp_json_encode(
				[
					'aud' => $audience,
					'exp' => time() + 12 * HOUR_IN_SECONDS,
					'sub' => 'mailto:' . get_option( 'admin_email' ),
				]
			)
		);

		$input = $header . '.' . $payload;

		// Sign the input.
		$signature = '';

		if ( ! openssl_sign( $input, $signature, $keys['private'], OPENSSL_ALGO_SHA256 ) ) {
			return '';
		}

		// OpenSSL signs into DER, but the token needs the raw pair.
		$signature = $this->convert_signature( $signature );

		if ( ! $signature ) {
			return '';
		}

		return $input . '.' . $this->encode( $signature );
	}

	/**
	 * Converts a DER encoded signature into the raw form a JWT needs.
	 *
	 * OpenSSL returns a DER sequence of two integers, while ES256 wants the two 32 byte values one
	 * after the other. DER integers are signed, so a value whose top bit is set carries a leading
	 * zero byte that has to come off, and a short value has to be padded back up to 32.
	 *
	 * @param string $signature DER encoded signature.
	 * @return string
	 */
	protected function convert_signature( $signature ) {
		$length = strlen( $signature );

		if ( $length < 8 || "\x30" !== $signature[0] ) {
			return '';
		}

		$offset = 2;

		// Skip the long form length bytes, if any.
		if ( ord( $signature[1] ) > 0x80 ) {
			$offset += ord( $signature[1] ) - 0x80;
		}

		// Read both integers.
		$parts = [];

		for ( $i = 0; $i < 2; $i++ ) {
			if ( $offset + 2 > $length || "\x02" !== $signature[ $offset ] ) {
				return '';
			}

			++$offset;

			$size = ord( $signature[ $offset ] );

			++$offset;

			if ( $offset + $size > $length ) {
				return '';
			}

			$part = ltrim( substr( $signature, $offset, $size ), "\x00" );

			if ( strlen( $part ) > 32 ) {
				return '';
			}

			$parts[] = str_pad( $part, 32, "\x00", STR_PAD_LEFT );

			$offset += $size;
		}

		return implode( '', $parts );
	}

	/**
	 * Encodes a value for use in a token.
	 *
	 * @param string $value Value to encode.
	 * @return string
	 */
	protected function encode( $value ) {
		// Base64url is what the VAPID specification requires for keys and tokens (RFC 8292);
		// nothing is being obfuscated.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Gets the push subscriptions of a user.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function get_subscriptions( $user_id ) {
		$subscriptions = get_user_meta( $user_id, 'hp_notification_push', true );

		return is_array( $subscriptions ) ? $subscriptions : [];
	}

	/**
	 * Adds a push subscription.
	 *
	 * @param int   $user_id User ID.
	 * @param array $subscription Subscription arguments.
	 * @return string Token for the service worker, or an empty string on failure.
	 */
	public function add_subscription( $user_id, $subscription ) {
		$endpoint = hp\get_array_value( $subscription, 'endpoint' );

		if ( ! is_string( $endpoint ) || ! filter_var( $endpoint, FILTER_VALIDATE_URL ) || 'https' !== wp_parse_url( $endpoint, PHP_URL_SCHEME ) ) {
			return '';
		}

		// Get subscriptions.
		$subscriptions = [];

		foreach ( $this->get_subscriptions( $user_id ) as $existing ) {
			if ( hp\get_array_value( $existing, 'endpoint' ) !== $endpoint ) {
				$subscriptions[] = $existing;
			}
		}

		// Get a token for the service worker. A push wakes the worker when no page is open, and a
		// REST nonce only exists while a page is open, so cookie authentication can't be used. The
		// token names the user up front, so reading it back needs no lookup, and only the hash is
		// kept, so the stored copy is useless on its own.
		$secret = wp_generate_password( 32, false );

		$subscriptions[] = [
			'endpoint' => esc_url_raw( $endpoint ),
			'secret'   => hash( 'sha256', $secret ),
			'added'    => time(),
		];

		// A browser can hold several subscriptions per user, but not many, so the oldest go.
		update_user_meta( $user_id, 'hp_notification_push', array_slice( $subscriptions, -10 ) );

		return $user_id . '.' . $secret;
	}

	/**
	 * Gets the user a service worker token belongs to.
	 *
	 * @param string $token Subscription token.
	 * @return int
	 */
	public function get_token_user( $token ) {
		if ( ! is_string( $token ) || strpos( $token, '.' ) === false ) {
			return 0;
		}

		list( $user_id, $secret ) = explode( '.', $token, 2 );

		$user_id = absint( $user_id );

		if ( ! $user_id || ! $secret ) {
			return 0;
		}

		// Check the secret.
		$secret = hash( 'sha256', $secret );

		foreach ( $this->get_subscriptions( $user_id ) as $subscription ) {
			if ( hash_equals( (string) hp\get_array_value( $subscription, 'secret' ), $secret ) ) {
				return $user_id;
			}
		}

		return 0;
	}

	/**
	 * Removes a push subscription.
	 *
	 * @param int    $user_id User ID.
	 * @param string $endpoint Subscription endpoint.
	 */
	public function delete_subscription( $user_id, $endpoint ) {
		$subscriptions = [];

		foreach ( $this->get_subscriptions( $user_id ) as $subscription ) {
			if ( hp\get_array_value( $subscription, 'endpoint' ) !== $endpoint ) {
				$subscriptions[] = $subscription;
			}
		}

		if ( $subscriptions ) {
			update_user_meta( $user_id, 'hp_notification_push', $subscriptions );
		} else {
			delete_user_meta( $user_id, 'hp_notification_push' );
		}
	}

	/**
	 * Queues the pushes when a notification is added.
	 *
	 * Nothing is sent from here. A notification is added on paths a visitor causes - a message
	 * sent, a listing reviewed, a booking made - and a push service is a third party, so the round
	 * trip would be held inside that request, once per subscription and up to ten of them. Every
	 * decision below is made from local data; the outbound calls happen moments later in a
	 * background request.
	 *
	 * @param object $notification Notification object.
	 */
	public function send_push( $notification ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Get user ID.
		$user_id = $notification->get_user__id();

		// The type is known only here, so whether this notification is a push at all is settled
		// now. The queued job carries the user alone and re-checks everything that can still
		// change in the gap.
		if ( ! in_array( 'push', hivepress()->hpnf_notification->get_user_channels( $user_id, $notification->get_type() ), true ) ) {
			return;
		}

		if ( hivepress()->hpnf_notification->is_quiet( $user_id ) ) {
			return;
		}

		// Get subscriptions.
		if ( ! $this->get_subscriptions( $user_id ) ) {
			return;
		}

		// Assign before testing. Core defines __get() but no __isset(), so isset() on a component
		// is always false and a guard written that way would disable push everywhere.
		$scheduler = hivepress()->scheduler;

		if ( ! $scheduler ) {
			return;
		}

		// No time and no interval is an async action: a background request moments later. The user
		// ID is the only argument, so a burst for one user coalesces into a single wake - the
		// scheduler drops an action whose hook and arguments are already queued, and that is the
		// behaviour wanted here, since the push carries no payload and the worker fetches the
		// latest notification when it wakes. A notification arriving in the narrow window while
		// that job is already running gets no wake of its own; it is carried by the next push, and
		// it is in the list and the badge either way.
		$scheduler->add_action( 'hivepress/v1/notifications/push', [ $user_id ] );
	}

	/**
	 * Sends the queued pushes of a user.
	 *
	 * @param int $user_id User ID.
	 */
	public function send_pushes( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		// Re-read the inputs and re-check the settings here rather than trusting what was true when
		// this was queued: push may have been switched off, the quiet hours may have started, the
		// browser may have unsubscribed, and the queue may retry.
		if ( ! $this->is_enabled() || hivepress()->hpnf_notification->is_quiet( $user_id ) ) {
			return;
		}

		foreach ( $this->get_subscriptions( $user_id ) as $subscription ) {
			$endpoint = hp\get_array_value( $subscription, 'endpoint' );

			if ( $endpoint ) {
				$this->send_request( $user_id, $endpoint );
			}
		}
	}

	/**
	 * Sends a push request to a push service.
	 *
	 * Only ever called from the scheduler, never from a visitor's request, which is what makes it
	 * safe for this to block and read the result.
	 *
	 * @param int    $user_id User ID.
	 * @param string $endpoint Subscription endpoint.
	 */
	protected function send_request( $user_id, $endpoint ) {

		// Get the audience, which is the origin of the push service and nothing more.
		$parts = wp_parse_url( $endpoint );

		if ( ! isset( $parts['scheme'], $parts['host'] ) ) {
			return;
		}

		$audience = $parts['scheme'] . '://' . $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$audience .= ':' . $parts['port'];
		}

		// Get token.
		$token = $this->get_token( $audience );

		if ( ! $token ) {
			return;
		}

		// Send request. This blocks: the response is the only thing that ever says a subscription is
		// dead, and 'blocking' => false discards it without buying an early return, because the
		// cURL transport runs curl_exec() either way. The endpoint is user-supplied, so unsafe URLs
		// are rejected: a real push service is always a public host, and this stops a crafted
		// subscription from making the server call something internal.
		$response = wp_remote_post(
			$endpoint,
			[
				'timeout'            => 5,
				'reject_unsafe_urls' => true,

				// Same reason as the updater, and it matters more here. WordPress's default
				// User-Agent is "WordPress/{version}; {site url}", and this request goes to Google,
				// Mozilla or Microsoft once per push rather than twice a day, so the default would
				// hand the site's address and WordPress version to a third party on every
				// notification. The push itself carries no payload; the header should carry no site
				// either.
				'user-agent'         => 'notifications-for-hivepress/' . HP_NOTIFICATIONS_VERSION,

				'headers'            => [
					'Authorization'  => 'vapid t=' . $token . ', k=' . $this->get_keys()['public'],
					'TTL'            => 86400,
					'Content-Length' => '0',
					'Urgency'        => 'normal',
				],
			]
		);

		// A push service having a bad minute is not a dead subscription, so a transport error is
		// left alone and the endpoint is tried again next time.
		if ( is_wp_error( $response ) ) {
			return;
		}

		// A gone or not found response means the browser dropped the subscription (RFC 8030), and it
		// is the only notice ever given, so the endpoint is removed rather than retried on every
		// notification for the life of the account. Every other status is left in place. The HTTP
		// API casts the code to an integer, so the strict comparison is safe.
		if ( in_array( wp_remote_retrieve_response_code( $response ), [ 404, 410 ], true ) ) {
			$this->delete_subscription( $user_id, $endpoint );
		}
	}

	/**
	 * Serves the service worker.
	 *
	 * The worker is served from the site root rather than the plugin directory, because a worker can
	 * only control pages at or below its own path. The query string has no bearing on that, so this
	 * gives it the whole site without a rewrite rule to flush.
	 */
	public function serve_worker() {

		// A public, read-only script asset: nothing is processed and nothing changes state.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['hp_notification_worker'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NoncedRedirect
		nocache_headers();

		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->get_worker();

		exit;
	}

	/**
	 * Gets the service worker source.
	 *
	 * @return string
	 */
	protected function get_worker() {
		$args = [
			'api'   => esc_url_raw( rest_url( 'hivepress/v1/notifications/latest' ) ),
			'icon'  => esc_url_raw( (string) get_site_icon_url( 192 ) ),
			'title' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'text'  => esc_html__( 'You have a new notification.', 'notifications-for-hivepress' ),
		];

		return 'var hpSettings = ' . wp_json_encode( $args ) . ";\n" . file_get_contents( plugin_dir_path( HP_NOTIFICATIONS_FILE ) . 'assets/js/worker.js' );
	}
}
