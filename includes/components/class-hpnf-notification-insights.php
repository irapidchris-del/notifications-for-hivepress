<?php
/**
 * Notification insights component.
 *
 * @package HivePress\Notifications\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Turns changes in a vendor's own numbers into notifications.
 *
 * Everything in the sibling extensions component is event-driven: something happens, a hook fires,
 * somebody is told. The notifications here have no event to hang on, because nothing happens at the
 * moment a response rate slips or a listing goes quiet. They exist only as a difference between how
 * things are now and how they were, so they need something to look, on a schedule, and remember
 * what it saw.
 *
 * That makes this the expensive half of the feature, and it is built accordingly:
 *
 * - **One pass a night**, never on a visitor's request.
 * - **Batched.** Each pass takes a slice of vendors, remembers where it stopped and queues itself
 *   again, so no single run grows with the size of the site.
 * - **Capped.** A whole night's work is bounded, so a site with fifty thousand vendors cannot turn
 *   this into an endless chain of jobs.
 * - **Switchable.** One box turns the entire pass off, and every type inside it can be turned off
 *   individually as well.
 * - **Aggregated in SQL, not in PHP.** The analytics figures for a whole batch come back in a
 *   handful of grouped queries against indexed columns, rather than a query per vendor.
 *
 * The snapshot each vendor carries is deliberately small - a few integers and the dates things were
 * last said - because it is written for every vendor every night and read on the next pass.
 */
final class Hpnf_Notification_Insights extends Component {

	/**
	 * Where a vendor's last snapshot is kept.
	 *
	 * @var string
	 */
	const SNAPSHOT_META = 'hp_notification_insights';

	/**
	 * The nightly pass.
	 *
	 * @var string
	 */
	const SWEEP_HOOK = 'hivepress/v1/notifications/insights';

	/**
	 * View totals worth remarking on.
	 *
	 * Chosen so the gaps widen as the numbers grow: passing a hundred is worth hearing about, and
	 * so is passing a thousand, but not every hundred after that.
	 *
	 * @var array
	 */
	const VIEW_MILESTONES = [ 100, 500, 1000, 5000, 10000, 25000, 50000, 100000 ];

	/**
	 * Completed booking totals worth remarking on.
	 *
	 * @var array
	 */
	const BOOKING_MILESTONES = [ 1, 10, 25, 50, 100, 250, 500, 1000 ];

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Types are registered whatever the pass is doing, because an owner who has switched the
		// pass off should still see what it would have sent, greyed out by their own choice rather
		// than missing with no explanation.
		add_filter( 'hivepress/v1/notification_types', [ $this, 'register_types' ], 30 );

		add_action( 'hivepress/v1/events/daily', [ $this, 'start_sweep' ] );
		add_action( self::SWEEP_HOOK, [ $this, 'run_sweep' ], 10, 2 );

