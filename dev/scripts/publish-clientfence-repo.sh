#!/usr/bin/env bash
# Publishes the ClientFence build of a release to github.com/Osir-Inc/clientfence-osir.
#
# That repository is generated, never edited: this script rebuilds the tree from the tag given, commits it
# as a single OSIR-authored commit, tags it with the same version and attaches the same archive. Issues and
# pull requests belong in the development repository, which is the only place the plugin is written.
#
#   dev/scripts/publish-clientfence-repo.sh v1.4.0
#
# Needs: a clean checkout of the tag, gh authenticated for Osir-Inc, and dev/scripts/build-release.sh output.
set -euo pipefail
cd "$(dirname "$0")/../.."

SRC_REPO="$PWD"
TAG="${1:?usage: publish-clientfence-repo.sh vX.Y.Z}"
VERSION="${TAG#v}"
REPO="Osir-Inc/clientfence-osir"
WORK="build/clientfence-repo"
ZIP="dist/osir-clientfence-registrar-${VERSION}.zip"

[[ -f "$ZIP" ]] || { echo "Missing $ZIP — run dev/scripts/build-release.sh first." >&2; exit 1; }
dev/scripts/make-clientfence.sh --check >/dev/null

# The public identity for everything OSIR publishes; never a person's name or address.
export GIT_AUTHOR_NAME=OSIR GIT_AUTHOR_EMAIL=osir@users.noreply.github.com
export GIT_COMMITTER_NAME=OSIR GIT_COMMITTER_EMAIL=osir@users.noreply.github.com

rm -rf "$WORK"
if gh repo view "$REPO" >/dev/null 2>&1; then
  git clone -q "https://github.com/${REPO}.git" "$WORK"
  ( cd "$WORK" && git ls-files -z | xargs -0 -r rm -f )
else
  echo "Creating $REPO"
  gh repo create "$REPO" --public \
    --description "OSIR domain registrar for ClientFence: domains, DNS in the client area. Generated from Osir-Inc/fossbilling-osir." \
    --homepage "https://osir.com"
  git clone -q "https://github.com/${REPO}.git" "$WORK" 2>/dev/null || { mkdir -p "$WORK" && git -C "$WORK" init -q -b main && git -C "$WORK" remote add origin "https://github.com/${REPO}.git"; }
fi

# The plugin itself, in the layout it is extracted into, plus the documents that travel with it.
cp -a build/clientfence/library build/clientfence/modules "$WORK/"
cp LICENSE SECURITY.md CHANGELOG.md "$WORK/"
cp dev/clientfence-repo-README.md "$WORK/README.md"
# Screenshots and anything else the published repository shows, taken on ClientFence itself.
cp -a dev/clientfence-repo-assets/. "$WORK/"

cd "$WORK"

# Same rule as the public mirror: nothing personal or internal leaves the building. The things to
# look for are derived from this machine rather than written down, so this script never becomes the
# leak it is meant to prevent.
patterns=$(
  cd "$OLDPWD" 2>/dev/null || true
  git -C "$SRC_REPO" config user.email 2>/dev/null
  git -C "$SRC_REPO" config user.name 2>/dev/null | tr ' ' '\n'
  git -C "$SRC_REPO" log -1 --format='%ae%n%an' 2>/dev/null | tr ' ' '\n'
  git -C "$SRC_REPO" remote get-url origin 2>/dev/null | sed -E 's#.*://([^/]+)/.*#\1#'
  echo "$HOME"
)
leaked=0
while read -r needle; do
  [[ -z "$needle" || ${#needle} -lt 4 ]] && continue
  if grep -rniFq --exclude-dir=.git -e "$needle" .; then
    echo "Found '${needle}' in the generated tree; not pushing." >&2
    leaked=1
  fi
done <<< "$patterns"
[[ "$leaked" -eq 0 ]] || exit 1

git add -A
if git diff --cached --quiet; then
  echo "Nothing changed for ${TAG}."
else
  git commit -q -m "Release ${TAG}

Generated from Osir-Inc/fossbilling-osir ${TAG}. Development, issues and pull
requests belong in that repository; this tree is rewritten on every release."
fi
git tag -a "$TAG" -m "$TAG" 2>/dev/null || true
git push -q origin main --follow-tags

gh release create "$TAG" "../../${ZIP}" --repo "$REPO" --title "$TAG" \
  --notes "The OSIR registrar for ClientFence ${VERSION}. Install \`osir-clientfence-registrar-${VERSION}.zip\`; the changelog and full documentation live in [Osir-Inc/fossbilling-osir](https://github.com/Osir-Inc/fossbilling-osir/releases/tag/${TAG})." \
  2>/dev/null || gh release upload "$TAG" "../../${ZIP}" --repo "$REPO" --clobber

echo "Published ${TAG} to ${REPO}"
