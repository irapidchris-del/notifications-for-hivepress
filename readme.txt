=== Notifications for HivePress ===

Contributors: ChrisB
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.9.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds on-site notifications with pop-ups and a notification history page, mirroring the emails that
HivePress and its extensions already send.

== Description ==

Every notification HivePress sends goes out as an email. This plugin listens to those emails and
creates a matching on-site notification for whoever the email was addressed to, so users see what's
happening without leaving the site.

Because it works from the email layer rather than from each extension, it covers every extension
that sends email, including any installed later. Nothing is hard-coded per extension.

= What users get =

* Pop-up notifications, with a countdown bar that pauses when hovered and a close button on each.
* A Notifications page under their account, grouped by date, with a filter by type.
* A settings page where they choose, per notification, whether they want it on-site, by email, by
  push, or not at all. On-site covers the notifications list, the bell menu and pop-ups.
* An unread count next to Notifications in the account menu.
* Mark one or all as read, and delete notifications they're finished with.

= What admins get =

Under HivePress > Settings > Notifications:

* **Types** - choose which notifications users can manage, how long to keep them, and the default
  channels for each user role. Anything left unticked is sent by HivePress as usual and users cannot
  turn it off.
* **Text** - write the wording for each notification, using tokens. Leave one blank to use the email
  subject as it is.
* **Delivery** - how often an open tab checks for new notifications, web push, the header bell, the
  sticky header, and statistics.
* **Pop-ups** - turn pop-ups on or off, set the position, cap how many show at once, choose whether
  they hide themselves, and set how many seconds they stay on screen.
* **Appearance** - background, text and accent colours, plus corner radius.

== How it works ==

**Capture.** HivePress fires `hivepress/v1/emails/{email_name}/send` when an email is sent, but
there is no single hook covering every email. The plugin enumerates the registered email classes
with `hivepress()->get_classes( 'emails' )` and attaches to each one, which amounts to the same
thing and keeps working when a new extension is activated.

**Recipients.** The recipient is resolved from the address the email is sent to, never from the
`user` token. HivePress addresses some emails (listing reported, listing submitted, vendor
registered) to the site administrator while setting the `user` token to the person who triggered
them, so reading the token would notify the wrong account.

**Storage.** Notifications are comments of the `hp_notification` type, matching how the Messages,
Reviews and Favorites extensions store their data. The read flag uses `comment_karma`, so
`comment_approved` stays at 1 and notifications never land in the pending comment queue. The type
is registered as non-public, so HivePress excludes it from admin comment screens and counts.

**Wording and tokens.** Each type has its own text field in the admin, rendered with HivePress's own
`replace_tokens()`. Tokens that hold an object need a field after a dot (`%listing.title%`,
`%sender.display_name%`), and a bare `%sender%` is left as-is, which is how HivePress behaves. A
fallback goes after a pipe: `%listing.title|your listing%`.

The tokens are listed under each field. The list an email declares is only there for the HivePress
email editor, so it's missing for the emails HivePress sends to the site administrator and it never
says which tokens hold an object. The tokens each type really uses are therefore recorded the first
time it fires, and the hint is built from those.

**Deep links.** Notifications link wherever the email links, taken from the type's link token. Those
are already deep links: the Messages extension's `message_url`, for example, points at the
conversation with an anchor for the exact message, so following a notification lands on the message
itself. Use the `hivepress/v1/notification_url` filter to pin a different link for a type.

**Extras.** Two notifications HivePress has no email for are included, and each is registered only
when its extension is active:

* *Listing Favourited* - tells the listing owner when someone favourites their listing.
* *Review Received* - tells the listing owner when a review is left, or when a moderated review is
  approved.

These have no email behind them, so they're on-site and push only, and the settings page offers just
those channels. A group's tick boxes are the union of what its notifications support, so ticking
Email on a group does not promise an email for the ones that have none. Neither fires when the owner
is the one who did it, and each notifies once per favourite or review. Add your own with the `hivepress/v1/notification_types` filter and `add_notification()`.

