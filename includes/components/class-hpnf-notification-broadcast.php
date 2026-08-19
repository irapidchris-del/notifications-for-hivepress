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
final class Hpnf_Notification_Broadcast extends Component {

	/**
	 * Option holding the sent announcements.
	 *
	 * @var string
	 */
	const LOG_OPTION = 'hp_notification_broadcast_log';

	/**
	 * How many sent announcements are kept.
	 *
	 * @var int
	 */
	const LOG_LIMIT = 50;

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

			// Send a logged one again.
			add_action( 'admin_post_hp_notification_broadcast_resend', [ $this, 'resend_broadcast' ] );

			// Report the result on the Dashboard, which is where a send from the widget returns to.
			add_action( 'admin_notices', [ $this, 'render_dashboard_notices' ] );
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
	 * Renders the result of a send.
	 *
	 * Shared by the Announcements page and the Dashboard, because a send started from the widget
	 * comes back to the Dashboard and has to say what happened there.
	 */
	public function render_notices() {

		// These only feed the notices after the redirect; nothing is processed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sent = absint( hp\get_array_value( $_GET, 'sent' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = sanitize_text_field( wp_unslash( (string) hp\get_array_value( $_GET, 'error' ) ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$empty = (bool) hp\get_array_value( $_GET, 'empty' );

		if ( $sent ) :
			?>
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
			<?php
		endif;

		if ( $error ) :
			?>
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
			<?php
		endif;

		if ( $empty ) :
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php esc_html_e( 'Nothing was sent. Add at least one username or email address to send to.', 'notifications-for-hivepress' ); ?>
				</p>
			</div>
			<?php
		endif;
	}

