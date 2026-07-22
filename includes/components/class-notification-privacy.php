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

		// Get notifications.
		$notifications = Models\Notification::query()->filter( [ 'user' => $user->ID ] )
			->order( [ 'created_date' => 'desc' ] )
			->limit( $this->number )
			->offset( ( $page - 1 ) * $this->number )
			->get();

		foreach ( $notifications as $notification ) {
			$items = [
				[
					'name'  => esc_html__( 'Date', 'notifications-for-hivepress' ),
					'value' => (string) $notification->get_created_date(),
				],

				[
					'name'  => esc_html__( 'Type', 'notifications-for-hivepress' ),
					'value' => hivepress()->notification->get_type_label( $notification->get_type() ),
				],

				[
					'name'  => esc_html__( 'Text', 'notifications-for-hivepress' ),
					'value' => (string) $notification->get_text(),
				],

				[
					'name'  => esc_html__( 'Read', 'notifications-for-hivepress' ),
					'value' => $notification->get_read() ? esc_html__( 'Yes', 'notifications-for-hivepress' ) : esc_html__( 'No', 'notifications-for-hivepress' ),
				],
			];

			if ( $notification->get_url() ) {
				$items[] = [
					'name'  => esc_html__( 'Link', 'notifications-for-hivepress' ),
					'value' => (string) $notification->get_url(),
				];
			}

			$data[] = [
				'group_id'    => 'hp-notifications',
				'group_label' => esc_html__( 'Notifications', 'notifications-for-hivepress' ),
				'item_id'     => 'hp-notification-' . $notification->get_id(),
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

			$response['items_removed'] = true;
		}

		return $response;
	}
}
