#!/usr/bin/env bash
#
# Regenerate budget.pot and compare its msgid SET with the committed one.
#
#   - a msgid in the committed .pot that the regenerated one lacks FAILS the
#     check: either a string was removed without regenerating the .pot, or
#     the regeneration lost strings (it has before, and Weblate then drops
#     them for every language);
#   - a msgid only in the regenerated .pot is a notice, not a failure: new
#     strings are expected between .pot refreshes.
#
# Compares entries, not lines: multi-line msgids are unwrapped first and
# msgctxt is part of the key, so re-wrapping or reordering is never a diff.
#
# Usage (from the repo root or anywhere):  .github/scripts/check-pot.sh [app-dir]
# Needs php and gettext's xgettext (make translations runs translationtool).
# The committed .pot is restored afterwards, so the working tree is unchanged.

set -euo pipefail

app_dir="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../budget" && pwd)}"
pot="$app_dir/translationfiles/templates/budget.pot"

if [ ! -f "$pot" ]; then
	echo "No committed .pot at $pot" >&2
	exit 2
fi

work="$(mktemp -d)"
trap 'cp "$work/committed.pot" "$pot" 2>/dev/null || true; rm -rf "$work"' EXIT
cp "$pot" "$work/committed.pot"

(cd "$app_dir" && make --no-print-directory translations >"$work/make.log" 2>&1) || {
	cat "$work/make.log" >&2
	echo "make translations failed" >&2
	exit 2
}
cp "$pot" "$work/regenerated.pot"

# One line per entry: msgctxt, a 0x01 separator, msgid. Continuation lines
# ("...") are appended to whichever field they continue. The header entry
# (empty msgid) is skipped.
msgids() {
	awk '
		function unquote(s) { sub(/^[^"]*"/, "", s); sub(/"[[:space:]]*$/, "", s); return s }
		function flush() { if (have && id != "") print ctx "\001" id; have = 0; ctx = ""; id = ""; field = "" }
		/^msgctxt "/      { flush(); ctx = unquote($0); field = "ctx"; next }
		/^msgid "/        { if (field != "ctx") flush(); id = unquote($0); field = "id"; have = 1; next }
		/^msgid_plural "/ { field = "plural"; next }
		/^msgstr/         { field = "str"; next }
		/^"/              { if (field == "ctx") ctx = ctx unquote($0); else if (field == "id") id = id unquote($0); next }
		END { flush() }
	' "$1" | LC_ALL=C sort -u
}

msgids "$work/committed.pot" >"$work/committed.ids"
msgids "$work/regenerated.pot" >"$work/regenerated.ids"

LC_ALL=C comm -23 "$work/committed.ids" "$work/regenerated.ids" >"$work/missing.ids"
LC_ALL=C comm -13 "$work/committed.ids" "$work/regenerated.ids" >"$work/added.ids"

printable() { sed 's/\x01/ | /; s/^ | //'; }

committed_count=$(wc -l <"$work/committed.ids")
regenerated_count=$(wc -l <"$work/regenerated.ids")
echo "msgids: committed $committed_count, regenerated $regenerated_count"

if [ -s "$work/added.ids" ]; then
	added=$(wc -l <"$work/added.ids")
	echo "::notice title=New translatable strings::$added msgid(s) are not in the committed budget.pot yet; regenerate it (make translations) in the same series as the t() change."
	printable <"$work/added.ids" | sed 's/^/  + /'
fi

if [ -s "$work/missing.ids" ]; then
	missing=$(wc -l <"$work/missing.ids")
	echo "::error title=budget.pot lost strings::$missing msgid(s) in the committed budget.pot are missing from a fresh regeneration."
	printable <"$work/missing.ids" | sed 's/^/  - /'
	exit 1
fi

echo "OK: every committed msgid is still produced."