**Channels.** Each user picks the channels they want per type. Turning the email off doesn't empty
the email body, because that's how HivePress disables an email site-wide and it would also hide
whether there was anything to send in the first place. Instead the email is stopped at `pre_wp_mail`,
matched on both address and subject so an unrelated email sent in between is never caught. A type
with nothing ticked is stored as an empty array rather than dropped, which is what tells "off" apart
from "never set". Adding a channel means adding it to one array, so SMS in v2 needs no rework here.

**Speed.** The pop-up payload is not printed into the page, because a full-page cache would serve
one user's notifications to another. Instead the browser asks for it once after the page has
loaded, during an idle moment. That request reads a single user meta value rather than querying the
comments table, and the unread count and type list are kept in user meta too, so the common path
costs one cached read. There is no polling.

= Notes and limits =

* An email whose body has been emptied is skipped, matching HivePress, which doesn't send it either.
  No on-site notification is created for it.
* Notifications appear on the next page load, so a user reading a page when something happens sees
  it on their next click rather than immediately.
* The recipient address is read from a protected property on the HivePress email class, because
  core exposes no getter for it. If HivePress ever adds one, that read should switch to it.

**Live checking.** An open tab checks for new notifications every 60 seconds by default (set 15 to
600, or clear the field to switch it off), so new ones appear without a reload. The check is paused while the tab is in the background and runs once as soon as it comes
back, and each check reads a single cached value, so it stays cheap.

**Web push.** Reaches people who have the site closed. Keys are generated for you on first use, and
the push itself carries no payload: the browser wakes the plugin's service worker, which fetches the
newest notification over a scoped token, so no payload encryption is involved and the token can't be
used as a login. Needs HTTPS. People are asked for permission only after a few visits (you choose
how many) and only on a click, because a refused prompt can never be asked again. Chrome, Firefox
and Edge work as standard; iOS Safari only allows push for sites added to the home screen, which is
an Apple rule rather than a plugin one.

**Header bell.** An optional bell with its own unread count and a dropdown of recent notifications,
added through the HiveTheme site header area that ListingHive provides. It's deliberately separate
from the count ListingHive already shows on the menu, which counts unread messages, unpaid bookings
and pending orders: these notifications mirror those same events, so adding to that count would
count everything twice. An optional sticky header setting keeps the bell on screen for themes that
don't already do this.

**Announcements.** HivePress > Announcements sends an on-site notification to everyone, to one
role, to your vendors, or to specific users picked by username or email, in scheduled batches of fifty so a big user base can't time the request out. It supports
%user.display_name% and an optional link, there is deliberately no opt-out, and it sits on its own page rather than in the settings, because
settings are saved and announcements are sent.

**Quiet hours.** Each user can set a nightly window, including one that runs past midnight, during
which no pop-ups or pushes arrive. Notifications still land in their list, so nothing is lost.

**Privacy.** Notifications are personal data, so they're included in the WordPress personal data
export and erasure tools, along with the person's notification settings.

**The list.** Search, a type filter, mark all as read, clear read, per-notification mark as
read/unread and delete, an icon per type, and the sender's avatar or the listing photo where one is
available. Anonymous per-type counts of how many notifications are sent and opened can be switched
on under Delivery, so you can tell which wording works. A pop-up sound can be switched on under
Pop-ups; browsers only allow it after the person has interacted with the page.

**WP-CLI.** `wp hivepress notifications types`, `... recount` (rebuilds the cached counts) and
`... cleanup` (runs the storage-period deletion now).

== Changelog ==

= 1.9.11 =
* Added the translation template, so the plugin can now be translated. It goes in
  wp-content/languages/plugins/, which is where Loco Translate and translate.wordpress.org put
  translations and the only place a plugin update cannot overwrite them.
* The unread count strings now carry a gettext context, so languages that need different singular
  and plural wording can supply both. English spells them the same, which previously collapsed them
  into a single untranslatable entry.

