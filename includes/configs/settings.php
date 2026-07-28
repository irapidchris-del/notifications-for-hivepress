<?php
/**
 * Settings configuration.
 *
 * Options are stored with the "hp_" prefix, so the "notification_toast_duration" field below is
 * saved as the "hp_notification_toast_duration" option. Defaults are added by HivePress when the
 * number of active extensions changes.
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
			'types'      => [
				'title'  => esc_html__( 'Types', 'notifications-for-hivepress' ),
				'_order' => 10,

				// The notification_types field is added by the notification component, because the
				// list of types depends on which extensions are active. It controls which
				// notifications users can manage; anything left unticked is sent by HivePress
				// exactly as it would be without this plugin.
				'fields' => [
					'notification_storage_period' => [
						'label'       => esc_html__( 'Storage Period (days)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Set the number of days to keep notifications for, or leave it empty to keep them indefinitely.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'_order'      => 20,
					],
				],
			],

			'text'       => [
				'title'  => esc_html__( 'Text', 'notifications-for-hivepress' ),
				'_order' => 15,

				// One field per enabled type is added by the notification component, because the
				// list of types and the tokens each one offers depend on the active extensions.
				'fields' => [],
			],

			'popups'     => [
				'title'  => esc_html__( 'Pop-ups', 'notifications-for-hivepress' ),
				'_order' => 20,

				'fields' => [
					'notification_toasts'                => [
						'label'       => esc_html__( 'Pop-ups', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Small cards that slide in while someone is browsing, announcing each new notification as it arrives.', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Show new notifications as pop-ups', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 10,
					],

					'notification_toast_position'        => [
						'label'       => esc_html__( 'Position', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The corner of the screen pop-ups appear in on desktops and laptops.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'Small screens use this instead, because pop-ups span the full width there and left or right stops meaning anything.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'Set the number of pop-ups shown at the same time. Any others wait their turn.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'Untick to keep pop-ups on screen until they are dismissed by hand.', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Hide pop-ups automatically', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_parent'     => 'notification_toasts',
						'_order'      => 40,
					],

					'notification_sound'                 => [
						'label'       => esc_html__( 'Sound', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Play a sound with pop-ups', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Browsers only allow sound once someone has clicked on the page, so a pop-up waiting on arrival stays silent. Ones that turn up while the tab is open will chime.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_toasts',
						'_order'      => 60,
					],

					'notification_sound_style'           => [
						'label'       => esc_html__( 'Sound Style', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The tone that plays when a pop-up arrives.', 'notifications-for-hivepress' ),
						'type'        => 'select',
						'default'     => 'chime',
						'required'    => true,
						'_parent'     => 'notification_sound',
						'_order'      => 22,

						'options'     => [
							'chime' => esc_html__( 'Chime', 'notifications-for-hivepress' ),
							'ping'  => esc_html__( 'Ping', 'notifications-for-hivepress' ),
							'pop'   => esc_html__( 'Pop', 'notifications-for-hivepress' ),
							'bell'  => esc_html__( 'Bell', 'notifications-for-hivepress' ),
							'soft'  => esc_html__( 'Soft', 'notifications-for-hivepress' ),
						],
					],

					'notification_toast_duration'        => [
						'label'       => esc_html__( 'Hide After (seconds)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Set the number of seconds a pop-up stays on screen before it hides itself. Hovering over a pop-up pauses the countdown.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 6,
						'min_value'   => 1,
						'max_value'   => 120,
						'required'    => true,
						'_parent'     => 'notification_toast_autohide',
						'_order'      => 50,
					],
				],
			],

			'delivery'   => [
				'title'  => esc_html__( 'Delivery', 'notifications-for-hivepress' ),
				'_order' => 25,

				'fields' => [
					'notification_poll'            => [
						'label'       => esc_html__( 'Check For New (seconds)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How often an open tab checks for new notifications, in seconds, so they appear without a reload. Clear the field to check only when a page loads. Checking is paused while a tab is in the background, and each check reads a single cached value, so the default of 60 costs very little.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 60,
						'min_value'   => 15,
						'max_value'   => 600,
						'_order'      => 10,
					],

					'notification_push'            => [
						'label'       => esc_html__( 'Push', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Send push notifications', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Reaches people who have the site closed. Needs HTTPS, and keys are created for you the first time. Users are asked for permission after a few visits, never on their first, because a refused prompt cannot be asked again.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 20,
					],

					'notification_push_delay'      => [
						'label'       => esc_html__( 'Ask After (visits)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Number of visits before someone is asked to allow push notifications.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 3,
						'min_value'   => 0,
						'max_value'   => 50,
						'_parent'     => 'notification_push',
						'_order'      => 30,
					],

					'notification_bell'            => [
						'label'       => esc_html__( 'Header Bell', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Show a bell in the site header', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Works with ListingHive and any theme that provides the HiveTheme site header area. This is separate from the count ListingHive already shows on the menu, which counts messages, bookings and orders rather than notifications.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 40,
					],

					'notification_bell_icon'       => [
						'label'       => esc_html__( 'Bell Icon', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The Font Awesome icon shown in the header, entered by name, such as bell, inbox, envelope, comment-dots or bell-slash. Use any free solid icon from Font Awesome; leave empty for the default bell.', 'notifications-for-hivepress' ),
						'type'        => 'text',
						'default'     => 'bell',
						'max_length'  => 64,
						'placeholder' => 'bell',
						'_parent'     => 'notification_bell',
						'_order'      => 31,
					],

					'notification_bell_color'      => [
						'label'       => esc_html__( 'Bell Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The colour of the header bell icon. The unread badge stays red so it always stands out.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#1a1a1a',
						'_parent'     => 'notification_bell',
						'_order'      => 33,
					],

					'notification_bell_size'       => [
						'label'       => esc_html__( 'Bell Size (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The size of the bell icon in pixels; the button around it scales to match.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 17,
						'min_value'   => 14,
						'max_value'   => 26,
						'_parent'     => 'notification_bell',
						'_order'      => 32,
					],

					'notification_bell_hide_count' => [
						'label'       => esc_html__( 'Hide Theme Counter', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Hide the counter the theme shows on the menu button', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The theme counts unread messages, unpaid bookings and pending orders; the bell mirrors those same events, so showing both counts everything twice.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_bell',
						'_order'      => 34,
					],

					'notification_sticky_header'   => [
						'label'       => esc_html__( 'Sticky Header', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Keep the header on screen when scrolling', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'A convenience for themes that do not do this already, so the bell stays reachable. Turn it off if your theme or your own code already handles it.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_bell',
						'_order'      => 50,
					],

					'notification_stats'           => [
						'label'       => esc_html__( 'Statistics', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Count how many notifications are sent and opened', 'notifications-for-hivepress' ),
						'description' => __( 'Anonymous per-type counts, shown on the <a href="admin.php?page=hp_notification_stats">Statistics</a> page while this is on.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 60,
					],
				],
			],

			'appearance' => [
				'title'  => esc_html__( 'Appearance', 'notifications-for-hivepress' ),
				'_order' => 30,

				'fields' => [
					'notification_toast_background_color' => [
						'label'       => esc_html__( 'Background Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Applies to pop-ups, the bell dropdown, and the cards on the notifications page.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#ffffff',
						'_order'      => 10,
					],

					'notification_toast_text_color'       => [
						'label'       => esc_html__( 'Text Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The main text colour used in pop-ups, the bell dropdown, and the notifications page.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#1a1a1a',
						'_order'      => 20,
					],

					'notification_toast_accent_color'     => [
						'label'       => esc_html__( 'Accent Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Used for the bar down the side of each pop-up and the unread marker in the notification list.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#3d9cd2',
						'_order'      => 30,
					],

					'notification_toast_text_size'        => [
						'label'       => esc_html__( 'Text Size (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The size of notification text in pixels, everywhere it appears.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 14,
						'min_value'   => 12,
						'max_value'   => 18,
						'_order'      => 34,
					],

					'notification_toast_text_weight'      => [
						'label'       => esc_html__( 'Text Weight', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How bold notification text is, everywhere it appears.', 'notifications-for-hivepress' ),
						'type'        => 'select',
						'default'     => '400',
						'required'    => true,
						'_order'      => 36,

						'options'     => [
							'400' => esc_html__( 'Normal', 'notifications-for-hivepress' ),
							'500' => esc_html__( 'Medium', 'notifications-for-hivepress' ),
							'600' => esc_html__( 'Semi-bold', 'notifications-for-hivepress' ),
							'700' => esc_html__( 'Bold', 'notifications-for-hivepress' ),
						],
					],

					'notification_panel_width'            => [
						'label'       => esc_html__( 'Dropdown Width (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The width of the bell dropdown in pixels. On small screens it spans the width available.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 320,
						'min_value'   => 280,
						'max_value'   => 420,
						'_order'      => 52,
					],

					'notification_toast_radius'           => [
						'label'       => esc_html__( 'Corner Radius (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Set the corner radius in pixels, or leave it empty to inherit the theme.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 3,
						'min_value'   => 0,
						'max_value'   => 40,
						'_order'      => 40,
					],
				],
			],
		],
	],
];
