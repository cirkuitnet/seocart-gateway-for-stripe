<?php
/**
 * WebhookEndpoints: sets up the Stripe webhook endpoint of this site and mode
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\WebhookProvisioning;
use SEOCart\Contracts\Payment\WebhookTarget;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a failed call to SEOCart's settings command; they are never HTML.

/**
 * Reuses, replaces or creates the webhook endpoint Stripe delivers this site's events to, for one mode.
 *
 * Owns one fact: which of the Stripe account's endpoints are this site's, and what is done with
 * them. An endpoint is this site's when its metadata carries this installation's uuid and the
 * mode (TAG_INSTALL, TAG_MODE) and its URL is the target's. The account may hold endpoints of other
 * sites and other integrations: they are skipped, never changed, never logged; this installation's
 * endpoints at another URL (a copy of the site) are only counted.
 *
 * Of this site's endpoints, the one whose signing secret SEOCart holds is reused while it still
 * matches: enabled, on the API version this adapter reads, subscribed to exactly the target's
 * events. Every other one is deleted, since Stripe shows a signing secret only when it creates an
 * endpoint, and then one is created, whose secret is handed back for SEOCart to keep sealed.
 * Listing first makes running it again safe: an endpoint created whose answer was lost is one of
 * this site's whose secret is not held, and the next run replaces it.
 *
 * @since 0.2.0
 */
final class WebhookEndpoints {

	/**
	 * The metadata key of an endpoint that holds the installation uuid of the site that set it up.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TAG_INSTALL = 'seocart_install';

	/**
	 * The metadata key of an endpoint that holds the mode it was set up for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TAG_MODE = 'seocart_mode';

	/**
	 * The most pages of endpoints read: Stripe keeps at most 16 per account, a page holds 100.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const MAX_PAGES = 10;

	/**
	 * The requests to Stripe.
	 *
	 * @since 0.2.0
	 *
	 * @var StripeApi
	 */
	private StripeApi $api;

	/**
	 * Builds the provisioner. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param StripeApi $api The requests to Stripe.
	 */
	public function __construct( StripeApi $api ) {
		$this->api = $api;
	}

	/**
	 * Sets up this site's endpoint for the target's mode: reuses, replaces or creates it.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When Stripe could not be asked, or refused a step; the message names the step and the status.
	 *
	 * @param WebhookTarget $target The endpoint this site needs.
	 * @return WebhookProvisioning What was done, with the new signing secret when one was created.
	 */
	public function provision( WebhookTarget $target ): WebhookProvisioning {
		$keep      = null;
		$stale     = array();
		$elsewhere = 0;

		foreach ( $this->endpoints( $target ) as $endpoint ) {
			if ( ! self::isThisInstallation( $endpoint, $target ) ) {
				continue;
			}

			if ( Fields::text( $endpoint, 'url' ) !== $target->url ) {
				++$elsewhere;
			} elseif ( null === $keep && self::isReusable( $endpoint, $target ) ) {
				$keep = $endpoint;
			} else {
				$stale[] = $endpoint;
			}
		}

		$removed = array();

		foreach ( $stale as $endpoint ) {
			$removed[] = $this->remove( $target, self::id( $endpoint ) );
		}

		if ( null !== $keep ) {
			return new WebhookProvisioning( WebhookProvisioning::REUSED, self::id( $keep ), null, $removed, $elsewhere );
		}

		$created = $this->create( $target );

		return new WebhookProvisioning( array() === $removed ? WebhookProvisioning::CREATED : WebhookProvisioning::REPLACED, $created['id'], $created['secret'], $removed, $elsewhere );
	}

	/**
	 * Lists every endpoint of the account, page by page.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When a page could not be read, its last endpoint is not named by a webhook endpoint's id, or there are more pages than an account can hold.
	 *
	 * @param WebhookTarget $target The target, whose mode's key the requests carry.
	 * @return list<array<mixed>> The endpoints.
	 */
	private function endpoints( WebhookTarget $target ): array {
		$endpoints = array();
		$after     = null;

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$answer = $this->api->get( $target->mode, 'webhook_endpoints', array( 'limit' => 100 ) + ( null === $after ? array() : array( 'starting_after' => $after ) ) );

			if ( $answer->isError() ) {
				throw new GatewayUnavailable( sprintf( 'Listing the Stripe webhook endpoints failed: HTTP %d.', $answer->status ) );
			}

			$listed    = Fields::objects( $answer->body, 'data' );
			$endpoints = array_merge( $endpoints, $listed );

			if ( true !== Fields::flag( $answer->body, 'has_more' ) ) {
				return $endpoints;
			}

			$after = array() === $listed ? null : self::id( $listed[ count( $listed ) - 1 ] );

			if ( null === $after ) {
				break;
			}
		}

