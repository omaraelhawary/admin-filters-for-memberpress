/**
 * MemberPress admin lists — bulk actions on the currently filtered set.
 *
 * The button lives in the filter card's footer. Every request is POSTed to admin-ajax.php with
 * the page's own query string appended, because the server reads the active filters (and the
 * ?page= slug that identifies the screen) out of $_GET, not out of the POST body.
 *
 * Nothing is written until a dry run has reported the match count for the key and value on
 * screen, and any value the server echoes back is written with textContent.
 */
(function () {
	'use strict';

	function cfg() {
		return window.meprmfBulkActions || {};
	}

	function i18n(key, fallback) {
		var strings = cfg().i18n || {};
		return typeof strings[key] === 'string' && strings[key] !== '' ? strings[key] : fallback;
	}

	function format(template, values) {
		return String(template).replace(/%(\d)\$[ds]|%[ds]/g, function (match, index) {
			return String(index ? values[Number(index) - 1] : values.shift());
		});
	}

	function el(tag, className) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		return node;
	}

	function labelled(text, input) {
		var label = el('label', 'meprmf-bulk__label');
		var span = el('span');
		span.textContent = text;
		label.appendChild(span);
		label.appendChild(input);
		return label;
	}

	function request(fields, queryString) {
		var conf = cfg();
		var payload = new URLSearchParams();
		payload.set('action', 'meprmf_bulk_set_meta');
		payload.set('nonce', conf.nonce || '');
		Object.keys(fields).forEach(function (name) {
			payload.set(name, fields[name]);
		});

		// The filter set travels in the query string: Meprmf_Util::get_request_value() and
		// Meprmf_Screen::detect() both read $_GET only. Live batches reuse the query captured
		// at preview so writes stay bound to the set the admin agreed to.
		var query = typeof queryString === 'string' ? queryString : window.location.search;
		return fetch(conf.ajaxUrl + query, {
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
						: i18n('error', 'Could not run the bulk action. Please try again.');
					throw new Error(message);
				}
				return data.data || {};
			});
	}

	function buildDialog() {
		var dialog = el('dialog', 'meprmf-bulk');

		var heading = el('h2');
		heading.textContent = i18n('title', 'Bulk actions on the filtered set');
		dialog.appendChild(heading);

		var intro = el('p');
		intro.textContent = i18n('action', 'Set user meta on every member in the filtered list.');
		dialog.appendChild(intro);

		var keyInput = el('input');
		keyInput.type = 'text';
		keyInput.className = 'regular-text';
		dialog.appendChild(labelled(i18n('metaKey', 'Meta key'), keyInput));

		var valueInput = el('input');
		valueInput.type = 'text';
		valueInput.className = 'regular-text';
		dialog.appendChild(labelled(i18n('metaValue', 'Meta value'), valueInput));

		var status = el('p', 'meprmf-bulk__status');
		status.setAttribute('role', 'status');
		dialog.appendChild(status);

		var ids = el('p', 'meprmf-bulk__ids');
		dialog.appendChild(ids);

		var footer = el('p', 'meprmf-bulk__actions');

		var previewBtn = el('button', 'button');
		previewBtn.type = 'button';
		previewBtn.textContent = i18n('preview', 'Preview');
		footer.appendChild(previewBtn);

		var runBtn = el('button', 'button button-primary');
		runBtn.type = 'button';
		runBtn.textContent = i18n('run', 'Run');
		runBtn.disabled = true;
		footer.appendChild(runBtn);

		var closeBtn = el('button', 'button-link');
		closeBtn.type = 'button';
		closeBtn.textContent = i18n('cancel', 'Cancel');
		footer.appendChild(closeBtn);

		dialog.appendChild(footer);
		document.body.appendChild(dialog);

		// Run stays locked to the exact key, value, and filter query that were previewed.
		var previewed = '';
		var previewedMembers = 0;
		var previewedQuery = '';
		var runToken = '';

		function pair() {
			return keyInput.value + '\n' + valueInput.value;
		}

		function previewStillValid() {
			return previewed !== ''
				&& previewed === pair()
				&& previewedQuery !== ''
				&& previewedQuery === window.location.search;
		}

		function syncRun() {
			runBtn.disabled = !previewStillValid() || runToken === '';
		}

		function busy(on) {
			previewBtn.disabled = on;
			closeBtn.disabled = on;
			runBtn.disabled = on || !previewStillValid() || runToken === '';
		}

		function invalidatePreview() {
			previewed = '';
			previewedMembers = 0;
			previewedQuery = '';
			runToken = '';
		}

		function fail(err) {
			ids.textContent = '';
			status.textContent = (err && err.message)
				? err.message
				: i18n('error', 'Could not run the bulk action. Please try again.');
		}

		keyInput.addEventListener('input', syncRun);
		valueInput.addEventListener('input', syncRun);

		previewBtn.addEventListener('click', function () {
			var asked = pair();
			invalidatePreview();
			ids.textContent = '';
			status.textContent = i18n('working', 'Working…');
			busy(true);

			request({
				meta_key: keyInput.value,
				meta_value: valueInput.value,
				dry_run: '1'
			})
				.then(function (data) {
					var preview = Array.isArray(data.preview) ? data.preview : [];
					status.textContent = format(i18n('matchCount', '%1$d matching rows / %2$d unique members'), [data.rows, data.members])
						+ ' ' + format(i18n('summary', 'Will set %1$s to %2$s on every one of those members.'), [data.key, data.value]);
					ids.textContent = format(i18n('previewIds', 'Dry run, nothing written. First %d member ids:'), [preview.length])
						+ ' ' + preview.join(', ');
					previewed = asked;
					previewedMembers = Number(data.members) || 0;
					previewedQuery = window.location.search;
					runToken = typeof data.runToken === 'string' ? data.runToken : '';
				})
				.catch(fail)
				.finally(function () {
					busy(false);
				});
		});

		function runLiveBatch(batchIndex, totals) {
			return request({
				meta_key: keyInput.value,
				meta_value: valueInput.value,
				run_token: runToken,
				batch_size: String(cfg().batchSize || ''),
				batch_index: String(batchIndex)
			}, previewedQuery).then(function (data) {
				var totalBatches = Number(data.batches) || 0;
				var memberTotal = Number(data.members) || previewedMembers;
				var cumulativeSucceeded = (totals.succeeded || 0) + (Number(data.succeeded) || 0);
				var batchNumber = Math.min(batchIndex + 1, Math.max(totalBatches, 1));

				status.textContent = format(
					i18n('batchProgress', 'Batch %1$d of %2$d: %3$d of %4$d members written.'),
					[batchNumber, Math.max(totalBatches, 1), cumulativeSucceeded, memberTotal]
				);

				if (data.failedAt !== null && typeof data.failedAt !== 'undefined') {
					status.textContent = format(
						i18n('stopped', 'Stopped: %1$d members written, then the write for member %2$d failed.'),
						[cumulativeSucceeded, data.failedAt]
					);
					invalidatePreview();
					closeBtn.textContent = i18n('close', 'Close');
					return;
				}

				if (totalBatches > 0 && batchIndex + 1 < totalBatches) {
					return runLiveBatch(batchIndex + 1, { succeeded: cumulativeSucceeded });
				}

				status.textContent = format(
					i18n('done', 'Done: %1$d of %2$d members written.'),
					[cumulativeSucceeded, memberTotal]
				);
				invalidatePreview();
				closeBtn.textContent = i18n('close', 'Close');
			});
		}

		runBtn.addEventListener('click', function () {
			if (!previewStillValid() || runToken === '') {
				status.textContent = i18n('previewFirst', 'Preview the set before running.');
				return;
			}
			ids.textContent = '';
			status.textContent = format(i18n('running', 'Running on %d members…'), [previewedMembers]);
			busy(true);

			runLiveBatch(0, { succeeded: 0 })
				.catch(fail)
				.finally(function () {
					busy(false);
				});
		});

		closeBtn.addEventListener('click', function () {
			dialog.close();
		});

		return {
			open: function () {
				invalidatePreview();
				status.textContent = '';
				ids.textContent = '';
				closeBtn.textContent = i18n('cancel', 'Cancel');
				syncRun();
				dialog.showModal();
				keyInput.focus();
			}
		};
	}

	function boot() {
		var buttons = document.querySelectorAll('[data-meprmf-bulk]');
		if (!buttons.length || !cfg().ajaxUrl || typeof HTMLDialogElement === 'undefined') {
			return;
		}

		var modal = null;
		buttons.forEach(function (button) {
			button.addEventListener('click', function () {
				if (!modal) {
					modal = buildDialog();
				}
				modal.open();
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
