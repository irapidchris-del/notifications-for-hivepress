<?php
/**
 * Notification model.
 *
 * @package HivePress\Notifications\Models
 */

namespace HivePress\Models;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Notification sent to a user.
 *
 * Stored as a comment of the "hp_notification" type, matching the storage model used by the
 * Messages, Reviews and Favorites extensions. The read flag uses the comment karma column so
 * that comment_approved stays at 1 and notifications are never treated as pending comments.
 *
 * Field getters are resolved by the model's __call(), so they are declared here for static
 * analysis and editor completion. Each one matches a field defined in the constructor below.
 *
 * @method string get_text()
 * @method int get_user__id()
 * @method \HivePress\Models\User|null get_user()
 * @method string get_created_date()
 * @method int get_read()
 * @method string get_type()
 * @method string get_image()
 * @method string get_url()
 * @method string get_icon()
 * @method string get_color()
 */
class Hpnf_Notification extends Comment {

	/**
	 * Class initializer.
	 *
	 * The model name and alias are pinned to their pre-prefix values. The alias IS the stored
	 * comment_type of every existing notification (Comment::init derives it from the class name,
	 * models/class-comment.php:29, and Comment::get refuses rows whose type differs, :56), so
	 * deriving it from the new prefixed class name would strand every stored notification.
	 *
	 * The model-update event now fires under two names, and the pin is why. The Hook component
	 * resolves the model name from the CLASS short name (components/class-model.php:27-33), so
	 * comment-row updates such as read toggles via wp_update_comment fire
	 * "hivepress/v1/models/hpnf_notification/update". Meta-only Comment::save() paths instead
	 * read the pinned meta name (models/class-comment.php:159) and keep firing
	 * "hivepress/v1/models/notification/update". No consumer of either exists in any searched
	 * tree; this records the split rather than resolving it.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'name'  => 'notification',
				'alias' => 'hp_notification',
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Class constructor.
	 *
	 * @param array $args Model arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'fields' => [
					'text'         => [
						'label'      => esc_html__( 'Text', 'notifications-for-hivepress' ),
						'type'       => 'text',
						'max_length' => 256,
						'required'   => true,
						'_alias'     => 'comment_content',
					],

					'user'         => [
						'type'     => 'id',
						'required' => true,
						'_alias'   => 'user_id',
						'_model'   => 'user',
					],

					'created_date' => [
						'type'     => 'date',
						'format'   => 'Y-m-d H:i:s',
						'required' => true,
						'_alias'   => 'comment_date',
					],

					'read'         => [
						'type'      => 'number',
						'min_value' => 0,
						'max_value' => 1,
						'required'  => true,
						'_alias'    => 'comment_karma',
					],

					'type'         => [
						'type'       => 'text',
						'max_length' => 128,
						'required'   => true,
						'_external'  => true,
					],

					'image'        => [
						'type'      => 'url',
						'_external' => true,
					],

					// A notification can carry its own icon and colour instead of the generic one
					// its type would give it, so a badge award can show the badge that was
					// actually earned rather than a stand-in.
					'icon'         => [
						'type'       => 'text',
						'max_length' => 64,
						'_external'  => true,
					],

					'color'        => [
						'type'       => 'text',
						'max_length' => 7,
						'_external'  => true,
					],

					'url'          => [
						'type'      => 'url',
						'_external' => true,
					],

				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * Gets the notification type label.
	 *
	 * Falls back to a humanised version of the type name if the source email doesn't declare
	 * a label, which is the case for the emails HivePress sends to the site administrator.
	 *
	 * @return string
	 */
	public function get_type_label() {
		return hivepress()->hpnf_notification->get_type_label( $this->get_type() );
	}
}
