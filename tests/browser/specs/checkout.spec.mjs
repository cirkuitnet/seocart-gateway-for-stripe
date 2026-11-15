// The checkout's card form in a browser: SEOCart's payment host with this plugin's island, Stripe's
// Payment Element, on SEOCart's payment fixture page (its tests/E2E/support/payments-page.ts, in the
// SEOCart checkout beside this one), against a site whose Stripe gateway is in test mode.
//
// The site is WP_BASE_URL, its administrator WP_USERNAME and WP_PASSWORD, who creates the one
// product the tests buy and trashes it afterwards. The page's configuration takes the Stripe
// account's publishable key and country from the test mode's settings file SEOCART_TAPE_SETTINGS
// names, read by PHP, which answers those two and never the secret key. The site must trust the
// runner's own address as a proxy (SEOCART_TRUSTED_PROXIES): each test is then a client of its own,
// by a fresh address in X-Forwarded-For, since the store allows one client five placements and
// resumes an hour.
//
// Every test is a guest's, whose cart is built through the page's own request context; records
// every request the page sends; and holds the page to the host's contract: only the host's two
// writes reach the Store API, no secret or card number is in a Store API request or the site's
// addresses, and the tab keeps nothing secret. Only Stripe's published test cards are typed, into
// Stripe's own fields. No trace, video or screenshot is taken. A test watches Stripe.js, through
// the `Stripe` function the page is given, for the Elements the island makes and destroys.
//
// The store's answer decides every payment: the island reports what Stripe said of the card, and
// the order is the one the host hands over after the resume, read back with its key.
//
// The cases:
//
// 1. Stripe.js is loaded only once Stripe is selected, and the Payment Element is shown inside the
//    Stripe island, card fields only: no postal code, as the checkout gives the billing address;
// 2. a card the bank challenges (4000 0000 0000 3220): Stripe's challenge appears on the page, its
//    Complete control is pressed, and the resume approves; the order is processing, its payment
//    authorized;
// 3. the same card with the challenge's Fail pressed: the resume declines, the island shows the
//    decline, the cart is open again; another card (4242 4242 4242 4242) is then approved;
// 4. a declined card (4000 0000 0000 0002): the resume declines, the island shows it; another card
//    is then approved;
// 5. Place pressed twice at once places once;
// 6. the page reloaded during the challenge: the host resumes on loading and waits; the card typed
//    again is placed under the same key, answered the same payment, challenged again and approved;
// 7. a change of the amount while the form is shown: the island is mounted again, the first
//    Element destroyed, a new one made for the new amount, which the placement then carries.

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { expect, request, test } from '@playwright/test';

import {
	expectNoSecretInUrls,
	expectNothingSecretKept,
	expectOnlyHostWrites,
	expectRemountOn,
	forwardClientAddress,
	hostEvents,
	hostState,
	placedOrders,
	restUrlOf,
	servePaymentsPage,
	storeRequests,
	waitForHost,
} from '../../../../seocart/tests/E2E/support/payments-page.ts';
import { CONTROLS, press } from '../press.mjs';

// Stripe's published test cards (https://docs.stripe.com/testing), typed with any future expiry
// and any CVC: one the bank always challenges, one declined, one approved without a challenge.
const CARDS = {
	challenged: '4000000000003220',
	declined: '4000000000000002',
	approved: '4242424242424242',
};

// The island's script, as it ships.
const ISLAND = readFileSync(new URL('../../../assets/island.js', import.meta.url), 'utf8');

// The Store API's namespace.
const STORE = '/seocart/store/v1';

// The argument SEOCart's return addresses carry the payment's uuid in.
const RETURN_ARG = 'seocart_payment';

// The product's price, in minor units.
const PRICE = 1999;

// How long Stripe and a bank's challenge may take to answer.
const STRIPE_MS = 90_000;

// The billing address the checkout holds and the host gives the island at each call.
const BILLING = {
	country: 'US',
	first_name: 'Ada',
	last_name: 'Lovelace',
	line1: '1 Main Street',
	city: 'Springfield',
	region: 'IL',
	postcode: '62701',
	email: 'ada@example.com',
};

