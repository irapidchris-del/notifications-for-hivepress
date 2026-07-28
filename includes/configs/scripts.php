<?php
/**
 * Scripts configuration.
 *
 * @package HivePress\Notifications\Configs
 */

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'notifications_frontend' => [
		'handle'  => 'hivepress-notifications',
		'src'     => plugin_dir_url( HP_NOTIFICATIONS_FILE ) . 'assets/js/frontend.js',

		// The file time rides along so browser and page caches refresh whenever the file
		// changes, not only on version bumps.
		'version' => HP_NOTIFICATIONS_VERSION . '.' . (int) filemtime( plugin_dir_path( HP_NOTIFICATIONS_FILE ) . 'assets/js/frontend.js' ),
		'deps'    => [ 'hivepress-core-frontend' ],
		'scope'   => 'frontend',
	],
];
