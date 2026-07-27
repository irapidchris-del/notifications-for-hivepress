# Releasing a new version

The plugin updates itself from this repository's GitHub **Releases** using the native WordPress
`update_plugins_{$hostname}` API (no third-party library). Once a user has the plugin installed,
each release you publish here shows up on their Plugins page as a normal update, with a working
"View version details" popup, a one-click update, and a "Check for updates" link on the plugin row.

## Fixed facts

- **Slug / folder / text domain:** `notifications-for-hivepress`. The release asset must unpack into
  a single folder with this exact name.
- **Release asset name:** always `notifications-for-hivepress.zip` (no version in the file name).
  The updater downloads the first release asset whose name ends in `.zip`, and the permanent link
  below depends on the fixed name.
- **Permanent download link** (post once on the HivePress forum; always serves the latest):

  ```
  https://github.com/irapidchris-del/notifications-for-hivepress/releases/latest/download/notifications-for-hivepress.zip
  ```

The build and upload are done by `.github/workflows/release.yml`, so you never build the zip by
hand.

## Bump the version first (every release)

Set the same version number in both places:

- `notifications-for-hivepress.php` — the `Version:` header **and** the `HP_NOTIFICATIONS_VERSION`
  constant.
- `readme.txt` — the `Stable tag:` line, plus a new `= X.Y.Z =` block at the top of the Changelog.

Commit that and merge it to `main` before releasing (the workflow builds from the tagged commit).

## Publishing

**Option A — from the GitHub UI.** Draft a new Release, set the tag to the version number (e.g.
`v1.10.0`; a leading `v` is fine), write the notes, and publish. The workflow fires on the
`release: published` event, builds the zip and attaches `notifications-for-hivepress.zip`.

**Option B — via workflow dispatch (used from a Claude session).** `gh` and the raw releases REST
API are not available inside a Claude session, so trigger the workflow instead:

1. Bump the `Version:` header, commit, and merge to `main`.
2. Trigger the workflow with the GitHub MCP tool `actions_run_trigger`:
   - `method: run_workflow`
   - `workflow_id: release.yml`
   - `ref: main`
   - `inputs: { tag: "vX.Y.Z", notes: "<changelog markdown>" }`
   The workflow creates the release if it doesn't exist (or force-moves the tag and refreshes the
   notes/asset if it does), building `notifications-for-hivepress.zip` from `$GITHUB_SHA`.
3. Verify with `get_release_by_tag` that the tag, notes and the `.zip` asset all landed.

## Notes

- The tag is the version the updater compares against the installed `Version:` header, so the tag
  must match the header (with or without a leading `v`).
- Draft and pre-releases are ignored: the updater reads `releases/latest`, which excludes them.
- Until the first release exists, `releases/latest` returns 404, the updater simply offers no update
  (a failed check is cached for an hour), and nothing errors.
- The first release carrying this updater must be installed manually from the link above; every
  release after that updates in place.
