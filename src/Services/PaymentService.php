<?php
/**
 * Payment Service - Handles payment workflow
 *
 * Business logic for managing payments and coordination.
 * Uses the shared stripe-integration plugin for Stripe operations.
 *
 * @package SellMyImages
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SellMyImages\Services;

use SellMyImages\Managers\JobManager;
use SellMyImages\Api\CostCalculator;
use StripeIntegration\StripeClient;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PaymentService class
 */
class PaymentService {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks(): void {
		// Hook into stripe-integration webhook events.
		add_action( 'stripe_integration_webhook_checkout_session_completed', array( $this, 'handle_checkout_completed' ), 10, 2 );
		add_action( 'stripe_integration_webhook_payment_intent_succeeded', array( $this, 'handle_payment_succeeded' ), 10, 2 );
		add_action( 'stripe_integration_webhook_payment_intent_payment_failed', array( $this, 'handle_payment_failed' ), 10, 2 );
		add_action( 'stripe_integration_webhook_charge_refunded', array( $this, 'handle_charge_refunded' ), 10, 2 );
		add_action( 'stripe_integration_webhook_checkout_session_expired', array( $this, 'handle_checkout_expired' ), 10, 2 );
	}

	/**
	 * Create checkout session for image upscaling
	 *
	 * @param array  $image_data Image data including dimensions.
	 * @param string $resolution Resolution multiplier.
	 * @param string $email Customer email.
	 * @param string $job_id Job ID for tracking.
	 * @return array|\WP_Error Checkout session data or error.
	 */
	public function create_checkout_session( array $image_data, string $resolution, string $email, string $job_id ): array|\WP_Error {
		// Calculate pricing.
		$cost_data = CostCalculator::calculate_cost_detailed( $image_data, $resolution );

		if ( is_wp_error( $cost_data ) ) {
			return new \WP_Error( 'invalid_price', __( 'Unable to calculate price', 'sell-my-images' ) );
		}

		// Prepare Stripe session data.
		$session_data = array(
			'payment_method_types' => array( 'card' ),
			'line_items'           => array(
				array(
					'price_data' => array(
						'currency'     => 'usd',
						'product_data' => array(
							'name'        => sprintf(
								/* translators: %s: resolution multiplier (2x, 4x, 8x) */
								__( 'High-Resolution Image (%s)', 'sell-my-images' ),
								$resolution
							),
							'description' => sprintf(
								/* translators: 1: original width, 2: original height, 3: new width, 4: new height */
								__( 'Upscaled from %1$dx%2$d to %3$dx%4$d pixels', 'sell-my-images' ),
								$image_data['width'],
								$image_data['height'],
								$cost_data['output_dimensions']['width'],
								$cost_data['output_dimensions']['height']
							),
						),
						'unit_amount'  => intval( $cost_data['customer_price'] * 100 ), // Convert to cents.
					),
					'quantity'   => 1,
				),
			),
			'mode'                 => 'payment',
			'success_url'          => $this->get_current_page_url() . '?smi_payment=success&session_id={CHECKOUT_SESSION_ID}&job_id=' . $job_id,
			'cancel_url'           => $this->get_current_page_url() . '?smi_payment=cancelled&job_id=' . $job_id,
			'metadata'             => array(
				'job_id'     => $job_id,
				'resolution' => $resolution,
				'source'     => 'sell-my-images',
			),
			'context'              => 'sell-my-images', // For stripe_integration_checkout_params filter.
		);

		// Create session using shared StripeClient.
		$result = StripeClient::create_checkout_session( $session_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Normalize Stripe response keys to SMI's expected format.
		return array(
			'session_id'   => $result['id'] ?? null,
			'checkout_url' => $result['url'] ?? null,
			'amount'       => ( $result['amount_total'] ?? 0 ) / 100,
		);
	}

	/**
	 * Handle checkout completed event
	 *
	 * @param object|array $session Stripe session data.
	 * @param object       $event   Full Stripe event.
	 */
	public function handle_checkout_completed( $session, $event ): void {
		// Convert to array if needed.
		$session = is_object( $session ) ? $session->toArray() : (array) $session;

		// Only handle our events.
		if ( ( $session['metadata']['source'] ?? '' ) !== 'sell-my-images' ) {
			return;
		}

		$job_id = $session['metadata']['job_id'] ?? null;

		if ( ! $job_id ) {
			return;
		}

		// Update job payment status.
		$result = $this->update_job_payment_status(
			$job_id,
			'paid',
			$session['id'],
			$session['payment_intent'] ?? null,
			( $session['amount_total'] ?? 0 ) / 100 // Convert from cents.
		);

		if ( is_wp_error( $result ) ) {
			return;
		}

		// Backfill customer email from Stripe if available and job missing email.
		$stripe_email = null;
		if ( isset( $session['customer_details']['email'] ) && ! empty( $session['customer_details']['email'] ) ) {
			$stripe_email = sanitize_email( $session['customer_details']['email'] );
		} elseif ( isset( $session['customer_email'] ) && ! empty( $session['customer_email'] ) ) {
			$stripe_email = sanitize_email( $session['customer_email'] );
		}

		if ( $stripe_email ) {
			$job = JobManager::get_job( $job_id );
			if ( ! is_wp_error( $job ) && ( empty( $job->email ) || ! is_email( $job->email ) ) ) {
				JobManager::update_job_status( $job_id, $job->status, array( 'email' => $stripe_email ) );
			}
		}

		// Move job from 'awaiting_payment' to 'pending' now that payment is complete.
		JobManager::update_job_status(
			$job_id,
			'pending',
			array(
				'paid_at' => current_time( 'mysql' ),
			)
		);

		// Essential user flow log - payment completed.
		error_log( 'SMI: Payment completed for job ' . $job_id );

		// Trigger upscaling via action (separation of concerns).
		do_action( 'smi_payment_completed', $job_id );
	}

	/**
	 * Handle payment succeeded event
	 *
	 * @param object|array $payment_intent Payment intent data.
	 * @param object       $event          Full Stripe event.
	 */
	public function handle_payment_succeeded( $payment_intent, $event ): void {
		$payment_intent = is_object( $payment_intent ) ? $payment_intent->toArray() : (array) $payment_intent;

		// Only handle our events.
		if ( ( $payment_intent['metadata']['source'] ?? '' ) !== 'sell-my-images' ) {
			return;
		}

		$job_id = $payment_intent['metadata']['job_id'] ?? null;

		if ( $job_id ) {
			$this->update_job_payment_status( $job_id, 'paid', null, $payment_intent['id'] );
		}
	}

	/**
	 * Handle payment failed event
	 *
	 * @param object|array $payment_intent Payment intent data.
	 * @param object       $event          Full Stripe event.
	 */
	public function handle_payment_failed( $payment_intent, $event ): void {
		$payment_intent = is_object( $payment_intent ) ? $payment_intent->toArray() : (array) $payment_intent;

		// Only handle our events.
		if ( ( $payment_intent['metadata']['source'] ?? '' ) !== 'sell-my-images' ) {
			return;
		}

		$job_id = $payment_intent['metadata']['job_id'] ?? null;

		if ( $job_id ) {
			$this->update_job_payment_status( $job_id, 'failed' );
			JobManager::update_job_status( $job_id, 'failed' );
		}
	}

	/**
	 * Handle charge refunded event
	 *
	 * Note: Stripe propagates metadata from checkout session → payment_intent → charge.
	 * However, refunds created programmatically may not have job_id in metadata.
	 * In that case, we look up the job by payment_intent_id.
	 *
	 * @param object|array $charge Stripe charge data.
	 * @param object       $event  Full Stripe event.
	 */
	public function handle_charge_refunded( $charge, $event ): void {
		$charge = is_object( $charge ) ? $charge->toArray() : (array) $charge;

		// Only handle our events - check metadata source.
		$source = $charge['metadata']['source'] ?? '';
		$job_id = $charge['metadata']['job_id'] ?? null;

		// If no source metadata, this might be a programmatic refund. Skip.
		if ( $source !== 'sell-my-images' && $source !== '' ) {
			return; // Explicitly from another plugin.
		}

		// If no job_id in metadata but we have payment_intent, look it up.
		if ( ! $job_id && ! empty( $charge['payment_intent'] ) ) {
			$job = JobManager::get_job_by_payment_intent( $charge['payment_intent'] );
			if ( ! is_wp_error( $job ) ) {
				$job_id = $job->job_id;
			}
		}

		if ( ! $job_id ) {
			return;
		}

		$this->update_job_payment_status( $job_id, 'refunded', null, null, ( $charge['amount'] ?? 0 ) / 100 );
		JobManager::update_job_status( $job_id, 'refunded' );
	}

	/**
	 * Handle checkout session expired event
	 *
	 * @param object|array $session Stripe session data.
	 * @param object       $event   Full Stripe event.
	 */
	public function handle_checkout_expired( $session, $event ): void {
		$session = is_object( $session ) ? $session->toArray() : (array) $session;

		// Only handle our events.
		if ( ( $session['metadata']['source'] ?? '' ) !== 'sell-my-images' ) {
			return;
		}

		$job_id = $session['metadata']['job_id'] ?? null;

		if ( $job_id ) {
			JobManager::update_job_status( $job_id, 'abandoned' );
			error_log( 'SMI: Checkout session expired for job ' . $job_id );
		}
	}

	/**
	 * Update job payment status in database
	 *
	 * @param string      $job_id            Job ID.
	 * @param string      $payment_status    Payment status.
	 * @param string|null $session_id        Session ID (optional).
	 * @param string|null $payment_intent_id Payment intent ID (optional).
	 * @param float|null  $amount_charged    Amount charged (optional).
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	private function update_job_payment_status( string $job_id, string $payment_status, ?string $session_id = null, ?string $payment_intent_id = null, ?float $amount_charged = null ): bool|\WP_Error {
		$payment_data = array();

		if ( $session_id ) {
			$payment_data['stripe_checkout_session_id'] = $session_id;
		}

		if ( $payment_intent_id ) {
			$payment_data['stripe_payment_intent_id'] = $payment_intent_id;
		}

		if ( $amount_charged ) {
			$payment_data['amount_charged'] = $amount_charged;
		}

		return JobManager::update_payment_status( $job_id, $payment_status, $payment_data );
	}

	/**
	 * Get current page URL for redirect
	 *
	 * @return string Current page URL.
	 */
	private function get_current_page_url(): string {
		// Try to get from HTTP_REFERER first (most reliable for checkout flow).
		if ( isset( $_SERVER['HTTP_REFERER'] ) && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
			// Make sure it's from the same domain.
			if ( strpos( $referer, home_url() ) === 0 ) {
				return $referer;
			}
		}

		// Fallback to current page.
		$protocol = is_ssl() ? 'https://' : 'http://';
		$host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// If no host available (CLI, bots), fall back to site URL.
		if ( empty( $host ) ) {
			return home_url( '/' );
		}

		return $protocol . $host . $uri;
	}

	/**
	 * Validate payment configuration
	 *
	 * @return bool|\WP_Error True if valid, WP_Error if invalid.
	 */
	public function validate_configuration(): bool|\WP_Error {
		return StripeClient::validate_configuration();
	}
}
