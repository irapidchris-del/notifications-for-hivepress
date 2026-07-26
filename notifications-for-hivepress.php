<?php
/**
 * Plugin Name: Notifications for HivePress
 * Description: Adds on-site notifications with toast pop-ups and a notification history page, mirroring the email notifications sent by HivePress and its extensions.
 * Version: 1.9.0
 * Author: ChrisB
 * Author URI: https://community.hivepress.io/u/chrisb
 * Text Domain: notifications-for-hivepress
 * Domain Path: /languages
 * Requires Plugins: hivepress
 * Requires PHP: 7.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package HivePress\Notifications
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

define( 'HP_NOTIFICATIONS_VERSION', '1.9.0' );
define( 'HP_NOTIFICATIONS_FILE', __FILE__ );

/**
 * Registers the extension with HivePress.
 *
 * HivePress collects extension paths via this filter, then autoloads classes and merges
 * configs from the "includes" directory of every registered path.
 *
 * @param array $extensions Extension paths.
 * @return array
 */
add_filter(
	'hivepress/v1/extensions',
	function( $extensions ) {
		$extensions[] = __DIR__;

		return $extensions;
	}
);

/**
 * Loads the plugin translations.
 */
add_action(
	'init',
	function() {
		load_plugin_textdomain( 'notifications-for-hivepress', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

/**
 * Wires up updates straight from the plugin's GitHub releases.
 *
 * Each release attaches a "notifications-for-hivepress.zip" asset built to install into the right
 * folder, and the Plugin Update Checker library offers it to WordPress like any other update: the
 * Plugins page shows the notice, "View details" and one-click update all work. The check only needs
 * to run in the admin, during the cron event that refreshes the update transient, and under WP-CLI,
 * so ordinary front-end requests skip it entirely.
 */
add_action(
	'init',
	function() {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$loader = __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';

		if ( ! is_readable( $loader ) ) {
			return;
		}

		require_once $loader;

		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return;
		}

		$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/irapidchris-del/notifications-for-hivepress/',
			HP_NOTIFICATIONS_FILE,
			'notifications-for-hivepress'
		);

		// Track published releases, not the default branch, so an unreleased commit is never
		// offered as an update. The attached zip is preferred over GitHub's generated source
		// archive, because it already unpacks into the "notifications-for-hivepress" folder.
		$update_checker->getVcsApi()->enableReleaseAssets( '/notifications-for-hivepress\.zip$/i' );
	},
	5
);
