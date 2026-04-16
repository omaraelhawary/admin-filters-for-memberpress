/**
 * Settings: show choices field only for "Single choice", subtle mute when N/A.
 */
(function () {
	'use strict';

	function closest(el, selector) {
		while (el && el.nodeType === 1) {
			if (el.matches(selector)) {
				return el;
			}
			el = el.parentElement;
		}
		return null;
	}

	function syncRow(card) {
		var sel = card.querySelector(
			'select[name*="[filter_type]"]'
		);
		var optsWrap = card.querySelector('.meprmf-options-field');
		if (!sel || !optsWrap) {
			return;
		}
		var v = sel.value;
		var needsChoices = v === 'select';
		optsWrap.classList.toggle('is-muted', !needsChoices);
		var ta = optsWrap.querySelector('textarea');
		if (ta) {
			if (needsChoices) {
				ta.removeAttribute('readonly');
			} else {
				ta.setAttribute('readonly', 'readonly');
			}
		}
	}

	function onChange(e) {
		if (!e.target || !e.target.matches('select[name*="[filter_type]"]')) {
			return;
		}
		var card = closest(e.target, '.meprmf-filter-card');
		if (card) {
			syncRow(card);
		}
	}

	document.addEventListener('change', onChange, false);

	document.addEventListener('DOMContentLoaded', function () {
		var cards = document.querySelectorAll('.meprmf-filter-card');
		for (var i = 0; i < cards.length; i++) {
			syncRow(cards[i]);
		}
	});
})();
