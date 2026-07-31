<?php
/**
 * Notification controller.
 *
 * @package HivePress\Notifications\Controllers
 */

namespace HivePress\Controllers;

use HivePress\Helpers as hp;
use HivePress\Blocks;
use HivePress\Forms;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Manages notifications.
 */
final class Notification extends Controller {

	/**
	 * Class constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'routes' => [

					// Returns the pending pop-ups of the current user and empties the queue. This is
					// a POST because it changes the queue, and because a page cache never caches POST.
					'notification_queue_resource'    => [
						'path'   => '/notifications/queue',
						'method' => 'POST',
						'action' => [ $this, 'get_notification_queue' ],
						'rest'   => true,
					],

					// Returns the unread count and nothing else. The queue endpoint above also
					// carries this number, but reading it empties the queue, so only a tab that can
					// actually paint the pop-ups may call it. A hidden tab needs the count without
					// that side effect: the service worker tells every tab when the operating
					// system has announced a push, and a tab that stayed on a stale number until it
					// was reloaded was the bug this exists to fix.
					'notification_count_resource'    => [
						'path'   => '/notifications/count',
						'method' => 'GET',
						'action' => [ $this, 'get_notification_count' ],
						'rest'   => true,
					],

					// Answers the service worker on a push. Authenticated by token rather than cookie,
					// because a push arrives when no page is open and WordPress ignores a REST
					// cookie that has no nonce.
					'notification_latest_resource'   => [
						'path'   => '/notifications/latest',
						'method' => 'GET',
						'action' => [ $this, 'get_latest_notification' ],
						'rest'   => true,
					],

					'notifications_recent_resource'  => [
						'path'   => '/notifications/recent',
						'method' => 'GET',
						'action' => [ $this, 'get_recent_notifications' ],
						'rest'   => true,
					],

					'notification_push_resource'     => [
						'path'   => '/notifications/push',
						'method' => 'POST',
						'action' => [ $this, 'update_push_subscription' ],
						'rest'   => true,
					],

					'notifications_read_resource'    => [
						'path'   => '/notifications/read',
						'method' => 'POST',
						'action' => [ $this, 'update_notifications_read' ],
						'rest'   => true,
					],

					'notifications_delete_resource'  => [
						'path'   => '/notifications/delete',
						'method' => 'POST',
						'action' => [ $this, 'delete_notifications' ],
						'rest'   => true,
					],

					'notifications_restore_resource' => [
						'path'   => '/notifications/restore',
						'method' => 'POST',
						'action' => [ $this, 'restore_notifications' ],
						'rest'   => true,
					],

					'notification_update_action'     => [
						'path'   => '/notifications/preferences',
						'method' => 'POST',
						'action' => [ $this, 'update_notification_preferences' ],
						'rest'   => true,
					],

					'notifications_view_page'        => [
						'title'     => esc_html__( 'Notifications', 'notifications-for-hivepress' ),
						'base'      => 'user_account_page',
						'path'      => '/notifications',
						'redirect'  => [ $this, 'redirect_notifications_view_page' ],
						'action'    => [ $this, 'render_notifications_view_page' ],
						'paginated' => true,
					],

					'notification_settings_page'     => [
						'title'    => esc_html__( 'Notification Settings', 'notifications-for-hivepress' ),
						'base'     => 'notifications_view_page',
						'path'     => '/settings',
						'redirect' => [ $this, 'redirect_notifications_view_page' ],
						'action'   => [ $this, 'render_notification_settings_page' ],
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * Returns the pending pop-ups and empties the queue.
	 *
	 * This reads a single user meta value rather than querying notifications, so it stays cheap
	 * enough to call on every page load.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function get_notification_queue( $request ) {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		// Get user ID.
		$user_id = get_current_user_id();

		// Get queue.
		$queue = hivepress()->notification->get_queue( $user_id );

		if ( $queue ) {
			hivepress()->notification->clear_queue( $user_id );
		}

		return hp\rest_response(
			200,
			[
				'notifications' => array_values( $queue ),
				'unread'        => hivepress()->notification->get_unread_count( $user_id ),
			]
		);
	}

	/**
	 * Returns the unread count without touching the queue.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function get_notification_count( $request ) {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		return hp\rest_response(
			200,
			[
				'unread' => hivepress()->notification->get_unread_count( get_current_user_id() ),
			]
		);
	}

	/**
	 * Marks notifications as read.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function update_notifications_read( $request ) {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		// Get user ID.
		$user_id = get_current_user_id();

		// Get notification IDs.
		$notification_ids = array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) );

		// Get the state to set.
		$read = $request->get_param( 'read' );
		$read = is_null( $read ) ? 1 : (int) (bool) $read;

		// Get filter. The user is part of the query rather than a check afterwards, so there's no
		// way to mark someone else's notification as read.
		$filter = [
			'user' => $user_id,
			'read' => 1 === $read ? 0 : 1,
		];

		if ( $notification_ids ) {
			$filter['id__in'] = $notification_ids;
		}

		// Get notifications.
		$notifications = Models\Notification::query()->filter( $filter )->limit( 500 )->get();

		$clicked = (bool) $request->get_param( 'clicked' );

		foreach ( $notifications as $notification ) {
			$notification->fill( [ 'read' => $read ] )->save( [ 'read' ] );

			if ( $clicked ) {
				hivepress()->notification->add_stat( $notification->get_type(), 'clicked' );
			}
		}

		return hp\rest_response(
			200,
			[
				'unread' => hivepress()->notification->update_unread_count( $user_id ),
			]
		);
	}

	/**
	 * Returns the newest unread notification for the service worker.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function get_latest_notification( $request ) {

		// Check the token. The worker has no cookie to offer.
		$user_id = hivepress()->notification_push ? hivepress()->notification_push->get_token_user( (string) $request->get_param( 'token' ) ) : 0;

		if ( ! $user_id ) {
			return hp\rest_error( 401 );
		}

		// Get notification.
		$notification = Models\Notification::query()->filter(
			[
				'user' => $user_id,
				'read' => 0,
			]
		)->order( [ 'created_date' => 'desc' ] )->get_first();

		if ( ! $notification ) {
			return hp\rest_error( 404 );
		}

		return hp\rest_response(
			200,
			[
				'id'    => $notification->get_id(),
				'text'  => $notification->get_text(),
				'url'   => (string) $notification->get_url(),

				// The notification's own picture, where it has one: a badge's image, or the avatar
				// of whoever caused it. Without this the operating system showed the site icon for
				// everything, so a badge award carried its own visual in the list and the bell and
				// then lost it at the one place people actually look first.
				'image' => (string) $notification->get_image(),
			]
		);
	}

	/**
	 * Turns a stored date into a short "2 hours ago" phrase.
	 *
	 * @param string $date Date string.
	 * @return string
	 */
	protected function get_time_text( $date ) {
		$time = strtotime( $date );

		if ( ! $time ) {
			return '';
		}

		// Notification dates are stored on the site's clock, so "now" has to come from the same
		// clock rather than UTC. Clock skew of a second could put a fresh notification a moment
		// in the future, so the time is clamped rather than blanked.
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$now = (int) current_time( 'timestamp' );

		/* translators: %s: human-readable time span, like "2 hours". */
		return sprintf( esc_html__( '%s ago', 'notifications-for-hivepress' ), human_time_diff( min( $time, $now ), $now ) );
	}

