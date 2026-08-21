=== Notifications for HivePress ===

Contributors: ChrisB
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
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

* **Delivery** - how often an open tab checks for new notifications, web push, the header bell and
  where it sits, the sticky header, and statistics.
* **Pop-ups** - turn pop-ups on or off, set the position, cap how many show at once, choose whether
  they hide themselves, and set how many seconds they stay on screen.
* **Appearance** - background, text and accent colours, the shape of the icon on each notification
  (circle, rounded square or square), corner radius, text size and weight, and the radius of the
  buttons this extension adds.
* **Live preview** - a sample pop-up beside the settings that redraws as you change them, so you can
  see the result before saving. It is drawn by the same stylesheet the site uses, so what you see is
  what your visitors get.
* **Types** - choose which notifications users can manage. Anything left unticked is sent by
  HivePress as usual and users cannot turn it off.
* **Defaults** - the channels each user role starts with before anyone changes their own settings,
  and how long notifications are kept before they're deleted automatically.
* **Text** - write the wording for each notification, using tokens. Leave one blank to use the email
  subject as it is.
* **Removing the Plugin** - your data is kept when the plugin is deleted, unless you tick the box
  here that says otherwise.

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

**Extras.** Four notifications HivePress has no email for are included, and each is registered only
when the extension it depends on is active:

* *Listing Favourited* - tells the listing owner when someone favourites their listing. Needs
  HivePress Favorites.
* *Review Received* - tells the listing owner when a review is left, or when a moderated review is
  approved. Needs HivePress Reviews.
* *Booking Completed* - asks the customer how their booking went once it's finished. Needs HivePress
  Bookings.
* *Badge Earned* - tells someone when they've been awarded a badge. Needs HivePress Badges.

These have no email behind them, so they're on-site and push only, and the settings page offers just
those channels. A group's tick boxes are the union of what its notifications support, so ticking
Email on a group does not promise an email for the ones that have none. The favourite and review
ones don't fire when the owner is the one who did it, and each notifies once per favourite or
review. Add your own with the `hivepress/v1/notification_types` filter and `add_notification()`.

**Channels.** Each user picks the channels they want per type. Turning the email off doesn't empty
the email body, because that's how HivePress disables an email site-wide and it would also hide
whether there was anything to send in the first place. Instead the email is stopped at `pre_wp_mail`,
matched on both address and subject so an unrelated email sent in between is never caught. A type
with nothing ticked is stored as an empty array rather than dropped, which is what tells "off" apart
from "never set". Adding a channel means adding it to one array; a channel members must opt into
themselves (such as SMS via Twilio for HivePress) is also named on the
`hivepress/v1/notification_optin_channels` filter, so role defaults never switch it on for anyone.

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
which no pop-ups, pushes or text messages arrive. Notifications still land in their list, so nothing
is lost.

**Privacy.** Notifications are personal data, so they're included in the WordPress personal data
export and erasure tools, along with the person's notification settings.

**Removing the plugin.** Deactivating it never removes anything. Deleting it keeps your
notifications, everyone's channel choices and every setting, so you can reinstall and carry on
where you left off. Only the things that would otherwise be left pointing at a plugin that no
longer exists are cleared: the cached update lookup and any queued announcement batches. If you
genuinely want it all gone, tick "Delete All Data" under Removing the Plugin before you delete.
WordPress will warn you on its delete screen that the data goes too, but it prints that for every
plugin that has an uninstaller, and here it's only true if you ticked that box.

**The list.** Search, a type filter, mark all as read, clear read, per-notification mark as
read/unread and delete, an icon per type, and the sender's avatar or the listing photo where one is
available. Anonymous per-type counts of how many notifications are sent and opened can be switched
on under Delivery, so you can tell which wording works. A pop-up sound can be switched on under
Pop-ups; browsers only allow it after the person has interacted with the page.

**WP-CLI.** `wp hivepress notifications types`, `... recount` (rebuilds the cached counts) and
`... cleanup` (runs the storage-period deletion now).

== Changelog ==

= 1.2.0 =
Nineteen new notifications, covering six companion extensions that had no way of telling anybody
anything, and a badge for the Action Bar.

