<?php
/**
 * UpscaleAbilities Tests
 *
 * @package SellMyImages\Tests
 */

namespace SellMyImages\Tests\Unit\Abilities;

use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use SellMyImages\Abilities\UpscaleAbilities;
use SellMyImages\Managers\JobManager;
use SellMyImages\Managers\UploadManager;

class UpscaleAbilitiesTest extends \SMI_TestCase {

    protected function setUp(): void {
        parent::setUp();

        // Mock WordPress functions commonly used
        Functions\when( 'get_option' )
            ->justReturn( 'admin@example.com' );

        Functions\when( 'sanitize_text_field' )
            ->alias( 
                function ( $input ) {
                    return trim( (string) $input );
                }
            );

        Functions\when( 'sanitize_email' )
            ->alias(
                function ( $input ) {
                    return filter_var( $input, FILTER_SANITIZE_EMAIL );
                }
            );

        Functions\when( 'absint' )
            ->alias( 
                function ( $input ) {
                    return abs( (int) $input );
                }
            );

        Functions\when( 'wp_attachment_is_image' )
            ->justReturn( true );

        Functions\when( 'wp_get_attachment_url' )
            ->justReturn( 'https://example.com/image.jpg' );

        Functions\when( 'wp_get_attachment_metadata' )
            ->justReturn( array(
                'width' => 1000,
                'height' => 800,
            ) );

        Functions\when( 'wp_upload_dir' )
            ->justReturn( array(
                'basedir' => '/var/www/uploads',
                'baseurl' => 'https://example.com/uploads',
            ) );

        Functions\when( 'do_action' )
            ->justReturn( null );

        Functions\when( '__' )
            ->alias(
                function ( $text ) {
                    return $text;
                }
            );

        // Mock JobManager methods
        Functions\when( 'SellMyImages\Managers\JobManager::create_job' )
            ->justReturn( array( 'job_id' => 'test-job-123' ) );

        Functions\when( 'SellMyImages\Managers\JobManager::update_payment_status' )
            ->justReturn( true );

        // Mock UploadManager methods
        Functions\when( 'SellMyImages\Managers\UploadManager::get_upload' )
            ->justReturn( array(
                'file_path' => '/var/www/uploads/test-upload.jpg',
                'width' => 1200,
                'height' => 900,
            ) );
    }

