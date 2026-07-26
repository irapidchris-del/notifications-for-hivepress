# Releasing a new version

The plugin updates itself from this repository's GitHub **Releases** using the bundled
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker). Once a user has the
plugin installed, every new release you publish here shows up on their Plugins page as a normal
update, with a working "View details" popup and one-click update.

## One-time facts

- **Slug / folder:** `notifications-for-hivepress` — the zip must unpack into a folder with this
  exact name (the build script guarantees it).
- **Release asset name:** `notifications-for-hivepress.zip` — must be identical on every release.
  The updater and the permanent link both depend on that fixed name.
- **Permanent download link** (safe to post once on the HivePress forum; always serves the latest):

  ```
  https://github.com/irapidchris-del/notifications-for-hivepress/releases/latest/download/notifications-for-hivepress.zip
  ```

## Steps for each release

1. **Bump the version** in three places, all to the same number (e.g. `1.10.0`):
   - `notifications-for-hivepress.php` — the `Version:` header **and** the `HP_NOTIFICATIONS_VERSION`
     constant.
   - `readme.txt` — the `Stable tag:` line, plus a new `= 1.10.0 =` block at the top of the
     Changelog. The Changelog is what the "View details" popup shows users, so write it for them.

2. **Build the zips:**

   ```bash
   bin/build-release.sh
   ```

   This reads the version from the plugin header and writes two files to `dist/`:

   - `notifications-for-hivepress.zip` — the release asset (clean name, no version).
   - `notifications-for-hivepress-<version>.zip` — the same contents, version-stamped, for your own
     testing so you can tell builds apart. Do **not** attach this one.

   Both unpack into `notifications-for-hivepress/`, so either installs cleanly with no folder
   warning.

3. **Create the GitHub release:**
   - Tag: the version number, e.g. `1.10.0` (a leading `v` is fine — the updater ignores it). The
     tag is what the updater compares against the installed version, so it must match the header.
   - Title and notes: whatever you like; the notes appear under "View details".
   - **Attach `dist/notifications-for-hivepress.zip` as a release asset.**
   - Publish (not a draft, not a pre-release — the updater looks at the latest published release).

4. **Done.** Within its check window WordPress offers the update to every installed site. To see it
   immediately on a test site, go to Dashboard → Updates and click "Check again".

## Notes

- The build ships only runtime files. Development files (`bin/`, `dist/`, `phpcs.xml`, `.github/`,
  this file) are excluded from both the built asset and — via `.gitattributes` `export-ignore` —
  GitHub's own source archive, so the fallback download stays clean too.
- If you ever publish a release **without** attaching the asset, the updater falls back to GitHub's
  source archive and the library renames the folder for you, so updates still work; the asset is
  just cleaner and carries the fixed download link.
- The first release that includes this updater has to be installed manually (from the link above).
  Every release after that updates in place.
