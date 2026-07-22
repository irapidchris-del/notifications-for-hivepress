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
?>
<div class="hp-notifications" data-component="notifications-list">
	<div class="hp-notifications__header">
		<div class="hp-notifications__summary">
			<?php if ( $notification_unread ) : ?>
				<span class="hp-notifications__count">
					<?php
					/* translators: %s: number of unread notifications. */
					echo esc_html( sprintf( _n( '%s unread', '%s unread', $notification_unread, 'notifications-for-hivepress' ), number_format_i18n( $notification_unread ) ) );
					?>
				</span>
			<?php else : ?>
				<span class="hp-notifications__count hp-notifications__count--clear"><?php esc_html_e( 'All caught up', 'notifications-for-hivepress' ); ?></span>
			<?php endif; ?>
		</div>

		<div class="hp-notifications__actions">
			<?php if ( $notification_unread ) : ?>
				<button type="button" class="hp-notifications__action" data-component="notifications-read-all">
					<i class="hp-icon fas fa-check-double"></i>
					<span><?php esc_html_e( 'Mark all as read', 'notifications-for-hivepress' ); ?></span>
				</button>
			<?php endif; ?>

			<?php if ( $notification_total ) : ?>
				<button type="button" class="hp-notifications__action" data-component="notifications-delete-read">
					<i class="hp-icon fas fa-trash"></i>
					<span><?php esc_html_e( 'Clear read', 'notifications-for-hivepress' ); ?></span>
				</button>
			<?php endif; ?>

			<a class="hp-notifications__action" href="<?php echo esc_url( hivepress()->router->get_url( 'notification_settings_page' ) ); ?>">
				<i class="hp-icon fas fa-cog"></i>
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

			<button type="submit" class="hp-notifications__filter-submit"><?php esc_html_e( 'Search', 'notifications-for-hivepress' ); ?></button>

			<?php if ( $hp_filtered ) : ?>
				<a class="hp-notifications__reset" href="<?php echo esc_url( $hp_page_url ); ?>"><?php esc_html_e( 'Reset', 'notifications-for-hivepress' ); ?></a>
			<?php endif; ?>
		</form>
	<?php endif; ?>

	<?php if ( $notification_groups ) : ?>
		<?php foreach ( $notification_groups as $hp_date => $hp_group ) : ?>
			<section class="hp-notifications__group">
				<h3 class="hp-notifications__date"><?php echo esc_html( $hp_group['label'] ); ?></h3>

				<ul class="hp-notifications__list">
					<?php
					foreach ( $hp_group['notifications'] as $hp_notification ) :
						$hp_time  = strtotime( (string) $hp_notification->get_created_date() );
						$hp_url   = (string) $hp_notification->get_url();
						$hp_read  = (bool) $hp_notification->get_read();
						$hp_icon  = hivepress()->notification->get_type_icon( $hp_notification->get_type() );
						$hp_image = (string) $hp_notification->get_image();
						?>
						<li class="hp-notification <?php echo $hp_read ? '' : 'hp-notification--unread'; ?>" data-id="<?php echo absint( $hp_notification->get_id() ); ?>">
							<div class="hp-notification__icon">
								<?php if ( $hp_image ) : ?>
									<img src="<?php echo esc_url( $hp_image ); ?>" alt="" loading="lazy">
								<?php else : ?>
									<i class="hp-icon fas fa-<?php echo esc_attr( $hp_icon ); ?>"></i>
								<?php endif; ?>
							</div>

							<div class="hp-notification__body">
								<span class="hp-notification__type"><?php echo esc_html( hivepress()->notification->get_type_label( $hp_notification->get_type() ) ); ?></span>

								<?php if ( $hp_url ) : ?>
									<a class="hp-notification__text" href="<?php echo esc_url( $hp_url ); ?>"><?php echo esc_html( $hp_notification->get_text() ); ?></a>
								<?php else : ?>
									<span class="hp-notification__text"><?php echo esc_html( $hp_notification->get_text() ); ?></span>
								<?php endif; ?>

								<?php if ( $hp_url ) : ?>
									<a class="hp-notification__link" href="<?php echo esc_url( $hp_url ); ?>">
										<span><?php echo esc_html( hivepress()->notification->get_type_link_text( $hp_notification->get_type() ) ); ?></span>
										<i class="hp-icon fas fa-chevron-right"></i>
									</a>
								<?php endif; ?>

								<?php if ( $hp_time ) : ?>
									<time class="hp-notification__time" datetime="<?php echo esc_attr( gmdate( 'c', $hp_time ) ); ?>" title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $hp_time ) ); ?>">
										<?php echo esc_html( wp_date( (string) get_option( 'time_format' ), $hp_time ) ); ?>
									</time>
								<?php endif; ?>
							</div>

							<div class="hp-notification__controls">
								<button type="button" class="hp-notification__toggle" data-component="notification-toggle" aria-label="<?php echo esc_attr( $hp_read ? esc_html__( 'Mark as unread', 'notifications-for-hivepress' ) : esc_html__( 'Mark as read', 'notifications-for-hivepress' ) ); ?>">
									<i class="hp-icon fas fa-<?php echo $hp_read ? 'envelope' : 'check'; ?>"></i>
								</button>

								<button type="button" class="hp-notification__delete" data-component="notification-delete" aria-label="<?php esc_attr_e( 'Delete notification', 'notifications-for-hivepress' ); ?>">
									<i class="hp-icon fas fa-times"></i>
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

			$hp_links = paginate_links(
				[
					'base'      => trailingslashit( $hp_page_url ) . 'page/%#%/',
					'format'    => '',
					'current'   => $notification_page,
					'total'     => $notification_pages,
					'add_args'  => $hp_args ? $hp_args : false,
					'type'      => 'list',
					'prev_text' => '<i class="hp-icon fas fa-chevron-left"></i>',
					'next_text' => '<i class="hp-icon fas fa-chevron-right"></i>',
				]
			);

			if ( $hp_links ) :
				?>
				<div class="hp-pagination">
					<nav class="navigation pagination" aria-label="<?php esc_attr_e( 'Notifications', 'notifications-for-hivepress' ); ?>">
						<div class="nav-links"><?php echo wp_kses_post( $hp_links ); ?></div>
					</nav>
				</div>
				<?php
			endif;
		endif;
		?>
	<?php else : ?>
		<div class="hp-notifications__empty">
			<i class="hp-icon fas fa-inbox"></i>

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
