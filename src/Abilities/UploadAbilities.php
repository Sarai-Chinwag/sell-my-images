<?php
/**
 * SMI Upload Abilities
 * 
 * WordPress Abilities API integration for image upload functionality.
 * Allows agents to upload images and create checkouts programmatically.
 *
 * @package SellMyImages\Abilities
 */

namespace SellMyImages\Abilities;

use SellMyImages\Managers\UploadManager;
use SellMyImages\Managers\JobManager;
use SellMyImages\Api\CostCalculator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UploadAbilities {

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
     * Register all upload abilities
     */
    public static function register_abilities(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        wp_register_ability(
            'sell-my-images/get-upload-pricing',
            array(
                'label'               => __( 'Get Upload Pricing', 'sell-my-images' ),
                'description'         => __( 'Calculate pricing for upscaling an image based on its dimensions.', 'sell-my-images' ),
                'category'            => 'content',
                'execute_callback'    => array( __CLASS__, 'get_upload_pricing' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'required'   => array( 'width', 'height' ),
                    'properties' => array(
                        'width' => array(
                            'type'        => 'integer',
                            'description' => __( 'Image width in pixels', 'sell-my-images' ),
                        ),
                        'height' => array(
                            'type'        => 'integer',
                            'description' => __( 'Image height in pixels', 'sell-my-images' ),
                        ),
                    ),
                ),
                'output_schema'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'pricing' => array(
                            'type'       => 'object',
                            'properties' => array(
                                '4x' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'price'        => array( 'type' => 'number' ),
                                        'output_width' => array( 'type' => 'integer' ),
                                        'output_height' => array( 'type' => 'integer' ),
                                    ),
                                ),
                                '8x' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'price'        => array( 'type' => 'number' ),
                                        'output_width' => array( 'type' => 'integer' ),
                                        'output_height' => array( 'type' => 'integer' ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            )
        );

        wp_register_ability(
            'sell-my-images/get-upload-status',
            array(
                'label'               => __( 'Get Upload Status', 'sell-my-images' ),
                'description'         => __( 'Check the status of an uploaded image by upload ID.', 'sell-my-images' ),
                'category'            => 'content',
                'execute_callback'    => array( __CLASS__, 'get_upload_status' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'required'   => array( 'upload_id' ),
                    'properties' => array(
                        'upload_id' => array(
                            'type'        => 'string',
                            'description' => __( 'Upload ID returned from upload endpoint', 'sell-my-images' ),
                        ),
                    ),
                ),
                'output_schema'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'exists'     => array( 'type' => 'boolean' ),
                        'width'      => array( 'type' => 'integer' ),
                        'height'     => array( 'type' => 'integer' ),
                        'file_size'  => array( 'type' => 'integer' ),
                        'mime_type'  => array( 'type' => 'string' ),
                    ),
                ),
            )
        );

        wp_register_ability(
            'sell-my-images/list-upload-jobs',
            array(
                'label'               => __( 'List Upload Jobs', 'sell-my-images' ),
                'description'         => __( 'List all jobs created from user uploads (not site images).', 'sell-my-images' ),
                'category'            => 'content',
                'execute_callback'    => array( __CLASS__, 'list_upload_jobs' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'status' => array(
                            'type'        => 'string',
                            'description' => __( 'Filter by status (pending, completed, failed, abandoned)', 'sell-my-images' ),
                            'enum'        => array( 'pending', 'completed', 'failed', 'abandoned', 'all' ),
                            'default'     => 'all',
                        ),
                        'limit' => array(
                            'type'        => 'integer',
                            'description' => __( 'Maximum number of jobs to return', 'sell-my-images' ),
                            'default'     => 50,
                        ),
                    ),
                ),
                'output_schema'       => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'job_id'         => array( 'type' => 'string' ),
                            'status'         => array( 'type' => 'string' ),
                            'resolution'     => array( 'type' => 'string' ),
                            'email'          => array( 'type' => 'string' ),
                            'amount_charged' => array( 'type' => 'number' ),
                            'created_at'     => array( 'type' => 'string' ),
                            'completed_at'   => array( 'type' => 'string' ),
                        ),
                    ),
                ),
            )
        );

        wp_register_ability(
            'sell-my-images/get-upload-stats',
            array(
                'label'               => __( 'Get Upload Stats', 'sell-my-images' ),
                'description'         => __( 'Get statistics about user-uploaded image sales.', 'sell-my-images' ),
                'category'            => 'content',
                'execute_callback'    => array( __CLASS__, 'get_upload_stats' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'days' => array(
                            'type'        => 'integer',
                            'description' => __( 'Number of days to look back (default: 30)', 'sell-my-images' ),
                            'default'     => 30,
                        ),
                    ),
                ),
                'output_schema'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'total_uploads'    => array( 'type' => 'integer' ),
                        'completed'        => array( 'type' => 'integer' ),
                        'revenue'          => array( 'type' => 'number' ),
                        'conversion_rate'  => array( 'type' => 'number' ),
                        'avg_order_value'  => array( 'type' => 'number' ),
                    ),
                ),
            )
        );
    }

    /**
     * Permission callback - check if user can manage SMI
     */
    public static function can_manage(): bool {
        return PermissionHelper::can_manage();
    }

    /**
     * Calculate pricing for given image dimensions
     */
    public static function get_upload_pricing( array $input ): array {
        $width = intval( $input['width'] );
        $height = intval( $input['height'] );

        if ( $width <= 0 || $height <= 0 ) {
            return array( 'error' => 'Invalid dimensions' );
        }

        $image_data = array(
            'width' => $width,
            'height' => $height,
        );

        $pricing = array();
        foreach ( array( '4x', '8x' ) as $resolution ) {
            $cost_data = CostCalculator::calculate_cost_detailed( $image_data, $resolution );
            $pricing[ $resolution ] = $cost_data;
        }

        return array( 'pricing' => $pricing );
    }

    /**
     * Check upload status
     */
    public static function get_upload_status( array $input ): array {
        $upload_id = sanitize_text_field( $input['upload_id'] );
        
        $upload = UploadManager::get_upload( $upload_id );
        
        if ( is_wp_error( $upload ) ) {
            return array(
                'exists' => false,
                'error'  => $upload->get_error_message(),
            );
        }

        return array(
            'exists'    => true,
            'width'     => $upload['width'],
            'height'    => $upload['height'],
            'file_size' => $upload['file_size'],
            'mime_type' => $upload['mime_type'],
        );
    }

    /**
     * List jobs from uploads
     */
    public static function list_upload_jobs( array $input ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'smi_jobs';
        $status = isset( $input['status'] ) ? sanitize_text_field( $input['status'] ) : 'all';
        $limit = isset( $input['limit'] ) ? min( intval( $input['limit'] ), 100 ) : 50;

        $where = "source_type = 'upload'";
        if ( $status !== 'all' ) {
            $where .= $wpdb->prepare( ' AND status = %s', $status );
        }

        $jobs = $wpdb->get_results( $wpdb->prepare(
            "SELECT job_id, status, resolution, email, amount_charged, created_at, completed_at 
             FROM $table 
             WHERE $where 
             ORDER BY created_at DESC 
             LIMIT %d",
            $limit
        ) );

        return array_map( function( $job ) {
            return array(
                'job_id'         => $job->job_id,
                'status'         => $job->status,
                'resolution'     => $job->resolution,
                'email'          => $job->email,
                'amount_charged' => floatval( $job->amount_charged ),
                'created_at'     => $job->created_at,
                'completed_at'   => $job->completed_at,
            );
        }, $jobs );
    }

    /**
     * Get upload statistics
     */
    public static function get_upload_stats( array $input ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'smi_jobs';
        $days = isset( $input['days'] ) ? intval( $input['days'] ) : 30;

        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT 
                COUNT(*) as total_uploads,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'completed' THEN amount_charged ELSE 0 END) as revenue
             FROM $table 
             WHERE source_type = 'upload' 
             AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );

        $total = intval( $stats->total_uploads );
        $completed = intval( $stats->completed );
        $revenue = floatval( $stats->revenue );

        return array(
            'total_uploads'   => $total,
            'completed'       => $completed,
            'revenue'         => $revenue,
            'conversion_rate' => $total > 0 ? round( ( $completed / $total ) * 100, 2 ) : 0,
            'avg_order_value' => $completed > 0 ? round( $revenue / $completed, 2 ) : 0,
        );
    }
}
