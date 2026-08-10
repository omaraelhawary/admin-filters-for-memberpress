/**
 * MemberPress admin lists — query-builder filter card.
 *
 * The card shell is printed by Meprmf_Toolbar_Renderer into an admin_footer pool; this script
 * moves it above the list table and renders one row per filter from the localized field catalog.
 * Field labels and saved-view names are always written with textContent / setAttribute: they can
 * contain anything an admin typed.
 */
(function () {
	'use strict';

	var GLYPH_CARET_OPEN = '▾';
	var GLYPH_CARET_CLOSED = '▸';
	var GLYPH_REMOVE = '×';
	var VALUELESS_OPS = { is_empty: true, is_not_empty: true };
	var RELATIVE_OPS = { in_last: true, not_in_last: true };

	function cfg() {
		return window.meprmfMembersFloating || {};
	}

	function i18n(key, fallback) {
		var strings = cfg().i18n || {};
		return typeof strings[key] === 'string' && strings[key] !== '' ? strings[key] : fallback;
	}

	function sprintf1(template, value) {
		return String(template).replace('%s', value);
	}

	function catalog() {
		return Array.isArray(cfg().catalog) ? cfg().catalog : [];
	}

	function entryByParam(param) {
		var list = catalog();
		for (var i = 0; i < list.length; i++) {
			if (String(list[i].param) === String(param)) {
				return list[i];
			}
		}
		return null;
	}

	function opTokens(entry) {
		return (entry && Array.isArray(entry.ops) ? entry.ops : []).map(function (op) {
			return String(op.v);
		});
	}

	function pickOp(entry, wanted) {
		var tokens = opTokens(entry);
		if (tokens.indexOf(wanted) !== -1) {
			return wanted;
		}
		return tokens.length > 0 ? tokens[0] : '';
	}

	function defaultOp(entry) {
		// No operator in the URL means the field used its own match mode: LIKE for text,
		// exact for everything else. Read that back as the operator that means the same thing.
		return pickOp(entry, entry.kind === 'text' ? 'contains' : 'is');
	}

	function matchParam() {
		return cfg().matchParam || 'meprmf_match';
	}

	function appliedMatchMode() {
		return cfg().matchMode === 'any' ? 'any' : 'all';
	}

	function relativeUnits() {
		var units = cfg().relativeUnits;
		if (Array.isArray(units) && units.length > 0) {
			return units;
		}
		return [ { v: 'days', l: 'days' }, { v: 'weeks', l: 'weeks' }, { v: 'months', l: 'months' }, { v: 'years', l: 'years' } ];
	}

	function kindLabel(kind) {
		switch (kind) {
			case 'choice':
				return i18n('kindChoice', 'choice');
			case 'date':
				return i18n('kindDate', 'date');
			case 'number':
				return i18n('kindNumber', 'number');
			default:
				return i18n('kindText', 'text');
		}
	}

	function knownKeys() {
		return Array.isArray(cfg().knownParams) ? cfg().knownParams : [];
	}

	function nativeKeys() {
		return Array.isArray(cfg().nativeParams) ? cfg().nativeParams : [];
	}

	function storageNs() {
		return cfg().storageId ? String(cfg().storageId) : 'memberpress_members';
	}

	function lsKeys() {
		return { open: 'meprmf_panel_open_' + storageNs() };
	}

	function safeSet(key, value) {
		try {
			localStorage.setItem(key, value);
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

	/* ---------------------------------------------------------------- URL plumbing */

	function stripKnownParams(u) {
		knownKeys().forEach(function (key) {
			u.searchParams.delete(key);
		});
	}

	function collectNativeToolbarParams() {
		var out = {};
		var skipValues = { all: true, '': true };

		nativeKeys().forEach(function (key) {
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
	 * Transactions: MemberPress always renders #date_field (default created_at) even when
	 * date_range_filter is "all". Do not carry date params unless a range is actually set.
	 */
	function stripInactiveTransactionDateParams(params) {
		var out = {};
		Object.keys(params || {}).forEach(function (key) {
			out[key] = params[key];
		});

		if (nativeKeys().indexOf('date_range_filter') === -1) {
			return out;
		}

		var dateRange = typeof out.date_range_filter === 'string' ? out.date_range_filter.trim() : '';
		if (!dateRange) {
			var drEl = document.getElementById('date_range_filter');
			if (drEl) {
				dateRange = (drEl.value || '').trim();
			}
		}

		if (!dateRange || dateRange === 'all') {
			delete out.date_range_filter;
			delete out.date_field;
			delete out.date_start;
			delete out.date_end;
		}

		return out;
	}

	/**
	 * Bookmarked ?*_access=expired URLs still filter; rewrite to inactive so the row matches.
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

	/* ---------------------------------------------------------------- row model */

	var rowSeq = 0;

	function makeRow(entry, op, v1, v2) {
		rowSeq += 1;
		return {
			id: 'r' + rowSeq,
			param: String(entry.param),
			op: op,
			v1: typeof v1 === 'string' ? v1 : '',
			v2: typeof v2 === 'string' ? v2 : ''
		};
	}

	function shapeFor(entry, op) {
		if (VALUELESS_OPS[op]) {
			return 'none';
		}
		if (RELATIVE_OPS[op]) {
			return 'relative';
		}
		if (op === 'between') {
			return 'two';
		}
		return 'one';
	}

	/**
	 * Which of the entry's params a single value is written to.
	 */
	function valueSlots(entry, op) {
		if (!entry.pair) {
			return [ 'value' ];
		}
		if (op === 'after' || op === 'at_least') {
			return [ 'from' ];
		}
		if (op === 'before' || op === 'at_most') {
			return [ 'to' ];
		}
		// `is` on a pair pins both bounds to the same value, which is an exact-day match.
		return [ 'from', 'to' ];
	}

	function rowIsActive(row) {
		if (VALUELESS_OPS[row.op]) {
			return true;
		}
		return String(row.v1 || '').trim() !== '' || String(row.v2 || '').trim() !== '';
	}

	/**
	 * GET params one row writes; empty when the row has no value to apply.
	 */
	function writesForRow(entry, row) {
		var p = entry.params || {};
		var out = {};
		var v1 = String(row.v1 || '').trim();
		var v2 = String(row.v2 || '').trim();

		if (VALUELESS_OPS[row.op]) {
			if (p.op) {
				out[p.op] = row.op;
			}
			return out;
		}

		if (RELATIVE_OPS[row.op]) {
			if (v1 === '') {
				return {};
			}
			if (p.op) {
				out[p.op] = row.op;
			}
			if (p.n) {
				out[p.n] = v1;
			}
			if (p.u) {
				out[p.u] = v2 !== '' ? v2 : 'days';
			}
			return out;
		}

		if (row.op === 'between') {
			if (v1 === '' && v2 === '') {
				return {};
			}
			if (p.op) {
				out[p.op] = row.op;
			}
			if (v1 !== '' && p.from) {
				out[p.from] = v1;
			}
			if (v2 !== '' && p.to) {
				out[p.to] = v2;
			}
			return out;
		}

		if (v1 === '') {
			return {};
		}
		if (p.op && row.op !== '') {
			out[p.op] = row.op;
		}
		valueSlots(entry, row.op).forEach(function (slot) {
			if (p[slot]) {
				out[p[slot]] = v1;
			}
		});

		return out;
	}

	function rowFromParams(entry, read) {
		var p = entry.params || {};
		var tokens = opTokens(entry);
		var rawOp = p.op ? read(p.op) : '';
		var op = tokens.indexOf(rawOp) === -1 ? '' : rawOp;

		var value = p.value ? read(p.value) : '';
		var from = p.from ? read(p.from) : '';
		var to = p.to ? read(p.to) : '';
		var n = p.n ? read(p.n) : '';
		var unit = p.u ? read(p.u) : '';

		if (VALUELESS_OPS[op]) {
			return makeRow(entry, op, '', '');
		}
		if (RELATIVE_OPS[op]) {
			return n !== '' ? makeRow(entry, op, n, unit || 'days') : null;
		}
		if (op === 'between') {
			return (from !== '' || to !== '') ? makeRow(entry, op, from, to) : null;
		}
		if (op === 'after') {
			var afterVal = value !== '' ? value : from;
			return afterVal !== '' ? makeRow(entry, op, afterVal, '') : null;
		}
		if (op === 'before') {
			var beforeVal = value !== '' ? value : to;
			return beforeVal !== '' ? makeRow(entry, op, beforeVal, '') : null;
		}
		if (op !== '') {
			var single = value !== '' ? value : (op === 'at_most' ? to : from);
			return single !== '' ? makeRow(entry, op, single, '') : null;
		}

		// Pre-2.1 URL with no operator: infer the row from the params that carry a value.
		if (value !== '') {
			return makeRow(entry, defaultOp(entry), value, '');
		}
		if (from !== '' && to !== '') {
			return makeRow(entry, pickOp(entry, 'between'), from, to);
		}
		if (from !== '') {
			return makeRow(entry, pickOp(entry, entry.kind === 'number' ? 'at_least' : 'after'), from, '');
		}
		if (to !== '') {
			return makeRow(entry, pickOp(entry, entry.kind === 'number' ? 'at_most' : 'before'), to, '');
		}
		if (n !== '') {
			return makeRow(entry, pickOp(entry, 'in_last'), n, unit || 'days');
		}

		return null;
	}

	function rowsFromMap(map) {
		var rows = [];
		var read = function (key) {
			var raw = map[key];
			return typeof raw === 'string' ? raw.trim() : '';
		};
		catalog().forEach(function (entry) {
			var row = rowFromParams(entry, read);
			if (row) {
				rows.push(row);
			}
		});
		return rows;
	}

	function rowsFromUrl() {
		var sp = new URL(window.location.href).searchParams;
		var map = {};
		knownKeys().forEach(function (key) {
			var v = sp.get(key);
			if (v !== null) {
				map[key] = v;
			}
		});
		return rowsFromMap(map);
	}

	function signature(rows, match) {
		return rows
			.filter(rowIsActive)
			.map(function (row) {
				return row.param + '|' + row.op + '|' + row.v1 + '|' + row.v2;
			})
			.sort()
			.join(';') + '#' + match;
	}

	/* ---------------------------------------------------------------- small DOM helpers */

	function el(tag, className) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		return node;
	}

	function textNode(tag, className, text) {
		var node = el(tag, className);
		node.textContent = String(text);
		return node;
	}

	function clear(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function optionNode(value, label, selected) {
		var opt = document.createElement('option');
		opt.value = String(value);
		opt.textContent = String(label);
		if (selected) {
			opt.selected = true;
		}
		return opt;
	}

	/* ---------------------------------------------------------------- card controller */

	function initCard(card) {
		var keys = lsKeys();
		var disclosure = card.querySelector('.meprmf-qb__disclosure');
		var countBadge = card.querySelector('[data-meprmf-count]');
		var addBtn = card.querySelector('.meprmf-qb__add');
		var popover = card.querySelector('.meprmf-qb__popover');
		var popoverList = card.querySelector('[data-meprmf-popover-list]');
		var searchInput = card.querySelector('.meprmf-qb__search');
		var viewsSelect = card.querySelector('[data-meprmf-views]');
		var deleteViewBtn = card.querySelector('[data-meprmf-delete-view]');
		var body = card.querySelector('.meprmf-qb__body');
		var footer = card.querySelector('.meprmf-qb__footer');
		var matchWrap = card.querySelector('[data-meprmf-match]');
		var rowsWrap = card.querySelector('[data-meprmf-rows]');
		var emptyWrap = card.querySelector('[data-meprmf-empty]');
		var chipsWrap = card.querySelector('[data-meprmf-chips]');
		var statusEl = card.querySelector('[data-meprmf-status]');
		var applyBtn = card.querySelector('[data-meprmf-apply]');
		var clearBtn = card.querySelector('[data-meprmf-clear]');
		var saveBtn = card.querySelector('[data-meprmf-save-view]');

		if (!disclosure || !addBtn || !popover || !popoverList || !body || !rowsWrap || !emptyWrap) {
			return;
		}

		var applied = rowsFromUrl();
		var state = {
			rows: applied.map(function (row) {
				return { id: row.id, param: row.param, op: row.op, v1: row.v1, v2: row.v2 };
			}),
			appliedSignature: signature(applied, appliedMatchMode()),
			match: appliedMatchMode(),
			open: safeGet(keys.open) !== 'false',
			popoverOpen: false,
			query: ''
		};

		/* ---- chips: only meaningful while the rows are hidden ---- */

		var chips = document.querySelector('.meprmf-active-filters');
		if (chips && chipsWrap) {
			chipsWrap.appendChild(chips);
		}

		function syncChips() {
			if (!chipsWrap) {
				return;
			}
			var show = !state.open && !!chips && chips.children.length > 0;
			chipsWrap.hidden = !show;
		}

		/* ---- disclosure ---- */

		function syncOpen() {
			disclosure.setAttribute('aria-expanded', state.open ? 'true' : 'false');
			var caret = disclosure.querySelector('.meprmf-qb__caret');
			if (caret) {
				caret.textContent = state.open ? GLYPH_CARET_OPEN : GLYPH_CARET_CLOSED;
			}
			body.hidden = !state.open;
			if (footer) {
				footer.hidden = !state.open;
			}
			card.classList.toggle('meprmf-qb--collapsed', !state.open);
			syncChips();
		}

		function setOpen(open) {
			state.open = !!open;
			safeSet(keys.open, state.open ? 'true' : 'false');
			if (!state.open) {
				closePopover();
			}
			syncOpen();
		}

		disclosure.addEventListener('click', function () {
			setOpen(!state.open);
		});

		/* ---- rows ---- */

		function unitGlyph(entry) {
			return entry.unit ? textNode('span', 'meprmf-qb__unit', entry.unit) : null;
		}

		function onValueChange(row, slot) {
			return function (ev) {
				row[slot] = String(ev.target.value || '');
				syncFooter();
			};
		}

		function valueInput(entry, row, slot, ariaLabel, extraClass) {
			var input = el('input', 'meprmf-qb__input ' + extraClass);
			if (entry.kind === 'date') {
				input.type = 'date';
			} else if (entry.kind === 'number') {
				input.type = 'number';
				input.step = 'any';
			} else {
				input.type = 'text';
				input.placeholder = i18n('valuePlaceholder', 'Type a value…');
			}
			input.value = row[slot] || '';
			input.setAttribute('aria-label', ariaLabel);
			input.addEventListener('input', onValueChange(row, slot));
			input.addEventListener('change', onValueChange(row, slot));
			input.addEventListener('keydown', function (ev) {
				if (ev.key === 'Enter') {
					ev.preventDefault();
					applyFilters();
				}
			});
			return input;
		}

		function choiceSelect(entry, row) {
			var select = el('select', 'meprmf-qb__input meprmf-qb__input--choice');
			select.setAttribute('aria-label', sprintf1(i18n('valueFor', 'Value for %s'), entry.label));
			select.appendChild(optionNode('', i18n('anyValue', 'Any value'), row.v1 === ''));
			(entry.options || []).forEach(function (opt) {
				select.appendChild(optionNode(opt.v, opt.l, String(row.v1) === String(opt.v)));
			});
			select.addEventListener('change', onValueChange(row, 'v1'));
			return select;
		}

		function relativeControls(entry, row, target) {
			var n = el('input', 'meprmf-qb__input meprmf-qb__input--n');
			n.type = 'number';
			n.min = '1';
			n.value = row.v1 || '';
			// Two controls, so two names: sharing one leaves a screen reader announcing the
			// amount and the unit identically.
			n.setAttribute('aria-label', sprintf1(i18n('windowAmountFor', '%s window length'), entry.label));
			n.addEventListener('input', onValueChange(row, 'v1'));
			target.appendChild(n);

			var unit = el('select', 'meprmf-qb__input meprmf-qb__input--unit');
			unit.setAttribute('aria-label', sprintf1(i18n('windowUnitFor', '%s window unit'), entry.label));
			relativeUnits().forEach(function (choice) {
				unit.appendChild(optionNode(choice.v, choice.l, String(row.v2 || 'days') === String(choice.v)));
			});
			unit.addEventListener('change', onValueChange(row, 'v2'));
			target.appendChild(unit);
		}

		function buildValueControls(entry, row, target) {
			clear(target);
			var shape = shapeFor(entry, row.op);

			if (shape === 'none') {
				target.appendChild(textNode('span', 'meprmf-qb__no-value', i18n('noValueNeeded', 'no value needed')));
				return;
			}

			if (shape === 'relative') {
				relativeControls(entry, row, target);
				return;
			}

			if (shape === 'two') {
				var glyph = unitGlyph(entry);
				if (glyph) {
					target.appendChild(glyph);
				}
				target.appendChild(
					valueInput(entry, row, 'v1', sprintf1(i18n('valueFromFor', '%s from'), entry.label), 'meprmf-qb__input--bound')
				);
				target.appendChild(textNode('span', 'meprmf-qb__joiner', i18n('andJoiner', 'and')));
				target.appendChild(
					valueInput(entry, row, 'v2', sprintf1(i18n('valueToFor', '%s to'), entry.label), 'meprmf-qb__input--bound')
				);
				return;
			}

			if (entry.kind === 'choice') {
				target.appendChild(choiceSelect(entry, row));
				return;
			}

			var single = unitGlyph(entry);
			if (single) {
				target.appendChild(single);
			}
			target.appendChild(
				valueInput(
					entry,
					row,
					'v1',
					sprintf1(i18n('valueFor', 'Value for %s'), entry.label),
					entry.kind === 'date' ? 'meprmf-qb__input--date' : (entry.kind === 'number' ? 'meprmf-qb__input--number' : 'meprmf-qb__input--text')
				)
			);
		}

		function buildRow(row) {
			var entry = entryByParam(row.param);
			if (!entry) {
				return null;
			}

			var wrap = el('div', 'meprmf-qb__row');
			wrap.setAttribute('role', 'group');
			wrap.setAttribute('aria-label', entry.label);
			wrap.setAttribute('data-meprmf-row-id', row.id);

			wrap.appendChild(textNode('span', 'meprmf-qb__row-field', entry.label));

			var ops = entry.ops || [];
			if (ops.length > 1) {
				var opSelect = el('select', 'meprmf-qb__op');
				opSelect.setAttribute('aria-label', sprintf1(i18n('operatorFor', 'Comparison for %s'), entry.label));
				ops.forEach(function (op) {
					opSelect.appendChild(optionNode(op.v, op.l, op.v === row.op));
				});
				opSelect.addEventListener('change', function (ev) {
					var next = String(ev.target.value || '');
					if (shapeFor(entry, next) !== shapeFor(entry, row.op)) {
						row.v1 = '';
						row.v2 = '';
					}
					row.op = next;
					buildValueControls(entry, row, valueCell);
					syncFooter();
				});
				wrap.appendChild(opSelect);
			} else {
				// A single operator (or none, as for a checkbox) is a statement, not a choice.
				wrap.appendChild(textNode('span', 'meprmf-qb__op-static', ops.length === 1 ? ops[0].l : i18n('opIs', 'is')));
			}

			var valueCell = el('span', 'meprmf-qb__row-value');
			buildValueControls(entry, row, valueCell);
			wrap.appendChild(valueCell);

			var remove = el('button', 'meprmf-qb__remove');
			remove.type = 'button';
			remove.setAttribute('aria-label', sprintf1(i18n('removeFilter', 'Remove %s filter'), entry.label));
			var glyph = textNode('span', null, GLYPH_REMOVE);
			glyph.setAttribute('aria-hidden', 'true');
			remove.appendChild(glyph);
			remove.addEventListener('click', function () {
				state.rows = state.rows.filter(function (candidate) {
					return candidate.id !== row.id;
				});
				renderRows();
				syncFooter();
				addBtn.focus();
			});
			wrap.appendChild(remove);

			return wrap;
		}

		function renderRows() {
			clear(rowsWrap);
			state.rows.forEach(function (row) {
				var node = buildRow(row);
				if (node) {
					rowsWrap.appendChild(node);
				}
			});
			var hasRows = rowsWrap.children.length > 0;
			emptyWrap.hidden = hasRows;
			if (matchWrap) {
				matchWrap.hidden = !hasRows;
			}
		}

		/* ---- match toggle ---- */

		var segments = Array.prototype.slice.call(card.querySelectorAll('[data-meprmf-match-mode]'));

		function setMatch(mode) {
			state.match = mode === 'any' ? 'any' : 'all';
			syncMatch();
			syncFooter();
		}

		segments.forEach(function (segment) {
			segment.addEventListener('click', function () {
				setMatch(segment.getAttribute('data-meprmf-match-mode'));
			});
		});

		// role="radio" carries arrow-key expectations: the arrows move focus and selection
		// together, and only the selected segment is a tab stop.
		if (matchWrap) {
			matchWrap.addEventListener('keydown', function (ev) {
				var step = 0;
				if (ev.key === 'ArrowRight' || ev.key === 'ArrowDown') {
					step = 1;
				} else if (ev.key === 'ArrowLeft' || ev.key === 'ArrowUp') {
					step = -1;
				}
				if (step === 0 || segments.length === 0) {
					return;
				}
				ev.preventDefault();
				var index = segments.indexOf(document.activeElement);
				if (index === -1) {
					index = state.match === 'any' ? 1 : 0;
				}
				var next = segments[(index + step + segments.length) % segments.length];
				setMatch(next.getAttribute('data-meprmf-match-mode'));
				next.focus();
			});
		}

		function syncMatch() {
			segments.forEach(function (segment) {
				var selected = segment.getAttribute('data-meprmf-match-mode') === state.match;
				segment.setAttribute('aria-checked', selected ? 'true' : 'false');
				segment.classList.toggle('is-selected', selected);
				segment.tabIndex = selected ? 0 : -1;
			});
		}

		/* ---- footer / badge ---- */

		function syncFooter() {
			var active = state.rows.filter(rowIsActive).length;
			if (countBadge) {
				countBadge.textContent = String(active);
				countBadge.hidden = active === 0;
			}
			if (statusEl) {
				statusEl.hidden = signature(state.rows, state.match) === state.appliedSignature;
			}
			syncChips();
		}

		/* ---- add-filter popover ---- */

		function usedParams() {
			var used = {};
			state.rows.forEach(function (row) {
				used[row.param] = true;
			});
			return used;
		}

		function renderPopoverList() {
			clear(popoverList);
			var used = usedParams();
			var query = state.query.toLowerCase();
			var groupLabels = cfg().groupLabels || {};
			var buckets = {};
			var order = [];

			catalog().forEach(function (entry) {
				if (used[entry.param]) {
					return;
				}
				if (query !== '' && String(entry.label).toLowerCase().indexOf(query) === -1) {
					return;
				}
				var group = String(entry.group || '');
				if (!buckets[group]) {
					buckets[group] = [];
					order.push(group);
				}
				buckets[group].push(entry);
			});

			// Group order follows the localized label map, so it stays stable across screens.
			var known = Object.keys(groupLabels).filter(function (group) {
				return !!buckets[group];
			});
			order.forEach(function (group) {
				if (known.indexOf(group) === -1) {
					known.push(group);
				}
			});

			if (known.length === 0) {
				popoverList.appendChild(textNode('p', 'meprmf-qb__popover-empty', i18n('noFilterMatches', 'No filters match.')));
				return;
			}

			known.forEach(function (group) {
				popoverList.appendChild(
					textNode('div', 'meprmf-qb__opt-group', groupLabels[group] || group)
				);
				buckets[group].forEach(function (entry) {
					var item = el('button', 'meprmf-qb__opt');
					item.type = 'button';
					item.setAttribute('data-meprmf-add', entry.param);
					item.appendChild(textNode('span', 'meprmf-qb__opt-label', entry.label));
					item.appendChild(textNode('span', 'meprmf-qb__opt-kind', kindLabel(entry.kind)));
					item.addEventListener('click', function () {
						addRow(entry);
					});
					popoverList.appendChild(item);
				});
			});
		}

		function addRow(entry) {
			var tokens = opTokens(entry);
			state.rows.push(makeRow(entry, tokens.length > 0 ? tokens[0] : '', '', ''));
			state.query = '';
			if (searchInput) {
				searchInput.value = '';
			}
			closePopover();
			renderRows();
			syncFooter();
			var last = rowsWrap.lastElementChild;
			var focusTarget = last ? last.querySelector('select, input') : null;
			if (focusTarget) {
				focusTarget.focus();
			}
		}

		function openPopover() {
			state.popoverOpen = true;
			popover.hidden = false;
			addBtn.setAttribute('aria-expanded', 'true');
			renderPopoverList();
			if (searchInput) {
				searchInput.focus();
			}
		}

		function closePopover() {
			if (!state.popoverOpen) {
				popover.hidden = true;
				addBtn.setAttribute('aria-expanded', 'false');
				return;
			}
			state.popoverOpen = false;
			popover.hidden = true;
			addBtn.setAttribute('aria-expanded', 'false');
		}

		addBtn.addEventListener('click', function () {
			if (state.popoverOpen) {
				closePopover();
				return;
			}
			if (!state.open) {
				setOpen(true);
			}
			openPopover();
		});

		if (searchInput) {
			searchInput.addEventListener('input', function () {
				state.query = String(searchInput.value || '').trim();
				renderPopoverList();
			});
		}

		popover.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape') {
				ev.stopPropagation();
				closePopover();
				addBtn.focus();
				return;
			}
			if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp') {
				return;
			}
			var items = Array.prototype.slice.call(popover.querySelectorAll('.meprmf-qb__opt'));
			if (items.length === 0) {
				return;
			}
			ev.preventDefault();
			var index = items.indexOf(document.activeElement);
			if (ev.key === 'ArrowDown') {
				items[index === -1 || index === items.length - 1 ? 0 : index + 1].focus();
			} else {
				items[index <= 0 ? items.length - 1 : index - 1].focus();
			}
		});

		document.addEventListener('click', function (ev) {
			if (!state.popoverOpen || popover.contains(ev.target) || addBtn.contains(ev.target)) {
				return;
			}
			closePopover();
		});

		/* ---- apply / clear ---- */

		function pendingParams() {
			var out = {};
			state.rows.forEach(function (row) {
				var entry = entryByParam(row.param);
				if (!entry) {
					return;
				}
				var writes = writesForRow(entry, row);
				Object.keys(writes).forEach(function (key) {
					out[key] = writes[key];
				});
			});
			if (state.match === 'any') {
				out[matchParam()] = 'any';
			}
			return out;
		}

		function applyFilters() {
			if (applyBtn) {
				applyBtn.disabled = true;
			}
			var u = new URL(window.location.href);
			// Only this plugin's params and the native toolbar params are managed here; page,
			// screen, orderby and MemberPress's own search keys are left exactly as they are.
			stripKnownParams(u);
			// Any change to the filter set invalidates the current offset.
			u.searchParams.delete('paged');

			var params = pendingParams();
			var native = stripInactiveTransactionDateParams(collectNativeToolbarParams());
			Object.keys(native).forEach(function (key) {
				params[key] = native[key];
			});
			Object.keys(params).forEach(function (key) {
				u.searchParams.set(key, params[key]);
			});

			window.location.assign(u.toString());
		}

		if (applyBtn) {
			applyBtn.addEventListener('click', applyFilters);
		}

		if (clearBtn) {
			clearBtn.addEventListener('click', function () {
				state.rows = [];
				state.match = 'all';
				if (viewsSelect) {
					viewsSelect.value = '';
					syncDeleteView();
				}
				setOpen(true);
				renderRows();
				syncMatch();
				syncFooter();
			});
		}

		/* ---- saved views ---- */

		function presets() {
			var list = cfg().presets;
			if (Array.isArray(list)) {
				return list;
			}
			if (list && typeof list === 'object') {
				return Object.keys(list).map(function (key) {
					return list[key];
				});
			}
			return [];
		}

		function presetById(id) {
			var target = String(id || '');
			var list = presets();
			for (var i = 0; i < list.length; i++) {
				if (list[i] && String(list[i].id) === target) {
					return list[i];
				}
			}
			return null;
		}

		function presetParamsMap(preset) {
			var known = {};
			knownKeys().forEach(function (key) {
				known[key] = true;
			});
			var map = {};
			Object.keys((preset && preset.params) || {}).forEach(function (key) {
				if (known[key]) {
					map[key] = String(preset.params[key] || '');
				}
			});
			return map;
		}

		/**
		 * The view the current URL is, if it is one. Selecting a view navigates, so nothing marks
		 * the select afterwards — match the applied filters against each saved view instead.
		 */
		function matchingPresetId(rows) {
			if (rows.length === 0) {
				return '';
			}
			var current = signature(rows, appliedMatchMode());
			var list = presets();
			for (var i = 0; i < list.length; i++) {
				if (!list[i] || !list[i].id) {
					continue;
				}
				var map = presetParamsMap(list[i]);
				if (signature(rowsFromMap(map), map[matchParam()] === 'any' ? 'any' : 'all') === current) {
					return String(list[i].id);
				}
			}
			return '';
		}

		function applyPreset(preset) {
			if (!preset || !preset.params) {
				return;
			}
			var map = presetParamsMap(preset);
			state.rows = rowsFromMap(map);
			state.match = map[matchParam()] === 'any' ? 'any' : 'all';
			renderRows();
			syncMatch();
			syncFooter();
			applyFilters();
		}

		function syncDeleteView() {
			if (deleteViewBtn) {
				deleteViewBtn.hidden = !viewsSelect || viewsSelect.value === '';
			}
		}

		if (viewsSelect) {
			viewsSelect.value = matchingPresetId(applied);
			viewsSelect.addEventListener('change', function () {
				syncDeleteView();
				var preset = presetById(viewsSelect.value);
				if (preset) {
					applyPreset(preset);
				}
			});
		}
		syncDeleteView();

		function forgetPreset(id) {
			cfg().presets = presets().filter(function (preset) {
				return preset && String(preset.id) !== String(id);
			});
			if (viewsSelect) {
				Array.prototype.slice.call(viewsSelect.options).forEach(function (option) {
					if (option.value === String(id) && option.parentNode) {
						option.parentNode.removeChild(option);
					}
				});
				viewsSelect.value = '';
			}
			card.querySelectorAll('[data-meprmf-preset-id]').forEach(function (pill) {
				if (pill.getAttribute('data-meprmf-preset-id') === String(id) && pill.parentNode) {
					pill.parentNode.removeChild(pill);
				}
			});
			syncDeleteView();
		}

		if (deleteViewBtn && viewsSelect) {
			deleteViewBtn.addEventListener('click', function () {
				var preset = presetById(viewsSelect.value);
				var conf = cfg();
				if (!preset) {
					return;
				}
				if (!conf.ajaxUrl || !conf.presetsNonce) {
					window.alert(i18n('deleteViewError', 'Could not delete the saved view. Please try again.'));
					return;
				}
				// Views are site-wide, so this is not only the current admin's list.
				if (!window.confirm(sprintf1(i18n('deleteViewConfirm', 'Delete the saved view “%s”?'), preset.name))) {
					return;
				}

				deleteViewBtn.disabled = true;

				var payload = new URLSearchParams();
				payload.set('action', 'meprmf_delete_filter_preset');
				payload.set('nonce', conf.presetsNonce);
				payload.set('screen', conf.storageId || storageNs());
				payload.set('id', String(preset.id));

				fetch(conf.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: payload.toString()
				})
					.then(function (res) {
						return res.json().then(function (data) {
							return { ok: res.ok, data: data };
						});
					})
					.then(function (result) {
						var data = result.data;
						if (!result.ok || !data || !data.success) {
							var message = (data && data.data && data.data.message)
								? data.data.message
								: i18n('deleteViewError', 'Could not delete the saved view. Please try again.');
							throw new Error(message);
						}
						forgetPreset(preset.id);
						if (data.data && Array.isArray(data.data.presets)) {
							cfg().presets = data.data.presets;
						}
					})
					.catch(function (err) {
						window.alert(err && err.message ? err.message : i18n('deleteViewError', 'Could not delete the saved view. Please try again.'));
					})
					.finally(function () {
						deleteViewBtn.disabled = false;
					});
			});
		}

		card.querySelectorAll('[data-meprmf-preset-id]').forEach(function (pill) {
			pill.addEventListener('click', function () {
				var preset = presetById(pill.getAttribute('data-meprmf-preset-id'));
				if (preset) {
					applyPreset(preset);
				}
			});
		});

		if (saveBtn) {
			saveBtn.addEventListener('click', function () {
				var params = pendingParams();
				if (Object.keys(params).length === 0) {
					window.alert(i18n('noActiveFilters', 'Apply at least one filter before saving a preset.'));
					return;
				}
				var conf = cfg();
				if (!conf.ajaxUrl || !conf.presetsNonce) {
					window.alert(i18n('saveError', 'Could not save the preset. Please try again.'));
					return;
				}
				var name = window.prompt(i18n('savePrompt', 'Preset name'), '');
				if (name === null) {
					return;
				}
				name = String(name).trim();
				if (name === '') {
					return;
				}

				saveBtn.disabled = true;

				var payload = new URLSearchParams();
				payload.set('action', 'meprmf_save_filter_preset');
				payload.set('nonce', conf.presetsNonce);
				payload.set('screen', conf.storageId || storageNs());
				payload.set('name', name);
				payload.set('params', JSON.stringify(params));

				fetch(conf.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: payload.toString()
				})
					.then(function (res) {
						return res.json().then(function (data) {
							return { ok: res.ok, data: data };
						});
					})
					.then(function (result) {
						var data = result.data;
						if (!result.ok || !data || !data.success) {
							var message = (data && data.data && data.data.message)
								? data.data.message
								: i18n('saveError', 'Could not save the preset. Please try again.');
							throw new Error(message);
						}
						var saved = data.data && data.data.preset ? data.data.preset : null;
						if (Array.isArray(data.data.presets)) {
							cfg().presets = data.data.presets;
						}
						if (saved && viewsSelect) {
							var existing = viewsSelect.querySelector('option[value="' + String(saved.id).replace(/"/g, '') + '"]');
							if (!existing) {
								viewsSelect.appendChild(optionNode(saved.id, saved.name, false));
							}
							viewsSelect.value = String(saved.id);
							syncDeleteView();
						}
					})
					.catch(function (err) {
						window.alert(err && err.message ? err.message : i18n('saveError', 'Could not save the preset. Please try again.'));
					})
					.finally(function () {
						saveBtn.disabled = false;
					});
			});
		}

		/* ---- first paint ---- */

		card.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && state.popoverOpen) {
				closePopover();
				addBtn.focus();
			}
		});

		renderRows();
		syncMatch();
		syncFooter();
		syncOpen();
		card.removeAttribute('hidden');
	}

	/**
	 * Card markup is printed in admin_footer (block markup is invalid inside MemberPress's
	 * `<p class="mepr-search-box">`). Move it above the tablenav before wiring handlers.
	 */
	function relocateCardsFromPool() {
		var pool = document.getElementById('meprmf-floating-panels-pool');
		document.querySelectorAll('[data-meprmf-panel-id]').forEach(function (anchor) {
			var id = anchor.getAttribute('data-meprmf-panel-id');
			if (!id) {
				return;
			}
			var card = document.getElementById(id);
			// A second anchor (bottom tablenav) must not drag the card down the page.
			if (!card || card.getAttribute('data-meprmf-mounted') === '1') {
				return;
			}
			card.setAttribute('data-meprmf-mounted', '1');
			var target = anchor.closest('.tablenav') || anchor.closest('p.mepr-search-box') || anchor;
			if (target.parentNode) {
				target.parentNode.insertBefore(card, target);
			}
		});
		if (pool && pool.parentNode) {
			pool.parentNode.removeChild(pool);
		}
	}

	function boot() {
		canonicalizeLegacyAccessParam();
		relocateCardsFromPool();
		document.querySelectorAll('.meprmf-qb').forEach(function (card) {
			initCard(card);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
