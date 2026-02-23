<?php
/**
 * SMI Upscale Abilities
 * 
 * WordPress Abilities API integration for direct image upscaling.
 * Provides the core primitive for triggering image upscaling without payment flow.
 *
 * @package SellMyImages\Abilities
 */

namespace SellMyImages\Abilities;

use SellMyImages\Managers\JobManager;
use SellMyImages\Managers\UploadManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UpscaleAbilities {

    /**
     * Initialize abilities registration
     */
    public static function init(): void {
        if ( did_action( 'wp_abilities_api_init' ) ) {
            self::register_abilities();
        } else {
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
        }
    }

    /**
     * Register all upscale abilities
     */
    public static function register_abilities(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        wp_register_ability(
            'sell-my-images/upscale-image',
            array(
                'label'               => __( 'Upscale Image', 'sell-my-images' ),
                'description'         => __( 'Upscale a WordPress attachment image or uploaded file at a specified resolution. Creates a tracked job and triggers the upscaling process.', 'sell-my-images' ),
                'category'            => 'content',
                'execute_callback'    => array( __CLASS__, 'upscale_image' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'attachment_id' => array(
                            'type'        => 'integer',
                            'description' => __( 'WordPress attachment ID for site images', 'sell-my-images' ),
                        ),
                        'upload_id' => array(
                            'type'        => 'string',
                            'description' => __( 'Upload ID from BYOI upload flow', 'sell-my-images' ),
                        ),
                        'resolution' => array(
                            'type'        => 'string',
                            'description' => __( 'Upscale factor', 'sell-my-images' ),
                            'enum'        => array( '2x', '4x', '8x' ),
                        ),
                    ),
                    'required' => array( 'resolution' ),
                ),
                'output_schema'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'job_id' => array( 
                            'type' => 'string',
                            'description' => __( 'Created job ID', 'sell-my-images' ),
                        ),
                        'status' => array( 
                            'type' => 'string',
                            'description' => __( 'Job status after creation', 'sell-my-images' ),
                        ),
                        'message' => array( 
                            'type' => 'string',
                            'description' => __( 'Success message', 'sell-my-images' ),
                        ),
                        'source_type' => array( 
                            'type' => 'string',
                            'description' => __( 'Image source type (site or upload)', 'sell-my-images' ),
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Permission callback
     */
    public static function can_manage(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Upscale image ability callback - The core primitive for upscaling
     */
    public static function upscale_image( array $input ): array {
        // Create job using shared method
        $job_result = self::create_upscale_job( $input );
        
        if ( is_wp_error( $job_result ) ) {
            return array(
                'error' => $job_result->get_error_message(),
            );
        }
        
        $job_id = $job_result['job_id'];
        $source_type = $job_result['source_type'];
        
        // Mark as paid to bypass payment flow and trigger upscaling
        $upscale_result = self::trigger_upscaling_for_job( $job_id, array( 'admin_override' => true ) );
        
        if ( is_wp_error( $upscale_result ) ) {
            return array(
                'error' => $upscale_result->get_error_message(),
            );
        }

        return array(
            'job_id'      => $job_id,
            'status'      => 'processing',
            'message'     => __( 'Image upscaling job created and started successfully', 'sell-my-images' ),
            'source_type' => $source_type,
        );
    }

    /**
     * Create upscaling job - Shared primitive for job creation
     * 
     * @param array $input Input parameters (attachment_id OR upload_id + resolution)
     * @return array|\WP_Error Job creation result or error
     */
    public static function create_upscale_job( array $input ): array|\WP_Error {
        // Validate that exactly one of attachment_id or upload_id is provided
        $has_attachment_id = ! empty( $input['attachment_id'] );
        $has_upload_id = ! empty( $input['upload_id'] );
        
        if ( ! $has_attachment_id && ! $has_upload_id ) {
            return new \WP_Error(
                'missing_id',
                __( 'Either attachment_id or upload_id must be provided', 'sell-my-images' )
            );
        }
        
        if ( $has_attachment_id && $has_upload_id ) {
            return new \WP_Error(
                'conflicting_ids',
                __( 'Cannot provide both attachment_id and upload_id', 'sell-my-images' )
            );
        }
        
        // Validate resolution is provided
        if ( empty( $input['resolution'] ) ) {
            return new \WP_Error(
                'missing_resolution',
                __( 'Resolution parameter is required', 'sell-my-images' )
            );
        }

        $resolution = sanitize_text_field( $input['resolution'] );
        $source_type = $has_attachment_id ? 'site' : 'upload';

        // Handle attachment_id path
        if ( $has_attachment_id ) {
            $attachment_id = absint( $input['attachment_id'] );

            // Validate attachment exists and is an image
            if ( ! wp_attachment_is_image( $attachment_id ) ) {
                return new \WP_Error(
                    'invalid_attachment',
                    __( 'Attachment does not exist or is not an image', 'sell-my-images' )
                );
            }

            // Get image URL and metadata
            $image_url = wp_get_attachment_url( $attachment_id );
            $metadata = wp_get_attachment_metadata( $attachment_id );

            if ( ! $image_url || ! $metadata ) {
                return new \WP_Error(
                    'attachment_data_error',
                    __( 'Could not retrieve image URL or metadata', 'sell-my-images' )
                );
            }

            // Prepare job data for site image
            $job_data = array(
                'image_url'      => $image_url,
                'resolution'     => $resolution,
                'email'          => $input['email'] ?? get_option( 'admin_email' ),
                'post_id'        => $input['post_id'] ?? 0,
                'attachment_id'  => $attachment_id,
                'image_width'    => isset( $metadata['width'] ) ? intval( $metadata['width'] ) : null,
                'image_height'   => isset( $metadata['height'] ) ? intval( $metadata['height'] ) : null,
                'source_type'    => 'site',
            );

        } else {
            // Handle upload_id path
            $upload_id = sanitize_text_field( $input['upload_id'] );

            // Get upload data
            $upload_data = UploadManager::get_upload( $upload_id );

            if ( is_wp_error( $upload_data ) ) {
                return $upload_data;
            }

            // Convert file path to URL using wp_upload_dir basedir/baseurl replacement
            $wp_upload_dir = wp_upload_dir();
            $base_dir = $wp_upload_dir['basedir'];
            $base_url = $wp_upload_dir['baseurl'];
            $image_url = str_replace( $base_dir, $base_url, $upload_data['file_path'] );

            // Prepare job data for upload
            $job_data = array(
                'image_url'        => $image_url,
                'resolution'       => $resolution,
                'email'            => $input['email'] ?? get_option( 'admin_email' ),
                'post_id'          => $input['post_id'] ?? 0,
                'image_width'      => $upload_data['width'],
                'image_height'     => $upload_data['height'],
                'source_type'      => 'upload',
                'upload_file_path' => $upload_data['file_path'],
            );
        }

        // Create job using JobManager
        $job_result = JobManager::create_job( $job_data );

        if ( is_wp_error( $job_result ) ) {
            return $job_result;
        }

        return array(
            'job_id' => $job_result['job_id'],
            'source_type' => $source_type,
        );
    }

    /**
     * Trigger upscaling for an existing job - Lower-level primitive
     * 
     * @param string $job_id Job ID
     * @param array $context Context data (e.g., admin_override)
     * @return true|\WP_Error Success or error
     */
    public static function trigger_upscaling_for_job( string $job_id, array $context = array() ): true|\WP_Error {
        // Update payment status to 'paid' to bypass payment flow
        $payment_result = JobManager::update_payment_status( $job_id, 'paid' );

        if ( is_wp_error( $payment_result ) ) {
            return new \WP_Error(
                'payment_status_failed',
                __( 'Failed to update payment status', 'sell-my-images' ) . ': ' . $payment_result->get_error_message()
            );
        }

        // Trigger upscaling process via the established action
        do_action( 'smi_payment_completed', $job_id, $context );

        return true;
    }
}