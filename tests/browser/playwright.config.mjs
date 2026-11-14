// The checkout's card form in a browser: specs/checkout.spec.mjs, against a WordPress site with
// SEOCart and this plugin active and the Stripe gateway in test mode, which WP_BASE_URL names.
// bin/ci/stripe-test-mode.sh installs that site on the runner and runs this (`site`, `checkout`).
//
// No trace, video or screenshot is taken, and nothing is uploaded: Stripe's own windows carry the
// payment's client secret and the account's publishable key in their addresses. One worker runs
// the tests in order: they share the site's product and the Stripe test account.

import { defineConfig } from '@playwright/test';

export default defineConfig({
	testDir: './specs',
	// SEOCart's payment fixture is TypeScript in the SEOCart checkout beside this one, and imports
	// @playwright/test from there; this maps that import to this package's copy, the one running
	// the tests, as Playwright refuses to load two.
	tsconfig: './tsconfig.json',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	forbidOnly: Boolean(process.env.CI),
	// A bank's challenge and Stripe's answers each take seconds; a test confirms up to three cards.
	timeout: 240_000,
	expect: { timeout: 30_000 },
	reporter: [['list']],
	outputDir: 'test-results',
	use: {
		baseURL: process.env.WP_BASE_URL,
		trace: 'off',
		video: 'off',
		screenshot: 'off',
	},
	projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
