<?php
/**
 * Notification component.
 *
 * @package HivePress\Notifications\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Creates on-site notifications from HivePress emails.
 *
 * Every notification HivePress and its extensions send goes through an email class, so mirroring
 * emails is the only way to cover every extension without hard-coding each one. Each email class
 * fires "hivepress/v1/emails/{email_name}/send" when it's sent, so this component enumerates the
 * registered email classes and listens to all of them.
 */
final class Hpnf_Notification extends Component {

	/**
	 * Default unread badge colour.
	 *
	 * The red HivePress uses for its own menu counts, taken from
	 * "hp-menu__item small" in hivepress/assets/css/frontend.min.css. Sharing it means an
	 * untouched site shows one consistent badge everywhere rather than two competing reds.
	 *
	 * @var string
	 */
	const BADGE_COLOR = '#ff5a5f';

	/**
	 * Types HivePress and its extensions address to the site owner.
	 *
	 * Filed into the "For Site Owners" group by get_type_group(), which explains why they need
	 * naming here rather than being recognised from their names.
	 *
	 * @var array
	 */
	const OWNER_TYPES = [
		'listing_claim_submit',
		'listing_import',
		'listing_report',
		'listing_submit',
		'listing_update',
		'offer_submit',
		'order_dispute',
		'order_refund_fail',
		'order_refund_request',
		'payout_fail',
		'payout_request',
		'request_submit',
		'vendor_register',
	];

	/**
	 * Whether each user sells on this site, keyed by user ID.
	 *
	 * @var array
	 */
	protected $vendors = [];

	/**
	 * Cached notification types.
	 *
	 * Null until first built, and reset to null when the type list changes within a request, which
	 * is what get_types() tests for.
	 *
	 * @var array|null
	 */
	protected $types;

	/**
	 * Email waiting to be stopped.
	 *
	 * @var array|null
	 */
	protected $suppressed;

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Listen to emails.
		add_action( 'init', [ $this, 'register_email_listeners' ], 1000 );

		// Switch on notification types that arrived with a newly installed extension.
		add_action( 'init', [ $this, 'maybe_register_new_types' ], 1100 );

		// Seed notifications for unread messages that predate the plugin, once.
		add_action( 'init', [ $this, 'maybe_backfill' ], 1200 );

		// Run the one-time upgrade rewrites.
		add_action( 'init', [ $this, 'maybe_upgrade' ] );

		// Listen to the extras that HivePress has no email for.
		add_action( 'hivepress/v1/models/favorite/create', [ $this, 'add_favorite_notification' ], 10, 2 );
		add_action( 'hivepress/v1/models/review/create', [ $this, 'add_review_notification' ], 10, 2 );
		add_action( 'hivepress/v1/models/review/update_status', [ $this, 'update_review_notification' ], 10, 4 );
		add_action( 'hivepress/v1/models/booking/complete', [ $this, 'add_booking_notification' ], 20, 1 );
		add_action( 'hivepress/v1/models/award/create', [ $this, 'add_award_notification' ], 10, 2 );

		// Stop emails a user turned off.
		add_filter( 'pre_wp_mail', [ $this, 'suppress_email' ], 10, 2 );

		// Add settings.
		add_filter( 'hivepress/v1/settings', [ $this, 'alter_settings' ] );

		// Add the newer Font Awesome and brand icons to every icon picker.
		add_filter( 'hivepress/v1/icons', [ $this, 'add_icons' ] );

		// Add the colour picker on the settings screen.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_color_picker' ] );

		// Add the live preview panel to the Notifications settings tab. Priority 20 because
		// HivePress registers its own sections at 10 and this has to see them.
		add_action( 'admin_init', [ $this, 'register_preview_section' ], 20 );

		// Delete notifications. The daily event is scheduled by the HivePress scheduler component,
		// so this only attaches to it.
		add_action( 'hivepress/v1/events/daily', [ $this, 'delete_notifications' ] );