= 1.9.10 =
* The on-site channel is now labelled "On-site" rather than "Pop-up". Turning it off does not just
  stop the pop-up, it stops the notification being created at all, so anyone unticking it to quieten
  pop-ups was silently losing their notifications list and bell as well.
* Each group on the notification settings page now says what its channels cover, and that a channel
  only applies to the notifications in that group which have one behind them. Ticking Email on a
  group never promised an email for every notification in it, but nothing said so.
* Push notifications now show the notification's own picture where it has one, so an earned badge
  arrives with the badge on it rather than the site icon.

= 1.9.9 =
* Page two of the notification list is no longer served with a "404 Not Found" status. WordPress
  decides the status before HivePress renders the page, so a list full of real notifications was
  being reported as missing, which caching layers and crawlers are entitled to act on.
* The tick beside a notification now says what the next click will do. It stayed on "Mark as read"
  after the row had been read, while clicking it marked the row unread.
* The push notification button no longer keeps the label "Waiting for your browser" after it has
  finished and been hidden.

= 1.9.8 =
* Fixed the unread count going stale when a push notification arrived. The count and the list were
  updated as part of showing the pop-up, so once the pop-up was correctly skipped - the operating
  system having already announced it - neither happened, and the page could sit several behind
  until it was reloaded.
* Fixed the same notification occasionally being announced twice, once by the operating system and
  again as a pop-up moments later. The record of what had been announced only lived in the page
  that was told, so a reload, or a second tab, lost it.
* A notification arriving while the notifications page is open is now added to the list, instead of
  only moving the counter above a list that had not changed.
* Fixed the push button on the notification settings page reading "Waiting for your browser" for
  ever after push had actually been enabled.
* Fixed the pagination on the notifications page showing a list bullet beside every page number.
* Fixed the Settings button on the notifications page turning its text the theme's link colour on
  hover, which on a coloured button was close to unreadable.
* Sending an announcement from the dashboard widget now returns you to the dashboard.

= 1.9.7 =
* The Announcements dashboard widget now fits its card. It was built from the same wide two-column
  layout as the full settings page, which pushed the fields out past the edge in the narrow
  dashboard column; it now stacks, with each field full width.
* The explanations on the Announcements page and widget have been shortened and moved into
  tooltips, the same hover help HivePress uses on its own settings, so the form reads as a form
  rather than a page of instructions.

= 1.9.6 =
* The Announcements page now has a History tab: what was sent, when, to whom, how many people it
  reached and who sent it, with a Resend button on each. Resend goes to that audience as it stands
  today rather than to the people who received it originally, so a role that has grown reaches its
  new members; a send to named people goes back to those same people. The last 50 are kept.
* A Badge Earned notification now shows the badge that was actually awarded: its own image where
  one is set, otherwise its own icon on its own colour, matching how badges appear on a profile.
* Fixed the bell colours only applying on keyboard focus, not on hover.
* Fixed Hide Combined Counter also hiding the Notifications and Messages counts inside the burger
  menu. It now hides only the combined number on the menu button itself.
* Fixed push notifications going quiet after a while. Skipping the notification when a tab was
  visible left the browser seeing silent pushes, which it warns about and then throttles. Every
  push now shows a notification, and the page skips its own pop-up for that one instead, so it is
  still announced only once.

= 1.9.5 =
* Fixed the notification settings page appearing to forget your choices. A channel you unticked
  sprang back the moment it saved, and saving again then wrote those restored values over the top,
  so a change could be lost for real. The setting itself was always stored correctly.
* Fixed an empty colour setting turning black. Opening the settings page and pressing Save, without
  touching a colour at all, stored black for any colour left empty, which is how the bell
  background could suddenly appear as a solid black circle.
* A push notification no longer arrives twice when you are already looking at the site. If a tab is
  open and visible it now shows the pop-up straight away, and the operating system notification is
  kept for when the site is closed, which is what it is for.
