<?php
/**
 * Notification extensions component.
 *
 * @package HivePress\Notifications\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Turns events from the companion extensions into on-site notifications.
 *
 * Everything HivePress and its official extensions send goes through an email class, and the main
 * component mirrors all of those automatically. The plugins handled here send no email, so their
 * events are invisible unless something listens for them, and the most important of them are
 * invisible in the interface too: a listing held for review simply does not appear, a gallery hidden
 * by the photo check simply goes, and listings that expired during a holiday quietly stay hidden.
 *
 * Each event is delivered through the same machinery as everything else, so the site owner gets a
 * tick box and a wording field for it, each person gets it in their own settings, and quiet hours,
 * pop-ups and push all behave exactly as they do for a booking or a message.
 *
 * Types are registered through the public `hivepress/v1/notification_types` filter rather than by
 * editing the main component, which keeps this whole feature in one file that can be read, and
 * proves the extension point works.
 */
final class Hpnf_Notification_Extensions extends Component {

	/**
	 * How long a run of blocked submissions is counted over.
	 *
	 * @var int
	 */
	const BLOCK_WINDOW = DAY_IN_SECONDS;

	/**
	 * Where the pending gallery access changes are held between digests.
	 *
	 * @var string
	 */
	const DIGEST_OPTION = 'hp_notification_gallery_access_digest';

	/**
	 * Most buyers recorded per vendor, per bucket, in one digest.
	 *
	 * @var int
	 */
	const DIGEST_LIMIT = 200;

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Register the types these extensions contribute. Priority 20 so the main component's own
		// list is complete first.
		add_filter( 'hivepress/v1/notification_types', [ $this, 'register_types' ], 20 );

		// Give the analytics summary email wording written for a notification list. It is already
		// mirrored, because an email class backs it, but with nothing of its own it falls back to
		// the email subject.
		add_filter( 'hivepress/v1/notification_default_texts', [ $this, 'register_default_texts' ] );

		/*
		 * Additional Gallery. Likes and comments are HivePress comment models, so core's own Hook
		 * component fires these; the gallery plugin needs no code for them at all. They only fire
		 * while the gallery plugin is active, because the model registry has to resolve the comment
		 * type first (components/class-hook.php:479-494), so the listeners are safe to register
		 * unconditionally.
		 */
		add_action( 'hivepress/v1/models/agl_like/create', [ $this, 'add_photo_like_notification' ], 10, 2 );
		add_action( 'hivepress/v1/models/agl_comment/create', [ $this, 'add_photo_comment_notification' ], 10, 2 );
		add_action( 'hivepress/v1/models/agl_clike/create', [ $this, 'add_comment_like_notification' ], 10, 2 );

		// Paid gallery access, and the photo review that hides a folder.
		add_action( 'hp_agl/access_purchased', [ $this, 'add_access_purchase_notification' ], 10, 4 );
		add_action( 'hp_agl/access_expiring', [ $this, 'add_access_expiring_notification' ], 10, 4 );
		add_action( 'hp_agl/access_expired', [ $this, 'add_access_expired_notification' ], 10, 3 );

		/*
		 * Priority 20, so the gallery's own daily scan - hooked at the default 10 - has already
		 * queued today's warnings by the time the digest is sent. At priority 10 the two would run in
		 * registration order, and a warning raised after the flush would sit in the queue for a whole
		 * day before anyone heard about it.
		 */
		add_action( 'hivepress/v1/events/daily', [ $this, 'send_access_digests' ], 20 );
		add_action( 'hp_agl/folder_flagged', [ $this, 'add_folder_hold_notification' ], 10, 2 );

		// Holiday Mode.
		add_action( 'holiday_mode_for_hivepress/started', [ $this, 'add_holiday_start_notification' ], 10, 2 );
		add_action( 'holiday_mode_for_hivepress/ended', [ $this, 'add_holiday_end_notification' ], 10, 3 );
		add_action( 'holiday_mode_for_hivepress/enforced', [ $this, 'add_holiday_enforced_notification' ], 10, 2 );

		// Listing Moderation.
		add_action( 'hpalm/listing_held', [ $this, 'add_moderation_hold_notification' ], 10, 4 );
		add_action( 'hpalm/limit_reached', [ $this, 'add_moderation_limit_notification' ], 10, 2 );
		add_action( 'hpalm/submission_blocked', [ $this, 'count_blocked_submission' ], 10, 2 );

		// Trust Signals. A vendor is never told they have been verified, and the badge appears on
		// their profile and every listing the moment it happens.
		add_action( 'updated_post_meta', [ $this, 'add_verified_notification' ], 10, 4 );
		add_action( 'added_post_meta', [ $this, 'add_verified_notification' ], 10, 4 );

		// A vendor who loses their membership while away cannot switch holiday mode off, and is
		// told so only if they try. Nothing else would ever reach them.
		add_action( 'hivepress/v1/events/daily', [ $this, 'check_holiday_entitlements' ] );

