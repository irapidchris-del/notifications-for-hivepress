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

		/**
		 * Filters the capability a member needs to be offered the owner-only groups.
		 *
		 * @hook hpnf_notification_owner_group_capability
		 * @param {string} $capability Capability name.
		 * @return {string} Capability name.
		 */
		$hpnf_owner_capability = apply_filters( 'hpnf_notification_owner_group_capability', 'manage_options' );
		$hpnf_is_owner         = current_user_can( $hpnf_owner_capability );

		foreach ( hivepress()->hpnf_notification->get_groups() as $group => $group_label ) {
			/*
			 * "For Site Owners" is not a feature area, it is an audience: every type in it reaches
			 * whoever runs the site, not the member reading this form. Offering them here let every
			 * signed-in member see, and switch, preferences for notifications they can never receive
			 * - reported by Chris on 2026-09-02 and present since at least v1.3.4, so it is a
			 * long-standing gap rather than a new one.
			 *
			 * The group holds sixteen types, not the three it held when this guard was written.
			 * Thirteen more were found on 2026-09-02 by reading the recipient at each send site:
			 * they all go to `get_option( 'admin_email' )` but were filed by name prefix under
			 * Listings, Orders and Requests. See Hpnf_Notification::OWNER_TYPES.
			 *
			 * Gated on the group rather than on the individual types, because the group IS the
			 * statement about audience; a type declaring `'group' => 'admin'` is declaring who
			 * reads it. See Hpnf_Notification::get_groups().
			 *
			 * The save path needs no separate guard: a member's form never carries these fields, so
			 * HivePress has nothing to accept for them even if a crafted POST names them.
			 */
			if ( 'admin' === $group && ! $hpnf_is_owner ) {
				continue;
			}

			$channels = [];

			foreach ( hivepress()->hpnf_notification->get_enabled_types() as $type ) {
				if ( hivepress()->hpnf_notification->get_type_group( $type ) !== $group ) {
					continue;
				}

				// System types can't be switched off, so they don't appear here at all.
				if ( hp\get_array_value( hivepress()->hpnf_notification->get_type_args( $type ), '_system' ) ) {
					continue;
				}

				/*
				 * Nor do the ones this member could never receive. A type declaring itself
				 * vendor-only is about somebody's own listings, gallery or figures, so offering it
				 * to a member who does not sell asks them to make a choice with no effect.
				 *
				 * The group's field survives as long as ONE type in it is offered, because the field
				 * is per group. That is why this does not, on its own, empty many groups: Bookings
				 * and Orders reach both sides of a transaction and stay whoever is reading. The
				 * group that does empty is Performance, every type of which is a vendor's own
				 * analytics, and emptying is exactly what should happen to it.
				 */
				if ( ! hivepress()->hpnf_notification->is_type_offered( $type ) ) {
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
