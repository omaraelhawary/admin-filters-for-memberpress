#!/usr/bin/env bash
# Populate a WordPress.org plugin SVN working copy from this repo:
#   - trunk/  ← contents of dist zip (plugin root files, no wrapper folder)
#   - assets/ ← directory banners, icons, and screenshot-1 from repo sources
#
# Prerequisite: create an SVN application password on WordPress.org
#   https://make.wordpress.org/meta/handbook/tutorials-guides/svn-access/
#
# Usage (from plugin root):
#   bash scripts/build-release.sh
#   svn checkout https://plugins.svn.wordpress.org/admin-filters-for-memberpress ~/path/to/svn-wc
#   bash scripts/prepare-wordpress-org-svn-working-copy.sh ~/path/to/svn-wc
#
# Then commit (uses username omarelhawary and your application password):
#   cd ~/path/to/svn-wc
#   svn add --force trunk assets
#   svn commit -m "Release 1.6.7 — initial plugin"
#   svn copy trunk tags/1.6.7
#   svn commit -m "Tag 1.6.7"
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SLUG="admin-filters-for-memberpress"

if [[ "${#}" -lt 1 ]]; then
  echo "usage: bash scripts/prepare-wordpress-org-svn-working-copy.sh /path/to/svn/working-copy" >&2
  exit 1
fi

SVN_WC="$(cd "${1}" && pwd)"

if [[ ! -d "${SVN_WC}/trunk" ]]; then
  echo "error: ${SVN_WC}/trunk not found — pass the top-level SVN checkout directory" >&2
  exit 1
fi

MAIN_PHP="${ROOT}/${SLUG}.php"
VERSION="$(
  sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "${MAIN_PHP}" | head -n1 | tr -d '\r'
)"
ZIP="${ROOT}/dist/${SLUG}-${VERSION}.zip"

if [[ ! -f "${ZIP}" ]]; then
  echo "error: missing ${ZIP} — run: bash scripts/build-release.sh" >&2
  exit 1
fi

TMP="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}.svn-stage.XXXXXX")"
cleanup() {
  rm -rf "${TMP}"
}
trap cleanup EXIT

unzip -q "${ZIP}" -d "${TMP}"
STAGE="${TMP}/${SLUG}"
if [[ ! -d "${STAGE}" ]]; then
  echo "error: expected ${STAGE} inside zip" >&2
  exit 1
fi

if ! command -v rsync >/dev/null 2>&1; then
  echo "error: rsync is required" >&2
  exit 1
fi

rsync -a --delete "${STAGE}/" "${SVN_WC}/trunk/"

ASSETS_SRC="${ROOT}/wordpress-org-assets"
SHOT_SRC="${ROOT}/.github/readme-assets/members-table-filters.png"
mkdir -p "${SVN_WC}/assets"

if [[ -d "${ASSETS_SRC}" ]]; then
  # Icon names must match https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
  for f in icon-128x128.png icon-256x256.png banner-772x250.png banner-1544x500.png; do
    if [[ -f "${ASSETS_SRC}/${f}" ]]; then
      cp -f "${ASSETS_SRC}/${f}" "${SVN_WC}/assets/${f}"
    fi
  done
fi

if [[ -f "${SHOT_SRC}" ]]; then
  cp -f "${SHOT_SRC}" "${SVN_WC}/assets/screenshot-1.png"
else
  echo "warning: ${SHOT_SRC} missing — add screenshot-1.png to SVN assets/ manually" >&2
fi

echo "Prepared trunk + assets under ${SVN_WC}"
echo "Version: ${VERSION} (from ${ZIP})"
echo "Next: cd '${SVN_WC}' && svn status && svn add --force trunk assets && svn commit && svn copy trunk tags/${VERSION} && svn commit"
