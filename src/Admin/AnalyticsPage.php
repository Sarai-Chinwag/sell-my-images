<?php
/**
 * Analytics Page Class
 * 
 * Displays analytics data organized by post with attachment breakdowns
 * 
 * @package SellMyImages
 * @since 1.0.0
 */

namespace SellMyImages\Admin;

use SellMyImages\Managers\AnalyticsTracker;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AnalyticsPage class
 */
class AnalyticsPage {
    
    /**
     * Constructor
     */
    public function __construct() {
        // This class is instantiated when needed
    }
    
    /**
     * Get and validate URL parameters for sorting and pagination
     * 
     * @return array Validated parameters
     */
    private function get_validated_params() {
        $params = array(
            'paged' => 1,
            'per_page' => 10,
            'orderby' => 'clicks',
            'order' => 'desc'
        );
        
        // Validate page number
        if ( isset( $_GET['paged'] ) ) {
            $paged = intval( $_GET['paged'] );
            $params['paged'] = max( 1, $paged );
        }
        
        // Validate per page
        if ( isset( $_GET['per_page'] ) ) {
            $per_page = intval( $_GET['per_page'] );
            $allowed_per_page = array( 10, 25, 50, 100 );
            if ( in_array( $per_page, $allowed_per_page, true ) ) {
                $params['per_page'] = $per_page;
            }
        }
        
        // Validate orderby
        if ( isset( $_GET['orderby'] ) ) {
            $orderby = sanitize_text_field( wp_unslash( $_GET['orderby'] ) );
            $allowed_orderby = array( 'revenue', 'profit', 'sales', 'clicks', 'conversion', 'pageviews', 'ctr', 'date', 'title' );
            if ( in_array( $orderby, $allowed_orderby, true ) ) {
                $params['orderby'] = $orderby;
            }
        }
        
        // Validate order
        if ( isset( $_GET['order'] ) ) {
            $order = sanitize_text_field( wp_unslash( $_GET['order'] ) );
            if ( in_array( $order, array( 'asc', 'desc' ), true ) ) {
                $params['order'] = $order;
            }
        }
        
        return $params;
    }
    
