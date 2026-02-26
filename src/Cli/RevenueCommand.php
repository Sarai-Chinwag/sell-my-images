<?php
/**
 * WP-CLI Revenue Report Command
 *
 * @package SellMyImages
 * @since 1.7.0
 */

namespace SellMyImages\Cli;

use SellMyImages\Abilities\AnalyticsAbilities;
use WP_CLI;
use WP_CLI\Utils;

/**
 * View revenue reports for Sell My Images.
 */
class RevenueCommand {

	/**
	 * Show a complete revenue report (summary + top posts + funnel).
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to include.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Number of top posts to show.
	 * ---
	 * default: 10
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp smi revenue report
	 *     wp smi revenue report --days=90
	 *
	 * @when after_wp_load
	 */
	public function report( $args, $assoc_args ) {
		$this->summary( $args, $assoc_args );
		WP_CLI::line( '' );
		$this->top_posts( $args, $assoc_args );
		WP_CLI::line( '' );
		$this->funnel( $args, $assoc_args );
	}

	/**
	 * Show sales summary.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to include.
	 * ---
	 * default: 30
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp smi revenue summary
	 *     wp smi revenue summary --days=90
	 *
	 * @when after_wp_load
	 */
	public function summary( $args, $assoc_args ) {
		$days = (int) ( $assoc_args['days'] ?? 30 );
		$data = AnalyticsAbilities::get_sales_summary( array( 'days' => $days ) );

		WP_CLI::line( WP_CLI::colorize( "%B=== Sales Summary ({$days} days) ===%n" ) );
		WP_CLI::line( "Total Jobs:    {$data['total_jobs']}" );
		WP_CLI::line( "Paid Jobs:     {$data['paid_jobs']}" );
		WP_CLI::line( "Total Revenue: \${$data['total_revenue']}" );
		WP_CLI::line( "Total Cost:    \${$data['total_cost']}" );
		WP_CLI::line( "Total Profit:  \${$data['total_profit']}" );
		WP_CLI::line( "Avg Price:     \${$data['avg_price']}" );
	}

	/**
	 * Show top selling posts.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to include.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Number of posts to show.
	 * ---
	 * default: 10
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp smi revenue top-posts
	 *     wp smi revenue top-posts --limit=5 --days=90
	 *
	 * @when after_wp_load
	 */
	public function top_posts( $args, $assoc_args ) {
		$days  = (int) ( $assoc_args['days'] ?? 30 );
		$limit = (int) ( $assoc_args['limit'] ?? 10 );
		$data  = AnalyticsAbilities::get_top_selling_posts( array(
			'days'  => $days,
			'limit' => $limit,
		) );

		WP_CLI::line( WP_CLI::colorize( "%B=== Top Selling Posts ({$days} days) ===%n" ) );

		if ( empty( $data ) ) {
			WP_CLI::line( 'No sales data found.' );
			return;
		}

		Utils\format_items(
			'table',
			$data,
			array( 'post_id', 'post_title', 'sales_count', 'revenue', 'clicks', 'conversion_rate' )
		);
	}

	/**
	 * Show conversion funnel.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to include.
	 * ---
	 * default: 30
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp smi revenue funnel
	 *
	 * @when after_wp_load
	 */
	public function funnel( $args, $assoc_args ) {
		$days = (int) ( $assoc_args['days'] ?? 30 );
		$data = AnalyticsAbilities::get_conversion_funnel( array( 'days' => $days ) );

		WP_CLI::line( WP_CLI::colorize( "%B=== Conversion Funnel ({$days} days) ===%n" ) );
		WP_CLI::line( "Total Clicks:      {$data['total_clicks']}" );
		WP_CLI::line( "Total Sales:       {$data['total_sales']}" );
		WP_CLI::line( "Conversion Rate:   {$data['conversion_rate']}%" );
		WP_CLI::line( "Revenue/Click:     \${$data['revenue_per_click']}" );
	}
}
