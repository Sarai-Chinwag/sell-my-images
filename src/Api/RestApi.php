<?php
/**
 * REST API Class
 * 
 * Handles WordPress REST API endpoints for the plugin
 * 
 * @package SellMyImages
 * @since 1.0.0
 */

namespace SellMyImages\Api;

use SellMyImages\Managers\JobManager;
use SellMyImages\Managers\DownloadManager;
use SellMyImages\Managers\AnalyticsTracker;
use SellMyImages\Managers\UploadManager;
use SellMyImages\Abilities\UpscaleAbilities;
use SellMyImages\Api\CostCalculator;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RestApi class
 */
class RestApi {
    
    /**
     * API namespace
     */
    const NAMESPACE = 'smi/v1';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
    }
    
    /**
     * Register REST API endpoints
     */
    public function register_endpoints() {
        
        // Calculate pricing for all resolutions at once
        register_rest_route( self::NAMESPACE, '/calculate-all-prices', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'calculate_all_prices' ),
            'permission_callback' => '__return_true', // Public endpoint
            'args'                => array(
                'attachment_id' => array(
                    'required'    => true,
                    'type'        => 'integer',
                    'description' => 'WordPress attachment ID for Gutenberg block images',
                ),
                'post_id' => array(
                    'required'    => true,
                    'type'        => 'integer',
                    'description' => 'WordPress post ID where the image appears',
                ),
            ),
        ) );
        
        // Create checkout session (replaces old direct upscaling)
    register_rest_route( self::NAMESPACE, '/create-checkout', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_checkout' ),
            'permission_callback' => '__return_true', // Public endpoint
            'args'                => array(
                'attachment_id' => array(
                    'required'    => true,
                    'type'        => 'integer',
                    'description' => 'WordPress attachment ID for Gutenberg block images',
                ),
                'post_id' => array(
                    'required'    => true,
                    'type'        => 'integer',
                    'description' => 'WordPress post ID where the image appears',
                ),
                'resolution' => array(
                    'required'    => true,
                    'type'        => 'string',
                    'enum'        => array( '4x', '8x' ),
                    'description' => 'Resolution multiplier',
                ),
                'email'      => array(
                    'required'    => false,
                    'type'        => 'string',
                    'format'      => 'email',
                    'description' => 'Email address for notification (optional; backfilled from Stripe if not provided)',
                ),
            ),
        ) );
        
        // Track button click for analytics
        register_rest_route( self::NAMESPACE, '/track-button-click', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'track_button_click' ),
            'permission_callback' => '__return_true', // Public endpoint
            'args'                => array(
                'attachment_id' => array(
                    'required'    => true,
                    'type'        => 'integer',
                    'description' => 'WordPress attachment ID for the clicked button',
                ),
                'post_id' => array(
                    'required'    => true,
                    'type'        => 'integer',
                    'description' => 'WordPress post ID where the button was clicked',
                ),
            ),
        ) );
        
        // Download processed image with token
        register_rest_route( self::NAMESPACE, '/download/(?P<token>[a-zA-Z0-9]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'download_image' ),
            'permission_callback' => '__return_true', // Public endpoint
            'args'                => array(
                'token' => array(
                    'required'    => true,
                    'type'        => 'string',
                    'description' => 'Download token for the processed image',
                ),
            ),
        ) );
        
        // Get job status for polling
        register_rest_route( self::NAMESPACE, '/job-status/(?P<job_id>[a-f0-9\-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_job_status' ),
            'permission_callback' => '__return_true', // Public endpoint
            'args'                => array(
                'job_id' => array(
                    'required'    => true,
                    'type'        => 'string',
                    'description' => 'Job ID to check status for',
                ),
            ),
        ) );
        
        // Upload image endpoint
        register_rest_route( self::NAMESPACE, '/upload-image', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'upload_image' ),
            'permission_callback' => '__return_true', // Public endpoint
        ) );
        
        // Create checkout for uploaded image
        register_rest_route( self::NAMESPACE, '/create-checkout-upload', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_checkout_upload' ),
            'permission_callback' => '__return_true', // Public endpoint
            'args'                => array(
                'upload_id' => array(
                    'required'    => true,
                    'type'        => 'string',
                    'description' => 'Upload ID from /upload-image endpoint',
                ),
                'resolution' => array(
                    'required'    => true,
                    'type'        => 'string',
                    'enum'        => array( '4x', '8x' ),
                    'description' => 'Resolution multiplier',
                ),
                'email'      => array(
                    'required'    => false,
                    'type'        => 'string',
                    'format'      => 'email',
                    'description' => 'Email address for notification (optional; backfilled from Stripe if not provided)',
                ),
            ),
        ) );
        
    }
    
    
    /**
     * Calculate pricing for all resolutions at once
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function calculate_all_prices( $request ) {
        // Check if plugin is enabled
        if ( ! get_option( 'smi_enabled', '1' ) ) {
            return new \WP_Error(
                'plugin_disabled',
                __( 'Plugin is currently disabled', 'sell-my-images' ),
                array( 'status' => 503 )
            );
        }
        
        $attachment_id = $request->get_param( 'attachment_id' );
        $post_id = $request->get_param( 'post_id' );
        
        // Require attachment ID (Gutenberg blocks only)
        if ( ! $attachment_id ) {
            return new \WP_Error(
                'missing_attachment_id',
                __( 'Attachment ID is required for Gutenberg block images', 'sell-my-images' ),
                array( 'status' => 400 )
            );
        }
        
        // Require post ID for analytics
        if ( ! $post_id ) {
            return new \WP_Error(
                'missing_post_id',
                __( 'Post ID is required for analytics tracking', 'sell-my-images' ),
                array( 'status' => 400 )
            );
        }
        
        $image_data = $this->get_image_data_from_attachment( $attachment_id );
        if ( is_wp_error( $image_data ) ) {
            return $image_data;
        }
        
        // Calculate pricing for all resolutions
        $resolutions = array( '4x', '8x' );
        $pricing_data = array();
        
        foreach ( $resolutions as $resolution ) {
            $cost_data = CostCalculator::calculate_cost_detailed( $image_data, $resolution );
            $pricing_data[$resolution] = $cost_data;
        }
        
        return new \WP_REST_Response( array(
            'success' => true,
            'pricing' => $pricing_data,
            'image_info' => array(
                'width' => $image_data['width'],
                'height' => $image_data['height'],
            ),
        ), 200 );
    }
    
    /**
     * Create checkout session for payment
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function create_checkout( $request ) {
        // Check if plugin is enabled
        if ( ! get_option( 'smi_enabled', '1' ) ) {
            return new \WP_Error(
                'plugin_disabled',
                __( 'Plugin is currently disabled', 'sell-my-images' ),
                array( 'status' => 503 )
            );
        }
        
        // Check if Stripe is configured using PaymentService
        $payment_service = new \SellMyImages\Services\PaymentService();
        $stripe_config = $payment_service->validate_configuration();
        if ( is_wp_error( $stripe_config ) ) {
            return new \WP_Error(
                'payment_not_configured',
                __( 'Payment system not configured: ', 'sell-my-images' ) . $stripe_config->get_error_message(),
                array( 'status' => 500 )
            );
        }
        
        $attachment_id = $request->get_param( 'attachment_id' );
        $post_id = $request->get_param( 'post_id' );
        $resolution = $request->get_param( 'resolution' );
        $email = $request->get_param( 'email' );
        if ( $email ) {
            $email = sanitize_email( $email );
        } else {
            $email = null; // optional, will be backfilled from Stripe
        }
        
        // Require attachment ID (Gutenberg blocks only)
        if ( ! $attachment_id ) {
            return new \WP_Error(
                'missing_attachment_id',
                __( 'Attachment ID is required for Gutenberg block images', 'sell-my-images' ),
                array( 'status' => 400 )
            );
        }
        
        // Require post ID for analytics
        if ( ! $post_id ) {
            return new \WP_Error(
                'missing_post_id',
                __( 'Post ID is required for analytics tracking', 'sell-my-images' ),
                array( 'status' => 400 )
            );
        }
        
        // Get image data from attachment
        $image_data = $this->get_image_data_from_attachment( $attachment_id );
        if ( is_wp_error( $image_data ) ) {
            return $image_data;
        }
        
        // Create job using shared UpscaleAbilities method
        $job_result = UpscaleAbilities::create_upscale_job( array(
            'attachment_id' => $attachment_id,
            'resolution'    => $resolution,
            'email'         => $email,
            'post_id'       => $post_id,
        ) );
        
        if ( is_wp_error( $job_result ) ) {
            return $job_result;
        }
        
        $job_data = array( 'job_id' => $job_result['job_id'] );
        
        // Store cost data in job record
        $cost_data = CostCalculator::calculate_cost_detailed( $image_data, $resolution );
        JobManager::update_cost_data( $job_data['job_id'], $cost_data );
        
        // Create Stripe checkout session using PaymentService
        $checkout_result = $payment_service->create_checkout_session( 
            $image_data, 
            $resolution, 
            $email, 
            $job_data['job_id'] 
        );
        
        if ( is_wp_error( $checkout_result ) ) {
            // Clean up job record on failure
            JobManager::delete_job( $job_data['job_id'] );
            return $checkout_result;
        }
        
        // Update job with checkout session ID
        JobManager::update_checkout_session( $job_data['job_id'], $checkout_result['session_id'] );
        
        return new \WP_REST_Response( array(
            'success' => true,
            'job_id' => $job_data['job_id'],
            'checkout_url' => $checkout_result['checkout_url'],
            'amount' => $checkout_result['amount'],
            'message' => __( 'Redirecting to payment...', 'sell-my-images' ),
        ), 200 );
    }
    
    /**
     * Track button click for analytics
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function track_button_click( $request ) {
        // Check if plugin is enabled
        if ( ! get_option( 'smi_enabled', '1' ) ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'message' => __( 'Plugin is currently disabled', 'sell-my-images' ),
            ), 503 );
        }
        
        $attachment_id = $request->get_param( 'attachment_id' );
        $post_id = $request->get_param( 'post_id' );
        
        // Validate required parameters
        if ( ! $attachment_id ) {
            return new \WP_Error(
                'missing_attachment_id',
                __( 'Attachment ID is required', 'sell-my-images' ),
                array( 'status' => 400 )
            );
        }
        
        if ( ! $post_id ) {
            return new \WP_Error(
                'missing_post_id',
                __( 'Post ID is required', 'sell-my-images' ),
                array( 'status' => 400 )
            );
        }
        
        // Track the click using AnalyticsTracker
        $result = AnalyticsTracker::track_button_click( $post_id, $attachment_id );
        
        if ( $result ) {
            return new \WP_REST_Response( array(
                'success' => true,
                'message' => __( 'Click tracked successfully', 'sell-my-images' ),
            ), 200 );
        } else {
            // Return success even if tracking fails to not disrupt user experience
            return new \WP_REST_Response( array(
                'success' => true,
                'message' => __( 'Click tracking attempted', 'sell-my-images' ),
            ), 200 );
        }
    }
    
    /**
     * Download processed image
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function download_image( $request ) {
        $token = $request->get_param( 'token' );
        
        // Delegate to DownloadManager for centralized download handling
        DownloadManager::serve_download( $token );
        
        // This won't be reached as serve_download exits
        exit;
    }
    
    /**
     * Get job status for polling
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function get_job_status( $request ) {
        $job_id = $request->get_param( 'job_id' );
        
        if ( ! $job_id ) {
            return new \WP_Error(
                'missing_job_id',
                __( 'Job ID is required', 'sell-my-images' ),
                array( 'status' => 400 )
            );
        }
        
        // Get job data using JobManager
        $job = JobManager::get_job( $job_id );
        
        if ( is_wp_error( $job ) ) {
            return new \WP_Error(
                'job_not_found',
                __( 'Job not found', 'sell-my-images' ),
                array( 'status' => 404 )
            );
        }
        
        // Convert stdClass to array if needed
        if ( is_object( $job ) ) {
            $job = (array) $job;
        }
        
        // Prepare response data
        $response_data = array(
            'job_id' => $job['job_id'],
            'status' => $job['status'],
            'payment_status' => $job['payment_status'],
            'resolution' => $job['resolution'],
            'created_at' => $job['created_at'],
        );
        
        // Add download URL if job is completed and has a download token
        if ( $job['status'] === 'completed' && ! empty( $job['download_token'] ) ) {
            $response_data['download_url'] = home_url( '/wp-json/smi/v1/download/' . $job['download_token'] );
        }
        
        // Note: Detailed failure reasons are logged for debugging but not exposed to users
        
        return new \WP_REST_Response( array(
            'success' => true,
            'data' => $response_data,
        ), 200 );
    }
    
    /**
     * Get image data from WordPress attachment ID
     * 
     * @param int $attachment_id WordPress attachment ID
     * @return array|WP_Error Image data or error
     */
    private function get_image_data_from_attachment( $attachment_id ) {
        $attachment_id = intval( $attachment_id );
        
        // Verify attachment exists and is an image
        if ( ! wp_attachment_is_image( $attachment_id ) ) {
            return new \WP_Error(
                'invalid_attachment',
                __( 'Invalid image attachment', 'sell-my-images' ),
                array( 'status' => 400 )
            );
        }
        
        $image_meta = wp_get_attachment_metadata( $attachment_id );
        $image_url = wp_get_attachment_url( $attachment_id );
        
        if ( ! $image_meta || ! $image_url ) {
            return new \WP_Error(
                'attachment_data_error',
                __( 'Unable to get attachment data', 'sell-my-images' ),
                array( 'status' => 400 )
            );
        }
        
        return array(
            'attachment_id' => $attachment_id,
            'src'           => $image_url,
            'width'         => $image_meta['width'],
            'height'        => $image_meta['height'],
        );
    }
    
    /**
     * Upload image endpoint
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function upload_image( $request ) {
        // Check if plugin is enabled
        if ( ! get_option( 'smi_enabled', '1' ) ) {
            return new \WP_Error(
                'plugin_disabled',
                __( 'Plugin is currently disabled', 'sell-my-images' ),
                array( 'status' => 503 )
            );
        }
        
        // Get uploaded file from request
        $files = $request->get_file_params();
        
        if ( empty( $files['image'] ) ) {
            return new \WP_Error(
                'no_file_provided',
                __( 'No image file was uploaded', 'sell-my-images' ),
                array( 'status' => 400 )
            );
        }
        
        // Upload and validate image
        $upload_result = UploadManager::upload_image( $files['image'] );
        
        if ( is_wp_error( $upload_result ) ) {
            return $upload_result;
        }
        
        // Calculate pricing for all resolutions
        $image_data = array(
            'width' => $upload_result['width'],
            'height' => $upload_result['height'],
        );
        
        $resolutions = array( '4x', '8x' );
        $pricing_data = array();
        
        foreach ( $resolutions as $resolution ) {
            $cost_data = CostCalculator::calculate_cost_detailed( $image_data, $resolution );
            $pricing_data[$resolution] = $cost_data;
        }
        
        return new \WP_REST_Response( array(
            'success' => true,
            'upload_id' => $upload_result['upload_id'],
            'pricing' => $pricing_data,
            'image_info' => array(
                'width' => $upload_result['width'],
                'height' => $upload_result['height'],
                'file_size' => $upload_result['file_size'],
            ),
        ), 200 );
    }
    
    /**
     * Create checkout session for uploaded image
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function create_checkout_upload( $request ) {
        // Check if plugin is enabled
        if ( ! get_option( 'smi_enabled', '1' ) ) {
            return new \WP_Error(
                'plugin_disabled',
                __( 'Plugin is currently disabled', 'sell-my-images' ),
                array( 'status' => 503 )
            );
        }
        
        // Check if Stripe is configured using PaymentService
        $payment_service = new \SellMyImages\Services\PaymentService();
        $stripe_config = $payment_service->validate_configuration();
        if ( is_wp_error( $stripe_config ) ) {
            return new \WP_Error(
                'payment_not_configured',
                __( 'Payment system not configured: ', 'sell-my-images' ) . $stripe_config->get_error_message(),
                array( 'status' => 500 )
            );
        }
        
        $upload_id = $request->get_param( 'upload_id' );
        $resolution = $request->get_param( 'resolution' );
        $email = $request->get_param( 'email' );
        if ( $email ) {
            $email = sanitize_email( $email );
        } else {
            $email = null;
        }
        
        // Get upload data for pricing calculation
        $upload = UploadManager::get_upload( $upload_id );
        if ( is_wp_error( $upload ) ) {
            return $upload;
        }
        
        // Convert upload data to image data format for pricing
        $image_data = array(
            'width' => $upload['width'],
            'height' => $upload['height'],
        );
        
        // Create job using shared UpscaleAbilities method
        $job_result = UpscaleAbilities::create_upscale_job( array(
            'upload_id'  => $upload_id,
            'resolution' => $resolution,
            'email'      => $email,
            'post_id'    => 0, // No post associated with uploads
        ) );
        
        if ( is_wp_error( $job_result ) ) {
            return $job_result;
        }
        
        $job_data = array( 'job_id' => $job_result['job_id'] );
        
        // Store cost data in job record
        $cost_data = CostCalculator::calculate_cost_detailed( $image_data, $resolution );
        JobManager::update_cost_data( $job_data['job_id'], $cost_data );
        
        // Create Stripe checkout session using PaymentService
        $checkout_result = $payment_service->create_checkout_session( 
            $image_data, 
            $resolution, 
            $email, 
            $job_data['job_id'] 
        );
        
        if ( is_wp_error( $checkout_result ) ) {
            // Clean up job record on failure
            JobManager::delete_job( $job_data['job_id'] );
            return $checkout_result;
        }
        
        // Update job with checkout session ID
        JobManager::update_checkout_session( $job_data['job_id'], $checkout_result['session_id'] );
        
        return new \WP_REST_Response( array(
            'success' => true,
            'job_id' => $job_data['job_id'],
            'checkout_url' => $checkout_result['checkout_url'],
            'amount' => $checkout_result['amount'],
            'message' => __( 'Redirecting to payment...', 'sell-my-images' ),
        ), 200 );
    }
}