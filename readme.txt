=== Notifications for HivePress ===

Contributors: ChrisB
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
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

= 1.0.0 =
First public release.

The plugin has been through a long private development and testing programme before this release,
so the changelog starts here rather than replaying that history. Everything below is what 1.0.0
does, and every item was verified on a live staging site before release.

* Mirrors every email HivePress and its extensions send as an on-site notification, working from
  the email layer so it covers extensions installed later without any per-extension code.
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