* **New - Additional Gallery notifications.** Somebody liking or commenting on one of your photos,
  replying to your comment, liking your comment, buying access to your gallery, and their access
  ending. Bursts of likes and comments about the same photo roll into one notification that reads
  "Alice and 12 others liked your photo" rather than arriving as twelve separate pop-ups.
* **New - the gallery that vanishes.** When the photo review hides one of your galleries you are now
  told, instead of finding out by noticing it has gone.
* **New - Listing Moderation notifications.** A vendor whose listing is held for review is now told
  it is waiting to be checked, which nothing anywhere said before. The site owner gets their own
  copy carrying the risk score and what was actually found, linked to the listing in the dashboard,
  so the pending queue can be triaged rather than read. A listing pulled back by the photo check
  gets its own wording, because it happens after the vendor has already seen it live.
* **New - Holiday Mode notifications.** Confirmation when holiday mode goes on and off, with how
  many listings went with it, and, separately, a warning about any that stayed hidden because their
  listing period ran out while you were away. That last one was completely invisible: the behaviour
  is right, but vendors came back from holiday quietly short of listings. A listing kept hidden
  because holiday mode is still on now says so, which answers "why will my listing not publish?"
  before it is asked. And a vendor whose membership lapses while they are away is told their
  listings are stuck, rather than only finding out if they happen to try switching it off.
* **New - Verified.** Vendors are told when you verify them. Nothing said so before.
* **New - the monthly analytics summary** now has wording written for a notification list rather
  than falling back to the email subject.
* **New - "Unread notifications" as an Action Bar badge.** Requires Action Bar 1.4.0. The bar's
  existing "All notifications" counter has never counted these notifications: it is HivePress's own
  combined counter, and it is now labelled "Account activity" so the two are told apart. The
  Notifications page has always been available as an Action Bar link, so with the badge you can put
  the bell on the bar.
* **New - the badge stays live**, updating as notifications arrive rather than waiting for the next
  page load, and pop-ups pinned to the bottom of the screen now sit above the Action Bar instead of
  landing underneath it on a phone.
* **New - For Site Owners, Gallery and Performance groups** on the settings screen, so notifications
  meant for you are not mixed in with the ones meant for your members. Two of the owner ones start
  switched off, because they are useful on some sites and noise on others.
* Every one of these can be reworded and switched off exactly like the existing notifications, and
  each person still chooses how they receive it.
* Fixed: sending a web push no longer holds up the page that caused it. A notification that also
  goes out as a push - a new message, a review, a booking - had that push handed to Google, Mozilla
  or Microsoft during the visitor's own request, once for every browser signed up, and how long they
  take to answer is not something your server can hurry. One slow reply holds one visitor; enough of
  them at once holds up the whole site, which looks like the site going down for no reason rather
  than like anything to do with notifications. Pushes now go out a moment later in the background.
  Nothing changes for the person receiving one.
* Fixed: a browser that has dropped its subscription is now forgotten. When somebody clears their
  site data, uninstalls their browser or turns notifications off at the phone, the push service says
  so plainly - but the answer was never read, so the dead subscription stayed on the account and was
  tried again on every notification from then on. Those are now cleared the first time the push
  service reports one.
* Requires Additional Gallery 1.8.2, Automated Listing Moderation 1.6.9, Holiday Mode 1.7.4 or
  Action Bar 1.4.0 for the parts that concern them. Older versions are ignored rather than broken.
* Fixed - checking for updates no longer holds up an admin page. The check ran while WordPress was
  building the Plugins screen, so on a site with several of these extensions one page load made one
  request to GitHub after another and could sit there for many seconds, once, before behaving
  normally again for hours. The check now runs in the background moments later. Pressing Check for
  updates still asks GitHub straight away, because you are waiting for that answer.
* Fixed - "View details" is back on the Plugins screen. WordPress only offers that link for a
  plugin that has told it about itself, and this one stayed quiet whenever there was nothing to
  update to, which is almost always. The details popup, its changelog and the donate link inside
  it were all unreachable from the Plugins screen as a result.