    /**
     * Get analytics data organized by post
     * 
     * @param array $params Optional parameters for sorting and pagination
     * @return array Analytics data grouped by post
     */
    public function get_analytics_data( $params = array() ) {
        global $wpdb;
        
        $jobs_table = \SellMyImages\Managers\DatabaseManager::get_jobs_table();
        
        // Check cache first
        $cache_key = 'smi_analytics_data';
        $raw_data = wp_cache_get( $cache_key, 'smi_analytics' );
        
        if ( false === $raw_data ) {
            // Single optimized query to get both post and attachment data
            // This eliminates the N+1 query problem
            $raw_data = $wpdb->get_results( $wpdb->prepare( "
                SELECT 
                    j.post_id,
                    p.post_title,
                    p.post_date,
                    j.attachment_id,
                    j.image_url,
                    j.image_width,
                    j.image_height,
                    COUNT(*) as sales_count,
                    SUM(j.amount_charged) as revenue,
                    SUM(j.amount_cost) as total_cost,
                    SUM(j.amount_charged - COALESCE(j.amount_cost, 0)) as profit,
                    AVG(j.amount_charged) as avg_price,
                    AVG(j.amount_cost) as avg_cost,
                    GROUP_CONCAT(DISTINCT j.resolution ORDER BY j.resolution) as resolutions_sold,
                    GROUP_CONCAT(DISTINCT j.email) as customer_emails,
                    MAX(j.created_at) as last_sale_date,
                    MIN(j.created_at) as first_sale_date
                FROM `" . esc_sql( $jobs_table ) . "` j
                LEFT JOIN {$wpdb->posts} p ON j.post_id = p.ID
                WHERE j.payment_status = %s
                AND j.attachment_id IS NOT NULL
                GROUP BY j.post_id, j.attachment_id
                ORDER BY j.post_id, revenue DESC
            ", 'paid' ) );
            
            // Enhance data with click tracking information
            $raw_data = $this->add_click_data_to_results( $raw_data );
            
            // Cache for 5 minutes
            wp_cache_set( $cache_key, $raw_data, 'smi_analytics', 5 * MINUTE_IN_SECONDS );
        }
        
        // Organize data by post with attachments nested
        $analytics_data = $this->organize_analytics_data( $raw_data );
        
        // Get posts with clicks but no sales (with pagination support)
        $click_only_posts = AnalyticsTracker::get_click_only_posts_data( $params );
        
        // Merge click-only posts with sales data
        $combined_data = array_merge( $analytics_data, $click_only_posts );
        
        // Hydrate pageviews and CTR for click-only posts (sales posts already have these from organize_analytics_data)
        $click_only_ids = array_map( function( $p ) { return $p->post_id; }, $click_only_posts );
        if ( ! empty( $click_only_ids ) ) {
            $click_only_views = $this->get_pageviews_for_posts( $click_only_ids );
            foreach ( $combined_data as $post_data ) {
                if ( ! isset( $post_data->pageviews ) ) {
                    $post_data->pageviews = $click_only_views[ $post_data->post_id ] ?? 0;
                    $post_data->ctr = $post_data->pageviews > 0 ? 
                        ( ( $post_data->total_clicks ?: 0 ) / $post_data->pageviews ) * 100 : 0;
                }
            }
        }
        
        // Apply sorting
        $combined_data = $this->sort_analytics_data( $combined_data, $params );
        
        // Apply pagination if specified
        if ( isset( $params['per_page'] ) && $params['per_page'] > 0 ) {
            $offset = ( ( $params['paged'] ?? 1 ) - 1 ) * $params['per_page'];
            $combined_data = array_slice( $combined_data, $offset, $params['per_page'] );
        }
        
        // Enhance with click tracking data
        return AnalyticsTracker::enhance_with_conversion_data( $combined_data );
    }
    
    /**
     * Sort analytics data based on parameters
     * 
     * @param array $data Analytics data to sort
     * @param array $params Sorting parameters
     * @return array Sorted data
     */
    private function sort_analytics_data( $data, $params = array() ) {
        $orderby = $params['orderby'] ?? 'revenue';
        $order = $params['order'] ?? 'desc';
        
        usort( $data, function( $a, $b ) use ( $orderby, $order ) {
            $value_a = $this->get_sort_value( $a, $orderby );
            $value_b = $this->get_sort_value( $b, $orderby );
            
            if ( $value_a == $value_b ) {
                // Secondary sort by revenue (desc) for consistency
                $value_a = $a->total_revenue ?: 0;
                $value_b = $b->total_revenue ?: 0;
            }
            
            $result = $value_a <=> $value_b;
            return ( $order === 'desc' ) ? -$result : $result;
        });
        
        return $data;
    }
    
    /**
     * Get sort value for a specific field
     * 
     * @param object $post_data Post data object
     * @param string $orderby Field to sort by
     * @return mixed Sort value
     */
    private function get_sort_value( $post_data, $orderby ) {
        switch ( $orderby ) {
            case 'revenue':
                return $post_data->total_revenue ?: 0;
            case 'profit':
                return $post_data->total_profit ?: 0;
            case 'sales':
                return $post_data->total_sales ?: 0;
            case 'clicks':
                return isset($post_data->total_clicks) ? $post_data->total_clicks : 0;
            case 'conversion':
                return $post_data->conversion_rate ?: 0;
            case 'pageviews':
                return $post_data->pageviews ?: 0;
            case 'ctr':
                return $post_data->ctr ?: 0;
            case 'date':
                return strtotime( $post_data->post_date ?: '1970-01-01' );
            case 'title':
                return strtolower( $post_data->post_title ?: '' );
            default:
                return $post_data->total_revenue ?: 0;
        }
    }
    
    /**
     * Get total count of posts for pagination
     * 
     * @return int Total number of posts
     */
    public function get_total_posts_count() {
        global $wpdb;
        
        $jobs_table = \SellMyImages\Managers\DatabaseManager::get_jobs_table();
        
        // Check cache first
        $cache_key = 'smi_total_posts_count';
        $total_count = wp_cache_get( $cache_key, 'smi_analytics' );
        
        if ( false === $total_count ) {
            // Count posts with sales
            $sales_count = $wpdb->get_var( $wpdb->prepare( "
                SELECT COUNT(DISTINCT post_id) 
                FROM `" . esc_sql( $jobs_table ) . "` 
                WHERE payment_status = %s 
                AND post_id IS NOT NULL
            ", 'paid' ) );
            
            // Count posts with clicks but no sales
            $click_only_count = AnalyticsTracker::get_click_only_posts_count();
            
            $total_count = intval( $sales_count ) + intval( $click_only_count );
            
            // Cache for 5 minutes
            wp_cache_set( $cache_key, $total_count, 'smi_analytics', 5 * MINUTE_IN_SECONDS );
        }
        
        return intval( $total_count );
    }
    
    /**
     * Organize raw analytics data by post with nested attachments
     * 
     * @param array $raw_data Raw query results
     * @return array Organized analytics data
     */
    private function organize_analytics_data( $raw_data ) {
        $organized = array();
        
        foreach ( $raw_data as $row ) {
            $post_id = $row->post_id;
            
            // Initialize post data if not exists
            if ( ! isset( $organized[$post_id] ) ) {
                $organized[$post_id] = (object) array(
                    'post_id' => $post_id,
                    'post_title' => $row->post_title,
                    'post_date' => $row->post_date,
                    'total_sales' => 0,
                    'total_revenue' => 0,
                    'total_cost' => 0,
                    'total_profit' => 0,
                    'total_clicks' => $row->total_clicks ?? 0,
                    'unique_images_sold' => 0,
                    'customer_emails' => array(),
                    'attachments' => array()
                );
            }
            
            // Add attachment data
            $attachment_data = (object) array(
                'attachment_id' => $row->attachment_id,
                'image_url' => $row->image_url,
                'image_width' => $row->image_width,
                'image_height' => $row->image_height,
                'sales_count' => $row->sales_count,
                'revenue' => $row->revenue,
                'total_cost' => $row->total_cost,
                'profit' => $row->profit,
                'avg_price' => $row->avg_price,
                'avg_cost' => $row->avg_cost,
                'resolutions_sold' => $row->resolutions_sold,
                'last_sale_date' => $row->last_sale_date,
                'first_sale_date' => $row->first_sale_date,
                'attachment_clicks' => $row->attachment_clicks ?? 0,
                'conversion_rate' => $row->conversion_rate ?? 0
            );
            
            $organized[$post_id]->attachments[] = $attachment_data;
            
            // Aggregate post totals
            $organized[$post_id]->total_sales += $row->sales_count;
            $organized[$post_id]->total_revenue += $row->revenue;
            $organized[$post_id]->total_cost += $row->total_cost;
            $organized[$post_id]->total_profit += $row->profit;
            $organized[$post_id]->unique_images_sold++;
            
            // Collect unique customer emails
            if ( ! empty( $row->customer_emails ) ) {
                $emails = explode( ',', $row->customer_emails );
                $organized[$post_id]->customer_emails = array_unique( array_merge( 
                    $organized[$post_id]->customer_emails, 
                    $emails 
                ) );
            }
        }
        
        // Batch-fetch pageviews for all posts
        $post_ids = array_keys( $organized );
        $pageviews_by_post = $this->get_pageviews_for_posts( $post_ids );
        
        // Calculate averages and finalize
        foreach ( $organized as $post_id => $post_data ) {
            $post_data->avg_sale_price = $post_data->total_sales > 0 ? 
                $post_data->total_revenue / $post_data->total_sales : 0;
            $post_data->avg_cost = $post_data->total_sales > 0 ? 
                $post_data->total_cost / $post_data->total_sales : 0;
            $post_data->unique_customers = count( $post_data->customer_emails );
            
            // Calculate post-level conversion rate (total sales / total clicks)
            $post_data->conversion_rate = $post_data->total_clicks > 0 ? 
                ( $post_data->total_sales / $post_data->total_clicks ) * 100 : 0;
            
            // Add pageviews and CTR (clicks / pageviews * 100)
            $post_data->pageviews = $pageviews_by_post[ $post_id ] ?? 0;
            $post_data->ctr = $post_data->pageviews > 0 ? 
                ( ( $post_data->total_clicks ?: 0 ) / $post_data->pageviews ) * 100 : 0;
        }
        
        // Sort by total revenue (descending)
        uasort( $organized, function( $a, $b ) {
            return $b->total_revenue <=> $a->total_revenue;
        });
        
        return array_values( $organized );
    }
    
    /**
     * Get summary statistics
     * 
     * @return object Summary statistics
     */
    public function get_summary_stats() {
        global $wpdb;
        
        $jobs_table = \SellMyImages\Managers\DatabaseManager::get_jobs_table();
        
        // Check cache first
        $cache_key = 'smi_summary_stats';
        $summary_stats = wp_cache_get( $cache_key, 'smi_analytics' );
        
        if ( false === $summary_stats ) {
            $summary_stats = $wpdb->get_row( $wpdb->prepare( "
                SELECT 
                    COUNT(*) as total_paid_jobs,
                    SUM(amount_charged) as total_revenue,
                    SUM(amount_cost) as total_cost,
                    SUM(amount_charged - COALESCE(amount_cost, 0)) as total_profit,
                    AVG(amount_charged) as avg_sale_price,
                    AVG(amount_cost) as avg_cost,
                    COUNT(DISTINCT post_id) as posts_with_sales,
                    COUNT(DISTINCT attachment_id) as unique_images_sold,
                    COUNT(DISTINCT email) as unique_customers
                FROM `" . esc_sql( $jobs_table ) . "`
                WHERE payment_status = %s
            ", 'paid' ) );
            
            // Cache for 5 minutes
            wp_cache_set( $cache_key, $summary_stats, 'smi_analytics', 5 * MINUTE_IN_SECONDS );
        }
        
        // Enhance with click tracking data
        return AnalyticsTracker::enhance_summary_stats( $summary_stats );
    }
    
    /**
     * Render the analytics page
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'sell-my-images' ) );
        }
        
        $params = $this->get_validated_params();
        $analytics_data = $this->get_analytics_data( $params );
        $summary_stats = $this->get_summary_stats();
        $total_posts = $this->get_total_posts_count();
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Sell My Images - Analytics', 'sell-my-images' ); ?></h1>
            
            <?php $this->render_summary_stats( $summary_stats ); ?>
            
            <?php if ( $total_posts > 0 ) : ?>
                <?php $this->render_sorting_controls( $params ); ?>
                <?php $this->render_pagination_info( $params, $total_posts ); ?>
            <?php endif; ?>
            
            <div class="smi-analytics-container">
                <?php if ( empty( $analytics_data ) && $total_posts == 0 ) : ?>
                    <div class="notice notice-info">
                        <p><?php esc_html_e( 'No analytics data found. Data will appear here once customers click download buttons or purchase upscaled images.', 'sell-my-images' ); ?></p>
                    </div>
                <?php else : ?>
                    <?php $this->render_analytics_table( $analytics_data ); ?>
                    <?php if ( $total_posts > $params['per_page'] ) : ?>
                        <?php $this->render_pagination( $params, $total_posts ); ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        
        <style>
        .smi-low-ctr { color: #d63638; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('.smi-post-header').click(function() {
                var $attachments = $(this).siblings('.smi-attachments');
                var $icon = $(this).find('.smi-toggle-icon');
                
                $attachments.toggleClass('expanded');
                $icon.toggleClass('expanded');
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render sorting controls
     * 
     * @param array $params Current parameters
     */
    private function render_sorting_controls( $params ) {
        $current_url = remove_query_arg( array( 'orderby', 'order', 'paged' ) );
        ?>
        <div class="smi-sorting-controls">
            <div class="smi-sort-options">
                <label for="smi-orderby"><?php esc_html_e( 'Sort by:', 'sell-my-images' ); ?></label>
                <select id="smi-orderby" name="orderby">
                    <option value="revenue" <?php selected( $params['orderby'], 'revenue' ); ?>><?php esc_html_e( 'Revenue', 'sell-my-images' ); ?></option>
                    <option value="profit" <?php selected( $params['orderby'], 'profit' ); ?>><?php esc_html_e( 'Profit', 'sell-my-images' ); ?></option>
                    <option value="sales" <?php selected( $params['orderby'], 'sales' ); ?>><?php esc_html_e( 'Sales Count', 'sell-my-images' ); ?></option>
                    <option value="clicks" <?php selected( $params['orderby'], 'clicks' ); ?>><?php esc_html_e( 'Click Count', 'sell-my-images' ); ?></option>
                    <option value="conversion" <?php selected( $params['orderby'], 'conversion' ); ?>><?php esc_html_e( 'Conversion Rate', 'sell-my-images' ); ?></option>
                    <option value="pageviews" <?php selected( $params['orderby'], 'pageviews' ); ?>><?php esc_html_e( 'Pageviews', 'sell-my-images' ); ?></option>
                    <option value="ctr" <?php selected( $params['orderby'], 'ctr' ); ?>><?php esc_html_e( 'Click-Through Rate', 'sell-my-images' ); ?></option>
                    <option value="date" <?php selected( $params['orderby'], 'date' ); ?>><?php esc_html_e( 'Post Date', 'sell-my-images' ); ?></option>
                    <option value="title" <?php selected( $params['orderby'], 'title' ); ?>><?php esc_html_e( 'Post Title', 'sell-my-images' ); ?></option>
                </select>
                
                <select id="smi-order" name="order">
                    <option value="desc" <?php selected( $params['order'], 'desc' ); ?>><?php esc_html_e( 'Highest to Lowest', 'sell-my-images' ); ?></option>
                    <option value="asc" <?php selected( $params['order'], 'asc' ); ?>><?php esc_html_e( 'Lowest to Highest', 'sell-my-images' ); ?></option>
                </select>
            </div>
            
            <div class="smi-per-page-options">
                <label for="smi-per-page"><?php esc_html_e( 'Show:', 'sell-my-images' ); ?></label>
                <select id="smi-per-page" name="per_page">
                    <option value="10" <?php selected( $params['per_page'], 10 ); ?>>10</option>
                    <option value="25" <?php selected( $params['per_page'], 25 ); ?>>25</option>
                    <option value="50" <?php selected( $params['per_page'], 50 ); ?>>50</option>
                    <option value="100" <?php selected( $params['per_page'], 100 ); ?>>100</option>
                </select>
                <span><?php esc_html_e( 'posts per page', 'sell-my-images' ); ?></span>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#smi-orderby, #smi-order, #smi-per-page').change(function() {
                var url = '<?php echo esc_js( $current_url ); ?>';
                var orderby = $('#smi-orderby').val();
                var order = $('#smi-order').val();
                var per_page = $('#smi-per-page').val();
                
                url += (url.indexOf('?') > -1 ? '&' : '?') + 'orderby=' + orderby + '&order=' + order + '&per_page=' + per_page;
                window.location.href = url;
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render pagination info
     * 
     * @param array $params Current parameters
     * @param int $total_posts Total number of posts
     */
    private function render_pagination_info( $params, $total_posts ) {
        $start = ( ( $params['paged'] - 1 ) * $params['per_page'] ) + 1;
        $end = min( $params['paged'] * $params['per_page'], $total_posts );
        ?>
        <div class="smi-pagination-info">
            <p>
                <?php 
                /* translators: 1: start number, 2: end number, 3: total number */
                printf( 
                    esc_html__( 'Showing %1$d-%2$d of %3$d posts', 'sell-my-images' ), 
                    esc_html( $start ), 
                    esc_html( $end ), 
                    esc_html( $total_posts ) 
                ); 
                ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Render pagination controls
     * 
     * @param array $params Current parameters
     * @param int $total_posts Total number of posts
     */
    private function render_pagination( $params, $total_posts ) {
        $total_pages = ceil( $total_posts / $params['per_page'] );
        
        if ( $total_pages <= 1 ) {
            return;
        }
        
        $current_url = remove_query_arg( 'paged' );
        
        $pagination_links = paginate_links( array(
            'base' => add_query_arg( 'paged', '%#%', $current_url ),
            'format' => '',
            'current' => $params['paged'],
            'total' => $total_pages,
            'prev_text' => __( '&laquo; Previous', 'sell-my-images' ),
            'next_text' => __( 'Next &raquo;', 'sell-my-images' ),
            'type' => 'plain',
        ) );
        
        if ( $pagination_links ) {
            ?>
            <div class="smi-pagination">
                <?php echo wp_kses_post( $pagination_links ); ?>
            </div>
            <?php
        }
    }
    
    /**
     * Render summary statistics cards
     * 
     * @param object $stats Summary statistics
     */
    private function render_summary_stats( $stats ) {
        if ( ! $stats ) {
            return;
        }
        
        ?>
        <div class="smi-summary-stats">
            <div class="smi-stat-card">
                <span class="smi-stat-value">$<?php echo esc_html( number_format( $stats->total_revenue ?: 0, 2 ) ); ?></span>
                <div class="smi-stat-label"><?php esc_html_e( 'Total Revenue', 'sell-my-images' ); ?></div>
            </div>
            
            <div class="smi-stat-card">
                <span class="smi-stat-value profit">$<?php echo esc_html( number_format( $stats->total_profit ?: 0, 2 ) ); ?></span>
                <div class="smi-stat-label"><?php esc_html_e( 'Total Profit', 'sell-my-images' ); ?></div>
            </div>
            
            <div class="smi-stat-card">
                <span class="smi-stat-value"><?php echo esc_html( number_format( $stats->total_paid_jobs ?: 0 ) ); ?></span>
                <div class="smi-stat-label"><?php esc_html_e( 'Total Sales', 'sell-my-images' ); ?></div>
            </div>
            
            <div class="smi-stat-card">
                <span class="smi-stat-value">
                    <?php 
                    $profit_margin = 0;
                    if ( $stats->total_revenue && $stats->total_cost ) {
                        $profit_margin = ( ( $stats->total_revenue - $stats->total_cost ) / $stats->total_revenue ) * 100;
                    }
                    echo esc_html( number_format( $profit_margin, 1 ) ); 
                    ?>%
                </span>
                <div class="smi-stat-label"><?php esc_html_e( 'Profit Margin', 'sell-my-images' ); ?></div>
            </div>
            
            <div class="smi-stat-card">
                <span class="smi-stat-value">$<?php echo esc_html( number_format( $stats->avg_sale_price ?: 0, 2 ) ); ?></span>
                <div class="smi-stat-label"><?php esc_html_e( 'Average Sale', 'sell-my-images' ); ?></div>
            </div>
            
            <div class="smi-stat-card">
                <span class="smi-stat-value"><?php echo esc_html( number_format( $stats->unique_customers ?: 0 ) ); ?></span>
                <div class="smi-stat-label"><?php esc_html_e( 'Unique Customers', 'sell-my-images' ); ?></div>
            </div>
            
            <div class="smi-stat-card">
                <span class="smi-stat-value"><?php echo esc_html( number_format( $stats->total_clicks ?: 0 ) ); ?></span>
                <div class="smi-stat-label"><?php esc_html_e( 'Total Button Clicks', 'sell-my-images' ); ?></div>
            </div>
            
            <div class="smi-stat-card">
                <span class="smi-stat-value">
                    <?php echo esc_html( number_format( $stats->avg_conversion_rate ?: 0, 1 ) ); ?>%
                </span>
                <div class="smi-stat-label"><?php esc_html_e( 'Conversion Rate', 'sell-my-images' ); ?></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render the main analytics table
     * 
     * @param array $analytics_data Analytics data grouped by post
     */
    private function render_analytics_table( $analytics_data ) {
        ?>
        <div class="smi-analytics-table">
            <?php foreach ( $analytics_data as $post_data ) : ?>
                <div class="smi-post-row">
                    <div class="smi-post-header">
                        <div>
                            <div class="smi-post-title">
                                <?php if ( $post_data->post_id ) : ?>
                                    <a href="<?php echo esc_url( get_permalink( $post_data->post_id ) ); ?>" target="_blank">
                                        <?php echo esc_html( $post_data->post_title ?: __( 'Unknown Post', 'sell-my-images' ) ); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo esc_html( $post_data->post_title ?: __( 'Unknown Post', 'sell-my-images' ) ); ?>
                                <?php endif; ?>
                                <span class="smi-toggle-icon">▶</span>
                            </div>
                            <div class="smi-post-meta">
                                <?php 
                                /* translators: %s: publication date */
                                printf( 
                                    esc_html__( 'Published: %s', 'sell-my-images' ), 
                                    $post_data->post_date ? esc_html( gmdate( 'M j, Y', strtotime( $post_data->post_date ) ) ) : esc_html__( 'Unknown', 'sell-my-images' )
                                ); ?>
                            </div>
                        </div>
                        
                        <div class="smi-post-stats">
                            <div>
                                <strong class="smi-revenue">$<?php echo esc_html( number_format( $post_data->total_revenue, 2 ) ); ?></strong><br>
                                <small><?php esc_html_e( 'Revenue', 'sell-my-images' ); ?></small>
                            </div>
                            <div>
                                <strong class="smi-success-text">$<?php echo esc_html( number_format( $post_data->total_profit ?: 0, 2 ) ); ?></strong><br>
                                <small><?php esc_html_e( 'Profit', 'sell-my-images' ); ?></small>
                            </div>
                            <div>
                                <strong><?php echo esc_html( $post_data->total_sales ); ?></strong><br>
                                <small><?php esc_html_e( 'Sales', 'sell-my-images' ); ?></small>
                            </div>
                            <div>
                                <strong><?php echo esc_html( number_format( $post_data->pageviews ?: 0 ) ); ?></strong><br>
                                <small><?php esc_html_e( 'Views', 'sell-my-images' ); ?></small>
                            </div>
                            <div>
                                <strong><?php echo esc_html( number_format( $post_data->total_clicks ?: 0 ) ); ?></strong><br>
                                <small><?php esc_html_e( 'Clicks', 'sell-my-images' ); ?></small>
                            </div>
                            <div>
                                <strong<?php echo ( $post_data->ctr ?: 0 ) < 1.0 && ( $post_data->pageviews ?: 0 ) > 100 ? ' class="smi-low-ctr"' : ''; ?>>
                                    <?php echo esc_html( number_format( $post_data->ctr ?: 0, 2 ) ); ?>%
                                </strong><br>
                                <small><?php esc_html_e( 'CTR', 'sell-my-images' ); ?></small>
                            </div>
                            <div>
                                <strong>
                                    <?php echo esc_html( number_format( $post_data->conversion_rate ?: 0, 1 ) ); ?>%
                                </strong><br>
                                <small><?php esc_html_e( 'Conversion', 'sell-my-images' ); ?></small>
                            </div>
                            <div>
                                <strong>
                                    <?php 
                                    $post_margin = 0;
                                    if ( $post_data->total_revenue && $post_data->total_cost ) {
                                        $post_margin = ( ( $post_data->total_revenue - $post_data->total_cost ) / $post_data->total_revenue ) * 100;
                                    }
                                    echo esc_html( number_format( $post_margin, 1 ) ); 
                                    ?>%
                                </strong><br>
                                <small><?php esc_html_e( 'Margin', 'sell-my-images' ); ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="smi-attachments">
                        <?php if ( empty( $post_data->attachments ) ) : ?>
                            <div class="smi-attachment-row">
                                <div colspan="3" class="smi-muted-text" style="text-align: center; font-style: italic;">
                                    <?php esc_html_e( 'No individual image data available', 'sell-my-images' ); ?>
                                </div>
                            </div>
                        <?php else : ?>
                            <?php foreach ( $post_data->attachments as $attachment ) : ?>
                                <div class="smi-attachment-row">
                                    <div>
                                        <?php if ( $attachment->attachment_id && wp_attachment_is_image( $attachment->attachment_id ) ) : ?>
                                            <?php echo wp_get_attachment_image( 
                                                $attachment->attachment_id, 
                                                array( 50, 50 ), 
                                                false, 
                                                array( 
                                                    'class' => 'smi-attachment-thumbnail',
                                                    'alt' => esc_attr__( 'Attachment thumbnail', 'sell-my-images' )
                                                ) 
                                            ); ?>
                                        <?php elseif ( $attachment->image_url ) : ?>
                                            <img src="<?php echo esc_url( $attachment->image_url ); ?>" 
                                                 alt="<?php esc_attr_e( 'Attachment thumbnail', 'sell-my-images' ); ?>" 
                                                 class="smi-attachment-thumbnail">
                                        <?php else : ?>
                                            <div class="smi-attachment-thumbnail">
                                                <span style="font-size: 20px;">📷</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="smi-attachment-details">
                                        <h4><?php 
                                        /* translators: %d: attachment ID */
                                        printf( esc_html__( 'Image ID: %d', 'sell-my-images' ), esc_html( $attachment->attachment_id ?: 0 ) ); ?></h4>
                                        <div class="smi-attachment-meta">
                                            <?php if ( $attachment->image_width && $attachment->image_height ) : ?>
                                                <div><?php printf( 
                                                    /* translators: 1: image width, 2: image height */
                                                    esc_html__( 'Dimensions: %1$dx%2$d', 'sell-my-images' ), 
                                                    esc_html( $attachment->image_width ), 
                                                    esc_html( $attachment->image_height ) 
                                                ); ?></div>
                                            <?php endif; ?>
                                            <?php if ( $attachment->resolutions_sold ) : ?>
                                                <div><?php 
                                                /* translators: %s: list of resolutions */
                                                printf( esc_html__( 'Resolutions sold: %s', 'sell-my-images' ), esc_html( $attachment->resolutions_sold ) ); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="smi-attachment-stats">
                                        <div class="smi-revenue">$<?php echo esc_html( number_format( $attachment->revenue, 2 ) ); ?></div>
                                        <div class="smi-success-text">$<?php echo esc_html( number_format( $attachment->profit ?: 0, 2 ) ); ?> profit</div>
                                        <div><?php 
                                        /* translators: %d: number of sales */
                                        printf( esc_html__( '%d sales', 'sell-my-images' ), esc_html( $attachment->sales_count ) ); ?></div>
                                        <div><?php 
                                        /* translators: %d: number of clicks */
                                        printf( esc_html__( '%d clicks', 'sell-my-images' ), esc_html( $attachment->click_count ?: 0 ) ); ?></div>
                                        <div><?php 
                                        /* translators: %.1f: conversion rate percentage */
                                        printf( esc_html__( '%.1f%% conversion', 'sell-my-images' ), esc_html( $attachment->conversion_rate ?: 0 ) ); ?></div>
                                        <div><?php 
                                        /* translators: %s: average price amount */
                                        printf( esc_html__( '$%s avg', 'sell-my-images' ), esc_html( number_format( $attachment->avg_price, 2 ) ) ); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
    
    /**
     * Add click data from post meta to sales results
     * 
     * @param array $raw_data Raw data from sales query
     * @return array Enhanced data with click information
     */
    private function add_click_data_to_results( $raw_data ) {
        if ( empty( $raw_data ) ) {
            return $raw_data;
        }
        
        // Get unique post IDs from the results
        $post_ids = array_unique( array_column( $raw_data, 'post_id' ) );
        
        // Fetch click data for all posts in a single query
        $click_data_by_post = $this->get_click_data_for_posts( $post_ids );
        
        // Enhance each result with click data
        foreach ( $raw_data as $result ) {
            $post_id = $result->post_id;
            $attachment_id = $result->attachment_id;
            
            // Get click data for this post
            $post_click_data = $click_data_by_post[ $post_id ] ?? array();
            
            // Calculate clicks for this specific attachment
            $attachment_key = 'attachment_' . $attachment_id;
            $attachment_clicks = isset( $post_click_data[ $attachment_key ] ) ? intval( $post_click_data[ $attachment_key ] ) : 0;
            
            // Add click data to result
            $result->attachment_clicks = $attachment_clicks;
            $result->total_clicks = isset( $post_click_data['total_clicks'] ) ? intval( $post_click_data['total_clicks'] ) : 0;
            
            // Calculate conversion rate (sales / clicks)
            if ( $attachment_clicks > 0 ) {
                $result->conversion_rate = ( $result->sales_count / $attachment_clicks ) * 100;
            } else {
                $result->conversion_rate = 0;
            }
        }
        
        return $raw_data;
    }
    
    /**
     * Get pageviews for multiple posts efficiently via _post_views meta
     * 
     * Cross-references the theme's view counter (stored as _post_views post meta)
     * to calculate click-through rates on the analytics page.
     * 
     * @param array $post_ids Array of post IDs
     * @return array Pageviews indexed by post ID
     */
    private function get_pageviews_for_posts( $post_ids ) {
        if ( empty( $post_ids ) ) {
            return array();
        }
        
        global $wpdb;
        
        $post_ids = array_map( 'intval', $post_ids );
        $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
        
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_value 
             FROM {$wpdb->postmeta} 
             WHERE post_id IN ($placeholders) 
             AND meta_key = '_post_views'",
            ...$post_ids
        ) );
        
        $views_by_post = array();
        foreach ( $results as $row ) {
            $views_by_post[ $row->post_id ] = intval( $row->meta_value );
        }
        
        return $views_by_post;
    }
    
    /**
     * Get click data for multiple posts efficiently
     * 
     * @param array $post_ids Array of post IDs
     * @return array Click data indexed by post ID
     */
    private function get_click_data_for_posts( $post_ids ) {
        if ( empty( $post_ids ) ) {
            return array();
        }
        
        // Prepare IN clause for post IDs
        $post_ids_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
        
        global $wpdb;
        
        // Fetch all click analytics meta in one query
        $query = "
            SELECT post_id, meta_value 
            FROM {$wpdb->postmeta} 
            WHERE post_id IN ($post_ids_placeholders) 
            AND meta_key = %s
        ";
        
        $query_args = array_merge( $post_ids, array( \SellMyImages\Managers\AnalyticsTracker::META_KEY ) );
        $results = $wpdb->get_results( $wpdb->prepare( $query, ...$query_args ) );
        
        $click_data_by_post = array();
        
        foreach ( $results as $result ) {
            $post_id = $result->post_id;
            $click_data = maybe_unserialize( $result->meta_value );
            
            if ( is_array( $click_data ) ) {
                $click_data_by_post[ $post_id ] = $click_data;
            }
        }
        
        return $click_data_by_post;
    }
}