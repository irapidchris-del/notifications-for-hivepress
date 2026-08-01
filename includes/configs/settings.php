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
				'description' => esc_html__( 'How notifications reach people: live updates while they browse, push notifications when the site is closed, and the bell in your header.', 'notifications-for-hivepress' ),
				'_order'      => 10,

				'fields'      => [
					'notification_poll'                    => [
						'label'       => esc_html__( 'Check For New (seconds)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How often an open page looks for new notifications, so they appear without a refresh. Clear the box to check only when a page loads. Checking pauses while a tab is in the background and reads a single cached value, so the default of 60 costs almost nothing.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 60,
						'min_value'   => 15,
						'max_value'   => 600,
						'_order'      => 10,
					],

					'notification_push'                    => [
						'label'       => esc_html__( 'Push Notifications', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Send push notifications', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Reaches people even when your site is closed, the way a phone app would. Your site must use HTTPS. The security keys are created for you the first time, so there is nothing to set up.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 20,
					],

					'notification_push_delay'              => [
						'label'       => esc_html__( 'Ask After (visits)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How many visits before someone is asked to allow push notifications. Asking on a first visit is usually refused, and a refusal cannot be undone by you, so waiting a few visits gets far more people saying yes.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'Adds a bell with an unread count and a dropdown of recent notifications. Works with ListingHive and any theme built on HiveTheme. If your header has no bell after switching this on, your theme does not provide the area it hooks into.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 40,
					],

					'notification_bell_icon'               => [
						'label'       => esc_html__( 'Bell Icon', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Start typing to search, such as "inbox" or "envelope". If your theme trims Font Awesome down, an icon it no longer includes will not show.', 'notifications-for-hivepress' ),
						'type'        => 'select',
						'default'     => 'bell',
						'required'    => true,

						// HivePress's own icon picker: the string is resolved to the icons config,
						// about a thousand Font Awesome names, and rendered as live previews in
						// Select2 - the same control core uses for attribute icons. Children must
						// sort after their parent checkbox (order 40), or the show-and-hide
						// reveals them above it.
						'options'     => 'icons',
						'_parent'     => 'notification_bell',
						'_order'      => 41,
					],

					'notification_bell_size'               => [
						'label'       => esc_html__( 'Bell Size (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The size of the bell icon. The circle around it grows to match.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 17,
						'min_value'   => 14,
						'max_value'   => 26,
						'_parent'     => 'notification_bell',
						'_order'      => 42,
					],

					'notification_bell_offset_x'           => [
						'label'       => esc_html__( 'Nudge Sideways (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Shifts the bell left or right without moving anything else in your header. Use a negative number to move it left, a positive one to move it right. Only needed if the bell does not quite line up with the buttons beside it.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 0,
						'min_value'   => -30,
						'max_value'   => 30,
						'_parent'     => 'notification_bell',
						'_order'      => 43,
					],

					'notification_bell_offset_y'           => [
						'label'       => esc_html__( 'Nudge Up or Down (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Shifts the bell up or down without moving anything else in your header. Use a negative number to move it up, a positive one to move it down.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 0,
						'min_value'   => -30,
						'max_value'   => 30,
						'_parent'     => 'notification_bell',
						'_order'      => 44,
					],

					'notification_bell_color'              => [
						'label'       => esc_html__( 'Bell Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The colour of the bell icon when nobody is pointing at it. The unread count on the bell has its own colour, under Appearance.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#1a1a1a',
						'_parent'     => 'notification_bell',
						'_order'      => 45,
					],

					'notification_bell_color_hover'        => [
						'label'       => esc_html__( 'Bell Colour (Hover)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The colour of the bell icon while the pointer is over it. Leave it empty to keep the colour above.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'_parent'     => 'notification_bell',
						'_order'      => 46,
					],

					'notification_bell_background'         => [
						'label'       => esc_html__( 'Bell Background', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The circle behind the bell icon. Leave it empty for no circle, which suits most headers.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'_parent'     => 'notification_bell',
						'_order'      => 47,
					],

					'notification_bell_background_hover'   => [
						'label'       => esc_html__( 'Bell Background (Hover)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The circle behind the bell while the pointer is over it. Leave it empty for a soft shade that works on light and dark headers alike.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'_parent'     => 'notification_bell',
						'_order'      => 48,
					],

					'notification_bell_hide_count'         => [
						'label'       => esc_html__( 'Hide Combined Counter', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Hide the combined count beside the account name', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'HivePress adds unread messages, unpaid bookings and pending orders into one number beside the account name. The bell already covers those same events, so leaving both on shows people two counts for the same thing.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_bell',
						'_order'      => 49,
					],

					'notification_bell_hide_message_count' => [
						'label'       => esc_html__( 'Hide Messages Counter', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Hide the unread count beside Messages', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The separate count the Messages extension shows on its own menu item. Worth keeping, because it says how many unread messages there are specifically, which the bell does not. Hide it only if you would rather people used the bell alone.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_bell',
						'_order'      => 50,
					],

					'notification_sticky_header'           => [
						'label'       => esc_html__( 'Sticky Header', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Keep the header on screen when scrolling', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Keeps the bell reachable on long pages. Leave it off if your theme already does this, or you will end up with two headers fighting each other.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_bell',
						'_order'      => 51,
					],

					'notification_stats'                   => [
						'label'       => esc_html__( 'Statistics', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Count how many notifications are sent and opened', 'notifications-for-hivepress' ),
						'description' => __( 'Anonymous totals per notification type, on the <a href="admin.php?page=hp_notification_stats">Statistics</a> page. A notification counts as opened when someone follows it; simply marking it read does not count. Nothing about individual people is stored.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 60,
					],
				],
			],

			'popups'     => [
				'title'       => esc_html__( 'Pop-ups', 'notifications-for-hivepress' ),
				'description' => esc_html__( 'The small cards that slide in to announce a notification while someone is browsing.', 'notifications-for-hivepress' ),
				'_order'      => 20,

				'fields'      => [
					'notification_toasts'                => [
						'label'       => esc_html__( 'Pop-ups', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Show new notifications as pop-ups', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Turn this off and notifications still arrive; they simply wait in the list and on the bell instead of announcing themselves.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'Phones use this instead. Pop-ups span the full width on a small screen, so only top, middle or bottom makes sense there.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'How long a pop-up stays before hiding itself. Pointing at a pop-up pauses the countdown, so nobody loses one mid-read.', 'notifications-for-hivepress' ),
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
				'description' => esc_html__( 'Colours and sizing, applied everywhere notifications appear: pop-ups, the bell dropdown and the notifications page.', 'notifications-for-hivepress' ),
				'_order'      => 30,

				'fields'      => [
					'notification_toast_background_color' => [
						'label'       => esc_html__( 'Background Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The background of pop-ups and the bell dropdown.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#ffffff',
						'_order'      => 10,
					],

					'notification_toast_text_color'       => [
						'label'       => esc_html__( 'Text Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The main text colour. Keep it readable against the background above.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#1a1a1a',
						'_order'      => 20,
					],

					'notification_toast_accent_color'     => [
						'label'       => esc_html__( 'Accent Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The stripe down the side of each pop-up, the dot marking an unread notification, and the icon behind each one. Your brand colour usually suits.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#3d9cd2',
						'_order'      => 30,
					],

					'notification_toast_radius'           => [
						'label'       => esc_html__( 'Corner Radius (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How rounded the corners are. Set 0 for square corners, or clear the box to inherit whatever your theme uses.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 3,
						'min_value'   => 0,
						'max_value'   => 40,
						'_order'      => 40,
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
						'description' => esc_html__( 'How bold notification text is. Unread notifications are always shown bolder than read ones, whichever you pick.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'The small number shown on the bell and beside Notifications in the account menu. Leaving this alone matches the counts HivePress already shows elsewhere.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#ff5a5f',
						'_order'      => 70,
					],

					'notification_panel_width'            => [
						'label'       => esc_html__( 'Dropdown Width (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How wide the bell dropdown is on desktops and laptops. Phones ignore this and span the screen instead, because there is no room to do otherwise.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 320,
						'min_value'   => 280,
						'max_value'   => 420,
						'_order'      => 80,
					],
				],
			],

			'types'      => [
				'title'       => esc_html__( 'Types', 'notifications-for-hivepress' ),
				'description' => esc_html__( 'Which notifications your users are allowed to manage for themselves. Anything left unticked keeps working exactly as HivePress sends it, and users cannot turn it off.', 'notifications-for-hivepress' ),
				'_order'      => 40,

				// The per-group type checkboxes are added by the notification component, because the
				// list depends on which extensions are active.
				'fields'      => [],
			],

			'defaults'   => [
				'title'       => esc_html__( 'Defaults', 'notifications-for-hivepress' ),
				'description' => esc_html__( 'What each kind of user gets before they change anything themselves. Existing users keep any choice they have already saved.', 'notifications-for-hivepress' ),
				'_order'      => 50,

				// One field per user role, added by the notification component.
				'fields'      => [
					'notification_storage_period' => [
						'label'       => esc_html__( 'Storage Period (days)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How long to keep notifications before deleting them automatically. Leave the box empty to keep them forever. Older notifications are removed once a day, so nothing disappears the moment it passes the limit.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'_order'      => 100,
					],
				],
			],

			'text'       => [
				'title'       => esc_html__( 'Text', 'notifications-for-hivepress' ),
				'description' => esc_html__( 'The wording of each notification. Leave one empty to use the subject line of the email HivePress already sends. The tokens you can use are listed under each box.', 'notifications-for-hivepress' ),
				'_order'      => 60,

				// One field per enabled type is added by the notification component, because the
				// list of types and the tokens each one offers depend on the active extensions.
				'fields'      => [],
			],
		],
	],
];