= 1.1.1 =
* Checking for updates no longer reports "Could not reach GitHub" when nothing is wrong. GitHub allows a server only a limited number of anonymous update checks each hour, shared by every plugin on the site and, on shared hosting, by every other site on the same server. Running out is ordinary, but it was reported as though the site could not reach GitHub at all. Update checks now read the release from github.com, which sets no such limit, so the message no longer appears. If the limit is ever reached by some other route, the notice now says so plainly instead of blaming your connection.
* A failed update check no longer hides an update that is genuinely waiting. The last successful answer is kept until a later check succeeds, so a pending update stays on the Plugins screen instead of disappearing for an hour.

= 1.1.0 =
A live preview on the settings screen, internal naming, opt-in channel support, and a couple of
Plugins-screen fixes.

* **New - a Live preview panel on the Notifications settings tab.** A sample pop-up sits beside the
  settings and redraws the moment you change a colour, a size, the corner radius, the icon shape,
  the position or the countdown, so you can see the result before you press Save Changes. It uses
  the same stylesheet your site uses rather than an imitation of it, so nothing can look right here
  and wrong on your pages. There is a Play again button to watch a pop-up arrive, and on a wide
  screen the panel follows you down the page as you scroll. Nothing in the panel is a setting, so it
  cannot change anything by accident.
* **All of the plugin's PHP classes and files now carry a unique Hpnf prefix**, so they can never
  clash with HivePress core or another extension. Notifications, settings and preferences are
  unaffected, and no action is needed after updating unless you have custom code naming the old
  classes or hooks.
* **New - opt-in channel support.** A delivery channel can now be registered as strictly opt-in
  through the `hivepress/v1/notification_optin_channels` filter: role defaults never grant an
  opt-in channel, its boxes never appear under Defaults, and it reaches a member only once they
  tick it themselves on their Notification Settings page. Built for the SMS channel provided by
  Twilio for HivePress 1.7.0, where "on by default" would mean unsolicited texts.
* The Quiet Hours wording now covers text messages, and the note on the first notification group
  gains a sentence about texts where the SMS channel is available.
* The author name on the Plugins screen now reads "ChrisB @ HivePress Community", and a Donate
  link has been added to the plugin row and the "View details" popup.
* **New - a Button Radius setting.** One box under Appearance rounds every button this extension
  adds, such as Mark all as read, Clear read and Settings. Leave it empty and they keep the shape
  of the other buttons in your theme, which is what most sites want; set 0 for square corners.
* Fixed: characters such as a pound sign no longer appear as their HTML code in a pop-up, in the
  header bell or in a notification shown by the phone itself. An order total that read
  "Total &amp;pound;10.00" now reads "Total £10.00" everywhere. The wording is decoded as it is shown,
  so notifications saved before this update are corrected too.
* For developers: the prefix rename changes these public hook names, each gaining an hpnf_ prefix
  on its last part - `hivepress/v1/forms/notification_update` (and `/meta`) is now
  `hivepress/v1/forms/hpnf_notification_update`, `hivepress/v1/templates/notifications_view_page`
  (and `/blocks`) is now `hivepress/v1/templates/hpnf_notifications_view_page`,
  `hivepress/v1/templates/notification_settings_page` (and `/blocks`) is now
  `hivepress/v1/templates/hpnf_notification_settings_page`, `hivepress/v1/models/notification`
  (and `/meta`) is now `hivepress/v1/models/hpnf_notification`, and
  `hivepress/v1/blocks/notifications` is now `hivepress/v1/blocks/hpnf_notifications`. The
  component keys (`hivepress()->hpnf_notification` and friends) and the settings form's
  `hp-form--hpnf-notification-update` class carry the same prefix, and a ticked protected-forms
  entry (reCAPTCHA, or Turnstile for HivePress) is migrated automatically. Routes, REST paths, the stored comment type,
  option and meta names, WP-CLI commands and the `hivepress/v1/notification_*` hooks are all
  unchanged.

= 1.0.1 =
Deleting the plugin now keeps your data, and the tick boxes do what they say.

* **Unticking every box now means "none of these", not "all of them".** This affected two
  places, and in both the screen showed one thing and the plugin did the opposite. Under Types,
  clearing every box in a group switched that whole group back on, sometimes leaving more
  notifications enabled than before you touched it. Under Defaults, clearing every box for a
  role gave that role every channel instead of none. Both now do exactly what you set. If you
  had cleared a group or a role to mean "everything", set it explicitly instead, because that
  is no longer how it is read.
