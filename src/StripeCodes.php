<?php
/**
 * StripeCodes: the words Stripe documents, against which anything Stripe answers is checked before the gateway repeats it
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

/**
 * Stripe's documented enumerations (error types, error codes, decline codes, refund failure reasons, cancellation reasons and PaymentIntent statuses) and the shapes of its ids and of the words of its paths.
 *
 * Owns one fact: which of Stripe's words the gateway may repeat. An answer is a string Stripe
 * wrote, and a field meant for a code can hold anything, a card number or a key included; so
 * the gateway repeats a code, in a log line or in a result's decline code, only when it is one of
 * the values Stripe documents, and an id only when it has the shape of a Stripe id; anything
 * else it repeats as nothing. A message, a parameter name or a link from an answer is never
 * repeated at all. A request's path, likewise, holds only words of Stripe's paths and Stripe ids
 * (pathSegment()).
 *
 * The lists are a snapshot of Stripe's documentation (the error codes at
 * https://docs.stripe.com/error-codes, the decline codes at https://docs.stripe.com/declines/codes),
 * so a value Stripe adds later is repeated as nothing, and a result takes the gateway's own code,
 * until it is listed here.
 *
 * @since 0.2.0
 */
final class StripeCodes {

	/**
	 * The types of an error Stripe answers.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const ERROR_TYPES = array( 'api_error', 'card_error', 'idempotency_error', 'invalid_request_error' );

	/**
	 * The decline codes Stripe documents, for card payments and for local payment methods.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const DECLINE_CODES = array(
		'authentication_required',
		'authentication_not_handled',
		'approve_with_id',
		'call_issuer',
		'card_not_supported',
		'card_velocity_exceeded',
		'currency_not_supported',
		'do_not_honor',
		'do_not_try_again',
		'duplicate_transaction',
		'expired_card',
		'fraudulent',
		'generic_decline',
		'incorrect_address',
		'incorrect_cvc',
		'incorrect_number',
		'incorrect_pin',
		'incorrect_zip',
		'insufficient_funds',
		'invalid_account',
		'invalid_amount',
		'invalid_cvc',
		'invalid_expiry_month',
		'invalid_expiry_year',
		'invalid_number',
		'invalid_pin',
		'issuer_not_available',
		'lost_card',
		'merchant_blacklist',
		'new_account_information_available',
		'no_action_taken',
		'not_permitted',
		'offline_pin_required',
		'online_or_offline_pin_required',
		'pickup_card',
		'pin_try_exceeded',
		'processing_error',
		'reenter_transaction',
		'restricted_card',
		'revocation_of_all_authorizations',
		'revocation_of_authorization',
		'security_violation',
		'service_not_allowed',
		'stolen_card',
		'stop_payment_order',
		'testmode_decline',
		'transaction_not_allowed',
		'try_again_later',
		'withdrawal_count_limit_exceeded',
		'mobile_device_authentication_required',
		'partner_generic_decline',
		'customer_declined',
		'code_invalid',
		'code_expired',
		'invalid_customer_account',
		'payment_limit_exceeded',
		'invalid_billing_agreement',
		'invalid_business_account',
		'partner_high_risk_customer',
		'compliance_violation',
		'payment_disputed',
		'invalid_authorization',
		'invalid_payment_information',
		'partner_payment_not_found',
		'expired_payment_information',
		'recurring_not_supported_by_bank',
		'partner_action_not_supported',
		'lost_or_stolen_card',
		'card_already_activated',
		'invalid_track_data',
	);

	/**
	 * The error codes Stripe documents.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const ERROR_CODES = array(
		'account_closed',
		'account_country_invalid_address',
		'account_error_country_change_requires_additional_steps',
		'account_holder_name_verification_failed',
		'account_invalid',
		'account_number_invalid',
		'acss_debit_session_incomplete',
		'action_blocked',
		'alipay_upgrade_required',
		'amount_too_large',
		'amount_too_small',
		'anomalous_money_movement_request',
		'api_key_expired',
		'authentication_required',
		'authentication_failure',
		'balance_insufficient',
		'balance_invalid_parameter',
		'bank_account_bad_routing_numbers',
		'bank_account_declined',
		'bank_account_exists',
		'bank_account_restricted',
		'bank_account_verification_failed',
		'bank_account_unusable',
		'bank_account_unverified',
		'billing_invalid_mandate',
		'bitcoin_upgrade_required',
		'capability_not_active',
		'card_decline_rate_limit_exceeded',
		'card_declined',
		'cardholder_phone_number_required',
		'capture_charge_authorization_expired',
		'capture_unauthorized_payment',
		'charge_already_captured',
		'charge_already_refunded',
		'charge_disputed',
		'charge_exceeds_source_limit',
		'charge_exceeds_transaction_limit',
		'charge_expired_for_capture',
		'charge_invalid_parameter',
		'clearing_code_unsupported',
		'country_code_invalid',
		'country_unsupported',
		'coupon_expired',
		'customer_max_payment_methods',
		'customer_max_subscriptions',
		'customer_tax_location_invalid',
		'debit_not_authorized',
		'email_invalid',
		'expired_card',
		'expired_payment_method',
		'financial_connections_account_inactive',
		'financial_connections_no_successful_transaction_refresh',
		'forwarding_api_inactive',
		'forwarding_api_invalid_parameter',
		'forwarding_api_retryable_upstream_error',
		'forwarding_api_upstream_connection_error',
		'forwarding_api_upstream_connection_timeout',
		'forwarding_api_upstream_error',
		'idempotency_key_in_use',
		'incorrect_address',
		'incorrect_cvc',
		'incorrect_number',
		'incorrect_postal_code',
		'incorrect_zip',
		'instant_payouts_config_disabled',
		'instant_payouts_limit_exceeded',
		'instant_payouts_unsupported',
		'insufficient_funds',
		'intent_invalid_state',
		'intent_verification_method_missing',
		'invalid_card_type',
		'invalid_characters',
		'invalid_charge_amount',
		'invalid_cvc',
		'invalid_expiry_month',
		'invalid_expiry_year',
		'invalid_mandate_reference_prefix_format',
		'invalid_number',
		'invalid_source_usage',
		'invalid_tax_location',
		'invoice_no_customer_line_items',
		'invoice_no_payment_method_types',
		'invoice_no_subscription_line_items',
		'invoice_not_editable',
		'invoice_on_behalf_of_not_editable',
		'invoice_payment_intent_requires_action',
		'invoice_upcoming_none',
		'livemode_mismatch',
		'lock_timeout',
		'missing',
		'no_account',
		'not_allowed_on_standard_account',
		'out_of_inventory',
		'parameter_invalid_empty',
		'parameter_invalid_integer',
		'parameter_invalid_string_blank',
		'parameter_invalid_string_empty',
		'parameter_missing',
		'parameter_unknown',
		'parameters_exclusive',
		'payment_intent_amount_reconfirmation_required',
		'payment_intent_action_required',
		'payment_intent_authentication_failure',
		'payment_intent_automatic_tax_incomplete',
		'payment_intent_incompatible_payment_method',
		'payment_intent_invalid_parameter',
		'payment_intent_konbini_rejected_confirmation_number',
		'payment_intent_mandate_invalid',
		'payment_intent_payment_attempt_expired',
		'payment_intent_payment_attempt_failed',
		'payment_intent_unexpected_state',
		'payment_method_bank_account_already_verified',
		'payment_method_bank_account_blocked',
		'payment_method_billing_details_address_missing',
		'payment_method_currency_mismatch',
		'payment_method_customer_decline',
		'payment_method_invalid_parameter',
		'payment_method_invalid_parameter_testmode',
		'payment_method_microdeposit_failed',
		'payment_method_microdeposit_processing_error',
		'payment_method_microdeposit_verification_amounts_invalid',
		'payment_method_microdeposit_verification_amounts_mismatch',
		'payment_method_microdeposit_verification_attempts_exceeded',
		'payment_method_microdeposit_verification_descriptor_code_mismatch',
		'payment_method_microdeposit_verification_timeout',
		'payment_method_not_available',
		'payment_method_provider_decline',
		'payment_method_provider_timeout',
		'payment_method_restricted',
		'payment_method_unactivated',
		'payment_method_unexpected_state',
		'payment_method_unsupported_type',
		'payouts_limit_exceeded',
		'payouts_not_allowed',
		'platform_account_required',
		'platform_api_key_expired',
		'postal_code_invalid',
		'processing_error',
		'product_inactive',
		'progressive_onboarding_limit_exceeded',
		'promotion_code_customer_missing_first_time',
		'promotion_code_customer_not_first_time',
		'rate_limit',
		'refer_to_customer',
		'refund_disputed_payment',
		'resource_already_exists',
		'resource_missing',
		'return_intent_already_processed',
		'routing_number_invalid',
		'secret_key_required',
		'sepa_unsupported_account',
		'setup_attempt_failed',
		'setup_intent_authentication_failure',
		'setup_intent_invalid_parameter',
		'setup_intent_mandate_invalid',
		'setup_intent_setup_attempt_expired',
		'setup_intent_unexpected_state',
		'shipping_address_invalid',
		'shipping_calculation_failed',
		'sku_inactive',
		'state_unsupported',
		'status_transition_invalid',
		'stripe_tax_inactive',
		'tax_id_invalid',
		'tax_id_prohibited',
		'taxes_calculation_failed',
		'terminal_location_country_unsupported',
		'terminal_reader_busy',
		'terminal_reader_hardware_fault',
		'terminal_reader_invalid_location_for_activation',
		'terminal_reader_invalid_location_for_payment',
		'terminal_reader_offline',
		'terminal_reader_timeout',
		'testmode_charges_only',
		'tls_version_unsupported',
		'token_already_used',
		'token_in_use',
		'transfer_source_balance_parameters_mismatch',
		'transfers_not_allowed',
		'url_invalid',
		'dispute_evidence_page_limit_exceeded',
	);

	/**
	 * Why a Refund failed, as Stripe documents it.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const REFUND_FAILURE_REASONS = array( 'lost_or_stolen_card', 'expired_or_canceled_card', 'charge_for_pending_refund_disputed', 'insufficient_funds', 'declined', 'merchant_request', 'unknown' );

	/**
	 * Why a PaymentIntent was cancelled, as Stripe documents it.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const CANCELLATION_REASONS = array( 'abandoned', 'automatic', 'duplicate', 'expired', 'failed_invoice', 'fraudulent', 'requested_by_customer', 'void_invoice' );

	/**
	 * The states of a PaymentIntent.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const INTENT_STATUSES = array( 'requires_payment_method', 'requires_confirmation', 'requires_action', 'processing', 'requires_capture', 'canceled', 'succeeded' );

	/**
	 * The prefixes of the Stripe ids the gateway may repeat: PaymentIntent, charge, payment, refund, payment refund, webhook endpoint, event, dispute.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const ID_PREFIXES = array( 'pi', 'ch', 'py', 're', 'pyr', 'we', 'evt', 'du' );

	/**
	 * The shape of an enumerated word: what any code or reason the gateway repeats must look like.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WORD = '/^[a-z0-9_]{1,64}\z/';

	/**
	 * The shape of a word of Stripe's paths, such as `payment_intents` or `capture`: lowercase letters and underscores, never a digit.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PATH_WORD = '/^[a-z_]{1,64}\z/';

	/**
	 * Returns a Stripe id, when it has the shape of one: a known prefix, an underscore, and 8 to 64 letters and digits.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value    What the answer holds.
	 * @param string      ...$kinds Optional. The prefixes accepted, such as `pi`. Default every one of ID_PREFIXES.
	 * @return string|null The id; null for anything else.
	 */
	public static function id( ?string $value, string ...$kinds ): ?string {
		$prefixes = implode( '|', array() === $kinds ? self::ID_PREFIXES : $kinds );

		return null !== $value && 1 === preg_match( '/^(?:' . $prefixes . ')_[A-Za-z0-9]{8,64}\z/', $value ) ? $value : null;
	}

