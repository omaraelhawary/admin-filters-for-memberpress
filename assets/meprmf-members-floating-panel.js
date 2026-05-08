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
			for (var i = 0; i < keys.length; i++) {
				o[String(keys[i])] = true;
			}
			return o;
		}

		function effectiveVisibleMap() {
			var arr = loadVisibleRaw();
			if (arr === null) {
				return allParamsMap();
			}
			var o = {};
			for (var i = 0; i < arr.length; i++) {
				o[String(arr[i])] = true;
			}
			return o;
		}

		function saveVisibleFromCheckboxes() {
			var out = [];
			var cbs = root.querySelectorAll('.meprmf-filter-panel__vis-cb');
			for (var i = 0; i < cbs.length; i++) {
				if (cbs[i].checked) {
					out.push(cbs[i].value);
				}
			}
			localStorage.setItem(k.vis, JSON.stringify(out));
		}

		function applyItemVisibility() {
			var vis = effectiveVisibleMap();
			var items = root.querySelectorAll('.meprmf-filter-panel__item');
			var any = false;
			for (var i = 0; i < items.length; i++) {
				var item = items[i];
				var p = item.getAttribute('data-meprmf-param');
				var show = !!(p && vis[p]);
				item.hidden = !show;
				if (show) {
					any = true;
				}
			}
			var emptyEl = root.querySelector('.meprmf-filter-panel__empty');
			var gridEl = root.querySelector('.meprmf-filter-panel__grid');
			if (emptyEl && gridEl) {
				emptyEl.hidden = any;
				gridEl.hidden = !any;
			}
		}

		function syncCustomizeChecks() {
			var vis = effectiveVisibleMap();
			var cbs = root.querySelectorAll('.meprmf-filter-panel__vis-cb');
			for (var i = 0; i < cbs.length; i++) {
				var cb = cbs[i];
				cb.checked = !!vis[cb.value];
			}
		}

		function stripKnownParams(u) {
			var keys = getKnownKeys();
			for (var i = 0; i < keys.length; i++) {
				u.searchParams.delete(keys[i]);
			}
		}

		function updateBadge() {
			var badge = root.querySelector('.meprmf-toggle-btn__badge');
			if (!badge) {
				return;
			}
			var u = new URL(window.location.href);
			var keys = getKnownKeys();
			var n = 0;
			for (var i = 0; i < keys.length; i++) {
				var v = u.searchParams.get(keys[i]);
				if (v !== null && String(v) !== '') {
					n++;
				}
			}
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
			var prev = localStorage.getItem(k.sig);
			if (prev !== null && prev !== current) {
				localStorage.removeItem(k.vis);
			}
			localStorage.setItem(k.sig, current);
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
			localStorage.setItem(k.open, open ? 'true' : 'false');
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) {
				panel.removeAttribute('hidden');
				panel.classList.add('meprmf-filter-panel--open');
			} else {
				panel.setAttribute('hidden', 'hidden');
				panel.classList.remove('meprmf-filter-panel--open');
				modeCustomize.hidden = true;
				modeFilter.hidden = false;
				panel.classList.remove('meprmf-filter-panel--customize');
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

		if (localStorage.getItem(k.open) === 'true') {
			toggle.setAttribute('aria-expanded', 'true');
			panel.removeAttribute('hidden');
			panel.classList.add('meprmf-filter-panel--open');
		}

		applyItemVisibility();

		toggle.addEventListener('click', function () {
			var open = toggle.getAttribute('aria-expanded') === 'true';
			setPanelOpen(!open);
		});

		var applyBtn = root.querySelector('.meprmf-filter-panel__apply');
		if (applyBtn) {
			applyBtn.addEventListener('click', function () {
				var u = new URL(window.location.href);
				stripKnownParams(u);
				var vis = effectiveVisibleMap();
				var items = root.querySelectorAll('.meprmf-filter-panel__item');
				for (var i = 0; i < items.length; i++) {
					var item = items[i];
					var param = item.getAttribute('data-meprmf-param');
					if (!param || !vis[param]) {
						continue;
					}
					var el = item.querySelector('.mepr_filter_field');
					if (!el) {
						continue;
					}
					var val = (el.value || '').trim();
					if (val !== '') {
						u.searchParams.set(param, val);
					}
				}
				localStorage.setItem(k.open, 'false');
				window.location.assign(u.toString());
			});
		}

		var clearBtn = root.querySelector('.meprmf-filter-panel__clear');
		if (clearBtn) {
			clearBtn.addEventListener('click', function () {
				var u = new URL(window.location.href);
				stripKnownParams(u);
				localStorage.setItem(k.open, 'false');
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
		function leaveCustomize() {
			setCustomizeMode(false);
			applyItemVisibility();
		}
		if (backBtn) {
			backBtn.addEventListener('click', leaveCustomize);
		}
		if (doneBtn) {
			doneBtn.addEventListener('click', leaveCustomize);
		}

		var visCbs = root.querySelectorAll('.meprmf-filter-panel__vis-cb');
		for (var j = 0; j < visCbs.length; j++) {
			visCbs[j].addEventListener('change', function () {
				saveVisibleFromCheckboxes();
				applyItemVisibility();
			});
		}

		var fields = root.querySelectorAll('.mepr_filter_field');
		for (var f = 0; f < fields.length; f++) {
			fields[f].addEventListener('change', function () {
				updateBadge();
			});
			fields[f].addEventListener('input', function () {
				updateBadge();
			});
			fields[f].addEventListener('keydown', function (ev) {
				if (ev.key !== 'Enter') {
					return;
				}
				ev.preventDefault();
				if (applyBtn) {
					applyBtn.click();
				}
			});
		}
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
		relocateFloatingPanelsFromPool();
		var roots = document.querySelectorAll('.meprmf-floating-root');
		for (var r = 0; r < roots.length; r++) {
			initRoot(roots[r]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
