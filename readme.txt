=== Notifications for HivePress ===

Contributors: ChrisB
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.11
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

= 1.7.11 =
* Fixed: updating two of these extensions one after the other could fail on the second with "up to date" until Check for updates was pressed again. WordPress rebuilds its update list after each update by asking wordpress.org first, and gives up on the whole list when that call is slow; the plugin now keeps its own update in the list regardless.
* Changed: a release found more than an hour ago is refreshed in the background whenever the Plugins screen is opened, so the newest release is offered rather than an intermediate one.
* New: a Check for updates bulk action on the Plugins screen, which checks every selected extension in one go, and the row that says Updating no longer shrinks on phones.

Older entries are in changelog.txt, which ships with the plugin. WordPress truncates this
section at 5,000 characters, so only the most recent releases are repeated here.

= 1.7.10 =
* Changed: the Settings button on the Notifications page now sits at the top right, level with the page title, so the list's own buttons have the row to themselves. The row no longer scrolls sideways.

= 1.7.9 =
* Fixed: the buttons above the notifications list stay on one row. When Clear selected appeared, Select all was left on one line and the buttons on the next; the row now moves under the unread count as a whole.

= 1.7.8 =
* Changed: on the Notification Settings page, the sentence explaining what On-site, Email, Push and Texts mean is now page text above the form rather than a note under the first group, and it no longer counts the tick boxes.

= 1.7.7 =
* Fixed: the sticky header only worked for signed-in people. Visitors, and anyone in a private browsing window, scrolled the header off the screen. It now pins for everyone when the setting is on; nothing changes for sites with it off.

= 1.7.6 =
* New: each notification on the Notifications page has a tick box, with Select all in the toolbar and a Clear selected button that removes the ticked ones.
* New: Mark all as read and Clear read ask before they act, as does Clear selected; a bulk change has no Undo.
* Changed: the unread counter on the header bell is now the same 24px circle with bold 12px text as the account-menu counters, so every counter in the header reads as one family. A Bell Counter Size setting under Delivery brings back the smaller 16px overlay if you prefer it.
* Changed: the notifications page now lays out its own search row: the field, the type list and the Search button share a line on wider screens and each take a full row on phones, with no theme rules needed.

= 1.7.5 =
* Changed: the Search button on the notifications page now carries a magnifying-glass icon, drawn the same way as the Mark all as read and Clear read buttons beside it. Themes that use a button's pseudo-elements for hover and loading effects (ListingHive does) left no room for a CSS-drawn glyph, so the icon is real markup.

= 1.7.4 =
* Fixed: tooltips inside the cards were cut off at the card's edge, and the ones on the Text section's fields had dropped below their labels; each now sits at the end of its label row and opens in full.
* Changed: the Install and Activate offers for other extensions are now links within the sentence rather than buttons.

= 1.7.3 =
* Changed: the Text section shows wording fields only for the notifications ticked in Types, following the boxes as you tick them; a card with nothing ticked says so.
* Changed: each Types card's help tooltip now sits at the end of its title bar instead of inside the folded card.
* Fixed: the empty-inbox icon on the Notifications page sat at the left edge instead of above the centred text.
* New: the settings sections now name the extensions that enhance them, linked to their announcements, and say whether each is active: Twilio under Delivery, Additional Gallery, Holiday Mode and Automated Listing Moderation under Types, and Vendor Analytics Pro and Trust Signals under Performance Notifications. Where one is missing or switched off there is an Install or Activate button, for owners allowed to do that.

= 1.7.2 =
* Fixed: a pound sign in changelog.txt showed a stray character before it. Nothing in the plugin itself changes.

= 1.7.1 =
* Changed: the emails sent with Email Studio's composer were listed under their class name, "Hpes
  Broadcast", on the settings screen and in every member's notification preferences. They are now
  called Site Emails, and the settings screen says where they come from.

= 1.7.0 =
* Changed: the Types section of the settings screen now shows each group as a card - the same
  cards Account Menu Enhancer uses - with the group's icon, its name and how many of its
  notifications are on. Click a card to open or close it; it remembers which you left open.
* Fixed: the thirteen owner notifications that 1.6.0 moved into "For Site Owners" were switched off
  on any site that had ever saved the Types section, because their saved choice was left behind in
  the group they came from. Updating carries each one's choice across, so what you had on stays on.
* Changed: the Types description no longer lists every notification that has no email behind it -
  on a site with several extensions that was thirty names in one sentence. It now says so once.
* Changed: the shared icon library is updated to the version that fixed two faults found in
  Account Menu Enhancer 3.4.0. Neither could happen on this extension's own screens; it is included
  so that every combination of extensions carries the corrected copy.

= 1.6.0 =
* Fixed: members no longer see notification settings for messages only you receive. Sixteen
  notifications go to the site owner's address - a listing being submitted or reported, a payout
  request, a refund that failed, a new vendor registering, and others - but only three of them were
  filed under "For Site Owners". The other thirteen sat under Listings, Orders and Requests, where
  every signed-in member could see them and switch them on and off to no effect. They are now all in
  one place, and that section is shown to administrators only.
* Changed: the Performance section is now shown only to members who sell on your site. Everything in
  it - view counts, search terms, response rates, verification - is about a vendor's own listings
  and profile, so it was asking buyers to set preferences for notifications they can never receive.
  A vendor whose profile is still awaiting your approval keeps the section, rather than losing it
  until they are approved.
* Added: a Gallery Access Summary notification for gallery owners. If you sell access to your
  gallery, you are now told once a day when people's access ends or is about to - one notification
  covering everyone, rather than one per person. Buyers already got their own warning and still do;
  this is the seller's side of it.
* Fixed: test emails you send yourself from Email Studio no longer appear in your notifications
  feed as though they were real events. A test deliberately goes out through the same path a real
  email does, so that what you check is exactly what a member would receive; the feed now knows to
  ignore it. Needs Email Studio 1.4.0 or later.
* Note for developers: a notification type may now declare `'audience' => 'vendor'`, and a section
  disappears when nothing in it applies to the person reading the form. Types say nothing by
  default and are offered to everybody, so nothing you have added changes behaviour.

= 1.5.13 =
* Changed: icons are now drawn directly into the page instead of being loaded as a font. A
  visitor's browser no longer downloads roughly 230 KB of stylesheet and font files just to show
  a few small pictures, and the icons can no longer clash with the icon font HivePress loads
  itself. Your colour and size settings work exactly as before.
* Added: every icon in the free Font Awesome 7 set is now available, brand icons included, which
  is around 800 more than before. Type a few letters to find one rather than scrolling a long
  list, and each result still shows you the icon itself.
* Changed: the settings screen loads a great deal faster, because the icon choices are fetched as
  you search instead of every one of them being written into the page.
* Fixed: three of the icons used by the insight notifications, the magnifying glass and the two
  trend arrows, showed as an empty space on most sites. They now draw correctly.

= 1.5.7 =
* Fixed: the newer Font Awesome icons and the brand icons this extension adds were being offered
  everywhere an icon can be chosen, including your listing category icons and your listing
  attribute icons, and they showed as an empty space there because this extension only loads the
  Font Awesome stylesheet for its own bell. Choosing one for a category left visitors looking at a
  blank tile. They are now offered only where the bell icon is chosen, on this extension's own
  settings tab, so every icon you can pick is one that will actually appear. If you had already
  chosen one of them for a category or an attribute, pick a different icon from the list; it was
  showing as blank before this release.