* Hide Theme Counter has become two settings, because HivePress shows two different numbers:
  "Hide Combined Counter" hides the total beside your name and on the menu button, which adds up
  unread messages, unpaid bookings, booking requests and pending orders; "Hide Messages Counter"
  hides the separate unread count on the Messages menu item. The old setting also targeted the
  wrong element on some layouts and did nothing.
* The channels on the notification settings page now appear in the same order in every row.
* Corrected "(1 users)" to "(1 user)" on the Announcements page, and the page now says plainly that
  announcements bypass each person's channel choices while still respecting quiet hours.

= 1.9.4 =
* The Bell Icon setting now uses HivePress's own icon picker, so you can choose from the full
  Font Awesome set with a live preview and a search box, rather than the short list we used to
  offer.
* The buttons on the notifications page, and the push button on the settings page, now use your
  theme's own button styling instead of ours, so they match every other button on the site.
* The header bell now has four colour settings: the icon and the circle behind it, each with its
  own resting and hover colour. Leave any of them empty and the bell looks exactly as it always
  has, so nothing changes unless you choose it.
* Fixed the Bell Colour setting having no effect, and the Dropdown Width setting having no effect:
  in both cases a fixed value later in the stylesheet was quietly overriding the setting.
* Fixed the Text Size setting having no effect on pop-up and notification text: a fixed size in
  the stylesheet was quietly overriding it.
* Notification text sizes now scale with your theme's own type sizes rather than being fixed in
  pixels.
* Added support for the HivePress Badges extension. Earning a badge now tells the person who
  earned it, with wording you can change like any other notification, and a link to the profile
  where their badges are shown.
* Fixed notification types from a newly installed extension being switched off without saying so.
  The saved list of types only ever held the types that existed when it was written, so anything
  added later, whether by Badges or by installing Bookings or Memberships, arrived silently
  disabled. Types added from now on switch themselves on and appear ticked; anything you have
  deliberately unticked stays that way.
* Fixed pop-ups being lost when the site sat in a background tab. They were fetched, which empties
  them from the queue, but a background tab never draws them, so they disappeared unseen. The
  queue is now left alone until the tab is actually being looked at.
* Fixed the unread count only updating in one place. Themes show the account menu several times
  over, and marking something read updated the first one, leaving the rest, the "x unread" heading
  and the "Mark all as read" button showing the old number.
* Fixed the Opened count and open rate on the Statistics page always showing zero: following a
  notification was recorded, but the browser cancelled the request as it moved to the new page.
  The Statistics description now also says exactly what counts as an open.
* Fixed announcements being cut off mid-word in the bell dropdown instead of wrapping.
* Fixed an empty grey button appearing beside "Push notifications are on for this device".
* The Announcements page now shows how many people each audience contains, counted live, instead
  of explaining what the roles mean. The old wording was wrong: HivePress does promote a user to
  Contributor when their vendor profile is published, so on most sites vendors are Contributors.
* The Announcements page now says that announcements are never emailed and that quiet hours apply.
* Push notification updates now take effect immediately rather than waiting for every tab on the
  site to be closed.

= 1.9.1 =
* Fixed the bell timestamps for real this time: the server computed them but never sent them.
  The payload now carries a relative time and a short absolute fallback.
* Deleting a notification can now be undone for a few seconds. Deletions go to the WordPress
  trash, which empties itself after around thirty days, so nothing extra is stored.
* Added five notification sounds to choose from: Chime, Ping, Pop, Bell and Soft.
* Added Bell Colour and Dropdown Width options, and made the bell and dropdown text follow the
  Text Size and Text Weight settings.
* Added a Bell Icon setting: choose the header bell's icon from a dropdown that previews each one,
  such as an inbox or envelope, with the bell's icon, colour and size grouped together under the
  Header Bell.
* Added Settings and Announcements quick links to the plugin's row on the Plugins screen, and a
  clear notice when HivePress is missing instead of silently doing nothing.
