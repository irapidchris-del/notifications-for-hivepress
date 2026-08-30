<?php
/**
 * Settings configuration.
 *
 * Options are stored with the "hp_" prefix, so the "notification_toast_duration" field below is
 * saved as the "hp_notification_toast_duration" option. Defaults are added by HivePress when the
 * number of active extensions changes.
 *
 * Section order runs from what most people change to what few people touch: Delivery first, then
 * Pop-ups and Appearance, and only then the long per-type lists under Types, Defaults and Text. The
 * type lists are the tallest and least often edited, so leading with them buried everything else.
 *
 * Copy standard for this file: every description is one to three short sentences carrying only the
 * points someone needs to decide - what the setting does, the one trap if there is one, and what
 * "empty" means where that differs per field. Longer background lives in code comments, not on the
 * screen; a previous version put whole paragraphs in the tooltips and the 173px tooltip bubble made
 * them unreadable.
 *
 * @package HivePress\Notifications\Configs
 */

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'notifications' => [
		'title'    => esc_html__( 'Notifications', 'notifications-for-hivepress' ),
		'_order'   => 35,

		'sections' => [
			'delivery'   => [
				'title'       => esc_html__( 'Delivery', 'notifications-for-hivepress' ),
				'description' => esc_html__( 'How notifications reach people: updates on the site itself, push notifications to their phone or computer, and the bell in your site header. Only people who are signed in see any of it, so sign in when you check your own site.', 'notifications-for-hivepress' ),
				'_order'      => 10,

				'fields'      => [
					'notification_poll'                    => [
						'label'       => esc_html__( 'Check for New Notifications Every (seconds)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How often an open page checks for new notifications, so they appear without a reload. Clear the box to switch checking off; new ones then appear on the next page load. Checking pauses in background tabs and is light work even on a busy site.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 60,
						'min_value'   => 15,
						'max_value'   => 600,
						'_order'      => 10,
					],

					'notification_push'                    => [
						'label'       => esc_html__( 'Push Notifications', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Send push notifications', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Reaches people on their phone or computer even while your site is closed. Your site must use HTTPS; the security keys are created for you. Unticking this drops Push from the boxes under Defaults on your next save.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 20,
					],

					'notification_push_delay'              => [
						'label'       => esc_html__( 'Ask After (visits)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How many browsing sessions someone makes before their browser asks to allow push. A first-visit ask is usually refused for good, so waiting gets far more people saying yes. Empty goes back to 3; 0 asks on the very first visit.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 3,
						'min_value'   => 0,
						'max_value'   => 50,
						'_parent'     => 'notification_push',
						'_order'      => 30,
					],

					'notification_bell'                    => [
						'label'       => esc_html__( 'Header Bell', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Show a bell in the site header', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Adds a bell with an unread count and a dropdown of recent notifications to your header, for signed-in people. It works in all six official HivePress themes; another theme may leave nowhere to put it, and the notifications page still shows everything.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 40,
					],

					'notification_bell_icon'               => [
						'label'       => esc_html__( 'Bell Icon', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The icon used for the bell. Click the box and type to search, such as "inbox" or "envelope"; the newer Font Awesome icons and the brand icons load their own stylesheet on your site automatically.', 'notifications-for-hivepress' ),
						'type'        => 'select',
						'default'     => 'bell',
						'required'    => true,

						// HivePress's own icon picker: the string is resolved to the icons config,
						// about a thousand Font Awesome 5 names plus the newer solid and brand
						// names this plugin merges in through the hivepress/v1/icons filter, and
						// rendered as live previews in Select2 - the same control core uses for
						// attribute icons. Children must sort after their parent checkbox (order
						// 40), or the show-and-hide reveals them above it.
						'options'     => 'icons',
						'_parent'     => 'notification_bell',
						'_order'      => 41,
					],

					'notification_bell_weight'             => [
						'label'       => esc_html__( 'Bell Icon Weight', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How heavy the bell icon is drawn. The extra weight follows the bell colours above.', 'notifications-for-hivepress' ),
						'type'        => 'select',
						'default'     => 'normal',
						'required'    => true,
						'_parent'     => 'notification_bell',
						'_order'      => 42,

						'options'     => [
							'normal'   => esc_html__( 'Normal', 'notifications-for-hivepress' ),
							'semibold' => esc_html__( 'Semi-bold', 'notifications-for-hivepress' ),
							'bold'     => esc_html__( 'Bold', 'notifications-for-hivepress' ),
						],
					],

					'notification_bell_size'               => [
						'label'       => esc_html__( 'Bell Size (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The size of the bell icon. The circle around it grows to match.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 17,
						'min_value'   => 14,
						'max_value'   => 26,
						'_parent'     => 'notification_bell',
						'_order'      => 43,
					],

					'notification_bell_offset_x'           => [
						'label'       => esc_html__( 'Nudge Sideways (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Shifts the bell without moving anything else in your header: negative moves it left, positive right. Only needed if it does not quite line up with the buttons beside it.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 0,
						'min_value'   => -30,
						'max_value'   => 30,
						'_parent'     => 'notification_bell',
						'_order'      => 44,
					],

					'notification_bell_offset_y'           => [
						'label'       => esc_html__( 'Nudge Up or Down (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Shifts the bell without moving anything else in your header: negative moves it up, positive down.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 0,
						'min_value'   => -30,
						'max_value'   => 30,
						'_parent'     => 'notification_bell',
						'_order'      => 45,
					],

					'notification_bell_color'              => [
						'label'       => esc_html__( 'Bell Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The colour of the bell icon. Leave it empty to match the rest of your header. The unread count has its own colour, under Appearance.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'_parent'     => 'notification_bell',
						'_order'      => 46,
					],

					'notification_bell_color_hover'        => [
						'label'       => esc_html__( 'Bell Colour (Hover)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The bell colour while pointed at or reached with the Tab key. Pick something clearly different from the colour above, so keyboard users can see where they are.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'_parent'     => 'notification_bell',
						'_order'      => 47,
					],

					'notification_bell_background'         => [
						'label'       => esc_html__( 'Bell Background', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The circle behind the bell icon. Leave it empty for no circle, which suits most headers.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'_parent'     => 'notification_bell',
						'_order'      => 48,
					],

					'notification_bell_background_hover'   => [
						'label'       => esc_html__( 'Bell Background (Hover)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The circle behind the bell while pointed at. Leave it empty for a soft shade that works on light and dark headers alike.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'_parent'     => 'notification_bell',
						'_order'      => 49,
					],

					'notification_bell_hide_count'         => [
						'label'       => esc_html__( 'Hide HivePress Counter', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Hide the count HivePress shows in the header', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'HivePress shows its own combined count beside the account link, and in some themes on the menu button too. Ticking this hides it in both places and leaves only the bell\'s count, which covers the same events.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_bell',
						'_order'      => 50,
					],

					'notification_bell_hide_message_count' => [
						'label'       => esc_html__( 'Hide Messages Counter', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Hide the unread count beside Messages', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Hides the count the Messages extension shows beside Messages in the account menu. It counts unread messages only, so keeping both is usually helpful. Without that extension this changes nothing.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_bell',
						'_order'      => 51,
					],

					'notification_sticky_header'           => [
						'label'       => esc_html__( 'Sticky Header', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Keep the header on screen when scrolling', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Keeps your whole site header at the top of the screen while signed-in people scroll. It follows the bell setting above, and leave it off if your theme already pins its header, or you will get two.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_bell',
						'_order'      => 52,
					],

					'notification_sticky_glass'            => [
						'label'       => esc_html__( 'Glass Effect', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Let the page show through the pinned header', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The pinned header becomes slightly see-through and blurs what scrolls behind it, and its dropdown menus get the same effect. Browsers that cannot blur, and anyone who has asked their device to reduce transparency, keep the solid header instead.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_sticky_header',
						'_order'      => 53,
					],

					'notification_sticky_glass_opacity'    => [
						'label'       => esc_html__( 'Glass Opacity (%)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How solid the pinned header stays. Lower lets more of the page through; below about 50 the text over it gets hard to read.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 72,
						'min_value'   => 10,
						'max_value'   => 100,
						'_parent'     => 'notification_sticky_glass',
						'_order'      => 54,
					],

					'notification_sticky_glass_blur'       => [
						'label'       => esc_html__( 'Glass Blur (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How much the page behind the header is blurred. More blur hides the detail and keeps the header legible.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 20,
						'min_value'   => 0,
						'max_value'   => 60,
						'_parent'     => 'notification_sticky_glass',
						'_order'      => 55,
					],

					/*
					 * The four corners are separate on purpose: one linked value cannot round only the
					 * bottom edge, which is the edge that actually shows once the header is pinned
					 * across the full width of the screen. Defaults of 0 keep the header exactly as it
					 * was before these existed. The CSS is only emitted when a corner is non-zero -
					 * see get_inline_styles().
					 */
					'notification_sticky_radius_top_left'  => [
						'label'       => esc_html__( 'Pinned Corner: Top Left (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Rounds the top-left corner of the pinned header. 0 keeps it square.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 0,
						'min_value'   => 0,
						'max_value'   => 40,
						'_parent'     => 'notification_sticky_header',
						'_order'      => 56,
					],

					'notification_sticky_radius_top_right' => [
						'label'       => esc_html__( 'Pinned Corner: Top Right (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Rounds the top-right corner of the pinned header. 0 keeps it square.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 0,
						'min_value'   => 0,
						'max_value'   => 40,
						'_parent'     => 'notification_sticky_header',
						'_order'      => 57,
					],

					'notification_sticky_radius_bottom_left' => [
						'label'       => esc_html__( 'Pinned Corner: Bottom Left (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Rounds the bottom-left corner of the pinned header. 0 keeps it square.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 0,
						'min_value'   => 0,
						'max_value'   => 40,
						'_parent'     => 'notification_sticky_header',
						'_order'      => 58,
					],

					'notification_sticky_radius_bottom_right' => [
						'label'       => esc_html__( 'Pinned Corner: Bottom Right (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Rounds the bottom-right corner of the pinned header. 0 keeps it square.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 0,
						'min_value'   => 0,
						'max_value'   => 40,
						'_parent'     => 'notification_sticky_header',
						'_order'      => 59,
					],

					'notification_stats'                   => [
						'label'       => esc_html__( 'Statistics', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Count how many notifications are sent and opened, as totals only', 'notifications-for-hivepress' ),

						// The link to the Statistics page went from here. That page only exists while
						// this box is ticked, so the link gave a permissions error at exactly the
						// moment somebody was reading this to decide whether to tick it.
						'description' => esc_html__( 'Keeps sent and opened totals per notification type; nothing is recorded about individual people. While ticked, a Statistics page appears under HivePress. Unticking stops the counting and removes the page, keeping the totals collected so far.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 60,
					],
				],
			],

			'popups'     => [
				'title'       => esc_html__( 'Pop-ups', 'notifications-for-hivepress' ),
				'description' => esc_html__( 'The small cards that slide in to announce a notification while someone is browsing. They only change the announcement; a notification nobody sees still waits on their notifications page.', 'notifications-for-hivepress' ),
				'_order'      => 20,

				'fields'      => [
					'notification_toasts'                => [
						'label'       => esc_html__( 'Pop-ups', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Show new notifications as pop-ups', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Untick and notifications still arrive; they wait on the notifications page, and on the bell if you have switched it on, instead of announcing themselves.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 10,
					],

					'notification_toast_position'        => [
						'label'       => esc_html__( 'Position', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Which corner pop-ups appear in on desktops and laptops.', 'notifications-for-hivepress' ),
						'type'        => 'select',
						'default'     => 'bottom-left',
						'required'    => true,
						'_parent'     => 'notification_toasts',
						'_order'      => 20,

						'options'     => [
							'top-right'    => esc_html__( 'Top Right', 'notifications-for-hivepress' ),
							'top-left'     => esc_html__( 'Top Left', 'notifications-for-hivepress' ),
							'bottom-right' => esc_html__( 'Bottom Right', 'notifications-for-hivepress' ),
							'bottom-left'  => esc_html__( 'Bottom Left', 'notifications-for-hivepress' ),
						],
					],

					'notification_toast_position_mobile' => [
						'label'       => esc_html__( 'Position on Mobile', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Where pop-ups sit on screens narrower than 480 pixels; wider screens use the corner above. Pop-ups stretch the full width there, so only top, centre or bottom is possible.', 'notifications-for-hivepress' ),
						'type'        => 'select',
						'default'     => 'bottom',
						'required'    => true,
						'_parent'     => 'notification_toasts',
						'_order'      => 25,

						'options'     => [
							'top'    => esc_html__( 'Top', 'notifications-for-hivepress' ),
							'center' => esc_html__( 'Centre', 'notifications-for-hivepress' ),
							'bottom' => esc_html__( 'Bottom', 'notifications-for-hivepress' ),
						],
					],

					'notification_toast_limit'           => [
						'label'       => esc_html__( 'Maximum Pop-ups', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How many pop-ups can be on screen at once. Any others wait their turn rather than piling up.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 3,
						'min_value'   => 1,
						'max_value'   => 10,
						'required'    => true,
						'_parent'     => 'notification_toasts',
						'_order'      => 30,
					],

					'notification_toast_autohide'        => [
						'label'       => esc_html__( 'Hiding', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Hide pop-ups automatically', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Untick to leave pop-ups on screen until they are closed by hand.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_parent'     => 'notification_toasts',
						'_order'      => 40,
					],

					'notification_toast_duration'        => [
						'label'       => esc_html__( 'Hide After (seconds)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How long a pop-up stays before hiding itself; the countdown pauses while the pointer is on it. Allow long enough for a whole sentence. A missed pop-up still waits on the notifications page.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 6,
						'min_value'   => 1,
						'max_value'   => 120,
						'required'    => true,
						'_parent'     => 'notification_toast_autohide',
						'_order'      => 50,
					],

					'notification_sound'                 => [
						'label'       => esc_html__( 'Sound', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Play a sound with pop-ups', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Browsers only allow sound after someone has clicked somewhere on the page, so the first pop-up of a visit may be silent. Later ones will chime.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_toasts',
						'_order'      => 60,
					],

					'notification_sound_style'           => [
						'label'       => esc_html__( 'Sound Style', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Which tone plays when a pop-up arrives.', 'notifications-for-hivepress' ),
						'type'        => 'select',
						'default'     => 'chime',
						'required'    => true,
						'_parent'     => 'notification_sound',

						// Directly after the Sound checkbox it belongs to; an earlier order used to
						// sort it above its parent, in between the position fields, where the
						// parent's show-and-hide left it missing from the screen.
						'_order'      => 62,

						'options'     => [
							'chime' => esc_html__( 'Chime', 'notifications-for-hivepress' ),
							'ping'  => esc_html__( 'Ping', 'notifications-for-hivepress' ),
							'pop'   => esc_html__( 'Pop', 'notifications-for-hivepress' ),
							'bell'  => esc_html__( 'Bell', 'notifications-for-hivepress' ),
							'soft'  => esc_html__( 'Soft', 'notifications-for-hivepress' ),
						],
					],
				],
			],

			'appearance' => [
				'title'       => esc_html__( 'Appearance', 'notifications-for-hivepress' ),
				// Which settings reach the notifications page is spelled out because three of them do
				// not: that page takes its background, text colour and corners from the theme, and an
				// owner changing them and seeing nothing happen went looking for a caching problem.
				'description' => esc_html__( 'Colours and sizing for notifications. Text size, weight, accent colour and icon shape apply everywhere notifications appear. Background, text colour and corner radius apply to the pop-ups and the bell dropdown only, because the notifications page uses your theme\'s own colours.', 'notifications-for-hivepress' ),
				'_order'      => 30,

				'fields'      => [
					'notification_toast_background_color' => [
						'label'       => esc_html__( 'Background Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The background of the pop-ups and the bell dropdown. Press Default beside the box to put the original white back; an empty box gives the same white.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#ffffff',
						'_order'      => 10,
					],

					'notification_toast_text_color'       => [
						'label'       => esc_html__( 'Text Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The main text colour in the pop-ups and the bell dropdown. Keep it readable against the background above. Press Default to put the original near-black back.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#1a1a1a',
						'_order'      => 20,
					],

					'notification_toast_accent_color'     => [
						'label'       => esc_html__( 'Accent Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The stripe on each pop-up, the unread dot, the links and the icon tile. Your brand colour usually suits. Press Default to put the original blue back.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#3d9cd2',
						'_order'      => 30,
					],

					'notification_icon_shape'             => [
						'label'       => esc_html__( 'Icon Shape', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The shape of the small picture at the start of every notification, whether a profile photo, a listing photo or an icon. Circles suit sites about people; squares suit places or products.', 'notifications-for-hivepress' ),
						'type'        => 'select',
						'default'     => 'circle',
						'required'    => true,
						'_order'      => 35,

						'options'     => [
							'circle'  => esc_html__( 'Circle', 'notifications-for-hivepress' ),
							'rounded' => esc_html__( 'Rounded Square', 'notifications-for-hivepress' ),
							'square'  => esc_html__( 'Square', 'notifications-for-hivepress' ),
						],
					],

					'notification_toast_radius'           => [
						'label'       => esc_html__( 'Corner Radius (px)', 'notifications-for-hivepress' ),
						// "Empty" means three different things on this screen: off for Check for New,
						// keep forever for the deletion box, and default here. Say which one this is.
						'description' => esc_html__( 'How rounded the corners of the pop-ups and the bell dropdown are. 0 gives square corners; an empty box gives the standard 3px back instead.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 3,
						'min_value'   => 0,
						'max_value'   => 40,
						'_order'      => 40,
					],

					'notification_button_radius'          => [
						'label'       => esc_html__( 'Button Radius (px)', 'notifications-for-hivepress' ),
						// Deliberately no default. Left empty the buttons keep whatever shape the
						// theme gives every other button on the site, which is right far more often
						// than any number we could pick; a value is only written once somebody has
						// decided the theme is wrong.
						'description' => esc_html__( 'How rounded this extension\'s own buttons are, such as Mark all as read. 0 gives square corners; an empty box leaves them shaped like the other buttons in your theme.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 0,
						'max_value'   => 40,
						'_order'      => 45,
					],

					'notification_toast_text_size'        => [
						'label'       => esc_html__( 'Text Size (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The size of notification text wherever it appears.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 14,
						'min_value'   => 12,
						'max_value'   => 18,
						'_order'      => 50,
					],

					'notification_toast_text_weight'      => [
						'label'       => esc_html__( 'Text Weight', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How bold notification text is. Unread notifications are always bolder than read ones, whichever you pick.', 'notifications-for-hivepress' ),
						'type'        => 'select',
						'default'     => '400',
						'required'    => true,
						'_order'      => 60,

						'options'     => [
							'400' => esc_html__( 'Normal', 'notifications-for-hivepress' ),
							'500' => esc_html__( 'Medium', 'notifications-for-hivepress' ),
							'600' => esc_html__( 'Semi-bold', 'notifications-for-hivepress' ),
							'700' => esc_html__( 'Bold', 'notifications-for-hivepress' ),
						],
					],

					'notification_badge_color'            => [
						'label'       => esc_html__( 'Unread Count Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The small number on the bell and beside Notifications in the account menu. Left alone it matches the counts HivePress shows elsewhere; press Default to put that red back.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#ff5a5f',
						'_order'      => 70,
					],

					'notification_panel_width'            => [
						'label'       => esc_html__( 'Dropdown Width (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How wide the bell dropdown is on desktops and laptops; screens narrower than about 768 pixels use the full width instead. An empty box gives the standard 320.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 320,
						'min_value'   => 280,
						'max_value'   => 420,

						// A width for a dropdown, so it belongs to the bell. Without this it sat on
						// screen offering to size something the site did not have.
						'_parent'     => 'notification_bell',
						'_order'      => 80,
					],
				],
			],

			'types'      => [
				'title'       => esc_html__( 'Types', 'notifications-for-hivepress' ),

				/*
				 * Unticking is not always "hand it back to HivePress": the extras have no HivePress
				 * email behind them, so unticking one switches that notification off. The email-less
				 * ones are NOT named here, on purpose - each exists only while its extension is
				 * active, so a static list read wrongly on any site missing one. alter_settings()
				 * appends the sentence naming exactly the ones this site really has.
				 */
				'description' => esc_html__( 'Which notifications this plugin handles. Tick one and it appears on the notifications page, the bell and as a pop-up, with each person choosing how they receive it. Leave one unticked and this plugin ignores it: any email HivePress already sends carries on exactly as before.', 'notifications-for-hivepress' ),
				'_order'      => 40,

				// The per-group type checkboxes are added by the notification component, because the
				// list depends on which extensions are active.
				'fields'      => [],
			],

			'defaults'   => [
				'title'       => esc_html__( 'Defaults', 'notifications-for-hivepress' ),
				'description' => esc_html__( 'What each kind of user receives until they choose for themselves on their own Notification Settings page. On-site is the notification itself; Email decides only whether the email HivePress already sends still reaches them. Anyone who has saved that page keeps their own choice.', 'notifications-for-hivepress' ),
				'_order'      => 50,

				// One field per user role, added by the notification component, which also appends a
				// sentence about Push when push is actually on offer.
				'fields'      => [],
			],

			'text'       => [
				'title'       => esc_html__( 'Text', 'notifications-for-hivepress' ),

				// The grey text inside each box is the wording actually used when the box is empty,
				// so the description must agree with what the owner can see. Where a type has no
				// wording of its own the grey text says the email subject is used instead.
				'description' => esc_html__( 'The wording and the title each notification shows on your site. Leave a box empty and the grey wording inside it is used, so only fill in what you want to change. Where the grey wording reads "The email subject is used", the subject line of the HivePress email is shown instead. A token is a placeholder between percent signs, such as %listing.title%, swapped for the real details when the notification is sent; hover the question mark beside a name to see the tokens it can use.', 'notifications-for-hivepress' ),
				'_order'      => 60,

				// One wording field and one title field per enabled type are added by the
				// notification component, because the list of types and the tokens each one offers
				// depend on the active extensions.
				'fields'      => [],
			],

			/*
			 * Storage Period lives here rather than under Defaults.
			 *
			 * It was the one setting in that section that could destroy anything, sitting under a
			 * heading promising only "what each kind of user starts with". Moving it puts the two
			 * irreversible settings together at the foot of the page, where an owner scrolling for
			 * something to change is not going to meet them by accident.
			 */
			'cleanup'    => [
				'title'       => esc_html__( 'Deleting Old Notifications', 'notifications-for-hivepress' ),
				'description' => esc_html__( 'Older notifications can be deleted automatically as they build up. Nothing is deleted unless you fill in the box below, and it applies to everyone on your site, not only you.', 'notifications-for-hivepress' ),
				'_order'      => 65,

				'fields'      => [
					'notification_storage_period' => [
						// The destructive verb belongs in the label, because on a narrow screen the
						// label is all there is: the tooltip does not open below 782px.
						'label'       => esc_html__( 'Delete Notifications Older Than (days)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Once a day, every notification older than this is deleted for good, for everyone, read or not, and it cannot be undone. An empty box means nothing is ever deleted. If unsure, start high, such as 365, and lower it later.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'_order'      => 10,
					],
				],
			],

			/*
			 * Its own section, because it is the only thing on this page that costs the site
			 * anything while nobody is looking. Everything else here reacts to something a person
			 * has just done; this walks every vendor once a night whether or not the site is busy.
			 */
			'insights'   => [
				'title'       => esc_html__( 'Performance Notifications', 'notifications-for-hivepress' ),
				'description' => esc_html__( 'Once a night this compares each vendor with how they were doing, and tells them what changed: weekly views, a listing getting attention, a response rate moving. It needs Vendor Analytics Pro or Trust Signals to have anything to compare, runs as small background jobs, and never runs while a page is loading.', 'notifications-for-hivepress' ),
				'_order'      => 62,
				'fields'      => [
					'notification_enable_insights' => [
						'label'       => esc_html__( 'Performance Notifications', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Look for changes worth telling vendors about', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Untick to stop the nightly comparison. The individual notifications stay listed under Types, and nothing already sent is removed.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 10,
					],
					'notification_insights_batch'  => [
						'label'       => esc_html__( 'Vendors Per Job', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How many vendors each background job looks at before handing over to the next one. Smaller is gentler on a shared host; the default suits almost every site.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 50,
						'min_value'   => 1,
						'max_value'   => 500,
						'_parent'     => 'notification_enable_insights',
						'_order'      => 20,
					],
					'notification_insights_cap'    => [
						'label'       => esc_html__( 'Vendors Per Night', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The most vendors one night will look at, as a safety valve. With more vendors than this, the pass carries on from the same place the following night, so everyone is still reached.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 2000,
						'min_value'   => 1,
						'_parent'     => 'notification_enable_insights',
						'_order'      => 30,
					],
				],
			],

			/*
			 * Its own section, and deliberately last.
			 *
			 * The section description exists to answer the question WordPress itself creates. The
			 * delete-confirmation screen always prints "(will also delete its data)" whenever an
			 * uninstall.php is present (wp-admin/plugins.php:376-380), whatever that file actually
			 * does - and ours keeps everything unless this box is ticked. Without a note here, an
			 * owner reads the core warning and reasonably concludes their data is going.
			 */
			'removal'    => [
				'title'       => esc_html__( 'Removing the Plugin', 'notifications-for-hivepress' ),
				'description' => esc_html__( 'Deleting this plugin keeps your notifications and settings, so you can reinstall and carry on; the warning WordPress shows on its delete screen is the same for every plugin and does not apply here. Switching the plugin off never removes anything. The box below is the one exception.', 'notifications-for-hivepress' ),
				'_order'      => 70,

				'fields'      => [
					'notification_delete_data' => [
						'label'       => esc_html__( 'Delete All Data', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Delete everything when this plugin is deleted', 'notifications-for-hivepress' ),

						// The list is spelled out rather than summarised as "all data", because the
						// words it used to summarise with - "channel choices", "push subscriptions" -
						// are not words this screen ever introduces, and a marketplace owner reads
						// the second as paid subscriptions.
						'description' => esc_html__( 'Leave this unticked unless you are certain. With it ticked, deleting the plugin also removes every notification, each person\'s delivery choices and quiet hours, the record of browsers allowed to show push, your wording and titles, your announcements, the Statistics totals and every setting on this page. It cannot be undone and nothing asks you to confirm at the time.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 10,
					],
				],
			],
		],
	],
];
