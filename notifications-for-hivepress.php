<?php
/**
 * Plugin Name: Notifications for HivePress
 * Plugin URI: https://github.com/irapidchris-del/notifications-for-hivepress
 * Description: Adds on-site notifications with toast pop-ups and a notification history page, mirroring the email notifications sent by HivePress and its extensions.
 * Version: 1.9.0
 * Author: ChrisB
 * Author URI: https://community.hivepress.io/u/chrisb
 * Text Domain: notifications-for-hivepress
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.2
 * Requires Plugins: hivepress
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/irapidchris-del/notifications-for-hivepress
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
 * Loads the GitHub release updater.
 *
 * The plugin is distributed through GitHub releases rather than wp.org, so updates go through the
 * native "update_plugins_{$hostname}" API keyed off the Update URI header above. The updater is a
 * handful of plain functions with no third-party library behind them.
 */
require_once __DIR__ . '/updater.php';
