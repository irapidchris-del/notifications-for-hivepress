<?php
/**
 * Notification broadcast component.
 *
 * @package HivePress\Notifications\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sends a notification to everyone, to one role, to the vendors, or to specific users.
 *
 * This lives on its own admin page rather than in the settings tab, because settings are saved and a
 * broadcast is sent. Putting a button that messages every user behind a "Save Changes" is the kind
 * of thing that gets pressed by accident.
 */
final class Notification_Broadcast extends Component {

	/**
	 * Number of users per batch.
	 *
	 * @var int
	 */
	protected $number = 50;

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Add the type.
		add_filter( 'hivepress/v1/notification_types', [ $this, 'add_type' ] );

		if ( is_admin() ) {

			// Add the page.
			add_action( 'admin_menu', [ $this, 'add_page' ], 20 );

			// Send the broadcast.
			add_action( 'admin_post_hp_notification_broadcast', [ $this, 'send_broadcast' ] );
		}

		// Send the batches.
		add_action( 'hivepress/v1/notifications/broadcast', [ $this, 'send_batch' ], 10, 5 );
		add_action( 'hivepress/v1/notifications/broadcast_users', [ $this, 'send_users_batch' ], 10, 4 );

		// Add the dashboard widget.
		add_action( 'wp_dashboard_setup', [ $this, 'add_dashboard_widget' ] );

		// Add the statistics page. Priority 35 lands it after the HivePress menu exists.
		add_action( 'admin_menu', [ $this, 'add_stats_page' ], 35 );

