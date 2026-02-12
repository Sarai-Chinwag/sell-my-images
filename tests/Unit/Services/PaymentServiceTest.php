<?php
/**
 * PaymentService Tests
 *
 * Tests the payment workflow against the real PaymentService implementation
 * which uses StripeIntegration\StripeClient static methods.
 *
 * @package SellMyImages\Tests
 */

namespace SellMyImages\Tests\Unit\Services;

use SellMyImages\Services\PaymentService;
use SellMyImages\Managers\JobManager;
use StripeIntegration\StripeClient;

// Load StripeClient stub before any test runs.
require_once dirname( __DIR__, 2 ) . '/stubs/StripeClientStub.php';

/**
 * PaymentServiceTest class
 */
class PaymentServiceTest extends \WP_UnitTestCase {

	/**
	 * Payment service instance.
	 *
	 * @var PaymentService
	 */
	private PaymentService $payment_service;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		StripeClient::reset();

		// Ensure required options exist.
		update_option( 'smi_markup_percentage', 550 );

		$this->payment_service = new PaymentService();
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down(): void {
		StripeClient::reset();
		parent::tear_down();
	}

	/**
	 * @test
	 * Regression: stripe-integration returns raw Stripe keys (id, url, amount_total)
	 * but SMI callers expect (session_id, checkout_url, amount in dollars).
	 * Broken Feb 5-12 2026 after migration to shared stripe-integration plugin.
	 */
	public function test_normalizes_stripe_response_keys(): void {
		StripeClient::$mock_response = array(
			'id'           => 'cs_live_abc123',
			'url'          => 'https://checkout.stripe.com/c/pay/cs_live_abc123',
			'amount_total' => 130, // Stripe returns cents.
			'object'       => 'checkout.session',
			'status'       => 'open',
		);

		$result = $this->payment_service->create_checkout_session(
			array( 'width' => 1000, 'height' => 800 ),
			'4x',
			'test@example.com',
			'job-regression-test'
		);

		$this->assertIsArray( $result );

		// Normalized keys must exist.
		$this->assertArrayHasKey( 'session_id', $result, 'Must map Stripe id → session_id' );
		$this->assertArrayHasKey( 'checkout_url', $result, 'Must map Stripe url → checkout_url' );
		$this->assertArrayHasKey( 'amount', $result, 'Must map Stripe amount_total → amount' );

		// Values correct.
		$this->assertEquals( 'cs_live_abc123', $result['session_id'] );
		$this->assertEquals( 'https://checkout.stripe.com/c/pay/cs_live_abc123', $result['checkout_url'] );
		$this->assertEquals( 1.30, $result['amount'], 'Amount must convert cents to dollars' );

		// Raw Stripe keys must NOT leak.
		$this->assertArrayNotHasKey( 'id', $result );
		$this->assertArrayNotHasKey( 'url', $result );
		$this->assertArrayNotHasKey( 'amount_total', $result );
	}

	/**
	 * @test
	 */
	public function test_propagates_wp_error_from_stripe_client(): void {
		StripeClient::$mock_response = new \WP_Error( 'stripe_error', 'Card declined' );

		$result = $this->payment_service->create_checkout_session(
			array( 'width' => 1000, 'height' => 800 ),
			'4x',
			'test@example.com',
			'job-error-test'
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'stripe_error', $result->get_error_code() );
	}

	/**
	 * @test
	 */
	public function test_passes_correct_metadata_to_stripe(): void {
		StripeClient::$mock_response = array(
			'id'           => 'cs_test_meta',
			'url'          => 'https://checkout.stripe.com/test',
			'amount_total' => 200,
		);

		$this->payment_service->create_checkout_session(
			array( 'width' => 500, 'height' => 400 ),
			'8x',
			'meta@example.com',
			'job-meta-123'
		);

		$params = StripeClient::$last_params;
		$this->assertNotNull( $params, 'StripeClient should have been called' );
		$this->assertEquals( 'job-meta-123', $params['metadata']['job_id'] );
		$this->assertEquals( '8x', $params['metadata']['resolution'] );
		$this->assertEquals( 'sell-my-images', $params['metadata']['source'] );
		$this->assertEquals( 'sell-my-images', $params['context'] );
	}

	/**
	 * @test
	 */
	public function test_sends_price_in_cents_to_stripe(): void {
		StripeClient::$mock_response = array(
			'id'           => 'cs_test_price',
			'url'          => 'https://checkout.stripe.com/test',
			'amount_total' => 500,
		);

		$this->payment_service->create_checkout_session(
			array( 'width' => 1000, 'height' => 1000 ),
			'4x',
			'price@example.com',
			'job-price-test'
		);

		$params   = StripeClient::$last_params;
		$unit_amt = $params['line_items'][0]['price_data']['unit_amount'];

		$this->assertIsInt( $unit_amt );
		$this->assertGreaterThan( 0, $unit_amt );
	}

	/**
	 * @test
	 */
	public function test_uses_usd_currency(): void {
		StripeClient::$mock_response = array(
			'id'           => 'cs_test_cur',
			'url'          => 'https://checkout.stripe.com/test',
			'amount_total' => 100,
		);

		$this->payment_service->create_checkout_session(
			array( 'width' => 500, 'height' => 500 ),
			'4x',
			null,
			'job-cur-test'
		);

		$params = StripeClient::$last_params;
		$this->assertEquals( 'usd', $params['line_items'][0]['price_data']['currency'] );
	}

