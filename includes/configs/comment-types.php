<?php
/**
 * Comment types configuration.
 *
 * Marking the type as non-public makes the HivePress comment component exclude it from
 * comment queries, comment counts and comment feeds, so notifications never appear in
 * the WordPress admin comment screens.
 *
 * @package HivePress\Notifications\Configs
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'notification' => [
		'public' => false,
	],
];