	/**
	 * Renders the result of a widget send on the Dashboard.
	 *
	 * Restricted to the Dashboard itself, so these never appear on an unrelated admin screen that
	 * happens to carry a "sent" parameter of its own.
	 */
	public function render_dashboard_notices() {
		$screen = get_current_screen();

		if ( ! $screen || 'dashboard' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_notices();
	}

	/**
	 * Renders the admin page.
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Announcements', 'notifications-for-hivepress' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=hp_settings&tab=notifications' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Notification Settings', 'notifications-for-hivepress' ); ?></a>
			<hr class="wp-header-end">

			<?php
			$this->render_notices();
			?>

			<?php
			// Which tab. Reading it decides what to render and nothing else.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$view = 'history' === sanitize_key( (string) hp\get_array_value( $_GET, 'view' ) ) ? 'history' : 'send';
			?>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=hp_notification_broadcast' ) ); ?>" class="nav-tab <?php echo 'send' === $view ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Send', 'notifications-for-hivepress' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=hp_notification_broadcast&view=history' ) ); ?>" class="nav-tab <?php echo 'history' === $view ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'History', 'notifications-for-hivepress' ); ?>
					<?php if ( $this->get_log() ) : ?>
						<span class="count">(<?php echo esc_html( number_format_i18n( count( $this->get_log() ) ) ); ?>)</span>
					<?php endif; ?>
				</a>
			</h2>

			<?php if ( 'history' === $view ) : ?>
				<?php $this->render_history(); ?>
			<?php else : ?>
				<p class="description" style="max-width:40em;">
					<?php esc_html_e( 'Sends an on-site notification, and a push notification to anyone who has allowed those. Announcements are never emailed and cannot be opted out of, so use them sparingly.', 'notifications-for-hivepress' ); ?>
					<span class="hp-tooltip"><span class="hp-tooltip__icon dashicons dashicons-editor-help"></span><span class="hp-tooltip__text"><?php esc_html_e( 'Because there is no opt-out, each person\'s own channel choices are bypassed. Quiet hours are still respected: the announcement waits in their list rather than interrupting. Nothing is emailed, so an announcement will not reach anyone who has stopped visiting the site.', 'notifications-for-hivepress' ); ?></span></span>
				</p>

				<?php $this->render_form(); ?>

				<?php if ( get_option( 'hp_notification_stats', true ) ) : ?>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=hp_notification_stats' ) ); ?>"><?php esc_html_e( 'View delivery statistics', 'notifications-for-hivepress' ); ?></a>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the sent announcements.
	 *
	 * A table rather than a feed: the useful questions here are what went out, when, to whom and
	 * how many it reached, which read best in columns.
	 */
	protected function render_history() {
		$log = $this->get_log();

		if ( ! $log ) {
			?>
			<p><?php esc_html_e( 'Nothing sent yet. Announcements you send from the Send tab are listed here, with the wording, who received them and when.', 'notifications-for-hivepress' ); ?></p>
			<?php

			return;
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<p class="description" style="max-width:40em;">
			<?php
			// Written out rather than run through _n(): the singular has no number to substitute,
			// and a plural set whose singular carries no placeholder cannot be translated correctly
			// into languages that need one in every form.
			if ( 1 === count( $log ) ) {
				esc_html_e( 'The last announcement you sent. Resend sends it again to that audience as it stands today, not to the people who received it originally.', 'notifications-for-hivepress' );
			} else {
				printf(
					/* translators: %s: number of announcements kept. */
					esc_html__( 'The last %s announcements you sent. Resend sends one again to that audience as it stands today, not to the people who received it originally.', 'notifications-for-hivepress' ),
					esc_html( number_format_i18n( count( $log ) ) )
				);
			}
			?>
		</p>

		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Sent', 'notifications-for-hivepress' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'notifications-for-hivepress' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Audience', 'notifications-for-hivepress' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Recipients', 'notifications-for-hivepress' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sent by', 'notifications-for-hivepress' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'notifications-for-hivepress' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $log as $entry ) :
					$sender = get_userdata( absint( hp\get_array_value( $entry, 'sender' ) ) );
					$time   = strtotime( (string) hp\get_array_value( $entry, 'sent' ) );
					?>
					<tr>
						<td>
							<?php
							// Stored on the site's clock, so date_i18n formats it as it is; wp_date
							// would add the offset a second time.
							echo esc_html( $time ? date_i18n( $format, $time ) : '' );
							?>
						</td>
						<td>
							<strong><?php echo esc_html( (string) hp\get_array_value( $entry, 'text' ) ); ?></strong>

							<?php if ( hp\get_array_value( $entry, 'url' ) ) : ?>
								<br>
								<a href="<?php echo esc_url( (string) $entry['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $entry['url'] ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $this->get_audience_label( $entry ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( absint( hp\get_array_value( $entry, 'count' ) ) ) ); ?></td>
						<td><?php echo esc_html( $sender ? $sender->display_name : esc_html__( 'Unknown', 'notifications-for-hivepress' ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
								<input type="hidden" name="action" value="hp_notification_broadcast_resend">
								<input type="hidden" name="entry" value="<?php echo esc_attr( (string) hp\get_array_value( $entry, 'id' ) ); ?>">
								<?php wp_nonce_field( 'hp_notification_broadcast_resend' ); ?>

								<button type="submit" class="button" onclick="return confirm( '<?php echo esc_js( __( 'Send this announcement again?', 'notifications-for-hivepress' ) ); ?>' );"><?php esc_html_e( 'Resend', 'notifications-for-hivepress' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}


	/**
	 * Renders a field label, with its explanation as a tooltip.
	 *
	 * Matches HivePress's own settings screen, which renders every field description as a hover
	 * tooltip beside the label (components/class-admin.php:322, :2064). Note core hides these
	 * below 782px, so nothing essential belongs in one.
	 *
	 * @param string $field_id Field id.
	 * @param string $label Field label.
	 * @param string $hint Tooltip text.
	 * @param bool   $compact Stacked layout.
	 */
	protected function render_label( $field_id, $label, $hint, $compact ) {
		$tooltip = '<div class="hp-tooltip"><span class="hp-tooltip__icon dashicons dashicons-editor-help"></span><div class="hp-tooltip__text">' . esc_html( $hint ) . '</div></div>';

		if ( $compact ) {
			echo '<div class="hp-broadcast__label"><label' . ( $field_id ? ' for="' . esc_attr( $field_id ) . '"' : '' ) . '>' . esc_html( $label ) . '</label>' . wp_kses_post( $tooltip ) . '</div>';

			return;
		}

		echo '<th scope="row"><div class="hp-broadcast__label"><label' . ( $field_id ? ' for="' . esc_attr( $field_id ) . '"' : '' ) . '>' . esc_html( $label ) . '</label>' . wp_kses_post( $tooltip ) . '</div></th>';
	}

	/**
	 * Renders the announcement form.
	 *
	 * Shared by the Announcements page and the dashboard widget, so both always behave the same.
	 *
	 * @param bool $compact Render stacked, for the narrow dashboard widget.
	 */
	public function render_form( $compact = false ) {

		// Count the audiences up front. Live counts beat any explanation of what the roles mean:
		// they are true on every install, and they make an accidental send to the wrong group
		// obvious before pressing the button rather than after.
		$counts  = count_users();
		$total   = absint( hp\get_array_value( $counts, 'total_users' ) );
		$vendors = count( $this->get_vendor_user_ids() );

		$roles = [
			/* translators: %s: number of users. */
			''         => sprintf( _n( 'Everyone (%s user)', 'Everyone (%s users)', $total, 'notifications-for-hivepress' ), number_format_i18n( $total ) ),
			/* translators: %s: number of users. */
			'_vendors' => sprintf( _n( 'Vendors, whatever their role (%s user)', 'Vendors, whatever their role (%s users)', $vendors, 'notifications-for-hivepress' ), number_format_i18n( $vendors ) ),
			'_users'   => esc_html__( 'Specific users…', 'notifications-for-hivepress' ),
		];

		foreach ( wp_roles()->get_names() as $role => $label ) {
			$role_count = absint( hp\get_array_value( (array) hp\get_array_value( $counts, 'avail_roles', [] ), $role ) );

			/* translators: %1$s: role name, %2$s: number of users. */
			$roles[ $role ] = sprintf( _n( '%1$s (%2$s user)', '%1$s (%2$s users)', $role_count, 'notifications-for-hivepress' ), translate_user_role( $label ), number_format_i18n( $role_count ) );
		}
		// The explanations live in tooltips rather than on the screen. Spelled out in full they
		// pushed the form off the bottom of the dashboard widget, and a wall of grey text above a
		// send button reads as a warning notice rather than as help.
		$hints = [
			/* translators: %user.display_name% is a literal token the admin types, not a placeholder. */
			'text'    => esc_html__( 'Up to 256 characters. Type %user.display_name% anywhere in the message to greet each person by name.', 'notifications-for-hivepress' ),
			'url'     => esc_html__( 'Optional. Adds a View link to the notification, so people can go straight to whatever it is about.', 'notifications-for-hivepress' ),
			'role'    => esc_html__( 'Counts are live, so you can see how many people each choice reaches before you send. The roles are WordPress roles, which your site or another plugin may have changed: HivePress promotes someone to Contributor when their vendor profile is published, so your vendors are usually Contributors. Vendors reaches anyone with a vendor profile whatever their role, which is normally what you want.', 'notifications-for-hivepress' ),
			'users'   => esc_html__( 'Usernames or email addresses, separated by commas or spaces. Suggestions match as you type. If any name cannot be matched, nothing is sent at all, so a typo can never mean half an announcement.', 'notifications-for-hivepress' ),
			'confirm' => esc_html__( 'Announcements cannot be opted out of and cannot be recalled once sent.', 'notifications-for-hivepress' ),
		];

		// Two layouts from one form: the settings-style two-column table on the full page, and a
		// stacked one in the dashboard widget, whose column is far too narrow for label-beside-field.
		$row   = $compact ? '<div class="hp-broadcast__row">' : '<tr>';
		$row_x = $compact ? '</div>' : '</tr>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hp-broadcast<?php echo $compact ? ' hp-broadcast--compact' : ''; ?>">
			<input type="hidden" name="action" value="hp_notification_broadcast">

			<?php // Which screen to return to, so the widget sends you back where you were. ?>
			<input type="hidden" name="origin" value="<?php echo $compact ? 'dashboard' : 'page'; ?>">
			<?php wp_nonce_field( 'hp_notification_broadcast' ); ?>

			<?php echo $compact ? '<div class="hp-broadcast__fields">' : '<table class="form-table" role="presentation"><tbody>'; ?>

				<?php echo wp_kses_post( $row ); ?>
					<?php $this->render_label( 'hp-broadcast-text', esc_html__( 'Message', 'notifications-for-hivepress' ), $hints['text'], $compact ); ?>
					<?php echo $compact ? '<div class="hp-broadcast__control">' : '<td>'; ?>
						<textarea name="text" id="hp-broadcast-text" rows="3" class="large-text" maxlength="256" required></textarea>
					<?php echo $compact ? '</div>' : '</td>'; ?>
				<?php echo wp_kses_post( $row_x ); ?>

				<?php echo wp_kses_post( $row ); ?>
					<?php $this->render_label( 'hp-broadcast-url', esc_html__( 'Link', 'notifications-for-hivepress' ), $hints['url'], $compact ); ?>
					<?php echo $compact ? '<div class="hp-broadcast__control">' : '<td>'; ?>
						<input type="url" name="url" id="hp-broadcast-url" class="regular-text" placeholder="https://">
					<?php echo $compact ? '</div>' : '</td>'; ?>
				<?php echo wp_kses_post( $row_x ); ?>

				<?php echo wp_kses_post( $row ); ?>
					<?php $this->render_label( 'hp-broadcast-role', esc_html__( 'Send To', 'notifications-for-hivepress' ), $hints['role'], $compact ); ?>
					<?php echo $compact ? '<div class="hp-broadcast__control">' : '<td>'; ?>
						<select name="role" id="hp-broadcast-role">
							<?php foreach ( $roles as $role => $label ) : ?>
								<option value="<?php echo esc_attr( $role ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php echo $compact ? '</div>' : '</td>'; ?>
				<?php echo wp_kses_post( $row_x ); ?>

				<?php echo $compact ? '<div class="hp-broadcast__row" id="hp-broadcast-users-row">' : '<tr id="hp-broadcast-users-row">'; ?>
					<?php $this->render_label( 'hp-broadcast-users', esc_html__( 'Users', 'notifications-for-hivepress' ), $hints['users'], $compact ); ?>
					<?php echo $compact ? '<div class="hp-broadcast__control">' : '<td>'; ?>
						<input type="text" name="users" id="hp-broadcast-users" class="large-text" list="hp-broadcast-users-list" placeholder="chris, jo@example.com" autocomplete="off">
						<?php $this->render_user_datalist(); ?>
					<?php echo $compact ? '</div>' : '</td>'; ?>
				<?php echo wp_kses_post( $row_x ); ?>

				<?php echo wp_kses_post( $row ); ?>
					<?php $this->render_label( '', esc_html__( 'Confirm', 'notifications-for-hivepress' ), $hints['confirm'], $compact ); ?>
					<?php echo $compact ? '<div class="hp-broadcast__control">' : '<td>'; ?>
						<label>
							<input type="checkbox" name="confirm" value="1" required>
							<?php esc_html_e( 'I understand this notifies everyone I have chosen.', 'notifications-for-hivepress' ); ?>
						</label>
					<?php echo $compact ? '</div>' : '</td>'; ?>
				<?php echo wp_kses_post( $row_x ); ?>

			<?php echo $compact ? '</div>' : '</tbody></table>'; ?>

			<?php submit_button( esc_html__( 'Send Announcement', 'notifications-for-hivepress' ), 'primary', 'submit', ! $compact ); ?>
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
				<option value="<?php echo esc_attr( $user->user_login ); ?>"><?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?></option>
			<?php endforeach; ?>
		</datalist>
		<?php
	}

	/**
	 * Renders the delivery statistics.
	 */
	protected function render_stats() {
		$stats = hivepress()->hpnf_notification->get_stats();

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
						<td><?php echo esc_html( hivepress()->hpnf_notification->get_type_label( $type ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $sent ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $opened ) ); ?></td>
						<?php // Capped at 100%. Each open is counted once now, but a site that ran an earlier build may already hold figures that were counted twice. ?>
						<td><?php echo esc_html( $sent ? number_format_i18n( min( 100, round( $opened / $sent * 100 ) ) ) . '%' : esc_html__( 'None sent yet', 'notifications-for-hivepress' ) ); ?></td>
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

			<?php if ( hivepress()->hpnf_notification->get_stats() ) : ?>
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
		<style>
			/* The dashboard column is roughly a third the width of a settings page, so the form
			 * stacks: labels above their fields and every control full width. Without this the
			 * two-column table forced the inputs out past the edge of the card. */
			.hp-broadcast--compact .hp-broadcast__row {
				margin-bottom: 12px;
			}

			.hp-broadcast--compact .hp-broadcast__label {
				display: flex;
				align-items: center;
				gap: 4px;
				margin-bottom: 4px;
				font-weight: 600;
			}

			.hp-broadcast--compact .hp-broadcast__control input[type="text"],
			.hp-broadcast--compact .hp-broadcast__control input[type="url"],
			.hp-broadcast--compact .hp-broadcast__control select,
			.hp-broadcast--compact .hp-broadcast__control textarea {
				width: 100%;
				max-width: 100%;
				box-sizing: border-box;
				margin: 0;
			}

			.hp-broadcast--compact .hp-broadcast__control label {
				display: flex;
				align-items: flex-start;
				gap: 6px;
			}

			/* The tooltip is positioned relative to its icon, and the widget clips at its edges,
			 * so the bubble is pulled back inside rather than disappearing under the card. */
			.hp-broadcast--compact .hp-tooltip__text {
				right: auto;
				left: 0;
				max-width: 240px;
				white-space: normal;
			}

			.hp-broadcast__label .hp-tooltip {
				margin-right: 0;
			}
		</style>

		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=hp_notification_broadcast' ) ); ?>"><?php esc_html_e( 'Announcements page', 'notifications-for-hivepress' ); ?></a>
			&nbsp;&middot;&nbsp;
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=hp_notification_broadcast&view=history' ) ); ?>"><?php esc_html_e( 'History', 'notifications-for-hivepress' ); ?></a>
			&nbsp;&middot;&nbsp;
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=hp_settings&tab=notifications' ) ); ?>"><?php esc_html_e( 'Settings', 'notifications-for-hivepress' ); ?></a>
		</p>
		<?php
		$this->render_form( true );
	}

	/**
	 * Builds the URL a submission returns to.
	 *
	 * The same form is on two screens. Sending from the Dashboard widget landed on the Announcements
	 * page, which is a jarring place to arrive when you never asked to leave the Dashboard, so the
	 * widget's copy says where it came from and the result is reported there instead.
	 *
	 * Only called after the nonce has been checked.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	protected function get_redirect_url( $args = [] ) {

		// Every caller has already run check_admin_referer(), which the sniff cannot see from here.
		// The value only chooses which of two admin screens to return to, and anything other than
		// "dashboard" falls through to the Announcements page.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$page = 'dashboard' === sanitize_key( (string) hp\get_array_value( $_POST, 'origin' ) ) ? 'index.php' : 'admin.php?page=hp_notification_broadcast';

		return add_query_arg( $args, admin_url( $page ) );
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
			wp_safe_redirect( $this->get_redirect_url() );

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

			// Two different failures, and they were sharing one message. A name that matched nothing
			// can be listed back to the sender; an empty box has no names to list, and putting a
			// dash in the gap produced "These could not be matched to an account: -", which tells
			// the sender nothing about what to do next.
			if ( $unresolved ) {
				wp_safe_redirect( $this->get_redirect_url( [ 'error' => rawurlencode( implode( ', ', array_slice( $unresolved, 0, 10 ) ) ) ] ) );

				exit;
			}

			if ( ! $user_ids ) {
				wp_safe_redirect( $this->get_redirect_url( [ 'empty' => 1 ] ) );

				exit;
			}

			$user_ids = array_values( array_unique( $user_ids ) );
			$count    = $this->dispatch_user_ids( $text, $url, $user_ids );

			$this->log_broadcast( $text, $url, $role, $count, $user_ids );

			wp_safe_redirect( $this->get_redirect_url( [ 'sent' => absint( $count ) ] ) );

			exit;
		}

		// Vendors: anyone with a published vendor profile, which is not a role.
		if ( '_vendors' === $role ) {
			$count = $this->dispatch_user_ids( $text, $url, $this->get_vendor_user_ids() );

			$this->log_broadcast( $text, $url, $role, $count );

			wp_safe_redirect( $this->get_redirect_url( [ 'sent' => absint( $count ) ] ) );

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
		// The context makes this broadcast's batches unique, because the HivePress scheduler
		// silently drops an action whose hook and arguments are already queued, and the same
		// announcement sent twice is a thing an admin is allowed to do. uniqid() rather than
		// time(): two identical sends inside the same second must still both go out.
		$this->send_batch( $text, $url, $role, 0, uniqid() );

		$this->log_broadcast( $text, $url, $role, $count );

		wp_safe_redirect( $this->get_redirect_url( [ 'sent' => absint( $count ) ] ) );

		exit;
	}

	/**
	 * Records a sent announcement.
	 *
	 * Announcements are fire and forget: once sent there was no way to see what had gone out, to
	 * whom, or when, which makes it easy to send the same thing twice or to lose the wording of
	 * something worth repeating. The log is a capped option rather than a table because it is a
	 * short admin-facing list, never queried by anything else, and should disappear cleanly on
	 * uninstall.
	 *
	 * @param string $text Message text.
	 * @param string $url Message URL.
	 * @param string $role Audience key.
	 * @param int    $count Recipient count.
	 * @param array  $user_ids Explicit recipients, for a send to named users.
	 */
	protected function log_broadcast( $text, $url, $role, $count, $user_ids = [] ) {
		$log = get_option( self::LOG_OPTION );

		if ( ! is_array( $log ) ) {
			$log = [];
		}

		array_unshift(
			$log,
			[
				'id'       => uniqid(),
				'text'     => (string) $text,
				'url'      => (string) $url,
				'role'     => (string) $role,
				'count'    => absint( $count ),
				'sent'     => current_time( 'mysql' ),
				'sender'   => get_current_user_id(),

				// Only kept for a send to named people, so Resend reaches the same list rather
				// than guessing. Capped: a huge list belongs to a role send anyway.
				'user_ids' => array_slice( array_map( 'absint', (array) $user_ids ), 0, 200 ),
			]
		);

		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_LIMIT ), false );
	}

	/**
	 * Gets the sent announcements, newest first.
	 *
	 * @return array
	 */
	public function get_log() {
		$log = get_option( self::LOG_OPTION );

		return is_array( $log ) ? $log : [];
	}

	/**
	 * Describes an audience key in words.
	 *
	 * Resolved at display time rather than stored, so a renamed role reads correctly afterwards.
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	protected function get_audience_label( $entry ) {
		$role = (string) hp\get_array_value( $entry, 'role' );

		if ( '_users' === $role ) {
			$names = [];

			foreach ( (array) hp\get_array_value( $entry, 'user_ids', [] ) as $user_id ) {
				$user = get_userdata( $user_id );

				if ( $user ) {
					$names[] = $user->display_name;
				}
			}

			if ( ! $names ) {
				return esc_html__( 'Named people', 'notifications-for-hivepress' );
			}

			// A long list is summarised rather than filling the column.
			if ( count( $names ) > 3 ) {
				return sprintf(
					/* translators: %1$s: names, %2$s: number of further people. */
					esc_html__( '%1$s and %2$s more', 'notifications-for-hivepress' ),
					implode( ', ', array_slice( $names, 0, 3 ) ),
					number_format_i18n( count( $names ) - 3 )
				);
			}

