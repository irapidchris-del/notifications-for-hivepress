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

			// Put them back in the canonical channel order. Accumulating them type by type left the
			// order dependent on which type happened to contribute each one, so one group could read
			// "Pop-up, Push, Email" while the next read "Pop-up, Email, Push".
			$channels = array_intersect_key( $labels, $channels );

			/*
			 * A group covers several kinds of notification, and they don't all support the same
			 * channels: Listings, for instance, holds both the HivePress listing emails and our own
			 * "Review Received", which has no email behind it at all. The tick box list is the union
			 * of what the group can do, so ticking Email here does not promise an email for every
			 * event in the group. Saying so stops the gap reading as a fault - a staging tester
			 * spent twenty minutes on a "missing" review email that was never going to exist.
			 */
			$fields[ $group ] = [
				'label'       => $group_label,
				'type'        => 'checkboxes',
				'options'     => $channels,
				'default'     => array_keys( $channels ),
				'description' => esc_html__( 'On-site covers your notifications list, the bell menu and pop-ups. Not every notification in this group has an email or a push behind it, so those only apply where one exists.', 'notifications-for-hivepress' ),
				'_order'      => $order,
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
				'description' => esc_html__( 'Choose how you want to hear about each of these. Clear every box in a row to turn that notification off.', 'notifications-for-hivepress' ),
				'message'     => esc_html__( 'Your notification settings have been saved.', 'notifications-for-hivepress' ),
				'action'      => hivepress()->router->get_url( 'notification_update_action' ),
				'fields'      => $fields,

				// Core's form script resets a form after a successful save unless it carries a
				// data-id ("form.data('reset') || !form.is('[data-id]')", common.js:1196). Without
				// this the tick a user just cleared springs straight back, so a save that worked
				// looks like one that failed - and a second save then posts those restored values
				// over the top, undoing the change for real. The id is the user whose preferences
				// these are, which is what a model-backed form would put there.
				'attributes'  => [
					'data-id' => get_current_user_id(),
				],

				'button'      => [
					'label' => esc_html__( 'Save Changes', 'notifications-for-hivepress' ),
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
