#!/usr/bin/env bash
# Build and deploy to WordPress.org plugin SVN (trunk + version tag).
#
# Prerequisites:
#   - WordPress.org SVN application password:
#     https://make.wordpress.org/meta/handbook/tutorials-guides/svn-access/
#   - Environment: SVN_USERNAME, SVN_PASSWORD
#   - Optional: SVN_WC_DIR (existing checkout; otherwise a temp checkout is used)
#   - Optional: EXPECTED_VERSION (fail if plugin header Version does not match)
#
# Usage (from plugin root):
#   export SVN_USERNAME=your-wp-org-username
#   export SVN_PASSWORD=your-application-password
#   bash scripts/deploy-wordpress-org-svn.sh
#
# GitHub Actions: set repository secrets SVN_USERNAME and SVN_PASSWORD, then publish
# a GitHub Release whose tag matches the plugin Version (e.g. 1.7.0 or v1.7.0).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SLUG="admin-filters-for-memberpress"
MAIN_PHP="${ROOT}/${SLUG}.php"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"

if [[ -z "${SVN_USERNAME:-}" || -z "${SVN_PASSWORD:-}" ]]; then
  echo "error: set SVN_USERNAME and SVN_PASSWORD (WordPress.org application password)" >&2
  exit 1
fi

if [[ ! -f "${MAIN_PHP}" ]]; then
  echo "error: expected bootstrap at ${MAIN_PHP}" >&2
  exit 1
fi

VERSION="$(
  sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "${MAIN_PHP}" | head -n1 | tr -d '\r'
)"
if [[ -z "${VERSION}" ]]; then
  echo "error: could not parse Version from ${MAIN_PHP}" >&2
  exit 1
fi

if [[ -n "${EXPECTED_VERSION:-}" && "${VERSION}" != "${EXPECTED_VERSION}" ]]; then
  echo "error: plugin Version is ${VERSION}, expected ${EXPECTED_VERSION}" >&2
  exit 1
fi

svn_non_interactive() {
  svn "$@" \
    --username "${SVN_USERNAME}" \
    --password "${SVN_PASSWORD}" \
    --non-interactive \
    --no-auth-cache \
    --trust-server-cert-failures unknown-ca,cn-mismatch,expired,not-yet-valid,other
}

bash "${SCRIPT_DIR}/build-release.sh"

OWN_WC=false
if [[ -z "${SVN_WC_DIR:-}" ]]; then
  OWN_WC=true
  SVN_WC_DIR="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}.svn-deploy.XXXXXX")"
  svn_non_interactive checkout "${SVN_URL}" "${SVN_WC_DIR}"
fi

cleanup() {
  if [[ "${OWN_WC}" == true ]]; then
    rm -rf "${SVN_WC_DIR}"
  fi
}
trap cleanup EXIT

bash "${SCRIPT_DIR}/prepare-wordpress-org-svn-working-copy.sh" "${SVN_WC_DIR}"

cd "${SVN_WC_DIR}"

svn_non_interactive add --force trunk assets

while IFS= read -r missing; do
  [[ -n "${missing}" ]] && svn_non_interactive rm --force "${missing}"
done < <(svn status | awk '/^!/ {print $2}')

svn_non_interactive commit -m "Release ${VERSION}"

if svn_non_interactive info "tags/${VERSION}" >/dev/null 2>&1; then
  echo "tags/${VERSION} already exists on WordPress.org SVN — trunk updated, tag left unchanged"
else
  svn_non_interactive copy trunk "tags/${VERSION}" -m "Tag ${VERSION}"
  echo "Deployed ${VERSION} to trunk and tags/${VERSION}"
fi