			return implode( ', ', $names );
		}

		if ( '_vendors' === $role ) {
			return esc_html__( 'Vendors', 'notifications-for-hivepress' );
		}

		if ( ! $role ) {
			return esc_html__( 'Everyone', 'notifications-for-hivepress' );
		}

		$names = wp_roles()->get_names();

		return isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role;
	}

	/**
	 * Sends a logged announcement again.
	 *
	 * Deliberately a fresh send to the audience as it stands today, not a replay to the original
	 * recipients: a role that has grown should reach its new members. A send to named people is
	 * the exception, because there the list was the point.
	 */
	public function resend_broadcast() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'notifications-for-hivepress' ) );
		}

		check_admin_referer( 'hp_notification_broadcast_resend' );

		$id    = sanitize_text_field( wp_unslash( (string) hp\get_array_value( $_POST, 'entry' ) ) );
		$entry = null;

		foreach ( $this->get_log() as $logged ) {
			if ( hp\get_array_value( $logged, 'id' ) === $id ) {
				$entry = $logged;

				break;
			}
		}

		if ( ! $entry ) {
			wp_safe_redirect( admin_url( 'admin.php?page=hp_notification_broadcast&view=history' ) );

			exit;
		}

		$text = (string) hp\get_array_value( $entry, 'text' );
		$url  = (string) hp\get_array_value( $entry, 'url' );
		$role = (string) hp\get_array_value( $entry, 'role' );

		if ( '_users' === $role ) {
			$user_ids = array_values( array_filter( array_map( 'absint', (array) hp\get_array_value( $entry, 'user_ids', [] ) ) ) );
			$count    = $this->dispatch_user_ids( $text, $url, $user_ids );

			$this->log_broadcast( $text, $url, $role, $count, $user_ids );
		} elseif ( '_vendors' === $role ) {
			$count = $this->dispatch_user_ids( $text, $url, $this->get_vendor_user_ids() );

			$this->log_broadcast( $text, $url, $role, $count );
		} else {
			if ( $role && ! wp_roles()->is_role( $role ) ) {
				$role = '';
			}

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

			$this->send_batch( $text, $url, $role, 0, uniqid() );

			$this->log_broadcast( $text, $url, $role, $count );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=hp_notification_broadcast&view=history&sent=' . absint( $count ) ) );

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

		// uniqid() rather than time(): the scheduler drops duplicate hook + args, and two
		// identical announcements sent within the same second must still both deliver.
		$context = uniqid();
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
	 * @param string $context Broadcast context.
	 */
	public function send_users_batch( $text, $url, $user_ids, $context = '' ) {
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

			hivepress()->hpnf_notification->add_notification(
				[
					'user' => $user_id,
					'type' => 'broadcast',
					'text' => hivepress()->hpnf_notification->render_text( $text, [ 'user' => $user ] ),
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
	 * @param string $context Broadcast context.
	 */
	public function send_batch( $text, $url, $role, $offset, $context = '' ) {
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

			hivepress()->hpnf_notification->add_notification(
				[
					'user' => $user_id,
					'type' => 'broadcast',
					'text' => hivepress()->hpnf_notification->render_text( $text, [ 'user' => $user ] ),
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
