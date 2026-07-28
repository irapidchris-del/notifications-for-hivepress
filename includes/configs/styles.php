<?php
/**
 * Styles configuration.
 *
 * @package HivePress\Notifications\Configs
 */

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'notifications_frontend' => [
		'handle'  => 'hivepress-notifications',
		'src'     => plugin_dir_url( HP_NOTIFICATIONS_FILE ) . 'assets/css/frontend.css',

		// The file time rides along so browser and page caches refresh whenever the file
		// changes, not only on version bumps.
		'version' => HP_NOTIFICATIONS_VERSION . '.' . (int) filemtime( plugin_dir_path( HP_NOTIFICATIONS_FILE ) . 'assets/css/frontend.css' ),
		'deps'    => [ 'hivepress-core-frontend' ],
		'scope'   => 'frontend',
	],
];
