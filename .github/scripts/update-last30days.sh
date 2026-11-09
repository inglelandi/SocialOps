#!/usr/bin/env bash
# Copies the newest last30days skill (github.com/mvanhorn/last30days-skill, MIT) into
# .claude/skills/last30days, without assets/ (the README's demo pictures and audio, which the
# skill never uses). Run from the repository root. It never runs the skill's own code.
#
# .github/workflows/last30days-update.yml runs it every morning and opens a PR for Panos to read
# and merge when the copy changed (decision D21). Run it by hand the same way to update now.
# With $GITHUB_OUTPUT set it writes changed=true|false and the old and new version and commit there.
set -euo pipefail

repo=https://github.com/mvanhorn/last30days-skill
dest=.claude/skills/last30days
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

git clone -q --depth 1 --filter=blob:none --sparse "$repo" "$tmp/up"
git -C "$tmp/up" sparse-checkout set skills/last30days
[ -f "$tmp/up/skills/last30days/SKILL.md" ] || { echo "no skills/last30days/SKILL.md upstream" >&2; exit 1; }

# only plain characters out of what upstream writes, since these values end up in a PR title and body
clean() { tr -cd 'A-Za-z0-9.+_-' | cut -c1-40; }
commit=$(git -C "$tmp/up" rev-parse HEAD | clean)
version=$(awk '/^version:/{gsub(/"/, "", $2); print $2; exit}' "$tmp/up/skills/last30days/SKILL.md" | clean)
old_commit=$(sed -n 's/^commit=//p' "$dest/UPSTREAM.txt" 2>/dev/null | clean || true)
old_version=$(sed -n 's/^version=//p' "$dest/UPSTREAM.txt" 2>/dev/null | clean || true)

rm -rf "$dest"
mkdir -p "$dest"
cp -a "$tmp/up/skills/last30days/." "$dest/"
rm -rf "$dest/assets"
cp "$tmp/up/LICENSE" "$dest/LICENSE"

# changed = the skill's files differ from the last commit (UPSTREAM.txt alone does not count)
if [ -z "$(git status --porcelain -- "$dest" ":(exclude)$dest/UPSTREAM.txt")" ]; then
  git checkout -q -- "$dest/UPSTREAM.txt" 2>/dev/null || true
  changed=false
else
  cat > "$dest/UPSTREAM.txt" <<EOF
# A copy of the last30days skill. Do not edit by hand: .github/scripts/update-last30days.sh
# rewrites this folder, and the daily "last30days update" PR brings each new version.
repo=$repo
commit=$commit
version=$version
copied=$(date -u +%Y-%m-%d)
left_out=assets/ (the README's demo pictures and audio)
EOF
  changed=true
fi

echo "last30days: changed=$changed ${old_version:-none} -> $version (${commit:0:7})"
if [ -n "${GITHUB_OUTPUT:-}" ]; then
  {
    echo "changed=$changed"
    echo "version=$version"
    echo "old_version=${old_version:-none}"
    echo "commit=$commit"
    echo "old_commit=${old_commit:-}"
    echo "short=${commit:0:7}"
  } >> "$GITHUB_OUTPUT"
fi
