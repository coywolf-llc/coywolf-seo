#!/usr/bin/env bash
#
# build-wporg.sh — produce the WordPress.org-compliant variant of this plugin.
#
# The plugin ships from GitHub with a bundled self-updater that pulls its own
# updates from the project's GitHub Releases. The WordPress.org Plugin
# Directory forbids that — listed plugins must update through .org — so this
# script takes an already-staged build tree and strips the self-updater out,
# leaving an otherwise-identical plugin that updates through wordpress.org.
#
# What it removes:
#   * includes/class-github-updater.php          — the updater class file
#   * any  wporg-strip:start .. wporg-strip:end  region in the main PHP file
#     (the require + bootstrap of the updater)
#   * any  wporg-strip:start .. wporg-strip:end  region in readme.txt
#     (prose that advertises GitHub-based updating / outbound calls)
#   * any  wporg-strip:start .. wporg-strip:end  region in readme.md
#     (readme.md is kept — the in-plugin Documentation page renders it — but
#     its marked regions are stripped the same way readme.txt's are)
#
# Usage:
#   .github/build-wporg.sh <src_dir> <out_dir>
#     <src_dir>  a tree already containing the runtime plugin files. In CI this
#                is the GitHub release stage, so the .git / .github / dev-tool
#                exclusions have already been applied — the .org variant is
#                exactly the GitHub build minus the updater.
#     <out_dir>  emptied and recreated; the variant is written to
#                <out_dir>/<slug>/  (slug = the stage dir's name).
#
set -euo pipefail

SRC="${1:?usage: build-wporg.sh <src_dir> <out_dir>}"
OUT="${2:?usage: build-wporg.sh <src_dir> <out_dir>}"

# The main file is the root *.php carrying the "Plugin Name:" header.
MAIN="$(grep -lE '^[[:space:]]*\*[[:space:]]*Plugin Name:' "$SRC"/*.php 2>/dev/null | head -n1 || true)"
[ -n "$MAIN" ] || { echo "build-wporg: no main plugin file (Plugin Name header) found in $SRC" >&2; exit 1; }
MAIN_BASE="$(basename "$MAIN")"
# The .org slug / folder name is the stage dir's own name. The release
# workflow stages into a folder named after its authoritative SLUG (which can
# differ from the repo name — e.g. repo "custom-blocks" ships slug
# "coywolf-custom-blocks"), so deriving it from $SRC keeps the two in lockstep.
SLUG="$(basename "$SRC")"

STAGE="$OUT/$SLUG"
rm -rf "$OUT"
mkdir -p "$STAGE"
cp -a "$SRC"/. "$STAGE"/

# Drop the .wordpress-org/ directory. The GitHub build ships it so the in-plugin
# Documentation page can render readme.md's screenshot/icon references, but the
# .org variant must not: .org serves the listing-page screenshots and icons from
# its own SVN assets tree (the deploy's ASSETS_DIR), and a hidden dot-directory
# carrying multi-megabyte PNGs has no place in the distributed plugin. The
# Markdown renderer already omits images whose files aren't bundled, so the
# Documentation page degrades cleanly here.
rm -rf "$STAGE/.wordpress-org"

# ---------------------------------------------------------------------------
# WordPress.org file-type compliance.
#
# .org rejects files that aren't the types normally found in a plugin. Two
# passes over the staged tree (vendored deps included — vendor/ ships):
#   1. Rename extension-less licence/notice files to .txt. Attribution must
#      ship, but an extension-less file is a review blocker (the
#      code-block-enhancer June 2026 review was rejected for its bundled
#      assets/themes/LICENSE-prism* files for exactly this reason).
#   2. Delete dev-only artefacts (lockfiles, linter/test config) that have no
#      place in a distributed plugin.
# ---------------------------------------------------------------------------
find "$STAGE" -type f \( \
	-name 'LICENSE' -o -name 'LICENCE' -o -name 'COPYING' \
	-o -name 'COPYING.LESSER' -o -name 'NOTICE' -o -name 'AUTHORS' \
	\) -print0 | while IFS= read -r -d '' f; do
	mv "$f" "$f.txt"
done

