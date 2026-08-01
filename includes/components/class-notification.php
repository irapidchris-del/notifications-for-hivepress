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
final class Notification extends Component {

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

		// Add the colour picker on the settings screen.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_color_picker' ] );

		// Delete notifications. The daily event is scheduled by the HivePress scheduler component,
		// so this only attaches to it.
		add_action( 'hivepress/v1/events/daily', [ $this, 'delete_notifications' ] );

		if ( ! is_admin() ) {

			// Answer 200 rather than 404 on page two of the list.
			add_action( 'template_redirect', [ $this, 'fix_paged_status' ], 1 );

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
			foreach ( hivepress()->get_classes( 'emails' ) as $name => $class ) {
				$label = call_user_func( [ $class, 'get_meta' ], 'label' );

				$types[ $name ] = [
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
				/* translators: %1$s: user name, %2$s: listing title. */
				'text'     => esc_html__( '%user.display_name% added %listing.title% to their favourites.', 'notifications-for-hivepress' ),
				'tokens'   => [ 'user', 'listing', 'listing_title', 'listing_url' ],
				'channels' => [ 'onsite', 'push' ],
				'icon'     => 'heart',
			];
		}

		if ( class_exists( '\HivePress\Models\Booking' ) ) {
			$types['booking_complete'] = [
				'label'     => esc_html__( 'Booking Completed', 'notifications-for-hivepress' ),
				'text'      => esc_html__( 'Your booking for %listing.title% is done. How did it go?', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'Leave a review', 'notifications-for-hivepress' ),
				'tokens'    => [ 'user', 'listing', 'booking', 'listing_title', 'listing_url' ],
				'channels'  => [ 'onsite', 'push' ],
			];
		}

		if ( class_exists( '\HivePress\Models\Review' ) ) {
			$types['listing_review'] = [
				'label'    => esc_html__( 'Review Received', 'notifications-for-hivepress' ),
				'text'     => esc_html__( '%author.display_name% reviewed %listing.title%.', 'notifications-for-hivepress' ),
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
				'text'      => esc_html__( 'Congratulations! You have earned the %badge.name% badge. Keep up the good work, %user.display_name%!', 'notifications-for-hivepress' ), // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText
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
			'account'     => esc_html__( 'Account', 'notifications-for-hivepress' ),
			'other'       => esc_html__( 'Other', 'notifications-for-hivepress' ),
		];
	}

	/**
	 * Gets the group of a notification type.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public function get_type_group( $type ) {
		$groups = [
			'listing'    => 'listings',
			'message'    => 'messages',
			'booking'    => 'bookings',
			'order'      => 'orders',
			'payout'     => 'orders',
			'request'    => 'requests',
			'offer'      => 'requests',
			'membership' => 'memberships',
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

		return trim( wp_strip_all_tags( hp\replace_tokens( (array) $tokens, $text ) ) );
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
			} else {
				$enabled = array_merge( $enabled, array_diff( $group_types, [ 'user_password_request', 'user_email_verify' ] ) );
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
		$new = array_diff( $optional, $known, [ 'user_password_request', 'user_email_verify' ] );

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
			if ( ! isset( $seeded[ $recipient_id ] ) && Models\Notification::query()->filter( [ 'user' => $recipient_id ] )->get_first_id() ) {
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

			$text = $this->render_text( $this->get_type_text( 'message_send' ), array_filter( $tokens ) );

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
	 * Handles an email that's being sent.
	 *
	 * Decides which channels the recipient wants for this type, stops the email if they've turned
	 * it off, and adds the on-site notification if they haven't.
	 *
	 * @param object $email Email object.
	 */
	public function process_email( $email ) {

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

		// Stop the email if the recipient turned it off. The body is deliberately left alone,
		// because emptying it is how HivePress itself disables an email and that would also hide
		// whether there was anything to send.
		if ( ! in_array( 'email', $channels, true ) ) {
			$this->suppressed = [
				'recipient' => $this->get_recipient( $email ),
				'subject'   => (string) $email->get_subject(),
			];
		}

		if ( ! in_array( 'onsite', $channels, true ) ) {
			return;
		}

		// Get tokens.
		$tokens = (array) $email->get_tokens();

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
		$notification = ( new Models\Notification() )->fill(
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
	protected function get_user_image( $user ) {
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

		// Get tokens.
		$tokens = [
			'user'          => $booking->get_user(),
			'listing'       => $listing,
			'booking'       => $booking,
			'listing_title' => $listing->get_title(),
			'listing_url'   => (string) get_permalink( $listing->get_id() ),
		];

		$tokens = array_filter( $tokens );

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
		$tokens = array_filter(
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

		$listing_url = (string) get_permalink( $listing->get_id() );

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

		$tokens = array_filter( $tokens );

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
			'type'       => $this->get_type_label( $notification->get_type() ),
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

		// Get labels.
		$labels = [];

		foreach ( $types as $type ) {
			$labels[ $type ] = $this->get_type_label( $type );
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
		$count = Models\Notification::query()->filter(
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
		if ( hivepress()->notification_push && hivepress()->notification_push->is_enabled() ) {
			$channels['push'] = esc_html__( 'Push', 'notifications-for-hivepress' );
		}

		return apply_filters( 'hivepress/v1/notification_channels', $channels );
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
	 * Enqueues the colour picker on the settings screen.
	 *
	 * The bell icon field needs nothing from us: "options => icons" hands it to HivePress's own
	 * picker, the same Select2 control with live previews that core uses for attribute icons, and
	 * both Select2 and core's JS are enqueued in wp-admin as well as on the front end. A
	 * hand-rolled picker here would only ever offer a stale, shorter list.
	 *
	 * Colours are the opposite case: core's Color field is a plain input and core ships no picker
	 * at all, so the Iris picker is ours to add. The field still works and saves without it.
	 */
	public function enqueue_color_picker() {

		// Only load on the HivePress settings screen. The tab is not checked because the script is
		// a no-op unless its fields are present.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'hp_settings' !== sanitize_key( (string) hp\get_array_value( $_GET, 'page' ) ) ) {
			return;
		}

		$path = plugin_dir_path( HP_NOTIFICATIONS_FILE );
		$url  = plugin_dir_url( HP_NOTIFICATIONS_FILE );

		// The file time rides along in the version so caches refresh whenever the file changes.
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'hp-notification-admin-colors', $url . 'assets/js/admin-colors.js', [ 'jquery', 'wp-color-picker' ], HP_NOTIFICATIONS_VERSION . '.' . (int) filemtime( $path . 'assets/js/admin-colors.js' ), true );
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
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function get_role_channels( $user_id ) {
		$channels = array_keys( $this->get_channels() );

		// Get user.
		$user = get_userdata( $user_id );

		if ( ! $user || ! $user->roles ) {
			return $channels;
		}

		// Take the first role that has defaults set.
		foreach ( (array) $user->roles as $role ) {
			$default = get_option( 'hp_notification_default_' . $role );

			if ( is_array( $default ) ) {
				return array_values( array_intersect( $channels, $default ) );
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

			$settings['notifications']['sections']['types']['fields'][ 'notification_types_' . $group ] = [
				'label'   => $group_label,
				'type'    => 'checkboxes',
				'options' => $options,
				'default' => array_diff( array_keys( $options ), [ 'user_password_request', 'user_email_verify' ] ),
				'_order'  => $order,
			];

			$order += 5;
		}

		// Add the per-role defaults, in their own section so they are not mistaken for more types.
		// Only the first carries the long explanation; repeating it on every role turned the screen
		// into a wall of identical paragraphs.
		if ( isset( $settings['notifications']['sections']['defaults'] ) ) {
			$order = 10;
			$note  = esc_html__( 'These are WordPress roles. HivePress promotes someone to Contributor when their vendor profile is published, so on most sites your vendors are Contributors and everyone else keeps the default role from Settings. Unticking every box here restores all channels rather than none; to switch a notification off for everyone, untick it under Types.', 'notifications-for-hivepress' );

			foreach ( wp_roles()->get_names() as $role => $label ) {
				$field = [
					/* translators: %s: role name. */
					'label'   => sprintf( esc_html__( '%s Defaults', 'notifications-for-hivepress' ), translate_user_role( $label ) ),
					'type'    => 'checkboxes',
					'options' => $this->get_channels(),
					'default' => array_keys( $this->get_channels() ),
					'_order'  => $order,
				];

				if ( $note ) {
					$field['description'] = $note;
					$note                 = '';
				}

				$settings['notifications']['sections']['defaults']['fields'][ 'notification_default_' . $role ] = $field;

				$order += 10;
			}
		}

		// Add the text fields.
		if ( ! isset( $settings['notifications']['sections']['text'] ) ) {
			return $settings;
		}

		$order = 10;

		foreach ( $this->get_enabled_types() as $type ) {
			$args = $this->get_type_args( $type );

			// System types have no fixed wording to customise.
			if ( hp\get_array_value( $args, '_system' ) ) {
				continue;
			}

			$settings['notifications']['sections']['text']['fields'][ 'notification_text_' . $type ] = [
				'label'       => $this->get_type_label( $type ),
				'description' => $this->get_token_hint( $type ),
				'type'        => 'text',
				'max_length'  => 256,
				'placeholder' => hp\get_array_value( $args, 'text' ) ? $args['text'] : esc_html__( 'The email subject is used', 'notifications-for-hivepress' ),
				'_order'      => $order,
			];

			$order += 10;
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

		if ( ! $tokens ) {
			return esc_html__( 'The tokens for this notification are listed here once it has been sent for the first time. Until then, plain text works, and leaving this empty uses the email subject.', 'notifications-for-hivepress' );
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
			/* translators: %s: comma-separated list of tokens. */
			esc_html__( 'Tokens: %s. Replace "field" with the one you want, such as %%listing.title%% or %%sender.display_name%%. A fallback goes after a pipe, such as %%listing.title|your listing%%.', 'notifications-for-hivepress' ),
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

		// Add script data. This is localized whether or not pop-ups are enabled, because the
		// notification list uses the same endpoints to mark notifications as read and delete them.
		wp_localize_script(
			'hivepress-notifications',
			'hpNotificationsData',
			[
				'apiURL'         => esc_url_raw( rest_url( 'hivepress/v1' ) ),
				'apiNonce'       => wp_create_nonce( 'wp_rest' ),
				'toasts'         => (bool) get_option( 'hp_notification_toasts', true ),
				'position'       => (string) get_option( 'hp_notification_toast_position', 'bottom-left' ),
				'positionMobile' => (string) get_option( 'hp_notification_toast_position_mobile', 'bottom' ),
				'sticky'         => (bool) get_option( 'hp_notification_sticky_header' ),
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
				'sound'          => (bool) get_option( 'hp_notification_sound' ),
				'poll'           => absint( get_option( 'hp_notification_poll', 60 ) ),
				'push'           => $this->get_push_data(),
			]
		);
	}

	/**
	 * Gets the data the browser needs to subscribe to push.
	 *
	 * @return mixed
	 */
	protected function get_push_data() {
		if ( ! hivepress()->notification_push || ! hivepress()->notification_push->is_enabled() ) {
			return null;
		}

		$keys = hivepress()->notification_push->get_keys();

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
		$output .= '<i class="hp-icon fas fa-' . esc_attr( $this->get_bell_icon() ) . '"></i>';

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
		// Only the resting icon colour has a default; the rest are emitted only when chosen, so an
		// untouched bell keeps the look it has always had.
		$bell_colors = [
			'--hp-notification-bell-color'            => sanitize_hex_color( (string) get_option( 'hp_notification_bell_color', '#1a1a1a' ) ),
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

		// Get radius.
		$radius = get_option( 'hp_notification_toast_radius' );

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
		$hidden = [];

		if ( get_option( 'hp_notification_bell_hide_count' ) ) {
			$hidden[] = '.hp-menu__item--user-account small';

			// Scoped to the burger's own link. The burger also contains the whole drop-down menu,
			// so a plain descendant match reached the per-item counts inside it and hid the
			// Notifications and Messages numbers as well - the opposite of what this setting says.
			// The count itself is not always a direct child of that link, hence "> a small".
			$hidden[] = '.header-navbar__burger > a small';
		}

		// The Messages count is a separate, narrower number: unread messages only, shown on the
		// Messages item of the account menu (messages/components/class-message.php:417, item key
		// "messages_thread"). Hiding it is a different decision from hiding the combined total,
		// which is why it is its own setting.
		if ( get_option( 'hp_notification_bell_hide_message_count' ) ) {
			$hidden[] = '.hp-menu__item--messages-thread small';
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
		$model    = new Models\Notification();

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
