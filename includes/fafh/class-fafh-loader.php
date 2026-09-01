<?php
/**
 * FAFH loader: decides which bundled copy of the library actually runs.
 *
 * Every plugin ships an identical `fafh/` folder. Only one copy may define the
 * FAFH class, so the copies register themselves here and the HIGHEST version
 * wins -- not the first one to load, which would otherwise be decided by
 * alphabetical plugin-folder order and could pin an old copy indefinitely.
 *
 * This class is deliberately tiny and must stay backwards compatible, because
 * the first copy included is the one that defines it and a newer copy cannot
 * replace an already-declared class. Put new behaviour in FAFH, not here.
 *
 * @package FAFH
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'FAFH_Loader', false ) ) {
	return;
}

/**
 * Arbitrates between the bundled copies of FAFH.
 */
final class FAFH_Loader {

	/**
	 * Every registered copy, as version => directory.
	 *
	 * @var array
	 */
	private static $copies = [];

	/**
	 * Directory of the copy that won, once resolved.
	 *
	 * @var string|null
	 */
	private static $winner = null;

	/**
	 * Whether the deferred load has been hooked.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Registers one bundled copy.
	 *
	 * @param string $version Copy's library version.
	 * @param string $dir     Absolute path to that copy's fafh/ directory,
	 *                        with no trailing separator (pass __DIR__).
	 */
	public static function register( $version, $dir ) {
		if ( null !== self::$winner ) {
			// Already resolved. A plugin activated mid-request cannot displace
			// the running copy, but it will win on the next page load.
			return;
		}

		self::$copies[ (string) $version ] = (string) $dir;

		if ( self::$hooked ) {
			return;
		}

		self::$hooked = true;

		// bootstrap.php is required at plugin-file include time, which is early.
		// WordPress has the hook API by then, but a PHP test harness that
		// includes a plugin file with a hand-written stub set may not: Holiday
		// Mode's tests/logic-tests.php did exactly that on 2026-09-01 and every
		// run fatalled with "Call to undefined function did_action()". Fail soft
		// instead -- the copies stay registered and the first call to load()
		// resolves them.
		if ( ! function_exists( 'add_action' ) || ! function_exists( 'did_action' ) ) {
			return;
		}

		if ( did_action( 'plugins_loaded' ) ) {
			// Late arrival (activation hook, mu-plugin, manual include): every
			// copy that is going to register has already done so.
			self::load();
		} else {
			// Early enough that siblings still get to register. Priority -100
			// so FAFH is available to anything hooking plugins_loaded normally.
			add_action( 'plugins_loaded', [ __CLASS__, 'load_on_hook' ], -100 );
		}
	}

	/**
	 * Action callback for the deferred load.
	 *
	 * Wraps load() because a hook callback must not return a value, and load()
	 * returns the winning directory for callers that force resolution early.
	 *
	 * @return void
	 */
	public static function load_on_hook() {
		self::load();
	}

	/**
	 * Loads the highest-versioned registered copy.
	 *
	 * Safe to call repeatedly and safe to call early: anything needing FAFH
	 * before plugins_loaded can call this to force resolution.
	 *
	 * @return string|null Directory of the winning copy, or null if none registered.
	 */
	public static function load() {
		if ( null !== self::$winner ) {
			return self::$winner;
		}

		if ( ! self::$copies ) {
			return null;
		}

		uksort( self::$copies, 'version_compare' );

		end( self::$copies );
		$version = key( self::$copies );
		$dir     = current( self::$copies );

		self::$winner = $dir;

		if ( ! class_exists( 'FAFH', false ) ) {
			require_once $dir . '/class-fafh.php';
		}

		FAFH::boot( $dir, $version );

		// Only the winning copy registers the endpoint, so it is hooked here
		// rather than in a plugin. is_admin() covers admin-ajax.php, which is
		// where the shim posts to.
		//
		// Every function is tested for, not just called: this file is included
		// at plugin-file scope and a test harness may have stubbed only part of
		// WordPress. An unguarded did_action() here fatalled Holiday Mode's
		// whole suite on 2026-09-01, and an unguarded is_admin() did the same to
		// FAFH's own on the day the shim landed. Fail soft, always.
		if ( function_exists( 'add_action' ) && function_exists( 'is_admin' ) && is_admin() ) {
			FAFH::register_ajax();
		}

		// The picker's search endpoint and the filter that puts a saved icon
		// back into a sourced field. Both are needed outside wp-admin too: the
		// REST route answers on its own request, where is_admin() is false.
		if ( function_exists( 'add_action' ) && function_exists( 'add_filter' ) ) {
			FAFH::register_rest();
			FAFH::register_picker();
		}

		return $dir;
	}

	/**
	 * Every copy that registered, for debugging a version mismatch.
	 *
	 * @return array Version => directory.
	 */
	public static function copies() {
		return self::$copies;
	}
}