		parent::__construct( $args );
	}

	/**
	 * Adds the broadcast notification type.
	 *
	 * @param array $types Notification types.
	 * @return array
	 */
	public function add_type( $types ) {
		$types['broadcast'] = [
			'label'    => esc_html__( 'Announcement', 'notifications-for-hivepress' ),
			'tokens'   => [ 'user' ],
			'channels' => [ 'onsite', 'push' ],
			'icon'     => 'bullhorn',
			'_system'  => true,
		];

		return $types;
	}

	/**
	 * Adds the admin page.
	 */
	public function add_page() {
		add_submenu_page(
			'hp_settings',
			esc_html__( 'Announcements', 'notifications-for-hivepress' ) . ' &lsaquo; ' . hivepress()->get_name(),
			esc_html__( 'Announcements', 'notifications-for-hivepress' ),
			'manage_options',
			'hp_notification_broadcast',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Renders the admin page.
	 */
	public function render_page() {

		// These only feed the notices after the redirect; nothing is processed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sent = absint( hp\get_array_value( $_GET, 'sent' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = sanitize_text_field( wp_unslash( (string) hp\get_array_value( $_GET, 'error' ) ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Announcements', 'notifications-for-hivepress' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=hp_settings&tab=notifications' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Notification Settings', 'notifications-for-hivepress' ); ?></a>
			<hr class="wp-header-end">

			<?php if ( $sent ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: number of users. */
								_n( 'The announcement is on its way to %s user.', 'The announcement is on its way to %s users.', $sent, 'notifications-for-hivepress' ),
								number_format_i18n( $sent )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: the names that could not be matched. */
								esc_html__( 'Nothing was sent. These could not be matched to an account: %s', 'notifications-for-hivepress' ),
								$error
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<p class="description" style="max-width:40em;">
				<?php esc_html_e( 'This sends an on-site notification to every user you choose, and there is no opt-out, so use it sparingly: anything that could wait for a newsletter probably should.', 'notifications-for-hivepress' ); ?>
			</p>

			<?php $this->render_form(); ?>

			<?php if ( get_option( 'hp_notification_stats', true ) ) : ?>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=hp_notification_stats' ) ); ?>"><?php esc_html_e( 'View delivery statistics', 'notifications-for-hivepress' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}


	/**
	 * Renders the announcement form.
	 *
	 * Shared by the Announcements page and the dashboard widget, so both always behave the same.
	 */
	public function render_form() {
		$roles = [
			''         => esc_html__( 'Everyone', 'notifications-for-hivepress' ),
			'_vendors' => esc_html__( 'Vendors (users with a listing profile)', 'notifications-for-hivepress' ),
			'_users'   => esc_html__( 'Specific users…', 'notifications-for-hivepress' ),
		];

		foreach ( wp_roles()->get_names() as $role => $label ) {
			$roles[ $role ] = translate_user_role( $label );
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="hp_notification_broadcast">
			<?php wp_nonce_field( 'hp_notification_broadcast' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="hp-broadcast-text"><?php esc_html_e( 'Message', 'notifications-for-hivepress' ); ?></label></th>
					<td>
						<textarea name="text" id="hp-broadcast-text" rows="3" class="large-text" maxlength="256" required></textarea>
						<p class="description">
							<?php
							/* translators: %user.display_name% is a literal token the admin types, not a placeholder. */
							esc_html_e( 'Up to 256 characters. You can use %user.display_name% to greet people by name.', 'notifications-for-hivepress' );
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="hp-broadcast-url"><?php esc_html_e( 'Link', 'notifications-for-hivepress' ); ?></label></th>
					<td>
						<input type="url" name="url" id="hp-broadcast-url" class="regular-text" placeholder="https://">
						<p class="description"><?php esc_html_e( 'Optional. Adds a View link to the notification.', 'notifications-for-hivepress' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="hp-broadcast-role"><?php esc_html_e( 'Send To', 'notifications-for-hivepress' ); ?></label></th>
					<td>
						<select name="role" id="hp-broadcast-role">
							<?php foreach ( $roles as $role => $label ) : ?>
								<option value="<?php echo esc_attr( $role ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description" style="max-width:34em;">
							<?php esc_html_e( 'The roles below the line are WordPress roles. HivePress adds no roles of its own: everyone keeps the site default from Settings, usually Subscriber, or Customer where WooCommerce assigns it. Being a vendor is a profile rather than a role, which is what the Vendors option is for.', 'notifications-for-hivepress' ); ?>
						</p>
					</td>
				</tr>

				<tr id="hp-broadcast-users-row">
					<th scope="row"><label for="hp-broadcast-users"><?php esc_html_e( 'Users', 'notifications-for-hivepress' ); ?></label></th>
					<td>
						<input type="text" name="users" id="hp-broadcast-users" class="large-text" list="hp-broadcast-users-list" placeholder="chris, jo@example.com" autocomplete="off">
					<?php $this->render_user_datalist(); ?>
						<p class="description"><?php esc_html_e( 'Usernames or email addresses, separated by commas or spaces; suggestions match the first one typed. Only used when sending to specific users; if any cannot be matched, nothing is sent.', 'notifications-for-hivepress' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Confirm', 'notifications-for-hivepress' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="confirm" value="1" required>
							<?php esc_html_e( 'I understand this notifies every user I have chosen.', 'notifications-for-hivepress' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( esc_html__( 'Send Announcement', 'notifications-for-hivepress' ) ); ?>
		</form>

		<script>
			( function() {
				var role = document.getElementById( 'hp-broadcast-role' );
				var row  = document.getElementById( 'hp-broadcast-users-row' );

				function sync() {
					row.style.display = '_users' === role.value ? '' : 'none';
				}

				role.addEventListener( 'change', sync );
				sync();
			}() );
		</script>
		<?php
	}

	/**
	 * Prints name and email suggestions for the users field.
	 *
	 * A datalist is native, so there's nothing to script or style; browsers match suggestions
	 * against the first entry typed.
	 */
	protected function render_user_datalist() {
		$users = get_users(
			[
				'number'  => 300,
				'orderby' => 'display_name',
				'fields'  => [ 'user_login', 'user_email', 'display_name' ],
			]
		);

		if ( ! $users ) {
			return;
		}
		?>
		<datalist id="hp-broadcast-users-list">
			<?php foreach ( $users as $user ) : ?>
				<option value="<?php echo esc_attr( $user->user_login ); ?>"><?php echo esc_html( $user->display_name . ' — ' . $user->user_email ); ?></option>
			<?php endforeach; ?>
		</datalist>
		<?php
	}

	/**
	 * Renders the delivery statistics.
	 */
	protected function render_stats() {
		$stats = hivepress()->notification->get_stats();

		if ( ! $stats ) {
			return;
		}

		uasort(
			$stats,
			function ( $a, $b ) {
				return hp\get_array_value( $b, 'sent', 0 ) <=> hp\get_array_value( $a, 'sent', 0 );
			}
		);
		?>
		<table class="widefat striped" style="max-width:640px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Notification', 'notifications-for-hivepress' ); ?></th>
					<th><?php esc_html_e( 'Sent', 'notifications-for-hivepress' ); ?></th>
					<th><?php esc_html_e( 'Opened', 'notifications-for-hivepress' ); ?></th>
					<th><?php esc_html_e( 'Open Rate', 'notifications-for-hivepress' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $stats as $type => $counts ) : ?>
					<?php
					$sent   = absint( hp\get_array_value( $counts, 'sent' ) );
					$opened = absint( hp\get_array_value( $counts, 'clicked' ) );
					?>
					<tr>
						<td><?php echo esc_html( hivepress()->notification->get_type_label( $type ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $sent ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $opened ) ); ?></td>
						<td><?php echo esc_html( $sent ? number_format_i18n( round( $opened / $sent * 100 ) ) . '%' : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description">
			<?php esc_html_e( 'Anonymous counts. Switch this off under Settings, in the Delivery section.', 'notifications-for-hivepress' ); ?>
		</p>
		<?php
	}

	/**
	 * Registers the statistics page, while counting is switched on.
	 */
	public function add_stats_page() {
		if ( ! get_option( 'hp_notification_stats', true ) ) {
			return;
		}

		add_submenu_page( 'hp_settings', esc_html__( 'Notification Statistics', 'notifications-for-hivepress' ), esc_html__( 'Statistics', 'notifications-for-hivepress' ), 'manage_options', 'hp_notification_stats', [ $this, 'render_stats_page' ] );
	}

	/**
	 * Renders the statistics page.
	 */
	public function render_stats_page() {
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Notification Statistics', 'notifications-for-hivepress' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=hp_settings&tab=notifications' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Notification Settings', 'notifications-for-hivepress' ); ?></a>
			<hr class="wp-header-end">

			<?php if ( hivepress()->notification->get_stats() ) : ?>
				<?php $this->render_stats(); ?>
			<?php else : ?>
				<p><?php esc_html_e( 'Nothing counted yet. Sent and opened totals appear here once notifications start flowing.', 'notifications-for-hivepress' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Registers the dashboard widget.
	 */
	public function add_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget( 'hp_notification_broadcast', esc_html__( 'Announcements', 'notifications-for-hivepress' ), [ $this, 'render_widget' ] );
	}

	/**
	 * Renders the dashboard widget.
	 */
	public function render_widget() {
		?>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=hp_notification_broadcast' ) ); ?>"><?php esc_html_e( 'Announcements page', 'notifications-for-hivepress' ); ?></a>
			&nbsp;·&nbsp;
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=hp_settings&tab=notifications' ) ); ?>"><?php esc_html_e( 'Notification settings', 'notifications-for-hivepress' ); ?></a>
		</p>
		<?php
		$this->render_form();
	}

	/**
	 * Handles the form.
	 */
	public function send_broadcast() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'notifications-for-hivepress' ) );
		}

		check_admin_referer( 'hp_notification_broadcast' );

		// Get arguments.
		$text = sanitize_text_field( wp_unslash( (string) hp\get_array_value( $_POST, 'text' ) ) );
		$url  = esc_url_raw( wp_unslash( (string) hp\get_array_value( $_POST, 'url' ) ) );
		$role = sanitize_key( (string) hp\get_array_value( $_POST, 'role' ) );

		if ( ! $text || ! hp\get_array_value( $_POST, 'confirm' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=hp_notification_broadcast' ) );

			exit;
		}

		$text = mb_substr( $text, 0, 256 );

		// Specific users: resolve every name before sending anything, so a typo can't mean half
		// an announcement.
		if ( '_users' === $role ) {
			$names = preg_split( '/[\s,]+/', sanitize_text_field( wp_unslash( (string) hp\get_array_value( $_POST, 'users' ) ) ), -1, PREG_SPLIT_NO_EMPTY );

			$user_ids   = [];
			$unresolved = [];

			foreach ( (array) $names as $name ) {
				$user = get_user_by( 'login', $name );

				if ( ! $user ) {
					$user = get_user_by( 'email', $name );
				}

				if ( $user ) {
					$user_ids[] = (int) $user->ID;
				} else {
					$unresolved[] = $name;
				}
			}

			if ( $unresolved || ! $user_ids ) {
				wp_safe_redirect( admin_url( 'admin.php?page=hp_notification_broadcast&error=' . rawurlencode( implode( ', ', array_slice( $unresolved ? $unresolved : [ '—' ], 0, 10 ) ) ) ) );

				exit;
			}

			$count = $this->dispatch_user_ids( $text, $url, array_unique( $user_ids ) );

			wp_safe_redirect( admin_url( 'admin.php?page=hp_notification_broadcast&sent=' . absint( $count ) ) );

			exit;
		}

		// Vendors: anyone with a published vendor profile, which is not a role.
		if ( '_vendors' === $role ) {
			$count = $this->dispatch_user_ids( $text, $url, $this->get_vendor_user_ids() );

			wp_safe_redirect( admin_url( 'admin.php?page=hp_notification_broadcast&sent=' . absint( $count ) ) );

			exit;
		}

		if ( $role && ! wp_roles()->is_role( $role ) ) {
			$role = '';
		}

		// Get the audience size.
		$count = ( new \WP_User_Query(
			array_filter(
				[
					'role'        => $role,
					'fields'      => 'ID',
					'number'      => 1,
					'count_total' => true,
				]
			)
		) )->get_total();

		// Send the first batch now, so a small site sees it land straight away, and let the
		// scheduler carry the rest. Notifying thousands of users inside one request would time out.
		// The context makes this broadcast's batches unique, because the scheduler drops an action
		// whose hook and arguments it has already queued, and the same announcement sent twice is
		// a thing an admin is allowed to do.
		$this->send_batch( $text, $url, $role, 0, time() );

		wp_safe_redirect( admin_url( 'admin.php?page=hp_notification_broadcast&sent=' . absint( $count ) ) );

		exit;
	}

	/**
	 * Sends to a list of user IDs, batching anything beyond the first chunk.
	 *
	 * @param string $text Message text.
	 * @param string $url Message URL.
	 * @param array  $user_ids User IDs.
	 * @return int
	 */
	protected function dispatch_user_ids( $text, $url, $user_ids ) {
		$user_ids = array_values( array_filter( array_map( 'absint', (array) $user_ids ) ) );

		if ( ! $user_ids ) {
			return 0;
		}

		$context = time();
		$chunks  = array_chunk( $user_ids, $this->number );

		// The first chunk goes now; the rest ride the scheduler a minute apart.
		$this->send_users_batch( $text, $url, array_shift( $chunks ), $context );

		foreach ( $chunks as $index => $chunk ) {
			hivepress()->scheduler->add_action( 'hivepress/v1/notifications/broadcast_users', [ $text, $url, $chunk, $context ], time() + ( $index + 1 ) * MINUTE_IN_SECONDS );
		}

		return count( $user_ids );
	}

	/**
	 * Gets the user IDs of everyone with a published vendor profile.
	 *
	 * @return array
	 */
	protected function get_vendor_user_ids() {
		global $wpdb;

		// A one-off read the moment an admin presses Send, with the result consumed immediately,
		// so there is nothing worth caching.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_author > 0", 'hp_vendor', 'publish' ) );

		return array_map( 'absint', (array) $user_ids );
	}

	/**
	 * Sends one announcement batch to a list of user IDs.
	 *
	 * @param string $text Message text.
	 * @param string $url Message URL.
	 * @param array  $user_ids User IDs.
	 * @param int    $context Broadcast context.
	 */
	public function send_users_batch( $text, $url, $user_ids, $context = 0 ) {
		foreach ( array_map( 'absint', (array) $user_ids ) as $user_id ) {

			// Skip anything that isn't a real user ID.
			if ( ! $user_id ) {
				continue;
			}

			// Get user.
			$user = \HivePress\Models\User::query()->get_by_id( $user_id );

			if ( ! $user ) {
				continue;
			}

			hivepress()->notification->add_notification(
				[
					'user' => $user_id,
					'type' => 'broadcast',
					'text' => hivepress()->notification->render_text( $text, [ 'user' => $user ] ),
					'url'  => $url,
				]
			);
		}
	}

	/**
	 * Sends one batch and queues the next.
	 *
	 * @param string $text Message text.
	 * @param string $url Message URL.
	 * @param string $role User role.
	 * @param int    $offset Batch offset.
	 * @param int    $context Broadcast context.
	 */
	public function send_batch( $text, $url, $role, $offset, $context = 0 ) {
		$offset = absint( $offset );

		// Get users.
		$user_ids = get_users(
			array_filter(
				[
					'role'    => $role,
					'fields'  => 'ID',
					'number'  => $this->number,
					'offset'  => $offset,
					'orderby' => 'ID',
					'order'   => 'ASC',
				]
			)
		);

		if ( ! $user_ids ) {
			return;
		}

		foreach ( $user_ids as $user_id ) {

			// Get user.
			$user = \HivePress\Models\User::query()->get_by_id( $user_id );

			if ( ! $user ) {
				continue;
			}

			hivepress()->notification->add_notification(
				[
					'user' => $user_id,
					'type' => 'broadcast',
					'text' => hivepress()->notification->render_text( $text, [ 'user' => $user ] ),
					'url'  => $url,
				]
			);
		}

		if ( count( $user_ids ) < $this->number ) {
			return;
		}

		// Queue the next batch.
		hivepress()->scheduler->add_action( 'hivepress/v1/notifications/broadcast', [ $text, $url, $role, $offset + $this->number, $context ], time() + MINUTE_IN_SECONDS );
	}
}