	/**
	 * @test
	 */
	public function test_includes_success_and_cancel_urls(): void {
		StripeClient::$mock_response = array(
			'id'           => 'cs_test_urls',
			'url'          => 'https://checkout.stripe.com/test',
			'amount_total' => 100,
		);

		$this->payment_service->create_checkout_session(
			array( 'width' => 500, 'height' => 500 ),
			'4x',
			null,
			'job-url-test'
		);

		$params = StripeClient::$last_params;
		$this->assertStringContainsString( 'smi_payment=success', $params['success_url'] );
		$this->assertStringContainsString( 'smi_payment=cancelled', $params['cancel_url'] );
		$this->assertStringContainsString( 'job_id=job-url-test', $params['success_url'] );
	}

	/**
	 * @test
	 */
	public function test_includes_product_description_with_resolution(): void {
		StripeClient::$mock_response = array(
			'id'           => 'cs_test_desc',
			'url'          => 'https://checkout.stripe.com/test',
			'amount_total' => 100,
		);

		$this->payment_service->create_checkout_session(
			array( 'width' => 500, 'height' => 400 ),
			'4x',
			null,
			'job-desc-test'
		);

		$params       = StripeClient::$last_params;
		$product_data = $params['line_items'][0]['price_data']['product_data'];

		$this->assertStringContainsString( '4x', $product_data['name'] );
		$this->assertNotEmpty( $product_data['description'] );
	}

	/**
	 * @test
	 */
	public function test_handle_checkout_completed_updates_job_to_pending(): void {
		$job_id = 'test-job-' . wp_generate_uuid4();
		JobManager::create_job(
			array(
				'job_id'        => $job_id,
				'attachment_id' => 1,
				'resolution'    => '4x',
				'email'         => 'test@example.com',
				'status'        => 'awaiting_payment',
			)
		);

		$session = array(
			'id'               => 'cs_test_completed',
			'payment_intent'   => 'pi_test_123',
			'amount_total'     => 150,
			'metadata'         => array(
				'source' => 'sell-my-images',
				'job_id' => $job_id,
			),
			'customer_details' => array(
				'email' => 'stripe@example.com',
			),
		);

		$this->payment_service->handle_checkout_completed(
			$session,
			(object) array( 'type' => 'checkout.session.completed' )
		);

		$job = JobManager::get_job( $job_id );
		$this->assertNotWPError( $job );
		$this->assertEquals( 'pending', $job->status );
	}

	/**
	 * @test
	 */
	public function test_handle_checkout_completed_ignores_non_smi_events(): void {
		$session = array(
			'id'       => 'cs_other_plugin',
			'metadata' => array(
				'source' => 'some-other-plugin',
				'job_id' => 'other-job',
			),
		);

		// Should not throw.
		$this->payment_service->handle_checkout_completed(
			$session,
			(object) array( 'type' => 'checkout.session.completed' )
		);

		$this->assertTrue( true );
	}

	/**
	 * @test
	 */
	public function test_handle_checkout_expired_marks_job_abandoned(): void {
		$job_id = 'test-job-' . wp_generate_uuid4();
		JobManager::create_job(
			array(
				'job_id'        => $job_id,
				'attachment_id' => 1,
				'resolution'    => '4x',
				'email'         => 'test@example.com',
				'status'        => 'awaiting_payment',
			)
		);

		$this->payment_service->handle_checkout_expired(
			array(
				'id'       => 'cs_test_expired',
				'metadata' => array(
					'source' => 'sell-my-images',
					'job_id' => $job_id,
				),
			),
			(object) array( 'type' => 'checkout.session.expired' )
		);

		$job = JobManager::get_job( $job_id );
		$this->assertNotWPError( $job );
		$this->assertEquals( 'abandoned', $job->status );
	}

	/**
	 * @test
	 */
	public function test_handle_payment_failed_marks_job_failed(): void {
		$job_id = 'test-job-' . wp_generate_uuid4();
		JobManager::create_job(
			array(
				'job_id'        => $job_id,
				'attachment_id' => 1,
				'resolution'    => '4x',
				'email'         => 'test@example.com',
				'status'        => 'awaiting_payment',
			)
		);

		$this->payment_service->handle_payment_failed(
			array(
				'id'       => 'pi_test_failed',
				'metadata' => array(
					'source' => 'sell-my-images',
					'job_id' => $job_id,
				),
			),
			(object) array( 'type' => 'payment_intent.payment_failed' )
		);

		$job = JobManager::get_job( $job_id );
		$this->assertNotWPError( $job );
		$this->assertEquals( 'failed', $job->status );
	}

	/**
	 * @test
	 */
	public function test_handle_checkout_completed_backfills_email(): void {
		$job_id = 'test-job-' . wp_generate_uuid4();
		JobManager::create_job(
			array(
				'job_id'        => $job_id,
				'attachment_id' => 1,
				'resolution'    => '4x',
				'email'         => '',
				'status'        => 'awaiting_payment',
			)
		);

		$this->payment_service->handle_checkout_completed(
			array(
				'id'               => 'cs_test_email',
				'payment_intent'   => 'pi_test_email',
				'amount_total'     => 200,
				'metadata'         => array(
					'source' => 'sell-my-images',
					'job_id' => $job_id,
				),
				'customer_details' => array(
					'email' => 'backfilled@example.com',
				),
			),
			(object) array( 'type' => 'checkout.session.completed' )
		);

		$job = JobManager::get_job( $job_id );
		$this->assertNotWPError( $job );
		$this->assertEquals( 'backfilled@example.com', $job->email );
	}

	/**
	 * @test
	 */
	public function test_validate_configuration_delegates_to_stripe_client(): void {
		StripeClient::$mock_validate = true;
		$this->assertTrue( $this->payment_service->validate_configuration() );

		StripeClient::$mock_validate = new \WP_Error( 'stripe_integration_missing_key', 'No key' );
		$this->assertWPError( $this->payment_service->validate_configuration() );
	}
}
