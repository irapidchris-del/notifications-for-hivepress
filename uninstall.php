<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen, not on deactivation, so switching the
 * plugin off temporarily loses nothing. Everything the plugin stored goes: the notifications
 * themselves, the cached counters and preferences kept in user meta, every option, and any
 * announcement batches still waiting in the scheduler.
 *
 * @package HivePress\Notifications
 */

// Exit if accessed directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Delete the notifications and their meta. A direct query is used because a large site can hold
// hundreds of thousands of notification comments, and deleting them one by one through the API
// would time the request out.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE meta FROM {$wpdb->commentmeta} AS meta
		INNER JOIN {$wpdb->comments} AS comments ON comments.comment_ID = meta.comment_id
		WHERE comments.comment_type = %s",
		'hp_notification'
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->comments} WHERE comment_type = %s", 'hp_notification' ) );

// Delete the options. The names are matched on the plugin's prefix because several are dynamic:
// one text option per notification type, one types option per group, one default per role. This
// runs once, while the plugin is being deleted, so there is nothing worth caching.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$option_names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'hp_notification' ) . '%'
	)
);

foreach ( (array) $option_names as $hp_option_name ) {
	delete_option( $hp_option_name );
}

// Delete the user meta: the pop-up queue, the cached unread count and type list, the channel
// preferences, the push subscriptions and the quiet hours.
foreach ( [
	'hp_notification_queue',
	'hp_notification_unread',
	'hp_notification_type_list',
	'hp_notification_preferences',
	'hp_notification_push',
	'hp_notification_quiet',
	'hp_notification_badges_sent',
] as $hp_meta_key ) {
	delete_metadata( 'user', 0, $hp_meta_key, '', true );
}

// Delete the updater's cached release details. Site transients are stored under their own
// prefix, so the option sweep above never matches them.
delete_site_transient( 'hp_notifications_github_release' );

// The known-types record does not carry the plugin's option prefix pattern used above only by
// coincidence of naming, so it is removed explicitly in case that prefix ever changes.
delete_option( 'hp_notification_known_types' );

// Clear any announcement batches still queued, whatever their arguments. The batches are queued
// through the HivePress scheduler, which runs on Action Scheduler, so they have to be removed
// there; the WP-Cron calls below only cover an install where Action Scheduler was unavailable
// and scheduling fell through.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'hivepress/v1/notifications/broadcast' );
	as_unschedule_all_actions( 'hivepress/v1/notifications/broadcast_users' );
}

wp_unschedule_hook( 'hivepress/v1/notifications/broadcast' );
wp_unschedule_hook( 'hivepress/v1/notifications/broadcast_users' );