* On first run, unread messages from before the plugin was installed now appear in the list,
  backdated and quiet: no pop-up, push or statistics for old news, and never any duplicates for
  users who already have notifications.
* Push notifications are now on by default; keys are still created on first use, and people are
  still only asked for browser permission after a few visits.
* Fixed the Sound Style dropdown, which sorted above the Sound setting it belongs to and ended up
  missing from the screen.
* The header bell now holds its place when other extensions, such as Requests, add their own
  header buttons - the shared header area is kept to a single row instead of stacking icons -
  and the empty-inbox icon on the notifications page is properly centred.
* The colour settings now use the WordPress colour picker, with a box that takes a hex code
  such as #000000 directly.
* Style and script updates now reach browsers immediately: asset addresses change whenever the
  files do, so stale copies can no longer be served from caches after an update.
* Link labels are now contextual where a type knows better, so a completed booking says
  "Leave a review" instead of "View".
* Moved delivery statistics to their own page under HivePress, shown only while counting is on,
  with links from the Settings tooltip and the Announcements page.
* Added units to every setting that needed one, such as Hide After (seconds) and Storage Period
  (days), and gave every option a plain-language description.
* Push notifications now carry the site icon on Android status bars as well.
* The personal data export and erasure tools now also cover notifications sitting in the trash
  after a delete, which the undo feature above made possible.
* Fixed the settings-page notice for when push is on but the server cannot generate keys; a logic
  slip meant it could never actually appear.
* Push requests now refuse private and loopback addresses, so a crafted subscription cannot point
  the server at something internal.
* Deleting the plugin now removes everything it stored: notifications, settings, cached counts
  and queued announcement batches. Deactivating still keeps everything.
* Hardened link handling: the scripts now only ever place an http or https link in a notification,
  as a second line of defence behind the sanitising the server already does.
* Added automatic updates. New versions released on GitHub now appear on your Plugins page like
  any other update, with a details popup and one-click install.
* The declared PHP requirement is now 7.4, matching HivePress itself. Nothing changed in what the
  plugin does: HivePress already required 7.4, so no site that could run this plugin is affected.
* The plugin now registers with HivePress in a way that survives a renamed plugin folder, so an
  install from a GitHub source download works the same as one from the release zip.
* The notifications page no longer needs the permalinks to be re-saved after a fresh install:
  activating the plugin now refreshes them itself.
* A cleared "Ask After (visits)" field now falls back to the default of three visits instead of
  asking on the very first one.
* Two identical announcements sent in quick succession now both deliver in full.
* Fixed the unread count beside Notifications in the account menu, which was drawn in the accent
  colour as a stretched oval instead of matching the counts HivePress shows elsewhere. It now
  inherits HivePress's own styling, so it looks the same as the unread messages count and follows
  any theme that restyles it.
* The unread count on the bell now uses the same red as HivePress rather than a slightly different
  one, and keeps its shape at two and three digits.
* Added an Unread Badge Colour setting under Appearance, for anyone who wants the counts in a
  colour of their own. Left alone, it matches HivePress.
* Fixed the first-run seeding of old unread messages, which checked a notification type that
  doesn't exist ("message_receive" rather than the Messages extension's "message_send") and so
  never ran, and read the wrong read flag, which would have seeded long-read messages once it did.
* Push keys now also generate on servers whose OpenSSL has no configuration file, which is common
  on Windows hosting: a minimal configuration ships with the plugin and is used only as a
  fallback. Without it, push was silently unavailable on such servers.
* Fixed the times shown on the notifications page, which were shifted by the site's UTC offset
  on any site whose timezone isn't UTC, and the Today/Yesterday grouping, which could lose its
  "Yesterday" label in the evening for the same reason.
* Fixed the Bell Icon, Bell Size, Bell Colour and Hide Theme Counter settings sorting above the
  Header Bell checkbox that reveals them, the same slip the Sound Style fix covered.