	/**
	 * Turns a stored date into a short absolute form, like "14:05" today or "3 Jul" before.
	 *
	 * @param string $date Date string.
	 * @return string
	 */
	protected function get_time_short( $date ) {
		$time = strtotime( $date );

		if ( ! $time ) {
			return '';
		}

		// Dates are stored on the site's clock, so "today" has to be judged on that clock too.
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		if ( gmdate( 'Y-m-d', $time ) === gmdate( 'Y-m-d', (int) current_time( 'timestamp' ) ) ) {
			return (string) date_i18n( (string) get_option( 'time_format', 'H:i' ), $time );
		}

		return (string) date_i18n( 'j M', $time );
	}

	/**
	 * Returns the most recent notifications for the header bell.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function get_recent_notifications( $request ) {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		$user_id = get_current_user_id();

		// Get notifications.
		$notifications = Models\Notification::query()->filter( [ 'user' => $user_id ] )
			->order( [ 'created_date' => 'desc' ] )
			->limit( 8 )
			->get();

		$results = [];

		foreach ( $notifications as $notification ) {
			$results[] = [
				'id'         => $notification->get_id(),
				'text'       => $notification->get_text(),
				'type'       => hivepress()->notification->get_type_label( $notification->get_type() ),
				'icon'       => hivepress()->notification->get_notification_icon( $notification ),
				'color'      => (string) $notification->get_color(),
				'image'      => (string) $notification->get_image(),
				'url'        => (string) $notification->get_url(),
				'read'       => (bool) $notification->get_read(),
				'time'       => (string) $notification->get_created_date(),
				'time_text'  => $this->get_time_text( (string) $notification->get_created_date() ),
				'time_short' => $this->get_time_short( (string) $notification->get_created_date() ),
				'link_label' => hivepress()->notification->get_type_link_text( $notification->get_type() ),
			];
		}

		return hp\rest_response(
			200,
			[
				'notifications' => $results,
				'unread'        => hivepress()->notification->get_unread_count( $user_id ),
			]
		);
	}

	/**
	 * Adds or removes a push subscription.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function update_push_subscription( $request ) {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		if ( ! hivepress()->notification_push || ! hivepress()->notification_push->is_enabled() ) {
			return hp\rest_error( 400 );
		}

		$user_id  = get_current_user_id();
		$endpoint = (string) $request->get_param( 'endpoint' );

		// Remove subscription.
		if ( $request->get_param( 'remove' ) ) {
			hivepress()->notification_push->delete_subscription( $user_id, $endpoint );

			return hp\rest_response( 200, [ 'id' => $user_id ] );
		}

		// Add subscription.
		$token = hivepress()->notification_push->add_subscription( $user_id, [ 'endpoint' => $endpoint ] );

		if ( ! $token ) {
			return hp\rest_error( 400 );
		}

		return hp\rest_response(
			200,
			[
				'id'    => $user_id,
				'token' => $token,
			]
		);
	}

	/**
	 * Deletes notifications.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function delete_notifications( $request ) {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		// Get user ID.
		$user_id = get_current_user_id();

		// Get notification IDs.
		$notification_ids = array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) );

		// Get filter.
		$filter = [ 'user' => $user_id ];

		if ( $notification_ids ) {
			$filter['id__in'] = $notification_ids;
		}

		// Clear only what's been read, for the bulk action.
		if ( $request->get_param( 'read' ) ) {
			$filter['read'] = 1;
		}

		// Get notifications.
		$notifications = Models\Notification::query()->filter( $filter )->limit( 500 )->get();

		foreach ( $notifications as $notification ) {

			// Trash rather than delete, so an accidental tap can be undone. Trashed comments
			// vanish from every query and count, and WordPress purges its trash on its own
			// after around thirty days, so nothing needs storing or scheduling here.
			wp_trash_comment( $notification->get_id() );
		}

		// Update type list.
		hivepress()->notification->rebuild_used_types( $user_id );

		return hp\rest_response(
			200,
			[
				'unread' => hivepress()->notification->update_unread_count( $user_id ),
			]
		);
	}

	/**
	 * Restores trashed notifications, for the undo after a delete.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function restore_notifications( $request ) {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		// Get user ID.
		$user_id = get_current_user_id();

		foreach ( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) as $comment_id ) {
			$comment = get_comment( $comment_id );

			// Only this user's own trashed notifications come back; anything else is ignored
			// rather than reported, so the endpoint reveals nothing about other IDs.
			if ( ! $comment || 'hp_notification' !== $comment->comment_type || absint( $comment->user_id ) !== $user_id || 'trash' !== $comment->comment_approved ) {
				continue;
			}

			wp_untrash_comment( $comment_id );
		}

		// Update type list.
		hivepress()->notification->rebuild_used_types( $user_id );

		return hp\rest_response(
			200,
			[
				'unread' => hivepress()->notification->update_unread_count( $user_id ),
			]
		);
	}

	/**
	 * Redirects the notifications page.
	 *
	 * @return mixed
	 */
	public function redirect_notifications_view_page() {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hivepress()->router->get_return_url( 'user_login_page' );
		}

