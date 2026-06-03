/**
 * MemberPress admin lists — floating filter panel (Phase 1). See DESIGN-SCREENS-AND-COMPONENTS.md §11.
 */
(function () {
	'use strict';

	function getKnownKeys() {
		if (typeof window.meprmfMembersFloating === 'undefined' || !window.meprmfMembersFloating.knownParams) {
			return [];
		}
		return window.meprmfMembersFloating.knownParams;
	}

	function storageNs() {
		var f = window.meprmfMembersFloating;
		if (f && f.storageId) {
			return String(f.storageId);
		}
		return 'memberpress_members';
	}

	function lsKeys() {
		var ns = storageNs();
		return {
			open: 'meprmf_panel_open_' + ns,
			vis: 'meprmf_visible_filters_' + ns,
			sig: 'meprmf_visible_filters_sig_' + ns
		};
	}

	function safeSet(key, value) {
		try {
			localStorage.setItem(key, value);
		} catch (e) {
			/* private mode / quota */
		}
	}

	function safeRemove(key) {
		try {
			localStorage.removeItem(key);
		} catch (e) {
			/* private mode / quota */
		}
	}

	function safeGet(key) {
		try {
			return localStorage.getItem(key);
		} catch (e) {
			return null;
		}
	}

	/**
	 * Bookmarked ?*_access=expired URLs still filter; rewrite to inactive so the dropdown matches.
	 */
	function canonicalizeLegacyAccessParam() {
		var u = new URL(window.location.href);
		var changed = false;
		[ 'mpm_access', 'mpmt_access', 'mpms_access', 'mpml_access' ].forEach(function (key) {
			if (u.searchParams.get(key) === 'expired') {
				u.searchParams.set(key, 'inactive');
				changed = true;
			}
		});
		if (changed) {
			history.replaceState(null, '', u.toString());
		}
	}

	function initRoot(root) {
		var k = lsKeys();

		function loadVisibleRaw() {
			try {
				var raw = localStorage.getItem(k.vis);
				if (raw === null || raw === '') {
					return null;
				}
				var arr = JSON.parse(raw);
				if (!Array.isArray(arr)) {
					return null;
				}
				return arr;
			} catch (e) {
				return null;
			}
		}

		function allParamsMap() {
			var keys = getKnownKeys();
			var o = {};
			keys.forEach(function (key) {
				o[String(key)] = true;
			});
			return o;
		}

		function effectiveVisibleMap() {
			var arr = loadVisibleRaw();
			if (arr === null) {
				return allParamsMap();
			}
			var o = {};
			arr.forEach(function (param) {
				o[String(param)] = true;
			});
			return o;
		}

		function saveVisibleFromCheckboxes() {
			var out = [];
			root.querySelectorAll('.meprmf-filter-panel__vis-cb').forEach(function (cb) {
				if (cb.checked) {
					out.push(cb.value);
				}
			});
			safeSet(k.vis, JSON.stringify(out));
		}

		function applyItemVisibility() {
			var vis = effectiveVisibleMap();
			var any = false;
			root.querySelectorAll('.meprmf-filter-panel__item').forEach(function (item) {
				var p = item.getAttribute('data-meprmf-param');
				var show = !!(p && vis[p]);
				item.hidden = !show;
				if (show) {
					any = true;
				}
			});
			var emptyEl = root.querySelector('.meprmf-filter-panel__empty');
			var gridEl = root.querySelector('.meprmf-filter-panel__grid');
			if (emptyEl && gridEl) {
				emptyEl.hidden = any;
				gridEl.hidden = !any;
			}
		}

		function syncCustomizeChecks() {
			var vis = effectiveVisibleMap();
			root.querySelectorAll('.meprmf-filter-panel__vis-cb').forEach(function (cb) {
				cb.checked = !!vis[cb.value];
			});
		}

		function stripKnownParams(u) {
			getKnownKeys().forEach(function (key) {
				u.searchParams.delete(key);
			});
		}

		function updateBadge() {
			var badge = root.querySelector('.meprmf-toggle-btn__badge');
			if (!badge) {
				return;
			}
			var u = new URL(window.location.href);
			var n = 0;
			getKnownKeys().forEach(function (key) {
				var v = u.searchParams.get(key);
				if (v !== null && String(v) !== '') {
					n++;
				}
			});
			badge.textContent = String(n);
			if (n > 0) {
				badge.removeAttribute('hidden');
				badge.removeAttribute('aria-hidden');
			} else {
				badge.setAttribute('hidden', 'hidden');
				badge.setAttribute('aria-hidden', 'true');
			}
		}

		function invalidateVisibleIfKnownParamsChanged() {
			var floating = window.meprmfMembersFloating;
			if (!floating || typeof floating.knownParamsSignature !== 'string' || floating.knownParamsSignature === '') {
				return;
			}
			var current = String(floating.knownParamsSignature);
			var prev = safeGet(k.sig);
			if (prev !== null && prev !== current) {
				safeRemove(k.vis);
			}
			safeSet(k.sig, current);
		}

		var toggle = root.querySelector('.meprmf-toggle-btn');
		var panel = root.querySelector('.meprmf-filter-panel');
		var modeFilter = root.querySelector('.meprmf-filter-panel__mode--filter');
		var modeCustomize = root.querySelector('.meprmf-filter-panel__mode--customize');
		if (!toggle || !panel || !modeFilter || !modeCustomize) {
			return;
		}

		invalidateVisibleIfKnownParamsChanged();

		function setPanelOpen(open) {
			safeSet(k.open, open ? 'true' : 'false');
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) {
				panel.removeAttribute('hidden');
				panel.classList.add('meprmf-filter-panel--open');
				var firstField = panel.querySelector('.mepr_filter_field');
				if (firstField) {
					firstField.focus();
				}
			} else {
				panel.setAttribute('hidden', 'hidden');
				panel.classList.remove('meprmf-filter-panel--open');
				modeCustomize.hidden = true;
				modeFilter.hidden = false;
				panel.classList.remove('meprmf-filter-panel--customize');
				toggle.focus();
			}
		}

		function setCustomizeMode(on) {
			if (on) {
				modeFilter.hidden = true;
				modeCustomize.hidden = false;
				panel.classList.add('meprmf-filter-panel--customize');
				syncCustomizeChecks();
			} else {
				modeCustomize.hidden = true;
				modeFilter.hidden = false;
				panel.classList.remove('meprmf-filter-panel--customize');
			}
		}

		if (safeGet(k.open) === 'true') {
			toggle.setAttribute('aria-expanded', 'true');
			panel.removeAttribute('hidden');
			panel.classList.add('meprmf-filter-panel--open');
		}

		applyItemVisibility();

		toggle.addEventListener('click', function () {
			var open = toggle.getAttribute('aria-expanded') === 'true';
			setPanelOpen(!open);
		});

		panel.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape') {
				setPanelOpen(false);
			}
		});

		var applyBtn = root.querySelector('.meprmf-filter-panel__apply');
		if (applyBtn) {
			applyBtn.addEventListener('click', function () {
				if (applyBtn.disabled) {
					return;
				}
				applyBtn.disabled = true;
				applyBtn.classList.add('is-busy');
				var u = new URL(window.location.href);
				stripKnownParams(u);
				var vis = effectiveVisibleMap();
				root.querySelectorAll('.meprmf-filter-panel__item').forEach(function (item) {
					var panelParam = item.getAttribute('data-meprmf-param');
					if (!panelParam || !vis[panelParam]) {
						return;
					}
					item.querySelectorAll('.mepr_filter_field').forEach(function (el) {
						var p = el.getAttribute('data-meprmf-param') || el.getAttribute('name') || panelParam;
						if (!p) {
							return;
						}
						var val = (el.value || '').trim();
						if (val !== '') {
							u.searchParams.set(p, val);
						}
					});
				});
				safeSet(k.open, 'false');
				window.location.assign(u.toString());
			});
		}

		var clearBtn = root.querySelector('.meprmf-filter-panel__clear');
		if (clearBtn) {
			clearBtn.addEventListener('click', function () {
				var u = new URL(window.location.href);
				stripKnownParams(u);
				safeSet(k.open, 'false');
				window.location.assign(u.toString());
			});
		}

		var custBtn = root.querySelector('.meprmf-filter-panel__customize');
		if (custBtn) {
			custBtn.addEventListener('click', function () {
				setCustomizeMode(true);
			});
		}

		var backBtn = root.querySelector('.meprmf-filter-panel__back');
		var doneBtn = root.querySelector('.meprmf-filter-panel__done');
		var dateRangeCb = root.querySelector('.meprmf-filter-panel__date-range-cb');
		var floatingCfg = window.meprmfMembersFloating || {};
		var initialDateRangeEnabled = !!floatingCfg.dateRangeEnabled;

		function saveDateRangePrefIfChanged(done) {
			if (!dateRangeCb || !floatingCfg.ajaxUrl || !floatingCfg.dateRangeNonce) {
				done();
				return;
			}
			var enabled = !!dateRangeCb.checked;
			if (enabled === initialDateRangeEnabled) {
				done();
				return;
			}
			var body = new URLSearchParams();
			body.set('action', 'meprmf_save_date_range_pref');
			body.set('nonce', floatingCfg.dateRangeNonce);
			body.set('enabled', enabled ? '1' : '0');
			fetch(floatingCfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			})
				.then(function (res) {
					if (!res.ok) {
						throw new Error('HTTP ' + res.status);
					}
					return res.json();
				})
				.then(function (data) {
					if (!data || !data.success) {
						throw new Error('save_failed');
					}
					window.location.reload();
				})
				.catch(function () {
					window.alert('Could not save the date range preference. Please try again.');
					done();
				});
		}

		function leaveCustomize() {
			saveDateRangePrefIfChanged(function () {
				setCustomizeMode(false);
				applyItemVisibility();
			});
		}
		if (backBtn) {
			backBtn.addEventListener('click', leaveCustomize);
		}
		if (doneBtn) {
			doneBtn.addEventListener('click', leaveCustomize);
		}

		root.querySelectorAll('.meprmf-filter-panel__vis-cb').forEach(function (cb) {
			cb.addEventListener('change', function () {
				saveVisibleFromCheckboxes();
				applyItemVisibility();
			});
		});

		root.querySelectorAll('.mepr_filter_field').forEach(function (field) {
			field.addEventListener('change', function () {
				updateBadge();
			});
			field.addEventListener('input', function () {
				updateBadge();
			});
			field.addEventListener('keydown', function (ev) {
				if (ev.key !== 'Enter') {
					return;
				}
				ev.preventDefault();
				if (applyBtn) {
					applyBtn.click();
				}
			});
		});
	}

	/**
	 * Panel markup is printed in admin_footer (valid HTML). Move each panel next to its toggle before wiring handlers.
	 */
	function relocateFloatingPanelsFromPool() {
		var pool = document.getElementById('meprmf-floating-panels-pool');
		if (!pool) {
			return;
		}
		document.querySelectorAll('.meprmf-floating-root').forEach(function (root) {
			var toggle = root.querySelector('.meprmf-toggle-btn[aria-controls]');
			if (!toggle) {
				return;
			}
			var id = toggle.getAttribute('aria-controls');
			if (!id) {
				return;
			}
			var panel = document.getElementById(id);
			if (panel && panel.parentNode === pool) {
				root.appendChild(panel);
			}
		});
		if (pool.parentNode) {
			pool.parentNode.removeChild(pool);
		}
	}

	function boot() {
		canonicalizeLegacyAccessParam();
		relocateFloatingPanelsFromPool();
		document.querySelectorAll('.meprmf-floating-root').forEach(function (root) {
			initRoot(root);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