		if ( ! is_admin() ) {

			// Answer 200 rather than 404 on page two of the list.
			add_action( 'template_redirect', [ $this, 'fix_paged_status' ], 1 );

			// Restore the template body classes. Core derives them from a template class named
			// after the current route (components/class-template.php:220-227); the prefixed
			// template classes no longer match the route names, so the classes the pages have
			// always carried are appended here, the same classes as before the rename.
			add_filter( 'body_class', [ $this, 'add_template_classes' ] );

			// Alter menus.
			add_filter( 'hivepress/v1/menus/user_account', [ $this, 'alter_user_account_menu' ] );

			// Add the header bell. ListingHive exposes this area for exactly this purpose, and the
			// theme only renders the wrapper when something hooks it.
			add_filter( 'hivetheme/v1/areas/site_header', [ $this, 'render_bell' ] );

			// Alter assets.
			add_filter( 'hivepress/v1/scripts', [ $this, 'alter_assets' ] );
			add_filter( 'hivepress/v1/styles', [ $this, 'alter_assets' ] );

			// Enqueue assets.
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ], 20 );
		}

		parent::__construct( $args );
	}

	/**
	 * Registers a listener for every email that HivePress and its extensions can send.
	 *
	 * HivePress only fires an email-specific action, so there's no single hook that catches every
	 * email. Enumerating the email classes and attaching to each one covers the same ground and
	 * keeps working when a new extension is activated.
	 */
	public function register_email_listeners() {
		foreach ( array_keys( $this->get_types() ) as $type ) {
			add_action( 'hivepress/v1/emails/' . $type . '/send', [ $this, 'process_email' ], 10, 1 );
		}
	}

	/**
	 * Gets the available notification types.
	 *
	 * Keys are email names, values are the labels shown to users. Emails that HivePress sends to
	 * the site administrator don't declare a label, so the email name is humanised instead.
	 *
	 * @return array
	 */
	public function get_types() {
		if ( is_null( $this->types ) ) {
			$types = [];

			// Get email types. Emails HivePress sends to the site administrator declare no label,
			// so the email name is humanised for those.
			$defaults = $this->get_default_texts();

			foreach ( hivepress()->get_classes( 'emails' ) as $name => $class ) {
				$label = call_user_func( [ $class, 'get_meta' ], 'label' );

				$types[ $name ] = [
					// Wording written for a notification list, falling back to the email subject
					// for the ones addressed to the site administrator.
					'text'     => hp\get_array_value( $defaults, $name, '' ),
					// Emails HivePress sends to the site administrator declare no label, so the
					// name is humanised. Title case rather than ucfirst, or the admin list reads
					// "Listing Approved, Listing Expired, Listing report, Listing submit" - the
					// odd ones out looking like a mistake rather than a different kind of email.
					'label'    => $label ? $label : ucwords( str_replace( '_', ' ', $name ) ),
					'tokens'   => array_filter( (array) call_user_func( [ $class, 'get_meta' ], 'tokens' ) ),
					'channels' => [ 'onsite', 'email', 'push' ],
					'email'    => true,
				];
			}

			// Email Studio's composer sends through an email class that declares no label on
			// purpose - a label would list it for editing in Email Studio, and it has no fixed
			// wording to edit - so the humanised name above would read "Hpes Broadcast": a class
			// name, on the settings screen and in every member's preferences. Named here instead,
			// in words a member can read; where those emails come from is said on the settings
			// screen only (get_type_note()), because the sentence names another plugin.
			if ( isset( $types['hpes_broadcast'] ) ) {
				$types['hpes_broadcast']['label'] = esc_html__( 'Site Emails', 'notifications-for-hivepress' );
			}

			// Get extra types.
			foreach ( $this->get_extra_types() as $name => $args ) {
				$types[ $name ] = $args;
			}

			// Sort types.
			uasort(
				$types,
				function( $a, $b ) {
					return strcasecmp( $a['label'], $b['label'] );
				}
			);

			/**
			 * Filters the notification types. Keys are type names, values are arrays with the label,
			 * the available tokens, the delivery channels and the default text.
			 *
			 * @hook hivepress/v1/notification_types
			 * @param {array} $types Notification types.
			 * @return {array} Notification types.
			 */
			$this->types = apply_filters( 'hivepress/v1/notification_types', $types );
		}

		return $this->types;
	}

	/**
	 * Gets the default wording for the notifications HivePress emails.
	 *
	 * Without these, an on-site notification falls back to the email's subject line, and a subject
	 * is written to be read in an inbox next to a sender and a body. On its own in a list it says
	 * very little: "Order Completed" or "User Registered" tells someone what happened to something,
	 * but not what happened to them. These say the second thing.
	 *
	 * Every token used here is declared by the email it belongs to, checked against the source
	 * rather than assumed, because replace_tokens() leaves an unknown token in the text verbatim -
	 * so a wrong guess would put a raw %token% in front of a real user.
	 *
	 * Two traps that shaped the wording. **%user_name% is the recipient**, not whoever caused the
	 * notification: every HivePress email body opens "Hi, %user_name%!", so it names the person
	 * reading it. And **%user_password% is never used**, despite user_register offering it: an
	 * on-site notification is stored in the database and sits in a list for weeks, which is the last
	 * place a password should be.
	 *
	 * Emails HivePress addresses to the site administrator declare no tokens at all, so they are
	 * absent here and keep the subject-line fallback, which is the right shape for them anyway.
	 *
	 * @return array
	 */
	protected function get_default_texts() {
		/*
		 * One sniff has to stay off here, and it is the dangerous kind: one phpcbf will "fix" for
		 * you, silently and wrongly.
		 *
		 * It reads every %word% as a printf placeholder. These are HivePress tokens, replaced by
		 * NAME rather than by position, by replace_tokens() in core's helpers. Told to order them,
		 * phpcbf rewrites "%site_name%" into "%1$site_name%" in any string holding two or more,
		 * producing a token replace_tokens() can never match - so the notification reaches a real
		 * user with a raw "%1$site_name%" in it.
		 *
		 * That is not hypothetical. Running phpcbf on this file did exactly that to five of the
		 * strings below, twice, and the only thing that caught it was a test that renders every
		 * default and looks for tokens left over. Never accept the fixer's version of these lines.
		 *
		 * The translators comments below are real, not sniff appeasement: a translator who renders
		 * %listing_title% into their own language breaks the notification, and they have no way to
		 * know that without being told.
		 *
		 * These are __() and not esc_html__() on purpose. esc_html__() escapes after translating,
		 * so the first French string containing "n'a" would be stored as "n&#039;a" and shown that
		 * way for the life of the notification. Escaping belongs at the point of output, and every
		 * consumer already does it: the templates use esc_html(), the pop-up, bell and undo bar
		 * assign to textContent, and the REST routes hand back JSON.
		 */
		// phpcs:disable WordPress.WP.I18n.UnorderedPlaceholdersText

		$texts = [

			/* translators: keep %site_name% and %user_name% exactly as written; they are replaced with the site name and the reader's name. */
			'user_register'          => __( 'Welcome to %site_name%, %user_name%. Your account is ready to use.', 'notifications-for-hivepress' ),

			/* translators: keep %listing_title% exactly as written; it is replaced with the listing name. */
			'listing_approve'        => __( 'Your listing %listing_title% has been approved and is now live.', 'notifications-for-hivepress' ),
			/* translators: keep %listing_title% exactly as written; it is replaced with the listing name. */
			'listing_reject'         => __( 'Your listing %listing_title% was not approved.', 'notifications-for-hivepress' ),
			/* translators: keep %listing_title% exactly as written; it is replaced with the listing name. */
			'listing_expire'         => __( 'Your listing %listing_title% has expired and is no longer shown.', 'notifications-for-hivepress' ),
			/* translators: keep %listing_title% exactly as written; it is replaced with the listing name. */
			'listing_sellout'        => __( 'Your listing %listing_title% has sold out.', 'notifications-for-hivepress' ),
			'listing_find'           => __( 'New listings have been added that match your saved search.', 'notifications-for-hivepress' ),
			/* translators: keep %listing_title% exactly as written; it is replaced with the listing name. */
			'listing_claim_approve'  => __( 'Your claim for %listing_title% has been approved. The listing is yours to manage.', 'notifications-for-hivepress' ),
			/* translators: keep %listing_title% exactly as written; it is replaced with the listing name. */
			'listing_claim_reject'   => __( 'Your claim for %listing_title% was not approved.', 'notifications-for-hivepress' ),

			/* translators: keep %sender.display_name% exactly as written; it is replaced with the sender's name. */
			'message_send'           => __( '%sender.display_name% sent you a message.', 'notifications-for-hivepress' ),

			/* translators: keep %listing_title% and %booking_dates% exactly as written; they are replaced with the listing name and the booking dates. */
			'booking_request'        => __( 'New booking request for %listing_title%, %booking_dates%.', 'notifications-for-hivepress' ),
			/* translators: keep %listing_title% and %booking_dates% exactly as written; they are replaced with the listing name and the booking dates. */
			'booking_accept'         => __( 'Your booking of %listing_title% was accepted for %booking_dates%.', 'notifications-for-hivepress' ),
			/* translators: keep %listing_title% and %booking_dates% exactly as written; they are replaced with the listing name and the booking dates. */
			'booking_decline'        => __( 'Your booking of %listing_title% for %booking_dates% was declined.', 'notifications-for-hivepress' ),
			/* translators: keep %listing_title% and %booking_dates% exactly as written; they are replaced with the listing name and the booking dates. */
			'booking_confirm_user'   => __( 'Your booking of %listing_title% is confirmed for %booking_dates%.', 'notifications-for-hivepress' ),
			/* translators: keep %listing_title% and %booking_dates% exactly as written; they are replaced with the listing name and the booking dates. */
			'booking_confirm_vendor' => __( 'A booking of %listing_title% is confirmed for %booking_dates%.', 'notifications-for-hivepress' ),
			/* translators: keep %listing_title% and %booking_dates% exactly as written; they are replaced with the listing name and the booking dates. */
			'booking_cancel_user'    => __( 'Your booking of %listing_title% for %booking_dates% has been cancelled.', 'notifications-for-hivepress' ),
			/* translators: keep %listing_title% and %booking_dates% exactly as written; they are replaced with the listing name and the booking dates. */
			'booking_cancel_vendor'  => __( 'A booking of %listing_title% for %booking_dates% has been cancelled.', 'notifications-for-hivepress' ),
			/* translators: keep %booking_number% and %booking_dates% exactly as written; they are replaced with the booking reference and its dates. */
			'booking_remind'         => __( 'Reminder: booking %booking_number% is coming up on %booking_dates%.', 'notifications-for-hivepress' ),

			/* translators: keep %order_number% and %order_amount% exactly as written; they are replaced with the order reference and its total. */
			'order_receive'          => __( 'You have a new order, %order_number%, for %order_amount%.', 'notifications-for-hivepress' ),
			/* translators: keep %order_number% and %order_amount% exactly as written; they are replaced with the order reference and its total. */
			'order_complete'         => __( 'Order %order_number% is complete. Total %order_amount%.', 'notifications-for-hivepress' ),
			/* translators: keep %order_number% exactly as written; it is replaced with the order reference. */
			'order_deliver'          => __( 'Order %order_number% has been delivered.', 'notifications-for-hivepress' ),
			/* translators: keep %order_number% and %order_amount% exactly as written; they are replaced with the order reference and its total. */
			'order_refund'           => __( 'Order %order_number% has been refunded, %order_amount%.', 'notifications-for-hivepress' ),
			/* translators: keep %order_number% exactly as written; it is replaced with the order reference. */
			'order_reject'           => __( 'The delivery for order %order_number% was rejected.', 'notifications-for-hivepress' ),
			/* translators: keep %payout_amount% and %payout_method% exactly as written; they are replaced with the amount and how it was paid. */
			'payout_complete'        => __( 'Your payout of %payout_amount% has been sent by %payout_method%.', 'notifications-for-hivepress' ),

			/* translators: keep %membership_plan% exactly as written; it is replaced with the plan name. */
			'membership_activate'    => __( 'Your %membership_plan% membership is now active.', 'notifications-for-hivepress' ),
			/* translators: keep %membership_plan% exactly as written; it is replaced with the plan name. */
			'membership_renew'       => __( 'Your %membership_plan% membership has renewed.', 'notifications-for-hivepress' ),
			/* translators: keep %membership_plan% exactly as written; it is replaced with the plan name. */
			'membership_expire'      => __( 'Your %membership_plan% membership has expired.', 'notifications-for-hivepress' ),

			/* translators: keep %request_title% exactly as written; it is replaced with the request name. */
			'request_send'           => __( 'New request: %request_title%.', 'notifications-for-hivepress' ),
			/* translators: keep %request_title% exactly as written; it is replaced with the request name. */
			'request_approve'        => __( 'Your request %request_title% has been approved.', 'notifications-for-hivepress' ),
			/* translators: keep %request_title% exactly as written; it is replaced with the request name. */
			'request_reject'         => __( 'Your request %request_title% was not approved.', 'notifications-for-hivepress' ),
			/* translators: keep %request_title% exactly as written; it is replaced with the request name. */
			'request_expire'         => __( 'Your request %request_title% has expired.', 'notifications-for-hivepress' ),
			'request_find'           => __( 'New requests have been posted that match what you offer.', 'notifications-for-hivepress' ),
			'offer_make'             => __( 'You have received a new offer.', 'notifications-for-hivepress' ),

			/* translators: keep %listing_title% exactly as written; it is replaced with the listing name. */
			'review_add'             => __( 'A new review has been left on %listing_title%.', 'notifications-for-hivepress' ),
			/* translators: keep %listing_title% exactly as written; it is replaced with the listing name. */
			'review_approve'         => __( 'Your review of %listing_title% has been approved.', 'notifications-for-hivepress' ),
			/* translators: keep %listing_title% exactly as written; it is replaced with the listing name. */
			'review_reply'           => __( 'Your review of %listing_title% has a reply.', 'notifications-for-hivepress' ),
		];

		// phpcs:enable WordPress.WP.I18n.UnorderedPlaceholdersText

		/**
		 * Filters the default wording for each notification type. The site owner's own wording, set
		 * under Settings, always wins over anything returned here.
		 *
		 * @hook hivepress/v1/notification_default_texts
		 * @param {array} $texts Default texts, keyed by notification type.
		 * @return {array} Default texts.
		 */
		return (array) apply_filters( 'hivepress/v1/notification_default_texts', $texts );
	}

	/**
	 * Gets the notification types that aren't backed by a HivePress email.
	 *
	 * These have no email to mirror, so they're on-site only. Each one is registered only when the
	 * extension that provides its model is active.
	 *
	 * @return array
	 */
	protected function get_extra_types() {
		$types = [];

		if ( class_exists( '\HivePress\Models\Favorite' ) ) {
			$types['listing_favorite'] = [
				'label'    => esc_html__( 'Listing Favourited', 'notifications-for-hivepress' ),
				/* translators: keep %user.display_name% and %listing.title% exactly as written; they are HivePress tokens replaced by name, not numbered placeholders, so they may be reordered or dropped but never translated. */
				'text'     => __( '%user.display_name% added %listing.title% to their favourites.', 'notifications-for-hivepress' ),
				'tokens'   => [ 'user', 'listing', 'listing_title', 'listing_url' ],
				'channels' => [ 'onsite', 'push' ],
				'icon'     => 'heart',
			];
		}

		if ( class_exists( '\HivePress\Models\Booking' ) ) {
			$types['booking_complete'] = [
				'label'     => esc_html__( 'Booking Completed', 'notifications-for-hivepress' ),
				/* translators: keep %listing.title% exactly as written; it is a HivePress token replaced with the listing name, not a placeholder to translate. */
				'text'      => __( 'Your booking for %listing.title% is done. How did it go?', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'Leave a review', 'notifications-for-hivepress' ),
				'tokens'    => [ 'user', 'listing', 'booking', 'listing_title', 'listing_url' ],
				'channels'  => [ 'onsite', 'push' ],
			];
		}

		if ( class_exists( '\HivePress\Models\Review' ) ) {
			$types['listing_review'] = [
				'label'    => esc_html__( 'Review Received', 'notifications-for-hivepress' ),
				/* translators: keep %author.display_name% and %listing.title% exactly as written; they are HivePress tokens replaced by name, not numbered placeholders, so they may be reordered or dropped but never translated. */
				'text'     => __( '%author.display_name% reviewed %listing.title%.', 'notifications-for-hivepress' ),
				'tokens'   => [ 'author', 'listing', 'review', 'listing_title', 'listing_url', 'review_url' ],
				'channels' => [ 'onsite', 'push' ],
			];
		}

		// The Badges extension awards badges silently: it writes an award and has no email, so
		// nobody is told they earned one. This is the only notification here that goes to the
		// person who caused it, because earning it is the good news.
		if ( class_exists( '\HivePress\Models\Award' ) ) {
			$types['badge_award'] = [
				'label'     => esc_html__( 'Badge Earned', 'notifications-for-hivepress' ),

				/*
				 * translators: %badge.name% and %user.display_name% are HivePress tokens, not printf
				 * placeholders: they are replaced by name, so they must keep their spelling and can
				 * be moved or removed freely when translating. The inline ignore is needed because
				 * the sniff reads %b and %u as printf conversions and would have them numbered,
				 * which would stop them being recognised as tokens at all.
				 */
				'text'      => __( 'Congratulations! You have earned the %badge.name% badge. Keep up the good work, %user.display_name%!', 'notifications-for-hivepress' ), // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText
				'link_text' => esc_html__( 'View your badges', 'notifications-for-hivepress' ),
				'tokens'    => [ 'user', 'badge', 'badge_name' ],
				'channels'  => [ 'onsite', 'push' ],
				'icon'      => 'award',
			];
		}

		return $types;
	}

	/**
	 * Gets the notification types as a name => label array.
	 *
	 * @return array
	 */
	public function get_type_labels() {
		return array_combine(
			array_keys( $this->get_types() ),
			array_column( $this->get_types(), 'label' )
		);
	}

	/**
	 * Gets the notification groups.
	 *
	 * Around forty types is too many to face as one list, so both the admin settings and each
	 * user's settings page work in these groups. Only the groups your active extensions actually
	 * provide types for ever appear.
	 *
	 * @return array
	 */
	public function get_groups() {
		return [
			'listings'    => esc_html__( 'Listings', 'notifications-for-hivepress' ),
			'messages'    => esc_html__( 'Messages', 'notifications-for-hivepress' ),
			'bookings'    => esc_html__( 'Bookings', 'notifications-for-hivepress' ),
			'orders'      => esc_html__( 'Orders & Payouts', 'notifications-for-hivepress' ),
			'requests'    => esc_html__( 'Requests & Offers', 'notifications-for-hivepress' ),
			'memberships' => esc_html__( 'Memberships', 'notifications-for-hivepress' ),
			'gallery'     => esc_html__( 'Gallery', 'notifications-for-hivepress' ),
			'performance' => esc_html__( 'Performance', 'notifications-for-hivepress' ),
			'account'     => esc_html__( 'Account', 'notifications-for-hivepress' ),

			// Everything here reaches the site owner rather than a member, which is a different
			// question from which feature it belongs to: an owner switching off "someone hit the
			// submission limit" must not also switch off the vendor's own held-listing notice.
			// Kept last but one so the groups people manage for their members read as one block.
			'admin'       => esc_html__( 'For Site Owners', 'notifications-for-hivepress' ),
			'other'       => esc_html__( 'Other', 'notifications-for-hivepress' ),
		];
	}

	/**
	 * Gets the icon that stands for a group on the settings screen.
	 *
	 * Font Awesome 5 spellings throughout, resolved by the library's alias table, so the same name
	 * works whichever copy of the library a site happens to load. Nothing here reaches a member:
	 * these draw only on the Types cards in wp-admin.
	 *
	 * @param string $group Group key.
	 * @return string Icon name, or an empty string for a group this does not know.
	 */
	public function get_group_icon( $group ) {
		$icons = [
			'listings'    => 'list',
			'messages'    => 'comments',
			'bookings'    => 'calendar-check',
			'orders'      => 'receipt',
			'requests'    => 'clipboard-list',
			'memberships' => 'id-card',
			'gallery'     => 'images',
			'performance' => 'chart-line',
			'account'     => 'user',
			'admin'       => 'shield-alt',
			'other'       => 'ellipsis-h',
		];

		return (string) hp\get_array_value( $icons, (string) $group, '' );
	}

	/**
	 * Gets the group of a notification type.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public function get_type_group( $type ) {

		// A type may name its own group. The prefix map below is the rule for everything HivePress
		// and its extensions send, because those names are all built from a model name - but it
		// cannot express "this one goes to the site owner", which cuts across the features
		// entirely. Two moderation types differ only in who reads them, and no prefix can say so.
		$declared = hp\get_array_value( $this->get_type_args( $type ), 'group' );

		if ( $declared && isset( $this->get_groups()[ $declared ] ) ) {
			return $declared;
		}

		/*
		 * The owner-addressed types that come from HivePress and its extensions, which cannot
		 * declare a group because this plugin builds them from the email classes rather than
		 * registering them by hand.
		 *
		 * Each one was confirmed by reading the recipient at its send site: every entry below is
		 * `'recipient' => get_option( 'admin_email' )`. Their names all begin with a member-facing
		 * prefix - `listing_`, `order_`, `payout_`, `request_` - so the map underneath files them
		 * with the notifications a member manages, and until 2026-09-02 every signed-in member could
		 * see and switch preferences for notifications only the site owner receives. The three types
		 * already in this group had it right; these thirteen are the same fault, unnoticed because
		 * the prefix was doing the filing.
		 *
		 * Naming them one by one, rather than testing the recipient, because the recipient is only
		 * known while an email is being sent and this question is asked while a settings form is
		 * being built. An extension adding another owner-facing email is handled by the filter on
		 * get_types(), which can set 'group' directly.
		 */
		if ( in_array( $type, self::OWNER_TYPES, true ) ) {
			return 'admin';
		}

		$groups = [
			'listing'    => 'listings',
			'holiday'    => 'listings',
			'moderation' => 'listings',
			'message'    => 'messages',
			'booking'    => 'bookings',
			'order'      => 'orders',
			'payout'     => 'orders',
			'request'    => 'requests',
			'offer'      => 'requests',
			'membership' => 'memberships',
			'gallery'    => 'gallery',
			'analytics'  => 'performance',
			'trust'      => 'performance',
			'user'       => 'account',
			'vendor'     => 'account',
			'badge'      => 'account',
		];

		return hp\get_array_value( $groups, strtok( (string) $type, '_' ), 'other' );
	}

	/**
	 * Gets the notification types users and admins can manage.
	 *
	 * System types such as announcements are excluded, because they can't be switched off.
	 *
	 * @return array
	 */
	public function get_optional_types() {
		return array_filter(
			$this->get_types(),
			function ( $args ) {
				return ! hp\get_array_value( $args, '_system' );
			}
		);
	}

	/**
	 * Gets who a notification type is meant for.
	 *
	 * "vendor" means the type only ever reaches somebody who sells on the site - a notice about
	 * their own listings, their own gallery, their own figures. Anything else is "all", which is the
	 * default: a type says nothing and is offered to everybody, so no existing type changes
	 * behaviour by this argument arriving.
	 *
	 * Deliberately NOT a delivery rule. Nothing here decides who receives a notification - the code
	 * that raises one already knows who it is for, and a buyer never triggers a vendor's event. This
	 * only decides who is OFFERED the preference, so a member who can never receive a thing is not
	 * asked whether they would like it by email.
	 *
	 * @param string $type Notification type.
	 * @return string Audience name.
	 */
	public function get_type_audience( $type ) {
		$audience = hp\get_array_value( $this->get_type_args( $type ), 'audience' );

		return 'vendor' === $audience ? 'vendor' : 'all';
	}

	/**
	 * Checks whether a user sells on this site.
	 *
	 * Any vendor profile counts, whatever its status. Core's own lookup adds `'status' => 'publish'`
	 * (controllers/class-user.php:1055), which is right for "is there a profile page to link to" and
	 * wrong here: a profile awaiting approval belongs to somebody who has already registered as a
	 * vendor, and taking their notification settings away while they wait - then handing them back
	 * on approval - is a worse answer than showing them settings a little early. Chris chose this on
	 * 2026-09-02.
	 *
	 * Cached per user for the request. The settings form asks once, but the types are looped for
	 * every group on the page.
	 *
	 * @param int $user_id User ID, or 0 for the current user.
	 * @return bool
	 */
	public function is_vendor( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		if ( ! isset( $this->vendors[ $user_id ] ) ) {
			$this->vendors[ $user_id ] = (bool) Models\Vendor::query()->filter( [ 'user' => $user_id ] )->get_first_id();
		}

		return $this->vendors[ $user_id ];
	}

	/**
	 * Checks whether a type should be offered to a user.
	 *
	 * @param string $type Notification type.
	 * @param int    $user_id User ID, or 0 for the current user.
	 * @return bool
	 */
	public function is_type_offered( $type, $user_id = 0 ) {
		return 'vendor' !== $this->get_type_audience( $type ) || $this->is_vendor( $user_id );
	}

	/**
	 * Gets the arguments of a notification type.
	 *
	 * @param string $type Notification type.
	 * @return array
	 */
	public function get_type_args( $type ) {
		return (array) hp\get_array_value( $this->get_types(), $type, [] );
	}

	/**
	 * Gets the label of a notification type.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public function get_type_label( $type ) {
		return (string) hp\get_array_value( $this->get_type_args( $type ), 'label', ucfirst( str_replace( '_', ' ', (string) $type ) ) );
	}

	/**
	 * Gets the settings-screen note for a notification type, or '' for most.
	 *
	 * A sentence for the site owner that would mean nothing to a member, appended to the group
	 * hint on the Types section and to the title field on the Text section. Never rendered on the
	 * front end: the one note there is names another plugin, and a member's preferences screen is
	 * not the place to learn which extensions the site runs.
	 *
	 * @param string $type Notification type.
	 * @return string Leading space plus the sentence, or ''.
	 */
	public function get_type_note( $type ) {
		if ( 'hpes_broadcast' === $type ) {
			return ' ' . esc_html__( 'Site Emails are the messages sent with the Email Composer in Email Studio for HivePress.', 'notifications-for-hivepress' );
		}

		return '';
	}

	/**
	 * Gets the label of a notification type as members should see it.
	 *
	 * HivePress labels the paired emails "(User)" and "(Vendor)" - "Booking Confirmed (User)" -
	 * which the admin needs, because both types are on the settings screen at once, and a member
	 * never does: each member only ever receives their own side of the pair, so the bracket is
	 * noise on the front end. The suffix is matched through the translator strings the labels are
	 * built with (reference/extensions/hivepress-bookings/includes/emails/class-booking-confirm-user.php:31),
	 * so a theme that renames vendors to Hosts is stripped just the same, with the literal words
	 * kept as a fallback for a site whose translator strings are unavailable.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public function get_type_public_label( $type ) {
		$label = $this->get_type_label( $type );

		// Assign before testing: core defines __get() but no __isset() on components.
		$translator = hivepress()->translator;

		$suffixes = [ 'User', 'Vendor' ];

		if ( $translator ) {
			$suffixes[] = (string) $translator->get_string( 'user' );
			$suffixes[] = (string) $translator->get_string( 'vendor' );
		}

		foreach ( array_filter( array_unique( $suffixes ) ) as $suffix ) {
			$ending = ' (' . $suffix . ')';

			if ( substr( $label, -strlen( $ending ) ) === $ending ) {
				return substr( $label, 0, -strlen( $ending ) );
			}
		}

		return $label;
	}

	/**
	 * Gets the title the admin has written for a notification type, or an empty string.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public function get_type_custom_title( $type ) {
		$title = get_option( 'hp_notification_title_' . $type );

		return is_string( $title ) ? trim( $title ) : '';
	}

	/**
	 * Gets the heading a notification type shows to members.
	 *
	 * The admin's own title wins where one is saved under Settings > Text; otherwise the type's
	 * public label is used, which is the label without the "(User)"/"(Vendor)" bracket. Every
	 * front-end surface reads this one method - the pop-up, the bell dropdown, the notifications
	 * page and its filter - so a saved title reaches all of them at once.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public function get_type_title( $type ) {
		$title = $this->get_type_custom_title( $type );

		return '' !== $title ? $title : $this->get_type_public_label( $type );
	}

	/**
	 * Gets the link label of a notification type.
	 *
	 * "View" is honest but bland; a type that knows where its link goes can say so, like "Leave
	 * a review" on a completed booking.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public function get_type_link_text( $type ) {
		return (string) hp\get_array_value( $this->get_type_args( $type ), 'link_text', esc_html__( 'View', 'notifications-for-hivepress' ) );
	}

	/**
	 * Gets the channels a notification type can be delivered through.
	 *
	 * The extra types have no email behind them, so they can only ever be a pop-up.
	 *
	 * @param string $type Notification type.
	 * @return array
	 */
	public function get_type_channels( $type ) {
		$channels = (array) hp\get_array_value( $this->get_type_args( $type ), 'channels', [ 'onsite' ] );

		return array_values( array_intersect( array_keys( $this->get_channels() ), $channels ) );
	}

	/**
	 * Gets the text template of a notification type.
	 *
	 * Falls back to the default the type declares, and then to nothing, which means the email
	 * subject is used as it arrives.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public function get_type_text( $type ) {
		$text = get_option( 'hp_notification_text_' . $type );

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			$text = hp\get_array_value( $this->get_type_args( $type ), 'text', '' );
		}

		return (string) $text;
	}

	/**
	 * Gets the wording a notification type uses once it has rolled others up.
	 *
	 * Only types that declare it are ever rolled up. Everything else keeps one notification per
	 * event, which is what almost all of them want.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public function get_type_grouped_text( $type ) {
		$text = get_option( 'hp_notification_text_grouped_' . $type );

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			$text = hp\get_array_value( $this->get_type_args( $type ), 'text_grouped', '' );
		}

		return (string) $text;
	}

	/**
	 * Adds a notification, rolling it into a recent one about the same thing.
	 *
	 * Some events arrive in bursts. A popular photo can take fifty likes in an evening, and fifty
	 * separate notifications - each one a pop-up, each one a push to somebody's phone - is not a
	 * feature, it is the reason people switch notifications off altogether.
	 *
	 * So a second event about the same thing rewrites the first notification instead of adding
	 * another: "Alice liked your photo" becomes "Alice and 12 others liked your photo". The roll-up
	 * only ever absorbs a notification the reader has not opened yet, because rewriting something
	 * they have already read would change history under them, and only within a window, so a like
	 * next week is properly its own news.
	 *
	 * @param array  $args Notification arguments, as add_notification() takes them, plus
	 *                     `grouped_text` for the wording used from the second event onwards.
	 * @param string $group_key Identifies the thing being rolled up, such as a photo ID.
	 * @return object|null
	 */
	public function add_grouped_notification( $args, $group_key ) {
		$group_key = (string) $group_key;

		$grouped_text = (string) hp\get_array_value( $args, 'grouped_text' );

		unset( $args['grouped_text'] );

		if ( ! $group_key || ! $grouped_text ) {
			return $this->add_notification( $args );
		}

		$user_id = (int) hp\get_array_value( $args, 'user' );
		$type    = (string) hp\get_array_value( $args, 'type' );

		if ( ! $user_id || ! $type ) {
			return null;
		}

		/**
		 * Filters how long a burst of related events keeps rolling into one notification.
		 *
		 * @hook hivepress/v1/notification_group_window
		 * @param {int} $seconds Window length in seconds. Default one day.
		 * @param {string} $type Notification type.
		 * @return {int} Window length in seconds.
		 */
		$window = (int) apply_filters( 'hivepress/v1/notification_group_window', DAY_IN_SECONDS, $type );

		$existing = $window > 0 ? get_comments(
			[
				'type'       => 'hp_notification',
				'user_id'    => $user_id,

				// Unread only. comment_karma is where this plugin keeps the read flag, and
				// WP_Comment_Query supports it directly (class-wp-comment-query.php:764).
				'karma'      => 0,

				/*
				 * NOT 'status' => 'any'. In WP_Comment_Query, 'any' overrides every other status
				 * and drops the status clause altogether (class-wp-comment-query.php:568-591), so
				 * trashed rows come back too. Notifications are always stored with
				 * comment_approved = 1 (the read flag lives in comment_karma precisely so that
				 * column can stay at 1), so 'any' bought nothing and only let the trash through -
				 * and rolling a burst INTO a trashed row is how it disappears. A vendor who
				 * dismissed one "Alice liked your photo" without opening it had every further like
				 * on that photo for the next 24 hours written into the deleted row: gone from the
				 * list, the bell, the unread badge and push, while the toast still popped up
				 * saying "and 3 others", and gone for good when WordPress emptied the trash about
				 * thirty days later. The default, 'all', is comment_approved IN (0,1), which is
				 * every live notification and no trashed one.
				 */
				'number'     => 1,
				'orderby'    => 'comment_date_gmt',
				'order'      => 'DESC',
				'date_query' => [
					[
						'after'     => gmdate( 'Y-m-d H:i:s', time() - $window ),
						'column'    => 'comment_date_gmt',
						'inclusive' => true,
					],
				],
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- two indexed meta keys on a table that only ever holds this plugin's own rows for one user; the alternative is a notification storm.
					'relation' => 'AND',
					[
						'key'   => 'hp_type',
						'value' => $type,
					],
					[
						'key'   => 'hp_notification_group',
						'value' => $group_key,
					],
				],
			]
		) : [];

		$existing = is_array( $existing ) ? reset( $existing ) : false;

		// Nothing recent to roll into, so this is the first of its burst.
		if ( ! $existing ) {
			$notification = $this->add_notification( $args );

			if ( $notification ) {
				add_comment_meta( $notification->get_id(), 'hp_notification_group', $group_key, true );
				add_comment_meta( $notification->get_id(), 'hp_notification_group_count', 1, true );
			}

			return $notification;
		}

		$comment_id = (int) $existing->comment_ID;
		$others     = max( 1, (int) get_comment_meta( $comment_id, 'hp_notification_group_count', true ) );

		update_comment_meta( $comment_id, 'hp_notification_group_count', $others + 1 );

		$notification = Models\Hpnf_Notification::query()->get_by_id( $comment_id );

		if ( ! $notification ) {
			return null;
		}

		// The count in the wording is everyone except the person named, so it lags the stored
		// total by one: two likes reads "Alice and 1 other".
		$notification->fill(
			[
				'text'         => $this->truncate( $grouped_text, 256 ),
				'created_date' => current_time( 'mysql' ),
				'url'          => (string) hp\get_array_value( $args, 'url' ),
				'image'        => (string) hp\get_array_value( $args, 'image' ),
			]
		);

		if ( ! $notification->save() ) {
			return null;
		}

		// Not counted as a second notification in the statistics: it is the same one, said again.
		// The unread count is untouched for the same reason - it never went up.
		$this->add_to_queue( $user_id, $notification );

		/**
		 * Fires when an on-site notification has absorbed another event.
		 *
		 * @hook hivepress/v1/notification_group
		 * @param {object} $notification Notification object.
		 * @param {int} $count How many events it now covers.
		 */
		do_action( 'hivepress/v1/notification_group', $notification, $others + 1 );

		return $notification;
	}

	/**
	 * Renders a text template against a set of tokens.
	 *
	 * @param string $text Text template.
	 * @param array  $tokens Token values.
	 * @return string
	 */
	public function render_text( $text, $tokens ) {
		if ( ! $text ) {
			return '';
		}

		$tokens = (array) $tokens;

		/*
		 * Two tokens every notification can rely on, whichever extension raised it.
		 *
		 * replace_tokens() only walks the tokens it is given, so a %token% with nothing behind it is
		 * left in the text exactly as typed - a site owner who writes "Welcome to %site_name%" and
		 * gets "Welcome to %site_name%" in front of their users has been let down by us, not by
		 * their typing. Adding them here rather than at each call site means every path gets them:
		 * the email listener, the extras, badges, announcements and the backfill alike.
		 *
		 * They never overwrite an extension's own token of the same name.
		 *
		 * The site name used to be decoded here, because WordPress stores blogname already run
		 * through esc_html() and get_bloginfo( 'name' ) hands back "Bob &amp; Sons" for a site
		 * called "Bob & Sons". That decode has moved to decode_text(), which every surface now
		 * calls as it serves the stored string: an entity was never unique to the site name (an
		 * order total arrives as "&pound;10.00" from the same class of escaping), and decoding on
		 * the way in could only ever help rows written after the fix shipped. Decoding here as
		 * well would decode twice, which is wrong for the one site owner who really did type
		 * "&amp;" into their title.
		 */
		$tokens += [
			'site_name' => (string) get_bloginfo( 'name' ),
			'site_url'  => home_url( '/' ),
		];

		/*
		 * The settings hint promises "%token|fallback%" wording for when a detail is missing, and
		 * core only keeps that promise for model tokens: replace_tokens() takes the fallback branch
		 * when the value is null (hivepress/includes/helpers.php:381), and a model field that is
		 * absent is null. The plain-string tokens this plugin passes never are - a missing detail
		 * arrives as an empty string - so the documented syntax silently never fired for them. An
		 * empty string is therefore handed to core as null, which is the branch the owner wrote
		 * the fallback for. A bare %token% without a bar still renders as nothing, exactly as
		 * before, because core's default fallback is the empty string.
		 */
		foreach ( $tokens as $name => $value ) {
			if ( '' === $value ) {
				$tokens[ $name ] = null;
			}
		}

		$text = trim( wp_strip_all_tags( hp\replace_tokens( $tokens, $text ) ) );

		/*
		 * A token that never made it into the array at all - not passed for this event, or dropped
		 * by a caller's filter - is still sitting in the text as "%name|fallback%", because core
		 * only walks the tokens it is given. The owner wrote that fallback for exactly this case,
		 * so it is honoured here rather than shipped raw. The pattern mirrors core's own: a token
		 * name, optionally a dot and a field, a bar, then anything up to the closing percent sign.
		 * A bare %name% with no bar is deliberately left alone - a raw token is the visible clue
		 * the hint relies on when somebody mistypes one.
		 */
		$text = preg_replace_callback(
			'/%[a-z0-9_.]+\s*\|([^%]+)%/i',
			function ( $matches ) {
				return trim( $matches[1] );
			},
			$text
		);

		return trim( $text );
	}

	/**
	 * Drops the tokens that have no value at all.
	 *
	 * Only genuinely absent values are dropped. array_filter() with no callback also throws away
	 * an empty string and, worse, the string "0" - and render_text() leaves any token it is not
	 * given sitting in the wording exactly as typed, so a listing literally titled "0" reached
	 * its owner reading "added %listing_title% to their favourites". An empty value renders as
	 * nothing, which is the right answer for an optional token; a raw placeholder in front of a
	 * real person never is. Same reasoning, same filter, as the extensions component's deliver().
	 *
	 * @param array $tokens Token values.
	 * @return array
	 */
	protected function filter_tokens( $tokens ) {
		return array_filter(
			(array) $tokens,
			function ( $value ) {
				return ! is_null( $value ) && [] !== $value;
			}
		);
	}

	/**
	 * Decodes the HTML entities in a stored notification text.
	 *
	 * @param string $text Stored notification text.
	 * @return string
	 */
	public function decode_text( $text ) {
		/*
		 * Notification text is plain text, but it is assembled from token values that reached us
		 * already HTML-escaped, and nothing in the pipeline decodes them: replace_tokens() only
		 * substitutes and wp_strip_all_tags() removes tags without touching entities.
		 *
		 * The order emails are the clearest case. HivePress builds %order_amount% with
		 * format_price(), which is wp_strip_all_tags( wc_price( $total ) )
		 * (reference/hivepress/includes/components/class-woocommerce.php:194), and WooCommerce
		 * writes the currency symbol as an entity - so the markup around it is stripped and a bare
		 * "&pound;10.00" is what gets stored. The site name does the same thing through
		 * get_bloginfo( 'name' ), and any esc_html'd listing title or display name will too.
		 *
		 * Only one of the two renderers ever showed it. The notifications page prints the string
		 * with esc_html(), which does not double-encode, so "&pound;" survives into the HTML and
		 * the browser paints "£" - correct entirely by accident. The pop-up, the bell and the
		 * service worker's OS notification assign the same string to textContent, where an entity
		 * is just characters, and the reader saw "Total &pound;10.00" (staging, 18 Aug 2026).
		 *
		 * So the decode belongs here, at the point each surface serves the string, not at the
		 * point it is written: every notification already in the database is repaired by it, with
		 * no migration. Decoding it once anywhere else as well would be a second decode.
		 *
		 * html_entity_decode() rather than wp_specialchars_decode(), because the latter knows only
		 * the five specialchars and would leave "&pound;" - the actual symptom - untouched.
		 * ENT_QUOTES covers both quote forms, ENT_HTML5 is needed for "&apos;", and the charset is
		 * named rather than left to the PHP default.
		 *
		 * This cannot open an escaping hole, and the check is that every consumer still escapes
		 * for its own context afterwards: the page escapes with esc_html(), the exporter's values
		 * go through core's own esc_html(), and the three JSON payloads are read into textContent
		 * by the script - never innerHTML. A stored "&lt;script&gt;" therefore decodes to a
		 * literal "<script>" that every surface shows as visible characters.
		 */
		return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Gets the notification types that start switched off.
	 *
	 * Three of these were hardcoded in three separate places, which is exactly the arrangement that
	 * lets the settings screen and the actual behaviour drift apart. A type can now say so itself,
	 * and every place that needs to know reads it from here.
	 *
	 * Password Reset and Email Verification are off because ticking one puts it under that person's
	 * Email box, and anybody who has cleared Email then cannot sign in to put it right. The rest are
	 * off because they are either high volume or aimed at a site owner who may not want them.
	 *
	 * @return array
	 */
	public function get_default_off_types() {
		$types = [ 'user_password_request', 'user_email_verify' ];

		foreach ( $this->get_types() as $type => $args ) {
			if ( hp\get_array_value( $args, '_default_off' ) ) {
				$types[] = $type;
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Gets the notification types the site administrator has switched on.
	 *
	 * The choices are saved per group, so each group can be managed on its own. The single
	 * pre-1.5 option is honoured when no grouped choice has ever been saved, and system types
	 * are always on.
	 *
	 * @return array
	 */
	public function get_enabled_types() {
		$optional = array_keys( $this->get_optional_types() );

		// Group the optional types.
		$grouped = [];

		foreach ( $optional as $type ) {
			$grouped[ $this->get_type_group( $type ) ][] = $type;
		}

		$enabled = [];
		$saved   = false;

		foreach ( $grouped as $group => $group_types ) {
			$choice = get_option( 'hp_notification_types_' . $group );

			if ( is_array( $choice ) ) {
				$saved   = true;
				$enabled = array_merge( $enabled, array_intersect( $choice, $group_types ) );
			} elseif ( '' === $choice ) {
				/*
				 * Nothing ticked, deliberately. The settings form posts no value at all for a
				 * checkbox group with every box clear, so the option stores as an empty string
				 * rather than an empty array - the stored-empty trap in hivepress-settings.md.
				 *
				 * Without this branch an empty string fell through to "never configured" below and
				 * switched the entire group back on. Measured: unticking all eight ticked boxes in
				 * the Listings group and saving left thirteen types enabled, five more than before
				 * the admin touched anything, and the screen then redrew with them all ticked.
				 *
				 * An absent option is still false, not '', so the two cases stay distinguishable
				 * and a site that has never saved keeps the sensible defaults.
				 */
				$saved = true;
			} else {
				$enabled = array_merge( $enabled, array_diff( $group_types, $this->get_default_off_types() ) );
			}
		}

		// Fall back to the single pre-1.5 option.
		if ( ! $saved ) {
			$legacy = get_option( 'hp_notification_types' );

			if ( is_array( $legacy ) ) {
				$enabled = array_intersect( $legacy, $optional );
			}
		}

		// System types are always on.
		$system = array_keys( array_diff_key( $this->get_types(), $this->get_optional_types() ) );

		return array_values( array_unique( array_merge( $enabled, $system ) ) );
	}

	/**
	 * Switches on notification types contributed by a newly installed extension.
	 *
	 * The admin's choice is stored per group as the list of ticked types, and HivePress seeds that
	 * option with the defaults when it activates or updates. Both mean a stored list only ever
	 * contains the types that existed when it was written, so a type arriving later - Badge Earned
	 * when Badges is installed, or every booking type when Bookings is - was absent from the list
	 * and therefore silently switched off, with its checkbox unticked and no way for anyone to
	 * know a feature had been added.
	 *
	 * A record of every type already offered to the admin distinguishes "new" from "deliberately
	 * unticked", which the stored list alone cannot. New types are written into the stored list, so
	 * the settings screen, this record and the actual behaviour always agree. On first run the
	 * record is simply seeded from what exists today, so nothing an admin has turned off is ever
	 * resurrected.
	 */
	public function maybe_register_new_types() {
		$known    = get_option( 'hp_notification_known_types' );
		$optional = array_keys( $this->get_optional_types() );

		// First run: everything that exists now counts as already offered.
		if ( ! is_array( $known ) ) {
			update_option( 'hp_notification_known_types', $optional, false );

			return;
		}

		// These two are off unless the admin asks for them, so a new install must not turn them on.
		$new = array_diff( $optional, $known, $this->get_default_off_types() );

		if ( ! $new ) {
			return;
		}

		// Add each new type to its group's stored list, where that list has been saved.
		$grouped = [];

		foreach ( $new as $type ) {
			$grouped[ $this->get_type_group( $type ) ][] = $type;
		}

		foreach ( $grouped as $group => $group_types ) {
			$option = 'hp_notification_types_' . $group;
			$choice = get_option( $option );

			if ( ! is_array( $choice ) ) {
				continue;
			}

			update_option( $option, array_values( array_unique( array_merge( $choice, $group_types ) ) ) );
		}

		update_option( 'hp_notification_known_types', array_values( array_unique( array_merge( $known, $optional ) ) ), false );

		// The cached type list is unaffected, but the enabled list is now stale within this request.
		$this->types = null;
	}

	/**
	 * Seeds notifications for unread messages that predate the plugin.
	 *
	 * The plugin mirrors events as they happen, so anything from before it was installed would
	 * never appear. Unread messages are the one thing that can be read back reliably: the Messages
	 * extension stores each one as an hp_message comment with the recipient in comment_karma, and
	 * marks it read by setting comment_approved to 1, so the unread ones are the comments still
	 * "hold". Each unread one becomes a quiet notification - list only, backdated to when the
	 * message arrived, with no pop-up, push or statistics - so a new install starts with the
	 * unread state people actually have.
	 *
	 * Runs once. Users who already have notifications are skipped entirely, so an update to an
	 * install that has been mirroring for a while can't create duplicates for them.
	 */
	public function maybe_backfill() {
		if ( ! class_exists( '\HivePress\Models\Message' ) || get_option( 'hp_notification_backfill_done' ) ) {
			return;
		}

		// The flag goes first, so two requests arriving together can't both run the scan.
		update_option( 'hp_notification_backfill_done', 1 );

		// The Messages extension's email class is Message_Send, so that's the type name the mirror
		// uses for received messages; there is no "message_receive" type.
		if ( ! in_array( 'message_send', $this->get_enabled_types(), true ) ) {
			return;
		}

		// Get the newest unread messages, capped hard so a large site can't stall this request.
		// The Messages extension aliases its read flag to comment_approved, so unread means the
		// "hold" status; there is no meta to query.
		$messages = get_comments(
			[
				'type'    => 'hp_message',
				'status'  => 'hold',
				'number'  => 100,
				'orderby' => 'comment_date',
				'order'   => 'DESC',
			]
		);

		$seeded = [];
		$url    = hivepress()->router->get_route( 'messages_view_page' ) ? (string) hivepress()->router->get_url( 'messages_view_page' ) : '';

		foreach ( $messages as $message ) {
			$recipient_id = absint( $message->comment_karma );
			$sender_id    = absint( $message->user_id );

			if ( ! $recipient_id || ! $sender_id || $recipient_id === $sender_id ) {
				continue;
			}

			// A few per person is a nudge; a hundred is a wall. The counts are keyed by user ID, so
			// they're read directly: hp\get_array_value() documents its key as a string.
			$seeded_count = isset( $seeded[ $recipient_id ] ) ? (int) $seeded[ $recipient_id ] : 0;

			if ( $seeded_count >= 5 ) {
				continue;
			}

			// Skip anyone who already has notifications: mirroring was active for them, so their
			// unread messages either have one or were deliberately cleared.
			if ( ! isset( $seeded[ $recipient_id ] ) && Models\Hpnf_Notification::query()->filter( [ 'user' => $recipient_id ] )->get_first_id() ) {
				$seeded[ $recipient_id ] = 100;

				continue;
			}

			// Get the sender, for the wording and the avatar.
			$sender = Models\User::query()->get_by_id( $sender_id );

			if ( ! $sender ) {
				continue;
			}

			// Use the admin's wording for this type where it renders, like a live notification. The
			// message rides along as a model, so tokens with a field, like %message.text%, resolve.
			$tokens = [
				'sender'  => $sender,
				'message' => \HivePress\Models\Message::query()->get_by_id( absint( $message->comment_ID ) ),
			];

			$text = $this->render_text( $this->get_type_text( 'message_send' ), $this->filter_tokens( $tokens ) );

			if ( ! $text ) {
				/* translators: %s: sender name. */
				$text = sprintf( esc_html__( '%s sent you a message.', 'notifications-for-hivepress' ), $sender->get_display_name() );
			}

			$notification = $this->add_notification(
				[
					'user'         => $recipient_id,
					'type'         => 'message_send',
					'text'         => $text,
					'url'          => $url,
					'image'        => $this->get_user_image( $sender ),
					'quiet'        => true,
					'created_date' => (string) $message->comment_date,
				]
			);

			if ( $notification ) {
				$seeded[ $recipient_id ] = $seeded_count + 1;
			}
		}
	}

	/**
	 * Carries the regrouped owner types' saved choices into the For Site Owners option.
	 *
	 * 1.6.0 moved thirteen types - the ones HivePress addresses to the site owner - out of
	 * Listings, Orders, Requests and Account into For Site Owners (see OWNER_TYPES). The move fixed
	 * what members were shown, and broke something quieter: get_enabled_types() reads each group's
	 * SAVED option and keeps only the types that are in it, so on a site that had ever saved the
	 * Types section, a moved type was now looked up in an option that had never heard of it and
	 * came out disabled. Thirteen owner notifications went silent, and the screen showed "3/16" on
	 * the card as the only sign. Found on 2026-09-02 while building those cards; 1.6.0 had shipped
	 * that morning.
	 *
	 * The rewrite reads each moved type's choice from where it USED to live and writes the answer
	 * into the option where it now lives. A former group that was never saved means the type was
	 * on by default; one saved as an empty string means everything in it was deliberately off.
	 * The former options are left alone - get_enabled_types() ignores a name that is no longer in
	 * that group - and nothing runs on a site that never saved any of the five options involved,
	 * because the defaults already give the right answer there.
	 *
	 * @return void
	 */
	protected function migrate_owner_types_group() {
		$former = [
			'listing' => 'listings',
			'order'   => 'orders',
			'payout'  => 'orders',
			'request' => 'requests',
			'offer'   => 'requests',
			'vendor'  => 'account',
		];

		$off      = $this->get_default_off_types();
		$admin    = get_option( 'hp_notification_types_admin' );
		$involved = is_array( $admin ) || '' === $admin;
		$enabled  = [];

		foreach ( self::OWNER_TYPES as $type ) {
			$group = hp\get_array_value( $former, strtok( $type, '_' ) );

			if ( ! $group ) {
				continue;
			}

			$choice = get_option( 'hp_notification_types_' . $group );

			if ( is_array( $choice ) ) {
				$involved = true;

				if ( in_array( $type, $choice, true ) ) {
					$enabled[] = $type;
				}
			} elseif ( '' === $choice ) {
				$involved = true;
			} elseif ( ! in_array( $type, $off, true ) ) {
				$enabled[] = $type;
			}
		}

		if ( ! $involved ) {
			return;
		}

		// What the admin group held before the move: its saved list, or its defaults if it was
		// never saved (the three moderation types, none of which is off by default).
		$kept = [];

		if ( is_array( $admin ) ) {
			$kept = $admin;
		} elseif ( '' !== $admin ) {
			foreach ( array_keys( $this->get_optional_types() ) as $type ) {
				if ( 'admin' === $this->get_type_group( $type ) && ! in_array( $type, self::OWNER_TYPES, true ) && ! in_array( $type, $off, true ) ) {
					$kept[] = $type;
				}
			}
		}

		$merged = array_values( array_unique( array_merge( array_map( 'strval', $kept ), $enabled ) ) );

		// An empty list is stored the way the settings form stores it, so the "deliberately none"
		// branch in get_enabled_types() still recognises it.
		update_option( 'hp_notification_types_admin', $merged ? $merged : '' );
	}

	/**
	 * Runs the one-time rewrites an update needs, once per version.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		$previous = (string) get_option( 'hp_notification_version' );

		if ( version_compare( $previous, HP_NOTIFICATIONS_VERSION, '>=' ) ) {
			return;
		}

		// The version goes first, so two requests arriving together can't both run the rewrites.
		update_option( 'hp_notification_version', HP_NOTIFICATIONS_VERSION );

		if ( version_compare( $previous, '1.7.0', '<' ) ) {
			$this->migrate_owner_types_group();
		}

		/*
		 * 1.1.0 renamed the settings form from "notification_update" to "hpnf_notification_update":
		 * the form meta name follows the class name, and the class gained the plugin's Hpnf_ prefix.
		 * HivePress's reCAPTCHA option stores a list of form names (components/class-form.php:499),
		 * so a saved tick for the old name would silently stop protecting the form after the update.
		 * The stored value is rewritten once, and only where it is actually present.
		 */
		$forms = get_option( 'hp_recaptcha_forms' );

		if ( is_array( $forms ) ) {
			$key = array_search( 'notification_update', $forms, true );

			if ( false !== $key ) {
				$forms[ $key ] = 'hpnf_notification_update';

				update_option( 'hp_recaptcha_forms', $forms );
			}
		}

		/*
		 * The same rename invalidates Turnstile for HivePress's saved tick too: that plugin keeps
		 * its own list of protected form names in "tfhp_protected_forms" and matches them by exact
		 * name, so a stored "notification_update" would silently stop protecting the settings form.
		 * A sibling plugin's option is touched here because it was this plugin's rename that broke
		 * the stored value, and it is touched directly rather than through Turnstile's functions
		 * because Turnstile may be inactive right now yet its option must already be correct when
		 * it comes back. Absent (false), stored-'' or any other non-array value means no tick was
		 * ever saved, so there is nothing to rewrite and the guard leaves it untouched.
		 */
		$forms = get_option( 'tfhp_protected_forms' );

		if ( is_array( $forms ) ) {
			$key = array_search( 'notification_update', $forms, true );

			if ( false !== $key ) {
				$forms[ $key ] = 'hpnf_notification_update';

				update_option( 'tfhp_protected_forms', $forms );
			}
		}
	}

	/**
	 * Handles an email that's being sent.
	 *
	 * Decides which channels the recipient wants for this type, stops the email if they've turned
	 * it off, and adds the on-site notification if they haven't.
	 *
	 * @param object $email Email object.
	 */
	public function process_email( $email ) {

		/**
		 * Filters whether an email becomes an on-site notification at all.
		 *
		 * This hook exists because "an email was sent" is not always "something happened to a
		 * member". An email can be sent as a TEST - Email Studio's previews and test sends go
		 * through HivePress's real send path on purpose, so that what an owner checks is exactly
		 * what a member would receive - and those must not land in anybody's notifications feed.
		 * Chris saw his own Email Studio test sends appear there on 2026-09-02.
		 *
		 * Returning false skips the email entirely: no notification, no feed entry, no delivery.
		 * The plugin doing the unusual thing is the one that should say so, so the veto lives here
		 * rather than this component carrying a list of other plugins it knows about.
		 *
		 * @hook hpnf_notification_process_email
		 * @param {bool} $process Whether to turn this email into a notification.
		 * @param {object} $email Email object.
		 * @return {bool} Whether to turn this email into a notification.
		 */
		if ( ! apply_filters( 'hpnf_notification_process_email', true, $email ) ) {
			return;
		}

		// A fresh email means any earlier suppression flag is stale: its wp_mail() either already
		// ran or never will, so it must not linger and catch a later email that happens to match.
		$this->suppressed = null;

		// Get type. Types the admin hasn't enabled are left alone entirely, so HivePress sends
		// them exactly as it would without this plugin.
		$type = call_user_func( [ get_class( $email ), 'get_meta' ], 'name' );

		if ( ! in_array( $type, $this->get_enabled_types(), true ) ) {
			return;
		}

		// Check content. HivePress doesn't send an email with an empty body, and this hook runs
		// before that check, so an emptied email is skipped here too rather than turning into an
		// on-site notification that no email ever matched.
		if ( ! $email->get_body() ) {
			return;
		}

		// Get user.
		$user_id = $this->get_recipient_id( $email );

		if ( ! $user_id ) {
			return;
		}

		// Get channels.
		$channels = $this->get_user_channels( $user_id, $type );

		/*
		 * Stop the email if the recipient turned it off. The body is deliberately left alone,
		 * because emptying it is how HivePress itself disables an email and that would also hide
		 * whether there was anything to send.
		 *
		 * One email is exempt: a message sent while the Messages extension's storage setting is
		 * off. In that mode the extension never saves the message - it puts the text into the
		 * email body and moves on (messages/controllers/class-message.php:230-242), so the email
		 * IS the message, and stopping it would destroy the only copy of what one person wrote to
		 * another while the sender is told it went through. The on-site notification cannot carry
		 * the text instead: it sits in a list for weeks, is served by REST and pushed to the OS,
		 * which is exactly why the registration password is kept out of it below. So for this one
		 * type, in this one configuration, delivery beats the reader's email preference - an email
		 * they asked not to have is a smaller wrong than losing their words.
		 */
		if ( ! in_array( 'email', $channels, true ) && ( 'message_send' !== $type || get_option( 'hp_message_enable_storage' ) ) ) {
			$this->suppressed = [
				'recipient' => $this->get_recipient( $email ),
				'subject'   => (string) $email->get_subject(),
			];
		}

		if ( ! in_array( 'onsite', $channels, true ) ) {
			return;
		}

		// Get tokens. The registration password is dropped before anything can see it: it must never
		// reach the stored text, and it must not be recorded as an available token either. Anyone
		// who typed it into the settings box under an older build now gets a literal %user_password%
		// in the notification, which is ugly but visible - far better than a silent leak.
		$tokens = array_diff_key( (array) $email->get_tokens(), [ 'user_password' => '' ] );

		$this->update_seen_tokens( $type, $tokens );

		// Get text. The admin's wording wins, and the email subject is the fallback.
		$text = $this->render_text( $this->get_type_text( $type ), $tokens );

		if ( ! $text ) {
			$text = trim( wp_strip_all_tags( (string) $email->get_subject() ) );
		}

		// Add notification.
		$this->add_notification(
			[
				'user'  => $user_id,
				'type'  => $type,
				'text'  => $text,
				'url'   => $this->get_url( $tokens, $type ),
				'image' => $this->get_image( $tokens ),
			]
		);
	}

	/**
	 * Picks an image for a notification from its tokens.
	 *
	 * A person's avatar beats a listing photo, because most notifications are about something a
	 * person did. The avatar always resolves, since WordPress falls back to a generated one.
	 *
	 * @param array $tokens Token values.
	 * @return string
	 */
	protected function get_image( $tokens ) {

		// Prefer the person. Model getters are magic methods, so this checks the class itself
		// rather than asking for a method that method_exists() can never see.
		foreach ( [ 'sender', 'author', 'user' ] as $name ) {
			$value = hp\get_array_value( $tokens, $name );

			if ( $value instanceof \HivePress\Models\User ) {
				return $this->get_user_image( $value );
			}
		}

		// Fall back to the listing photo.
		$listing = hp\get_array_value( $tokens, 'listing' );

		if ( is_object( $listing ) && hp\is_namespace_instance( $listing, 'models' ) ) {
			return (string) get_the_post_thumbnail_url( $listing->get_id(), 'thumbnail' );
		}

		return '';
	}

	/**
	 * Adds a notification.
	 *
	 * @param array $args Notification arguments.
	 * @return mixed
	 */
	public function add_notification( $args ) {
		$args = array_merge(
			[
				'user'         => 0,
				'type'         => '',
				'text'         => '',
				'url'          => '',
				'image'        => '',

				// Optional per-notification visuals, overriding the type's generic icon.
				'icon'         => '',
				'color'        => '',

				// A quiet notification only lands in the list: no pop-up, no push, no statistics.
				// Used when seeding notifications for things that happened before the plugin was
				// installed, which shouldn't arrive like breaking news.
				'quiet'        => false,

				// Backdatable so a seeded notification keeps the date of the event it describes.
				'created_date' => '',
			],
			$args
		);

		if ( ! $args['user'] || ! $args['type'] || ! $args['text'] ) {
			return;
		}

		// Add notification.
		$notification = ( new Models\Hpnf_Notification() )->fill(
			[
				'text'         => $this->truncate( $args['text'], 256 ),
				'user'         => $args['user'],
				'type'         => $args['type'],
				'read'         => 0,
				'created_date' => $args['created_date'] ? $args['created_date'] : current_time( 'mysql' ),
				'url'          => $args['url'],
				'image'        => $args['image'],
				'icon'         => sanitize_html_class( (string) $args['icon'] ),
				'color'        => sanitize_hex_color( (string) $args['color'] ),
			]
		);

		if ( ! $notification->save() ) {
			return;
		}

		// Update counter.
		$this->update_unread_count( $args['user'] );

		// Update type list.
		$this->add_used_type( $args['user'], $args['type'] );

		if ( $args['quiet'] ) {
			return $notification;
		}

		// Update stats.
		$this->add_stat( $args['type'], 'sent' );

		// Add to queue.
		$this->add_to_queue( $args['user'], $notification );

		/**
		 * Fires when an on-site notification has been added.
		 *
		 * @hook hivepress/v1/notification_add
		 * @param {object} $notification Notification object.
		 */
		do_action( 'hivepress/v1/notification_add', $notification );

		return $notification;
	}

	/**
	 * Appends the review deep link to a listing URL.
	 *
	 * The fragment is client-side only, so it can't fragment a page cache, and the frontend
	 * script opens the Write a Review window when it sees it. Someone who can't review just
	 * lands on the listing.
	 *
	 * @param string $url Listing URL.
	 * @return string
	 */
	protected function get_review_url( $url ) {
		if ( ! $url || ! class_exists( '\HivePress\Models\Review' ) ) {
			return $url;
		}

		return $url . '#hp-review';
	}

	/**
	 * Gets the image of a user.
	 *
	 * The profile image people upload in HivePress lives on the user model's attachment, which
	 * get_avatar_url() knows nothing about, so that one comes first and Gravatar is the fallback.
	 *
	 * @param object $user User object.
	 * @return string
	 */
	public function get_user_image( $user ) {
		$image = $user->get_image__url( 'thumbnail' );

		if ( $image ) {
			return (string) $image;
		}

		return (string) get_avatar_url( $user->get_id(), [ 'size' => 96 ] );
	}

	/**
	 * Adds a notification when a listing is favourited.
	 *
	 * @param int    $favorite_id Favorite ID.
	 * @param object $favorite Favorite object.
	 */
	public function add_favorite_notification( $favorite_id, $favorite ) {
		$this->add_extra_notification( 'listing_favorite', $favorite, 'user' );
	}

	/**
	 * Adds a notification when a review is left.
	 *
	 * With review moderation on, HivePress creates the review unapproved, so there's nothing to
	 * tell the owner about until it's approved.
	 *
	 * @param int    $review_id Review ID.
	 * @param object $review Review object.
	 */
	public function add_review_notification( $review_id, $review ) {
		if ( ! is_object( $review ) || ! $review->is_approved() ) {
			return;
		}

		$this->add_extra_notification( 'listing_review', $review, 'author' );
	}

	/**
	 * Adds a notification when a review is approved.
	 *
	 * @param int    $review_id Review ID.
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param object $review Review object.
	 */
	public function update_review_notification( $review_id, $new_status, $old_status, $review ) {
		if ( 'approve' !== $new_status ) {
			return;
		}

		$this->add_extra_notification( 'listing_review', $review, 'author' );
	}

	/**
	 * Adds a notification when a booking finishes.
	 *
	 * The Bookings extension schedules this for twelve hours after the booking ends, and has no
	 * email for it, so the client hears nothing today. It goes to the client, and links to the
	 * listing, which is where a review is left.
	 *
	 * @param int $booking_id Booking ID.
	 */
	public function add_booking_notification( $booking_id ) {
		if ( ! in_array( 'booking_complete', $this->get_enabled_types(), true ) || ! class_exists( '\HivePress\Models\Booking' ) ) {
			return;
		}

		// Get booking.
		$booking = \HivePress\Models\Booking::query()->get_by_id( absint( $booking_id ) );

		// Only a confirmed booking took place. The completion event is scheduled at booking time,
		// so it can still fire for one that was canceled or never paid for in the meantime.
		if ( ! $booking || 'publish' !== $booking->get_status() ) {
			return;
		}

		// Get user.
		$user_id = $booking->get_user__id();

		if ( ! $user_id ) {
			return;
		}

		// Get listing.
		$listing = $booking->get_listing();

		if ( ! $listing ) {
			return;
		}

		// Check the channels.
		if ( ! in_array( 'onsite', $this->get_user_channels( $user_id, 'booking_complete' ), true ) ) {
			return;
		}

		/*
		 * Only a listing the public can still open gets a link. This event fires twelve hours
		 * after the booking ends, which is long enough for the listing to have expired overnight -
		 * core moves expired listings to draft - or been trashed by the vendor, and get_permalink()
		 * happily builds a "?post_type=hp_listing&p=N" or "__trashed" URL for those. Both pass the
		 * URL check, so "Leave a review" shipped pointing at a 404. No link rather than a broken
		 * one, the same trade get_badge_url() makes; the wording still names the listing either way.
		 */
		$listing_url = 'publish' === $listing->get_status() ? (string) get_permalink( $listing->get_id() ) : '';

		// Get tokens.
		$tokens = [
			'user'          => $booking->get_user(),
			'listing'       => $listing,
			'booking'       => $booking,
			'listing_title' => $listing->get_title(),
			'listing_url'   => $listing_url,
		];

		$tokens = $this->filter_tokens( $tokens );

		$this->update_seen_tokens( 'booking_complete', $tokens );

		// Add notification. The listing photo beats the client's own avatar here, because the
		// notification goes to the client.
		$this->add_notification(
			[
				'user'  => $user_id,
				'type'  => 'booking_complete',
				'text'  => $this->render_text( $this->get_type_text( 'booking_complete' ), $tokens ),
				'url'   => $this->get_review_url( $this->get_url( $tokens, 'booking_complete' ) ),
				'image' => (string) get_the_post_thumbnail_url( $listing->get_id(), 'thumbnail' ),
			]
		);
	}

	/**
	 * Adds a notification when a badge is awarded.
	 *
	 * The Badges extension writes an award whenever a threshold is passed, and removes lower awards
	 * as someone climbs, so only creation is worth telling anyone about. Awards are also re-checked
	 * hourly and can be removed and re-added if a metric dips and recovers, which is why the award
	 * carries a marker: the same badge is announced once, not every time it is recalculated.
	 *
	 * @param int    $award_id Award ID.
	 * @param object $award Award object.
	 */
	public function add_award_notification( $award_id, $award ) {
		if ( ! is_object( $award ) || ! in_array( 'badge_award', $this->get_enabled_types(), true ) ) {
			return;
		}

		// Get user.
		$user_id = $award->get_user__id();

		if ( ! $user_id ) {
			return;
		}

		// Get badge.
		$badge = $award->get_badge();

		if ( ! $badge ) {
			return;
		}

		// Only announce a given badge once per person, however often the award is recalculated.
		$sent = (array) get_user_meta( $user_id, 'hp_notification_badges_sent', true );

		if ( in_array( (int) $badge->get_id(), array_map( 'intval', $sent ), true ) ) {
			return;
		}

		// Check the channels.
		if ( ! in_array( 'onsite', $this->get_user_channels( $user_id, 'badge_award' ), true ) ) {
			return;
		}

		// Get tokens.
		$tokens = $this->filter_tokens(
			[
				'user'       => $award->get_user(),
				'badge'      => $badge,
				'badge_name' => $badge->get_name(),
			]
		);

		$this->update_seen_tokens( 'badge_award', $tokens );

		$text = $this->render_text( $this->get_type_text( 'badge_award' ), $tokens );

		if ( ! $text ) {
			/* translators: %s: badge name. */
			$text = sprintf( esc_html__( 'You have earned the %s badge.', 'notifications-for-hivepress' ), $badge->get_name() );
		}

		// Add notification. Badges are shown on a vendor's public profile, so that is where the
		// link goes when there is one; a user without a vendor profile has nowhere to look yet.
		//
		// The visual is the badge itself rather than a stand-in: its own image where one is set,
		// otherwise its own icon on its own colour, exactly as the Badges block renders it
		// (badges/includes/blocks/class-badges.php:98-112, which falls back to "award" when no
		// icon is chosen).
		$notification = $this->add_notification(
			[
				'user'  => $user_id,
				'type'  => 'badge_award',
				'text'  => $text,
				'url'   => $this->get_badge_url( $user_id ),
				'image' => (string) get_the_post_thumbnail_url( $badge->get_id(), 'thumbnail' ),
				'icon'  => $badge->get_icon() ? $badge->get_icon() : 'award',
				'color' => (string) $badge->get_color(),
			]
		);

		if ( $notification ) {
			$sent[] = (int) $badge->get_id();

			update_user_meta( $user_id, 'hp_notification_badges_sent', array_values( array_unique( array_map( 'intval', $sent ) ) ) );
		}
	}

	/**
	 * Gets the page where someone can see their badges.
	 *
	 * Badges render on the vendor and user profile blocks, so the public vendor page is the useful
	 * destination. Sites without the Vendors route resolved, or users who have never published a
	 * vendor profile, get no link rather than a broken one.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	protected function get_badge_url( $user_id ) {
		if ( ! class_exists( '\HivePress\Models\Vendor' ) || ! hivepress()->router->get_route( 'vendor_view_page' ) ) {
			return '';
		}

		// Get vendor.
		$vendor_id = \HivePress\Models\Vendor::query()->filter(
			[
				'status' => 'publish',
				'user'   => $user_id,
			]
		)->get_first_id();

		if ( ! $vendor_id ) {
			return '';
		}

		return (string) hivepress()->router->get_url( 'vendor_view_page', [ 'vendor_id' => $vendor_id ] );
	}

	/**
	 * Adds a notification for something that happened to a listing.
	 *
	 * The notification goes to the listing owner, and never to the person who caused it, so a
	 * stylist favouriting their own listing doesn't notify themselves.
	 *
	 * @param string $type Notification type.
	 * @param object $object Model object.
	 * @param string $actor_field Field holding the user who caused it.
	 */
	protected function add_extra_notification( $type, $object, $actor_field ) {
		if ( ! is_object( $object ) || ! in_array( $type, $this->get_enabled_types(), true ) ) {
			return;
		}

		// Only notify once per object. A moderated review fires on creation and again on approval,
		// and the owner should hear about it once. Both extra types are comment models.
		if ( get_comment_meta( $object->get_id(), 'hp_notification_sent', true ) ) {
			return;
		}

		// Get listing.
		$listing = $object->get_listing();

		if ( ! $listing ) {
			return;
		}

		// Get the listing owner.
		$user_id = $listing->get_user__id();

		if ( ! $user_id || $user_id === $object->{ 'get_' . $actor_field . '__id' }() ) {
			return;
		}

		// Check the owner's channels.
		if ( ! in_array( 'onsite', $this->get_user_channels( $user_id, $type ), true ) ) {
			return;
		}

		// Get actor.
		$actor = $object->{ 'get_' . $actor_field }();

		if ( ! $actor ) {
			return;
		}

		// Only a listing the public can still open gets a link: a moderated review can be approved
		// after its listing has expired to draft or been trashed, and get_permalink() then builds a
		// URL that passes the URL check and 404s. No link rather than a broken one.
		$listing_url = 'publish' === $listing->get_status() ? (string) get_permalink( $listing->get_id() ) : '';

		/*
		 * A review notification links to the review itself, not the top of the listing page. Reviews
		 * sit in a section well down that page, so following "Review Received" used to land someone
		 * on the photos with no sign of the thing they had just been told about.
		 *
		 * The anchor is the Reviews extension's own, not one invented here: it renders each review
		 * with id="review-{id}" and builds its %review_url% the same way
		 * (reference/extensions/hivepress-reviews/includes/components/class-review.php:394), which
		 * is why the three review emails HivePress does send already arrive in the right place.
		 * "#reviews" is the section fallback and is what the extension's own rating link uses
		 * (templates/listing/view/listing-rating.php:9).
		 *
		 * It goes in as its own token rather than by rewriting listing_url, for two reasons:
		 * get_url() takes the first token ending in "url", so ordering it first makes it the link
		 * without disturbing anything else, and %listing_url% stays a plain listing link for anyone
		 * writing their own wording. The name matches the token HivePress uses for the same thing.
		 */
		$review_url = '';

		if ( 'listing_review' === $type && $listing_url ) {
			$review_url = $listing_url . ( $object->get_id() ? '#review-' . $object->get_id() : '#reviews' );
		}

		// Get tokens.
		$tokens = [
			$actor_field    => $actor,
			'review_url'    => $review_url,
			'listing'       => $listing,
			'listing_title' => $listing->get_title(),
			'listing_url'   => $listing_url,
			'review'        => 'listing_review' === $type ? $object : null,
		];

		$tokens = $this->filter_tokens( $tokens );

		$this->update_seen_tokens( $type, $tokens );

		// Add notification.
		$notification = $this->add_notification(
			[
				'user'  => $user_id,
				'type'  => $type,
				'text'  => $this->render_text( $this->get_type_text( $type ), $tokens ),
				'url'   => $this->get_url( $tokens, $type ),
				'image' => $this->get_user_image( $actor ),
			]
		);

		if ( $notification ) {
			add_comment_meta( $object->get_id(), 'hp_notification_sent', 1, true );
		}
	}

	/**
	 * Gets the user ID an email is addressed to.
	 *
	 * The recipient has to come from the address the email is sent to. The "user" token can't be
	 * used because HivePress sets it to the user who triggered the email, which is not the
	 * recipient for the emails that go to the site administrator.
	 *
	 * @param object $email Email object.
	 * @return int
	 */
	protected function get_recipient_id( $email ) {

		// Get recipient.
		$recipient = $this->get_recipient( $email );

		if ( ! $recipient ) {
			return 0;
		}

		// Get user.
		$user = get_user_by( 'email', $recipient );

		return $user ? $user->ID : 0;
	}

	/**
	 * Reads the recipient address of an email.
	 *
	 * HivePress keeps the recipient in a protected property and doesn't expose a getter for it, so
	 * the property is read within the scope of the abstract email class it's declared on.
	 *
	 * @param object $email Email object.
	 * @return string
	 */
	protected function get_recipient( $email ) {
		if ( ! property_exists( $email, 'recipient' ) ) {
			return '';
		}

		// Read recipient.
		$reader = \Closure::bind(
			function() {
				return $this->recipient;
			},
			$email,
			'\HivePress\Emails\Email'
		);

		if ( ! $reader ) {
			return '';
		}

		$recipient = $reader();

		if ( is_array( $recipient ) ) {
			$recipient = hp\get_first_array_value( $recipient );
		}

		return $this->parse_address( $recipient );
	}

	/**
	 * Gets the address out of a recipient value.
	 *
	 * Handles the "Name <address>" form that wp_mail accepts, so that an address compares equal
	 * whichever form it arrives in.
	 *
	 * @param mixed $value Recipient value.
	 * @return string
	 */
	protected function parse_address( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		if ( preg_match( '/<([^>]+)>/', $value, $matches ) ) {
			$value = $matches[1];
		}

		return trim( $value );
	}

	/**
	 * Stops an email that the recipient turned off for this notification type.
	 *
	 * HivePress calls wp_mail() straight after the send hook, so the email flagged there is
	 * matched on both address and subject rather than stopping whatever comes next.
	 *
	 * @param mixed $result Short-circuit value.
	 * @param array $atts Mail arguments.
	 * @return mixed
	 */
	public function suppress_email( $result, $atts ) {
		if ( ! $this->suppressed ) {
			return $result;
		}

		// Get recipients.
		$recipients = hp\get_array_value( $atts, 'to', [] );

		if ( ! is_array( $recipients ) ) {
			$recipients = explode( ',', (string) $recipients );
		}

		$recipients = array_map( [ $this, 'parse_address' ], $recipients );

		// Check the email.
		if ( ! in_array( $this->suppressed['recipient'], $recipients, true ) || (string) hp\get_array_value( $atts, 'subject' ) !== $this->suppressed['subject'] ) {
			return $result;
		}

		$this->suppressed = null;

		// Returning a value other than null stops wp_mail() from sending. True is returned because
		// nothing failed, the email was turned off on purpose.
		return true;
	}

	/**
	 * Gets the URL a notification should link to.
	 *
	 * @param array  $tokens Token values.
	 * @param string $type Notification type.
	 * @return string
	 */
	protected function get_url( $tokens, $type ) {
		$url = '';

		// HivePress names its link tokens after the model they point at, and those links are
		// already deep links. The message link, for example, carries the anchor of the message
		// itself, so following the notification opens the conversation at the right place.
		foreach ( (array) $tokens as $name => $value ) {
			if ( is_string( $value ) && 'url' === substr( $name, -3 ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
				$url = $value;

				break;
			}
		}

		/**
		 * Filters the URL a notification links to. Use this to pin an exact link for a type when the
		 * link token isn't the one you want.
		 *
		 * @hook hivepress/v1/notification_url
		 * @param {string} $url Notification URL.
		 * @param {array} $tokens Token values.
		 * @param {string} $type Notification type.
		 * @return {string} Notification URL.
		 */
		return (string) apply_filters( 'hivepress/v1/notification_url', $url, $tokens, $type );
	}

	/**
	 * Truncates text to a maximum length.
	 *
	 * @param string $text Text to truncate.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	protected function truncate( $text, $length ) {
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}

		return rtrim( mb_substr( $text, 0, $length - 1 ) ) . '…';
	}

	/**
	 * Adds a notification to the pop-up queue.
	 *
	 * The queue is kept in user meta so that the request the browser makes on page load reads a
	 * single cached meta value instead of querying the comments table.
	 *
	 * @param int    $user_id User ID.
	 * @param object $notification Notification object.
	 */
	protected function add_to_queue( $user_id, $notification ) {
		if ( ! get_option( 'hp_notification_toasts', true ) ) {
			return;
		}

		// A pop-up during someone's quiet hours is exactly the interruption they asked not to have.
		// The notification is still in the list, so nothing is lost.
		if ( $this->is_quiet( $user_id ) ) {
			return;
		}

		// Get queue.
		$queue = $this->get_queue( $user_id );

		// The stored date is on the site's clock, so date_i18n() formats it as it stands; wp_date()
		// would add the offset a second time. The machine-readable value wants real UTC, which
		// get_gmt_from_date() derives with the site's timezone rules. Both match what the list
		// template prints, because the script uses them to build a row identical to its own.
		$created = (string) $notification->get_created_date();
		$time    = strtotime( $created );

		// Add notification.
		$queue[] = [
			'id'         => $notification->get_id(),
			'text'       => $notification->get_text(),

			// The pop-up's heading: the admin's saved title, or the public label. Never the raw
			// admin label, which can carry a "(User)"/"(Vendor)" bracket.
			'type'       => $this->get_type_title( $notification->get_type() ),
			'icon'       => $this->get_notification_icon( $notification ),
			'color'      => (string) $notification->get_color(),
			'image'      => (string) $notification->get_image(),
			'url'        => (string) $notification->get_url(),
			'link_label' => $this->get_type_link_text( $notification->get_type() ),
			'time'       => $time ? date_i18n( (string) get_option( 'time_format' ), $time ) : '',
			'datetime'   => $time ? get_gmt_from_date( $created, 'c' ) : '',
		];

		// Set queue.
		update_user_meta( $user_id, 'hp_notification_queue', array_slice( $queue, -20 ) );
	}

	/**
	 * Gets the pop-up queue of a user.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function get_queue( $user_id ) {
		$queue = get_user_meta( $user_id, 'hp_notification_queue', true );

		return is_array( $queue ) ? $queue : [];
	}

	/**
	 * Clears the pop-up queue of a user.
	 *
	 * @param int $user_id User ID.
	 */
	public function clear_queue( $user_id ) {
		delete_user_meta( $user_id, 'hp_notification_queue' );
	}

	/**
	 * Gets the types a user has actually received, as a name => label array.
	 *
	 * Offering every possible type would show filters that return nothing, so the types a user has
	 * received are tracked in user meta as notifications arrive. That keeps this a single cached
	 * meta read instead of a query across the comment meta table on every page view.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function get_used_types( $user_id ) {
		$types = get_user_meta( $user_id, 'hp_notification_type_list', true );

		if ( ! is_array( $types ) ) {
			$types = $this->rebuild_used_types( $user_id );
		}

		// Get labels. These feed the filter dropdown on the notifications page, which is a member
		// surface, so they use the same title the rest of the front end shows.
		$labels = [];

		foreach ( $types as $type ) {
			$labels[ $type ] = $this->get_type_title( $type );
		}

		asort( $labels );

		return $labels;
	}

	/**
	 * Adds a type to the list of types a user has received.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type Notification type.
	 */
	protected function add_used_type( $user_id, $type ) {
		$types = get_user_meta( $user_id, 'hp_notification_type_list', true );

		if ( ! is_array( $types ) ) {
			$types = $this->rebuild_used_types( $user_id );
		}

		if ( in_array( $type, $types, true ) ) {
			return;
		}

		$types[] = $type;

		update_user_meta( $user_id, 'hp_notification_type_list', array_values( $types ) );
	}

	/**
	 * Rebuilds the list of types a user has received.
	 *
	 * Only runs when notifications are deleted or when the list is missing, so the cost of the
	 * query is paid rarely rather than on every page view.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function rebuild_used_types( $user_id ) {
		global $wpdb;

		// Get types. The result is the cache: it's written to user meta below and read from there
		// everywhere else, and this only runs when notifications are deleted or from WP-CLI.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$types = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta.meta_value
				FROM {$wpdb->commentmeta} AS meta
				INNER JOIN {$wpdb->comments} AS comments ON comments.comment_ID = meta.comment_id
				WHERE meta.meta_key = %s AND comments.comment_type = %s AND comments.user_id = %d",
				'hp_type',
				'hp_notification',
				$user_id
			)
		);

		$types = array_values( array_filter( (array) $types ) );

		update_user_meta( $user_id, 'hp_notification_type_list', $types );

		return $types;
	}

	/**
	 * Gets the number of unread notifications.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function get_unread_count( $user_id ) {
		$count = get_user_meta( $user_id, 'hp_notification_unread', true );

		if ( '' === $count ) {
			$count = $this->update_unread_count( $user_id );
		}

		return absint( $count );
	}

	/**
	 * Recounts the unread notifications of a user and caches the result.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function update_unread_count( $user_id ) {
		$count = Models\Hpnf_Notification::query()->filter(
			[
				'user' => $user_id,
				'read' => 0,
			]
		)->get_count();

		update_user_meta( $user_id, 'hp_notification_unread', $count );

		return $count;
	}

	/**
	 * Gets the delivery channels, as a name => label array.
	 *
	 * A future SMS channel is added here and works everywhere else without further changes.
	 *
	 * @return array
	 */
	public function get_channels() {

		/**
		 * Filters the notification delivery channels. Keys are channel names, values are the labels
		 * shown to users.
		 *
		 * @hook hivepress/v1/notification_channels
		 * @param {array} $channels Notification channels.
		 * @return {array} Notification channels.
		 */

		/*
		 * "On-site" rather than "Pop-up", which is what this said in development and was wrong in a
		 * way that mattered. Turning this off does not just stop the pop-up: the notification is
		 * never created at all (see the onsite checks in add_notification and its callers), so the
		 * person loses the entry in their list and the bell as well. Somebody unticking "Pop-up" to
		 * stop things appearing over the page would have silently lost their notification history.
		 * It also confused two rounds of staging testing, where "Pop-up only" read as "toasts only".
		 */
		$channels = [
			'onsite' => esc_html__( 'On-site', 'notifications-for-hivepress' ),
			'email'  => esc_html__( 'Email', 'notifications-for-hivepress' ),
		];

		// Push is only offered once it's switched on and has keys, so nobody can choose a channel
		// that has nowhere to go.
		if ( hivepress()->hpnf_notification_push && hivepress()->hpnf_notification_push->is_enabled() ) {
			$channels['push'] = esc_html__( 'Push', 'notifications-for-hivepress' );
		}

		return apply_filters( 'hivepress/v1/notification_channels', $channels );
	}

	/**
	 * Gets the channels members must opt into themselves.
	 *
	 * An opt-in channel is never granted by a role default: it starts off for
	 * everyone, in every stored state, until a member ticks it on their own
	 * Notification Settings page. Built for paid channels such as SMS, where
	 * "on by default" would mean unsolicited texts on the site owner's bill.
	 *
	 * @return array
	 */
	public function get_optin_channels() {

		/**
		 * Filters the channels members must opt into themselves. Add a channel
		 * name here as well as to the channels filter to make it strictly opt-in.
		 *
		 * @hook hivepress/v1/notification_optin_channels
		 * @param {array} $channels Channel names.
		 * @return {array} Channel names.
		 */
		return array_values( array_intersect( array_keys( $this->get_channels() ), array_unique( (array) apply_filters( 'hivepress/v1/notification_optin_channels', [] ) ) ) );
	}

	/**
	 * Gets the icon of a single notification.
	 *
	 * A notification may carry its own icon, which is how a badge award shows the badge that was
	 * actually earned. Everything else falls back to the generic icon for its type.
	 *
	 * @param object $notification Notification object.
	 * @return string
	 */
	public function get_notification_icon( $notification ) {
		$icon = sanitize_html_class( (string) $notification->get_icon() );

		return $icon ? $icon : $this->get_type_icon( $notification->get_type() );
	}

	/**
	 * Gets the icon of a notification type.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public function get_type_icon( $type ) {
		$icon = hp\get_array_value( $this->get_type_args( $type ), 'icon' );

		if ( $icon ) {
			return $icon;
		}

		// Guess from the name. HivePress names its emails after the model they concern, so the
		// first word is a reliable clue. Every icon here is in the Font Awesome solid set that
		// HivePress bundles.
		$icons = [
			'booking'    => 'calendar-check',
			'order'      => 'shopping-cart',
			'payout'     => 'wallet',
			'listing'    => 'tag',
			'message'    => 'envelope',
			'review'     => 'star',
			'membership' => 'id-card',
			'request'    => 'bullhorn',
			'offer'      => 'handshake',
			'vendor'     => 'store',
			'user'       => 'user',
		];

		foreach ( $icons as $prefix => $name ) {
			if ( 0 === strpos( $type, $prefix ) ) {
				return $name;
			}
		}

		return 'bell';
	}

	/**
	 * Gets the Font Awesome name of the header bell icon.
	 *
	 * Admins enter a free solid icon name such as "inbox" or "bell". A pasted "fa-" or "fas fa-"
	 * prefix is tolerated, and anything left after sanitising that isn't a usable class name falls
	 * back to the default bell.
	 *
	 * @return string
	 */
	public function get_bell_icon() {
		$icon = strtolower( trim( (string) get_option( 'hp_notification_bell_icon', 'bell' ) ) );

		// Drop a leading style prefix like "fas fa-" or "fa-" so a pasted full class still works.
		$icon = (string) preg_replace( '/^(fa[a-z]{0,2}\s+)?fa-/', '', $icon );

		$icon = sanitize_html_class( $icon );

		return $icon ? $icon : 'bell';
	}

	/**
	 * Gets the newer Font Awesome solid icons this plugin adds to the picker.
	 *
	 * HivePress's own icons config is the Font Awesome 5 solid set, and core only enqueues the
	 * FA5 solid stylesheet, so these render blank unless the pinned FA7 stylesheet is loaded -
	 * which is why get_bell_icon_class() and the enqueues below treat these names specially.
	 * Every name here is a canonical free solid icon present in Font Awesome 6 and 7; nothing is
	 * guessed, because an unknown name renders as a blank square in front of real users.
	 *
	 * @return array
	 */
	protected function get_extra_solid_icons() {
		return [
			'arrow-trend-down',
			'arrow-trend-up',
			'bars-progress',
			'basket-shopping',
			'bell-concierge',
			'bolt-lightning',
			'building-columns',
			'burger',
			'cake-candles',
			'calendar-days',
			'cart-shopping',
			'champagne-glasses',
			'chart-column',
			'chart-simple',
			'circle-check',
			'circle-dollar-to-slot',
			'circle-exclamation',
			'circle-info',
			'circle-question',
			'circle-user',
			'circle-xmark',
			'clock-rotate-left',
			'comment-sms',
			'diagram-project',
			'earth-americas',
			'earth-europe',
			'envelope-circle-check',
			'envelopes-bulk',
			'face-grin-stars',
			'face-laugh',
			'face-smile',
			'fire-flame-curved',
			'gauge',
			'gauge-high',
			'gear',
			'gears',
			'hand-holding-dollar',
			'heart-pulse',
			'house',
			'house-chimney',
			'list-check',
			'location-dot',
			'magnifying-glass',
			'martini-glass',
			'message',
			'mobile-screen',
			'mobile-screen-button',
			'money-bill-trend-up',
			'mug-saucer',
			'pen-to-square',
			'people-group',
			'person',
			'person-biking',
			'person-walking',
			'phone-flip',
			'plane-up',
			'right-from-bracket',
			'right-to-bracket',
			'rotate',
			'rotate-left',
			'rotate-right',
			'scale-balanced',
			'screwdriver-wrench',
			'shield-halved',
			'shop',
			'sliders',
			'square-check',
			'star-half-stroke',
			'table-list',
			'ticket-simple',
			'tower-broadcast',
			'trash-can',
			'truck-fast',
			'user-gear',
			'user-pen',
			'users-gear',
			'van-shuttle',
			'wand-magic-sparkles',
			'xmark',
		];
	}

	/**
	 * Gets the Font Awesome brand icons this plugin adds to the picker.
	 *
	 * Brands live in their own font family, so these need the "fa-brands" class rather than
	 * "fas" - which is why the name list has to be known here, per icon, and not inferred.
	 * None of these names collides with a solid icon name.
	 *
	 * @return array
	 */
	protected function get_brand_icons() {
		return [
			'airbnb',
			'amazon',
			'android',
			'app-store',
			'app-store-ios',
			'apple',
			'apple-pay',
			'behance',
			'bitcoin',
			'bluesky',
			'btc',
			'cc-amex',
			'cc-apple-pay',
			'cc-mastercard',
			'cc-paypal',
			'cc-stripe',
			'cc-visa',
			'dev',
			'discord',
			'dribbble',
			'dropbox',
			'drupal',
			'ebay',
			'ethereum',
			'facebook',
			'facebook-f',
			'facebook-messenger',
			'figma',
			'github',
			'gitlab',
			'google',
			'google-drive',
			'google-pay',
			'google-play',
			'hubspot',
			'instagram',
			'joomla',
			'kickstarter',
			'line',
			'linkedin',
			'linkedin-in',
			'mailchimp',
			'medium',
			'microsoft',
			'mixcloud',
			'odnoklassniki',
			'patreon',
			'paypal',
			'pinterest',
			'pinterest-p',
			'product-hunt',
			'reddit',
			'salesforce',
			'shopify',
			'skype',
			'slack',
			'snapchat',
			'soundcloud',
			'spotify',
			'squarespace',
			'stack-overflow',
			'stripe',
			'stripe-s',
			'telegram',
			'threads',
			'tiktok',
			'tripadvisor',
			'tumblr',
			'twitch',
			'twitter',
			'uber',
			'viber',
			'vimeo',
			'vk',
			'waze',
			'weixin',
			'whatsapp',
			'windows',
			'wix',
			'wordpress',
			'wordpress-simple',
			'x-twitter',
			'xing',
			'yelp',
			'youtube',
		];
	}

	/**
	 * Adds the newer Font Awesome and brand icons to the icons config.
	 *
	 * Keys match values, the shape of core's own config, and the merged list is re-sorted so the
	 * additions interleave alphabetically rather than trailing at the end.
	 *
	 * SCOPED ON PURPOSE - do not remove the gate. `hivepress/v1/icons` filters the SHARED icons
	 * config (reference/hivepress/includes/class-core.php:412-433), which feeds every
	 * "options => icons" picker on the site: this plugin's bell, core's listing-attribute icons
	 * and the listing-category Icon field that ExpertHive, JobHive and MeetingHive add
	 * (themes/experthive/includes/components/class-theme.php:253-280). This plugin only enqueues
	 * its bundled Font Awesome 7 on its own settings tab and, on the front end, when the BELL
	 * itself uses an extended name - so anywhere else the names were offered they could not be
	 * drawn.
	 *
	 * Measured on hivepress-dev 2026-08-30, ExpertHive, front page, logged out, with the sibling
	 * plugins that also bundle Font Awesome switched off: a category set to `house-chimney`
	 * rendered `<i class="hp-icon fas fa-house-chimney">` whose ::before computed
	 * `content: none` - Font Awesome 5 has no such rule, so the element was EMPTY. A category set
	 * to the brand `stripe` did have a rule (the FA5 CSS carries the brand codepoints) but drew
	 * into "Font Awesome 5 Free" weight 900, which holds no glyph there: an inked-pixel count of
	 * the rendered character came back at 0 px, against 1,038 px for the FA5 control `home`. Both
	 * cards showed an empty tile on the front page. The bug hid on this site for a day because
	 * Action Bar enqueues the SAME shared `fafh-fontawesome` handle on the front end, so
	 * whichever sibling happens to be active decided whether another plugin's icons appeared.
	 *
	 * The theme hard-codes `fas fa-{name}` in its category template, so a brand name could never
	 * render there even with the stylesheet loaded - it would need the `fa-brands` family too.
	 * That is why the fix is to stop offering the names rather than to load the font site-wide:
	 * offering a choice that cannot be drawn is the defect, and 307 KB of fonts on every page of
	 * every site would not have fixed the brand half of it anyway.
	 *
	 * @param array $icons Icons config.
	 * @return array
	 */
	public function add_icons( $icons ) {
		if ( ! $this->is_own_settings_screen() ) {
			return $icons;
		}

		foreach ( array_merge( $this->get_extra_solid_icons(), $this->get_brand_icons() ) as $name ) {
			if ( ! isset( $icons[ $name ] ) ) {
				$icons[ $name ] = $name;
			}
		}

		ksort( $icons );

		return $icons;
	}

	/**
	 * Whether this request is building this plugin's own HivePress settings tab.
	 *
	 * This is the twin of is_settings_tab() below and exists because that one cannot be used here.
	 * is_settings_tab() reads $GLOBALS['wp_settings_fields'], which core fills during
	 * register_settings() - and register_settings() is the very thing that builds the fields and
	 * therefore asks for the icons config, so at the moment this filter runs that global is still
	 * empty. Asking it here would always answer false and the bell picker would lose its own
	 * icons.
	 *
	 * So the tab is resolved the way core resolves it (class-admin.php:277 and :606-621),
	 * including two details that are easy to miss:
	 *
	 * - `options.php` must be allowed through, or SAVING the tab would validate the chosen bell
	 *   icon against a list that no longer contains it and silently reset it.
	 * - the tab falls back to the FIRST tab when "tab" is absent, which is what the bare
	 *   admin.php?page=hp_settings link in the HivePress menu is. Core's own settings form posts
	 *   to options.php?tab={tab}, so the parameter is present on save.
	 *
	 * @return bool
	 */
	protected function is_own_settings_screen() {
		global $pagenow;

		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the address to identify the admin screen being rendered, not processing submitted data.
		$hp_page = (string) hp\get_array_value( $_GET, 'page' );

		if ( 'options.php' !== $pagenow && ! ( 'admin.php' === $pagenow && 'hp_settings' === $hp_page ) ) {
			return false;
		}

		$hp_tabs = array_keys( hp\sort_array( hivepress()->get_config( 'settings' ) ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the address to identify the admin screen being rendered, not processing submitted data.
		$hp_tab = (string) hp\get_array_value( $_GET, 'tab' );

		if ( ! in_array( $hp_tab, $hp_tabs, true ) ) {
			$hp_tab = (string) hp\get_first_array_value( $hp_tabs );
		}

		return 'notifications' === $hp_tab;
	}

	/**
	 * Whether an icon name is one of the brand icons.
	 *
	 * @param string $icon Icon name.
	 * @return bool
	 */
	public function is_brand_icon( $icon ) {
		return in_array( $icon, $this->get_brand_icons(), true );
	}

	/**
	 * Whether an icon name needs the Font Awesome stylesheet this plugin loads.
	 *
	 * True for the added solid names and every brand: core only enqueues Font Awesome 5 solid,
	 * so both would otherwise render as a blank space.
	 *
	 * @param string $icon Icon name.
	 * @return bool
	 */
	public function is_extended_icon( $icon ) {
		return $this->is_brand_icon( $icon ) || in_array( $icon, $this->get_extra_solid_icons(), true );
	}

	/**
	 * Gets the full Font Awesome class for the header bell icon.
	 *
	 * Three cases, because two stylesheets are in play: a brand icon needs "fa-brands" and the
	 * pinned FA7 stylesheet, an added solid icon needs "fa-solid" and that same stylesheet, and
	 * everything else keeps the "fas" class drawn by the FA5 solid set HivePress already enqueues.
	 *
	 * @return string
	 */
	/**
	 * Icons the front-end script draws on its own, rather than from a payload.
	 *
	 * Chrome: chevrons, the close and tick controls, the read/unread flip, and
	 * the fallback bell. Localised once per page so the script never has to ask
	 * for one. Keep in step with assets/js/frontend.js -- a name used there but
	 * missing here draws nothing at all.
	 *
	 * @var array
	 */
	const SCRIPT_ICONS = [
		'bell',
		'check',
		'check-double',
		'chevron-down',
		'chevron-left',
		'chevron-right',
		'cog',
		'envelope',
		'times',
		'trash',
	];

	/**
	 * Compact glyph data for a set of icon names, for handing to a script.
	 *
	 * Returns canonical name => "viewBox|path". Unknown names are dropped, so a
	 * consumer can treat a missing key as "no icon" without a second check.
	 *
	 * @param array $names Icon names, canonical or Font Awesome 5 era.
	 * @return array
	 */
	public function get_icon_pairs( $names ) {
		if ( ! class_exists( 'FAFH' ) ) {
			return [];
		}

		$wanted = [];

		foreach ( array_unique( array_filter( (array) $names ) ) as $name ) {
			// Brand names live in their own family, and a bare name would resolve
			// to solid first, so the style is stated rather than guessed.
			$wanted[ (string) $name ] = $this->is_brand_icon( (string) $name ) ? 'brands' : 'solid';
		}

		return \FAFH::map( $wanted );
	}

	/**
	 * Allowed tags for echoing get_icon_markup() output.
	 *
	 * The markup is built by this plugin from bundled data, never from user
	 * input, but templates still pass it through wp_kses() so the escaping
	 * sniff has something to see and a future edit cannot quietly widen it.
	 *
	 * @return array
	 */
	public function icon_kses() {
		if ( class_exists( 'FAFH' ) ) {
			return \FAFH::kses();
		}

		return [
			'i' => [
				'class'       => true,
				'aria-hidden' => true,
			],
		];
	}

	/**
	 * Markup for one icon, as inline SVG where the library is available.
	 *
	 * Keeps `hp-icon`, which is core's own class and carries the sizing and
	 * spacing every one of these icons inherits. Falls back to the class markup
	 * if the library is missing, so a broken include degrades to the previous
	 * behaviour rather than to a blank space.
	 *
	 * @param string $icon    Icon name, or a full Font Awesome class string.
	 * @param string $classes Extra classes for the wrapper.
	 * @return string
	 */
	public function get_icon_markup( $icon, $classes = '' ) {
		$icon = (string) $icon;

		if ( '' === $icon ) {
			return '';
		}

		$wrapper = trim( 'hp-icon ' . $classes );

		if ( class_exists( 'FAFH' ) ) {
			$svg = \FAFH::svg( $icon );

			if ( $svg ) {
				return '<i class="' . esc_attr( trim( $wrapper . ' fafh-icon' ) ) . '" aria-hidden="true">' . $svg . '</i>';
			}
		}

		// A bare name needs the family class the old markup carried.
		$class = false === strpos( $icon, 'fa-' ) ? $this->get_icon_class( $icon ) : $icon;

		return '<i class="' . esc_attr( trim( $wrapper . ' ' . $class ) ) . '" aria-hidden="true"></i>';
	}

	/**
	 * Font Awesome classes for one icon name.
	 *
	 * Only used by the fallback path in get_icon_markup(); the SVG path needs no
	 * family class at all.
	 *
	 * @param string $icon Bare icon name.
	 * @return string
	 */
	public function get_icon_class( $icon ) {
		if ( $this->is_brand_icon( $icon ) ) {
			return 'fa-brands fa-' . $icon;
		}

		if ( $this->is_extended_icon( $icon ) ) {
			return 'fa-solid fa-' . $icon;
		}

		return 'fas fa-' . $icon;
	}

	/**
	 * Font Awesome classes for the configured header-bell icon.
	 *
	 * Only the fallback path needs this now: get_icon_markup() draws the bell as
	 * inline SVG, which carries no family class at all. Kept because the bell
	 * icon can be a brand, an added Font Awesome 6/7 solid name, or one of the
	 * Font Awesome 5 names core's own stylesheet already covers, and each of the
	 * three needs a different class when a font is drawing it.
	 *
	 * @return string
	 */
	public function get_bell_icon_class() {
		$icon = $this->get_bell_icon();

		if ( $this->is_brand_icon( $icon ) ) {
			return 'fa-brands fa-' . $icon;
		}

		if ( $this->is_extended_icon( $icon ) ) {
			return 'fa-solid fa-' . $icon;
		}

		return 'fas fa-' . $icon;
	}

	/**
	 * Registers the shared Font Awesome stylesheet.
	 *
	 * The handle is shared across this site's custom plugins on purpose, guarded so whichever
	 * plugin runs first registers it and only one copy ever loads. It is registered here and
	 * enqueued only where an icon actually needs it, so a site using only the FA5 icons core
	 * already ships loads nothing extra at all.
	 */
	protected function register_fontawesome() {
		// The webfont lives inside FAFH now and is wanted in wp-admin only, for
		// the picker previews. FAFH also loads the sheet that re-points brand
		// names at the brands family, which is what the $hp_brand_css block below
		// used to do by hand -- that block is now the fallback, not the norm.
		if ( class_exists( 'FAFH' ) ) {
			\FAFH::enqueue_admin();

			return;
		}
	}

	/**
	 * Whether the settings tab currently being rendered is this plugin's own.
	 *
	 * Answered from the fields HivePress has actually registered for this request, never from
	 * $_GET['tab']. The address cannot be trusted: get_settings_tab() falls back to the FIRST tab
	 * whenever "tab" is absent (reference/hivepress/includes/components/class-admin.php:607-622),
	 * and the bare admin.php?page=hp_settings link in the HivePress menu is exactly that case, so
	 * reading the address would miss this plugin's own tab on any site where it sorts first.
	 *
	 * register_settings() builds the sections and fields for one tab only and calls
	 * add_settings_field() with the prefixed option name (class-admin.php:287-325), so
	 * $wp_settings_fields['hp_settings'] holds hp_notification_* keys on this tab and on no other.
	 * It is the server-side twin of the [name^="hp_notification_"] gate the scripts use, and it is
	 * populated in time because HivePress registers on admin_init (:66) while this runs on
	 * admin_enqueue_scripts, which wp-admin fires later, from admin-header.php.
	 *
	 * @return bool
	 */
	protected function is_settings_tab() {
		if ( ! isset( $GLOBALS['wp_settings_fields']['hp_settings'] ) || ! is_array( $GLOBALS['wp_settings_fields']['hp_settings'] ) ) {
			return false;
		}

		foreach ( $GLOBALS['wp_settings_fields']['hp_settings'] as $hp_section ) {
			foreach ( array_keys( (array) $hp_section ) as $hp_field ) {
				if ( 0 === strpos( (string) $hp_field, 'hp_notification_' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Enqueues the colour picker on the settings screen.
	 *
	 * The bell icon field needs nothing from us: "options => icons" hands it to HivePress's own
	 * picker, the same Select2 control with live previews that core uses for attribute icons, and
	 * both Select2 and core's JS are enqueued in wp-admin as well as on the front end. A
	 * hand-rolled picker here would only ever offer a stale, shorter list.
	 *
	 * Colours are the opposite case: core's Color field is a plain input and core ships no picker
	 * at all, so the Iris picker is ours to add. The field still works and saves without it.
	 *
	 * The live preview panel's assets load here too. The front-end stylesheet is the real one, not
	 * a copy: every rule in it is scoped to .hp-notification* or .hp-nfh-*, and the only thing it
	 * emits globally is a block of :root custom properties that nothing in wp-admin reads. Loading
	 * it means the preview and the site are drawn by one stylesheet, so the panel doubles as a
	 * check on the front-end appearance rather than being a replica that can drift out of step.
	 */
	public function enqueue_color_picker() {

		// Only load on the HivePress settings screen.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'hp_settings' !== sanitize_key( (string) hp\get_array_value( $_GET, 'page' ) ) ) {
			return;
		}

		/*
		 * And only on this plugin's own tab.
		 *
		 * Until 1.5.4 there was no tab check here at all, deliberately: the tab in the address is
		 * not necessarily the tab being rendered, and each script was written to do nothing unless
		 * its own fields were on screen, so enqueuing on every tab looked free. It was not.
		 *
		 * A no-op script is still a file the browser fetches, parses and holds, and the two
		 * stylesheets are not no-ops at all - CSS cannot test anything. This plugin's FRONT-END
		 * stylesheet was loading onto every other extension's settings tab, where a rule that ever
		 * stopped being tightly scoped would restyle somebody else's controls and read as a bug in
		 * their plugin. admin.css already had one such rule (form.hp-form--table tr[hidden]). A QA
		 * pass on 2026-08-30 also found two different plugins' admin-preview.js loading together on
		 * the Account Menu tab, and twelve more siblings are due to gain settings-screen chrome of
		 * their own, so "everyone enqueues everywhere" would have ended with a dozen plugins' admin
		 * assets on every tab.
		 *
		 * is_settings_tab() answers the "which tab is this?" question properly instead of guessing
		 * from the address, including the no-tab fallback case that made guessing unsafe. The
		 * scripts keep their own [name^="hp_notification_"] gates: this decides whether they load,
		 * those decide whether they act, and neither is a substitute for the other.
		 */
		if ( ! $this->is_settings_tab() ) {
			return;
		}

		$path = plugin_dir_path( HP_NOTIFICATIONS_FILE );
		$url  = plugin_dir_url( HP_NOTIFICATIONS_FILE );

		// The file time rides along in the version so caches refresh whenever the file changes.
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'hp-notification-admin-colors', $url . 'assets/js/admin-colors.js', [ 'jquery', 'wp-color-picker' ], HP_NOTIFICATIONS_VERSION . '.' . (int) filemtime( $path . 'assets/js/admin-colors.js' ), true );

		wp_enqueue_style( 'hp-notification-frontend', $url . 'assets/css/frontend.css', [], HP_NOTIFICATIONS_VERSION . '.' . (int) filemtime( $path . 'assets/css/frontend.css' ) );
		wp_enqueue_style( 'hp-notification-admin', $url . 'assets/css/admin.css', [ 'hp-notification-frontend' ], HP_NOTIFICATIONS_VERSION . '.' . (int) filemtime( $path . 'assets/css/admin.css' ) );
		wp_enqueue_script( 'hp-notification-admin-preview', $url . 'assets/js/admin-preview.js', [ 'jquery', 'wp-color-picker' ], HP_NOTIFICATIONS_VERSION . '.' . (int) filemtime( $path . 'assets/js/admin-preview.js' ), true );

		/*
		 * The pinned Font Awesome for the icon picker previews, plus a fix the previews cannot do without.
		 *
		 * Core's Select2 template hard-codes "fas fa-{name}" (reference/hivepress/assets/js/
		 * common.js:233), and a brand glyph does not live in the solid font family, so a brand
		 * icon previews as a blank square under that class. The generated rules below re-point
		 * exactly the brand names this plugin adds at the brands family. Brand and solid names
		 * never overlap in Font Awesome, so nothing legitimate is re-pointed.
		 */
		// The brand CSS this block used to generate is gone with the webfont.
		// It re-pointed brand NAMES at the brands font family, because core
		// previews every option as `fas fa-{id}` and a brand glyph is not in the
		// solid font. An SVG has no font family to be wrong about, so the whole
		// problem disappeared rather than being solved somewhere else.
		$this->register_fontawesome();

		// The shared settings chrome and the collapsible groups on the Notifications tab. The
		// script keeps its own hp_notification_* gate as a belt and braces, but the tab check above
		// means it no longer loads on the other HivePress tabs at all.
		wp_enqueue_script( 'hp-notification-admin-nav', $url . 'assets/js/admin-nav.js', [], HP_NOTIFICATIONS_VERSION . '.' . (int) filemtime( $path . 'assets/js/admin-nav.js' ), true );

		wp_localize_script(
			'hp-notification-admin-nav',
			'hpnfAdminNav',
			[
				'show'   => esc_html__( 'Show options', 'notifications-for-hivepress' ),
				'hide'   => esc_html__( 'Hide options', 'notifications-for-hivepress' ),

				/*
				 * The shared chrome's own wording, in the shape the copied block reads it from.
				 *
				 * These three strings are the same in every sibling plugin on purpose. The nav
				 * label read "Jump to:" here until 1.5.5, and the house standard is "Jump to a
				 * section:" - two extensions labelling the same control differently is exactly the
				 * inconsistency the shared chrome exists to remove
				 * (resources/hivepress-settings.md, "The settings anchor nav"). The colon is part
				 * of the wording: it reads as a lead-in to the links that follow it, not as a
				 * heading over them.
				 */
				'labels' => [
					'jumpTo'    => esc_html__( 'Jump to a section:', 'notifications-for-hivepress' ),
					'save'      => esc_html__( 'Save Changes', 'notifications-for-hivepress' ),
					'backToTop' => esc_html__( 'Back to top', 'notifications-for-hivepress' ),
				],
			]
		);
	}

	/**
	 * Registers the live preview panel on the Notifications settings tab.
	 *
	 * The panel is a settings section of our own with no title and no fields, which is what gets it
	 * into the settings form at all. WordPress prints an <h2> only for a section that has a title
	 * (wp-admin/includes/template.php:1782) and a <table> only for a section that has registered
	 * fields (:1790), so a section with neither leaves the callback's own markup standing on its own
	 * as a block-level sibling inside the form, above settings_fields() and the Save button
	 * (reference/hivepress/templates/admin/settings.php:16-18).
	 */
	public function register_preview_section() {
		global $pagenow;

		// HivePress registers its settings on options.php as well, so that a save has the field list
		// to validate against (reference/hivepress/includes/components/class-admin.php:278). Nothing
		// is rendered on that request, so a panel registered there is pure waste.
		if ( 'admin.php' !== $pagenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'hp_settings' !== sanitize_key( (string) hp\get_array_value( $_GET, 'page' ) ) ) {
			return;
		}

		/*
		 * Whether the tab being registered is ours.
		 *
		 * Asked of the registered fields rather than of $_GET['tab'] - see is_settings_tab(), which
		 * carries the reasoning and is the same test the asset enqueue uses. This used to look for
		 * our own "popups" section by name; the field test replaced it in 1.5.4 so there is one
		 * answer to "is this our tab?" rather than two that could drift apart, and because a
		 * section name is not prefixed and a sibling extension could register one called popups,
		 * while an hp_notification_ field can only be ours.
		 */
		if ( ! $this->is_settings_tab() ) {
			return;
		}

		add_settings_section( 'hpnf_preview', '', [ $this, 'render_preview_section' ], 'hp_settings' );

		if ( ! isset( $GLOBALS['wp_settings_sections']['hp_settings']['hpnf_preview'] ) ) {
			return;
		}

		/*
		 * Move the panel to the front of the list.
		 *
		 * Sections render in registration order and ours is necessarily last, which on a narrow
		 * screen would leave "here is what it looks like" underneath every control that changes it.
		 * On a wide screen the panel is lifted into a column of its own and the order stops
		 * mattering, so this only shows below 1400px - which is where it matters.
		 *
		 * This is a plain reorder of a data array that WordPress reads later in the same request. It
		 * runs no callbacks and changes no section, so there is nothing here to fire twice.
		 */
		$sections = $GLOBALS['wp_settings_sections']['hp_settings'];
		$preview  = $sections['hpnf_preview'];

		unset( $sections['hpnf_preview'] );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reordering our own entry in the settings section list, which is the documented way sections are held and has no setter.
		$GLOBALS['wp_settings_sections']['hp_settings'] = array_merge( [ 'hpnf_preview' => $preview ], $sections );
	}

	/**
	 * Renders the live preview panel.
	 *
	 * The pop-up below is the same markup frontend.js builds, element for element and in the same
	 * order (assets/js/frontend.js, Toasts.show()), drawn by the same stylesheet. Mirroring it
	 * exactly is the point: a spacing or wrapping bug then appears here, on a screen somebody looks
	 * at while working, instead of only in production.
	 *
	 * There are no form inputs of any kind in here, on purpose. options.php calls update_option()
	 * for every option registered on the tab, so a stray input that posted would take part in the
	 * save, and anything this panel left out would be blanked (resources/hivepress-settings.md).
	 * The replay control is a plain type="button".
	 *
	 * WordPress does not filter or escape a section callback's output, so everything below is
	 * escaped here.
	 */
	public function render_preview_section() {

		// The same reads the front end makes, so an untouched setting previews as the site draws it.
		$position        = (string) get_option( 'hp_notification_toast_position', 'bottom-left' );
		$position_mobile = (string) get_option( 'hp_notification_toast_position_mobile', 'bottom' );
		$autohide        = (bool) get_option( 'hp_notification_toast_autohide', true );
		$duration        = max( 1, absint( get_option( 'hp_notification_toast_duration', 6 ) ) );

		// Fall back rather than trust, because these two go straight into a class name.
		if ( ! in_array( $position, [ 'top-right', 'top-left', 'bottom-right', 'bottom-left' ], true ) ) {
			$position = 'bottom-left';
		}

		if ( ! in_array( $position_mobile, [ 'top', 'center', 'bottom' ], true ) ) {
			$position_mobile = 'bottom';
		}

		// The sample is fixed wording, never a real notification. A preview built from real data is
		// empty on a fresh site, which is precisely when somebody is choosing colours. It uses the
		// most elements any pop-up can have - icon, type, text, link and countdown - so no setting on
		// this tab is left with nothing to change.
		$user = wp_get_current_user();
		$name = trim( (string) $user->display_name );

		// A display name is all but guaranteed here, but a stand-in keeps the sentence readable if a
		// site has somehow left one blank. Given context, because a bare "Sam" in the translation
		// file is impossible to place.
		if ( ! $name ) {
			$name = _x( 'Sam', 'stand-in name in the settings preview', 'notifications-for-hivepress' );
		}

		echo '<div class="hpnf-preview"><div class="hpnf-preview__inner">';

		echo '<h2 class="hpnf-preview__title">' . esc_html__( 'Live preview', 'notifications-for-hivepress' ) . '</h2>';

		/*
		 * The stage carries the appearance settings as custom properties, set by admin-preview.js as
		 * the settings change. They go here rather than on :root, which is what the front end uses,
		 * so that nothing this panel does can reach the rest of wp-admin.
		 *
		 * Hidden from screen readers because it is a picture of a pop-up rather than a pop-up: its
		 * close button closes nothing and its link goes nowhere, so announcing them would offer two
		 * controls that do not exist. The button is also taken out of the tab order, which
		 * aria-hidden alone does not do.
		 */
		echo '<div class="hpnf-preview__stage" aria-hidden="true">';

		echo '<div class="hp-notification-toasts hp-notification-toasts--' . esc_attr( $position ) . ' hp-notification-toasts--m-' . esc_attr( $position_mobile ) . '">';

		echo '<div class="hp-notification-toast hp-notification-toast--visible">';

		echo '<div class="hp-notification-toast__icon"><i class="hp-icon fas fa-envelope"></i></div>';

		echo '<div class="hp-notification-toast__body">';
		echo '<span class="hp-notification-toast__type">' . esc_html__( 'New message', 'notifications-for-hivepress' ) . '</span>';

		echo '<span class="hp-notification-toast__text">' . esc_html(
			sprintf(
				/* translators: %s: name of the person signed in. */
				__( '%s sent you a message about Riverside Studio.', 'notifications-for-hivepress' ),
				$name
			)
		) . '</span>';

		// No href: the link has to be an <a> to be drawn like the real one, and an <a> without one
		// cannot be followed or focused, so the preview stays inert.
		echo '<a class="hp-notification-toast__link"><span>' . esc_html__( 'View message', 'notifications-for-hivepress' ) . '</span><i class="hp-icon fas fa-chevron-right"></i></a>';
		echo '</div>';

		echo '<button type="button" class="hp-notification-toast__close" tabindex="-1"><i class="hp-icon fas fa-times"></i></button>';

		/*
		 * The countdown bar is rendered here rather than left to the script, because its animation
		 * ends in a "forwards" fill: with no duration on the element it would run to empty in the
		 * frame before the script loads and stay there, and setting a duration afterwards does not
		 * bring a finished animation back.
		 */
		echo '<div class="hp-notification-toast__progress" style="animation-duration:' . esc_attr( (string) $duration ) . 's"' . ( $autohide ? '' : ' hidden' ) . '></div>';

		echo '</div></div></div>';

		echo '<p class="description hpnf-preview__description">' . esc_html__( 'How a pop-up will look with the settings on this page. It follows every change as you make it, and nothing is stored until you press Save Changes. The wording is a sample rather than a real notification.', 'notifications-for-hivepress' ) . '</p>';

		echo '<button type="button" class="button hpnf-preview__replay">' . esc_html__( 'Play again', 'notifications-for-hivepress' ) . '</button>';

		echo '</div></div>';
	}

	/**
	 * Checks whether a user is within their quiet hours.
	 *
	 * Quiet hours stop pop-ups and pushes. The notification is still created, so nothing is lost,
	 * it just waits in the list rather than interrupting.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function is_quiet( $user_id ) {
		$quiet = get_user_meta( $user_id, 'hp_notification_quiet', true );

		if ( ! is_array( $quiet ) || ! isset( $quiet['start'], $quiet['end'] ) ) {
			return false;
		}

		$start = absint( $quiet['start'] );
		$end   = absint( $quiet['end'] );

		if ( $start === $end ) {
			return false;
		}

		// Use the site's time, which is the only clock the server knows.
		$hour = (int) wp_date( 'G' );

		if ( $start < $end ) {
			return $hour >= $start && $hour < $end;
		}

		// The window runs past midnight.
		return $hour >= $start || $hour < $end;
	}

	/**
	 * Gets the quiet hours of a user.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function get_quiet_hours( $user_id ) {
		$quiet = get_user_meta( $user_id, 'hp_notification_quiet', true );

		return is_array( $quiet ) ? $quiet : [];
	}

	/**
	 * Sets the quiet hours of a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $start Start hour.
	 * @param int $end End hour.
	 */
	public function update_quiet_hours( $user_id, $start, $end ) {
		$start = min( 23, absint( $start ) );
		$end   = min( 23, absint( $end ) );

		if ( $start === $end ) {
			delete_user_meta( $user_id, 'hp_notification_quiet' );

			return;
		}

		update_user_meta(
			$user_id,
			'hp_notification_quiet',
			[
				'start' => $start,
				'end'   => $end,
			]
		);
	}

	/**
	 * Records that a notification was sent or opened.
	 *
	 * Only totals per type are kept. Nothing is recorded against a person, so this stays out of the
	 * way of the personal data tools.
	 *
	 * @param string $type Notification type.
	 * @param string $stat Statistic name.
	 */
	public function add_stat( $type, $stat ) {
		if ( ! get_option( 'hp_notification_stats', true ) ) {
			return;
		}

		$stats = get_option( 'hp_notification_stat_data' );

		if ( ! is_array( $stats ) ) {
			$stats = [];
		}

		$stats[ $type ][ $stat ] = absint( hp\get_array_value( (array) hp\get_array_value( $stats, $type, [] ), $stat ) ) + 1;

		update_option( 'hp_notification_stat_data', $stats, false );
	}

	/**
	 * Gets the notification statistics.
	 *
	 * @return array
	 */
	public function get_stats() {
		$stats = get_option( 'hp_notification_stat_data' );

		return is_array( $stats ) ? $stats : [];
	}

	/**
	 * Gets the channels a user wants for a notification type.
	 *
	 * Preferences are saved per group rather than per type. System types such as announcements
	 * bypass preferences entirely, because there's nothing to opt out of.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type Notification type.
	 * @return array
	 */
	public function get_user_channels( $user_id, $type ) {
		$channels = array_intersect( $this->get_type_channels( $type ), array_keys( $this->get_channels() ) );

		if ( hp\get_array_value( $this->get_type_args( $type ), '_system' ) ) {
			return array_values( $channels );
		}

		$preferences = $this->get_stored_preferences( $user_id );
		$group       = $this->get_type_group( $type );

		if ( isset( $preferences[ $group ] ) ) {
			return array_values( array_intersect( (array) $preferences[ $group ], $channels ) );
		}

		return array_values( array_intersect( $this->get_role_channels( $user_id ), $channels ) );
	}

	/**
	 * Gets the channels a user's role starts with.
	 *
	 * A stylist and a client want different things by default, and neither should have to go and
	 * set that up before the site behaves sensibly.
	 *
	 * The screen is taken at its word. Three states, not two: a saved list means those channels, a
	 * saved-but-empty list means none of them, and no saved value at all means the role has never
	 * been configured and gets everything.
	 *
	 * The middle one used to be missing. Unticking every box for a role posts nothing, so the option
	 * stores as an empty string rather than an empty array, is_array( '' ) is false, and the role
	 * fell through to the last line and got every channel - the exact opposite of what the admin had
	 * just set, and in the noisier direction. It was documented in a tooltip rather than fixed,
	 * which is not the same thing: a settings screen that needs a footnote to explain why it does
	 * the reverse of what it shows is a broken settings screen.
	 *
	 * Opt-in channels are excluded throughout: a role default can never grant one.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function get_role_channels( $user_id ) {
		$channels = array_values( array_diff( array_keys( $this->get_channels() ), $this->get_optin_channels() ) );

		// Get user.
		$user = get_userdata( $user_id );

		if ( ! $user || ! $user->roles ) {
			return $channels;
		}

		// Take the first role that has been configured either way.
		foreach ( (array) $user->roles as $role ) {
			$default = get_option( 'hp_notification_default_' . $role );

			if ( is_array( $default ) ) {
				return array_values( array_intersect( $channels, $default ) );
			}

			if ( '' === $default ) {
				return [];
			}
		}

		return $channels;
	}

	/**
	 * Gets the stored channel choices of a user, keyed by group.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	protected function get_stored_preferences( $user_id ) {
		$stored = get_user_meta( $user_id, 'hp_notification_preferences', true );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Gets the channels a user wants for every group, in the shape the settings form expects.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function get_user_preferences( $user_id ) {
		$stored = $this->get_stored_preferences( $user_id );
		$role   = $this->get_role_channels( $user_id );

		// Work out which channels each group can deliver.
		$groups = [];

		foreach ( $this->get_enabled_types() as $type ) {
			if ( hp\get_array_value( $this->get_type_args( $type ), '_system' ) ) {
				continue;
			}

			$group = $this->get_type_group( $type );

			$groups[ $group ] = array_unique( array_merge( hp\get_array_value( $groups, $group, [] ), $this->get_type_channels( $type ) ) );
		}

		$preferences = [];

		foreach ( $groups as $group => $channels ) {
			$choice = isset( $stored[ $group ] ) ? (array) $stored[ $group ] : $role;

			$preferences[ $group ] = array_values( array_intersect( $choice, $channels ) );
		}

		return $preferences;
	}

	/**
	 * Saves the channels a user wants.
	 *
	 * An empty array is stored rather than dropped, because that's what tells "every channel off"
	 * apart from "never set", which falls back to every channel on.
	 *
	 * @param int   $user_id User ID.
	 * @param array $preferences Channels keyed by group.
	 */
	public function update_user_preferences( $user_id, $preferences ) {

		// Work out which groups exist right now, and which channels each can deliver, so a
		// crafted request can't store a group or a channel the settings page never offered.
		$groups = [];

		foreach ( $this->get_enabled_types() as $type ) {
			if ( hp\get_array_value( $this->get_type_args( $type ), '_system' ) ) {
				continue;
			}

			$group = $this->get_type_group( $type );

			$groups[ $group ] = array_unique( array_merge( hp\get_array_value( $groups, $group, [] ), $this->get_type_channels( $type ) ) );
		}

		// Get stored preferences.
		$stored = get_user_meta( $user_id, 'hp_notification_preferences', true );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		foreach ( $preferences as $group => $selected ) {
			if ( ! isset( $groups[ $group ] ) ) {
				continue;
			}

			$stored[ $group ] = array_values( array_intersect( $groups[ $group ], (array) $selected ) );
		}

		update_user_meta( $user_id, 'hp_notification_preferences', $stored );
	}

	/**
	 * Adds the notification settings.
	 *
	 * The notification types are added here rather than in the settings config because the list
	 * depends on the extensions that are active, which isn't known until the classes are loaded.
	 *
	 * @param array $settings Settings configuration.
	 * @return array
	 */
	public function alter_settings( $settings ) {

		// Give each colour field the default the picker's reset control restores to. Without it
		// the picker has nothing to reset to and its Clear button leaves the field empty.
		// The filter also fires with arrays that carry no notifications tab at all (observed as
		// "Undefined array key" warnings in debug.log during WP-CLI runs), so guard before reading.
		if ( ! isset( $settings['notifications']['sections'] ) ) {
			return $settings;
		}

		foreach ( [ 'appearance', 'delivery' ] as $section ) {
			foreach ( (array) hp\get_array_value( (array) hp\get_array_value( $settings['notifications']['sections'], $section, [] ), 'fields', [] ) as $name => $field ) {
				if ( 'color' === hp\get_array_value( $field, 'type' ) && hp\get_array_value( $field, 'default' ) ) {
					$settings['notifications']['sections'][ $section ]['fields'][ $name ]['attributes']['data-default-color'] = $field['default'];
				}
			}
		}

		if ( ! isset( $settings['notifications']['sections']['types'] ) ) {
			return $settings;
		}

		/*
		 * Said once and said generally. This used to name every email-less type the site had, built
		 * at runtime so it could never mention a checkbox that was not on the screen - and on a site
		 * with the gallery, holiday and insight extensions active that was thirty-three names in one
		 * sentence. Chris asked for it to go on 2026-09-02: "It's a wall of text which we want to
		 * avoid." The runtime gate stays, so a site with no such type is not told about one.
		 */
		$hp_has_emailless = false;

		foreach ( $this->get_optional_types() as $args ) {
			if ( isset( $args['channels'] ) && ! in_array( 'email', (array) $args['channels'], true ) ) {
				$hp_has_emailless = true;

				break;
			}
		}

		if ( $hp_has_emailless ) {
			$settings['notifications']['sections']['types']['description'] .= ' ' . esc_html__( 'Some notification types have no email behind them, so unticking one of those switches that notification off altogether.', 'notifications-for-hivepress' );
		}

		// One field per group keeps forty types from arriving as one wall, and only the groups
		// your active extensions provide ever appear. The section itself carries the explanation,
		// so nothing is repeated per group.
		$order = 10;

		foreach ( $this->get_groups() as $group => $group_label ) {
			$options = [];

			foreach ( $this->get_optional_types() as $type => $args ) {
				if ( $this->get_type_group( $type ) === $group ) {
					$options[ $type ] = hp\get_array_value( $args, 'label', $type );
				}
			}

			if ( ! $options ) {
				continue;
			}

			/*
			 * Every group carries the same tooltip. These eight fields had none at all, which broke
			 * the house rule that every setting gets one, and did it on the tallest part of the
			 * screen: forty-odd tick boxes whose only guidance was a single paragraph the owner had
			 * to scroll back up to reread. One shared string keeps it to a single line to translate.
			 */
			$group_hint = esc_html__( 'Tick what this plugin should handle as an on-site notification, with each person choosing how they receive it. Leave one unticked and any email HivePress already sends carries on unchanged.', 'notifications-for-hivepress' );

			/*
			 * Two boxes on the Account group arrive unticked on purpose, and nothing said why.
			 * Ticking one puts it under that person's Email box, and anybody who has cleared Email
			 * then stops receiving it - which for a password reset or an email verification means
			 * they cannot get back into their account at all. HivePress refuses the login outright
			 * (reference/hivepress/includes/components/class-user.php:154-155).
			 */
			if ( 'account' === $group ) {
				$group_hint .= ' ' . esc_html__( 'Password Reset and Email Verification start unticked on purpose: ticked, anyone who has cleared Email stops receiving them and cannot sign in to put that right.', 'notifications-for-hivepress' );
			}

			// Review notifications sit in two places, so each group says where the others are. The
			// tidier answer is a Reviews group of its own, but that would move them to a different
			// stored option and silently switch them back on for anyone upgrading.
			if ( 'listings' === $group && isset( $options['listing_review'] ) ) {
				$group_hint .= ' ' . esc_html__( 'Review Received is here because it is about your own listing. The other review notifications are under Other.', 'notifications-for-hivepress' );
			}

			// Only when Review Received is really on the screen: it is registered by the Reviews
			// extension, so on a site without Reviews this sentence pointed at a checkbox that was
			// nowhere on the page - the same class of mistake as the hard-coded email-less list.
			if ( 'other' === $group && isset( $this->get_types()['listing_review'] ) ) {
				$group_hint .= ' ' . esc_html__( 'Review Received, for a review left on your own listing, is under Listings.', 'notifications-for-hivepress' );
			}

			// Where Site Emails come from, for the owner only: the sentence names another plugin.
			foreach ( array_keys( $options ) as $option_type ) {
				$group_hint .= $this->get_type_note( $option_type );
			}

			$settings['notifications']['sections']['types']['fields'][ 'notification_types_' . $group ] = [
				'label'       => $group_label,
				'description' => $group_hint,
				'type'        => 'checkboxes',
				'options'     => $options,
				'default'     => array_diff( array_keys( $options ), $this->get_default_off_types() ),

				// Marks the checkbox list for the collapse admin-nav.js adds. The attribute lands
				// on the field's own wrapper div (class-checkboxes.php:77 renders attributes on
				// it), which is exactly the element whose list the script folds away.
				'attributes'  => [
					// Marks the field for admin-nav.js to draw as a card: a header bar carrying the
					// group's icon and name over the folded checkbox list. The icon is only named
					// when the library has it, so a missing glyph draws nothing rather than a box.
					'data-hpnf-card'      => $group,
					'data-hpnf-card-icon' => class_exists( 'FAFH' ) && \FAFH::has( $this->get_group_icon( $group ) ) ? $this->get_group_icon( $group ) : '',
				],
				'_order'      => $order,
			];

			$order += 5;
		}

		// Add the per-role defaults, in their own section so they are not mistaken for more types.
		if ( isset( $settings['notifications']['sections']['defaults'] ) ) {
			$order = 10;

			/*
			 * Every role row gets a tooltip, not just whichever role WordPress happened to return
			 * first. Only the first used to have one, and it explained what a WordPress role is
			 * without ever mentioning the three tick boxes beside it - so on a WooCommerce site the
			 * Customer and Shop Manager rows sat there bare, with nothing to say that clearing
			 * Email stops HivePress's own email reaching those people.
			 */
			$role_hint = esc_html__( 'What people with this role receive until they choose for themselves. Unticking Email stops the email HivePress already sends from reaching them.', 'notifications-for-hivepress' );

			// The long note about which role is which goes on the first row only. Repeating it on
			// every role turned the section into a wall of identical paragraphs.
			$note = esc_html__( 'These are WordPress roles. HivePress makes someone a Contributor when their vendor profile is published, so on most sites your vendors are Contributors.', 'notifications-for-hivepress' );

			foreach ( wp_roles()->get_names() as $role => $label ) {
				$field = [
					/* translators: %s: role name. */
					'label'       => sprintf( esc_html__( '%s Defaults', 'notifications-for-hivepress' ), translate_user_role( $label ) ),
					'description' => $note ? $role_hint . ' ' . $note : $role_hint,
					'type'        => 'checkboxes',

					// Opt-in channels are left off this screen on purpose: a role default can never
					// grant one, so a box here would be the lying-checkbox class of mistake - ticked
					// on the screen while delivery refuses it.
					'options'     => array_diff_key( $this->get_channels(), array_flip( $this->get_optin_channels() ) ),
					'default'     => array_diff( array_keys( $this->get_channels() ), $this->get_optin_channels() ),
					'_order'      => $order,
				];

				$note = '';

				$settings['notifications']['sections']['defaults']['fields'][ 'notification_default_' . $role ] = $field;

				$order += 10;
			}

			// The section description explains On-site and Email unconditionally, but Push is only
			// on the screen when it is actually on offer, so its sentence is only added then. A
			// screen that explains a box nobody can see is its own kind of confusing.
			if ( isset( $this->get_channels()['push'] ) ) {
				$settings['notifications']['sections']['defaults']['description'] .= ' ' . esc_html__( 'Push goes out with the on-site notification, so Push without On-site sends neither.', 'notifications-for-hivepress' );
			}
		}

		// Add the text fields.
		if ( ! isset( $settings['notifications']['sections']['text'] ) ) {
			return $settings;
		}

		$order = 10;

		/*
		 * The fields are laid down group by group rather than in one alphabetical run, so the
		 * rows for one source - Bookings, Listings, Gallery - sit together and admin-nav.js can
		 * fold each run behind a single toggle. The group label rides on every field as a data
		 * attribute (text fields render their attributes onto the <input>,
		 * reference/hivepress/includes/fields/class-text.php:234), because the script has no
		 * other way to know where one group ends and the next begins.
		 */
		$hpnf_grouped_types = [];

		foreach ( $this->get_enabled_types() as $type ) {

			// System types have no fixed wording to customise.
			if ( hp\get_array_value( $this->get_type_args( $type ), '_system' ) ) {
				continue;
			}

			$hpnf_grouped_types[ $this->get_type_group( $type ) ][] = $type;
		}

		foreach ( $this->get_groups() as $group => $group_label ) {
			foreach ( (array) hp\get_array_value( $hpnf_grouped_types, $group, [] ) as $type ) {
				$args = $this->get_type_args( $type );

				/*
				 * The title comes first, above the wording, matching the order the two render in on
				 * every surface: the heading sits above the sentence on the pop-up, the bell
				 * dropdown and the notifications page alike.
				 *
				 * Titles are plain words, not token templates: the pop-up heading and the push
				 * title are rendered long after the send, when the tokens are no longer to hand,
				 * so a token here would reach people verbatim. The description says so instead of
				 * leaving it to be discovered. The placeholder is the public label, which is what
				 * shows when the box is left empty.
				 */
				$settings['notifications']['sections']['text']['fields'][ 'notification_title_' . $type ] = [
					/* translators: %s: notification name. */
					'label'       => sprintf( esc_html__( '%s (title)', 'notifications-for-hivepress' ), $this->get_type_label( $type ) ),
					'description' => esc_html__( 'The short heading shown above the wording: on pop-ups, in the bell dropdown, on the notifications page and as the push notification title. Plain words only, as tokens are not replaced here. Leave it empty to use the name shown in grey.', 'notifications-for-hivepress' ) . $this->get_type_note( $type ),
					'type'        => 'text',
					'max_length'  => 64,
					'placeholder' => $this->get_type_public_label( $type ),

					'attributes'  => [
						'style'                => 'width:100%;max-width:52em;',
						'data-hpnf-group'      => $group_label,
						'data-hpnf-group-key'  => $group,
						'data-hpnf-group-icon' => class_exists( 'FAFH' ) && \FAFH::has( $this->get_group_icon( $group ) ) ? $this->get_group_icon( $group ) : '',
					],

					'_order'      => $order,
				];

				$order += 10;

				$settings['notifications']['sections']['text']['fields'][ 'notification_text_' . $type ] = [
					'label'       => $this->get_type_label( $type ),
					'description' => $this->get_token_hint( $type ),
					'type'        => 'text',
					'max_length'  => 256,

					/*
					 * Sanitised through wp_kses rather than sanitize_text_field, which eats tokens.
					 *
					 * sanitize_text_field() strips percent-encoded octets, and it cannot tell one from
					 * a HivePress token: in "%badge.name%" it reads the "%ba" as an encoded byte,
					 * because b and a are both hex digits, and saves "dge.name%". The admin types the
					 * token this very field's hint told them to use, presses Save, and the notification
					 * goes out reading "You have earned the dge.name% badge". Measured on WP 7.0.2.
					 *
					 * It is a whole class of tokens, not one: any name whose first two characters are
					 * both hex digits goes the same way, so %category...%, %date...% and anything an
					 * extension adds starting ba, ca, da, de, fa and so on are all affected. %user...%
					 * and %listing...% happen to survive, which is what makes this so easy to miss.
					 *
					 * Setting "html" to a non-empty array routes the value through wp_kses instead
					 * (reference/hivepress/includes/fields/class-text.php:194-201), which leaves
					 * percent sequences alone. The list is HivePress's own minimal set, the one
					 * hp\sanitize_html() uses. Nothing is loosened by this in practice: render_text()
					 * puts every stored string through wp_strip_all_tags() before it reaches anyone,
					 * the notifications page escapes with esc_html(), and the pop-up and bell assign to
					 * textContent. The control itself is unchanged - class-text.php:234 renders a plain
					 * input either way.
					 */
					'html'        => [
						'strong' => [],
						'i'      => [ 'class' => [] ],
						'a'      => [
							'href'   => [],
							'target' => [],
							'class'  => [],
						],
					],

					// The default wording shows here as grey hint text, so leaving the box empty is a
					// visible choice rather than a blank. Types HivePress only emails to the site
					// administrator have no wording of their own and say so.
					'placeholder' => hp\get_array_value( $args, 'text' ) ? $args['text'] : esc_html__( 'The email subject is used', 'notifications-for-hivepress' ),

					/*
					 * Full width, because these hold a whole sentence and the box is otherwise about
					 * 350px - roughly a third of a default, and none of the end of a 256 character one,
					 * so nobody can read what they are editing.
					 *
					 * An inline width rather than a class: HivePress's admin stylesheet sizes these
					 * with ".hp-field.regular-text{width:25em}" and ships no rule for WordPress's
					 * "large-text", so adding that class changes nothing. An inline style wins on the
					 * cascade without an !important arms race, and only for these fields.
					 */
					'attributes'  => [
						'style'                => 'width:100%;max-width:52em;',
						'data-hpnf-group'      => $group_label,
						'data-hpnf-group-key'  => $group,
						'data-hpnf-group-icon' => class_exists( 'FAFH' ) && \FAFH::has( $this->get_group_icon( $group ) ) ? $this->get_group_icon( $group ) : '',
					],

					'_order'      => $order,
				];

				$order += 10;

				// A type that rolls bursts up needs a second wording, because the first one is
				// written about one person and cannot be reused once there are twelve. Only the
				// handful of types that actually roll up get this field, so the screen does not
				// double in length. Inheriting the wording field's definition also carries its
				// group attribute, which keeps this row inside the same collapsible group.
				$grouped = hp\get_array_value( $args, 'text_grouped' );

				if ( $grouped ) {
					$settings['notifications']['sections']['text']['fields'][ 'notification_text_grouped_' . $type ] = array_merge(
						$settings['notifications']['sections']['text']['fields'][ 'notification_text_' . $type ],
						[
							/* translators: %s: notification name. */
							'label'       => sprintf( esc_html__( '%s (more than one)', 'notifications-for-hivepress' ), $this->get_type_label( $type ) ),
							/* translators: %other_count% is a HivePress token, not a printf placeholder: it is replaced by name, so it must keep its spelling exactly and must not be numbered. */
							'description' => esc_html__( 'Used from the second one onwards, when several arriving close together are shown as one notification. Keep %other_count% exactly as written; it becomes "12 others" or "1 other".', 'notifications-for-hivepress' ) . ' ' . $this->get_token_hint( $type ),
							'placeholder' => $grouped,
							'_order'      => $order,
						]
					);

					$order += 10;
				}
			}
		}

		return $settings;
	}

	/**
	 * Records the tokens a notification type actually uses.
	 *
	 * The token list an email declares is only there for the HivePress email editor, so the emails
	 * that HivePress sends to the site administrator declare none at all even though they pass
	 * plenty. It also doesn't say which tokens hold an object, and those are the ones that need a
	 * field after a dot. Both are only knowable from a real send, so they're recorded here and used
	 * to build an accurate hint in the admin.
	 *
	 * @param string $type Notification type.
	 * @param array  $tokens Token values.
	 */
	protected function update_seen_tokens( $type, $tokens ) {
		$seen = [];

		foreach ( $tokens as $name => $value ) {
			$seen[ $name ] = hp\is_namespace_instance( $value, 'models' ) ? 1 : 0;
		}

		ksort( $seen );

		// Get stored tokens.
		$stored = get_option( 'hp_notification_tokens' );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		if ( hp\get_array_value( $stored, $type ) === $seen ) {
			return;
		}

		$stored[ $type ] = $seen;

		update_option( 'hp_notification_tokens', $stored, false );
	}

	/**
	 * Lists the tokens a notification type can use.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	protected function get_token_hint( $type ) {
		$tokens = [];

		// Get the tokens the type declares. A declared name that matches a known model is shown
		// with a real field straight away, so the full list is visible before the first send.
		foreach ( (array) hp\get_array_value( $this->get_type_args( $type ), 'tokens', [] ) as $name ) {
			$tokens[ $name ] = array_key_exists( $name, $this->get_model_token_fields() );
		}

		// Get the tokens actually seen, which is more accurate and covers the types that declare
		// none.
		foreach ( (array) hp\get_array_value( (array) get_option( 'hp_notification_tokens' ), $type, [] ) as $name => $is_model ) {
			$tokens[ $name ] = (bool) $is_model;
		}

		/*
		 * One token is never offered. HivePress's registration email declares %user_password% and
		 * it does work, so it would otherwise appear in this list - and the list is what tells the
		 * site owner which tokens to use. Anyone who takes the hint writes a plaintext password
		 * into wp_comments, where it is served by two REST routes and pushed to the OS notification
		 * body until the storage period expires. An email is read once and can be deleted; an
		 * on-site notification sits in a list for weeks.
		 */
		unset( $tokens['user_password'] );

		if ( ! $tokens ) {
			return esc_html__( 'Tokens are placeholders such as %listing.title%, swapped for the real wording when a notification is sent. The ones this notification offers are listed here after its first send; until then ordinary words work.', 'notifications-for-hivepress' );
		}

		ksort( $tokens );

		// Get the list. Object tokens are shown with a field, because that's the only form that
		// gets replaced.
		$list = [];

		foreach ( $tokens as $name => $is_model ) {
			if ( true === $is_model ) {
				$list[] = '%' . $name . '.' . hp\get_array_value( $this->get_model_token_fields(), $name, 'field' ) . '%';
			} else {
				$list[] = '%' . $name . '%';
			}
		}

		return sprintf(
			/* translators: %s: comma-separated list of tokens. Keep the doubled percent signs as they are; they print as single ones. */
			esc_html__( 'Tokens: %s. Each is swapped for the real wording when the notification is sent; copy them exactly. Where one ends in ".field", change that word to the detail you want, and a vertical bar (|) inside a token sets fallback wording, as in %%listing.title|your listing%%.', 'notifications-for-hivepress' ),
			implode( ', ', $list )
		);
	}

	/**
	 * Gets an example field per known model token, for the hints.
	 *
	 * @return array
	 */
	protected function get_model_token_fields() {
		return [
			'user'       => 'display_name',
			'sender'     => 'display_name',
			'recipient'  => 'display_name',
			'author'     => 'display_name',
			'listing'    => 'title',
			'vendor'     => 'name',
			'booking'    => 'id',
			'order'      => 'id',
			'request'    => 'title',
			'offer'      => 'id',
			'membership' => 'id',
			'review'     => 'id',
			'message'    => 'text',
			'badge'      => 'name',
		];
	}

	/**
	 * Answers 200 rather than 404 on page two of the notification list.
	 *
	 * WordPress decides the status long before HivePress renders anything. The rewrite rule for
	 * "/page/{n}/" sets "paged", the main query then finds no posts because the route is served by
	 * a virtual page rather than an archive, and WP::handle_404() sets a 404 during wp(). HivePress
	 * only clears the flag later, on "template_include" (class-router.php:596), which fixes the body
	 * class and the template but is far too late for the header. The page then serves twenty real
	 * notifications under a 404, which caching layers and crawlers are entitled to treat as missing.
	 *
	 * Core HivePress account pages have the same behaviour, confirmed on staging 2026-07-31 for
	 * /account/listings/page/2/. This corrects it only for the routes this plugin owns; fixing it
	 * everywhere is core's to do.
	 *
	 * Runs at priority 1 on template_redirect, which is after wp() has decided and before any
	 * output, so status_header() still reaches the browser.
	 */
	public function fix_paged_status() {
		if ( ! is_404() ) {
			return;
		}

		if ( ! in_array( hivepress()->router->get_current_route_name(), [ 'notifications_view_page', 'notification_settings_page' ], true ) ) {
			return;
		}

		global $wp_query;

		$wp_query->is_404 = false;

		status_header( 200 );
	}

	/**
	 * Restores the hp-template body classes on the plugin's account pages.
	 *
	 * Core resolves a template class from the route name (components/class-template.php:220-227),
	 * so once the template classes carry the Hpnf_ prefix that lookup fails and both pages would
	 * silently lose their hp-template classes, taking the theme's account-page styling with them.
	 * The list matches the class set core derived before the rename.
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public function add_template_classes( $classes ) {
		$route = hivepress()->router->get_current_route_name();

		$leaves = [
			'notifications_view_page'    => 'hp-template--notifications-view-page',
			'notification_settings_page' => 'hp-template--notification-settings-page',
		];

		if ( isset( $leaves[ $route ] ) ) {
			$classes = array_merge(
				$classes,
				[
					'hp-template',
					'hp-template--page-sidebar-left',
					'hp-template--user-account-page',
					$leaves[ $route ],
				]
			);
		}

		return $classes;
	}

	/**
	 * Adds the notifications item to the account menu.
	 *
	 * @param array $menu Menu arguments.
	 * @return array
	 */
	public function alter_user_account_menu( $menu ) {
		$menu['items']['notifications_view'] = [
			'route'  => 'notifications_view_page',
			'_order' => 25,
		];

		// Get count.
		$count = $this->get_unread_count( get_current_user_id() );

		if ( $count ) {
			$menu['items']['notifications_view']['meta'] = number_format_i18n( $count );
		}

		return $menu;
	}

	/**
	 * Removes the notification assets for signed-out visitors.
	 *
	 * Notifications are only ever shown to a signed-in user, so there's no reason to make everyone
	 * else download the script and stylesheet.
	 *
	 * @param array $assets Asset configurations.
	 * @return array
	 */
	public function alter_assets( $assets ) {
		if ( ! is_user_logged_in() ) {
			unset( $assets['notifications_frontend'] );
		}

		return $assets;
	}

	/**
	 * Enqueues the notification assets.
	 *
	 * The pop-up data can't be printed into the page because a page cache would serve one user's
	 * notifications to another, so the script fetches the queue after the page has loaded.
	 */
	public function enqueue_scripts() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Add styles. These also cover the notification list, so they're added whether or not
		// pop-ups are enabled.
		$styles = $this->get_inline_styles();

		if ( $styles ) {
			wp_add_inline_style( 'hivepress-notifications', $styles );
		}

		// Icons are inline SVG on the front end, so what the page needs is FAFH's
		// tiny sizing sheet and NOT the ~234 KB webfont. This used to load the
		// webfont whenever the BELL icon was an extended name, which also meant
		// three notification type icons that are FA6/7 names -- magnifying-glass,
		// arrow-trend-up and arrow-trend-down -- drew an empty tile on any site
		// whose bell was a plain FA5 name. Inline SVG fixes that as a side effect.
		if ( class_exists( 'FAFH' ) ) {
			\FAFH::enqueue_style();
		} elseif ( get_option( 'hp_notification_bell' ) && $this->is_extended_icon( $this->get_bell_icon() ) ) {
			// Fallback only: without the library there is no SVG to draw.
			$this->register_fontawesome();
		}

		// Add script data. This is localized whether or not pop-ups are enabled, because the
		// notification list uses the same endpoints to mark notifications as read and delete them.
		//
		// Everything sits one level down, under "config", because wp_localize_script() casts every
		// top-level scalar to a string (class-wp-scripts.php:655-663): false becomes "", true
		// becomes "1" and 6 becomes "6". The booleans survive that only by luck, "" being falsy,
		// which is one strict comparison away from a bug that exists only in the browser. Values
		// nested one level deep keep their real types.
		wp_localize_script(
			'hivepress-notifications',
			'hpNotificationsData',
			[
				'config' => [
					// Canonical name => "viewBox|path" for the icons the script
					// draws itself. Payload icons are NOT here: they arrive in the
					// REST envelope that references them, because a notification
					// type can name any icon and the types filter is public.
					'icons'          => $this->get_icon_pairs( self::SCRIPT_ICONS ),
					'apiURL'         => esc_url_raw( rest_url( 'hivepress/v1' ) ),
					'apiNonce'       => wp_create_nonce( 'wp_rest' ),
					'toasts'         => (bool) get_option( 'hp_notification_toasts', true ),
					'position'       => (string) get_option( 'hp_notification_toast_position', 'bottom-left' ),
					'positionMobile' => (string) get_option( 'hp_notification_toast_position_mobile', 'bottom' ),
					// Gated on the bell, like the two hide-counter settings. Its row is a "_parent" child
					// of Header Bell, so it disappears from the screen when the bell is switched off,
					// but the stored value stays behind: without this check an admin who tried the bell
					// with a sticky header and then changed their mind kept a header pinned to the top
					// of every page, with the tick box that would undo it no longer on the screen.
					'sticky'         => (bool) get_option( 'hp_notification_bell' ) && (bool) get_option( 'hp_notification_sticky_header' ),
					'stickyGlass'    => (bool) get_option( 'hp_notification_bell' ) && (bool) get_option( 'hp_notification_sticky_header' ) && (bool) get_option( 'hp_notification_sticky_glass' ),
					'glassOpacity'   => max( 10, min( 100, (int) get_option( 'hp_notification_sticky_glass_opacity', 72 ) ) ),
					'glassBlur'      => max( 0, min( 60, (int) get_option( 'hp_notification_sticky_glass_blur', 20 ) ) ),
					'autohide'       => (bool) get_option( 'hp_notification_toast_autohide', true ),
					'duration'       => max( 1, absint( get_option( 'hp_notification_toast_duration', 6 ) ) ),
					'limit'          => max( 1, absint( get_option( 'hp_notification_toast_limit', 3 ) ) ),
					'closeText'      => esc_html__( 'Close', 'notifications-for-hivepress' ),
					'viewText'       => esc_html__( 'View', 'notifications-for-hivepress' ),

					/*
					 * Both plural forms and the empty state, so the page header can be rewritten in the
					 * reader's language when a notification is marked read without a reload.
					 *
					 * These carry a gettext context rather than only a translator comment. English
					 * spells both forms the same way, so without a context they collapse into one POT
					 * entry with two contradictory comments, and a language that does need different
					 * forms has no way to supply them.
					 */
					/* translators: %s: number of unread notifications. */
					'unreadText'     => esc_html_x( '%s unread', 'plural', 'notifications-for-hivepress' ),
					/* translators: %s: number of unread notifications, always one here. */
					'unreadOneText'  => esc_html_x( '%s unread', 'singular', 'notifications-for-hivepress' ),
					'caughtUpText'   => esc_html__( 'All caught up', 'notifications-for-hivepress' ),
					'readText'       => esc_html__( 'Mark as read', 'notifications-for-hivepress' ),

					// Both halves of the tick's label, so it can say what the next click will do.
					'markUnreadText' => esc_html__( 'Mark as unread', 'notifications-for-hivepress' ),
					'deleteText'     => esc_html__( 'Delete notification', 'notifications-for-hivepress' ),

					// The heading the script gives the group it creates for a notification that arrives
					// while the page is open, matching the one the block prints for today.
					'todayText'      => esc_html__( 'Today', 'notifications-for-hivepress' ),
					'deletedText'    => esc_html__( 'Notification deleted.', 'notifications-for-hivepress' ),
					'undoText'       => esc_html__( 'Undo', 'notifications-for-hivepress' ),
					'soundStyle'     => (string) get_option( 'hp_notification_sound_style', 'chime' ),
					'emptyText'      => esc_html__( 'Nothing new.', 'notifications-for-hivepress' ),
					'errorText'      => esc_html__( 'These could not be loaded. Please try again in a moment.', 'notifications-for-hivepress' ),
					'sound'          => (bool) get_option( 'hp_notification_sound' ),
					'poll'           => absint( get_option( 'hp_notification_poll', 60 ) ),
					'push'           => $this->get_push_data(),
				],
			]
		);
	}

	/**
	 * Gets the data the browser needs to subscribe to push.
	 *
	 * @return mixed
	 */
	protected function get_push_data() {
		if ( ! hivepress()->hpnf_notification_push || ! hivepress()->hpnf_notification_push->is_enabled() ) {
			return null;
		}

		$keys = hivepress()->hpnf_notification_push->get_keys();

		if ( ! $keys ) {
			return null;
		}

		// A cleared number field stores '' and (int) '' is 0, which would mean "ask on the very
		// first visit" - the one thing this delay exists to prevent. Only a numeric stored value
		// counts; anything else falls back to the default. An explicit 0 is numeric and honoured.
		$delay = get_option( 'hp_notification_push_delay', 3 );
		$delay = is_numeric( $delay ) ? max( 0, (int) $delay ) : 3;

		return [
			'key'    => $keys['public'],
			'worker' => esc_url_raw( add_query_arg( 'hp_notification_worker', '1', home_url( '/' ) ) ),

			// Asking for permission on page load is how a site gets blocked for good. The prompt
			// waits until someone has been around long enough to know what the site is.
			'delay'  => $delay,
			'views'  => 'hp_notification_views',
		];
	}

	/**
	 * Renders the notification bell in the site header.
	 *
	 * ListingHive already shows a red count on the burger, but that counts unread messages, unpaid
	 * bookings and pending orders. These notifications mirror the same events, so adding to that
	 * count would count everything twice. The bell is therefore its own control with its own count.
	 *
	 * @param string $output Area output.
	 * @return string
	 */
	public function render_bell( $output ) {
		if ( ! is_user_logged_in() || ! get_option( 'hp_notification_bell' ) ) {
			return $output;
		}

		// Get count.
		$count = $this->get_unread_count( get_current_user_id() );

		// Render bell.
		$output .= '<div class="hp-notification-bell" data-component="notification-bell">';

		$output .= '<a href="' . esc_url( hivepress()->router->get_url( 'notifications_view_page' ) ) . '" class="hp-notification-bell__toggle" aria-haspopup="true" aria-expanded="false" aria-label="' . esc_attr__( 'Notifications', 'notifications-for-hivepress' ) . '">';
		$output .= $this->get_icon_markup( $this->get_bell_icon() );

		if ( $count ) {
			$output .= '<small>' . esc_html( number_format_i18n( $count ) ) . '</small>';
		}

		$output .= '</a>';

		// The panel is filled in on first open, so a page view costs nothing extra.
		$output .= '<div class="hp-notification-bell__panel" hidden>';
		$output .= '<div class="hp-notification-bell__header"><span>' . esc_html__( 'Notifications', 'notifications-for-hivepress' ) . '</span>';
		$output .= '<a href="' . esc_url( hivepress()->router->get_url( 'notifications_view_page' ) ) . '">' . esc_html__( 'See all', 'notifications-for-hivepress' ) . '</a></div>';
		$output .= '<div class="hp-notification-bell__body" data-component="notification-bell-body"><div class="hp-notification-bell__loading">' . esc_html__( 'Loading…', 'notifications-for-hivepress' ) . '</div></div>';
		$output .= '</div>';

		$output .= '</div>';

		return $output;
	}

	/**
	 * Gets the appearance styles set in the admin.
	 *
	 * @return string
	 */
	protected function get_inline_styles() {
		$styles = [
			'--hp-notification-background' => sanitize_hex_color( (string) get_option( 'hp_notification_toast_background_color' ) ),
			'--hp-notification-text'       => sanitize_hex_color( (string) get_option( 'hp_notification_toast_text_color' ) ),
			'--hp-notification-accent'     => sanitize_hex_color( (string) get_option( 'hp_notification_toast_accent_color' ) ),
		];

		// Get the four bell colours: the icon and the circle behind it, each resting and hovered.
		// None of them has a default, so an untouched bell inherits its colour from the header it
		// sits in - see the "inherit" fallback in frontend.css. Forcing a near-black here made the
		// bell all but invisible on the themes with a dark header, JobHive's #012132 among them, on
		// sites whose owner had never opened Appearance.
		$bell_colors = [
			'--hp-notification-bell-color'            => sanitize_hex_color( (string) get_option( 'hp_notification_bell_color' ) ),
			'--hp-notification-bell-color-hover'      => sanitize_hex_color( (string) get_option( 'hp_notification_bell_color_hover' ) ),
			'--hp-notification-bell-background'       => sanitize_hex_color( (string) get_option( 'hp_notification_bell_background' ) ),
			'--hp-notification-bell-background-hover' => sanitize_hex_color( (string) get_option( 'hp_notification_bell_background_hover' ) ),
		];

		foreach ( array_filter( $bell_colors ) as $name => $value ) {
			$styles[ $name ] = $value;
		}

		// Get the unread badge colour. The default matches the red HivePress uses for its own menu
		// counts, so an untouched site looks like one plugin rather than two.
		$badge_color = sanitize_hex_color( (string) get_option( 'hp_notification_badge_color', self::BADGE_COLOR ) );

		if ( $badge_color ) {
			$styles['--hp-notification-badge'] = $badge_color;
		}

		// Get the dropdown width.
		$panel = absint( get_option( 'hp_notification_panel_width', 320 ) );

		if ( $panel ) {
			$styles['--hp-notification-panel-width'] = min( max( $panel, 280 ), 420 ) . 'px';
		}

		// The leading picture's shape. The variable holds the radius rather than the name, so the
		// stylesheet needs no knowledge of the choices.
		// "0px" rather than "0": the whole array is run through array_filter() below, which drops a
		// bare "0" as falsy and would have quietly left square corners rendering as circles.
		$shapes = [
			'circle'  => '50%',
			'rounded' => '8px',
			'square'  => '0px',
		];

		$shape = (string) get_option( 'hp_notification_icon_shape', 'circle' );

		if ( isset( $shapes[ $shape ] ) && 'circle' !== $shape ) {
			$styles['--hp-notification-icon-radius'] = $shapes[ $shape ];
		}

		// Get the bell size.
		$bell = absint( get_option( 'hp_notification_bell_size', 17 ) );

		if ( $bell ) {
			$styles['--hp-notification-bell-size'] = $bell . 'px';
		}

		// Nudge the bell. These are signed, so intval rather than absint, and clamped to a range
		// that can only ever tidy alignment - enough to line the bell up with whatever a theme has
		// put beside it, not enough to move it somewhere it does not belong.
		foreach ( [
			'x' => 'hp_notification_bell_offset_x',
			'y' => 'hp_notification_bell_offset_y',
		] as $axis => $option ) {
			$offset = intval( get_option( $option, 0 ) );

			if ( $offset ) {
				$styles[ '--hp-notification-bell-offset-' . $axis ] = max( -30, min( 30, $offset ) ) . 'px';
			}
		}

		// Get the text size and weight.
		$size = absint( get_option( 'hp_notification_toast_text_size', 14 ) );

		if ( $size ) {
			$styles['--hp-notification-font-size'] = $size . 'px';
		}

		$weight = absint( get_option( 'hp_notification_toast_text_weight', 400 ) );

		if ( $weight ) {
			$styles['--hp-notification-font-weight'] = (string) $weight;
		}

		// Get radius. The default is repeated here and not just declared in the config, because
		// HivePress seeds its options on an admin request and this method runs on the front end:
		// before that first admin page load get_option() returns false, which passes the guard
		// below and emits 0px, so every corner squares off while the settings box still reads 3.
		$radius = get_option( 'hp_notification_toast_radius', 3 );

		if ( '' !== $radius && ! is_null( $radius ) ) {
			$styles['--hp-notification-radius'] = absint( $radius ) . 'px';
		}

		// Filter styles.
		$styles = array_filter( $styles );

		// Get output. The rules below are appended whether or not any variable was set, so a site
		// that only ticked "Hide Theme Counter" still gets its rule.
		$output = '';

		foreach ( $styles as $name => $value ) {
			$output .= $name . ':' . $value . ';';
		}

		if ( $output ) {
			$output = ':root{' . $output . '}';
		}

		/*
		 * Round this extension's own buttons, and only when a radius was actually entered.
		 *
		 * Written as a whole rule rather than a custom property on purpose. A property would mean
		 * "border-radius: var( --hp-notification-button-radius )" sitting in the stylesheet
		 * permanently, and an undefined custom property makes that declaration invalid at
		 * computed-value time - which resolves to the inherited value, NOT to whatever the theme
		 * set. So the unset case would square every button off instead of leaving it alone, the
		 * exact opposite of the intent. Emitting nothing emits nothing.
		 *
		 * Two classes in each selector because both really are on the element: it beats a theme's
		 * single-class ".button--secondary" without an !important, and reaches only the buttons
		 * this extension puts on the page - the account pages, the push prompt and the undo
		 * action. Core's own buttons elsewhere are none of our business.
		 */
		$button_radius = get_option( 'hp_notification_button_radius' );

		if ( '' !== $button_radius && ! is_null( $button_radius ) && false !== $button_radius ) {

			// The last selector is the Save Changes button on the member's Notification Settings
			// page: it is core's own form button, so it carries no class of ours, and scoping
			// through the form's generated class (hp-form--{name}, class-form.php:226) reaches
			// that one button without touching any other HivePress form on the site.
			$output .= '.hp-button.hp-notifications__action,.hp-button.hp-notifications__filter-submit,.hp-notification-push .hp-button,.hp-notification-undo .hp-button,.hp-form--hpnf-notification-update .hp-form__button{border-radius:' . absint( $button_radius ) . 'px;}';
		}

		/*
		 * The bell icon's weight. Font Awesome ships one solid weight, so "bolder" is drawn as a
		 * thin stroke around the glyph in currentColor - which is what makes it follow the bell
		 * colour settings, hover included, without any colour plumbing of its own. paint-order
		 * keeps the stroke behind the fill so the glyph does not thin out. Normal emits nothing.
		 */
		$strokes = [
			'semibold' => '0.3px',
			'bold'     => '0.5px',
		];

		$weight_option = (string) get_option( 'hp_notification_bell_weight', 'normal' );

		if ( get_option( 'hp_notification_bell' ) && isset( $strokes[ $weight_option ] ) ) {
			/*
			 * Two declarations because there are two renderers. -webkit-text-stroke
			 * thickens a FONT glyph and does nothing to an SVG; stroke/stroke-width do
			 * the reverse. Both are inherited, so the <i> carries them and whichever
			 * one drew the bell picks its pair up. The SVG's path sets
			 * vector-effect="non-scaling-stroke", without which stroke-width would be
			 * read in user units (1/512 em, every Font Awesome viewBox being
			 * "0 0 W 512") and 0.3px would be invisible.
			 */
			$output .= '.hp-notification-bell .hp-notification-bell__toggle i{-webkit-text-stroke:' . $strokes[ $weight_option ] . ' currentColor;stroke:currentColor;stroke-width:' . $strokes[ $weight_option ] . ';paint-order:stroke fill;}';
		}

		/*
		 * The pinned header's corner radii, one option per corner because one linked value cannot
		 * round only the visible bottom edge. Emitted as a whole rule only when a corner is set,
		 * so an untouched site ships no rule at all - same reasoning as the button radius above.
		 * A cleared number field stores '', so anything non-numeric counts as 0. The glass
		 * overlay follows via border-radius:inherit in the stylesheet.
		 */
		if ( get_option( 'hp_notification_bell' ) && get_option( 'hp_notification_sticky_header' ) ) {
			$corners = [];

			// Shorthand order: top-left, top-right, bottom-right, bottom-left.
			foreach ( [
				'hp_notification_sticky_radius_top_left',
				'hp_notification_sticky_radius_top_right',
				'hp_notification_sticky_radius_bottom_right',
				'hp_notification_sticky_radius_bottom_left',
			] as $option ) {
				$value = get_option( $option, 0 );

				$corners[] = ( is_numeric( $value ) ? max( 0, min( 40, (int) $value ) ) : 0 ) . 'px';
			}

			$radius = implode( ' ', $corners );

			if ( '0px 0px 0px 0px' !== $radius ) {
				$output .= '.hp-nfh-sticky{border-radius:' . $radius . ';}';
			}
		}

		// Recolour the account menu count, but only when the admin has actually chosen a colour
		// other than the HivePress default. Left alone, the count inherits core's own styling and
		// therefore matches every other count in the menu, including under a theme that restyles
		// them. The selector carries one extra class so a deliberate choice still wins against a
		// theme stylesheet that loads after this one.
		if ( $badge_color && self::BADGE_COLOR !== strtolower( $badge_color ) ) {
			$output .= '.hp-menu .hp-menu__item--notifications-view small{background-color:' . $badge_color . ';}';
		}

		// Hide HivePress's own counters, each of which is a different number.
		//
		// The combined one is the "notice_count" request context: Messages adds unread messages to
		// it, Bookings adds unpaid bookings and booking requests, Marketplace adds pending orders.
		// Core prints it beside the user's name in the header
		// (templates/user/login/user-login-link.php:10) and ListingHive prints the same value on
		// the burger (header.php:38), so both need hiding together or the number simply moves.
		// Descendant combinators throughout: HiveTheme's own rule is
		// ".header-navbar__burger>a small", so the count is not always a direct child of the link
		// and a child combinator quietly matched nothing.
		// Both are read only while the bell itself is on. HivePress's "_parent" argument hides a
		// child row in the admin but leaves the stored value alone, so without this guard an admin
		// who ticked Hide Combined Counter and later unticked Header Bell lost HivePress's own
		// unread count for good: the bell is gone, core's count is still suppressed, and the
		// setting that would bring it back is no longer on the screen to untick.
		$hidden = [];

		if ( get_option( 'hp_notification_bell' ) ) {
			if ( get_option( 'hp_notification_bell_hide_count' ) ) {
				$hidden[] = '.hp-menu__item--user-account small';

				// Scoped to the burger's own link. The burger also contains the whole drop-down
				// menu, so a plain descendant match reached the per-item counts inside it and hid
				// the Notifications and Messages numbers as well - the opposite of what this
				// setting says. The count is not always a direct child of that link, hence "> a".
				$hidden[] = '.header-navbar__burger > a small';
			}

			// The Messages count is a separate, narrower number: unread messages only, shown on the
			// Messages item of the account menu (messages/components/class-message.php:417, item
			// key "messages_thread"). Hiding it is a different decision from hiding the combined
			// total, which is why it is its own setting.
			if ( get_option( 'hp_notification_bell_hide_message_count' ) ) {
				$hidden[] = '.hp-menu__item--messages-thread small';
			}
		}

		if ( $hidden ) {

			/**
			 * Filters the selectors used to hide HivePress's own unread counters. Themes outside
			 * the official six put these elsewhere; point this at whatever holds them.
			 *
			 * @hook hivepress/v1/notification_hide_count_selector
			 * @param {string} $selector CSS selector.
			 * @return {string} CSS selector.
			 */
			$selector = apply_filters( 'hivepress/v1/notification_hide_count_selector', implode( ', ', $hidden ) );

			if ( $selector ) {
				$output .= $selector . '{display:none !important;}';
			}
		}

		return $output;
	}

	/**
	 * Deletes notifications that are older than the storage period.
	 *
	 * The HivePress comment query maps field filters onto the query variables that WP_Comment_Query
	 * supports, and there's no variable for comparing comment_date, so the date range is passed as
	 * a date query instead. A field filter such as "created_date__lt" would be dropped silently and
	 * match every notification.
	 */
	public function delete_notifications() {
		$period = absint( get_option( 'hp_notification_storage_period' ) );

		if ( ! $period ) {
			return;
		}

		// Comment dates are stored on the site's clock, so the cutoff has to be built from the
		// same clock rather than UTC.
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $period * DAY_IN_SECONDS );

		// Delete in batches until none are left. A site that expires more than one batch a day
		// would otherwise build a backlog that a single capped run could never catch up on. The
		// batch count is still bounded, so one cron run can't stall on an enormous backlog.
		$user_ids = [];
		$model    = new Models\Hpnf_Notification();

		for ( $batch = 0; $batch < 20; $batch++ ) {

			// Get comments.
			$comments = get_comments(
				[
					'type'       => 'hp_notification',
					'number'     => 500,
					'orderby'    => 'comment_date',
					'order'      => 'ASC',

					'date_query' => [
						[
							'before'    => $cutoff,
							'inclusive' => false,
							'column'    => 'comment_date',
						],
					],
				]
			);

			if ( ! $comments ) {
				break;
			}

			// Delete notifications.
			foreach ( $comments as $comment ) {
				$user_ids[] = (int) $comment->user_id;

				$model->delete( absint( $comment->comment_ID ) );
			}

			if ( count( $comments ) < 500 ) {
				break;
			}
		}

		// Update counters and type lists.
		foreach ( array_unique( array_filter( $user_ids ) ) as $user_id ) {
			$this->update_unread_count( $user_id );
			$this->rebuild_used_types( $user_id );
		}
	}
}