	/**
	 * Returns a PaymentIntent's client secret, when it has the form of that PaymentIntent's own: its id, `_secret_`, then 8 to 64 letters and digits.
	 *
	 * A key, another PaymentIntent's secret or anything else in its place never reaches the
	 * shopper's browser.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value    What the answer holds.
	 * @param string      $intentId The PaymentIntent's id, itself checked by id().
	 * @return string|null The client secret; null for anything else.
	 */
	public static function clientSecret( #[\SensitiveParameter] ?string $value, string $intentId ): ?string {
		return null !== $value && 1 === preg_match( '/^' . preg_quote( $intentId, '/' ) . '_secret_[A-Za-z0-9]{8,64}\z/', $value ) ? $value : null;
	}

	/**
	 * Returns a segment of a request's path, when it is a word of Stripe's paths or a Stripe id.
	 *
	 * A digit run such as a card number is neither, so it never reaches a request's path or the
	 * log line that names one.
	 *
	 * @since 0.2.0
	 *
	 * @param string $segment The segment, such as `payment_intents`, a PaymentIntent's id or `capture`.
	 * @return string|null The segment; null for anything else.
	 */
	public static function pathSegment( string $segment ): ?string {
		return 1 === preg_match( self::PATH_WORD, $segment ) ? $segment : self::id( $segment );
	}