// The address the running test's requests to the site come from; set before each test.
let client = '';

// What the running test's page wrote to its console, printed when the test fails.
let consoleLines = [];

// Returns a line with every handle of Stripe's and key it may quote replaced.
function redacted(line) {
	return line.replace(/\S*_secret_\S*/g, '<handle>').replace(/\b(pk|sk|rk)_(test|live)_\w+/g, '<key>');
}

// Returns the site's home URL, with its trailing slash.
function home() {
	return String(process.env.WP_BASE_URL ?? '').replace(/\/?$/, '/');
}

// Returns the URL of a Store API route.
function storeUrl(route) {
	return restUrlOf(home(), `${STORE}${route}`);
}

// Returns the headers of the running test's Store API requests.
function storeHeaders() {
	return { 'X-SEOCart-Store': '1', 'X-Forwarded-For': client };
}

// Returns the Stripe account's publishable key and country from the settings file. PHP reads the
// file and answers those two settings only: the secret key never reaches this process.
function publicSettings() {
	const code =
		'$s = json_decode( (string) file_get_contents( $argv[1] ), true );' +
		'echo json_encode( array( "publishable_key" => (string) ( $s["publishable_key"] ?? "" ), "account_country" => (string) ( $s["account_country"] ?? "" ) ) );';

	return JSON.parse(execFileSync('php', ['-r', code, process.env.SEOCART_TAPE_SETTINGS ?? ''], { encoding: 'utf8' }));
}

// The Stripe method, as a checkout's payment configuration lists it.
function stripeMethod() {
	return {
		id: 'stripe',
		label: 'Card',
		mode: 'test',
		island: true,
		settings: publicSettings(),
		currencies: ['USD', 'GBP', 'EUR'],
		instructions: null,
		paysOnDelivery: false,
	};
}

// A method paid outside any provider, listed first in the first case.
const BANK = {
	id: 'bank_transfer',
	label: 'Bank transfer',
	mode: 'test',
	island: false,
	settings: {},
	currencies: ['USD', 'GBP', 'EUR'],
	instructions: 'Pay by transfer, quoting your order number.',
	paysOnDelivery: false,
};

