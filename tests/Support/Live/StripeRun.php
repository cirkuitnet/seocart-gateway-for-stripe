<?php
/**
 * StripeRun: what the live tests, their cleanup and the workflow's script share of one run against Stripe, beside SEOCart's
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support\Live;

/**
 * Names the soak's environment of a run against Stripe's test mode.
 *
 * The workflow's soak-size step checks the soak-size input and gives the size to the webhooks
 * job's later steps; the seed repeats a soak.
 *
 * @since 0.2.0
 */
final class StripeRun {

	/**
	 * The environment variable giving the soak's size, as the workflow's soak-size step checked it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SOAK_SIZE = 'SEOCART_SOAK_SIZE';

	/**
	 * The environment variable giving the soak's seed, to repeat a run; a fresh one is drawn without it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SOAK_SEED = 'SEOCART_SOAK_SEED';
}
