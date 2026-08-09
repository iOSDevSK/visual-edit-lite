#!/usr/bin/env bash
# Ship a version: verify, push, tag, and publish the GitHub Release with the
# ZIP attached — in one command, so the release cannot lag behind the code.
#
#   tools/release.sh [X.Y.Z]        # default: the version in the plugin header
#
# It exists because "push, then remember to release" failed twice: the commits
# went up and the release asset stayed at the previous build, so the ZIP a
# person downloads was not the code in the repo. Every step below ends in a
# check, and the last one downloads the published asset back and diffs it
# against a fresh build. Anything less is a claim, not a verification.
#
# Idempotent: run it again on an unchanged, already-released commit and it
# says so and exits 0.
#
# Notes for the release come from the matching `= X.Y.Z =` block in
# readme.txt's changelog, so there is one place to write them.
set -uo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
cd "$SRC" || exit 1
SLUG="visual-edit-lite"
REPO="iOSDevSK/$SLUG"
MAIN="$SRC/$SLUG.php"

ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
die()  { printf '\n\033[31mRELEASE ABORTED: %s\033[0m\n' "$*" >&2; exit 1; }

command -v gh >/dev/null || die "the GitHub CLI (gh) is required"
gh auth status >/dev/null 2>&1 || die "gh is not authenticated — run: gh auth login"

VERSION="${1:-$(grep -m1 '^ \* Version:' "$MAIN" | sed 's/.*: //' | tr -d '[:space:]')}"
[ -n "$VERSION" ] || die "could not read a version"
case "$VERSION" in v*) die "no 'v' prefix — tags are the bare version string";; esac

step "Preflight ($VERSION)"
[ -z "$(git status --porcelain)" ] || die "working tree is dirty — commit first; a release must be a commit"
HEAD_SHA="$(git rev-parse HEAD)"
ok "clean tree at ${HEAD_SHA:0:7}"

# The header, CLARA_VE_VERSION and readme Stable tag agreeing is build-plugin's
# job; this only checks that the version being released is the one in the code.
HDR="$(grep -m1 '^ \* Version:' "$MAIN" | sed 's/.*: //' | tr -d '[:space:]')"
[ "$HDR" = "$VERSION" ] || die "asked to release $VERSION but the plugin header says $HDR"
ok "version matches the plugin header"

step "Verify"
if [ -f .verified ] && [ "$(cat .verified)" = "$HEAD_SHA" ]; then
  ok "HEAD already verified — skipping the 40 s run"
else
  tools/verify.sh || die "verification failed — nothing is released"
fi

step "Push"
git push origin main || die "could not push main"
ok "main at ${HEAD_SHA:0:7}"

step "Build"
ZIP="$SRC/$SLUG-$VERSION.zip"
tools/build-plugin.sh "$ZIP" >/dev/null || die "build refused"
ok "$(basename "$ZIP")"

step "Tag"
# -f so a corrected build can replace a tag nothing has consumed yet. Once
# anything external depends on releases, stop doing this and bump the version
# instead: a moving tag is a lie to whoever already fetched it.
git tag -f -a "$VERSION" -m "$VERSION" >/dev/null || die "could not tag"
git push -f origin "refs/tags/$VERSION" >/dev/null 2>&1 || die "could not push the tag"
REMOTE_TAG="$(git ls-remote origin "refs/tags/$VERSION^{}" | awk '{print $1}')"
[ "$REMOTE_TAG" = "$HEAD_SHA" ] || die "the remote tag points at $REMOTE_TAG, not HEAD"
ok "$VERSION -> ${HEAD_SHA:0:7}"

step "Release"
NOTES="$(awk -v v="= $VERSION =" '
  $0 == v { grab = 1; next }
  grab && /^= [0-9]/ { exit }
  grab { print }
' readme.txt | sed '/^[[:space:]]*$/d')"
[ -n "$NOTES" ] || NOTES="Visual Edit Lite $VERSION."

if gh release view "$VERSION" -R "$REPO" >/dev/null 2>&1; then
  gh release edit "$VERSION" -R "$REPO" --notes "$NOTES" >/dev/null || die "could not update the release notes"
  gh release upload "$VERSION" "$ZIP" -R "$REPO" --clobber >/dev/null || die "could not upload the asset"
  ok "release updated"
else
  gh release create "$VERSION" -R "$REPO" --title "$VERSION" --notes "$NOTES" "$ZIP" >/dev/null \
    || die "could not create the release"
  ok "release created"
fi

step "Proof"
# Download what the world will actually get, and diff it against a fresh build.
DL="$(mktemp -d)"; PUB="$(mktemp -d)"; FRESH="$(mktemp -d)"
gh release download "$VERSION" -R "$REPO" -p '*.zip' -O "$DL/pub.zip" --clobber >/dev/null \
  || die "the published asset could not be downloaded back"
( cd "$PUB" && unzip -q "$DL/pub.zip" ) || die "the published asset is not a readable ZIP"
tools/build-plugin.sh "$DL/fresh.zip" >/dev/null || die "rebuild failed"
( cd "$FRESH" && unzip -q "$DL/fresh.zip" )
diff -r "$PUB" "$FRESH" >/dev/null || die "the published asset does NOT match this commit"
PUBVER="$(grep -m1 '^ \* Version:' "$PUB/$SLUG/$SLUG.php" | sed 's/.*: //' | tr -d '[:space:]')"
[ "$PUBVER" = "$VERSION" ] || die "the published ZIP reports version $PUBVER"
rm -rf "$DL" "$PUB" "$FRESH" "$ZIP"
ok "downloaded asset is byte-for-byte this commit, version $PUBVER"

printf '\n\033[32m✓ RELEASED\033[0m %s — https://github.com/%s/releases/tag/%s\n' "$VERSION" "$REPO" "$VERSION"
