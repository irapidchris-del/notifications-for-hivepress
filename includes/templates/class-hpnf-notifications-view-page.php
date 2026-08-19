<?php
/**
 * Notifications view page template.
 *
 * @package HivePress\Notifications\Templates
 */

namespace HivePress\Templates;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Notifications page.
 */
class Hpnf_Notifications_View_Page extends User_Account_Page {

	/**
	 * Class constructor.
	 *
	 * @param array $args Template arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_trees(
			[
				'blocks' => [
					'page_content' => [
						'blocks' => [
							'notification_list' => [
								'type'   => 'hpnf_notifications',
								'_label' => esc_html__( 'Notifications', 'notifications-for-hivepress' ),
								'_order' => 10,
							],
						],
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