* **Fixed wording being mangled when you saved it.** Any token whose name began with two
  characters that happen to be hexadecimal was destroyed on save: `%badge.name%` became
  `dge.name%` and `%decline_reason%` became `cline_reason%`, so the notification went out with
  a fragment in it. The tokens the box recommends now survive being typed into it.
* **Every setting on the page has been rewritten.** Several were describing something the plugin
  did not do. Types said that unticking a notification left it "working exactly as HivePress
  sends it", which was untrue of the four that have no HivePress email behind them, so unticking
  one of those turned the feature off rather than handing it back. Text said an empty box used
  the email subject line, when almost every box has wording of its own built in, shown in grey
  inside it. Appearance implied every colour reached the notifications page, when three of them
  do not. Every setting now has a tooltip, including the notification groups and the per-role
  boxes, which had none at all.
* **Storage Period has moved and been renamed.** It is now "Delete Notifications Older Than
  (days)" in its own section, Deleting Old Notifications, instead of sitting under a heading
  that promised nothing could be lost. Your existing setting is unchanged.
* **Sticky Header no longer gets stuck.** Switching the header bell off after using a sticky
  header left the header pinned to the top of every page, with the tick box that would undo it
  no longer on screen.
* Translations no longer keep HTML entities in the notification wording, and the site name is
  no longer written into notifications as "Bob &amp;amp; Sons".
* Two pieces of internal hardening: the browser settings payload now keeps its true number and
  yes/no types rather than relying on WordPress's string conversion behaving, and the admin
  colour pickers detect a colour being picked by the picker's own signal rather than an event it
  never sends.
* The updater and web push no longer send your site address and WordPress version to GitHub or
  to the push service in the request header.

* **Your notifications and settings survive deleting the plugin.** Until now, deleting it removed
  everything straight away. It now keeps the lot unless you tick the new "Delete All Data" box in
  the Removing the Plugin section, so an accidental delete, or removing the plugin to install a
  clean copy, no longer costs you anything. WordPress still shows its own "(will also delete its
  data)" warning on the delete screen because it shows that for every plugin with an uninstaller,
  but it does not apply here unless you ticked the box. Switching the plugin off has never removed
  anything and still doesn't.
* Deleting the plugin always clears the things that would otherwise be left behind pointing at a
  plugin that is gone: the cached update lookup, and any announcement batches still queued.

= 1.0.0 =
First public release.

The plugin has been through a long private development and testing programme before this release,
so the changelog starts here rather than replaying that history. Everything below is what 1.0.0
does, and every item was verified on a live staging site before release.

* Mirrors every email HivePress and its extensions send as an on-site notification, working from
  the email layer so it covers extensions installed later without any per-extension code.
* Sensible wording out of the box for thirty-five notification types, written for a notification
  list rather than an inbox: "Welcome to your site, Alex. Your account is ready to use." instead of
  "User Registered". Every word is editable, and %site_name% is available in any of them.
* Adds four notifications HivePress has no email for at all: Listing Favourited, Review Received,
  Booking Completed and Badge Earned.
* A notifications page under the user's account, grouped by date, with search, a type filter,
  mark as read and unread, delete with an undo window, and clear read.
* Pop-up notifications with a pausable countdown, a per-user channel choice of on-site, email and
  push, and quiet hours.
* Web push for people who have the site closed, with keys generated on first use and a payloadless
  push, so nothing sensitive travels and the token cannot be used as a login.
* An optional header bell with its own unread count and dropdown, with configurable icon and
  background colours for both resting and hover states, and a pixel nudge for lining it up with
  whatever your theme puts beside it.
* Announcements: send a notification to everyone, one role, all vendors, or named users, with a
  history of what was sent and a resend that recomputes the audience at send time.
* An optional sticky header, which also keeps page anchors clear of itself so a link to a review
  or a section lands where you meant it to.
* Anonymous per-type delivery and open statistics, so you can tell which wording works.
* A storage period that removes notifications once they pass a chosen age.
* WP-CLI commands for listing types, rebuilding the cached counts and running the cleanup.
