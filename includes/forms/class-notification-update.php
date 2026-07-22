<?php
/**
 * Notification update form.
 *
 * @package HivePress\Notifications\Forms
 */

namespace HivePress\Forms;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Lets a user choose how they hear about each notification group.
 *
 * There's one field per group rather than per type, because forty rows is a wall nobody reads.
 * The options are the delivery channels, so a future SMS channel needs no change here.
 */
class Notification_Update extends Form {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'   => esc_html__( 'Notification Settings', 'notifications-for-hivepress' ),
				'captcha' => false,
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Class constructor.
	 *
	 * @param array $args Form arguments.
	 */
	public function __construct( $args = [] ) {

		// Get channels.
		$labels = hivepress()->notification->get_channels();

		// Get fields.
		$fields = [];
		$order  = 10;

		foreach ( hivepress()->notification->get_groups() as $group => $group_label ) {
			$channels = [];

			foreach ( hivepress()->notification->get_enabled_types() as $type ) {
				if ( hivepress()->notification->get_type_group( $type ) !== $group ) {
					continue;
				}

				// System types can't be switched off, so they don't appear here at all.
				if ( hp\get_array_value( hivepress()->notification->get_type_args( $type ), '_system' ) ) {
					continue;
				}

				// Only offer the channels this group can actually be delivered through, so a
				// group with no email behind it doesn't offer an email that never arrives.
				$channels += array_intersect_key( $labels, array_flip( hivepress()->notification->get_type_channels( $type ) ) );
			}

			if ( ! $channels ) {
				continue;
			}

			$fields[ $group ] = [
				'label'   => $group_label,
				'type'    => 'checkboxes',
				'options' => $channels,
				'default' => array_keys( $channels ),
				'_order'  => $order,
			];

			$order += 10;
		}

		// Add quiet hours. Hours run on the site's clock, and setting both the same switches
		// them off.
		$hours = [];

		for ( $hour = 0; $hour <= 23; $hour++ ) {
			$hours[ $hour ] = sprintf( '%02d:00', $hour );
		}

		$quiet = hivepress()->notification->get_quiet_hours( get_current_user_id() );

		$fields['quiet_start'] = [
			'label'       => esc_html__( 'Quiet Hours', 'notifications-for-hivepress' ),
			'description' => esc_html__( 'No pop-ups or push notifications between these times. Anything that arrives still waits in your list. Set both the same to switch this off.', 'notifications-for-hivepress' ),
			'type'        => 'select',
			'options'     => $hours,
			'default'     => absint( hp\get_array_value( $quiet, 'start' ) ),
			'required'    => true,
			'_order'      => 1000,
		];

		$fields['quiet_end'] = [
			'label'    => esc_html__( 'Until', 'notifications-for-hivepress' ),
			'type'     => 'select',
			'options'  => $hours,
			'default'  => absint( hp\get_array_value( $quiet, 'end' ) ),
			'required' => true,
			'_order'   => 1010,
		];

		$args = hp\merge_arrays(
			[
				'description' => esc_html__( 'Choose how you want to hear about each of these. Clear both boxes to turn a notification off.', 'notifications-for-hivepress' ),
				'message'     => esc_html__( 'Your notification settings have been saved.', 'notifications-for-hivepress' ),
				'action'      => hivepress()->router->get_url( 'notification_update_action' ),
				'fields'      => $fields,

				'button'      => [
					'label' => esc_html__( 'Save Changes', 'notifications-for-hivepress' ),
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