		parent::__construct( $args );
	}

	/**
	 * Registers the notification types these extensions contribute.
	 *
	 * Each block is guarded on a constant the plugin defines rather than on a class, because five of
	 * the six are function-based and register no HivePress model. A constant check is also free,
	 * where resolving a HivePress class runs its static init() through the autoloader and can
	 * translate before WordPress considers textdomains loadable.
	 *
	 * @param array $types Notification types.
	 * @return array
	 */
	public function register_types( $types ) {
		/*
		 * The performance types are stamped vendor-only, the same way the insights component stamps
		 * its own. Every one of them is delivered to a vendor - trust_verified goes to $owner_id,
		 * the vendor who was verified - so a member who does not sell can never receive one and
		 * should not be offered the preference.
		 *
		 * The gallery and listing types are NOT stamped. Both sets genuinely reach either side: a
		 * buyer is told when their gallery access is running out, and a comment author is told when
		 * their comment is liked or replied to.
		 */
		$performance = array_map(
			function( $args ) {
				return array_merge( $args, [ 'audience' => 'vendor' ] );
			},
			$this->get_performance_types()
		);

		$types = array_merge( $types, $this->get_gallery_types(), $this->get_listing_types(), $performance );

		/*
		 * The analytics summary is registered by its own email class, so it only needs an icon: with
		 * no prefix the icon guesser recognises, it arrives as a plain bell like everything unknown.
		 *
		 * Its group is deliberately left alone. It reads as belonging under Performance, but a
		 * type's group decides which stored option holds the admin's tick, so moving it would drop
		 * it out of the list they have saved and into a group they have never configured - which
		 * counts as "never set up" and switches the whole group on. An owner who had deliberately
		 * unticked this would find it back on after an update, with nothing to say why. The same
		 * reasoning already keeps the review types where they are.
		 */
		if ( isset( $types['hpva_analytics_summary'] ) ) {
			$types['hpva_analytics_summary']['icon'] = 'chart-line';
		}

		return $types;
	}

	/**
	 * Gets the notification types the gallery contributes.
	 *
	 * @return array
	 */
	protected function get_gallery_types() {
		if ( ! defined( 'HP_AGL_VERSION' ) ) {
			return [];
		}

		/*
		 * Two of these need events the gallery only fires from 1.8.2, and a type registered without
		 * them is worse than a missing one: it takes a tick box and a wording field on the settings
		 * screen, tells the owner it is on, and can never arrive. That is the same lying-checkbox
		 * mistake the channel list is careful to avoid.
		 *
		 * Detected by asking the component what it can do rather than by comparing HP_AGL_VERSION,
		 * because that constant has been wrong before: 1.8.1 shipped with the header reading 1.8.1
		 * and the constant still reading 1.8.0, so a version comparison would have been answered
		 * with a number that was never true. A method either exists or it does not.
		 *
		 * The component is assigned and then tested rather than checked with isset(), because
		 * HivePress's Core defines no __isset() and isset( hivepress()->x ) is always false.
		 */
		$gallery = hivepress()->agl_gallery;

		$can_flag_folders  = $gallery && method_exists( $gallery, 'review_folder_images' );
		$can_warn_expiring = $gallery && method_exists( $gallery, 'warn_expiring_access' );

		// phpcs:disable WordPress.WP.I18n.UnorderedPlaceholdersText -- these are HivePress tokens, replaced by name rather than by position. Told to order them, phpcbf rewrites %folder_title% into %1$folder_title% and the token can never match again, so the notification reaches a real person with the raw placeholder in it.
		$types = [

			'gallery_photo_like'    => [
				'label'        => esc_html__( 'Gallery Photo Liked', 'notifications-for-hivepress' ),
				/* translators: keep %user.display_name% and %folder_title% exactly as written; they are replaced with the name of the person who liked it and the gallery name. */
				'text'         => __( '%user.display_name% liked your photo in %folder_title%.', 'notifications-for-hivepress' ),
				/* translators: keep %user.display_name%, %other_count% and %folder_title% exactly as written; %other_count% becomes the number of other people and the word for them, such as "12 others". */
				'text_grouped' => __( '%user.display_name% and %other_count% liked your photo in %folder_title%.', 'notifications-for-hivepress' ),
				'link_text'    => esc_html__( 'View the photo', 'notifications-for-hivepress' ),
				'tokens'       => [ 'user', 'folder_title', 'photo_url' ],
				'channels'     => [ 'onsite', 'push' ],
				'icon'         => 'heart',
			],

			'gallery_photo_comment' => [
				'label'        => esc_html__( 'Gallery Photo Commented On', 'notifications-for-hivepress' ),
				/* translators: keep %author.display_name% and %folder_title% exactly as written; they are replaced with the name of the person who commented and the gallery name. */
				'text'         => __( '%author.display_name% commented on your photo in %folder_title%.', 'notifications-for-hivepress' ),
				/* translators: keep %author.display_name%, %other_count% and %folder_title% exactly as written; %other_count% becomes the number of other people and the word for them, such as "12 others". */
				'text_grouped' => __( '%author.display_name% and %other_count% commented on your photo in %folder_title%.', 'notifications-for-hivepress' ),
				'link_text'    => esc_html__( 'Read the comment', 'notifications-for-hivepress' ),
				'tokens'       => [ 'author', 'folder_title', 'photo_url' ],
				'channels'     => [ 'onsite', 'push' ],
				'icon'         => 'comment',
			],

			'gallery_comment_reply' => [
				'label'     => esc_html__( 'Gallery Comment Replied To', 'notifications-for-hivepress' ),
				/* translators: keep %author.display_name% exactly as written; it is replaced with the name of the person who replied. */
				'text'      => __( '%author.display_name% replied to your comment.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'Read the reply', 'notifications-for-hivepress' ),
				'tokens'    => [ 'author', 'folder_title', 'photo_url' ],
				'channels'  => [ 'onsite', 'push' ],
				'icon'      => 'reply',
			],

			'gallery_comment_like'  => [
				'label'        => esc_html__( 'Gallery Comment Liked', 'notifications-for-hivepress' ),
				/* translators: keep %user.display_name% exactly as written; it is replaced with the name of the person who liked it. */
				'text'         => __( '%user.display_name% liked your comment.', 'notifications-for-hivepress' ),
				/* translators: keep %user.display_name% and %other_count% exactly as written; %other_count% becomes the number of other people and the word for them, such as "12 others". */
				'text_grouped' => __( '%user.display_name% and %other_count% liked your comment.', 'notifications-for-hivepress' ),
				'tokens'       => [ 'user', 'folder_title', 'photo_url' ],

				// On-site only, and off unless somebody asks for it. This is the lowest-value event
				// the plugin can raise and close to the highest volume, which is a poor trade for a
				// notification on somebody's phone.
				'channels'     => [ 'onsite' ],
				'icon'         => 'thumbs-up',
				'_default_off' => true,
			],

		];

		// Only where the photo review runs as a queued job that drafts the folder. Before 1.8.2 the
		// gallery refused the save outright instead, so there was no hidden gallery to report.
		if ( $can_flag_folders ) {
			$types['gallery_folder_hold'] = [
				'label'    => esc_html__( 'Gallery Hidden For Review', 'notifications-for-hivepress' ),
				/* translators: keep %folder_title% exactly as written; it is replaced with the gallery name. */
				'text'     => __( 'Your gallery %folder_title% has been hidden while its photos are reviewed.', 'notifications-for-hivepress' ),
				'tokens'   => [ 'folder_title', 'folder_url' ],
				'channels' => [ 'onsite', 'push' ],
				'icon'     => 'eye-slash',
			];
		}

		// The access types only mean anything where the vendor can sell access at all.
		$gallery = hivepress()->agl_gallery;

		if ( $gallery && $gallery->is_paid_access_enabled() ) {
			$types['gallery_access_purchase'] = [
				'label'     => esc_html__( 'Gallery Access Bought', 'notifications-for-hivepress' ),
				/* translators: keep %user.display_name% exactly as written; it is replaced with the buyer's name. */
				'text'      => __( '%user.display_name% bought access to your gallery.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'View your gallery', 'notifications-for-hivepress' ),
				'tokens'    => [ 'user', 'gallery_url' ],
				'channels'  => [ 'onsite', 'push' ],
				'icon'      => 'lock-open',
			];

			// The daily scan that fires this arrived in 1.8.2. Without it nothing ever looks ahead, so
			// the type would sit on the screen waiting for an event that is never raised.
			if ( $can_warn_expiring ) {
				$types['gallery_access_expiring'] = [
					'label'     => esc_html__( 'Gallery Access Ending Soon', 'notifications-for-hivepress' ),
					/* translators: keep %vendor_name% and %days_left% exactly as written; %days_left% becomes a length of time such as "7 days". */
					'text'      => __( 'Your access to %vendor_name%\'s gallery ends in %days_left%. Renew it to keep it.', 'notifications-for-hivepress' ),
					'link_text' => esc_html__( 'View the gallery', 'notifications-for-hivepress' ),
					'tokens'    => [ 'vendor_name', 'days_left', 'gallery_url' ],
					'channels'  => [ 'onsite', 'push' ],
					'icon'      => 'hourglass-half',
				];
			}

			$types['gallery_access_expire'] = [
				'label'     => esc_html__( 'Gallery Access Ended', 'notifications-for-hivepress' ),
				/* translators: keep %vendor_name% exactly as written; it is replaced with the vendor's name. */
				'text'      => __( 'Your access to %vendor_name%\'s gallery has ended.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'View the gallery', 'notifications-for-hivepress' ),
				'tokens'    => [ 'vendor_name', 'gallery_url' ],
				'channels'  => [ 'onsite', 'push' ],
				'icon'      => 'hourglass-end',
			];

			/*
			 * The vendor's side of the two types above, as ONE notification a day rather than one per
			 * buyer.
			 *
			 * Per-buyer would be the obvious shape and the wrong one. These events fire once for
			 * every grant, so a vendor with fifty people holding access gets fifty separate notices
			 * as they lapse - and because a member's preferences are set per group, the only way to
			 * escape that is to switch off Gallery entirely, taking the notices about their own
			 * photos and folders with it. Chris chose the digest on 2026-09-02.
			 *
			 * On-site only. A digest is a summary of things that have already happened, so waking
			 * somebody's phone for it is the wrong trade even where push is available.
			 *
			 * Two tokens rather than one assembled sentence, so an owner can still reword this in
			 * Email Studio. %detail% carries the split because the sentence genuinely changes shape -
			 * a day may bring only endings, only warnings, or both - and a template cannot choose
			 * between those without gluing fragments together, which does not survive translation.
			 */
			$types['gallery_access_digest'] = [
				'label'     => esc_html__( 'Gallery Access Summary', 'notifications-for-hivepress' ),
				/* translators: keep %people% and %detail% exactly as written; %people% becomes a number and the word for it, such as "4 people", and %detail% a phrase such as "3 ended, 1 ends within a week". */
				'text'      => __( 'Gallery access changed for %people%: %detail%.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'View your gallery', 'notifications-for-hivepress' ),
				'tokens'    => [ 'people', 'detail', 'gallery_url' ],
				'channels'  => [ 'onsite' ],
				'icon'      => 'clock-rotate-left',
				'audience'  => 'vendor',
			];
		}

		// phpcs:enable WordPress.WP.I18n.UnorderedPlaceholdersText

		return $types;
	}

	/**
	 * Gets the notification types about a person's own listings.
	 *
	 * @return array
	 */
	protected function get_listing_types() {
		$types = [];

		// phpcs:disable WordPress.WP.I18n.UnorderedPlaceholdersText -- HivePress tokens, replaced by name; see the note in get_gallery_types().

		if ( defined( 'HOLIDAY_MODE_FOR_HIVEPRESS_VERSION' ) ) {
			/*
			 * Four of the five need the actions Holiday Mode only fires from 1.7.4. The fifth reads
			 * the entitlement directly, which has been there all along, so it works on any version.
			 *
			 * A version comparison rather than the capability check used for the gallery, because
			 * what has to be detected here is whether an action is fired, and there is no way to ask
			 * that of a plugin. Holiday Mode's constant has always tracked its header, unlike the
			 * gallery's, so comparing it is sound.
			 */
			$holiday_fires_events = version_compare( HOLIDAY_MODE_FOR_HIVEPRESS_VERSION, '1.7.4', '>=' );

			if ( $holiday_fires_events ) {
				$types['holiday_start'] = [
					'label'    => esc_html__( 'Holiday Mode On', 'notifications-for-hivepress' ),
					/* translators: keep %listing_count% exactly as written; it becomes a number and the word for it, such as "4 listings". */
					'text'     => __( 'Holiday mode is on. We have hidden %listing_count% until you switch it off.', 'notifications-for-hivepress' ),
					'tokens'   => [ 'listing_count', 'listings_url' ],
					'channels' => [ 'onsite' ],
					'icon'     => 'umbrella-beach',
				];

				$types['holiday_end'] = [
					'label'    => esc_html__( 'Holiday Mode Off', 'notifications-for-hivepress' ),
					/* translators: keep %listing_count% exactly as written; it becomes a number and the word for it, such as "4 listings". */
					'text'     => __( 'Welcome back. We have restored %listing_count%.', 'notifications-for-hivepress' ),
					'tokens'   => [ 'listing_count', 'listings_url' ],
					'channels' => [ 'onsite' ],
					'icon'     => 'sun',
				];

				$types['holiday_expired_listing'] = [
					'label'     => esc_html__( 'Listings Expired While Away', 'notifications-for-hivepress' ),
					/* translators: keep %listing_count% exactly as written; it becomes a number and the word for it, such as "4 listings". */
					'text'      => __( '%listing_count% stayed hidden while you were away, because the listing period had run out. Renew to restore.', 'notifications-for-hivepress' ),
					'link_text' => esc_html__( 'Renew them', 'notifications-for-hivepress' ),
					'tokens'    => [ 'listing_count', 'listings_url' ],
					'channels'  => [ 'onsite', 'push' ],
					'icon'      => 'calendar-times',
				];

				$types['holiday_enforced_draft'] = [
					'label'    => esc_html__( 'Listing Kept Hidden', 'notifications-for-hivepress' ),
					/* translators: keep %listing_title% exactly as written; it is replaced with the listing name. */
					'text'     => __( '%listing_title% was kept hidden because holiday mode is still on.', 'notifications-for-hivepress' ),
					'tokens'   => [ 'listing_title', 'listing_url' ],
					'channels' => [ 'onsite' ],
					'icon'     => 'eye-slash',
				];
			}

			$types['holiday_entitlement_lapsed'] = [
				'label'     => esc_html__( 'Cannot Leave Holiday Mode', 'notifications-for-hivepress' ),
				'text'      => __( 'Your membership is not active, so your listings stay hidden. Renew it to restore them.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'Renew', 'notifications-for-hivepress' ),
				'tokens'    => [ 'settings_url' ],
				'channels'  => [ 'onsite', 'push' ],
				'icon'      => 'lock',
			];
		}

		if ( defined( 'HPALM_VERSION' ) ) {
			$types['moderation_hold'] = [
				'label'    => esc_html__( 'Listing Held For Review', 'notifications-for-hivepress' ),

				/*
				 * Deliberately says nothing about scores, spam or why. Heuristics false-positive,
				 * which is the moderation plugin's own stated reason for never auto-trashing
				 * anything, and this reaches the vendor who may well have done nothing wrong.
				 */
				/* translators: keep %listing_title% exactly as written; it is replaced with the listing name. */
				'text'     => __( '%listing_title% is waiting to be checked before it goes live. We will let you know as soon as it has been reviewed.', 'notifications-for-hivepress' ),
				'tokens'   => [ 'listing_title', 'listing_url' ],
				'channels' => [ 'onsite', 'push' ],
				'icon'     => 'hourglass-half',
			];

			$types['moderation_photo_hold'] = [
				'label'    => esc_html__( 'Listing Back Under Review', 'notifications-for-hivepress' ),

				// Its own wording rather than the one above, because the timing is what makes it
				// confusing: the photo check runs minutes or hours later, so this pulls back a
				// listing the vendor has already seen live.
				/* translators: keep %listing_title% exactly as written; it is replaced with the listing name. */
				'text'     => __( '%listing_title% has gone back under review while its photos are checked.', 'notifications-for-hivepress' ),
				'tokens'   => [ 'listing_title', 'listing_url' ],
				'channels' => [ 'onsite', 'push' ],
				'icon'     => 'images',
			];

			$types['moderation_hold_admin'] = [
				'label'    => esc_html__( 'Listing Held, With Reasons', 'notifications-for-hivepress' ),
				/* translators: keep %listing_title%, %risk_score% and %risk_reasons% exactly as written; they are replaced with the listing name, its score and what was found. */
				'text'     => __( '%listing_title% was held for review with a risk score of %risk_score%. %risk_reasons%', 'notifications-for-hivepress' ),
				'tokens'   => [ 'listing_title', 'risk_score', 'risk_reasons', 'admin_url', 'listing_url' ],
				'channels' => [ 'onsite', 'push' ],
				'icon'     => 'shield-alt',
				'group'    => 'admin',
			];

			$types['moderation_limit_reached'] = [
				'label'        => esc_html__( 'Submission Limit Reached', 'notifications-for-hivepress' ),
				/* translators: keep %user.display_name% exactly as written; it is replaced with the person's name. */
				'text'         => __( '%user.display_name% has reached the daily limit for adding listings.', 'notifications-for-hivepress' ),
				'tokens'       => [ 'user', 'admin_url' ],
				'channels'     => [ 'onsite' ],
				'icon'         => 'user-clock',
				'group'        => 'admin',

				// Off by default: on a site with a low limit and legitimate bulk vendors this fires
				// constantly, and it is useful only to owners actually watching for spam.
				'_default_off' => true,
			];

			$types['moderation_repeat_blocks'] = [
				'label'        => esc_html__( 'Repeated Refused Listings', 'notifications-for-hivepress' ),
				/* translators: keep %user.display_name% and %block_count% exactly as written; they are replaced with the person's name and how many were refused. */
				'text'         => __( '%user.display_name% has had %block_count% listings refused today.', 'notifications-for-hivepress' ),
				'tokens'       => [ 'user', 'block_count', 'admin_url' ],
				'channels'     => [ 'onsite' ],
				'icon'         => 'ban',
				'group'        => 'admin',
				'_default_off' => true,
			];
		}

		// phpcs:enable WordPress.WP.I18n.UnorderedPlaceholdersText

		return $types;
	}

	/**
	 * Gets the notification types about how somebody is doing.
	 *
	 * @return array
	 */
	protected function get_performance_types() {
		if ( ! defined( 'HPTS_VERSION' ) ) {
			return [];
		}

		return [
			'trust_verified' => [
				'label'     => esc_html__( 'Verified', 'notifications-for-hivepress' ),
				'text'      => __( 'You are now verified. A verified badge shows on your profile and on every listing you have.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'View your profile', 'notifications-for-hivepress' ),
				'tokens'    => [ 'vendor_url' ],
				'channels'  => [ 'onsite', 'push' ],
				'icon'      => 'check-circle',
			],
		];
	}

	/**
	 * Gives the analytics summary email wording written for a notification list.
	 *
	 * The type already exists and is already mirrored, because an email class backs it. It just has
	 * nothing of its own to say, so it falls back to the email subject, which is written for an
	 * inbox rather than for a line in a list.
	 *
	 * @param array $texts Default texts.
	 * @return array
	 */
	public function register_default_texts( $texts ) {
		if ( ! defined( 'HPVA_VERSION' ) ) {
			return $texts;
		}

		/* translators: keep every %token% exactly as written; they are replaced with the period and the figures for it. */
		$texts['hpva_analytics_summary'] = __( '%period%: %listing_views% views, %messages% messages and %bookings% bookings across your listings.', 'notifications-for-hivepress' ); // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText -- HivePress tokens, replaced by name; ordering them breaks them.

		return $texts;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Additional Gallery.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Tells a vendor somebody liked one of their photos.
	 *
	 * @param int    $like_id Like ID.
	 * @param object $like Like object.
	 * @return void
	 */
	public function add_photo_like_notification( $like_id, $like ) {
		$this->add_photo_engagement_notification( 'gallery_photo_like', $like, 'user' );
	}

	/**
	 * Tells a vendor somebody commented on one of their photos, or an author somebody replied.
	 *
	 * @param int    $comment_id Comment ID.
	 * @param object $comment Comment object.
	 * @return void
	 */
	public function add_photo_comment_notification( $comment_id, $comment ) {
		if ( ! is_object( $comment ) ) {
			return;
		}

		$parent_id = (int) $comment->get_parent__id();

		// A reply goes to the person being replied to, not to whoever owns the photo. It is the
		// only gallery notification that routinely reaches an ordinary visitor rather than a vendor.
		if ( $parent_id ) {
			$this->add_comment_reply_notification( $comment, $parent_id );

			return;
		}

		$this->add_photo_engagement_notification( 'gallery_photo_comment', $comment, 'author' );
	}

	/**
	 * Tells a vendor somebody liked or commented on one of their photos.
	 *
	 * @param string $type Notification type.
	 * @param object $object Like or comment object.
	 * @param string $actor_field Field naming the person who did it.
	 * @return void
	 */
	protected function add_photo_engagement_notification( $type, $object, $actor_field ) {
		if ( ! is_object( $object ) ) {
			return;
		}

		$photo_id = (int) $object->get_photo__id();
		$folder   = $this->get_photo_folder( $photo_id );

		if ( ! $folder ) {
			return;
		}

		$owner_id = (int) get_post_field( 'post_author', $folder->get_id() );
		$actor_id = (int) $object->{ 'get_' . $actor_field . '__id' }();

		// Nobody is told about their own activity.
		if ( ! $owner_id || $owner_id === $actor_id ) {
			return;
		}

		$actor = $object->{ 'get_' . $actor_field }();

		if ( ! $actor ) {
			return;
		}

		$photo_url = $this->get_photo_url( $photo_id, $folder );

		/*
		 * A new COMMENT is a place on the page, so its notification lands on it; a photo LIKE has
		 * no element of its own and keeps the plain photo link. instanceof, never method_exists():
		 * model getters are magic, so method_exists() is always false on both.
		 */
		if ( $object instanceof Models\Agl_Comment ) {
			$photo_url = $this->add_comment_fragment( $photo_url, $object->get_id() );
		}

		$tokens = [
			$actor_field   => $actor,
			'folder_title' => (string) $folder->get_title(),
			'photo_url'    => $photo_url,
		];

		$this->deliver(
			$type,
			$owner_id,
			$tokens,
			[
				'image'     => hivepress()->hpnf_notification->get_user_image( $actor ),
				'group_key' => 'photo-' . $photo_id,
			]
		);
	}

	/**
	 * Tells somebody their gallery comment has a reply.
	 *
	 * @param object $comment Reply object.
	 * @param int    $parent_id Parent comment ID.
	 * @return void
	 */
	protected function add_comment_reply_notification( $comment, $parent_id ) {
		$parent = Models\Agl_Comment::query()->get_by_id( $parent_id );

		if ( ! $parent ) {
			return;
		}

		$recipient_id = (int) $parent->get_author__id();
		$actor_id     = (int) $comment->get_author__id();

		if ( ! $recipient_id || $recipient_id === $actor_id ) {
			return;
		}

		$actor = $comment->get_author();

		if ( ! $actor ) {
			return;
		}

		$photo_id = (int) $comment->get_photo__id();
		$folder   = $this->get_photo_folder( $photo_id );

		$this->deliver(
			'gallery_comment_reply',
			$recipient_id,
			[
				'author'       => $actor,
				'folder_title' => $folder ? (string) $folder->get_title() : '',

				/*
				 * Anchored to the REPLY, so the click lands on the words being announced rather
				 * than the top of the photo page. Every comment carries a matching
				 * `id="agl-comment-N"` (gallery 1.8.12+; on an older gallery the fragment is
				 * simply ignored and the link behaves as before). The sticky header is already
				 * accounted for: the same scroll-padding-top that offsets review deep links
				 * offsets any fragment jump.
				 */
				'photo_url'    => $this->add_comment_fragment( $this->get_photo_url( $photo_id, $folder ), $comment->get_id() ),
			],
			[ 'image' => hivepress()->hpnf_notification->get_user_image( $actor ) ]
		);
	}

	/**
	 * Tells somebody their gallery comment was liked.
	 *
	 * @param int    $like_id Comment like ID.
	 * @param object $like Comment like object.
	 * @return void
	 */
	public function add_comment_like_notification( $like_id, $like ) {
		if ( ! is_object( $like ) ) {
			return;
		}

		// The liked comment is the like's `parent`, not a field called `comment`. A magic getter for
		// a field that does not exist returns null in silence, so the wrong name here would leave
		// this feature dead on every site while reading perfectly well.
		$comment_id = (int) $like->get_parent__id();
		$comment    = $comment_id ? Models\Agl_Comment::query()->get_by_id( $comment_id ) : null;

		if ( ! $comment ) {
			return;
		}

		$recipient_id = (int) $comment->get_author__id();
		$actor_id     = (int) $like->get_user__id();

		if ( ! $recipient_id || $recipient_id === $actor_id ) {
			return;
		}

		$actor = $like->get_user();

		if ( ! $actor ) {
			return;
		}

		$photo_id = (int) $like->get_photo__id();
		$folder   = $this->get_photo_folder( $photo_id );

		$this->deliver(
			'gallery_comment_like',
			$recipient_id,
			[
				'user'         => $actor,
				'folder_title' => $folder ? (string) $folder->get_title() : '',

				// Anchored to the LIKED comment - see the reply builder for why and for the
				// older-gallery fallback.
				'photo_url'    => $this->add_comment_fragment( $this->get_photo_url( $photo_id, $folder ), $comment_id ),
			],
			[
				'image'     => hivepress()->hpnf_notification->get_user_image( $actor ),
				'group_key' => 'comment-' . $comment_id,
			]
		);
	}

	/**
	 * Tells a vendor somebody bought access to their gallery.
	 *
	 * @param int $user_id Buyer user ID.
	 * @param int $vendor_id Vendor ID.
	 * @param int $order_id Order ID.
	 * @param int $expires Expiry timestamp.
	 * @return void
	 */
	public function add_access_purchase_notification( $user_id, $vendor_id, $order_id, $expires ) {
		$owner_id = (int) get_post_field( 'post_author', $vendor_id );
		$buyer    = $user_id ? Models\User::query()->get_by_id( $user_id ) : null;

		if ( ! $owner_id || ! $buyer ) {
			return;
		}

		$this->deliver(
			'gallery_access_purchase',
			$owner_id,
			[
				'user'        => $buyer,
				'gallery_url' => $this->get_vendor_gallery_url( $vendor_id ),
			],
			[ 'image' => hivepress()->hpnf_notification->get_user_image( $buyer ) ]
		);
	}

	/**
	 * Warns a buyer their gallery access is about to end.
	 *
	 * @param int $user_id Buyer user ID.
	 * @param int $vendor_id Vendor ID.
	 * @param int $expires Expiry timestamp.
	 * @param int $days_left Whole days remaining.
	 * @return void
	 */
	public function add_access_expiring_notification( $user_id, $vendor_id, $expires, $days_left ) {
		$days_left = max( 1, (int) $days_left );

		$this->deliver(
			'gallery_access_expiring',
			(int) $user_id,
			[
				'vendor_name' => $this->get_vendor_name( $vendor_id ),
				/* translators: %s: number of days. */
				'days_left'   => sprintf( _n( '%s day', '%s days', $days_left, 'notifications-for-hivepress' ), number_format_i18n( $days_left ) ),
				'gallery_url' => $this->get_vendor_gallery_url( $vendor_id ),
			]
		);

		$this->queue_access_digest( $vendor_id, $user_id, 'ending' );
	}

	/**
	 * Tells a buyer their gallery access has ended.
	 *
	 * @param int $user_id Buyer user ID.
	 * @param int $vendor_id Vendor ID.
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function add_access_expired_notification( $user_id, $vendor_id, $order_id ) {
		$this->deliver(
			'gallery_access_expire',
			(int) $user_id,
			[
				'vendor_name' => $this->get_vendor_name( $vendor_id ),
				'gallery_url' => $this->get_vendor_gallery_url( $vendor_id ),
			]
		);

		$this->queue_access_digest( $vendor_id, $user_id, 'ended' );
	}

	/**
	 * Records one access change for its vendor's next digest.
	 *
	 * Held in a single option rather than in post meta on each vendor, because the flush would
	 * otherwise have to find the vendors with something waiting - a meta query across every vendor on
	 * the site, run daily, to reach the handful that had a lapse. One option is one read and one
	 * write. Autoload is off: this is touched by a cron job and by nothing else.
	 *
	 * Buyers are recorded rather than counted, so somebody whose access both warns and lapses inside
	 * one day is one person in the total rather than two. The two buckets are still kept apart,
	 * because the sentence names each.
	 *
	 * Nothing is queued when the digest is switched off, or where the vendor has no owner. Queuing
	 * regardless would be harmless but would leave a growing option nobody ever reads.
	 *
	 * @param int    $vendor_id Vendor ID.
	 * @param int    $user_id Buyer user ID.
	 * @param string $bucket Either "ending" or "ended".
	 * @return void
	 */
	protected function queue_access_digest( $vendor_id, $user_id, $bucket ) {
		$vendor_id = (int) $vendor_id;
		$user_id   = (int) $user_id;
		$component = hivepress()->hpnf_notification;

		if ( ! $vendor_id || ! $user_id || ! $component ) {
			return;
		}

		if ( ! in_array( 'gallery_access_digest', $component->get_enabled_types(), true ) ) {
			return;
		}

		if ( ! (int) get_post_field( 'post_author', $vendor_id ) ) {
			return;
		}

		$queue = $this->get_access_digest_queue();
		$held  = (array) hp\get_array_value( $queue, $vendor_id, [] );
		$ids   = array_map( 'absint', (array) hp\get_array_value( $held, $bucket, [] ) );

		if ( in_array( $user_id, $ids, true ) ) {
			return;
		}

		// A cap, so a runaway loop somewhere else cannot grow this option without limit. Past it the
		// count stops rising, which reads as "at least 200" rather than as a wrong smaller number.
		if ( count( $ids ) >= self::DIGEST_LIMIT ) {
			return;
		}

		$ids[]           = $user_id;
		$held[ $bucket ] = $ids;

		$queue[ $vendor_id ] = $held;

		update_option( self::DIGEST_OPTION, $queue, false );
	}

	/**
	 * Gets the pending access changes, keyed by vendor ID.
	 *
	 * @return array
	 */
	protected function get_access_digest_queue() {
		$queue = get_option( self::DIGEST_OPTION );

		return is_array( $queue ) ? $queue : [];
	}

	/**
	 * Sends each vendor one notification covering the day's access changes.
	 *
	 * The queue is cleared BEFORE anything is delivered. A delivery that throws would otherwise leave
	 * the whole queue in place and send the same digest again tomorrow, on top of tomorrow's; losing
	 * one day's summary is the better failure of the two.
	 *
	 * @return void
	 */
	public function send_access_digests() {
		$queue = $this->get_access_digest_queue();

		if ( ! $queue ) {
			return;
		}

		delete_option( self::DIGEST_OPTION );

		foreach ( $queue as $vendor_id => $held ) {
			$vendor_id = (int) $vendor_id;
			$owner_id  = $vendor_id ? (int) get_post_field( 'post_author', $vendor_id ) : 0;

			if ( ! $owner_id ) {
				continue;
			}

			$ended  = array_unique( array_map( 'absint', (array) hp\get_array_value( $held, 'ended', [] ) ) );
			$ending = array_unique( array_map( 'absint', (array) hp\get_array_value( $held, 'ending', [] ) ) );

			// Somebody warned today and lapsed today is one person, not two.
			$people = count( array_unique( array_merge( $ended, $ending ) ) );

			if ( ! $people ) {
				continue;
			}

			$this->deliver(
				'gallery_access_digest',
				$owner_id,
				[
					'people'      => $this->count_people( $people ),
					'detail'      => $this->describe_access_changes( count( $ended ), count( $ending ) ),
					'gallery_url' => $this->get_vendor_gallery_url( $vendor_id ),
				]
			);
		}
	}

	/**
	 * Turns a head count into a number and the word for it.
	 *
	 * @param int $count Number of people.
	 * @return string
	 */
	protected function count_people( $count ) {
		$count = max( 0, (int) $count );

		/* translators: %s: number of people. */
		return sprintf( _n( '%s person', '%s people', $count, 'notifications-for-hivepress' ), number_format_i18n( $count ) );
	}

	/**
	 * Describes a day's access changes in one phrase.
	 *
	 * Either half may be nothing - a day can bring only lapses, or only warnings - so the phrase is
	 * assembled from whichever halves happened rather than from a template with holes in it.
	 *
	 * @param int $ended Number whose access ended.
	 * @param int $ending Number whose access is about to end.
	 * @return string
	 */
	protected function describe_access_changes( $ended, $ending ) {
		$parts = [];

		if ( $ended > 0 ) {
			/* translators: %s: number of people whose access ended. */
			$parts[] = sprintf( _n( '%s ended', '%s ended', $ended, 'notifications-for-hivepress' ), number_format_i18n( $ended ) );
		}

		if ( $ending > 0 ) {
			/* translators: %s: number of people whose access is about to end. */
			$parts[] = sprintf( _n( '%s ends within a week', '%s end within a week', $ending, 'notifications-for-hivepress' ), number_format_i18n( $ending ) );
		}

		/* translators: joins the two halves of a summary, as in "3 ended, 1 ends within a week". */
		return implode( _x( ', ', 'access summary separator', 'notifications-for-hivepress' ), $parts );
	}

	/**
	 * Tells a vendor their gallery has been hidden by the photo review.
	 *
	 * @param int    $folder_id Folder ID.
	 * @param object $folder Folder object.
	 * @return void
	 */
	public function add_folder_hold_notification( $folder_id, $folder = null ) {
		$owner_id = (int) get_post_field( 'post_author', $folder_id );

		if ( ! $owner_id ) {
			return;
		}

		$title = is_object( $folder ) ? (string) $folder->get_title() : (string) get_the_title( $folder_id );

		$this->deliver(
			'gallery_folder_hold',
			$owner_id,
			[
				'folder_title' => $title,
				'folder_url'   => (string) hivepress()->router->get_url( 'gallery_folder_edit_page', [ 'gallery_folder_id' => $folder_id ] ),
			]
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Holiday Mode.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Confirms holiday mode is on, and says how many listings went with it.
	 *
	 * @param int $user_id Vendor user ID.
	 * @param int $hidden Listings hidden.
	 * @return void
	 */
	public function add_holiday_start_notification( $user_id, $hidden ) {
		$this->deliver(
			'holiday_start',
			(int) $user_id,
			[
				'listing_count' => $this->count_listings( $hidden ),
				'listings_url'  => (string) hivepress()->router->get_url( 'listings_edit_page' ),
			]
		);
	}

	/**
	 * Welcomes a vendor back, and separately warns about anything that expired while they were away.
	 *
	 * Two notifications rather than one sentence with a clause on the end. The counts are different
	 * things, and burying "four did not come back" inside a welcome is how it goes unnoticed.
	 *
	 * @param int $user_id Vendor user ID.
	 * @param int $restored Listings made visible again.
	 * @param int $expired Listings that stayed hidden.
	 * @return void
	 */
	public function add_holiday_end_notification( $user_id, $restored, $expired ) {
		$user_id = (int) $user_id;

		$listings_url = (string) hivepress()->router->get_url( 'listings_edit_page' );

		if ( $restored > 0 ) {
			$this->deliver(
				'holiday_end',
				$user_id,
				[
					'listing_count' => $this->count_listings( $restored ),
					'listings_url'  => $listings_url,
				]
			);
		}

		if ( $expired > 0 ) {
			$this->deliver(
				'holiday_expired_listing',
				$user_id,
				[
					'listing_count' => $this->count_listings( $expired ),
					'listings_url'  => $listings_url,
				]
			);
		}
	}

	/**
	 * Explains why a listing did not go live.
	 *
	 * @param int $listing_id Listing ID.
	 * @param int $user_id Vendor user ID.
	 * @return void
	 */
	public function add_holiday_enforced_notification( $listing_id, $user_id ) {
		$this->deliver(
			'holiday_enforced_draft',
			(int) $user_id,
			[
				'listing_title' => (string) get_the_title( $listing_id ),
				'listing_url'   => (string) hivepress()->router->get_url( 'listing_edit_page', [ 'listing_id' => $listing_id ] ),
			]
		);
	}

	/**
	 * Tells a vendor their listings are stuck hidden because their membership lapsed.
	 *
	 * Holiday Mode refuses the switch-off and explains why, but only at the moment somebody tries.
	 * Anybody who does not try simply stays invisible, so this is the only thing that reaches them.
	 *
	 * Sent once per lapse: the flag clears as soon as they are entitled again, so renewing and then
	 * lapsing a second time is properly told twice.
	 *
	 * @return void
	 */
	public function check_holiday_entitlements() {
		if ( ! defined( 'HOLIDAY_MODE_FOR_HIVEPRESS_VERSION' ) || ! class_exists( '\Holiday_Mode_For_HivePress' ) ) {
			return;
		}

		$holiday = \Holiday_Mode_For_HivePress::instance();

		if ( ! $holiday || ! method_exists( $holiday, 'get_entitlement' ) ) {
			return;
		}

		// Only people who actually have holiday mode on, which on any site is a small number.
		$user_ids = get_users(
			[
				'meta_key'    => '_holiday_mode_for_hivepress', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed meta key, run once a day on cron, and the set is bounded by how many vendors are away.
				'fields'      => 'ID',
				'number'      => 500,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'count_total' => false,
			]
		);

		foreach ( (array) $user_ids as $user_id ) {
			$user_id     = (int) $user_id;
			$entitlement = $holiday->get_entitlement( $user_id );
			$told        = (bool) get_user_meta( $user_id, 'hp_notification_holiday_lapsed', true );

			if ( ! empty( $entitlement['allowed'] ) ) {

				// Entitled again, so the next lapse is news once more.
				if ( $told ) {
					delete_user_meta( $user_id, 'hp_notification_holiday_lapsed' );
				}

				continue;
			}

			if ( $told ) {
				continue;
			}

			update_user_meta( $user_id, 'hp_notification_holiday_lapsed', 1 );

			$this->deliver(
				'holiday_entitlement_lapsed',
				$user_id,
				[ 'settings_url' => (string) hivepress()->router->get_url( 'user_edit_settings_page' ) ]
			);
		}
	}

	/*
	 * -------------------------------------------------------------------------
	 * Listing Moderation.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Tells a vendor their listing is held, and the site owner why.
	 *
	 * @param int    $listing_id Listing ID.
	 * @param string $context Either `submission` or `photo_review`.
	 * @param int    $score Risk score.
	 * @param array  $signals Points keyed by signal name.
	 * @return void
	 */
	public function add_moderation_hold_notification( $listing_id, $context, $score, $signals ) {
		$listing_id = (int) $listing_id;
		$owner_id   = (int) get_post_field( 'post_author', $listing_id );

		$tokens = [
			'listing_title' => (string) get_the_title( $listing_id ),
			'listing_url'   => (string) hivepress()->router->get_url( 'listing_edit_page', [ 'listing_id' => $listing_id ] ),
		];

		if ( $owner_id ) {
			$this->deliver( 'photo_review' === $context ? 'moderation_photo_hold' : 'moderation_hold', $owner_id, $tokens );
		}

		/*
		 * The owner's copy carries the score and the reasons, which is what makes the pending queue
		 * something to triage rather than something to read. Its link goes to wp-admin, because the
		 * front-end edit page belongs to the vendor and is where they fix their own listing; an
		 * owner needs the screen with Publish on it.
		 *
		 * The admin link is listed first because the link is chosen by taking the first token whose
		 * name ends in "url", so order is what decides it.
		 */
		$this->deliver_to_admins(
			'moderation_hold_admin',
			array_merge(
				[ 'admin_url' => admin_url( 'post.php?post=' . $listing_id . '&action=edit' ) ],
				$tokens,
				[
					'risk_score'   => number_format_i18n( (int) $score ),
					'risk_reasons' => $this->describe_signals( (array) $signals ),
				]
			)
		);
	}

	/**
	 * Tells the site owner somebody has hit the daily submission limit.
	 *
	 * @param int $user_id Vendor user ID.
	 * @param int $limit The configured limit.
	 * @return void
	 */
	public function add_moderation_limit_notification( $user_id, $limit ) {
		$user = $user_id ? Models\User::query()->get_by_id( $user_id ) : null;

		if ( ! $user ) {
			return;
		}

		$this->deliver_to_admins(
			'moderation_limit_reached',
			[
				'user'      => $user,
				'admin_url' => admin_url( 'user-edit.php?user_id=' . (int) $user_id ),
			]
		);
	}

	/**
	 * Counts refused submissions, and tells the site owner about a run of them.
	 *
	 * The count is a transient rather than user meta on purpose: a tally of somebody's failed
	 * attempts is not a record worth keeping permanently, and it stops mattering after a day.
	 *
	 * @param int $user_id Vendor user ID.
	 * @param int $listing_id Listing ID.
	 * @return void
	 */
	public function count_blocked_submission( $user_id, $listing_id ) {
		$user_id = (int) $user_id;

		if ( ! $user_id || ! in_array( 'moderation_repeat_blocks', hivepress()->hpnf_notification->get_enabled_types(), true ) ) {
			return;
		}

		/**
		 * Filters how many refused submissions in a day are worth telling the site owner about.
		 *
		 * @hook hivepress/v1/notification_block_threshold
		 * @param {int} $threshold Number of refusals. Default 5.
		 * @return {int} Number of refusals.
		 */
		$threshold = max( 2, (int) apply_filters( 'hivepress/v1/notification_block_threshold', 5 ) );

		$key   = 'hp_notification_blocks_' . $user_id;
		$count = (int) get_transient( $key ) + 1;

		set_transient( $key, $count, self::BLOCK_WINDOW );

		// Only on the crossing, so a determined spammer produces one notification a day rather than
		// one per attempt from the fifth onwards.
		if ( $count !== $threshold ) {
			return;
		}

		$user = Models\User::query()->get_by_id( $user_id );

		if ( ! $user ) {
			return;
		}

		$this->deliver_to_admins(
			'moderation_repeat_blocks',
			[
				'user'        => $user,
				'block_count' => number_format_i18n( $count ),
				'admin_url'   => admin_url( 'user-edit.php?user_id=' . $user_id ),
			]
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Trust Signals.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Tells a vendor they have been verified.
	 *
	 * Hooked to the raw meta actions rather than to a HivePress model event, because verification is
	 * a tick box on the vendor in wp-admin and nothing else fires there.
	 *
	 * @param int    $meta_id Meta ID.
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public function add_verified_notification( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( 'hp_verified' !== $meta_key || ! $meta_value || 'hp_vendor' !== get_post_type( $post_id ) ) {
			return;
		}

		// An import writes the same meta, and nobody wants a verification notification for every
		// vendor in a migration. Every HivePress model hook bails on this; a raw hook has to check.
		if ( defined( 'HP_IMPORT' ) && HP_IMPORT ) {
			return;
		}

		$owner_id = (int) get_post_field( 'post_author', $post_id );

		if ( ! $owner_id ) {
			return;
		}

		// Verification can be re-saved on any vendor edit, and only the first time is news.
		if ( get_post_meta( $post_id, 'hp_notification_verified_sent', true ) ) {
			return;
		}

		add_post_meta( $post_id, 'hp_notification_verified_sent', 1, true );

		$this->deliver(
			'trust_verified',
			$owner_id,
			[ 'vendor_url' => (string) get_permalink( $post_id ) ]
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Shared plumbing.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Sends one notification, if the site and the reader both want it.
	 *
	 * @param string $type Notification type.
	 * @param int    $user_id Recipient user ID.
	 * @param array  $tokens Token values.
	 * @param array  $args Optional `image` and `group_key`.
	 * @return void
	 */
	protected function deliver( $type, $user_id, $tokens, $args = [] ) {
		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			return;
		}

		$component = hivepress()->hpnf_notification;

		if ( ! $component || ! in_array( $type, $component->get_enabled_types(), true ) ) {
			return;
		}

		// Somebody who has turned the on-site notification off gets nothing, and push goes with it,
		// because push carries the on-site notification rather than replacing it.
		if ( ! in_array( 'onsite', $component->get_user_channels( $user_id, $type ), true ) ) {
			return;
		}

		/*
		 * Only genuinely absent values are dropped. array_filter() with no callback also throws
		 * away an empty string and, worse, the string "0" - and render_text() leaves any token it
		 * is not given sitting in the wording exactly as typed. A held listing with a score of zero
		 * therefore reached the site owner reading "with a risk score of %risk_score%".
		 *
		 * An empty value renders as nothing, which is the right answer for an optional token; a
		 * placeholder in front of a real person never is.
		 */
		$tokens = array_filter(
			(array) $tokens,
			function ( $value ) {
				return ! is_null( $value ) && [] !== $value;
			}
		);

		$text = $component->render_text( $component->get_type_text( $type ), $tokens );

		if ( ! $text ) {
			return;
		}

		$payload = [
			'user'  => $user_id,
			'type'  => $type,
			'text'  => $text,
			'url'   => $this->get_url( $tokens ),
			'image' => (string) hp\get_array_value( $args, 'image' ),
		];

		$group_key = (string) hp\get_array_value( $args, 'group_key' );

		if ( $group_key && $component->get_type_grouped_text( $type ) ) {
			$payload['grouped_text'] = $component->render_text(
				$component->get_type_grouped_text( $type ),
				array_merge( $tokens, [ 'other_count' => $this->count_others( $type, $user_id, $group_key ) ] )
			);

			$component->add_grouped_notification( $payload, $group_key );

			return;
		}

		$component->add_notification( $payload );
	}

	/**
	 * Sends one notification to everybody who runs the site.
	 *
	 * @param string $type Notification type.
	 * @param array  $tokens Token values.
	 * @return void
	 */
	protected function deliver_to_admins( $type, $tokens ) {
		foreach ( $this->get_admin_ids() as $admin_id ) {
			$this->deliver( $type, $admin_id, $tokens );
		}
	}

	/**
	 * Gets the people who should receive the site owner's notifications.
	 *
	 * @return array
	 */
	protected function get_admin_ids() {
		$ids = get_users(
			[
				'capability' => 'manage_options',
				'fields'     => 'ID',

				// Capped so a site that has handed the capability to a large group cannot turn one
				// held listing into hundreds of notifications.
				'number'     => 20,
				'orderby'    => 'ID',
				'order'      => 'ASC',
			]
		);

		/**
		 * Filters who receives the notifications addressed to the site owner.
		 *
		 * @hook hivepress/v1/notification_admin_ids
		 * @param {array} $ids User IDs.
		 * @return {array} User IDs.
		 */
		return array_map( 'absint', (array) apply_filters( 'hivepress/v1/notification_admin_ids', (array) $ids ) );
	}

	/**
	 * Counts how many other people a rolled-up notification will cover.
	 *
	 * Reads the count the roll-up itself keeps, so the wording and the stored total can never
	 * disagree. The answer excludes the person named in the text, which is why it lags by one.
	 *
	 * @param string $type Notification type.
	 * @param int    $user_id Recipient user ID.
	 * @param string $group_key Group key.
	 * @return string
	 */
	protected function count_others( $type, $user_id, $group_key ) {
		$existing = get_comments(
			[
				'type'       => 'hp_notification',
				'user_id'    => $user_id,
				'karma'      => 0,

				// No 'status' => 'any' here either: it would count a trashed row, so the toast
				// would say "and 3 others" about a notification nobody can open. See the long note
				// on the matching query in add_grouped_notification().
				'number'     => 1,
				'orderby'    => 'comment_date_gmt',
				'order'      => 'DESC',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- two indexed meta keys, scoped to one user's own notifications.
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
		);

		$existing = is_array( $existing ) ? reset( $existing ) : false;
		$others   = $existing ? max( 1, (int) get_comment_meta( (int) $existing->comment_ID, 'hp_notification_group_count', true ) ) : 1;

		/* translators: %s: number of people. */
		return sprintf( _n( '%s other', '%s others', $others, 'notifications-for-hivepress' ), number_format_i18n( $others ) );
	}

	/**
	 * Formats a listing count with the word for it.
	 *
	 * The word is inside the token rather than in the sentence, so one stored wording reads correctly
	 * for one listing and for forty. A site owner editing the text cannot get the plural wrong,
	 * because there is no plural for them to get wrong.
	 *
	 * @param int $count Number of listings.
	 * @return string
	 */
	protected function count_listings( $count ) {
		$count = max( 0, (int) $count );

		/* translators: %s: number of listings. */
		return sprintf( _n( '%s listing', '%s listings', $count, 'notifications-for-hivepress' ), number_format_i18n( $count ) );
	}

	/**
	 * Turns the recorded risk signals into a sentence.
	 *
	 * @param array $signals Points keyed by signal name.
	 * @return string
	 */
	protected function describe_signals( $signals ) {
		if ( ! $signals || ! function_exists( 'hpalm_get_signal_labels' ) ) {
			return esc_html__( 'No specific reasons were recorded.', 'notifications-for-hivepress' );
		}

		$labels = hpalm_get_signal_labels();
		$found  = [];

		foreach ( array_keys( $signals ) as $signal ) {
			if ( isset( $labels[ $signal ] ) ) {
				$found[] = $labels[ $signal ];
			}
		}

		if ( ! $found ) {

			// Block mode records no signals at all, and a newer moderation release can name one
			// this version has no wording for. Either way the sentence still has to read, so it
			// says plainly that there is nothing to list rather than trailing off.
			return esc_html__( 'No specific reasons were recorded.', 'notifications-for-hivepress' );
		}

		// wp_sprintf_l() builds the "a, b and c" list with the separators of the reader's own
		// language, which a hardcoded implode() cannot.
		return wp_sprintf_l( '%l.', $found );
	}

	/**
	 * Picks the link for a notification from its tokens.
	 *
	 * The first token whose name ends in "url" wins, matching how the main component chooses a link
	 * for the notifications it mirrors from emails.
	 *
	 * @param array $tokens Token values.
	 * @return string
	 */
	protected function get_url( $tokens ) {
		foreach ( (array) $tokens as $name => $value ) {
			if ( is_string( $value ) && 'url' === substr( $name, -3 ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Appends a comment anchor to a photo URL.
	 *
	 * @param string $url Photo page URL, possibly empty.
	 * @param int    $comment_id Comment ID.
	 * @return string
	 */
	protected function add_comment_fragment( $url, $comment_id ) {
		$comment_id = (int) $comment_id;

		if ( ! $url || ! $comment_id ) {
			return (string) $url;
		}

		return $url . '#agl-comment-' . $comment_id;
	}

	/**
	 * Gets the folder a gallery photo belongs to.
	 *
	 * A photo is an attachment whose post_parent is the folder, which is how the gallery plugin
	 * resolves ownership everywhere else.
	 *
	 * @param int $photo_id Attachment ID.
	 * @return object|null
	 */
	protected function get_photo_folder( $photo_id ) {
		$photo_id = (int) $photo_id;

		if ( ! $photo_id || ! class_exists( '\HivePress\Models\Gallery_Folder' ) ) {
			return null;
		}

		$folder_id = (int) wp_get_post_parent_id( $photo_id );

		if ( ! $folder_id || 'hp_gallery_folder' !== get_post_type( $folder_id ) ) {
			return null;
		}

		return Models\Gallery_Folder::query()->get_by_id( $folder_id );
	}

	/**
	 * Gets the page a gallery photo can be seen on.
	 *
	 * @param int    $photo_id Attachment ID.
	 * @param object $folder Folder object, when it is known.
	 * @return string
	 */
	protected function get_photo_url( $photo_id, $folder = null ) {
		$photo_id = (int) $photo_id;

		if ( ! $photo_id || ! $folder ) {
			return '';
		}

		// A child route inherits its parent's path but nothing else, and this one is three deep:
		// gallery_view_page carries the vendor, gallery_folder_view_page the folder, and only then
		// comes the photo. Leaving any of the three out builds a URL that resolves to nothing.
		$vendor_id = (int) $folder->get_vendor__id();

		if ( ! $vendor_id ) {
			return '';
		}

		return (string) hivepress()->router->get_url(
			'gallery_photo_view_page',
			[
				'vendor_id'         => $vendor_id,
				'gallery_folder_id' => (int) $folder->get_id(),
				'attachment_id'     => $photo_id,
			]
		);
	}

	/**
	 * Gets a vendor's public gallery page.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return string
	 */
	protected function get_vendor_gallery_url( $vendor_id ) {
		$vendor_id = (int) $vendor_id;

		/*
		 * The router happily builds this URL for a vendor who no longer exists. Access grants live
		 * in the buyer's user meta, and the gallery's daily expiry scan never checks the vendor
		 * post is still there, so the expiring and expired notifications can fire after the vendor
		 * has been deleted - and then "View the gallery" walked straight into a 404. Only a vendor
		 * page the public can still open gets a link; no link rather than a broken one, the same
		 * trade the main component's get_badge_url() makes.
		 */
		if ( 'publish' !== get_post_status( $vendor_id ) ) {
			return '';
		}

		return (string) hivepress()->router->get_url( 'gallery_view_page', [ 'vendor_id' => $vendor_id ] );
	}

	/**
	 * Gets a vendor's name.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return string
	 */
	protected function get_vendor_name( $vendor_id ) {
		$name = (string) get_the_title( (int) $vendor_id );

		/*
		 * A deleted or untitled vendor post has no title, and the default wording drops this value
		 * straight into a sentence: an empty name reached real people as "Your access to 's
		 * gallery has ended." A neutral stand-in keeps the sentence whole. The purchase
		 * notification never needs this because it requires the vendor's post_author to resolve;
		 * the two expiry notifications can outlive the vendor they are about.
		 */
		if ( '' === trim( $name ) ) {
			return __( 'a vendor', 'notifications-for-hivepress' );
		}

		return $name;
	}
}