		return false;
	}

	/**
	 * Saves the channels a user wants for each notification type.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function update_notification_preferences( $request ) {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		// Validate form. Passing the request parameters without overriding sets every field, so a
		// type with nothing ticked arrives as null rather than keeping its default.
		$form = ( new Forms\Notification_Update() )->set_values( $request->get_params() );

		if ( ! $form->validate() ) {
			return hp\rest_error( 400, $form->get_errors() );
		}

		// Get preferences. The quiet hour fields belong to the same form but aren't types.
		$preferences = [];

		foreach ( array_keys( $form->get_fields() ) as $type ) {
			if ( in_array( $type, [ 'quiet_start', 'quiet_end' ], true ) ) {
				continue;
			}

			$preferences[ $type ] = (array) $form->get_value( $type );
		}

		// Update preferences.
		hivepress()->notification->update_user_preferences( get_current_user_id(), $preferences );

		// Update quiet hours.
		hivepress()->notification->update_quiet_hours( get_current_user_id(), absint( $form->get_value( 'quiet_start' ) ), absint( $form->get_value( 'quiet_end' ) ) );

		return hp\rest_response( 200, [ 'id' => get_current_user_id() ] );
	}

	/**
	 * Renders the notification settings page.
	 *
	 * @return string
	 */
	public function render_notification_settings_page() {
		return ( new Blocks\Template( [ 'template' => 'notification_settings_page' ] ) )->render();
	}

	/**
	 * Renders the notifications page.
	 *
	 * @return string
	 */
	public function render_notifications_view_page() {
		return ( new Blocks\Template(
			[
				'template' => 'notifications_view_page',

				'context'  => [
					'notifications' => [],
				],
			]
		) )->render();
	}
}
