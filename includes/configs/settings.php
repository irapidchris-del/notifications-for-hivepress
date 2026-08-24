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
				'description' => esc_html__( 'How notifications reach people: updates that appear while they are on your site, push notifications that reach their phone or computer when they are not, and the bell in your site header. Only people who are signed in see any of it, so sign in when you check your own site.', 'notifications-for-hivepress' ),
				'_order'      => 10,

				'fields'      => [
					'notification_poll'                    => [
						'label'       => esc_html__( 'Check for New Notifications Every (seconds)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How often a page that is already open asks your site whether anything new has arrived, so notifications appear without anyone reloading. Clear the box to switch that asking off, and new notifications will then show up the next time someone loads a page. Checking pauses while a tab is in the background and reads one small stored value each time, so 60 seconds is light work even on a busy site.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 60,
						'min_value'   => 15,
						'max_value'   => 600,
						'_order'      => 10,
					],

					'notification_push'                    => [
						'label'       => esc_html__( 'Push Notifications', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Send push notifications', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Reaches people on their phone or computer even when your site is closed, the way a phone app would. Your site must use HTTPS, and the security keys it needs are created for you the first time, so there is usually nothing to set up. Push is only offered as a choice, here and to your users, while this is ticked and those keys exist, so unticking it drops Push from the boxes under Defaults on your next save; tick those again if you switch push back on.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 20,
					],

					'notification_push_delay'              => [
						'label'       => esc_html__( 'Ask After (visits)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How many visits someone makes before their browser asks them to allow push notifications. A visit is one browsing session rather than one page, and asking on a first visit is usually refused, which you cannot undo, so waiting a few visits gets far more people saying yes. Clearing the box goes back to 3, and 0 asks on the very first visit, so to stop the asking altogether untick Push Notifications above.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'Adds a bell to your site header with a count of unread notifications and a dropdown of the recent ones, shown only to people who are signed in. It works in all six official HivePress themes: ListingHive, RentalHive, JobHive, TaskHive, ExpertHive and MeetingHive. Another theme may leave nowhere to put it, and if no bell appears there, people can still read everything on their notifications page.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 40,
					],

					'notification_bell_icon'               => [
						'label'       => esc_html__( 'Bell Icon', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The icon used for the bell in your header. Click the box and type to search, such as "inbox" or "envelope", and each icon is previewed beside its name. Some themes ship a smaller set of icons than HivePress does, so if your choice shows as a blank space on your site, pick another one.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'The colour of the bell icon when nobody is pointing at it. Leave it empty to match the rest of your header. The unread count on the bell has its own colour, under Appearance.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'_parent'     => 'notification_bell',
						'_order'      => 45,
					],

					'notification_bell_color_hover'        => [
						'label'       => esc_html__( 'Bell Colour (Hover)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The colour of the bell icon while the pointer is over it, and while someone has reached it with the Tab key. Leave it empty to keep the colour above. Pick something clearly different from that colour, because this is also how people who move around a page without a mouse see where they have got to.', 'notifications-for-hivepress' ),
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
						'label'       => esc_html__( 'Hide HivePress Counter', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Hide the count HivePress shows in the header', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'HivePress shows a count of its own beside the account link, and in some themes on the menu button as well. It adds together whichever of these your site has: unread messages, booking requests, unpaid bookings and orders waiting to be delivered. Ticking this hides that count in both places and leaves only the bell\'s, which covers the same events for every notification you have ticked under Types.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_bell',
						'_order'      => 49,
					],

					'notification_bell_hide_message_count' => [
						'label'       => esc_html__( 'Hide Messages Counter', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Hide the unread count beside Messages', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The Messages extension puts a count of its own beside Messages in the account menu. It counts unread messages only, where the bell counts notifications of every kind, so keeping both is usually helpful; tick this if you would rather people used the bell alone. If you do not use the Messages extension there is no such count and this changes nothing.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_bell',
						'_order'      => 50,
					],

					'notification_sticky_header'           => [
						'label'       => esc_html__( 'Sticky Header', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Keep the header on screen when scrolling', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Your whole site header stays at the top of the screen as people scroll down a long page, so the bell and your menu stay within reach. Only people who are signed in get this, so sign in when you check it on your own site. It follows the bell setting above, so switching the bell off switches this off too, and leave it off if your theme already keeps its header on screen or you will end up with two headers at once.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_bell',
						'_order'      => 51,
					],

					'notification_sticky_glass'            => [
						'label'       => esc_html__( 'Glass Effect', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Let the page show through the pinned header', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The pinned header becomes slightly see-through and blurs whatever scrolls behind it. Browsers that cannot do this keep the solid header instead, and so does anyone who has asked their device to reduce transparency, so nothing becomes hard to read.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_parent'     => 'notification_sticky_header',
						'_order'      => 52,
					],

					'notification_sticky_glass_opacity'    => [
						'label'       => esc_html__( 'Glass Opacity (%)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How solid the pinned header stays. Lower lets more of the page through. Below about 50 the text over it starts to get hard to read on a busy page.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 72,
						'min_value'   => 10,
						'max_value'   => 100,
						'_parent'     => 'notification_sticky_glass',
						'_order'      => 53,
					],

					'notification_sticky_glass_blur'       => [
						'label'       => esc_html__( 'Glass Blur (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How much the page behind the header is blurred. More blur hides the detail behind it and keeps the header legible.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 20,
						'min_value'   => 0,
						'max_value'   => 60,
						'_parent'     => 'notification_sticky_glass',
						'_order'      => 54,
					],

					'notification_stats'                   => [
						'label'       => esc_html__( 'Statistics', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Count how many notifications are sent and opened, as totals only', 'notifications-for-hivepress' ),

						// The link to the Statistics page went from here. That page only exists while
						// this box is ticked, so the link gave a permissions error at exactly the
						// moment somebody was reading this to decide whether to tick it.
						'description' => esc_html__( 'Totals are kept for each type of notification, and nothing is recorded about individual people. A notification counts as opened when someone follows it through to what it is about; marking it read does not count. While this is ticked, a Statistics page appears under HivePress in the menu on the left, and unticking it stops the counting and removes the page, though the totals collected so far are kept.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 60,
					],
				],
			],

			'popups'     => [
				'title'       => esc_html__( 'Pop-ups', 'notifications-for-hivepress' ),
				'description' => esc_html__( 'The small cards that slide in to announce a notification while someone is browsing. These settings only change the announcement; a notification nobody sees still waits for them on their notifications page.', 'notifications-for-hivepress' ),
				'_order'      => 20,

				'fields'      => [
					'notification_toasts'                => [
						'label'       => esc_html__( 'Pop-ups', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Show new notifications as pop-ups', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Turn this off and notifications still arrive; instead of announcing themselves they wait on the notifications page in each person\'s account, and on the bell if you have switched it on.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'Where pop-ups sit on screens narrower than 480 pixels, which is most phones held upright. Wider screens, including tablets and phones turned sideways, use the corner set above. Pop-ups stretch the full width on a narrow screen, so only top, centre or bottom is possible there.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'How long a pop-up stays on screen before it hides itself. The bar along the bottom empties as the time runs down, and it stops while the pointer is on the pop-up, so nobody loses one halfway through reading it. Allow long enough for a whole sentence. A pop-up that someone misses is still waiting for them on the notifications page.', 'notifications-for-hivepress' ),
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
				// The old description said these applied "everywhere notifications appear", which is
				// not true of three of them: the notifications page takes its background, its text
				// colour and its corners from the theme, so an owner changing them and then
				// checking that page saw nothing happen and went looking for a caching problem.
				'description' => esc_html__( 'Colours and sizing for notifications. Text size, text weight, accent colour and icon shape apply wherever notifications appear, including the notifications page. Background colour, text colour and corner radius apply to the pop-ups and the bell dropdown only, because the notifications page takes its background and text colour from your theme.', 'notifications-for-hivepress' ),
				'_order'      => 30,

				'fields'      => [
					'notification_toast_background_color' => [
						'label'       => esc_html__( 'Background Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The background of the pop-ups and the bell dropdown. Press Default beside the box to put the original white back; an empty box gives you the same white rather than anything see-through.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#ffffff',
						'_order'      => 10,
					],

					'notification_toast_text_color'       => [
						'label'       => esc_html__( 'Text Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The main text colour inside the pop-ups and the bell dropdown. Keep it readable against the background above. Press Default beside the box to put the original near-black back; the notifications page ignores this and uses your theme\'s own text colour.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#1a1a1a',
						'_order'      => 20,
					],

					'notification_toast_accent_color'     => [
						'label'       => esc_html__( 'Accent Colour', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The stripe down the side of each pop-up, the dot that marks a notification as unread, the links, and the round tile behind the small picture when a notification shows an icon rather than a photo. Your brand colour usually suits. Press Default beside the box to put the original blue back.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#3d9cd2',
						'_order'      => 30,
					],

					'notification_icon_shape'             => [
						'label'       => esc_html__( 'Icon Shape', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The shape of the small picture at the start of every notification. That picture may be someone\'s profile photo, a listing photo or a plain icon, and your choice applies to all three. Circles suit sites about people; squares suit sites about places or products.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'How rounded the corners of the pop-ups and the bell dropdown are. Set 0 for square corners; an empty box is not the same thing and gives you the standard 3px back.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'How rounded the buttons added by this extension are, such as Mark all as read, Clear read and Settings. Set 0 for square corners; an empty box leaves them shaped like the other buttons in your theme.', 'notifications-for-hivepress' ),
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
						'description' => esc_html__( 'The small number shown on the bell and beside Notifications in the account menu. Left alone it matches the counts HivePress already shows elsewhere. Press Default beside the box to put that red back.', 'notifications-for-hivepress' ),
						'type'        => 'color',
						'default'     => '#ff5a5f',
						'_order'      => 70,
					],

					'notification_panel_width'            => [
						'label'       => esc_html__( 'Dropdown Width (px)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How wide the dropdown under the bell is on desktops and laptops. Any screen narrower than about 768 pixels ignores this and uses the full width instead, because a fixed width would run off the edge. Leave the box empty for the standard 320.', 'notifications-for-hivepress' ),
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
				 * The old wording, "anything left unticked keeps working exactly as HivePress sends
				 * it", was true of most of these and flatly wrong about the extras, which have no
				 * HivePress email behind them at all: unticking one of those does not hand it back
				 * to HivePress, it switches the notification off. An owner who read that sentence
				 * as "unticking is safe, it only removes the choice" was turning features off
				 * without knowing.
				 *
				 * The email-less ones are NOT named here, on purpose. Each of them exists only when
				 * its extension is active, so a static list read wrongly on any site missing one -
				 * a staging install with no Badges extension was told about a "Badge Earned" box
				 * that was nowhere on its screen. alter_settings() appends the sentence naming
				 * exactly the ones this site really has.
				 */
				'description' => esc_html__( 'Which notifications this plugin handles. Tick one and it appears on the notifications page, on the bell and as a pop-up, and each person chooses how they receive it, including whether they still want the email. Leave one unticked and this plugin ignores it: nothing appears on your site, and any email HivePress already sends carries on exactly as before, with nobody able to switch it off. Untick every box in a group and none of that group is handled.', 'notifications-for-hivepress' ),
				'_order'      => 40,

				// The per-group type checkboxes are added by the notification component, because the
				// list depends on which extensions are active.
				'fields'      => [],
			],

			'defaults'   => [
				'title'       => esc_html__( 'Defaults', 'notifications-for-hivepress' ),
				'description' => esc_html__( 'What each kind of user receives until they choose for themselves on their own Notification Settings page. On-site is the notification itself: the entry on their notifications page, the count on the bell and the pop-up. Email decides only whether the email HivePress already sends still reaches them. These boxes apply to people who joined long ago as well as new arrivals, and anyone who has saved that page keeps their own choice whatever you set here.', 'notifications-for-hivepress' ),
				'_order'      => 50,

				// One field per user role, added by the notification component, which also appends a
				// sentence about Push when push is actually on offer.
				'fields'      => [],
			],

			'text'       => [
				'title'       => esc_html__( 'Text', 'notifications-for-hivepress' ),

				// "Leave one empty to use the subject line of the email HivePress already sends" was
				// true of about six of these boxes and wrong about the other forty, which have
				// wording of their own built in. The grey text inside each box is showing that
				// wording, so the description was actively contradicting what the owner could see.
				'description' => esc_html__( 'The wording each notification shows on your site. Leave a box empty and the grey wording inside it is used, so you only need to fill in the ones you want to change. Where that grey wording reads "The email subject is used", this plugin has no sentence of its own for that notification and the subject line of the HivePress email is shown instead. A token is a placeholder written between percent signs, such as %listing.title%, which is swapped for the real name, date or amount when the notification is sent; hover the question mark beside a name to see the tokens that notification can use.', 'notifications-for-hivepress' ),
				'_order'      => 60,

				// One field per enabled type is added by the notification component, because the
				// list of types and the tokens each one offers depend on the active extensions.
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
				'description' => esc_html__( 'Notifications build up over time, so you can have older ones deleted automatically. Nothing is deleted unless you fill in the box below. This is the only setting on this page that removes anything while the plugin is in use, and it applies to everyone on your site, not only you.', 'notifications-for-hivepress' ),
				'_order'      => 65,

				'fields'      => [
					'notification_storage_period' => [
						// The destructive verb belongs in the label, because on a narrow screen the
						// label is all there is: the tooltip does not open below 782px.
						'label'       => esc_html__( 'Delete Notifications Older Than (days)', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Once a day, every notification older than this is deleted for good, for everyone on your site, whether or not it has been read. It cannot be undone. Leave the box empty and nothing is ever deleted, which is how a new site starts. If you are unsure, begin with a high number such as 365 and lower it later.', 'notifications-for-hivepress' ),
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
				'description' => esc_html__( 'Some notifications are not about something that just happened, but about how a vendor is doing: their weekly views, a listing suddenly getting attention, a response rate going up or down. Nothing announces those, so once a night this compares each vendor with how they were and tells them what changed. It needs Vendor Analytics Pro or Trust Signals to have anything to compare. The work is spread over several small background jobs and never runs while somebody is loading a page.', 'notifications-for-hivepress' ),
				'_order'      => 62,
				'fields'      => [
					'notification_enable_insights' => [
						'label'       => esc_html__( 'Performance Notifications', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Look for changes worth telling vendors about', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'Untick to stop the nightly comparison entirely. The individual notifications stay listed under Types so you can see what you have turned off, and nothing already sent is removed.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 10,
					],
					'notification_insights_batch'  => [
						'label'       => esc_html__( 'Vendors Per Job', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'How many vendors each background job looks at before handing over to the next one. Smaller is gentler on a shared host and takes more jobs to get through everyone; larger gets it done sooner. The default suits almost every site, and there is no need to change it unless your host complains about long-running jobs.', 'notifications-for-hivepress' ),
						'type'        => 'number',
						'default'     => 50,
						'min_value'   => 1,
						'max_value'   => 500,
						'_parent'     => 'notification_enable_insights',
						'_order'      => 20,
					],
					'notification_insights_cap'    => [
						'label'       => esc_html__( 'Vendors Per Night', 'notifications-for-hivepress' ),
						'description' => esc_html__( 'The most vendors a single night will look at, however many you have. It is a safety valve rather than a target: on a site with fewer vendors than this it never comes into play. If you have more, the pass stops when it reaches this number and starts again from the beginning the following night, so everyone is still reached, just not all on the same day.', 'notifications-for-hivepress' ),
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
				'description' => esc_html__( 'Your notifications and settings are kept if you delete this plugin, so you can reinstall it and carry on. WordPress shows its own warning on the delete screen saying the data goes too, but that warning is the same for every plugin and does not apply here unless you tick the box below. Switching the plugin off never removes anything. The box below is the one exception: with it ticked, deleting the plugin permanently erases every notification and every choice your members have made, and nothing on the delete screen will remind you that you asked for it.', 'notifications-for-hivepress' ),
				'_order'      => 70,

				'fields'      => [
					'notification_delete_data' => [
						'label'       => esc_html__( 'Delete All Data', 'notifications-for-hivepress' ),
						'caption'     => esc_html__( 'Delete everything when this plugin is deleted', 'notifications-for-hivepress' ),

						// The list is spelled out rather than summarised as "all data", because the
						// words it used to summarise with - "channel choices", "push subscriptions" -
						// are not words this screen ever introduces, and a marketplace owner reads
						// the second as paid subscriptions.
						'description' => esc_html__( 'Leave this unticked unless you are certain. With it ticked, deleting the plugin also removes every notification on the site, each person\'s own choice of how they are notified and their quiet hours, the record of which browsers have agreed to show push notifications, your wording for each notification, the announcements you have sent, the sent and opened totals on the Statistics page, and every setting on this page. It cannot be undone and nothing asks you to confirm at the time, so copy down anything you want to keep first. Deleting the plugin with this unticked keeps all of it.', 'notifications-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 10,
					],
				],
			],
		],
	],
];
