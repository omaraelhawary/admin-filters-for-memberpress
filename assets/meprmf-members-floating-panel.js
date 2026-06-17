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

	function stripKnownParams(u) {
		getKnownKeys().forEach(function (key) {
			u.searchParams.delete(key);
		});
	}

	function normalizePresetsList(presets) {
		if (Array.isArray(presets)) {
			return presets;
		}
		if (presets && typeof presets === 'object') {
			return Object.keys(presets).map(function (key) {
				return presets[key];
			});
		}
		return [];
	}

	function mergePresetsList(existing, incoming, savedPreset) {
		var list = normalizePresetsList(incoming);
		if (list.length === 0) {
			list = normalizePresetsList(existing);
		}
		if (savedPreset && savedPreset.id) {
			var savedId = String(savedPreset.id).toLowerCase();
			list = list.filter(function (p) {
				return !p || String(p.id).toLowerCase() !== savedId;
			});
			list.push(savedPreset);
		}
		return list;
	}

	function collectActiveParamsFromPanel(root) {
		var out = {};
		if (!root) {
			return out;
		}
		root.querySelectorAll('.meprmf-filter-panel__item').forEach(function (item) {
			item.querySelectorAll('.mepr_filter_field').forEach(function (el) {
				var p = el.getAttribute('data-meprmf-param') || el.getAttribute('name');
				if (!p) {
					return;
				}
				var val = (el.value || '').trim();
				if (val !== '') {
					out[String(p)] = val;
				}
			});
		});
		return out;
	}

	function filterParamsToKnownKeys(params) {
		var known = {};
		getKnownKeys().forEach(function (key) {
			known[key] = true;
		});
		var out = {};
		Object.keys(params || {}).forEach(function (key) {
			if (!known[key]) {
				return;
			}
			var val = String(params[key] || '').trim();
			if (val !== '') {
				out[key] = val;
			}
		});
		return out;
	}

	function collectActiveParamsFromUrl() {
		var u = new URL(window.location.href);
		var out = {};
		getKnownKeys().forEach(function (key) {
			var v = u.searchParams.get(key);
			if (v !== null && String(v) !== '') {
				out[key] = String(v);
			}
		});
		return out;
	}

	function getNativeToolbarKeys() {
		if (typeof window.meprmfMembersFloating === 'undefined' || !window.meprmfMembersFloating.nativeParams) {
			return [];
		}
		return window.meprmfMembersFloating.nativeParams;
	}

	function collectNativeToolbarParams() {
		var out = {};
		var skipValues = { all: true, '': true };

		getNativeToolbarKeys().forEach(function (key) {
			var el = document.getElementById(String(key));
			if (!el) {
				return;
			}
			var val = (el.value || '').trim();
			if (val !== '' && !skipValues[val]) {
				out[String(key)] = val;
			}
		});

		return out;
	}

	/**
	 * Active filter params: visible fields from the panel, hidden fields preserved from the URL.
	 */
	function collectEffectiveActiveParams(root, visibleMap) {
		var fromUrl = collectActiveParamsFromUrl();
		var fromPanel = collectActiveParamsFromPanel(root);
		var fromNative = collectNativeToolbarParams();
		var vis = visibleMap || null;
		var out = {};

		Object.keys(fromUrl).forEach(function (key) {
			if (vis && !vis[key]) {
				out[key] = fromUrl[key];
			}
		});

		Object.keys(fromPanel).forEach(function (key) {
			if (!vis || vis[key]) {
				out[key] = fromPanel[key];
			}
		});

		Object.keys(fromNative).forEach(function (key) {
			out[key] = fromNative[key];
		});

		return out;
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

		function updateBadge() {
			var badge = root.querySelector('.meprmf-toggle-btn__badge');
			if (!badge) {
				return;
			}
			var n = Object.keys(collectEffectiveActiveParams(root, effectiveVisibleMap())).length;
			badge.textContent = String(n);
			if (n > 0) {
				badge.removeAttribute('hidden');
				badge.removeAttribute('aria-hidden');
			} else {
				badge.setAttribute('hidden', 'hidden');
				badge.setAttribute('aria-hidden', 'true');
			}
		}

		function getFocusableInPanel() {
			if (!panel) {
				return [];
			}
			var selector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
			return Array.prototype.slice.call(panel.querySelectorAll(selector)).filter(function (el) {
				return !el.hidden && el.offsetParent !== null;
			});
		}

		var focusTrapHandler = null;

		function enableFocusTrap() {
			disableFocusTrap();
			focusTrapHandler = function (ev) {
				if (ev.key !== 'Tab' || panel.hasAttribute('hidden')) {
					return;
				}
				var focusable = getFocusableInPanel();
				if (focusable.length === 0) {
					return;
				}
				var first = focusable[0];
				var last = focusable[focusable.length - 1];
				if (ev.shiftKey && document.activeElement === first) {
					ev.preventDefault();
					last.focus();
				} else if (!ev.shiftKey && document.activeElement === last) {
					ev.preventDefault();
					first.focus();
				}
			};
			document.addEventListener('keydown', focusTrapHandler);
		}

		function disableFocusTrap() {
			if (focusTrapHandler) {
				document.removeEventListener('keydown', focusTrapHandler);
				focusTrapHandler = null;
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
				enableFocusTrap();
				var firstField = panel.querySelector('.mepr_filter_field:not([hidden])');
				if (!firstField) {
					firstField = panel.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
				}
				if (firstField) {
					firstField.focus();
				}
			} else {
				disableFocusTrap();
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
			enableFocusTrap();
		}

		applyItemVisibility();
		updateBadge();

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
				var active = collectEffectiveActiveParams(root, effectiveVisibleMap());
				Object.keys(active).forEach(function (p) {
					u.searchParams.set(p, active[p]);
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

		initPresetsBar(root, panel, effectiveVisibleMap);
	}

	function getPresetsFromConfig() {
		var cfg = window.meprmfMembersFloating || {};
		return normalizePresetsList(cfg.presets);
	}

	function findPresetById(id) {
		var target = String(id || '');
		if (target === '') {
			return null;
		}
		var list = getPresetsFromConfig();
		for (var i = 0; i < list.length; i++) {
			if (String(list[i].id) === target) {
				return list[i];
			}
		}
		return null;
	}

	function normalizePresetParams(params) {
		var out = {};
		if (!params || typeof params !== 'object') {
			return out;
		}
		var known = {};
		getKnownKeys().forEach(function (key) {
			known[key] = true;
		});
		Object.keys(params).forEach(function (key) {
			if (!known[key]) {
				return;
			}
			var val = String(params[key] || '').trim();
			if (val !== '') {
				out[key] = val;
			}
		});
		return out;
	}

	function paramsMatchActiveUrl(presetParams) {
		var active = collectActiveParamsFromUrl();
		var preset = normalizePresetParams(presetParams);
		var activeKeys = Object.keys(active);
		var presetKeys = Object.keys(preset);
		if (activeKeys.length === 0 || presetKeys.length === 0 || activeKeys.length !== presetKeys.length) {
			return false;
		}
		for (var i = 0; i < presetKeys.length; i++) {
			var key = presetKeys[i];
			if (active[key] !== preset[key]) {
				return false;
			}
		}
		return true;
	}

	function findMatchingPresetId() {
		var list = getPresetsFromConfig();
		for (var i = 0; i < list.length; i++) {
			if (list[i] && list[i].id && paramsMatchActiveUrl(list[i].params)) {
				return String(list[i].id);
			}
		}
		return '';
	}

	function syncPresetSelectToActiveUrl(selectEl, loadBtn, deleteBtn) {
		if (!selectEl) {
			return;
		}
		var matchedId = findMatchingPresetId();
		selectEl.value = matchedId;
		syncPresetActionButtons(selectEl, loadBtn, deleteBtn);
	}

	function rebuildPresetSelect(selectEl, presets, selectedId, ensurePreset) {
		if (!selectEl) {
			return;
		}
		var cfg = window.meprmfMembersFloating || {};
		var i18n = cfg.i18n || {};
		var placeholder = i18n.presetsPlaceholder || '— Choose a preset —';
		var list = normalizePresetsList(presets);

		while (selectEl.firstChild) {
			selectEl.removeChild(selectEl.firstChild);
		}

		var emptyOpt = document.createElement('option');
		emptyOpt.value = '';
		emptyOpt.textContent = placeholder;
		selectEl.appendChild(emptyOpt);

		list.forEach(function (preset) {
			if (!preset || !preset.id || !preset.name) {
				return;
			}
			var opt = document.createElement('option');
			opt.value = String(preset.id);
			opt.textContent = String(preset.name);
			selectEl.appendChild(opt);
		});

		if (selectedId) {
			var sid = String(selectedId);
			selectEl.value = sid;
			if (selectEl.value !== sid && ensurePreset && String(ensurePreset.id) === sid && ensurePreset.name) {
				var fallbackOpt = document.createElement('option');
				fallbackOpt.value = sid;
				fallbackOpt.textContent = String(ensurePreset.name);
				selectEl.appendChild(fallbackOpt);
				selectEl.value = sid;
			}
			if (selectEl.value !== sid) {
				selectEl.value = '';
			}
		}
	}

	function setConfigPresets(presets) {
		if (!window.meprmfMembersFloating) {
			window.meprmfMembersFloating = {};
		}
		window.meprmfMembersFloating.presets = normalizePresetsList(presets);
	}

	function syncPresetActionButtons(selectEl, loadBtn, deleteBtn) {
		var hasSelection = !!(selectEl && selectEl.value);
		if (loadBtn) {
			loadBtn.disabled = !hasSelection;
		}
		if (deleteBtn) {
			deleteBtn.disabled = !hasSelection;
		}
	}

	function applyPresetParams(params) {
		var u = new URL(window.location.href);
		stripKnownParams(u);
		if (params && typeof params === 'object') {
			var known = {};
			getKnownKeys().forEach(function (key) {
				known[key] = true;
			});
			Object.keys(params).forEach(function (key) {
				if (!known[key]) {
					return;
				}
				var val = String(params[key] || '').trim();
				if (val !== '') {
					u.searchParams.set(key, val);
				}
			});
		}
		window.location.assign(u.toString());
	}

	function initPresetsBar(root, panel, getVisibleMap) {
		var cfg = window.meprmfMembersFloating || {};
		var i18n = cfg.i18n || {};
		var selectEl = panel.querySelector('.meprmf-filter-panel__preset-select');
		var loadBtn = panel.querySelector('.meprmf-filter-panel__preset-load');
		var saveBtn = panel.querySelector('.meprmf-filter-panel__preset-save');
		var deleteBtn = panel.querySelector('.meprmf-filter-panel__preset-delete');

		if (!selectEl || !loadBtn || !saveBtn || !deleteBtn) {
			return;
		}

		syncPresetSelectToActiveUrl(selectEl, loadBtn, deleteBtn);

		selectEl.addEventListener('change', function () {
			syncPresetActionButtons(selectEl, loadBtn, deleteBtn);
		});

		loadBtn.addEventListener('click', function () {
			if (loadBtn.disabled || !selectEl.value) {
				return;
			}
			var preset = findPresetById(selectEl.value);
			if (!preset || !preset.params) {
				return;
			}
			applyPresetParams(preset.params);
		});

		saveBtn.addEventListener('click', function () {
			var visMap = typeof getVisibleMap === 'function' ? getVisibleMap() : null;
			var active = filterParamsToKnownKeys(collectEffectiveActiveParams(root, visMap));
			if (Object.keys(active).length === 0) {
				window.alert(i18n.noActiveFilters || 'Apply at least one filter before saving a preset.');
				return;
			}
			if (!cfg.ajaxUrl || !cfg.presetsNonce) {
				window.alert(i18n.saveError || 'Could not save the preset. Please try again.');
				return;
			}

			var name = window.prompt(i18n.savePrompt || 'Preset name', '');
			if (name === null) {
				return;
			}
			name = String(name).trim();
			if (name === '') {
				return;
			}

			saveBtn.disabled = true;

			var body = new URLSearchParams();
			body.set('action', 'meprmf_save_filter_preset');
			body.set('nonce', cfg.presetsNonce);
			body.set('screen', cfg.storageId || storageNs());
			body.set('name', name);
			body.set('params', JSON.stringify(active));

			fetch(cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			})
				.then(function (res) {
					return res.json().then(function (data) {
						return { ok: res.ok, data: data };
					});
				})
				.then(function (result) {
					var data = result.data;
					if (!result.ok || !data || !data.success) {
						var msg = (data && data.data && data.data.message) ? data.data.message : (i18n.saveError || 'Could not save the preset. Please try again.');
						throw new Error(msg);
					}
					var savedPreset = data.data && data.data.preset ? data.data.preset : null;
					var presets = mergePresetsList(cfg.presets, data.data ? data.data.presets : null, savedPreset);
					setConfigPresets(presets);
					var selectedId = savedPreset && savedPreset.id ? savedPreset.id : '';
					rebuildPresetSelect(selectEl, presets, selectedId, savedPreset);
					syncPresetActionButtons(selectEl, loadBtn, deleteBtn);
				})
				.catch(function (err) {
					window.alert(err && err.message ? err.message : (i18n.saveError || 'Could not save the preset. Please try again.'));
				})
				.finally(function () {
					saveBtn.disabled = false;
				});
		});

		deleteBtn.addEventListener('click', function () {
			if (deleteBtn.disabled || !selectEl.value) {
				return;
			}
			if (!window.confirm(i18n.deleteConfirm || 'Delete this saved preset for all admins?')) {
				return;
			}
			if (!cfg.ajaxUrl || !cfg.presetsNonce) {
				window.alert(i18n.deleteError || 'Could not delete the preset. Please try again.');
				return;
			}

			deleteBtn.disabled = true;

			var body = new URLSearchParams();
			body.set('action', 'meprmf_delete_filter_preset');
			body.set('nonce', cfg.presetsNonce);
			body.set('screen', cfg.storageId || storageNs());
			body.set('id', selectEl.value);

			fetch(cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			})
				.then(function (res) {
					return res.json().then(function (data) {
						return { ok: res.ok, data: data };
					});
				})
				.then(function (result) {
					var data = result.data;
					if (!result.ok || !data || !data.success) {
						var msg = (data && data.data && data.data.message) ? data.data.message : (i18n.deleteError || 'Could not delete the preset. Please try again.');
						throw new Error(msg);
					}
					var presets = normalizePresetsList(data.data ? data.data.presets : null);
					setConfigPresets(presets);
					rebuildPresetSelect(selectEl, presets, '');
					syncPresetActionButtons(selectEl, loadBtn, deleteBtn);
				})
				.catch(function (err) {
					window.alert(err && err.message ? err.message : (i18n.deleteError || 'Could not delete the preset. Please try again.'));
				})
				.finally(function () {
					syncPresetActionButtons(selectEl, loadBtn, deleteBtn);
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