    /**
     * @test
     */
    public function upscale_image_requires_id_parameter(): void {
        $result = UpscaleAbilities::upscale_image( array(
            'resolution' => '4x',
        ) );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'Either attachment_id or upload_id must be provided', $result['error'] );
    }

    /**
     * @test
     */
    public function upscale_image_rejects_both_id_parameters(): void {
        $result = UpscaleAbilities::upscale_image( array(
            'attachment_id' => 123,
            'upload_id' => 'test-upload',
            'resolution' => '4x',
        ) );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'Cannot provide both attachment_id and upload_id', $result['error'] );
    }

    /**
     * @test
     */
    public function upscale_image_requires_resolution(): void {
        $result = UpscaleAbilities::upscale_image( array(
            'attachment_id' => 123,
        ) );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'Resolution parameter is required', $result['error'] );
    }

    /**
     * @test
     */
    public function upscale_image_handles_attachment_id(): void {
        $result = UpscaleAbilities::upscale_image( array(
            'attachment_id' => 123,
            'resolution' => '4x',
        ) );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertArrayHasKey( 'job_id', $result );
        $this->assertEquals( 'test-job-123', $result['job_id'] );
        $this->assertEquals( 'processing', $result['status'] );
        $this->assertEquals( 'site', $result['source_type'] );
    }

    /**
     * @test
     */
    public function upscale_image_handles_upload_id(): void {
        $result = UpscaleAbilities::upscale_image( array(
            'upload_id' => 'test-upload',
            'resolution' => '4x',
        ) );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertArrayHasKey( 'job_id', $result );
        $this->assertEquals( 'test-job-123', $result['job_id'] );
        $this->assertEquals( 'processing', $result['status'] );
        $this->assertEquals( 'upload', $result['source_type'] );
    }

    /**
     * @test
     */
    public function create_upscale_job_validates_parameters(): void {
        $result = UpscaleAbilities::create_upscale_job( array() );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'missing_id', $result->get_error_code() );
    }

    /**
     * @test
     */
    public function create_upscale_job_rejects_conflicting_ids(): void {
        $result = UpscaleAbilities::create_upscale_job( array(
            'attachment_id' => 123,
            'upload_id' => 'test-upload',
            'resolution' => '4x',
        ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'conflicting_ids', $result->get_error_code() );
    }

    /**
     * @test
     */
    public function create_upscale_job_requires_resolution(): void {
        $result = UpscaleAbilities::create_upscale_job( array(
            'attachment_id' => 123,
        ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'missing_resolution', $result->get_error_code() );
    }

    /**
     * @test
     */
    public function create_upscale_job_validates_attachment_exists(): void {
        Functions\when( 'wp_attachment_is_image' )
            ->justReturn( false );

        $result = UpscaleAbilities::create_upscale_job( array(
            'attachment_id' => 123,
            'resolution' => '4x',
        ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'invalid_attachment', $result->get_error_code() );
    }

    /**
     * @test
     */
    public function create_upscale_job_handles_attachment_data_error(): void {
        Functions\when( 'wp_get_attachment_url' )
            ->justReturn( false );

        $result = UpscaleAbilities::create_upscale_job( array(
            'attachment_id' => 123,
            'resolution' => '4x',
        ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'attachment_data_error', $result->get_error_code() );
    }

    /**
     * @test
     */
    public function create_upscale_job_handles_upload_error(): void {
        Functions\when( 'SellMyImages\Managers\UploadManager::get_upload' )
            ->justReturn( new \WP_Error( 'upload_not_found', 'Upload not found' ) );

        $result = UpscaleAbilities::create_upscale_job( array(
            'upload_id' => 'invalid-upload',
            'resolution' => '4x',
        ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'upload_not_found', $result->get_error_code() );
    }

    /**
     * @test
     */
    public function create_upscale_job_successful_with_attachment(): void {
        $result = UpscaleAbilities::create_upscale_job( array(
            'attachment_id' => 123,
            'resolution' => '4x',
            'email' => 'user@example.com',
            'post_id' => 456,
        ) );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'job_id', $result );
        $this->assertArrayHasKey( 'source_type', $result );
        $this->assertEquals( 'test-job-123', $result['job_id'] );
        $this->assertEquals( 'site', $result['source_type'] );
    }

    /**
     * @test
     */
    public function create_upscale_job_successful_with_upload(): void {
        $result = UpscaleAbilities::create_upscale_job( array(
            'upload_id' => 'test-upload',
            'resolution' => '8x',
            'email' => 'user@example.com',
        ) );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'job_id', $result );
        $this->assertArrayHasKey( 'source_type', $result );
        $this->assertEquals( 'test-job-123', $result['job_id'] );
        $this->assertEquals( 'upload', $result['source_type'] );
    }

    /**
     * @test
     */
    public function trigger_upscaling_for_job_handles_payment_failure(): void {
        Functions\when( 'SellMyImages\Managers\JobManager::update_payment_status' )
            ->justReturn( new \WP_Error( 'payment_failed', 'Payment update failed' ) );

        $result = UpscaleAbilities::trigger_upscaling_for_job( 'test-job-123' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'payment_status_failed', $result->get_error_code() );
        $this->assertStringContainsString( 'Failed to update payment status', $result->get_error_message() );
    }

    /**
     * @test
     */
    public function trigger_upscaling_for_job_successful(): void {
        Actions\expectDone( 'smi_payment_completed' )
            ->once()
            ->with( 'test-job-123', array( 'admin_override' => true ) );

        $result = UpscaleAbilities::trigger_upscaling_for_job( 'test-job-123', array( 'admin_override' => true ) );

        $this->assertTrue( $result );
    }

    /**
     * @test
     */
    public function upscale_image_fires_payment_completed_action(): void {
        Actions\expectDone( 'smi_payment_completed' )
            ->once()
            ->with( 'test-job-123', array( 'admin_override' => true ) );

        UpscaleAbilities::upscale_image( array(
            'attachment_id' => 123,
            'resolution' => '4x',
        ) );
    }

    /**
     * @test
     */
    public function create_upscale_job_uses_provided_email(): void {
        Functions\expect( 'SellMyImages\Managers\JobManager::create_job' )
            ->once()
            ->with( \Mockery::on( function ( $job_data ) {
                return $job_data['email'] === 'custom@example.com';
            } ) )
            ->andReturn( array( 'job_id' => 'test-job-123' ) );

        UpscaleAbilities::create_upscale_job( array(
            'attachment_id' => 123,
            'resolution' => '4x',
            'email' => 'custom@example.com',
        ) );
    }

    /**
     * @test
     */
    public function create_upscale_job_falls_back_to_admin_email(): void {
        Functions\expect( 'SellMyImages\Managers\JobManager::create_job' )
            ->once()
            ->with( \Mockery::on( function ( $job_data ) {
                return $job_data['email'] === 'admin@example.com';
            } ) )
            ->andReturn( array( 'job_id' => 'test-job-123' ) );

        UpscaleAbilities::create_upscale_job( array(
            'attachment_id' => 123,
            'resolution' => '4x',
        ) );
    }

    /**
     * @test
     */
    public function create_upscale_job_uses_provided_post_id(): void {
        Functions\expect( 'SellMyImages\Managers\JobManager::create_job' )
            ->once()
            ->with( \Mockery::on( function ( $job_data ) {
                return $job_data['post_id'] === 789;
            } ) )
            ->andReturn( array( 'job_id' => 'test-job-123' ) );

        UpscaleAbilities::create_upscale_job( array(
            'attachment_id' => 123,
            'resolution' => '4x',
            'post_id' => 789,
        ) );
    }

    /**
     * @test
     */
    public function create_upscale_job_defaults_post_id_to_zero(): void {
        Functions\expect( 'SellMyImages\Managers\JobManager::create_job' )
            ->once()
            ->with( \Mockery::on( function ( $job_data ) {
                return $job_data['post_id'] === 0;
            } ) )
            ->andReturn( array( 'job_id' => 'test-job-123' ) );

        UpscaleAbilities::create_upscale_job( array(
            'upload_id' => 'test-upload',
            'resolution' => '4x',
        ) );
    }

    /**
     * @test
     */
    public function create_upscale_job_converts_upload_path_to_url(): void {
        Functions\expect( 'SellMyImages\Managers\JobManager::create_job' )
            ->once()
            ->with( \Mockery::on( function ( $job_data ) {
                return $job_data['image_url'] === 'https://example.com/uploads/test-upload.jpg';
            } ) )
            ->andReturn( array( 'job_id' => 'test-job-123' ) );

        UpscaleAbilities::create_upscale_job( array(
            'upload_id' => 'test-upload',
            'resolution' => '4x',
        ) );
    }
}