<?php
/**
 * Plugin Name: Notifications for HivePress
 * Plugin URI: https://github.com/irapidchris-del/notifications-for-hivepress
 * Description: Adds on-site notifications with toast pop-ups and a notification history page, mirroring the email notifications sent by HivePress and its extensions.
 * Version: 1.9.11
 * Author: ChrisB
 * Author URI: https://community.hivepress.io/u/chrisb
 * Text Domain: notifications-for-hivepress
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: hivepress
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/irapidchris-del/notifications-for-hivepress
 *
 * @package HivePress\Notifications
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

define( 'HP_NOTIFICATIONS_VERSION', '1.9.11' );
define( 'HP_NOTIFICATIONS_FILE', __FILE__ );

/**
 * Registers the extension with HivePress.
 *
 * HivePress collects extension paths via this filter, then autoloads classes and merges
 * configs from the "includes" directory of every registered path. The array form is used
 * rather than a bare directory string: given a string, core requires the main file to be
 * named after the folder ({dirname}/{dirname}.php), so a renamed install folder - which is
 * exactly what a GitHub "Download ZIP" produces - would silently disable the whole plugin.
 * The filter must be added at file scope; core reads it before plugins_loaded callbacks run.
 *
 * @param array $extensions Extension paths.
 * @return array
 */
add_filter(
	'hivepress/v1/extensions',
	function( $extensions ) {
		$extensions['notifications_for_hivepress'] = [
			'name'    => 'Notifications for HivePress',
			'version' => HP_NOTIFICATIONS_VERSION,
			'path'    => __DIR__,
			'url'     => rtrim( plugin_dir_url( __FILE__ ), '/' ),
		];

		return $extensions;
	}
);

/**
 * Flushes the rewrite rules around activation.
 *
 * The plugin registers front-end routes (/account/notifications and its settings child), and
 * HivePress regenerates rewrite rules from routes on every init - but WordPress serves the
 * cached rules until they are flushed, and core only flushes on its own activate/update. So a
 * fresh install of this plugin would 404 on its pages until something else happened to flush.
 * Deleting the option is core's own flush pattern; WordPress rebuilds the rules lazily.
 */
register_activation_hook(
	__FILE__,
	function() {
		delete_option( 'rewrite_rules' );
	}
);

register_deactivation_hook(
	__FILE__,
	function() {
		delete_option( 'rewrite_rules' );
	}
);

/*
 * Translations are deliberately not loaded here.
 *
 * WordPress has loaded plugin translations automatically since 4.6 using the Text Domain and Domain
 * Path headers above, and it reads them from wp-content/languages/plugins/ - which is where both
 * translate.wordpress.org and Loco Translate put them, and the only place that survives a plugin
 * update. Calling load_plugin_textdomain() would point at this plugin's own /languages/ folder
 * instead, so a user's translation would be wiped by the next release. The folder ships the POT
 * alone, for translators to work from.
 */

/**
 * Adds the quick links to the plugin row on the Plugins screen.
 *
 * The links are only useful once HivePress has loaded the extension, so they only appear while
 * HivePress is active; the Check for updates link is added separately by the updater either way.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function( $links ) {
		if ( function_exists( 'hivepress' ) && current_user_can( 'manage_options' ) ) {
			array_unshift(
				$links,
				'<a href="' . esc_url( admin_url( 'admin.php?page=hp_settings&tab=notifications' ) ) . '">' . esc_html__( 'Settings', 'notifications-for-hivepress' ) . '</a>',
				'<a href="' . esc_url( admin_url( 'admin.php?page=hp_notification_broadcast' ) ) . '">' . esc_html__( 'Announcements', 'notifications-for-hivepress' ) . '</a>'
			);
		}

		return $links;
	}
);

/**
 * Says so when HivePress is missing.
 *
 * Everything this plugin does is loaded by HivePress from the path registered above, so without
 * HivePress there is no settings tab, no Announcements page and no notifications, and previously
 * nothing said why. A plain notice beats silence.
 */
add_action(
	'admin_notices',
	function() {
		if ( function_exists( 'hivepress' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__( 'Notifications for HivePress requires the HivePress plugin to be installed and active. Until then, its settings tab, Announcements page and notifications are unavailable.', 'notifications-for-hivepress' ) . '</p></div>';
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