find "$STAGE" -type f \( \
	-name 'composer.lock' \
	-o -name '*.dist' \
	-o -name '.editorconfig' \
	-o -name '.php-cs-fixer.php' -o -name '.php-cs-fixer.dist.php' \
	-o -name '.php_cs' -o -name '.php_cs.dist' \
	-o -name 'phpstan.neon' -o -name 'phpstan.neon.dist' \
	-o -name 'phpunit.xml' -o -name 'phpunit.xml.dist' \
	-o -name 'mempalace.yaml' \
	\) -delete

# Drop vendored CLI binaries (extension-less, not needed at runtime) so the .org
# file-type check passes — e.g. league/html-to-markdown ships a bin/ script and
# Composer mirrors it into vendor/bin/.
find "$STAGE/vendor" -type d -name bin -prune -exec rm -rf {} + 2>/dev/null || true

# Delete a <marker>:start .. <marker>:end region from a file, portably
# (macOS + Linux). The Perl flip-flop matches the start..end lines inclusive.
# $2 is the marker base name and defaults to "wporg-strip".
strip_marked() {
	local f="$1"
	local marker="${2:-wporg-strip}"
	[ -f "$f" ] || return 0
	perl -ni -e "print unless /\\Q${marker}\\E:start/ .. /\\Q${marker}\\E:end/" "$f"
}

rm -f "$STAGE/includes/class-github-updater.php"
strip_marked "$STAGE/$MAIN_BASE"
strip_marked "$STAGE/readme.txt"
# Keep readme.md (the in-plugin Documentation page renders it), but strip any
# marked regions from it just like readme.txt.
strip_marked "$STAGE/readme.md"

# ---------------------------------------------------------------------------
# Experimental Labs features.
#
# The Labs subsystem (includes/labs/) ships only in the GitHub distribution;
# the WordPress.org variant must not carry experimental features. Remove the
# tree and strip the labs-strip-marked require of it from the main file (and
# any labs-strip prose from the readmes). Callers reference the Labs classes
# only behind class_exists() guards, so their absence is a clean no-op.
#
# Set INCLUDE_LABS=1 (or true) to keep Labs in the .org variant for a one-off.
# ---------------------------------------------------------------------------
if [ "${INCLUDE_LABS:-}" != "1" ] && [ "${INCLUDE_LABS:-}" != "true" ]; then
	rm -rf "$STAGE/includes/labs"
	strip_marked "$STAGE/$MAIN_BASE" 'labs-strip'
	strip_marked "$STAGE/readme.txt" 'labs-strip'
	strip_marked "$STAGE/readme.md" 'labs-strip'

	# Guard: the Labs tree and any require of it must be gone from the variant.
	# Only a real require/include of includes/labs/ would break the .org build;
	# inert class_exists()-guarded references and docblock mentions are fine, so
	# the grep matches require/include statements specifically.
	leak_re='(require|include)(_once)?[^;]*includes/labs/'
	if [ -d "$STAGE/includes/labs" ] || grep -rIlE "$leak_re" "$STAGE" --include='*.php' >/dev/null 2>&1; then
		echo "build-wporg: ERROR — Labs code leaked into the .org variant:" >&2
		[ -d "$STAGE/includes/labs" ] && echo "  includes/labs/ still present" >&2
		grep -rIlE "$leak_re" "$STAGE" --include='*.php' >&2 || true
		exit 1
	fi
fi

# Strip the "Update URI" plugin header. WordPress.org forbids it (it signals a
# non-.org update source, and Plugin Check reports it as a plugin updater). The
# GitHub build keeps it so .org can't hijack updates for the same slug.
perl -ni -e 'print unless /^\s*\*\s*Update URI\s*:/i' "$STAGE/$MAIN_BASE"

# The stripped main file must still parse.
php -l "$STAGE/$MAIN_BASE"

# Guard: no trace of the self-updater code may survive in the variant. (Scoped
# to .php so historical mentions in the readme changelog don't trip it.)
if grep -rIlE 'GitHub_Updater|class-github-updater' "$STAGE" --include='*.php' >/dev/null 2>&1; then
	echo "build-wporg: ERROR — self-updater code leaked into the .org variant:" >&2
	grep -rIlE 'GitHub_Updater|class-github-updater' "$STAGE" --include='*.php' >&2
	exit 1
fi

echo "build-wporg: wrote $STAGE (slug: $SLUG)"
