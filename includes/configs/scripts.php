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
		'version' => HP_NOTIFICATIONS_VERSION,
		'deps'    => [ 'hivepress-core-frontend' ],
		'scope'   => 'frontend',
	],
];
