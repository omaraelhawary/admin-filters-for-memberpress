/**
 * Make MemberPress's own CSV export links honour the active filters.
 *
 * MemberPress builds its export links from `action`, `all` and a nonce only
 * (MeprAppHelper::export_table_link), and the export runs on admin-ajax.php. With no
 * `page` in the request this plugin's screen detection returns null, so none of its
 * predicates reach MeprDb::list_table — and MemberPress's own toolbar filters are
 * dropped too. The link ends up advertising the filtered row count while exporting
 * every row in the table.
 *
 * Copying the current URL's query onto those links is enough to fix it: `page` restores
 * screen detection and the filter params do the rest. No separate export endpoint.
 */
(function () {
	'use strict';

	// Params owned by the export request itself, or meaningless to it.
	var SKIP = [ 'action', 'all', '_wpnonce', 'mepr_members_nonce', 'mepr_transactions_nonce', 'mepr_subscriptions_nonce', 'paged' ];

	/**
	 * Merge the current screen's query params into an export URL.
	 *
	 * Params already on the export URL always win, so the nonce and action are never
	 * clobbered. Exported as meprmfMergeExportUrl for tests.
	 *
	 * @param {string} exportUrl  The href MemberPress rendered.
	 * @param {string} pageSearch The current location.search.
	 * @return {string} The href with the active filters merged in.
	 */
	function mergeExportUrl(exportUrl, pageSearch) {
		if (!exportUrl) {
			return exportUrl;
		}

		var current;
		try {
			current = new URLSearchParams(pageSearch || '');
		} catch (e) {
			return exportUrl;
		}

		var hashAt = exportUrl.indexOf('#');
		var hash = hashAt >= 0 ? exportUrl.slice(hashAt) : '';
		var base = hashAt >= 0 ? exportUrl.slice(0, hashAt) : exportUrl;

		var queryAt = base.indexOf('?');
		var path = queryAt >= 0 ? base.slice(0, queryAt) : base;
		var target;
		try {
			target = new URLSearchParams(queryAt >= 0 ? base.slice(queryAt + 1) : '');
		} catch (e) {
			return exportUrl;
		}

		current.forEach(function (value, key) {
			if (SKIP.indexOf(key) !== -1) {
				return;
			}
			if (target.has(key)) {
				return;
			}
			if (value === '') {
				return;
			}
			target.append(key, value);
		});

		var query = target.toString();
		return path + (query ? '?' + query : '') + hash;
	}

	function rewriteExportLinks(root, pageSearch) {
		var links = (root || document).querySelectorAll('a[href*="admin-ajax.php"]');
		var changed = 0;

		Array.prototype.forEach.call(links, function (link) {
			var href = link.getAttribute('href') || '';
			if (href.indexOf('action=mepr_') === -1) {
				return;
			}
			// Rewrite once, even if the table footer is re-rendered.
			if (link.getAttribute('data-meprmf-export') === '1') {
				return;
			}
			var next = mergeExportUrl(href, pageSearch);
			if (next !== href) {
				link.setAttribute('href', next);
				changed++;
			}
			link.setAttribute('data-meprmf-export', '1');
		});

		return changed;
	}

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = { mergeExportUrl: mergeExportUrl };
	}

	if (typeof document !== 'undefined' && document.addEventListener) {
		document.addEventListener('DOMContentLoaded', function () {
			rewriteExportLinks(document, window.location.search);
		});
	}
})();
