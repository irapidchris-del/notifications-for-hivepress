<?php
/**
 * Notification settings page template.
 *
 * @package HivePress\Notifications\Templates
 */

namespace HivePress\Templates;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Notification settings page.
 */
class Notification_Settings_Page extends User_Account_Page {

	/**
	 * Class constructor.
	 *
	 * @param array $args Template arguments.
	 */
	public function __construct( $args = [] ) {
		$blocks = [
			'notification_update_form' => [
				'type'   => 'form',
				'form'   => 'notification_update',
				'values' => hivepress()->notification->get_user_preferences( get_current_user_id() ),
				'_label' => esc_html__( 'Notification Settings', 'notifications-for-hivepress' ),
				'_order' => 10,
			],
		];

		// Offer a button to switch push on for this device. Waiting for the automatic prompt is
		// fine, but someone on their own settings page has earned a direct switch. The script
		// reveals it only where the browser supports push. The admin option is read directly here,
		// because is_enabled() already requires keys and could never expose the failure below.
		if ( hivepress()->notification_push && get_option( 'hp_notification_push', true ) && ! hivepress()->notification_push->get_keys() ) {

			// Push is switched on but the keys could not be generated, which means this server's
			// OpenSSL can't do the required curve. Saying so beats a button that silently fails.
			$blocks['notification_push_setup'] = [
				'type'    => 'content',
				'_order'  => 20,

				'content' => '<div class="hp-notification-push"><span class="hp-notification-push__status">' . esc_html__( 'Push notifications are switched on, but this server could not generate the required keys, so they are unavailable. Your host can enable OpenSSL elliptic curve support.', 'notifications-for-hivepress' ) . '</span></div>',
			];
		} elseif ( hivepress()->notification_push && hivepress()->notification_push->is_enabled() ) {
			$blocks['notification_push_setup'] = [
				'type'    => 'content',
				'_order'  => 20,

				'content' => '<div class="hp-notification-push" data-component="notification-push-setup" hidden data-label-enable="' . esc_attr__( 'Enable push notifications on this device', 'notifications-for-hivepress' ) . '" data-label-enabled="' . esc_attr__( 'Push notifications are on for this device.', 'notifications-for-hivepress' ) . '" data-label-blocked="' . esc_attr__( 'Push notifications are blocked for this site in your browser settings.', 'notifications-for-hivepress' ) . '" data-label-working="' . esc_attr__( 'Waiting for your browser…', 'notifications-for-hivepress' ) . '"><button type="button" class="hp-button button button--primary"></button><span class="hp-notification-push__status"></span></div>',
			];
		}

		$args = hp\merge_trees(
			[
				'blocks' => [
					'page_content' => [
						'blocks' => $blocks,
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
