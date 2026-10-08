#!/usr/bin/env bash
# Private reproducibility check only: no tags, release upload, or installation.
set -euo pipefail
archive="${1:?Pass a committed git archive tar}"
epoch="${2:?Pass the source commit timestamp}"
[[ -f "$archive" && "$epoch" =~ ^[0-9]+$ ]] || { echo 'Invalid archive or timestamp' >&2; exit 1; }
export TZ=UTC
work="$(mktemp -d /tmp/roxy-release-verification-XXXXXXXX)"
mkdir "$work/source"
tar -xf "$archive" -C "$work/source"
for iteration in first second; do
  mkdir -p "$work/$iteration/roxy-suite"
  rsync -a --exclude='.git' --exclude='.github' --exclude='/.gitignore' \
    --exclude='build' --exclude='/tests/' --exclude='/docs/' --exclude='/tools/' \
    --exclude='/RoxyEdit.md' --exclude='.DS_Store' "$work/source/" "$work/$iteration/roxy-suite/"
  find "$work/$iteration/roxy-suite" -type f -exec touch -d "@$epoch" {} +
  (cd "$work/$iteration"; find roxy-suite -type f -print | LC_ALL=C sort | zip -X package.zip -@ >/dev/null)
  unzip -t "$work/$iteration/package.zip" > "$work/$iteration/integrity.txt"
done
cmp "$work/first/package.zip" "$work/second/package.zip"
unzip -Z1 "$work/first/package.zip" > "$work/entries.txt"
if grep -E '^roxy-suite/(tests|docs|tools|\.git|\.github|build)(/|$)|^roxy-suite/(RoxyEdit\.md|\.gitignore)$|(^|/)\.DS_Store$' "$work/entries.txt"; then
  echo 'Excluded development files leaked into package' >&2; exit 1
fi
printf 'Verified committed runtime files: %s\n' "$(wc -l < "$work/entries.txt")"
sha256sum "$archive" "$work/first/package.zip" "$work/second/package.zip"
printf 'Private verification directory: %s\n' "$work"
