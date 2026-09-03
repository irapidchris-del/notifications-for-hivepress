<?php
/**
 * Notifications page template part.
 *
 * Override this file by copying it to "hivepress/notification/view/page/notifications.php" in your
 * theme. The variables below come from the notifications block context.
 *
 * @package HivePress\Notifications\Templates
 *
 * @var array  $notification_groups Notifications grouped by date.
 * @var array  $notification_types Types the current user has received.
 * @var string $notification_type Type the list is filtered by.
 * @var string $notification_search Current search term.
 * @var int    $notification_unread Number of unread notifications.
 * @var int    $notification_pages Total number of pages.
 * @var int    $notification_page Current page number.
 * @var int    $notification_total Total number of notifications.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$hp_page_url = hivepress()->router->get_url( 'notifications_view_page' );
$hp_filtered = $notification_type || $notification_search;

// Whether the script may add an arriving notification straight to the list. Only a plain first
// page qualifies: a filtered or searched list might legitimately exclude it, and the newest thing
// does not belong on page two of a list ordered newest first. Anywhere else it appears on the next
// load, as it always has.
$hp_live = ! $hp_filtered && $notification_page < 2;
?>
<div class="hp-notifications" data-component="notifications-list" data-live="<?php echo $hp_live ? '1' : '0'; ?>">
	<div class="hp-notifications__header">
		<div class="hp-notifications__summary">
			<?php // The data-component lets the script keep this in step when something is marked read without a reload. ?>
			<?php if ( $notification_unread ) : ?>
				<span class="hp-notifications__count" data-component="notifications-count">
					<?php
					/* translators: %s: number of unread notifications. */
					echo esc_html( sprintf( _n( '%s unread', '%s unread', $notification_unread, 'notifications-for-hivepress' ), number_format_i18n( $notification_unread ) ) );
					?>
				</span>
			<?php else : ?>
				<span class="hp-notifications__count hp-notifications__count--clear" data-component="notifications-count"><?php esc_html_e( 'All caught up', 'notifications-for-hivepress' ); ?></span>
			<?php endif; ?>
		</div>

		<div class="hp-notifications__actions">
			<?php
			// hp-button gives the structure (inline-flex, centred, icon gap); button and
			// button--secondary are the theme's own appearance, which all six official themes
			// define. Nothing here is styled by us, so these match the theme's buttons exactly.
			?>
			<?php
			// Both buttons are always rendered and simply hidden when they have nothing to act on,
			// because a notification arriving while the page is open makes them useful again and
			// the script has no way to rebuild one that was never printed.
			?>
			<?php
			// Select all pairs with the box on each row; Clear selected appears once any is ticked.
			// Both act on the rows the script can see, so a filtered page clears only what it shows.
			?>
			<label class="hp-button button button--secondary hp-notifications__select-all" <?php echo $notification_total ? '' : 'hidden'; ?>>
				<input type="checkbox" data-component="notifications-select-all">
				<span><?php esc_html_e( 'Select all', 'notifications-for-hivepress' ); ?></span>
			</label>

			<button type="button" class="hp-button button button--secondary hp-notifications__action" data-component="notifications-delete-selected" hidden>
				<?php echo wp_kses( hivepress()->hpnf_notification->get_icon_markup( 'trash' ), hivepress()->hpnf_notification->icon_kses() ); ?>
				<span><?php esc_html_e( 'Clear selected', 'notifications-for-hivepress' ); ?></span>
			</button>

			<button type="button" class="hp-button button button--secondary hp-notifications__action" data-component="notifications-read-all" <?php echo $notification_unread ? '' : 'hidden'; ?>>
				<?php echo wp_kses( hivepress()->hpnf_notification->get_icon_markup( 'check-double' ), hivepress()->hpnf_notification->icon_kses() ); ?>
				<span><?php esc_html_e( 'Mark all as read', 'notifications-for-hivepress' ); ?></span>
			</button>

			<button type="button" class="hp-button button button--secondary hp-notifications__action" data-component="notifications-delete-read" <?php echo $notification_total ? '' : 'hidden'; ?>>
				<?php echo wp_kses( hivepress()->hpnf_notification->get_icon_markup( 'trash' ), hivepress()->hpnf_notification->icon_kses() ); ?>
				<span><?php esc_html_e( 'Clear read', 'notifications-for-hivepress' ); ?></span>
			</button>

			<a class="hp-button button button--secondary hp-notifications__action" href="<?php echo esc_url( hivepress()->router->get_url( 'notification_settings_page' ) ); ?>">
				<?php echo wp_kses( hivepress()->hpnf_notification->get_icon_markup( 'cog' ), hivepress()->hpnf_notification->icon_kses() ); ?>
				<span><?php esc_html_e( 'Settings', 'notifications-for-hivepress' ); ?></span>
			</a>
		</div>
	</div>

	<?php if ( $notification_total || $hp_filtered ) : ?>
		<form method="get" action="<?php echo esc_url( $hp_page_url ); ?>" class="hp-notifications__filters">
			<label class="screen-reader-text" for="hp-notification-search"><?php esc_html_e( 'Search notifications', 'notifications-for-hivepress' ); ?></label>
			<input type="search" name="notification_search" id="hp-notification-search" value="<?php echo esc_attr( $notification_search ); ?>" placeholder="<?php esc_attr_e( 'Search notifications', 'notifications-for-hivepress' ); ?>">

			<?php if ( count( $notification_types ) > 1 ) : ?>
				<label class="screen-reader-text" for="hp-notification-type"><?php esc_html_e( 'Filter by type', 'notifications-for-hivepress' ); ?></label>

				<select name="notification_type" id="hp-notification-type" data-component="notifications-filter">
					<option value=""><?php esc_html_e( 'All types', 'notifications-for-hivepress' ); ?></option>

					<?php foreach ( $notification_types as $hp_type => $hp_label ) : ?>
						<option value="<?php echo esc_attr( $hp_type ); ?>" <?php selected( $hp_type, $notification_type ); ?>><?php echo esc_html( $hp_label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<button type="submit" class="hp-button button button--primary hp-notifications__filter-submit">
				<?php echo wp_kses( hivepress()->hpnf_notification->get_icon_markup( 'search' ), hivepress()->hpnf_notification->icon_kses() ); ?>
				<span><?php esc_html_e( 'Search', 'notifications-for-hivepress' ); ?></span>
			</button>

			<?php if ( $hp_filtered ) : ?>
				<a class="hp-notifications__reset" href="<?php echo esc_url( $hp_page_url ); ?>"><?php esc_html_e( 'Reset', 'notifications-for-hivepress' ); ?></a>
			<?php endif; ?>
		</form>
	<?php endif; ?>

	<?php if ( $notification_groups ) : ?>
		<?php foreach ( $notification_groups as $hp_date => $hp_group ) : ?>
			<section class="hp-notifications__group"<?php echo empty( $hp_group['today'] ) ? '' : ' data-today="1"'; ?>>
				<h3 class="hp-notifications__date"><?php echo esc_html( $hp_group['label'] ); ?></h3>

				<ul class="hp-notifications__list">
					<?php
					foreach ( $hp_group['notifications'] as $hp_notification ) :
						$hp_time  = strtotime( (string) $hp_notification->get_created_date() );
						$hp_url   = (string) $hp_notification->get_url();
						$hp_read  = (bool) $hp_notification->get_read();
						$hp_icon  = hivepress()->hpnf_notification->get_notification_icon( $hp_notification );
						$hp_image = (string) $hp_notification->get_image();

						// A notification may carry its own colour, which is how a badge award shows
						// the badge's own colour rather than the shared accent.
						$hp_color = sanitize_hex_color( (string) $hp_notification->get_color() );
						$hp_style = $hp_color ? 'background-color:' . $hp_color . ';color:#ffffff;' : '';

						// The stored text carries HTML entities from escaped token values, and the
						// same string is served to the pop-up and the bell as plain text. Both
						// renderers read it through decode_text() so they agree; esc_html() below
						// escapes the decoded text for this one, exactly as before.
						$hp_text = hivepress()->hpnf_notification->decode_text( $hp_notification->get_text() );
						?>
						<li class="hp-notification <?php echo $hp_read ? '' : 'hp-notification--unread'; ?>" data-id="<?php echo absint( $hp_notification->get_id() ); ?>">
							<label class="hp-notification__select">
								<input type="checkbox" data-component="notification-select" value="<?php echo esc_attr( $hp_notification->get_id() ); ?>">
								<span class="screen-reader-text"><?php esc_html_e( 'Select this notification', 'notifications-for-hivepress' ); ?></span>
							</label>

							<div class="hp-notification__icon"<?php echo $hp_style ? ' style="' . esc_attr( $hp_style ) . '"' : ''; ?>>
								<?php if ( $hp_image ) : ?>
									<img src="<?php echo esc_url( $hp_image ); ?>" alt="" loading="lazy">
								<?php else : ?>
									<?php echo wp_kses( hivepress()->hpnf_notification->get_icon_markup( $hp_icon ), hivepress()->hpnf_notification->icon_kses() ); ?>
								<?php endif; ?>
							</div>

							<div class="hp-notification__body">
								<?php // The member-facing heading: the admin's saved title, or the label without the "(User)"/"(Vendor)" bracket. ?>
								<span class="hp-notification__type"><?php echo esc_html( hivepress()->hpnf_notification->get_type_title( $hp_notification->get_type() ) ); ?></span>

								<?php if ( $hp_url ) : ?>
									<a class="hp-notification__text" href="<?php echo esc_url( $hp_url ); ?>"><?php echo esc_html( $hp_text ); ?></a>
								<?php else : ?>
									<span class="hp-notification__text"><?php echo esc_html( $hp_text ); ?></span>
								<?php endif; ?>

								<?php if ( $hp_url ) : ?>
									<a class="hp-notification__link" href="<?php echo esc_url( $hp_url ); ?>">
										<span><?php echo esc_html( hivepress()->hpnf_notification->get_type_link_text( $hp_notification->get_type() ) ); ?></span>
										<?php echo wp_kses( hivepress()->hpnf_notification->get_icon_markup( 'chevron-right' ), hivepress()->hpnf_notification->icon_kses() ); ?>
									</a>
								<?php endif; ?>

								<?php if ( $hp_time ) : ?>
									<?php
									// The stored date is on the site's clock, so date_i18n() formats it
									// as it is; wp_date() would add the UTC offset a second time. The
									// datetime attribute wants real UTC, which get_gmt_from_date()
									// derives with the site's timezone rules.
									?>
									<time class="hp-notification__time" datetime="<?php echo esc_attr( get_gmt_from_date( (string) $hp_notification->get_created_date(), 'c' ) ); ?>" title="<?php echo esc_attr( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $hp_time ) ); ?>">
										<?php echo esc_html( date_i18n( (string) get_option( 'time_format' ), $hp_time ) ); ?>
									</time>
								<?php endif; ?>
							</div>

							<div class="hp-notification__controls">
								<?php
								// The label says what the next click will do, and the script keeps it
								// in step. Flipping only the icon left a read row still offering to
								// "Mark as read" while the click marked it unread.
								$hp_toggle_label = $hp_read ? esc_html__( 'Mark as unread', 'notifications-for-hivepress' ) : esc_html__( 'Mark as read', 'notifications-for-hivepress' );
								?>
								<button type="button" class="hp-notification__toggle" data-component="notification-toggle" aria-label="<?php echo esc_attr( $hp_toggle_label ); ?>" title="<?php echo esc_attr( $hp_toggle_label ); ?>">
									<?php echo wp_kses( hivepress()->hpnf_notification->get_icon_markup( $hp_read ? 'envelope' : 'check' ), hivepress()->hpnf_notification->icon_kses() ); ?>
								</button>

								<button type="button" class="hp-notification__delete" data-component="notification-delete" aria-label="<?php esc_attr_e( 'Delete notification', 'notifications-for-hivepress' ); ?>">
									<?php echo wp_kses( hivepress()->hpnf_notification->get_icon_markup( 'times' ), hivepress()->hpnf_notification->icon_kses() ); ?>
								</button>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>

		<?php
		if ( $notification_pages > 1 ) :
			$hp_args = array_filter(
				[
					'notification_type'   => $notification_type,
					'notification_search' => $notification_search,
				]
			);

			// "plain" rather than "list", and mid_size to match, because this has to come out as the
			// flat run of links that HivePress styles: ".hp-pagination .nav-links" is a flex row
			// whose children are the ".page-numbers" themselves. The list form wraps each number in
			// an "li", which no theme resets inside this container, so every page number arrived
			// wearing its theme's list bullet and the row read "* 1 2 *".
			$hp_links = paginate_links(
				[
					'base'      => trailingslashit( $hp_page_url ) . 'page/%#%/',
					'format'    => '',
					'current'   => $notification_page,
					'total'     => $notification_pages,
					'add_args'  => $hp_args ? $hp_args : false,
					'type'      => 'plain',
					'mid_size'  => 1,
					'prev_text' => hivepress()->hpnf_notification->get_icon_markup( 'chevron-left' ),
					'next_text' => hivepress()->hpnf_notification->get_icon_markup( 'chevron-right' ),
				]
			);

			if ( $hp_links ) :
				?>
				<div class="hp-pagination">
					<?php // The markup core's own the_posts_pagination() produces, which is what HivePress pages use. ?>
					<nav class="navigation pagination" aria-label="<?php esc_attr_e( 'Notifications', 'notifications-for-hivepress' ); ?>">
						<h2 class="screen-reader-text"><?php esc_html_e( 'Notifications navigation', 'notifications-for-hivepress' ); ?></h2>
						<div class="nav-links"><?php echo wp_kses_post( $hp_links ); ?></div>
					</nav>
				</div>
				<?php
			endif;
		endif;
		?>
	<?php else : ?>
		<div class="hp-notifications__empty">
			<?php echo wp_kses( hivepress()->hpnf_notification->get_icon_markup( 'inbox' ), hivepress()->hpnf_notification->icon_kses() ); ?>

			<?php if ( $hp_filtered ) : ?>
				<p><?php esc_html_e( 'Nothing matches that.', 'notifications-for-hivepress' ); ?></p>
				<a href="<?php echo esc_url( $hp_page_url ); ?>"><?php esc_html_e( 'Show all notifications', 'notifications-for-hivepress' ); ?></a>
			<?php else : ?>
				<p><?php esc_html_e( 'No notifications yet. When something happens on your account, it will show up here.', 'notifications-for-hivepress' ); ?></p>
				<a href="<?php echo esc_url( hivepress()->router->get_url( 'notification_settings_page' ) ); ?>"><?php esc_html_e( 'Choose what you get notified about', 'notifications-for-hivepress' ); ?></a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
