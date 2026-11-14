// Presses a control of Stripe's 3-D Secure test page, for the browser scripts and tests: the page
// Stripe sends a shopper to (complete-3ds.mjs), and the same page in Stripe's window on the
// checkout (specs/checkout.spec.mjs). Stripe puts the page's controls in a frame, so every frame is
// looked in.

// The controls of Stripe's test page, by what the test asks for: a button's accessible name, from
// its start, so no other button of the page is ever pressed. Stripe does not document the page's
// markup, so these are the one place to change when it changes.
export const CONTROLS = {
	authorize: /^\s*(complete|authori[sz]e)\b/i,
	fail: /^\s*fail\b/i,
};

// How long a press may wait for the control to take the click.
const CLICK_MS = 30_000;

// How long a frame may take to finish its requests before a press, at most.
const SETTLE_MS = 10_000;

// Presses the first button of a name in any frame of the page, once its frame has loaded and its
// requests have settled, and answers its text, or null when none appeared before the deadline (a
// time in milliseconds, as Date.now() gives). A button pressed before its page's scripts ran would
// do nothing.
export async function press(page, name, until) {
	while (Date.now() < until) {
		for (const frame of page.frames()) {
			const button = frame.getByRole('button', { name }).first();

			if ((await button.count().catch(() => 0)) > 0) {
				await frame.waitForLoadState('load', { timeout: CLICK_MS });
				await frame.waitForLoadState('networkidle', { timeout: SETTLE_MS }).catch(() => {});

				const text = ((await button.textContent().catch(() => '')) ?? '').trim();

				await button.click({ timeout: CLICK_MS });
				return text || 'pressed';
			}
		}

		await new Promise((resolve) => setTimeout(resolve, 500));
	}

	return null;
}
