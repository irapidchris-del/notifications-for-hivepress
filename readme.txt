=== Notifications for HivePress ===

Contributors: ChrisB
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.9.0
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
* A settings page where they choose, per notification, whether they want a pop-up, an email, both
  or neither.
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

These have no email behind them, so they're pop-up only and the settings page offers just that
channel. Neither fires when the owner is the one who did it, and each notifies once per favourite or
review. Add your own with the `hivepress/v1/notification_types` filter and `add_notification()`.

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

= 1.9.0 =
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
  header buttons, and the empty-inbox icon on the notifications page is properly centred.
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
