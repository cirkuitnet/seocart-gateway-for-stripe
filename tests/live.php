<?php
/**
 * The Stripe test mode workflow, as SEOCart's live steps read it: its parts, what each proves, the conformance suite's
 * run against Stripe, the shapes of Stripe's credentials, and the PaymentIntents a recorded run created
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\GatewayForStripe\Tests\Support\Live\CleanupSelection;
use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;

// SEOCart reads this file by path, where no autoloader may be: what it needs of the plugin's tests is required by path too.
require_once __DIR__ . '/Support/Live/CleanupSelection.php';

return new class() extends LiveDeclaration {

	/**
	 * Returns the live workflow's file name.
	 *
	 * @since 0.2.0
	 *
	 * @return string The file name.
	 */
	public function workflow(): string {
		return 'stripe-test-mode.yml';
	}

	/**
	 * Returns the jobs, each with its choice of the `parts` input.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each choice, by job.
	 */
	public function jobs(): array {
		return array(
			'live-conformance' => 'conformance',
			'three-d-secure'   => 'three-d-secure',
			'webhooks'         => 'webhooks',
			'checkout'         => 'checkout',
		);
	}

	/**
	 * Returns the live parts' tests: a run of fewer proved less than the part claims.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{filter: string, tests: int}> Each part's filter and count.
	 */
	public function liveParts(): array {
		return array(
			'three-d-secure' => array(
				'filter' => '/Live\\\\(AbandonedWindow|ThreeDSecure)Test::/',
				'tests'  => 4,
			),
			'webhooks'       => array(
				'filter' => '/Live\\\\(EndpointProvisioning|PolledEvents|Soak)Test::/',
				'tests'  => 3,
			),
		);
	}

	/**
	 * Returns what a run proves, each with its job.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each job, by what it proves.
	 */
	public function proofs(): array {
		return array(
			'The conformance suite, every request sent to Stripe\'s test mode' => 'live-conformance',
			'3-D Secure completed in a browser on Stripe\'s test page, then resumed' => 'three-d-secure',
			'A shopper who never acts: voided at the window\'s end, cancelled at Stripe `abandoned`' => 'three-d-secure',
			'Real events read back from Stripe, delivered before, after and twice, and soaked' => 'webhooks',
			'Webhook endpoint set up at Stripe, reused, and removed' => 'webhooks',
			'The checkout\'s card form in a browser: Stripe\'s Payment Element, a bank\'s challenge passed and failed, declines, retries, a reload and a new amount' => 'checkout',
		);
	}

	/**
	 * Returns the facts a job reports beyond its counts and nonce: the soak's seed, and the API versions of the events Stripe wrote.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The facts.
	 */
	public function facts(): array {
		return array( 'seed', 'api-versions' );
	}

	/**
	 * Returns how many tests the conformance test holds.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	public function suiteTests(): int {
		return 17;
	}

	/**
	 * Returns the tests the suite skips against Stripe's test mode, by the scenario each cannot produce.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each scenario, by test.
	 */
	public function suiteSkips(): array {
		return array(
			'test_rule_09_expiry'                    => 'expires',
			'test_rule_17_void_answers_the_approval' => 'requires_action_completed',
		);
	}

	/**
	 * Returns the shapes of Stripe's credentials: its keys, a webhook signing secret, a client secret, and a receipt's address.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The patterns, by what each matches.
	 */
	public function shapes(): array {
		return array(
			'a secret or restricted key' => '/(?<![A-Za-z0-9])(?:sk|rk)_[A-Za-z0-9_]{20,}/',
			'a publishable key'          => '/(?<![A-Za-z0-9])pk_(?:test|live)_[A-Za-z0-9_]{20,}/',
			'a webhook signing secret'   => '/whsec_[A-Za-z0-9_]*/',
			'a client secret'            => '/[A-Za-z0-9_]*_secret_[A-Za-z0-9_]*/',
			'a receipt\'s address'       => '/receipt_url/',
		);
	}

	/**
	 * Returns the PaymentIntents recorded exchanges created at Stripe, a declined one included.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<mixed>> $exchanges The exchanges.
	 * @return array<string, string> Each store uuid, by Stripe's PaymentIntent id.
	 */
	public function createdBy( array $exchanges ): array {
		return CleanupSelection::createdBy( $exchanges );
	}
};
