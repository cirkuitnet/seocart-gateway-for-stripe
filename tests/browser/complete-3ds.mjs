// Completes Stripe's 3-D Secure test page in a browser, for the Stripe test mode workflow's live tests.
//
// Reads one JSON line on its standard input, never an argument a process list would show:
//   { "url": the page's address, "returnOrigin": the store's origin Stripe sends the shopper back to,
//     "control": "authorize" or "fail", "browser": a browser to launch, or "" for Playwright's own }
// Opens the page, presses the control, answers every request to the store's origin itself (no site
// is needed), and prints exactly one word: succeeded, failed, no-control or timeout; any other error
// is reduced to its name, as a message of Playwright's can quote the page's address, which holds the
// payment's client secret and the account's publishable key. Nothing else is printed, and no trace,
// video or screenshot is taken.

import { pathToFileURL } from 'node:url';

// The controls of Stripe's test page, by what the test asks for: a button's accessible name, looked
// for in every frame, as the page puts its challenge in a frame. Stripe does not document the page's
// markup, so these are the one place to change when it changes.
export const CONTROLS = {
	authorize: /^\s*(complete|authori[sz]e)\b/i,
	fail: /^\s*fail\b/i,
};

// How long the page may take to load, the control to appear, and Stripe to send the shopper back.
const PAGE_MS = 60_000;
const CONTROL_MS = 30_000;
const RETURN_MS = 30_000;

// Every word the script can print besides an error's name.
export const WORDS = ['succeeded', 'failed', 'no-control', 'timeout'];

// Reduces an error to one word: `timeout` for Playwright's timeouts, otherwise the error's name.
export function outcomeWord(error) {
	const name = error && typeof error.name === 'string' ? error.name : '';

	if (name === 'TimeoutError') {
		return 'timeout';
	}

	return /^[A-Za-z]{1,40}$/.test(name) ? name : 'Error';
}

// Reads what the store's return address says of the payment: Stripe adds `redirect_status` when it
// sends the shopper back; without it, the control that was pressed says what was asked for.
export function wordFromReturn(returned, control) {
	const status = returned.searchParams.get('redirect_status');

	if (status === 'succeeded' || status === 'failed') {
		return status;
	}

	return control === 'authorize' ? 'succeeded' : 'failed';
}

// Prints an outcome, or an error reduced to its word, and nothing else.
export function report(outcome, write = (text) => process.stdout.write(text)) {
	write(`${typeof outcome === 'string' && WORDS.includes(outcome) ? outcome : outcomeWord(outcome)}\n`);
}

// Presses the first button of a name in any frame of the page; false when none appeared in time.
async function press(page, name, until) {
	while (Date.now() < until) {
		for (const frame of page.frames()) {
			const button = frame.getByRole('button', { name });

			if ((await button.count().catch(() => 0)) > 0) {
				await button.first().click({ timeout: CONTROL_MS });
				return true;
			}
		}

		await new Promise((resolve) => setTimeout(resolve, 500));
	}

	return false;
}

// Opens the page, presses the control and waits for Stripe to send the shopper back to the store.
async function complete({ url, returnOrigin, control, browser }) {
	const { chromium } = await import('playwright');
	const launched = await chromium.launch({ headless: true, ...(browser ? { executablePath: browser } : {}) });

	try {
		const context = await launched.newContext();
		const page = await context.newPage();
		let returned = null;

		await context.route(
			(address) => address.origin === returnOrigin,
			async (route) => {
				returned = new URL(route.request().url());
				await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Back at the store</title>' });
			}
		);

		await page.goto(url, { waitUntil: 'load', timeout: PAGE_MS });

		if (!(await press(page, CONTROLS[control], Date.now() + CONTROL_MS))) {
			return 'no-control';
		}

		for (const until = Date.now() + RETURN_MS; returned === null && Date.now() < until; ) {
			await new Promise((resolve) => setTimeout(resolve, 250));
		}

		return returned === null ? 'timeout' : wordFromReturn(returned, control);
	} finally {
		await launched.close();
	}
}

// Reads the one JSON line of the standard input.
async function readInput() {
	let text = '';

	for await (const chunk of process.stdin) {
		text += chunk;
	}

	const input = JSON.parse(text.split('\n')[0]);

	if (typeof input.url !== 'string' || typeof input.returnOrigin !== 'string' || !(input.control in CONTROLS)) {
		throw new TypeError('The input names no page, return origin or known control.');
	}

	return input;
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
	try {
		report(await complete(await readInput()));
	} catch (error) {
		report(error);
	}
}
