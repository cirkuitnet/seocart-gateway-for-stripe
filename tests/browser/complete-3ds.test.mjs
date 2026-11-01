// Tests that the 3-D Secure script prints one word and never what an error quotes.
//
// Planted violation, shown red and removed: report() printing an error's message instead of its
// word: the address with the made-up client secret is printed.

import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { test } from 'node:test';

import { CONTROLS, outcomeWord, report, wordFromReturn } from './complete-3ds.mjs';

// Prints through report() into a string.
function printed(outcome) {
	let text = '';

	report(outcome, (chunk) => {
		text += chunk;
	});

	return text;
}

test('an error quoting the page address with a client secret prints its name only', () => {
	const secret = `pi_${randomBytes(12).toString('hex')}_${'secret'}_${randomBytes(12).toString('hex')}`;
	const error = new Error(`page.goto: net::ERR_FAILED at https://hooks.stripe.com/3d_secure_2/hosted?payment_intent_client_secret=${secret}`);

	assert.equal(printed(error), 'Error\n');

	const named = new TypeError(`Cannot read the frame of https://hooks.stripe.com/?client_secret=${secret}`);

	assert.equal(printed(named), 'TypeError\n');
	assert.ok(!printed(named).includes(secret));
});

test('a timeout prints timeout, and an error without a plain name prints Error', () => {
	const timeout = new Error('Timeout 30000ms exceeded.');

	timeout.name = 'TimeoutError';
	assert.equal(outcomeWord(timeout), 'timeout');

	const odd = new Error('odd');

	odd.name = 'Name with https://example.com/?q=1';
	assert.equal(printed(odd), 'Error\n');
	assert.equal(printed(undefined), 'Error\n');
});

test('each outcome prints as itself', () => {
	for (const word of ['succeeded', 'failed', 'no-control', 'timeout']) {
		assert.equal(printed(word), `${word}\n`);
	}

	assert.equal(printed('https://example.com/?client_secret=x'), 'Error\n');
});

test('the return address says the outcome, or the control pressed does', () => {
	assert.equal(wordFromReturn(new URL('https://example.org/?payment_intent=pi_1&redirect_status=failed'), 'authorize'), 'failed');
	assert.equal(wordFromReturn(new URL('https://example.org/?redirect_status=succeeded'), 'fail'), 'succeeded');
	assert.equal(wordFromReturn(new URL('https://example.org/?payment_intent=pi_1'), 'authorize'), 'succeeded');
	assert.equal(wordFromReturn(new URL('https://example.org/?payment_intent=pi_1'), 'fail'), 'failed');
});

test('the controls match the names Stripe has given its test page', () => {
	for (const name of ['Complete authentication', 'COMPLETE', 'Authorize Test Payment']) {
		assert.match(name, CONTROLS.authorize);
		assert.doesNotMatch(name, CONTROLS.fail);
	}

	for (const name of ['Fail authentication', 'FAIL', 'Fail Test Payment']) {
		assert.match(name, CONTROLS.fail);
		assert.doesNotMatch(name, CONTROLS.authorize);
	}
});