	/**
	 * Returns an enumerated word, when it has the shape of one.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The word; null for anything else.
	 */
	public static function word( ?string $value ): ?string {
		return null !== $value && 1 === preg_match( self::WORD, $value ) ? $value : null;
	}

	/**
	 * Returns an error type, when it is one Stripe documents.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The type; null for anything else.
	 */
	public static function errorType( ?string $value ): ?string {
		return self::oneOf( $value, self::ERROR_TYPES );
	}

	/**
	 * Returns an error code, when it is one Stripe documents.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The code; null for anything else.
	 */
	public static function errorCode( ?string $value ): ?string {
		return self::oneOf( $value, self::ERROR_CODES );
	}

	/**
	 * Returns a decline code, when it is one Stripe documents.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The code; null for anything else.
	 */
	public static function declineCode( ?string $value ): ?string {
		return self::oneOf( $value, self::DECLINE_CODES );
	}

	/**
	 * Returns why a Refund failed, when it is a reason Stripe documents.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The reason; null for anything else.
	 */
	public static function refundFailure( ?string $value ): ?string {
		return self::oneOf( $value, self::REFUND_FAILURE_REASONS );
	}

	/**
	 * Returns why a PaymentIntent was cancelled, when it is a reason Stripe documents.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The reason; null for anything else.
	 */
	public static function cancellationReason( ?string $value ): ?string {
		return self::oneOf( $value, self::CANCELLATION_REASONS );
	}

	/**
	 * Returns a PaymentIntent's status, when it is one Stripe documents.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The status; null for anything else.
	 */
	public static function intentStatus( ?string $value ): ?string {
		return self::oneOf( $value, self::INTENT_STATUSES );
	}

	/**
	 * Returns a value when a list holds it.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value The value.
	 * @param string[]    $known The values Stripe documents.
	 * @return string|null The value; null when it is not one of them.
	 *
	 * @phpstan-param list<string> $known
	 */
	private static function oneOf( ?string $value, array $known ): ?string {
		return null !== self::word( $value ) && in_array( $value, $known, true ) ? $value : null;
	}
}
