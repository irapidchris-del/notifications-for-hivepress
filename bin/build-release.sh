#!/usr/bin/env bash
#
# Builds the distributable plugin zips.
#
# Produces two files in dist/:
#
#   notifications-for-hivepress.zip           - the release asset. Attach THIS to the GitHub
#                                               release. It is also what the permanent download
#                                               link and the in-dashboard updater serve.
#   notifications-for-hivepress-<version>.zip - the same contents, named with the version, for
#                                               internal testing so you can tell builds apart.
#
# Both unpack into a "notifications-for-hivepress/" folder, so WordPress installs them into the
# right place with no folder-mismatch warning, whichever one you use.
#
# Usage: bin/build-release.sh

set -euo pipefail

SLUG="notifications-for-hivepress"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

MAIN="$SLUG.php"

if [ ! -f "$MAIN" ]; then
	echo "Error: $MAIN not found in $ROOT" >&2
	exit 1
fi

# Read the version straight from the plugin header, so there is a single source of truth.
VERSION="$(grep -iE '^[[:space:]]*\*[[:space:]]*Version:' "$MAIN" | head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r' | xargs)"

if [ -z "$VERSION" ]; then
	echo "Error: could not read the Version header from $MAIN" >&2
	exit 1
fi

# Runtime files only. Everything not listed here (bin, dist, .git, .github, phpcs.xml, tests, docs)
# is left out of the shipped plugin on purpose.
ITEMS=( "$MAIN" uninstall.php readme.txt includes assets templates lib )

BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT
STAGE="$BUILD/$SLUG"
mkdir -p "$STAGE"

for item in "${ITEMS[@]}"; do
	if [ -e "$item" ]; then
		cp -R "$item" "$STAGE/"
	fi
done

# The languages folder is optional; ship it when it exists.
if [ -d languages ]; then
	cp -R languages "$STAGE/"
fi

# Drop any stray VCS or OS cruft that may have ridden along.
find "$STAGE" -name '.git*' -prune -exec rm -rf {} + 2>/dev/null || true
find "$STAGE" -name '.DS_Store' -delete 2>/dev/null || true

mkdir -p "$ROOT/dist"
CLEAN_ABS="$ROOT/dist/$SLUG.zip"
VERSIONED_ABS="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$CLEAN_ABS" "$VERSIONED_ABS"

( cd "$BUILD" && zip -rqX "$CLEAN_ABS" "$SLUG" )
cp "$CLEAN_ABS" "$VERSIONED_ABS"

echo "Built version $VERSION:"
echo "  dist/$SLUG.zip            <- attach this to the GitHub release"
echo "  dist/$SLUG-$VERSION.zip   <- for internal testing"
