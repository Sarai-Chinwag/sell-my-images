<?php
/**
 * PaymentService Tests
 *
 * @package SellMyImages\Tests
 */

namespace SellMyImages\Tests\Unit\Services;

use Brain\Monkey\Functions;
use SellMyImages\Services\PaymentService;
use SellMyImages\Api\StripeApi;
use SellMyImages\Config\Constants;
use Mockery;

class PaymentServiceTest extends \SMI_TestCase {

    private $stripe_api_mock;
    private $payment_service;

    protected function setUp(): void {
        parent::setUp();

        // Create mock StripeApi
        $this->stripe_api_mock = Mockery::mock( StripeApi::class );

        // Common function mocks
        Functions\when( 'get_option' )
            ->alias(
                function ( $option, $default = '' ) {
                    $options = array(
                        'smi_markup_percentage'     => 200,
                        'smi_stripe_webhook_secret' => 'whsec_test',
                    );
                    return $options[ $option ] ?? $default;
                }
            );

        Functions\when( 'home_url' )
            ->justReturn( 'https://example.com' );

        Functions\when( 'is_ssl' )
            ->justReturn( true );

        // Inject mock into PaymentService
        $this->payment_service = new PaymentService( $this->stripe_api_mock );
    }

    protected function tearDown(): void {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function create_checkout_session_returns_checkout_url(): void {
        $image_data = array(
            'width'  => 1000,
            'height' => 800,
            'src'    => 'https://example.com/image.jpg',
        );

        $expected_result = array(
            'session_id'   => 'cs_test_123',
            'checkout_url' => 'https://checkout.stripe.com/pay/cs_test_123',
            'amount'       => 1.50,
        );

        $this->stripe_api_mock
            ->shouldReceive( 'create_checkout_session' )
            ->once()
            ->andReturn( $expected_result );

        $result = $this->payment_service->create_checkout_session(
            $image_data,
            '4x',
            'customer@example.com',
            'job-uuid-1234'
        );

        $this->assertIsArray( $result );
        $this->assertEquals( 'cs_test_123', $result['session_id'] );
        $this->assertStringContainsString( 'checkout.stripe.com', $result['checkout_url'] );
    }

    /**
     * @test
     */
    public function create_checkout_session_propagates_stripe_errors(): void {
        $image_data = array(
            'width'  => 1000,
            'height' => 800,
            'src'    => 'https://example.com/image.jpg',
        );

        $this->stripe_api_mock
            ->shouldReceive( 'create_checkout_session' )
            ->once()
            ->andReturn( new \WP_Error( 'stripe_error', 'Card declined' ) );

        $result = $this->payment_service->create_checkout_session(
            $image_data,
            '4x',
            'customer@example.com',
            'job-uuid-1234'
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * @test
     */
    public function create_checkout_session_includes_job_metadata(): void {
        $image_data = array(
            'width'  => 500,
            'height' => 400,
            'src'    => 'https://example.com/test.jpg',
        );

        $this->stripe_api_mock
            ->shouldReceive( 'create_checkout_session' )
            ->once()
            ->withArgs(
                function ( $session_data ) {
                    // Verify metadata includes job_id
                    return isset( $session_data['metadata']['job_id'] )
                        && $session_data['metadata']['job_id'] === 'test-job-123'
                        && $session_data['metadata']['resolution'] === '8x'
                        && $session_data['metadata']['source'] === 'sell-my-images';
                }
            )
            ->andReturn(
                array(
                    'session_id'   => 'cs_test',
                    'checkout_url' => 'https://stripe.com',
                    'amount'       => 2.00,
                )
            );

        $result = $this->payment_service->create_checkout_session(
            $image_data,
            '8x',
            null,
            'test-job-123'
        );

        $this->assertIsArray( $result );
    }

    /**
     * @test
     */
    public function create_checkout_session_calculates_correct_price(): void {
        // 1000x1000 at 4x = 4000x4000 = 16MP
        // Credits = ceil(16 * 0.25) = 4
        // Cost = 4 * $0.04 = $0.16
        // Customer price = $0.16 * 3 = $0.48, but Stripe min is $0.50
        $image_data = array(
            'width'  => 1000,
            'height' => 1000,
        );

        $this->stripe_api_mock
            ->shouldReceive( 'create_checkout_session' )
            ->once()
            ->withArgs(
                function ( $session_data ) {
                    // unit_amount should be in cents
                    $unit_amount = $session_data['line_items'][0]['price_data']['unit_amount'];
                    // Should be at least 50 cents (Stripe minimum)
                    return $unit_amount >= 50;
                }
            )
            ->andReturn(
                array(
                    'session_id'   => 'cs_test',
                    'checkout_url' => 'https://stripe.com',
                    'amount'       => 0.50,
                )
            );

        $result = $this->payment_service->create_checkout_session(
            $image_data,
            '4x',
            'test@example.com',
            'job-123'
        );

        $this->assertIsArray( $result );
    }

    /**
     * @test
     */
    public function create_checkout_session_includes_product_description(): void {
        $image_data = array(
            'width'  => 500,
            'height' => 400,
        );

        $this->stripe_api_mock
            ->shouldReceive( 'create_checkout_session' )
            ->once()
            ->withArgs(
                function ( $session_data ) {
                    $product_data = $session_data['line_items'][0]['price_data']['product_data'];
                    // Should include resolution in name
                    $has_name = strpos( $product_data['name'], '4x' ) !== false;
                    // Should include dimensions in description
                    $has_description = isset( $product_data['description'] );
                    return $has_name && $has_description;
                }
            )
            ->andReturn(
                array(
                    'session_id'   => 'cs_test',
                    'checkout_url' => 'https://stripe.com',
                )
            );

        $result = $this->payment_service->create_checkout_session(
            $image_data,
            '4x',
            null,
            'job-123'
        );

        $this->assertIsArray( $result );
    }

    /**
     * @test
     */
    public function create_checkout_session_uses_correct_currency(): void {
        $image_data = array(
            'width'  => 1000,
            'height' => 1000,
        );

        $this->stripe_api_mock
            ->shouldReceive( 'create_checkout_session' )
            ->once()
            ->withArgs(
                function ( $session_data ) {
                    return $session_data['line_items'][0]['price_data']['currency'] === 'usd';
                }
            )
            ->andReturn(
                array(
                    'session_id'   => 'cs_test',
                    'checkout_url' => 'https://stripe.com',
                )
            );

        $result = $this->payment_service->create_checkout_session(
            $image_data,
            '4x',
            null,
            'job-123'
        );

        $this->assertIsArray( $result );
    }

    /**
     * @test
     */
    public function create_checkout_session_includes_success_and_cancel_urls(): void {
        $image_data = array(
            'width'  => 1000,
            'height' => 1000,
        );

        $this->stripe_api_mock
            ->shouldReceive( 'create_checkout_session' )
            ->once()
            ->withArgs(
                function ( $session_data ) {
                    $has_success = isset( $session_data['success_url'] )
                        && strpos( $session_data['success_url'], 'smi_payment=success' ) !== false;
                    $has_cancel = isset( $session_data['cancel_url'] )
                        && strpos( $session_data['cancel_url'], 'smi_payment=cancelled' ) !== false;
                    return $has_success && $has_cancel;
                }
            )
            ->andReturn(
                array(
                    'session_id'   => 'cs_test',
                    'checkout_url' => 'https://stripe.com',
                )
            );

        $result = $this->payment_service->create_checkout_session(
            $image_data,
            '4x',
            null,
            'job-123'
        );

        $this->assertIsArray( $result );
    }

    /**
     * @test
     */
    public function payment_service_accepts_stripe_api_via_constructor(): void {
        $custom_mock = Mockery::mock( StripeApi::class );

        // Should not throw - verifies DI works
        $service = new PaymentService( $custom_mock );

        $this->assertInstanceOf( PaymentService::class, $service );
    }

    /**
     * @test
     */
    public function create_checkout_session_works_without_email(): void {
        $image_data = array(
            'width'  => 1000,
            'height' => 800,
        );

        $this->stripe_api_mock
            ->shouldReceive( 'create_checkout_session' )
            ->once()
            ->withArgs(
                function ( $session_data ) {
                    // Verify customer_email is NOT set (Stripe collects it)
                    return ! isset( $session_data['customer_email'] );
                }
            )
            ->andReturn(
                array(
                    'session_id'   => 'cs_test_no_email',
                    'checkout_url' => 'https://checkout.stripe.com/pay/cs_test_no_email',
                )
            );

        $result = $this->payment_service->create_checkout_session(
            $image_data,
            '4x',
            null,
            'job-uuid-no-email'
        );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'checkout_url', $result );
    }

    /**
     * @test
     */
    public function create_checkout_session_accepts_email_parameter(): void {
        $image_data = array(
            'width'  => 1000,
            'height' => 800,
        );

        $this->stripe_api_mock
            ->shouldReceive( 'create_checkout_session' )
            ->once()
            ->withArgs(
                function ( $session_data ) {
                    // Verify session is created (email not forced to Stripe)
                    return isset( $session_data['line_items'] )
                        && isset( $session_data['metadata']['job_id'] );
                }
            )
            ->andReturn(
                array(
                    'session_id'   => 'cs_test_with_email',
                    'checkout_url' => 'https://checkout.stripe.com/pay/cs_test_with_email',
                )
            );

        $result = $this->payment_service->create_checkout_session(
            $image_data,
            '4x',
            'test@example.com',
            'job-uuid-with-email'
        );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'checkout_url', $result );
    }

    /**
     * @test
     * Regression test: stripe-integration returns raw Stripe keys (url, id, amount_total)
     * but SMI callers expect (checkout_url, session_id, amount).
     * Broken Feb 5-12 2026 after migration to shared stripe-integration plugin.
     */
    public function create_checkout_session_normalizes_stripe_response_keys(): void {
        $image_data = array(
            'width'  => 1000,
            'height' => 800,
            'src'    => 'https://example.com/image.jpg',
        );

        // Mock StripeClient to return raw Stripe response format
        $raw_stripe_response = array(
            'id'           => 'cs_live_abc123',
            'url'          => 'https://checkout.stripe.com/c/pay/cs_live_abc123',
            'amount_total' => 130, // Stripe returns cents
            'object'       => 'checkout.session',
            'status'       => 'open',
        );

        $this->stripe_api_mock
            ->shouldReceive( 'create_checkout_session' )
            ->once()
            ->andReturn( $raw_stripe_response );

        $result = $this->payment_service->create_checkout_session(
            $image_data,
            '4x',
            'test@example.com',
            'job-regression-test'
        );

        $this->assertIsArray( $result );

        // These are the keys RestApi.php expects
        $this->assertArrayHasKey( 'session_id', $result, 'Response must include session_id (mapped from Stripe id)' );
        $this->assertArrayHasKey( 'checkout_url', $result, 'Response must include checkout_url (mapped from Stripe url)' );
        $this->assertArrayHasKey( 'amount', $result, 'Response must include amount (mapped from Stripe amount_total)' );

        // Values must be correct
        $this->assertEquals( 'cs_live_abc123', $result['session_id'] );
        $this->assertEquals( 'https://checkout.stripe.com/c/pay/cs_live_abc123', $result['checkout_url'] );
        $this->assertEquals( 1.30, $result['amount'], 'Amount must be converted from cents to dollars' );

        // Must NOT contain raw Stripe keys (callers don't expect them)
        $this->assertArrayNotHasKey( 'id', $result, 'Raw Stripe key "id" should not leak through' );
        $this->assertArrayNotHasKey( 'url', $result, 'Raw Stripe key "url" should not leak through' );
        $this->assertArrayNotHasKey( 'amount_total', $result, 'Raw Stripe key "amount_total" should not leak through' );
    }
}
