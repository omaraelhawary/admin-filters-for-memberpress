#!/usr/bin/env bash
# Refresh the local WordPress.org SVN mirror (admin-filters-for-memberpress-svn/).
# Read-only: svn checkout or svn update from plugins.svn.wordpress.org.
#
# Usage (from plugin root):
#   bash scripts/sync-local-svn-working-copy.sh
#
# Runs automatically at the end of scripts/deploy-wordpress-org-svn.sh.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SLUG="admin-filters-for-memberpress"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
LOCAL_WC="${ROOT}/${SLUG}-svn"

if [[ -n "${GITHUB_ACTIONS:-}" ]]; then
  echo "GitHub Actions cannot update SVN folders on your computer."
  echo "After a successful deploy, run locally from the plugin root:"
  echo "  bash scripts/sync-local-svn-working-copy.sh"
  exit 0
fi

if ! command -v svn >/dev/null 2>&1; then
  echo "error: svn is required" >&2
  exit 1
fi

is_svn_wc() {
  [[ -d "${1}/.svn" ]] && svn info "${1}" >/dev/null 2>&1
}

if is_svn_wc "${LOCAL_WC}"; then
  echo "Updating local SVN mirror: ${LOCAL_WC}"
  svn update "${LOCAL_WC}"
else
  if [[ -e "${LOCAL_WC}" ]]; then
    echo "error: ${LOCAL_WC} exists but is not a Subversion working copy" >&2
    echo "Remove it or rename it, then run this script again." >&2
    exit 1
  fi
  echo "Checking out WordPress.org SVN to: ${LOCAL_WC}"
  svn checkout "${SVN_URL}" "${LOCAL_WC}"
fi

TRUNK_VERSION="$(
  sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "${LOCAL_WC}/trunk/${SLUG}.php" 2>/dev/null | head -n1 | tr -d '\r' || true
)"
echo "Local mirror ready. trunk Version: ${TRUNK_VERSION:-unknown}"
echo "Recent tags on WordPress.org:"
svn list "${SVN_URL}/tags/" 2>/dev/null | tail -5 || true