// Signs the administrator in, in a request context of its own, and returns it with the headers of
// its REST requests.
async function administrator() {
	const admin = await request.newContext({ baseURL: home(), ignoreHTTPSErrors: true });

	await admin.get('wp-login.php');

	const signedIn = await admin.post('wp-login.php', {
		form: { log: process.env.WP_USERNAME ?? '', pwd: process.env.WP_PASSWORD ?? '', 'wp-submit': 'Log In', testcookie: '1' },
		maxRedirects: 0,
	});

	expect(signedIn.status(), 'The administrator signs in.').toBe(302);

	const nonce = (await (await admin.get('wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();

	return { admin, headers: { 'X-WP-Nonce': nonce } };
}

// Returns the guest's cart as the Store API answers it.
async function readCart(api) {
	return (await api.get(storeUrl('/cart'), { headers: storeHeaders() })).json();
}

// Adds units of the product to the cart.
async function addLine(api, variantId, quantity) {
	const cart = await readCart(api);
	const added = await api.post(storeUrl('/cart/lines'), {
		headers: storeHeaders(),
		data: { lines: [{ variant_id: variantId, quantity }], ...(cart.version > 0 ? { cart_version: cart.version } : {}) },
	});

	expect(added.status(), 'The line is added.').toBe(200);
}

// Builds a guest's cart: one unit of the product, and a checkout paid with Stripe.
async function readyCart(api, variantId) {
	await addLine(api, variantId, 1);

	const { email, ...address } = BILLING;
	const written = await api.put(storeUrl('/checkout'), {
		headers: storeHeaders(),
		data: { cart_version: (await readCart(api)).version, billing_address: { ...address, email }, shipping_address: address, payment_method_key: 'stripe' },
	});

	expect(written.status(), 'The checkout is written.').toBe(200);
}

// Watches Stripe.js on every page the test loads, on `window.__stripeSeen`: each Elements group
// the page makes, with its amount; each Element made and destroyed; and what each confirmation came
// to, by the error's type and code alone. Stripe's objects take no new members, so the `Stripe`
// function Stripe.js gives the page, and what it makes, are handed out behind proxies that call the
// objects themselves; a confirmation is given the Elements group itself.
function watchStripe() {
	const seen = (window.__stripeSeen = { watched: false, elements: [], created: 0, destroyed: 0, confirmations: [] });
	const objects = new WeakMap();
	const watched = (object, members) => {
		const proxy = new Proxy(object, {
			get(target, name) {
				if (Object.prototype.hasOwnProperty.call(members, name)) {
					return members[name];
				}

				const value = Reflect.get(target, name, target);

				return typeof value === 'function' ? value.bind(target) : value;
			},
		});

		objects.set(proxy, object);

		return proxy;
	};
	let given;

	Object.defineProperty(window, 'Stripe', {
		configurable: true,
		enumerable: true,
		get: () => given,
		set(Stripe) {
			given =
				typeof Stripe !== 'function'
					? Stripe
					: new Proxy(Stripe, {
							apply(target, self, args) {
								const stripe = Reflect.apply(target, self, args);

								seen.watched = true;

								return watched(stripe, {
									elements(options) {
										const group = stripe.elements(options);

										seen.elements.push({ mode: options?.mode, amount: options?.amount, currency: options?.currency, captureMethod: options?.captureMethod });

										return watched(group, {
											create(...created) {
												const element = group.create(...created);

												seen.created++;

												return watched(element, {
													destroy(...destroyed) {
														seen.destroyed++;
														return element.destroy(...destroyed);
													},
												});
											},
										});
									},
									async confirmPayment(params) {
										const confirmation = { answered: false, error: null, status: null };

										seen.confirmations.push(confirmation);

										let result;

										try {
											result = await stripe.confirmPayment({ ...params, elements: objects.get(params.elements) ?? params.elements });
										} catch (thrown) {
											confirmation.thrown = String(thrown?.name ?? 'Error');
											throw thrown;
										}

										Object.assign(confirmation, {
											answered: true,
											error: result.error ? { type: result.error.type ?? null, code: result.error.code ?? null } : null,
											status: result.paymentIntent?.status ?? null,
										});

										return result;
									},
								});
							},
						});
		},
	});
}

// Returns what the page saw of Stripe.js.
function stripeSeen(page) {
	return page.evaluate(() => window.__stripeSeen);
}

// Hands the host the cart as the Store API has it now, with the billing address, as the checkout does.
async function updateHost(page) {
	await page.evaluate(async (billing) => {
		const state = window.__seocartPayments;

		state.host.update({ ...(await state.readCart()), billingCountry: billing.country, billing });
	}, BILLING);
}

// Serves the fixture page with the island and the methods given, opens it, and gives the host the
// billing address.
async function openCheckout(page, pluginBasePath, methods) {
	const address = await servePaymentsPage(page, {
		baseURL: home(),
		pluginBasePath,
		config: { contract: '0.1.0', restRoot: `${home()}?rest_route=/`, returnQueryArg: RETURN_ARG, locale: 'en_US', methods },
		islands: [{ source: ISLAND, at: 'before' }],
	});

	await page.goto(address);
	await waitForHost(page);
	await updateHost(page);

	return address;
}

// Returns the Payment Element's frame inside the Stripe island.
function cardFrame(page) {
	return page.locator('.seocart-island[data-gateway="stripe"] iframe').first().contentFrame();
}

// Types a card into the Payment Element: its number, an expiry and a CVC, and nothing else, as the
// form asks for nothing else.
async function typeCard(page, number) {
	const frame = cardFrame(page);

	await frame.locator('input[name="number"]').fill(number);
	await frame.locator('input[name="expiry"]').fill('12 / 34');
	await frame.locator('input[name="cvc"]').fill('123');
	await expect(frame.locator('input[name="postalCode"], select[name="country"]'), 'The form asks for no billing details.').toHaveCount(0);
}

// Presses the page's Place button.
async function placeOrder(page) {
	await page.locator('#seocart-place').click();
}

// Tells whether a frame of the page is Stripe's 3-D Secure challenge.
function challengeShown(page) {
	return page.frames().some((frame) => {
		try {
			const { hostname, pathname } = new URL(frame.url());

			return hostname === 'hooks.stripe.com' || pathname.includes('three-ds-2-challenge');
		} catch {
			return false;
		}
	});
}

// Names the page's frames by origin and first path segment, for a failure's message.
function frameNames(page) {
	return page
		.frames()
		.map((frame) => {
			try {
				const { origin, pathname } = new URL(frame.url());

				return `${origin}/${pathname.split('/')[1] ?? ''}`;
			} catch {
				return '(no address)';
			}
		})
		.join(', ');
}

// Waits until Stripe's challenge has closed, or the time given has passed; tells whether it closed.
async function challengeClosed(page, ms) {
	for (const until = Date.now() + ms; Date.now() < until; ) {
		if (!challengeShown(page)) {
			return true;
		}

		await page.waitForTimeout(500);
	}

	return !challengeShown(page);
}

// Waits for Stripe's challenge on the page and presses a control of it, again while the challenge
// stays open (a press can land before the challenge's page is ready for it), three times at most;
// returns what was pressed and how many times.
async function answerChallenge(page, control) {
	await expect.poll(() => challengeShown(page), { timeout: STRIPE_MS, message: 'Stripe\'s challenge appears on the page.' }).toBe(true);

	let pressed = null;
	let presses = 0;

	do {
		pressed = await press(page, CONTROLS[control], Date.now() + STRIPE_MS);
		presses++;
		expect(pressed, `The challenge's ${control} control is pressed; the page's frames: ${frameNames(page)}`).toMatch(CONTROLS[control]);
	} while (!(await challengeClosed(page, 20_000)) && presses < 3);

	expect(challengeShown(page), `The challenge closes once answered, after ${presses} presses.`).toBe(false);
	// eslint-disable-next-line no-console
	console.log(`The challenge's "${pressed}" was pressed ${presses} time(s), and the challenge closed.`);

	return { pressed, presses };
}

// Waits until the host has handed over an order, and returns it.
async function awaitPlaced(page) {
	await expect.poll(async () => (await placedOrders(page)).length, { timeout: STRIPE_MS }).toBe(1);

	return (await placedOrders(page))[0];
}

// Waits until the host rests with an error, and returns its code.
async function awaitError(page, code) {
	await expect.poll(async () => (await hostState(page))?.error?.code ?? null, { timeout: STRIPE_MS }).toBe(code);
}

// Reads an order's statuses as its customer does, with its key in the header.
async function orderStatus(api, placed) {
	const answer = await api.get(storeUrl(`/orders/${placed.orderUuid}`), {
		headers: { ...storeHeaders(), 'X-SEOCart-Order-Key': placed.orderKey },
	});

	expect(answer.status(), 'The order is read with its key.').toBe(200);

	const { status, payment_status: paymentStatus } = await answer.json();

	return { status, paymentStatus };
}

// Expects the order handed over to be approved: processing, its payment authorized for later capture.
async function expectApproved(page, placed) {
	expect(placed.outcome).toBe('approved');
	expect(await orderStatus(page.request, placed)).toEqual({ status: 'processing', paymentStatus: 'authorized' });
	expect((await hostState(page))?.phase).toBe('settled');
}

// Returns the page's placements and resumes, in order: the route, the idempotency key, the
// payment's uuid a resume names, and the answer's status.
function hostWrites(ledger) {
	return ledger.store
		.filter((entry) => entry.method === 'POST')
		.map((entry) => ({
			route: entry.route,
			key: entry.idempotencyKey,
			intent: entry.route.endsWith('/resume') ? JSON.parse(entry.body).intent_uuid : null,
			status: entry.status,
		}));
}

// Expects a decline to be shown in the island, the cart read again for it, and the host at rest.
async function expectDeclineShown(page) {
	await awaitError(page, 'declined');
	await expect(page.locator('.seocart-island[data-gateway="stripe"] .seocart-notice.is-error')).not.toBeEmpty();
	expect((await hostEvents(page)).filter((event) => event.type === 'stale').map((event) => event.detail.reason)).toEqual(['declined']);
	expect(await placedOrders(page)).toEqual([]);
}

// Holds the page to the host's contract: only its writes reached the Store API; no secret given,
// provider's handle or card number is in a Store API request or an address of the site; the tab
// keeps nothing secret. Stripe's own requests carry its handles to Stripe, and are left out.
async function expectContractKept(page, ledger, secrets) {
	const origin = new URL(home()).origin;

	await expectOnlyHostWrites(page, ledger);
	expectNoSecretInUrls({ ...ledger, urls: ledger.urls.filter((url) => url.startsWith(`${origin}/`)) }, secrets);
	await expectNothingSecretKept(page, secrets);
}

// A bank's step and Stripe's answers carry the payment's handle in addresses.
test.use({ trace: 'off', screenshot: 'off', video: 'off' });

test.describe('The card form in the checkout, as a guest', () => {
	let product;
	let ledger;
	let pluginBasePath;

	test.beforeAll(async () => {
		const { admin, headers } = await administrator();
		const created = await admin.post(restUrlOf(home(), '/wp/v2/seocart-products'), {
			headers,
			data: { title: 'Card form spec: a product a guest pays for', status: 'publish', seocart: { sku: `STRIPE-E2E-${Date.now()}`, price_minor: PRICE } },
		});

		expect(created.status(), 'The product is created.').toBe(201);

		const { id, seocart } = await created.json();
		const stocked = await admin.post(restUrlOf(home(), `/seocart/v1/stock-items/${seocart.variant_id}/adjustments`), {
			headers,
			data: { delta: 400, reason: 'received' },
		});

		expect(stocked.status(), 'The product is stocked.').toBeLessThan(300);

		product = { id, variantId: seocart.variant_id };
		pluginBasePath = `${new URL(home()).pathname}wp-content/plugins/seocart/`;
		await admin.dispose();
	});

	test.afterAll(async () => {
		if (!product) {
			return;
		}

		const { admin, headers } = await administrator();

		// The trash, not a delete: the orders' allocations keep the variant from being deleted.
		await admin.delete(restUrlOf(home(), `/wp/v2/seocart-products/${product.id}`), { headers });
		await admin.dispose();
	});

	test.beforeEach(async ({ page }) => {
		// A client of its own, for this test.
		client = await forwardClientAddress(page.context(), home());
		ledger = storeRequests(page);
		consoleLines = [];
		page.on('console', (message) => consoleLines.push(`${message.type()}: ${redacted(message.text())}`));
		await page.addInitScript(watchStripe);
	});

	test.afterEach(async ({ page }, testInfo) => {
		const confirmations = (await stripeSeen(page).catch(() => null))?.confirmations ?? [];

		// What each confirmation came to, as Stripe answered it: the PaymentIntent's status, or the
		// error's type and code; never a message or a handle.
		// eslint-disable-next-line no-console
		console.log(`Stripe's confirmations: ${JSON.stringify(confirmations.map(({ error, status }) => (error ? `${error.type}/${error.code}` : status)))}`);

		if (testInfo.status !== testInfo.expectedStatus) {
			const state = await hostState(page).catch(() => null);
			const seen = await stripeSeen(page).catch(() => null);

			// eslint-disable-next-line no-console
			console.log(
				[
					ledger.describe(),
					`The host: ${state?.phase ?? 'unknown'}, ${state?.error?.code ?? 'no error'} ${state?.error?.detail ?? ''}`,
					`Stripe.js: ${JSON.stringify(seen)}`,
					`Frames: ${frameNames(page)}`,
					'Console:',
					...consoleLines.slice(-20),
				].join('\n')
			);
		}
	});

	test('(1) Stripe.js is loaded once Stripe is selected, and the Payment Element shows card fields only', async ({ page }) => {
		await readyCart(page.request, product.variantId);
		await openCheckout(page, pluginBasePath, [BANK, stripeMethod()]);

		const stripeJs = () => ledger.urls.filter((url) => url.startsWith('https://js.stripe.com/')).length;

		await expect(page.locator('.seocart-choice[data-method="bank_transfer"]')).toHaveClass(/is-selected/);
		await page.waitForTimeout(1000);
		expect(stripeJs(), 'No request to Stripe.js before Stripe is selected.').toBe(0);

		await page.locator('.seocart-choice[data-method="stripe"] input[type="radio"]').check();

		await expect(page.locator('.seocart-island[data-gateway="stripe"] iframe').first()).toBeVisible();
		expect(stripeJs()).toBeGreaterThan(0);
		await typeCard(page, CARDS.approved);

		const { totals } = await readCart(page.request);

		expect(await stripeSeen(page)).toEqual({
			watched: true,
			elements: [{ mode: 'payment', amount: totals.summary.grand_minor, currency: totals.currency.toLowerCase(), captureMethod: 'manual' }],
			created: 1,
			destroyed: 0,
			confirmations: [],
		});
		await expectContractKept(page, ledger, []);
	});

	test('(2) a card the bank challenges: the challenge appears, Complete is pressed, and the resume approves', async ({ page }) => {
		await readyCart(page.request, product.variantId);
		await openCheckout(page, pluginBasePath, [stripeMethod()]);
		await typeCard(page, CARDS.challenged);
		await placeOrder(page);

		expect((await answerChallenge(page, 'authorize')).pressed).toMatch(/complete/i);

		const placed = await awaitPlaced(page);

		await expectApproved(page, placed);
		expect(hostWrites(ledger).map(({ route }) => route)).toEqual([`${STORE}/checkout`, `${STORE}/checkout/resume`]);
		await expectContractKept(page, ledger, [placed.orderKey]);
	});

	test('(3) the challenge failed: declined, shown in the form, the cart open again; another card is then approved', async ({ page }) => {
		await readyCart(page.request, product.variantId);
		await openCheckout(page, pluginBasePath, [stripeMethod()]);
		await typeCard(page, CARDS.challenged);
		await placeOrder(page);

		expect((await answerChallenge(page, 'fail')).pressed).toMatch(/fail/i);
		await expectDeclineShown(page);

		const reopened = await readCart(page.request);

		expect(reopened.lines.length, 'The cart is open again, with its line.').toBe(1);

		await typeCard(page, CARDS.approved);
		await placeOrder(page);

		const placed = await awaitPlaced(page);
		const writes = hostWrites(ledger);

		await expectApproved(page, placed);
		expect(writes.map(({ route }) => route)).toEqual([`${STORE}/checkout`, `${STORE}/checkout/resume`, `${STORE}/checkout`, `${STORE}/checkout/resume`]);
		expect(writes[0].key, 'The second attempt has a key of its own.').not.toBe(writes[2].key);
		expect(writes[1].intent, 'Each attempt is a payment of its own.').not.toBe(writes[3].intent);
		await expectContractKept(page, ledger, [placed.orderKey]);
	});

	test('(4) a declined card is declined at the resume and shown in the form; another card is then approved', async ({ page }) => {
		await readyCart(page.request, product.variantId);
		await openCheckout(page, pluginBasePath, [stripeMethod()]);
		await typeCard(page, CARDS.declined);
		await placeOrder(page);
		await expectDeclineShown(page);

		await typeCard(page, CARDS.approved);
		await placeOrder(page);

		const placed = await awaitPlaced(page);

		await expectApproved(page, placed);
		expect(hostWrites(ledger).map(({ route }) => route)).toEqual([`${STORE}/checkout`, `${STORE}/checkout/resume`, `${STORE}/checkout`, `${STORE}/checkout/resume`]);
		await expectContractKept(page, ledger, [placed.orderKey]);
	});

	test('(5) Place pressed twice at once places once', async ({ page }) => {
		await readyCart(page.request, product.variantId);
		await openCheckout(page, pluginBasePath, [stripeMethod()]);
		await typeCard(page, CARDS.approved);
		// The fixture's Place button calls the host on every click, and stays enabled: both clicks
		// reach it, which the page counts.
		await page.evaluate(() => {
			window.__placeClicks = 0;
			document.getElementById('seocart-place').addEventListener('click', () => window.__placeClicks++);
		});
		await page.locator('#seocart-place').dblclick();

		const placed = await awaitPlaced(page);

		expect(await page.evaluate(() => window.__placeClicks), 'Both clicks reached Place.').toBe(2);
		await expectApproved(page, placed);
		expect(hostWrites(ledger).map(({ route }) => route)).toEqual([`${STORE}/checkout`, `${STORE}/checkout/resume`]);
		await expectContractKept(page, ledger, [placed.orderKey]);
	});

	test('(6) the page reloaded during the challenge: one payment, approved once the card is typed again', async ({ page }) => {
		await readyCart(page.request, product.variantId);
		await openCheckout(page, pluginBasePath, [stripeMethod()]);
		await typeCard(page, CARDS.challenged);
		await placeOrder(page);
		await expect.poll(() => challengeShown(page), { timeout: STRIPE_MS, message: 'Stripe\'s challenge appears on the page.' }).toBe(true);

		await page.reload();
		await waitForHost(page);
		await updateHost(page);
		// The host resumes the kept payment on loading, and waits for the shopper: nothing acts by itself.
		await awaitError(page, 'action_incomplete');
		expect(challengeShown(page), 'No challenge opens by itself.').toBe(false);

		await typeCard(page, CARDS.challenged);
		await placeOrder(page);
		await answerChallenge(page, 'authorize');

		const placed = await awaitPlaced(page);
		const writes = hostWrites(ledger);

		await expectApproved(page, placed);
		expect(writes.map(({ route }) => route)).toEqual([`${STORE}/checkout`, `${STORE}/checkout/resume`, `${STORE}/checkout`, `${STORE}/checkout/resume`]);
		expect(writes[2].key, 'Placed again under the kept key.').toBe(writes[0].key);
		expect(writes[3].intent, 'The same payment is resumed: one PaymentIntent.').toBe(writes[1].intent);
		await expectContractKept(page, ledger, [placed.orderKey]);
	});

	test('(7) a new amount while the form is shown: a new Element for it, which the placement carries', async ({ page }) => {
		await readyCart(page.request, product.variantId);
		await openCheckout(page, pluginBasePath, [stripeMethod()]);
		await expect(cardFrame(page).locator('input[name="number"]')).toBeVisible();

		const first = (await readCart(page.request)).totals.summary.grand_minor;

		await expectRemountOn(page, 'stripe', async () => {
			await addLine(page.request, product.variantId, 1);
			await updateHost(page);
		});

		const seen = await stripeSeen(page);
		const total = (await readCart(page.request)).totals.summary.grand_minor;

		expect({ created: seen.created, destroyed: seen.destroyed }, 'The first Element destroyed, a second made.').toEqual({ created: 2, destroyed: 1 });
		expect(total).toBeGreaterThan(first);
		expect(seen.elements.map(({ amount }) => amount)).toEqual([first, total]);

		await typeCard(page, CARDS.approved);
		await placeOrder(page);

		const placed = await awaitPlaced(page);
		const [placement] = ledger.store.filter((entry) => entry.method === 'POST' && entry.route === `${STORE}/checkout`);

		await expectApproved(page, placed);
		expect(JSON.parse(placement.body).grand_total_minor, 'The placement is for the Element\'s amount.').toBe(total);
		await expectContractKept(page, ledger, [placed.orderKey]);
	});
});
