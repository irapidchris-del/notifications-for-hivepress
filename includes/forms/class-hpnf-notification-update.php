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
class Hpnf_Notification_Update extends Form {

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
		$labels = hivepress()->hpnf_notification->get_channels();

		// Get fields.
		$fields = [];
		$order  = 10;
		$note   = esc_html__( 'On-site means your notifications list, the bell menu and pop-ups while you browse. Not every notification has an email or a push behind it, so those two only apply where one exists.', 'notifications-for-hivepress' );

		// The SMS channel only exists when another plugin registers it, so its caveat only renders
		// when the boxes it explains are on the screen. It matters because texting is gated twice:
		// by the member's tick here, and by the event having an SMS message saved by the admin.
		if ( isset( $labels['sms'] ) ) {
			$note .= ' ' . esc_html__( 'Texts apply only where an event has an SMS message set up.', 'notifications-for-hivepress' );
		}

		foreach ( hivepress()->hpnf_notification->get_groups() as $group => $group_label ) {
			$channels = [];

			foreach ( hivepress()->hpnf_notification->get_enabled_types() as $type ) {
				if ( hivepress()->hpnf_notification->get_type_group( $type ) !== $group ) {
					continue;
				}

				// System types can't be switched off, so they don't appear here at all.
				if ( hp\get_array_value( hivepress()->hpnf_notification->get_type_args( $type ), '_system' ) ) {
					continue;
				}

				// Only offer the channels this group can actually be delivered through, so a
				// group with no email behind it doesn't offer an email that never arrives.
				$channels += array_intersect_key( $labels, array_flip( hivepress()->hpnf_notification->get_type_channels( $type ) ) );
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
			 *
			 * It is said once, on the first group only. Repeating it under all seven turned the page
			 * into the same wall of identical paragraphs that the dashboard widget was pulled up on.
			 */
			$fields[ $group ] = [
				'label'   => $group_label,
				'type'    => 'checkboxes',
				'options' => $channels,
				'default' => array_keys( $channels ),
				'_order'  => $order,
			];

			if ( $note ) {
				$fields[ $group ]['description'] = $note;
				$note                            = '';
			}

			$order += 10;
		}

		// Add quiet hours. Hours run on the site's clock, and setting both the same switches
		// them off.
		$hours = [];

		for ( $hour = 0; $hour <= 23; $hour++ ) {
			$hours[ $hour ] = sprintf( '%02d:00', $hour );
		}

		$quiet = hivepress()->hpnf_notification->get_quiet_hours( get_current_user_id() );

		// Text messages are only mentioned while an SMS channel is actually registered (by the
		// Twilio extension); on a site without one the sentence promised to silence something
		// that does not exist.
		$quiet_hint = isset( $labels['sms'] )
			? esc_html__( 'No pop-ups, push notifications or text messages between these times. Anything with an on-site notification still waits in your list. Set both the same to switch this off.', 'notifications-for-hivepress' )
			: esc_html__( 'No pop-ups or push notifications between these times. Anything with an on-site notification still waits in your list. Set both the same to switch this off.', 'notifications-for-hivepress' );

		$fields['quiet_start'] = [
			'label'       => esc_html__( 'Quiet Hours', 'notifications-for-hivepress' ),
			'description' => $quiet_hint,
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
				'description' => esc_html__( 'Choose how you want to hear about each of these. Clear every box in a group to disable that kind of notification altogether.', 'notifications-for-hivepress' ),
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
