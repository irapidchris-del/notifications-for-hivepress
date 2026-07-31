<?php
/**
 * Notification privacy component.
 *
 * @package HivePress\Notifications\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Includes notifications in the WordPress personal data tools.
 *
 * A notification is personal data: it names a person, records something they did or that happened to
 * them, and is kept against their user ID. It therefore has to come out on a subject access request
 * and go on an erasure request, along with the counters and preferences stored alongside it.
 */
final class Notification_Privacy extends Component {

	/**
	 * Number of notifications handled per batch.
	 *
	 * @var int
	 */
	protected $number = 100;

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Register the personal data tools.
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );

		parent::__construct( $args );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['notifications-for-hivepress'] = [
			'exporter_friendly_name' => esc_html__( 'Notifications', 'notifications-for-hivepress' ),
			'callback'               => [ $this, 'export_data' ],
		];

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['notifications-for-hivepress'] = [
			'eraser_friendly_name' => esc_html__( 'Notifications', 'notifications-for-hivepress' ),
			'callback'             => [ $this, 'erase_data' ],
		];

		return $erasers;
	}

	/**
	 * Exports the notifications of a user.
	 *
	 * @param string $email Email address.
	 * @param int    $page Page number.
	 * @return array
	 */
	public function export_data( $email, $page = 1 ) {
		$data = [];

		// Get user.
		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		$page = max( 1, absint( $page ) );

		// Get notifications. The comments are read directly rather than through the model query,
		// because a deleted-with-undo notification sits in the trash for up to thirty days and the
		// model query can't see the trash, yet it's still stored personal data that has to come out.
		$notifications = get_comments(
			[
				'type'    => 'hp_notification',
				'user_id' => $user->ID,
				'status'  => [ 'all', 'trash' ],
				'number'  => $this->number,
				'offset'  => ( $page - 1 ) * $this->number,
				'orderby' => 'comment_date',
				'order'   => 'DESC',
			]
		);

		foreach ( $notifications as $notification ) {
			$url = (string) get_comment_meta( (int) $notification->comment_ID, 'hp_url', true );

			$items = [
				[
					'name'  => esc_html__( 'Date', 'notifications-for-hivepress' ),
					'value' => (string) $notification->comment_date,
				],

				[
					'name'  => esc_html__( 'Type', 'notifications-for-hivepress' ),
					'value' => hivepress()->notification->get_type_label( (string) get_comment_meta( (int) $notification->comment_ID, 'hp_type', true ) ),
				],

				[
					'name'  => esc_html__( 'Text', 'notifications-for-hivepress' ),
					'value' => (string) $notification->comment_content,
				],

				[
					'name'  => esc_html__( 'Read', 'notifications-for-hivepress' ),
					'value' => absint( $notification->comment_karma ) ? esc_html__( 'Yes', 'notifications-for-hivepress' ) : esc_html__( 'No', 'notifications-for-hivepress' ),
				],
			];

			if ( $url ) {
				$items[] = [
					'name'  => esc_html__( 'Link', 'notifications-for-hivepress' ),
					'value' => $url,
				];
			}

			$data[] = [
				'group_id'    => 'hp-notifications',
				'group_label' => esc_html__( 'Notifications', 'notifications-for-hivepress' ),
				'item_id'     => 'hp-notification-' . absint( $notification->comment_ID ),
				'data'        => $items,
			];
		}

		// The settings only need exporting once, so they go with the first batch.
		if ( 1 === $page ) {
			$settings = [];

			foreach ( hivepress()->notification->get_enabled_types() as $type ) {

				// System types carry no user choice worth exporting.
				if ( hp\get_array_value( hivepress()->notification->get_type_args( $type ), '_system' ) ) {
					continue;
				}

				$channels = [];

				foreach ( hivepress()->notification->get_user_channels( $user->ID, $type ) as $channel ) {
					$channels[] = hp\get_array_value( hivepress()->notification->get_channels(), $channel, $channel );
				}

				$settings[] = [
					'name'  => hivepress()->notification->get_type_label( $type ),
					'value' => $channels ? implode( ', ', $channels ) : esc_html__( 'Off', 'notifications-for-hivepress' ),
				];
			}

			if ( $settings ) {
				$data[] = [
					'group_id'    => 'hp-notification-settings',
					'group_label' => esc_html__( 'Notification Settings', 'notifications-for-hivepress' ),
					'item_id'     => 'hp-notification-settings',
					'data'        => $settings,
				];
			}
		}

		return [
			'data' => $data,
			'done' => count( $notifications ) < $this->number,
		];
	}

	/**
	 * Erases the notifications of a user.
	 *
	 * @param string $email Email address.
	 * @param int    $page Page number.
	 * @return array
	 */
	public function erase_data( $email, $page = 1 ) {
		$response = [
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];

		// Get user.
		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return $response;
		}

		// Get notifications. The offset stays at zero because each batch is deleted, so the next
		// batch is always at the start.
		$notifications = Models\Notification::query()->filter( [ 'user' => $user->ID ] )
			->limit( $this->number )
			->get();

		$count = 0;

		foreach ( $notifications as $notification ) {
			$notification->delete();

			++$count;
		}

		// A deleted-with-undo notification sits in the trash for up to thirty days, where the model
		// query can't see it, so the trash is emptied directly once the visible ones are gone.
		if ( $count < $this->number ) {
			$trashed = get_comments(
				[
					'type'    => 'hp_notification',
					'user_id' => $user->ID,
					'status'  => 'trash',
					'number'  => $this->number - $count,
					'fields'  => 'ids',
				]
			);

			foreach ( $trashed as $comment_id ) {
				wp_delete_comment( absint( $comment_id ), true );

				++$count;
			}
		}

		if ( $count ) {
			$response['items_removed'] = true;
		}

		$response['done'] = $count < $this->number;

		// Clear everything else once the notifications are gone.
		if ( $response['done'] ) {
			delete_user_meta( $user->ID, 'hp_notification_queue' );
			delete_user_meta( $user->ID, 'hp_notification_unread' );
			delete_user_meta( $user->ID, 'hp_notification_type_list' );
			delete_user_meta( $user->ID, 'hp_notification_preferences' );
			delete_user_meta( $user->ID, 'hp_notification_push' );
			delete_user_meta( $user->ID, 'hp_notification_quiet' );
			delete_user_meta( $user->ID, 'hp_notification_badges_sent' );

			$response['items_removed'] = true;
		}

		return $response;
	}
}
