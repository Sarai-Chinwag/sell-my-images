<?php
/**
 * SMI Analytics Abilities
 * 
 * WordPress Abilities API integration for sales and click analytics.
 * Provides insights into click tracking, sales, conversion rates, and revenue.
 *
 * @package SellMyImages\Abilities
 */

namespace SellMyImages\Abilities;

use SellMyImages\Managers\AnalyticsTracker;
use SellMyImages\Managers\DatabaseManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AnalyticsAbilities {

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
     * Register all analytics abilities
     */
    public static function register_abilities(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        // 1. Get Click Analytics
        wp_register_ability(
            'smi/get-click-analytics',
            array(
                'label'               => __( 'Get Click Analytics', 'sell-my-images' ),
                'description'         => __( 'Get click tracking data for download buttons, optionally filtered by post.', 'sell-my-images' ),
                'category'            => 'site',
                'execute_callback'    => array( __CLASS__, 'get_click_analytics' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'post_id' => array(
                            'type'        => 'integer',
                            'description' => __( 'Specific post ID to get analytics for (optional)', 'sell-my-images' ),
                        ),
                        'limit' => array(
                            'type'        => 'integer',
                            'description' => __( 'Maximum posts to return (default: 20)', 'sell-my-images' ),
                            'default'     => 20,
                        ),
                    ),
                ),
                'output_schema'       => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'post_id'      => array( 'type' => 'integer' ),
                            'post_title'   => array( 'type' => 'string' ),
                            'total_clicks' => array( 'type' => 'integer' ),
                            'first_click'  => array( 'type' => 'string' ),
                            'last_click'   => array( 'type' => 'string' ),
                            'attachments'  => array(
                                'type'  => 'array',
                                'items' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'attachment_id' => array( 'type' => 'integer' ),
                                        'clicks'        => array( 'type' => 'integer' ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            )
        );

        // 2. Get Sales Summary
        wp_register_ability(
            'smi/get-sales-summary',
            array(
                'label'               => __( 'Get Sales Summary', 'sell-my-images' ),
                'description'         => __( 'Get summary statistics for image sales including revenue, costs, and profit.', 'sell-my-images' ),
                'category'            => 'site',
                'execute_callback'    => array( __CLASS__, 'get_sales_summary' ),
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
                        'total_jobs'    => array( 'type' => 'integer' ),
                        'paid_jobs'     => array( 'type' => 'integer' ),
                        'total_revenue' => array( 'type' => 'number' ),
                        'total_cost'    => array( 'type' => 'number' ),
                        'total_profit'  => array( 'type' => 'number' ),
                        'avg_price'     => array( 'type' => 'number' ),
                    ),
                ),
            )
        );

        // 3. Get Top Selling Posts
        wp_register_ability(
            'smi/get-top-selling-posts',
            array(
                'label'               => __( 'Get Top Selling Posts', 'sell-my-images' ),
                'description'         => __( 'Get posts ranked by sales, including click and conversion data.', 'sell-my-images' ),
                'category'            => 'site',
                'execute_callback'    => array( __CLASS__, 'get_top_selling_posts' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'limit' => array(
                            'type'        => 'integer',
                            'description' => __( 'Maximum posts to return (default: 10)', 'sell-my-images' ),
                            'default'     => 10,
                        ),
                        'days' => array(
                            'type'        => 'integer',
                            'description' => __( 'Number of days to look back (default: 30)', 'sell-my-images' ),
                            'default'     => 30,
                        ),
                    ),
                ),
                'output_schema'       => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'post_id'         => array( 'type' => 'integer' ),
                            'post_title'      => array( 'type' => 'string' ),
                            'sales_count'     => array( 'type' => 'integer' ),
                            'revenue'         => array( 'type' => 'number' ),
                            'clicks'          => array( 'type' => 'integer' ),
                            'conversion_rate' => array( 'type' => 'number' ),
                        ),
                    ),
                ),
            )
        );

        // 4. Get Top Selling Images
        wp_register_ability(
            'smi/get-top-selling-images',
            array(
                'label'               => __( 'Get Top Selling Images', 'sell-my-images' ),
                'description'         => __( 'Get individual images ranked by sales.', 'sell-my-images' ),
                'category'            => 'site',
                'execute_callback'    => array( __CLASS__, 'get_top_selling_images' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'limit' => array(
                            'type'        => 'integer',
                            'description' => __( 'Maximum images to return (default: 10)', 'sell-my-images' ),
                            'default'     => 10,
                        ),
                        'days' => array(
                            'type'        => 'integer',
                            'description' => __( 'Number of days to look back (default: 30)', 'sell-my-images' ),
                            'default'     => 30,
                        ),
                    ),
                ),
                'output_schema'       => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'attachment_id' => array( 'type' => 'integer' ),
                            'post_id'       => array( 'type' => 'integer' ),
                            'image_url'     => array( 'type' => 'string' ),
                            'sales_count'   => array( 'type' => 'integer' ),
                            'revenue'       => array( 'type' => 'number' ),
                            'clicks'        => array( 'type' => 'integer' ),
                        ),
                    ),
                ),
            )
        );

        // 5. Get Conversion Funnel
        wp_register_ability(
            'smi/get-conversion-funnel',
            array(
                'label'               => __( 'Get Conversion Funnel', 'sell-my-images' ),
                'description'         => __( 'Get conversion funnel metrics from clicks to sales.', 'sell-my-images' ),
                'category'            => 'site',
                'execute_callback'    => array( __CLASS__, 'get_conversion_funnel' ),
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
                        'total_clicks'     => array( 'type' => 'integer' ),
                        'total_sales'      => array( 'type' => 'integer' ),
                        'conversion_rate'  => array( 'type' => 'number' ),
                        'revenue_per_click' => array( 'type' => 'number' ),
                    ),
                ),
            )
        );

        // 6. List Jobs
        wp_register_ability(
            'smi/list-jobs',
            array(
                'label'               => __( 'List Jobs', 'sell-my-images' ),
                'description'         => __( 'List upscaling jobs with optional status filter.', 'sell-my-images' ),
                'category'            => 'site',
                'execute_callback'    => array( __CLASS__, 'list_jobs' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'status' => array(
                            'type'        => 'string',
                            'description' => __( 'Filter by status (pending/processing/completed/paid/failed)', 'sell-my-images' ),
                            'enum'        => array( 'pending', 'processing', 'completed', 'paid', 'failed' ),
                        ),
                        'limit' => array(
                            'type'        => 'integer',
                            'description' => __( 'Maximum jobs to return (default: 50)', 'sell-my-images' ),
                            'default'     => 50,
                        ),
                        'offset' => array(
                            'type'        => 'integer',
                            'description' => __( 'Number of jobs to skip (default: 0)', 'sell-my-images' ),
                            'default'     => 0,
                        ),
                    ),
                ),
                'output_schema'       => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'job_id'         => array( 'type' => 'string' ),
                            'post_id'        => array( 'type' => 'integer' ),
                            'attachment_id'  => array( 'type' => 'integer' ),
                            'status'         => array( 'type' => 'string' ),
                            'payment_status' => array( 'type' => 'string' ),
                            'resolution'     => array( 'type' => 'string' ),
                            'email'          => array( 'type' => 'string' ),
                            'amount_charged' => array( 'type' => 'number' ),
                            'amount_cost'    => array( 'type' => 'number' ),
                            'created_at'     => array( 'type' => 'string' ),
                            'completed_at'   => array( 'type' => 'string' ),
                            'paid_at'        => array( 'type' => 'string' ),
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
        return PermissionHelper::can_manage();
    }

    /**
     * Get click analytics for posts
     */
    public static function get_click_analytics( array $input ): array {
        $post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : null;
        $limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : 20;

        // If specific post requested
        if ( $post_id ) {
            $click_data = AnalyticsTracker::get_click_counts( $post_id );
            
            if ( empty( $click_data ) ) {
                return array();
            }

            $post = get_post( $post_id );
            $attachments = self::extract_attachments_from_click_data( $click_data );

            return array(
                array(
                    'post_id'      => $post_id,
                    'post_title'   => $post ? $post->post_title : '',
                    'total_clicks' => isset( $click_data['total_clicks'] ) ? intval( $click_data['total_clicks'] ) : 0,
                    'first_click'  => isset( $click_data['first_click_date'] ) ? $click_data['first_click_date'] : null,
                    'last_click'   => isset( $click_data['last_click_date'] ) ? $click_data['last_click_date'] : null,
                    'attachments'  => $attachments,
                ),
            );
        }

        // Get all posts with click data
        $posts_with_clicks = AnalyticsTracker::get_posts_with_click_data();
        
        if ( empty( $posts_with_clicks ) ) {
            return array();
        }

        // Get click data for each post and sort by total clicks
        $results = array();
        foreach ( $posts_with_clicks as $pid ) {
            $click_data = AnalyticsTracker::get_click_counts( $pid );
            $post = get_post( $pid );
            
            $results[] = array(
                'post_id'      => $pid,
                'post_title'   => $post ? $post->post_title : '',
                'total_clicks' => isset( $click_data['total_clicks'] ) ? intval( $click_data['total_clicks'] ) : 0,
                'first_click'  => isset( $click_data['first_click_date'] ) ? $click_data['first_click_date'] : null,
                'last_click'   => isset( $click_data['last_click_date'] ) ? $click_data['last_click_date'] : null,
                'attachments'  => self::extract_attachments_from_click_data( $click_data ),
            );
        }

        // Sort by total clicks descending
        usort( $results, function( $a, $b ) {
            return $b['total_clicks'] - $a['total_clicks'];
        });

        return array_slice( $results, 0, $limit );
    }

    /**
     * Extract attachment click data from analytics array
     */
    private static function extract_attachments_from_click_data( array $click_data ): array {
        $attachments = array();
        foreach ( $click_data as $key => $value ) {
            if ( strpos( $key, 'attachment_' ) === 0 ) {
                $attachment_id = intval( str_replace( 'attachment_', '', $key ) );
                $attachments[] = array(
                    'attachment_id' => $attachment_id,
                    'clicks'        => intval( $value ),
                );
            }
        }
        return $attachments;
    }

    /**
     * Get sales summary statistics
     */
    public static function get_sales_summary( array $input ): array {
        global $wpdb;

        $days = isset( $input['days'] ) ? absint( $input['days'] ) : 30;
        $table = DatabaseManager::get_jobs_table();

        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT 
                COUNT(*) as total_jobs,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_jobs,
                SUM(CASE WHEN payment_status = 'paid' THEN amount_charged ELSE 0 END) as total_revenue,
                SUM(CASE WHEN payment_status = 'paid' THEN COALESCE(amount_cost, 0) ELSE 0 END) as total_cost,
                AVG(CASE WHEN payment_status = 'paid' THEN amount_charged ELSE NULL END) as avg_price
             FROM `" . esc_sql( $table ) . "`
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );

        $total_revenue = floatval( $stats->total_revenue ?? 0 );
        $total_cost = floatval( $stats->total_cost ?? 0 );

        return array(
            'total_jobs'    => intval( $stats->total_jobs ?? 0 ),
            'paid_jobs'     => intval( $stats->paid_jobs ?? 0 ),
            'total_revenue' => round( $total_revenue, 2 ),
            'total_cost'    => round( $total_cost, 2 ),
            'total_profit'  => round( $total_revenue - $total_cost, 2 ),
            'avg_price'     => round( floatval( $stats->avg_price ?? 0 ), 2 ),
        );
    }

    /**
     * Get top selling posts
     */
    public static function get_top_selling_posts( array $input ): array {
        global $wpdb;

        $limit = isset( $input['limit'] ) ? min( absint( $input['limit'] ), 100 ) : 10;
        $days = isset( $input['days'] ) ? absint( $input['days'] ) : 30;
        $table = DatabaseManager::get_jobs_table();

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT 
                j.post_id,
                p.post_title,
                COUNT(*) as sales_count,
                SUM(j.amount_charged) as revenue
             FROM `" . esc_sql( $table ) . "` j
             LEFT JOIN {$wpdb->posts} p ON j.post_id = p.ID
             WHERE j.payment_status = 'paid'
             AND j.post_id IS NOT NULL
             AND j.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY j.post_id
             ORDER BY sales_count DESC, revenue DESC
             LIMIT %d",
            $days,
            $limit
        ) );

        $output = array();
        foreach ( $results as $row ) {
            $clicks = AnalyticsTracker::get_total_clicks( $row->post_id );
            $conversion_rate = $clicks > 0 ? ( $row->sales_count / $clicks ) * 100 : 0;

            $output[] = array(
                'post_id'         => intval( $row->post_id ),
                'post_title'      => $row->post_title ?: '',
                'sales_count'     => intval( $row->sales_count ),
                'revenue'         => round( floatval( $row->revenue ), 2 ),
                'clicks'          => $clicks,
                'conversion_rate' => round( $conversion_rate, 2 ),
            );
        }

        return $output;
    }

    /**
     * Get top selling images
     */
    public static function get_top_selling_images( array $input ): array {
        global $wpdb;

        $limit = isset( $input['limit'] ) ? min( absint( $input['limit'] ), 100 ) : 10;
        $days = isset( $input['days'] ) ? absint( $input['days'] ) : 30;
        $table = DatabaseManager::get_jobs_table();

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT 
                j.attachment_id,
                j.post_id,
                j.image_url,
                COUNT(*) as sales_count,
                SUM(j.amount_charged) as revenue
             FROM `" . esc_sql( $table ) . "` j
             WHERE j.payment_status = 'paid'
             AND j.attachment_id IS NOT NULL
             AND j.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY j.attachment_id
             ORDER BY sales_count DESC, revenue DESC
             LIMIT %d",
            $days,
            $limit
        ) );

        $output = array();
        foreach ( $results as $row ) {
            // Get click data for this specific attachment from its parent post
            $clicks = 0;
            if ( $row->post_id ) {
                $click_data = AnalyticsTracker::get_click_counts( $row->post_id );
                $attachment_key = 'attachment_' . $row->attachment_id;
                $clicks = isset( $click_data[ $attachment_key ] ) ? intval( $click_data[ $attachment_key ] ) : 0;
            }

            // Get attachment URL if available
            $image_url = $row->image_url;
            if ( $row->attachment_id ) {
                $attachment_url = wp_get_attachment_url( $row->attachment_id );
                if ( $attachment_url ) {
                    $image_url = $attachment_url;
                }
            }

            $output[] = array(
                'attachment_id' => intval( $row->attachment_id ),
                'post_id'       => intval( $row->post_id ),
                'image_url'     => $image_url ?: '',
                'sales_count'   => intval( $row->sales_count ),
                'revenue'       => round( floatval( $row->revenue ), 2 ),
                'clicks'        => $clicks,
            );
        }

        return $output;
    }

    /**
     * Get conversion funnel metrics
     */
    public static function get_conversion_funnel( array $input ): array {
        global $wpdb;

        $days = isset( $input['days'] ) ? absint( $input['days'] ) : 30;
        $table = DatabaseManager::get_jobs_table();

        // Get total clicks across all posts
        $posts_with_clicks = AnalyticsTracker::get_posts_with_click_data();
        $total_clicks = 0;
        foreach ( $posts_with_clicks as $post_id ) {
            $total_clicks += AnalyticsTracker::get_total_clicks( $post_id );
        }

        // Get sales and revenue from jobs table
        $sales_data = $wpdb->get_row( $wpdb->prepare(
            "SELECT 
                COUNT(*) as total_sales,
                SUM(amount_charged) as total_revenue
             FROM `" . esc_sql( $table ) . "`
             WHERE payment_status = 'paid'
             AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );

        $total_sales = intval( $sales_data->total_sales ?? 0 );
        $total_revenue = floatval( $sales_data->total_revenue ?? 0 );

        $conversion_rate = $total_clicks > 0 ? ( $total_sales / $total_clicks ) * 100 : 0;
        $revenue_per_click = $total_clicks > 0 ? $total_revenue / $total_clicks : 0;

        return array(
            'total_clicks'      => $total_clicks,
            'total_sales'       => $total_sales,
            'conversion_rate'   => round( $conversion_rate, 2 ),
            'revenue_per_click' => round( $revenue_per_click, 2 ),
        );
    }

    /**
     * List jobs with optional filters
     */
    public static function list_jobs( array $input ): array {
        $where = array();
        
        if ( isset( $input['status'] ) && ! empty( $input['status'] ) ) {
            $status = sanitize_text_field( $input['status'] );
            // Handle 'paid' as payment_status filter
            if ( $status === 'paid' ) {
                $where['payment_status'] = 'paid';
            } else {
                $where['status'] = $status;
            }
        }

        $limit = isset( $input['limit'] ) ? min( absint( $input['limit'] ), 100 ) : 50;
        $offset = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

        $results = DatabaseManager::get_results( array(
            'where'    => $where,
            'order_by' => 'created_at',
            'order'    => 'DESC',
            'limit'    => $limit,
            'offset'   => $offset,
        ) );

        $output = array();
        foreach ( $results as $job ) {
            $output[] = array(
                'job_id'         => $job->job_id,
                'post_id'        => intval( $job->post_id ?? 0 ),
                'attachment_id'  => intval( $job->attachment_id ?? 0 ),
                'status'         => $job->status,
                'payment_status' => $job->payment_status,
                'resolution'     => $job->resolution,
                'email'          => $job->email,
                'amount_charged' => floatval( $job->amount_charged ?? 0 ),
                'amount_cost'    => floatval( $job->amount_cost ?? 0 ),
                'created_at'     => $job->created_at,
                'completed_at'   => $job->completed_at,
                'paid_at'        => $job->paid_at,
            );
        }

        return $output;
    }
}