		parent::__construct( $args );
	}

	/**
	 * Registers the types this component sends.
	 *
	 * @param array $types Notification types.
	 * @return array
	 */
	public function register_types( $types ) {
		/*
		 * Every type this component sends is vendor-only: they are all about a vendor's own
		 * listings, their own profile and their own figures, and a member who does not sell can
		 * never receive one.
		 *
		 * Stamped over the whole set here rather than repeated on each definition. The definitions
		 * below are already vendor-facing by construction - the wording of every one of them says
		 * "your listings" - so an insight added later is vendor-only whether or not somebody
		 * remembers to say so, and a per-type argument is one line to forget.
		 */
		$own = array_map(
			function( $args ) {
				return array_merge( $args, [ 'audience' => 'vendor' ] );
			},
			array_merge( $this->get_analytics_types(), $this->get_trust_types() )
		);

		return array_merge( $types, $own );
	}

	/**
	 * Gets the types built from Vendor Analytics Pro's figures.
	 *
	 * @return array
	 */
	protected function get_analytics_types() {
		if ( ! $this->has_analytics() ) {
			return [];
		}

		// phpcs:disable WordPress.WP.I18n.UnorderedPlaceholdersText -- these are HivePress tokens, replaced by name rather than by position. Told to order them, phpcbf rewrites %listing_title% into %1$listing_title% and the token can never match again, so the notification reaches a real person with the raw placeholder in it.
		return [

			'analytics_weekly_digest'   => [
				'label'     => esc_html__( 'Weekly Summary', 'notifications-for-hivepress' ),
				/* translators: keep %view_count% and %change% exactly as written; they become the number of views and a phrase such as "up 12%". */
				'text'      => __( 'Your listings were viewed %view_count% times last week, %change%.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'See your report', 'notifications-for-hivepress' ),
				'tokens'    => [ 'view_count', 'change', 'report_url' ],
				'channels'  => [ 'onsite' ],
				'icon'      => 'chart-line',
			],

			'analytics_views_milestone' => [
				'label'     => esc_html__( 'Views Milestone', 'notifications-for-hivepress' ),
				/* translators: keep %view_count% exactly as written; it becomes the running total of views. */
				'text'      => __( 'Your listings have now been viewed %view_count% times.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'See your report', 'notifications-for-hivepress' ),
				'tokens'    => [ 'view_count', 'report_url' ],
				'channels'  => [ 'onsite', 'push' ],
				'icon'      => 'trophy',
			],

			'analytics_listing_spike'   => [
				'label'     => esc_html__( 'Listing Getting Attention', 'notifications-for-hivepress' ),
				/* translators: keep %listing_title% and %view_count% exactly as written. */
				'text'      => __( '%listing_title% was viewed %view_count% times yesterday, far more than usual.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'View the listing', 'notifications-for-hivepress' ),
				'tokens'    => [ 'listing_title', 'view_count', 'listing_url' ],
				'channels'  => [ 'onsite', 'push' ],
				'icon'      => 'fire',
			],

			'analytics_listing_quiet'   => [
				'label'        => esc_html__( 'Listing Gone Quiet', 'notifications-for-hivepress' ),
				/* translators: keep %listing_title% exactly as written. */
				'text'         => __( '%listing_title% has not been viewed for a month. Refreshing its photos or description often helps.', 'notifications-for-hivepress' ),
				'link_text'    => esc_html__( 'Edit the listing', 'notifications-for-hivepress' ),
				'tokens'       => [ 'listing_title', 'listing_url' ],
				'channels'     => [ 'onsite' ],
				'icon'         => 'moon',

				// Off unless the owner asks for it. It is useful advice on a site where vendors
				// tend their listings, and a monthly reminder of failure on one where they do not.
				'_default_off' => true,
			],

			'analytics_top_term'        => [
				'label'     => esc_html__( 'Top Search Term', 'notifications-for-hivepress' ),
				/* translators: keep %term% and %impression_count% exactly as written. */
				'text'      => __( 'Most people who found you last month searched for "%term%" - it showed your listings %impression_count% times.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'See your report', 'notifications-for-hivepress' ),
				'tokens'    => [ 'term', 'impression_count', 'report_url' ],
				'channels'  => [ 'onsite' ],
				'icon'      => 'magnifying-glass',
			],
		];
		// phpcs:enable WordPress.WP.I18n.UnorderedPlaceholdersText
	}

	/**
	 * Gets the types built from Trust Signals' figures.
	 *
	 * @return array
	 */
	protected function get_trust_types() {
		if ( ! $this->has_trust_signals() ) {
			return [];
		}

		// phpcs:disable WordPress.WP.I18n.UnorderedPlaceholdersText -- HivePress tokens, replaced by name. See the note in get_analytics_types().
		return [

			'trust_response_rate_up'   => [
				'label'     => esc_html__( 'Response Rate Improved', 'notifications-for-hivepress' ),
				/* translators: keep %response_rate% exactly as written; it becomes a percentage such as "95%". */
				'text'      => __( 'Your response rate is up to %response_rate%. Keep it up - it shows on your profile.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'View your profile', 'notifications-for-hivepress' ),
				'tokens'    => [ 'response_rate', 'vendor_url' ],
				'channels'  => [ 'onsite' ],
				'icon'      => 'arrow-trend-up',
			],

			'trust_response_rate_down' => [
				'label'     => esc_html__( 'Response Rate Slipped', 'notifications-for-hivepress' ),
				/* translators: keep %response_rate% exactly as written; it becomes a percentage such as "62%". */
				'text'      => __( 'Your response rate has slipped to %response_rate%. It shows on your profile, so replying sooner helps.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'Read your messages', 'notifications-for-hivepress' ),
				'tokens'    => [ 'response_rate', 'messages_url' ],
				'channels'  => [ 'onsite' ],
				'icon'      => 'arrow-trend-down',
			],

			'trust_response_time_up'   => [
				'label'     => esc_html__( 'Replying Faster', 'notifications-for-hivepress' ),
				/* translators: keep %response_time% exactly as written; it becomes a phrase such as "within an hour". */
				'text'      => __( 'You are replying faster than you were - your profile now says you answer %response_time%.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'View your profile', 'notifications-for-hivepress' ),
				'tokens'    => [ 'response_time', 'vendor_url' ],
				'channels'  => [ 'onsite' ],
				'icon'      => 'stopwatch',
			],

			'trust_bookings_milestone' => [
				'label'     => esc_html__( 'Bookings Milestone', 'notifications-for-hivepress' ),
				/* translators: keep %booking_count% exactly as written. */
				'text'      => __( 'You have now completed %booking_count% bookings. That is on your profile for everyone to see.', 'notifications-for-hivepress' ),
				'link_text' => esc_html__( 'View your profile', 'notifications-for-hivepress' ),
				'tokens'    => [ 'booking_count', 'vendor_url' ],
				'channels'  => [ 'onsite', 'push' ],
				'icon'      => 'award',
			],
		];
		// phpcs:enable WordPress.WP.I18n.UnorderedPlaceholdersText
	}

	/**
	 * Whether Vendor Analytics Pro is present with the tables these types read.
	 *
	 * Checked by asking for the function rather than comparing a version constant: a function
	 * either exists or it does not, and a version constant in this range has been wrong before.
	 *
	 * @return bool
	 */
	protected function has_analytics() {
		return function_exists( 'hpva_table' ) && function_exists( 'hpva_terms_table' );
	}

	/**
	 * Whether Trust Signals is present with the statistics these types read.
	 *
	 * @return bool
	 */
	protected function has_trust_signals() {
		return function_exists( 'hpts_get_vendor_stats' );
	}

	/**
	 * Whether the nightly pass is allowed to run at all.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		if ( ! get_option( 'hp_notification_enable_insights', true ) ) {
			return false;
		}

		return $this->has_analytics() || $this->has_trust_signals();
	}

	/**
	 * How many vendors one pass looks at.
	 *
	 * @return int
	 */
	protected function get_batch_size() {
		return max( 1, min( 500, (int) get_option( 'hp_notification_insights_batch', 50 ) ) );
	}

	/**
	 * How many vendors a whole night looks at.
	 *
	 * @return int
	 */
	protected function get_nightly_cap() {
		return max( 1, (int) get_option( 'hp_notification_insights_cap', 2000 ) );
	}

	/**
	 * Starts a night's pass.
	 *
	 * @return void
	 */
	public function start_sweep() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->queue_next( 0, 0 );
	}

	/**
	 * Queues the next batch.
	 *
	 * The position is passed as an argument rather than kept in an option, and that is not a style
	 * choice. HivePress's scheduler refuses to queue a hook that already has an action with the
	 * same arguments, and `as_has_scheduled_action()` counts RUNNING as well as PENDING
	 * (action-scheduler/functions.php, verified) - so a batch queueing its own successor with no
	 * arguments was matching *itself*, mid-run, and quietly queueing nothing. The pass would do one
	 * batch a night and stop, leaving every vendor past the first batch unlooked-at for ever, with
	 * no error anywhere. A site small enough to fit in one batch, which is every test site, could
	 * never show it.
	 *
	 * Each batch starts at a different vendor, so the arguments differ and the dedupe lets it
	 * through - while still doing its real job of refusing an exact duplicate.
	 *
	 * @param int $after Last vendor ID seen.
	 * @param int $done How many vendors this night has looked at.
	 * @return void
	 */
	protected function queue_next( $after, $done ) {
		$scheduler = hivepress()->scheduler;

		if ( $scheduler ) {
			$scheduler->add_action( self::SWEEP_HOOK, [ absint( $after ), absint( $done ) ] );
		}
	}

	/**
	 * Looks at one batch of vendors and queues the next.
	 *
	 * @param int $after Last vendor ID seen.
	 * @param int $done How many vendors this night has looked at.
	 * @return void
	 */
	public function run_sweep( $after = 0, $done = 0 ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$after = absint( $after );
		$done  = absint( $done );
		$cap   = $this->get_nightly_cap();

		if ( $done >= $cap ) {
			return;
		}

		$size    = min( $this->get_batch_size(), $cap - $done );
		$vendors = $this->get_vendor_batch( $after, $size );

		if ( ! $vendors ) {
			return;
		}

		$this->process_batch( $vendors );

		$last = end( $vendors );

		// Only queue again while a full batch came back. A short one is the end of the list.
		if ( count( $vendors ) >= $size ) {
			$this->queue_next( absint( $last['id'] ), $done + count( $vendors ) );
		}
	}

	/**
	 * Gets the next slice of vendors.
	 *
	 * Keyed on the ID rather than an offset, so a vendor created or deleted mid-pass cannot make
	 * the pass skip somebody or look at them twice.
	 *
	 * @param int $after Last vendor ID seen.
	 * @param int $size Batch size.
	 * @return array
	 */
	protected function get_vendor_batch( $after, $size ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a keyset-paged walk of every vendor, run once a night from cron. Caching a cursor page would defeat the point of the cursor, and WP_Query cannot express "ID greater than" without loading the posts it is trying to avoid loading.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID AS id, post_author AS user_id FROM {$wpdb->posts}
				 WHERE post_type = 'hp_vendor' AND post_status = 'publish' AND ID > %d
				 ORDER BY ID ASC
				 LIMIT %d",
				$after,
				$size
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $rows ? $rows : [];
	}

	/**
	 * Looks at one batch.
	 *
	 * The figures for the whole batch are fetched in a few grouped queries before anybody is
	 * examined, because a query per vendor is how a nightly job becomes an hourly one.
	 *
	 * @param array $vendors Vendor rows.
	 * @return void
	 */
	protected function process_batch( $vendors ) {
		$ids = array_map( 'absint', wp_list_pluck( $vendors, 'id' ) );

		$views_week  = $this->has_analytics() ? $this->sum_views( $ids, 7, 0 ) : [];
		$views_prev  = $this->has_analytics() ? $this->sum_views( $ids, 7, 7 ) : [];
		$views_total = $this->has_analytics() ? $this->sum_views( $ids, 0, 0 ) : [];

		foreach ( $vendors as $vendor ) {
			$vendor_id = absint( $vendor['id'] );
			$user_id   = absint( $vendor['user_id'] );

			if ( ! $vendor_id || ! $user_id ) {
				continue;
			}

			$snapshot = $this->read_snapshot( $vendor_id );
			$next     = $snapshot;

			if ( $this->has_analytics() ) {
				$next = $this->check_analytics(
					$user_id,
					$vendor_id,
					$next,
					[
						'week'  => absint( hp\get_array_value( $views_week, $vendor_id ) ),
						'prev'  => absint( hp\get_array_value( $views_prev, $vendor_id ) ),
						'total' => absint( hp\get_array_value( $views_total, $vendor_id ) ),
					]
				);
			}

			if ( $this->has_trust_signals() ) {
				$next = $this->check_trust_signals( $user_id, $vendor_id, $next );
			}

			if ( $next !== $snapshot ) {
				update_post_meta( $vendor_id, self::SNAPSHOT_META, $next );
			}
		}
	}

	/**
	 * Sums listing and profile views per vendor over a window.
	 *
	 * @param array $vendor_ids Vendor IDs.
	 * @param int   $days Window length in days, 0 for all time.
	 * @param int   $offset Days back the window ends, 0 for yesterday.
	 * @return array Totals keyed by vendor ID.
	 */
	protected function sum_views( $vendor_ids, $days, $offset ) {
		global $wpdb;

		if ( ! $vendor_ids ) {
			return [];
		}

		$table        = hpva_table();
		$placeholders = implode( ',', array_fill( 0, count( $vendor_ids ), '%d' ) );
		$params       = $vendor_ids;

		$where = "vendor_id IN ( {$placeholders} ) AND metric IN ( 'view', 'vendor_view' )";

		if ( $days > 0 ) {
			$where   .= ' AND stat_date >= %s AND stat_date <= %s';
			$params[] = gmdate( 'Y-m-d', strtotime( '-' . ( $days + $offset ) . ' days', $this->today() ) );
			$params[] = gmdate( 'Y-m-d', strtotime( '-' . ( 1 + $offset ) . ' days', $this->today() ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the placeholders are built from a count of integers and every value is passed through prepare(); runs once a night from cron.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics tables from Vendor Analytics Pro: a table name cannot be a placeholder, and every value in these queries is passed through prepare(). They run from a nightly cron job, never on a page load, so there is nothing to cache.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT vendor_id, SUM(value) AS total FROM {$table} WHERE {$where} GROUP BY vendor_id", $params ), ARRAY_A );

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = [];

		foreach ( (array) $rows as $row ) {
			$out[ absint( $row['vendor_id'] ) ] = absint( $row['total'] );
		}

		return $out;
	}

	/**
	 * Midnight today, in the site's timezone.
	 *
	 * The analytics table buckets by local day, so a window worked out in UTC would be off by one
	 * for most of the world.
	 *
	 * @return int
	 */
	protected function today() {
		return strtotime( current_time( 'Y-m-d' ) . ' 00:00:00' );
	}

	/**
	 * Reads a vendor's snapshot.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return array
	 */
	protected function read_snapshot( $vendor_id ) {
		$snapshot = get_post_meta( $vendor_id, self::SNAPSHOT_META, true );

		return is_array( $snapshot ) ? $snapshot : [];
	}

	/**
	 * Whether enough time has passed to say something of this kind again.
	 *
	 * Every type here describes a trend, and a trend restated every night is noise. Each kind gets
	 * its own quiet period, counted from the last time it was actually sent.
	 *
	 * @param array  $snapshot Snapshot.
	 * @param string $key Kind.
	 * @param int    $days Quiet period.
	 * @return bool
	 */
	protected function may_send( $snapshot, $key, $days ) {
		$last = absint( hp\get_array_value( $snapshot, 'said_' . $key ) );

		return ! $last || $last <= time() - $days * DAY_IN_SECONDS;
	}

	/**
	 * Marks a kind as just said.
	 *
	 * @param array  $snapshot Snapshot.
	 * @param string $key Kind.
	 * @return array
	 */
	protected function mark_said( $snapshot, $key ) {
		$snapshot[ 'said_' . $key ] = time();

		return $snapshot;
	}

	/**
	 * Compares a vendor's analytics figures with the last snapshot.
	 *
	 * The three view totals are handed in, already fetched for the whole batch. The checks that
	 * need a query of their own are each behind their quiet period, so on any given night only the
	 * small fraction of vendors actually due one pays for it - a vendor is looked at closely once a
	 * week at most, and once a month for the two monthly ones.
	 *
	 * @param int   $user_id Recipient.
	 * @param int   $vendor_id Vendor ID.
	 * @param array $snapshot Snapshot.
	 * @param array $views `week`, `prev` and `total` view counts.
	 * @return array Updated snapshot.
	 */
	protected function check_analytics( $user_id, $vendor_id, $snapshot, $views ) {
		$report_url = $this->get_route_url( 'vendor_analytics_page' );

		// --- Weekly summary. ---
		if ( $views['week'] > 0 && $this->may_send( $snapshot, 'digest', 7 ) ) {
			$sent = $this->send(
				$user_id,
				'analytics_weekly_digest',
				[
					'view_count' => number_format_i18n( $views['week'] ),
					'change'     => $this->describe_change( $views['week'], $views['prev'] ),
					'report_url' => $report_url,
				],
				$report_url
			);

			if ( $sent ) {
				$snapshot = $this->mark_said( $snapshot, 'digest' );
			}
		}

		// --- Views milestone. ---
		$passed  = absint( hp\get_array_value( $snapshot, 'views_milestone' ) );
		$reached = 0;

		foreach ( self::VIEW_MILESTONES as $milestone ) {
			if ( $views['total'] >= $milestone && $milestone > $passed ) {
				$reached = $milestone;
			}
		}

		if ( $reached ) {
			/*
			 * The snapshot moves to the milestone reached whether or not anything was sent. A
			 * vendor who installs this plugin with fifty thousand views already behind them should
			 * not be told they have passed a hundred, then five hundred, then a thousand, one a
			 * night for a week.
			 */
			$snapshot['views_milestone'] = $reached;

			if ( $passed ) {
				$this->send(
					$user_id,
					'analytics_views_milestone',
					[
						'view_count' => number_format_i18n( $reached ),
						'report_url' => $report_url,
					],
					$report_url
				);
			}
		}

		// --- A listing suddenly getting attention. ---
		if ( $this->may_send( $snapshot, 'spike', 7 ) ) {
			$spike = $this->find_spike( $vendor_id );

			if ( $spike ) {
				$sent = $this->send(
					$user_id,
					'analytics_listing_spike',
					[
						'listing_title' => $spike['title'],
						'view_count'    => number_format_i18n( $spike['views'] ),
						'listing_url'   => $spike['url'],
					],
					$spike['url']
				);

				if ( $sent ) {
					$snapshot = $this->mark_said( $snapshot, 'spike' );
				}
			}
		}

		// --- A listing nobody is looking at. ---
		if ( $this->may_send( $snapshot, 'quiet', 30 ) ) {
			$quiet = $this->find_quiet_listing( $vendor_id );

			if ( $quiet ) {
				$sent = $this->send(
					$user_id,
					'analytics_listing_quiet',
					[
						'listing_title' => $quiet['title'],
						'listing_url'   => $quiet['url'],
					],
					$quiet['url']
				);

				if ( $sent ) {
					$snapshot = $this->mark_said( $snapshot, 'quiet' );
				}
			}
		}

		// --- What people searched for to find them. ---
		if ( $this->may_send( $snapshot, 'term', 30 ) ) {
			$term = $this->find_top_term( $vendor_id );

			if ( $term ) {
				$sent = $this->send(
					$user_id,
					'analytics_top_term',
					[
						'term'             => $term['term'],
						'impression_count' => number_format_i18n( $term['impressions'] ),
						'report_url'       => $report_url,
					],
					$report_url
				);

				if ( $sent ) {
					$snapshot = $this->mark_said( $snapshot, 'term' );
				}
			}
		}

		return $snapshot;
	}

	/**
	 * Puts a week-on-week change into words.
	 *
	 * Said in plain language rather than as a signed percentage, and a first week with nothing to
	 * compare against says so instead of claiming an infinite rise.
	 *
	 * @param int $now This week.
	 * @param int $before Last week.
	 * @return string
	 */
	protected function describe_change( $now, $before ) {
		if ( $before < 1 ) {
			return esc_html__( 'your first week with views', 'notifications-for-hivepress' );
		}

		$change = (int) round( ( ( $now - $before ) / $before ) * 100 );

		if ( abs( $change ) < 10 ) {
			return esc_html__( 'about the same as the week before', 'notifications-for-hivepress' );
		}

		if ( $change > 0 ) {
			/* translators: %s: percentage. */
			return sprintf( esc_html__( 'up %s%% on the week before', 'notifications-for-hivepress' ), number_format_i18n( $change ) );
		}

		/* translators: %s: percentage. */
		return sprintf( esc_html__( 'down %s%% on the week before', 'notifications-for-hivepress' ), number_format_i18n( abs( $change ) ) );
	}

	/**
	 * Finds a listing that did far better yesterday than it usually does.
	 *
	 * Needs a floor as well as a multiple: one view against a fortnight of none is a threefold
	 * rise and means nothing.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return array|null
	 */
	protected function find_spike( $vendor_id ) {
		global $wpdb;

		$table     = hpva_table();
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day', $this->today() ) );
		$from      = gmdate( 'Y-m-d', strtotime( '-15 days', $this->today() ) );
		$to        = gmdate( 'Y-m-d', strtotime( '-2 days', $this->today() ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics tables from Vendor Analytics Pro: a table name cannot be a placeholder, and every value in these queries is passed through prepare(). They run from a nightly cron job, never on a page load, so there is nothing to cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT listing_id,
				        SUM( CASE WHEN stat_date = %s THEN value ELSE 0 END ) AS yesterday,
				        SUM( CASE WHEN stat_date BETWEEN %s AND %s THEN value ELSE 0 END ) AS earlier
				 FROM {$table}
				 WHERE vendor_id = %d AND metric = 'view' AND listing_id > 0 AND stat_date >= %s
				 GROUP BY listing_id
				 ORDER BY yesterday DESC
				 LIMIT 5",
				$yesterday,
				$from,
				$to,
				absint( $vendor_id ),
				$from
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $rows as $row ) {
			$views   = absint( $row['yesterday'] );
			$average = absint( $row['earlier'] ) / 14;

			if ( $views >= 10 && $views >= max( 3, $average * 3 ) ) {
				$listing = get_post( absint( $row['listing_id'] ) );

				if ( $listing && 'publish' === $listing->post_status ) {
					return [
						'title' => $listing->post_title,
						'views' => $views,
						'url'   => (string) get_permalink( $listing ),
					];
				}
			}
		}

		return null;
	}

	/**
	 * Finds a published listing nobody has looked at for a month.
	 *
	 * A listing published within that month is left alone: it has not gone quiet, it is new.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return array|null
	 */
	protected function find_quiet_listing( $vendor_id ) {
		global $wpdb;

		$table  = hpva_table();
		$cutoff = gmdate( 'Y-m-d', strtotime( '-30 days', $this->today() ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics tables from Vendor Analytics Pro: a table name cannot be a placeholder, and every value in these queries is passed through prepare(). They run from a nightly cron job, never on a page load, so there is nothing to cache.
		$seen = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT listing_id FROM {$table}
				 WHERE vendor_id = %d AND metric = 'view' AND listing_id > 0 AND stat_date >= %s",
				absint( $vendor_id ),
				$cutoff
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$seen = array_map( 'absint', (array) $seen );

		$listings = get_posts(
			[
				'post_type'      => 'hp_listing',
				'post_parent'    => absint( $vendor_id ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'date_query'     => [ [ 'before' => '30 days ago' ] ],
				'exclude'        => $seen,
				'orderby'        => 'date',
				'order'          => 'ASC',
			]
		);

		$listing = hp\get_first_array_value( $listings );

		if ( ! $listing ) {
			return null;
		}

		return [
			'title' => $listing->post_title,
			'url'   => (string) get_permalink( $listing ),
		];
	}

	/**
	 * Finds the search term that showed a vendor's listings most often last month.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return array|null
	 */
	protected function find_top_term( $vendor_id ) {
		global $wpdb;

		$listing_ids = get_posts(
			[
				'post_type'      => 'hp_listing',
				'post_parent'    => absint( $vendor_id ),
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
			]
		);

		if ( ! $listing_ids ) {
			return null;
		}

		$table        = hpva_terms_table();
		$placeholders = implode( ',', array_fill( 0, count( $listing_ids ), '%d' ) );
		$params       = array_map( 'absint', $listing_ids );
		$params[]     = gmdate( 'Y-m-d', strtotime( '-30 days', $this->today() ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- placeholders are built from a count of integers and every value goes through prepare(); nightly cron, monthly per vendor.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics tables from Vendor Analytics Pro: a table name cannot be a placeholder, and every value in these queries is passed through prepare(). They run from a nightly cron job, never on a page load, so there is nothing to cache.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT term, SUM(impressions) AS impressions FROM {$table} WHERE listing_id IN ( {$placeholders} ) AND stat_date >= %s GROUP BY term ORDER BY impressions DESC LIMIT 1", $params ), ARRAY_A );

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// A term that showed up a handful of times is not "how people find you".
		if ( ! $row || absint( $row['impressions'] ) < 5 ) {
			return null;
		}

		return [
			'term'        => sanitize_text_field( $row['term'] ),
			'impressions' => absint( $row['impressions'] ),
		];
	}

	/**
	 * Compares a vendor's trust signals with the last snapshot.
	 *
	 * Trust Signals caches its per-vendor statistics behind a transient keyed to a cache stamp, so
	 * asking for them costs one cached read for most vendors and a computation only when something
	 * they depend on has changed.
	 *
	 * @param int   $user_id Recipient.
	 * @param int   $vendor_id Vendor ID.
	 * @param array $snapshot Snapshot.
	 * @return array Updated snapshot.
	 */
	protected function check_trust_signals( $user_id, $vendor_id, $snapshot ) {
		$stats = hpts_get_vendor_stats( $vendor_id );

		if ( ! is_array( $stats ) ) {
			return $snapshot;
		}

		$vendor_url = (string) get_permalink( $vendor_id );
		$response   = hp\get_array_value( $stats, 'response' );

		// --- Response rate, both ways. ---
		if ( is_array( $response ) && isset( $response['rate'] ) ) {
			$rate = (int) round( (float) $response['rate'] );
			$was  = hp\get_array_value( $snapshot, 'response_rate' );

			if ( ! is_null( $was ) ) {
				$was  = (int) $was;
				$type = '';

				/*
				 * Ten points either way. A rate computed from a handful of messages moves by whole
				 * numbers of points when a single message is answered, and a vendor told every
				 * morning that their rate wobbled would switch the whole thing off.
				 */
				if ( $this->may_send( $snapshot, 'rate', 14 ) ) {
					if ( $rate >= $was + 10 ) {
						$type = 'trust_response_rate_up';
					} elseif ( $rate <= $was - 10 ) {
						$type = 'trust_response_rate_down';
					}
				}

				if ( $type ) {
					$up     = 'trust_response_rate_up' === $type;
					$url    = $up ? $vendor_url : $this->get_route_url( 'user_messages_page' );
					$tokens = [ 'response_rate' => $rate . '%' ];

					if ( $up ) {
						$tokens['vendor_url'] = $vendor_url;
					} else {
						$tokens['messages_url'] = $url;
					}

					if ( $this->send( $user_id, $type, $tokens, $url ) ) {
						$snapshot = $this->mark_said( $snapshot, 'rate' );
					}
				}
			}

			$snapshot['response_rate'] = $rate;
		}

		// --- Replying faster. Only ever said the good way round. ---
		if ( is_array( $response ) && function_exists( 'hpts_response_bucket' ) ) {
			$median  = absint( hp\get_array_value( $response, 'median' ) );
			$samples = absint( hp\get_array_value( $response, 'samples' ) );
			$was     = absint( hp\get_array_value( $snapshot, 'response_median' ) );

			/*
			 * Trust Signals reports a median in seconds and turns it into a phrase for the profile.
			 * The test is on the phrase, not on the seconds: shaving four minutes off is an
			 * improvement nobody can see, and telling somebody they are quicker when their profile
			 * says exactly what it said yesterday is a claim they can check and find wrong.
			 *
			 * A median over a handful of replies swings wildly on one message, so a few are needed
			 * before it means anything.
			 */
			if ( $median && $was && $samples >= 5 && $median < $was && $this->may_send( $snapshot, 'speed', 30 ) ) {
				$now_says = hpts_response_bucket( $median );
				$was_said = hpts_response_bucket( $was );

				if ( $now_says && $now_says !== $was_said ) {
					$sent = $this->send(
						$user_id,
						'trust_response_time_up',
						[
							'response_time' => $now_says,
							'vendor_url'    => $vendor_url,
						],
						$vendor_url
					);

					if ( $sent ) {
						$snapshot = $this->mark_said( $snapshot, 'speed' );
					}
				}
			}

			if ( $median ) {
				$snapshot['response_median'] = $median;
			}
		}

		// --- Completed bookings milestone. ---
		$bookings = absint( hp\get_array_value( $stats, 'bookings' ) );
		$passed   = absint( hp\get_array_value( $snapshot, 'bookings_milestone' ) );
		$reached  = 0;

		foreach ( self::BOOKING_MILESTONES as $milestone ) {
			if ( $bookings >= $milestone && $milestone > $passed ) {
				$reached = $milestone;
			}
		}

		if ( $reached ) {
			$snapshot['bookings_milestone'] = $reached;

			// As with views: the first pass records where they already are rather than announcing
			// every milestone they passed before this plugin was watching.
			if ( $passed ) {
				$this->send(
					$user_id,
					'trust_bookings_milestone',
					[
						'booking_count' => number_format_i18n( $reached ),
						'vendor_url'    => $vendor_url,
					],
					$vendor_url
				);
			}
		}

		return $snapshot;
	}

	/**
	 * Gets a route URL, or an empty string where the route is not registered.
	 *
	 * @param string $route Route name.
	 * @return string
	 */
	protected function get_route_url( $route ) {
		$router = hivepress()->router;

		if ( ! $router ) {
			return '';
		}

		return (string) $router->get_url( $route );
	}

	/**
	 * Sends one of these notifications.
	 *
	 * Three separate switches have to allow it, and none of them is optional:
	 *
	 * - the whole nightly pass, checked before any of this runs;
	 * - the site owner's tick for this type, which is why the enabled list is consulted here rather
	 *   than trusted to the model - `add_notification()` does not check it, its callers do;
	 * - the reader's own choice of channels, because somebody who has turned this type off should
	 *   not receive it through push either. Push carries the on-site notification rather than
	 *   replacing it, so if the on-site one is unwanted there is nothing for push to carry.
	 *
	 * The wording goes through the component's own renderer rather than a plain replace, so tokens
	 * behave here exactly as they do for every other notification.
	 *
	 * @param int    $user_id Recipient.
	 * @param string $type Type name.
	 * @param array  $tokens Token values.
	 * @param string $url Link.
	 * @return bool Whether anything was sent.
	 */
	protected function send( $user_id, $type, $tokens, $url = '' ) {
		$user_id   = absint( $user_id );
		$component = hivepress()->hpnf_notification;

		if ( ! $user_id || ! $component ) {
			return false;
		}

		if ( ! in_array( $type, $component->get_enabled_types(), true ) ) {
			return false;
		}

		if ( ! in_array( 'onsite', $component->get_user_channels( $user_id, $type ), true ) ) {
			return false;
		}

		$text = $component->render_text( $component->get_type_text( $type ), array_filter( (array) $tokens ) );

		if ( ! $text ) {
			return false;
		}

		$component->add_notification(
			[
				'user' => $user_id,
				'type' => $type,
				'text' => $text,
				'url'  => $url,
			]
		);

		return true;
	}
}
