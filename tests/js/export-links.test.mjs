/**
 * Checks the export-link URL merge: MemberPress's export link must inherit the
 * current screen's filter params without ever losing its own action or nonce.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { mergeExportUrl } = require('../../assets/meprmf-export-links.js');

const EXPORT = '/wp-admin/admin-ajax.php?action=mepr_members&all=1&mepr_members_nonce=abc123';

test('page and filter params are merged in', () => {
	const out = mergeExportUrl(EXPORT, '?page=memberpress-members&mpm_access=active&mpf_country=DE');
	assert.match(out, /page=memberpress-members/);
	assert.match(out, /mpm_access=active/);
	assert.match(out, /mpf_country=DE/);
});

test('the export action and nonce survive', () => {
	const out = mergeExportUrl(EXPORT, '?page=memberpress-members&action=something_else');
	assert.match(out, /action=mepr_members/);
	assert.match(out, /mepr_members_nonce=abc123/);
	assert.doesNotMatch(out, /action=something_else/);
});

test('all is not overwritten and paged is dropped', () => {
	const out = mergeExportUrl(EXPORT, '?page=memberpress-members&all=0&paged=3');
	assert.match(out, /all=1/);
	assert.doesNotMatch(out, /all=0/);
	assert.doesNotMatch(out, /paged=3/);
});

test('a param already on the export link is not duplicated by the page URL', () => {
	// Not in the skip list, present on both sides: the export link's own value wins
	// and the key must appear exactly once.
	const out = mergeExportUrl(EXPORT + '&membership=7', '?page=memberpress-members&membership=9');
	const values = new URLSearchParams(out.split('?')[1]).getAll('membership');
	assert.deepEqual(values, [ '7' ]);
});

test('operator params come along', () => {
	const out = mergeExportUrl(EXPORT, '?page=memberpress-members&mpf_country=DE&mpf_country__op=is_not');
	assert.match(out, /mpf_country__op=is_not/);
});

test('empty values are not carried over', () => {
	const out = mergeExportUrl(EXPORT, '?page=memberpress-members&mpf_city=');
	assert.doesNotMatch(out, /mpf_city/);
});

test('no query on the current screen leaves the link untouched', () => {
	assert.equal(mergeExportUrl(EXPORT, ''), EXPORT);
});

test('values needing encoding survive a round trip', () => {
	const out = mergeExportUrl(EXPORT, '?page=memberpress-members&mpf_city=' + encodeURIComponent('São Paulo & Co'));
	const parsed = new URLSearchParams(out.split('?')[1]);
	assert.equal(parsed.get('mpf_city'), 'São Paulo & Co');
});

test('a hash fragment is preserved at the end', () => {
	const out = mergeExportUrl(EXPORT + '#top', '?page=memberpress-members');
	assert.ok(out.endsWith('#top'));
	assert.match(out, /page=memberpress-members/);
});
