#!/usr/bin/env bash
# Build a distributable WordPress plugin zip (no tests, CI, Composer, or dev docs).
#
# Usage (from plugin root — same folder as admin-filters-for-memberpress.php):
#   bash scripts/build-release.sh
# Or from anywhere:
#   bash /path/to/admin-filters-for-memberpress/scripts/build-release.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SLUG="admin-filters-for-memberpress"
MAIN_PHP="${ROOT}/${SLUG}.php"

if [[ ! -f "${MAIN_PHP}" ]]; then
  echo "error: expected bootstrap at ${MAIN_PHP}" >&2
  exit 1
fi

# Read * Version: x.y.z from the plugin header (first match).
VERSION="$(
  sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "${MAIN_PHP}" | head -n1 | tr -d '\r'
)"
if [[ -z "${VERSION}" ]]; then
  echo "error: could not parse Version from ${MAIN_PHP}" >&2
  exit 1
fi

OUT_DIR="${ROOT}/dist"
ARCHIVE_NAME="${SLUG}-${VERSION}.zip"
ARCHIVE_PATH="${OUT_DIR}/${ARCHIVE_NAME}"

TMP="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}.build.XXXXXX")"
cleanup() {
  rm -rf "${TMP}"
}
trap cleanup EXIT

STAGE="${TMP}/${SLUG}"
mkdir -p "${STAGE}" "${OUT_DIR}"

if ! command -v rsync >/dev/null 2>&1; then
  echo "error: rsync is required (install Xcode CLT on macOS)" >&2
  exit 1
fi

rsync -a \
  --delete \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.cursor/' \
  --exclude='dist/' \
  --exclude='wordpress-org-assets/' \
  --exclude='scripts/' \
  --exclude='tests/' \
  --exclude='vendor/' \
  --exclude='docs/' \
  --exclude='phpunit.xml.dist' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='composer.phar' \
  --exclude='.phpunit.result.cache' \
  --exclude='.DS_Store' \
  --exclude='.gitignore' \
  --exclude='CLAUDE.md' \
  --exclude='REVIEW.md' \
  "${ROOT}/" "${STAGE}/"

(
  cd "${TMP}"
  rm -f "${ARCHIVE_PATH}"
  zip -rq "${ARCHIVE_PATH}" "${SLUG}"
)

echo "Built ${ARCHIVE_PATH} ($(du -h "${ARCHIVE_PATH}" | awk '{print $1}'))"
