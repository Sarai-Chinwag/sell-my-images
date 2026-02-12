<?php
/**
 * StripeClient stub for testing.
 *
 * Replaces StripeIntegration\StripeClient when the stripe-integration
 * plugin is not loaded in the test environment.
 *
 * @package SellMyImages\Tests
 */

namespace StripeIntegration;

/**
 * Stub StripeClient with controllable static responses.
 */
class StripeClient {

	/**
	 * Mock response for create_checkout_session.
	 *
	 * @var array|\WP_Error|null
	 */
	public static $mock_response = null;

	/**
	 * Last params passed to create_checkout_session.
	 *
	 * @var array|null
	 */
	public static $last_params = null;

	/**
	 * Mock response for validate_configuration.
	 *
	 * @var bool|\WP_Error
	 */
	public static $mock_validate = true;

	/**
	 * Create checkout session stub.
	 *
	 * @param array $params Session parameters.
	 * @return array|\WP_Error
	 */
	public static function create_checkout_session( array $params ) {
		self::$last_params = $params;
		return self::$mock_response;
	}

	/**
	 * Validate configuration stub.
	 *
	 * @return bool|\WP_Error
	 */
	public static function validate_configuration() {
		return self::$mock_validate;
	}

	/**
	 * Reset all mock state.
	 */
	public static function reset() {
		self::$mock_response = null;
		self::$last_params   = null;
		self::$mock_validate = true;
	}
}
