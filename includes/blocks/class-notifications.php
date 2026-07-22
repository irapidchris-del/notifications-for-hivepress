<?php
/**
 * Notifications block.
 *
 * @package HivePress\Notifications\Blocks
 */

namespace HivePress\Blocks;

use HivePress\Helpers as hp;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Renders the notification list of the current user.
 */
class Notifications extends Block {

	/**
	 * Number of notifications per page.
	 *
	 * @var int
	 */
	protected $number = 20;

	/**
	 * Renders block HTML.
	 *
	 * @return string
	 */
	public function render() {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		// Get user ID.
		$user_id = get_current_user_id();

		// Get type.
		$type = $this->get_current_type();

		// Get page number.
		$page_number = hivepress()->request->get_page_number();

		// Get search term. Reading it only filters the reader's own list, so there's nothing a
		// nonce would protect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = sanitize_text_field( wp_unslash( (string) hp\get_array_value( $_GET, 'notification_search' ) ) );

		// Get filter.
		$filter = [ 'user' => $user_id ];

		if ( $type ) {
			$filter['type'] = $type;
		}

		if ( $search ) {

			// The HivePress comment query maps field filters onto WP_Comment_Query variables, and
			// there's no variable for matching comment_content, so a "text__like" filter would be
			// dropped and quietly return everything. WP_Comment_Query does have a search variable
			// though, so the matching IDs are found with it first.
			$found = get_comments(
				[
					'type'    => 'hp_notification',
					'user_id' => $user_id,
					'search'  => $search,
					'fields'  => 'ids',
					'number'  => 500,
				]
			);

			if ( ! $found ) {
				return $this->render_part( [], [], $type, $search, 0, 0, 1, $user_id );
			}

			$filter['id__in'] = array_map( 'absint', $found );
		}

		// Get total count. This uses its own query object, because get_count() reuses whatever
		// arguments the query already has and the paged query has a limit applied.
		$total = Models\Notification::query()->filter( $filter )->get_count();

		// Get notifications.
		$notifications = Models\Notification::query()->filter( $filter )
			->order( [ 'created_date' => 'desc' ] )
			->limit( $this->number )
			->offset( ( max( 1, $page_number ) - 1 ) * $this->number )
			->get();

		// Render part.
		return $this->render_part(
			$this->group_notifications( $notifications ),
			hivepress()->notification->get_used_types( $user_id ),
			$type,
			$search,
			$total,
			(int) ceil( $total / $this->number ),
			max( 1, $page_number ),
			$user_id
		);
	}

	/**
	 * Renders the list.
	 *
	 * @param array  $groups Grouped notifications.
	 * @param array  $types Available types.
	 * @param string $type Current type.
	 * @param string $search Current search term.
	 * @param int    $total Total count.
	 * @param int    $pages Total pages.
	 * @param int    $page Current page.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	protected function render_part( $groups, $types, $type, $search, $total, $pages, $page, $user_id ) {
		return ( new Part(
			[
				'path'    => 'notification/view/page/notifications',

				'context' => array_merge(
					$this->context,
					[
						'notification_groups' => $groups,
						'notification_types'  => $types ? $types : hivepress()->notification->get_used_types( $user_id ),
						'notification_type'   => $type,
						'notification_search' => $search,
						'notification_unread' => hivepress()->notification->get_unread_count( $user_id ),
						'notification_pages'  => $pages,
						'notification_page'   => $page,
						'notification_total'  => $total,
					]
				),
			]
		) )->render();
	}

	/**
	 * Gets the type the list is filtered by.
	 *
	 * @return string
	 */
	protected function get_current_type() {

		// Reading it only filters the reader's own list, so there's nothing a nonce would protect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = sanitize_key( (string) hp\get_array_value( $_GET, 'notification_type' ) );

		if ( ! array_key_exists( $type, hivepress()->notification->get_types() ) ) {
			return '';
		}

		return $type;
	}

	/**
	 * Groups notifications by the day they were created.
	 *
	 * @param iterable $notifications Notification objects.
	 * @return array
	 */
	protected function group_notifications( $notifications ) {
		$groups = [];

		// Get dates.
		// The dates being grouped are comment dates, which WordPress stores on the site's clock,
		// so "today" has to come from the same clock rather than UTC.
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$today = wp_date( 'Y-m-d' );
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$yesterday = wp_date( 'Y-m-d', strtotime( '-1 day', (int) current_time( 'timestamp' ) ) );

		foreach ( $notifications as $notification ) {

			// Get timestamp.
			$time = strtotime( (string) $notification->get_created_date() );

			if ( ! $time ) {
				continue;
			}

			// Get date.
			$date = gmdate( 'Y-m-d', $time );

			// Get label.
			if ( $date === $today ) {
				$label = esc_html__( 'Today', 'notifications-for-hivepress' );
			} elseif ( $date === $yesterday ) {
				$label = esc_html__( 'Yesterday', 'notifications-for-hivepress' );
			} else {
				$label = wp_date( (string) get_option( 'date_format' ), $time );
			}

			// Add notification.
			if ( ! isset( $groups[ $date ] ) ) {
				$groups[ $date ] = [
					'label'         => $label,
					'notifications' => [],
				];
			}

			$groups[ $date ]['notifications'][] = $notification;
		}

		return $groups;
	}
}
