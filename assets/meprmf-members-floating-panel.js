/**
 * Members list — floating filter panel (Phase 1). See DESIGN-SCREENS-AND-COMPONENTS.md §11.
 */
(function () {
	'use strict';

	var LS_OPEN = 'meprmf_panel_open';
	var LS_VIS = 'meprmf_visible_filters';

	function getKnownKeys() {
		if (typeof window.meprmfMembersFloating === 'undefined' || !window.meprmfMembersFloating.knownParams) {
			return [];
		}
		return window.meprmfMembersFloating.knownParams;
	}

	function loadVisibleRaw() {
		try {
			var raw = localStorage.getItem(LS_VIS);
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

	function saveVisibleFromCheckboxes(root) {
		var out = [];
		var cbs = root.querySelectorAll('.meprmf-filter-panel__vis-cb');
		for (var i = 0; i < cbs.length; i++) {
			if (cbs[i].checked) {
				out.push(cbs[i].value);
			}
		}
		localStorage.setItem(LS_VIS, JSON.stringify(out));
	}

	function applyItemVisibility(root) {
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

	function syncCustomizeChecks(root) {
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

	function updateBadge(root) {
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

	function initRoot(root) {
		var toggle = root.querySelector('.meprmf-toggle-btn');
		var panel = root.querySelector('.meprmf-filter-panel');
		var modeFilter = root.querySelector('.meprmf-filter-panel__mode--filter');
		var modeCustomize = root.querySelector('.meprmf-filter-panel__mode--customize');
		if (!toggle || !panel || !modeFilter || !modeCustomize) {
			return;
		}

		function setPanelOpen(open) {
			localStorage.setItem(LS_OPEN, open ? 'true' : 'false');
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
				syncCustomizeChecks(root);
			} else {
				modeCustomize.hidden = true;
				modeFilter.hidden = false;
				panel.classList.remove('meprmf-filter-panel--customize');
			}
		}

		if (localStorage.getItem(LS_OPEN) === 'true') {
			toggle.setAttribute('aria-expanded', 'true');
			panel.removeAttribute('hidden');
			panel.classList.add('meprmf-filter-panel--open');
		}

		applyItemVisibility(root);

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
				localStorage.setItem(LS_OPEN, 'false');
				window.location.assign(u.toString());
			});
		}

		var clearBtn = root.querySelector('.meprmf-filter-panel__clear');
		if (clearBtn) {
			clearBtn.addEventListener('click', function () {
				var u = new URL(window.location.href);
				stripKnownParams(u);
				localStorage.setItem(LS_OPEN, 'false');
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
			applyItemVisibility(root);
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
				saveVisibleFromCheckboxes(root);
				applyItemVisibility(root);
			});
		}

		var fields = root.querySelectorAll('.mepr_filter_field');
		for (var f = 0; f < fields.length; f++) {
			fields[f].addEventListener('change', function () {
				updateBadge(root);
			});
			fields[f].addEventListener('input', function () {
				updateBadge(root);
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

	function boot() {
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
