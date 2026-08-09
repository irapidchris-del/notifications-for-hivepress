<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen, never on deactivation, so switching the
 * plugin off temporarily loses nothing at all.
 *
 * **Deleting the plugin keeps the owner's data by default.** Someone who deletes a plugin by
 * accident, or removes it to install a clean copy, gets their notifications and settings back when
 * they reinstall. Destruction is opt-in, through the "Delete All Data" checkbox in the Removing the
 * Plugin section of the settings page, and is never a surprise.
 *
 * There is no way to ask at delete time. The confirmation form in wp-admin/plugins.php:398-410 is
 * hard-coded with no do_action or apply_filters inside it, so a checkbox cannot be added to that
 * screen; the setting has to live on our own page. Worse, WordPress prints "(will also delete its
 * data)" on that screen whenever an uninstall.php exists at all (wp-admin/plugins.php:376-380),
 * whatever the file actually does, so the setting's own description has to tell the owner that the
 * core warning does not apply to them unless they ticked the box.
 *
 * Two things go either way, because they are regenerable runtime junk rather than anything the
 * owner made: cached values, and scheduled actions. Scheduled actions especially - leaving them
 * queued means Action Scheduler keeps firing hooks for a plugin that is no longer installed.
 *
 * @package HivePress\Notifications
 */

// Exit if accessed directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Read the owner's choice first, before anything is touched.
$hp_delete_all = (bool) get_option( 'hp_notification_delete_data' );

/*
 * ---------------------------------------------------------------------------------------------
 * Always cleaned, whichever way the setting is set.
 * ---------------------------------------------------------------------------------------------
 */

// The updater's cached release lookup. A site transient lives under its own prefix, so neither the
// option sweep below nor a plain delete_option() would ever reach it.
delete_site_transient( 'hp_notifications_github_release' );

// Any other transient the plugin has ever set. Nothing writes one today, but a transient is stored
// as "_transient_{name}" plus a separate "_transient_timeout_{name}" row, so the prefix sweep used
// for options further down cannot match them - it anchors on "hp_notification" at the start of the
// name. Leaving a timeout row behind with no value row is the classic orphan.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$hp_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_' . $wpdb->esc_like( 'hp_notification' ) . '%',
		'_transient_timeout_' . $wpdb->esc_like( 'hp_notification' ) . '%'
	)
);

foreach ( (array) $hp_transients as $hp_transient_name ) {
	delete_option( $hp_transient_name );
}

// Clear any announcement batches still queued, whatever their arguments. They are queued through
// the HivePress scheduler, which runs on Action Scheduler rather than WP-Cron, so they have to be
// removed there; the wp_unschedule_hook() calls only cover an install where Action Scheduler was
// unavailable and scheduling fell through to core.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'hivepress/v1/notifications/broadcast' );
	as_unschedule_all_actions( 'hivepress/v1/notifications/broadcast_users' );
}

wp_unschedule_hook( 'hivepress/v1/notifications/broadcast' );
wp_unschedule_hook( 'hivepress/v1/notifications/broadcast_users' );

/*
 * ---------------------------------------------------------------------------------------------
 * Everything below happens only when the owner asked for it.
 * ---------------------------------------------------------------------------------------------
 */

if ( $hp_delete_all ) {

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

	// Delete the "already notified once" markers. These are the one thing the plugin writes outside
	// its own comments: they sit on HivePress's favourite and review comments, whose comment_type is
	// hp_favorite or hp_review, so the join above cannot reach them and one row per favourite and
	// per review would otherwise be left behind for good.
	delete_metadata( 'comment', 0, 'hp_notification_sent', '', true );

	// Delete the options. The names are matched on the plugin's prefix because several are dynamic:
	// one text option per notification type, one types option per group, one default per role. This
	// runs once, while the plugin is being deleted, so there is nothing worth caching.
	//
	// The "delete all data" option itself is excluded here and removed at the very end. If this run
	// fails part-way through - a timeout on a large site is the realistic case - the flag is still
	// set, so a second attempt finishes the job. Sweeping it away first would silently flip the site
	// back to "retain" with half the data still lying around.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$hp_option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s",
			$wpdb->esc_like( 'hp_notification' ) . '%',
			'hp_notification_delete_data'
		)
	);

	foreach ( (array) $hp_option_names as $hp_option_name ) {
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

	// The known-types record matches the prefix sweep above only by coincidence of naming, so it is
	// removed explicitly in case that prefix ever changes.
	delete_option( 'hp_notification_known_types' );

	// Last, and only once everything above has succeeded.
	delete_option( 'hp_notification_delete_data' );
}
