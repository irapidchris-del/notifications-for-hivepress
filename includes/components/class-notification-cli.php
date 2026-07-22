<?php
/**
 * Notification CLI component.
 *
 * @package HivePress\Notifications\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adds WP-CLI commands.
 */
final class Notification_CLI extends Component {

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'hivepress notifications recount', [ $this, 'recount' ] );
			\WP_CLI::add_command( 'hivepress notifications cleanup', [ $this, 'cleanup' ] );
			\WP_CLI::add_command( 'hivepress notifications types', [ $this, 'types' ] );
		}

		parent::__construct( $args );
	}

	/**
	 * Rebuilds the unread counts and type lists.
	 *
	 * The counts are cached in user meta, so this is the fix if they ever drift, after a bulk
	 * import or a database restore.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hivepress notifications recount
	 */
	public function recount() {
		$user_ids = get_users(
			[
				'fields' => 'ID',
				'number' => -1,
			]
		);

		$count = 0;

		foreach ( $user_ids as $user_id ) {
			hivepress()->notification->update_unread_count( $user_id );
			hivepress()->notification->rebuild_used_types( $user_id );

			++$count;
		}

		\WP_CLI::success( sprintf( 'Recounted notifications for %d users.', $count ) );
	}

	/**
	 * Deletes notifications older than the storage period.
	 *
	 * Runs the same routine as the daily event, for when you'd rather not wait for it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hivepress notifications cleanup
	 */
	public function cleanup() {
		if ( ! absint( get_option( 'hp_notification_storage_period' ) ) ) {
			\WP_CLI::warning( 'No storage period is set, so nothing is deleted.' );

			return;
		}

		hivepress()->notification->delete_notifications();

		\WP_CLI::success( 'Cleanup complete.' );
	}

	/**
	 * Lists the notification types.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hivepress notifications types
	 */
	public function types() {
		$enabled = hivepress()->notification->get_enabled_types();
		$rows    = [];

		foreach ( hivepress()->notification->get_types() as $name => $args ) {
			$rows[] = [
				'name'     => $name,
				'label'    => $args['label'],
				'enabled'  => in_array( $name, $enabled, true ) ? 'yes' : 'no',
				'channels' => implode( ', ', hivepress()->notification->get_type_channels( $name ) ),
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'name', 'label', 'enabled', 'channels' ] );
	}
}