* "Ask After (visits)" now counts real visits, one per browsing session, rather than page views.
* The push service worker now reaches the site on installs without pretty permalinks as well.
* Old notifications past the storage period are now removed in batches until none remain, so a
  busy site can no longer build up a backlog the daily clean-up couldn't clear.

= 1.8.0 =
* Fixed push: the availability of push wrongly depended on per-role default channels, which could
  remove the settings-page button and stop subscriptions entirely.
* When push is on but the server cannot generate keys, the settings page now says so instead of
  showing nothing.
* Rebuilt the sticky header: it now fixes the theme's real header bar seamlessly at the exact
  scroll position, with no animation and no jump, and works under themes with hidden overflow.
* Added an option to hide the theme's own menu counter, which counts the same events as the bell.
* Added a Bell Size option, and Text Size and Text Weight options under Appearance.
* Token hints in the Text section now list every token with a real example field before the first
  send, shown as the field's tooltip.
* Delivery statistics now have a home: a table at the bottom of the Announcements page.
* Added an Announcements dashboard widget, cross-links between Announcements and the settings tab,
  and name and email suggestions while typing in the Users field.
* Hardened the bell timestamps against clock skew, and made the list action buttons larger.

= 1.7.0 =
* The Booking Completed notification now opens the Write a Review window on the listing it links
  to, using the same trigger HivePress binds, so the review restriction settings keep working.
* Bell dropdown items now show the type and how long ago each arrived, and linked items carry a
  small chevron.

= 1.6.0 =
* Added a button on the settings page to switch push notifications on for the current device.
* Unread items in the bell dropdown can now be dismissed with their own control, and a
  notification without a link, such as an announcement, can be dismissed by tapping it.
* Added a separate pop-up position for small screens: top, centre or bottom, defaulting to
  bottom.
* Centred the icon on the empty notifications page properly.

= 1.5.0 =
* Grouped the settings: the admin Types list and each user's settings page now work in areas
  (Listings, Messages, Bookings, Orders & Payouts, Requests & Offers, Memberships, Account) instead
  of forty separate rows, and only the groups your active extensions provide appear.
* Announcements now reach everyone: the user opt-out has been removed and the type no longer
  appears in user settings.

= 1.4.0 =
* Notification images now use the person's HivePress profile image, falling back to Gravatar.
* Announcements can now be sent to specific users by username or email, and to vendors.
* Explained on the Announcements page and role defaults that these are WordPress roles, since
  HivePress adds none of its own.
* Live checking is now on by default at 60 seconds, so new notifications appear without a reload.
* The unread count now also shows in the browser tab title.
* Pop-ups now default to the bottom left, and the colour pickers start on the plugin's palette
  instead of opening black.
* The account menu badge is now a proper circle.
* Removed the email body preview from the notification list.
* Canceled and unpaid bookings no longer trigger the Booking Completed notification.

= 1.3.0 =
* Added live checking for new notifications while a tab is open, paused in the background.
* Added web push with automatic VAPID keys, a payloadless service worker, and a permission prompt
  that waits for a few visits.
* Added the notifications to the WordPress personal data export and erasure tools.
* Added an optional header bell with a recent-notifications dropdown, and an optional sticky header.
* Added an Announcements admin page for sending a notification to everyone or to one role.
* Added quiet hours, per-role default channels, search, mark as read/unread, mark all as read, clear
  read, per-type icons, avatars and listing photos, anonymous sent/opened counts, an optional pop-up
  sound, a Booking Completed notification that opens the Write a Review window on the listing, and WP-CLI commands.

= 1.2.0 =
* Token hints are now built from the tokens a notification really uses, so they're accurate for the
  types that declare none.
* Verified against the Bookings, Marketplace, Requests and Memberships extensions.

= 1.1.0 =
* Added per-type notification text with tokens, editable in the admin.
* Added explicit deep links in pop-ups and the notification list.
* Added Listing Favourited and Review Received, which HivePress has no email for.

= 1.0.0 =
* Initial release.