		throw new GatewayUnavailable( 'Listing the Stripe webhook endpoints did not come to an end.' );
	}

	/**
	 * Deletes one of this site's endpoints; one already gone counts as deleted.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When Stripe refused the deletion.
	 *
	 * @param WebhookTarget $target The target, whose mode's key the request carries.
	 * @param string        $id     The endpoint's id.
	 * @return string The id.
	 */
	private function remove( WebhookTarget $target, string $id ): string {
		$answer = $this->api->delete( $target->mode, StripeApi::path( 'webhook_endpoints', $id ) );

		if ( $answer->isError() && 404 !== $answer->status ) {
			throw new GatewayUnavailable( sprintf( 'Deleting a Stripe webhook endpoint failed: HTTP %d.', $answer->status ) );
		}

		return $id;
	}

	/**
	 * Creates the endpoint, tagged as this site's, on the API version this adapter reads.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When Stripe refused it, or answered without the endpoint's id or signing secret.
	 *
	 * @param WebhookTarget $target The target.
	 * @return array{id: string, secret: string} The endpoint's id and signing secret.
	 */
	private function create( WebhookTarget $target ): array {
		$answer = $this->api->post(
			$target->mode,
			'webhook_endpoints',
			array(
				'url'            => $target->url,
				'enabled_events' => $target->events,
				'api_version'    => Gateway::API_VERSION,
				'description'    => sprintf( 'SEOCart (%s)', $target->mode->value ),
				'metadata'       => array(
					self::TAG_INSTALL => $target->installUuid,
					self::TAG_MODE    => $target->mode->value,
				),
			)
		);

		if ( $answer->isError() ) {
			throw new GatewayUnavailable( sprintf( 'Creating the Stripe webhook endpoint failed: HTTP %d.', $answer->status ) );
		}

		$secret = Fields::text( $answer->body, 'secret' );

		if ( null === $secret || ! str_starts_with( $secret, 'whsec_' ) ) {
			throw new GatewayUnavailable( 'Stripe created the webhook endpoint but did not answer with its signing secret.' );
		}

		return array(
			'id'     => self::id( $answer->body ),
			'secret' => $secret,
		);
	}

	/**
	 * Tells whether an endpoint carries this installation's tag for the target's mode.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed>  $endpoint The endpoint.
	 * @param WebhookTarget $target   The target.
	 * @return bool True when it does.
	 */
	private static function isThisInstallation( array $endpoint, WebhookTarget $target ): bool {
		return Fields::text( $endpoint, 'metadata', self::TAG_INSTALL ) === $target->installUuid
			&& Fields::text( $endpoint, 'metadata', self::TAG_MODE ) === $target->mode->value;
	}

	/**
	 * Tells whether this site's endpoint at the target's URL may be kept: SEOCart holds its secret, and it still matches.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed>  $endpoint The endpoint.
	 * @param WebhookTarget $target   The target.
	 * @return bool True when it may.
	 */
	private static function isReusable( array $endpoint, WebhookTarget $target ): bool {
		$events = Fields::texts( $endpoint, 'enabled_events' );
		$wanted = $target->events;

		sort( $events );
		sort( $wanted );

		return $target->secretHeld
			&& Fields::text( $endpoint, 'id' ) === $target->endpointId
			&& 'enabled' === Fields::text( $endpoint, 'status' )
			&& Gateway::API_VERSION === Fields::text( $endpoint, 'api_version' )
			&& array_values( array_unique( $events ) ) === array_values( array_unique( $wanted ) );
	}

	/**
	 * Returns an endpoint's id.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When it holds no webhook endpoint's id (StripeCodes::id()): such an id is never sent, logged or handed to SEOCart.
	 *
	 * @param array<mixed> $endpoint The endpoint.
	 * @return string The id.
	 */
	private static function id( array $endpoint ): string {
		return StripeCodes::id( Fields::text( $endpoint, 'id' ), 'we' ) ?? throw new GatewayUnavailable( 'Stripe answered with a webhook endpoint that is not named by a webhook endpoint\'s id.' );
	}
}
