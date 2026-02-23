<?php
/**
 * SMI Checkout Abilities
 *
 * WordPress Abilities API integration for the public checkout flow.
 * Replaces the legacy REST/AJAX endpoints with proper abilities.
 *
 * @package SellMyImages\Abilities
 */

namespace SellMyImages\Abilities;

use SellMyImages\Managers\JobManager;
use SellMyImages\Managers\AnalyticsTracker;
use SellMyImages\Services\PaymentService;
use SellMyImages\Api\CostCalculator;
use SellMyImages\Config\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckoutAbilities {

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
	 * Register all checkout abilities
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sell-my-images/calculate-prices',
			array(
				'label'               => __( 'Calculate Image Prices', 'sell-my-images' ),
				'description'         => __( 'Calculate upscaling prices for all resolution options of a given image attachment.', 'sell-my-images' ),
				'category'            => 'content',
				'meta' => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => true ) ),
				'execute_callback'    => array( __CLASS__, 'calculate_prices' ),
				'permission_callback' => '__return_true',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array(
							'type'        => 'integer',
							'description' => __( 'WordPress attachment ID', 'sell-my-images' ),
						),
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Post ID containing the image', 'sell-my-images' ),
						),
					),
					'required' => array( 'attachment_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'prices' => array(
							'type'        => 'array',
							'description' => __( 'Pricing for each resolution option', 'sell-my-images' ),
						),
						'image' => array(
							'type'        => 'object',
							'description' => __( 'Image metadata', 'sell-my-images' ),
						),
					),
				),
			)
		);

		wp_register_ability(
			'sell-my-images/create-checkout',
			array(
				'label'               => __( 'Create Checkout Session', 'sell-my-images' ),
				'description'         => __( 'Create a Stripe checkout session for image upscaling. Includes duplicate prevention — reuses existing pending jobs for the same image+resolution.', 'sell-my-images' ),
				'category'            => 'content',
				'meta' => array( 'show_in_rest' => true, 'annotations' => array( 'destructive' => true ) ),
				'execute_callback'    => array( __CLASS__, 'create_checkout' ),
				'permission_callback' => '__return_true',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array(
							'type'        => 'integer',
							'description' => __( 'WordPress attachment ID', 'sell-my-images' ),
						),
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Post ID containing the image', 'sell-my-images' ),
						),
						'resolution' => array(
							'type'        => 'string',
							'description' => __( 'Upscale factor', 'sell-my-images' ),
							'enum'        => Constants::VALID_RESOLUTIONS,
						),
						'email' => array(
							'type'        => 'string',
							'description' => __( 'Customer email (optional, backfilled from Stripe)', 'sell-my-images' ),
						),
					),
					'required' => array( 'attachment_id', 'post_id', 'resolution' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'job_id' => array(
							'type'        => 'string',
							'description' => __( 'Job ID', 'sell-my-images' ),
						),
						'checkout_url' => array(
							'type'        => 'string',
							'description' => __( 'Stripe checkout URL', 'sell-my-images' ),
						),
						'amount' => array(
							'type'        => 'number',
							'description' => __( 'Amount to charge', 'sell-my-images' ),
						),
						'reused' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether an existing pending job was reused', 'sell-my-images' ),
						),
					),
				),
			)
		);

		wp_register_ability(
			'sell-my-images/get-job-status',
			array(
				'label'               => __( 'Get Job Status', 'sell-my-images' ),
				'description'         => __( 'Check the status of an image upscaling job.', 'sell-my-images' ),
				'category'            => 'content',
				'meta' => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => true ) ),
				'execute_callback'    => array( __CLASS__, 'get_job_status' ),
				'permission_callback' => '__return_true',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'job_id' => array(
							'type'        => 'string',
							'description' => __( 'Job ID to check', 'sell-my-images' ),
						),
					),
					'required' => array( 'job_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array(
							'type'        => 'string',
							'description' => __( 'Job status', 'sell-my-images' ),
						),
						'download_url' => array(
							'type'        => 'string',
							'description' => __( 'Download URL when complete', 'sell-my-images' ),
						),
					),
				),
			)
		);

		wp_register_ability(
			'sell-my-images/track-click',
			array(
				'label'               => __( 'Track Button Click', 'sell-my-images' ),
				'description'         => __( 'Track an image buy button click for analytics.', 'sell-my-images' ),
				'category'            => 'content',
				'meta' => array( 'show_in_rest' => true, 'annotations' => array( 'destructive' => true ) ),
				'execute_callback'    => array( __CLASS__, 'track_click' ),
				'permission_callback' => '__return_true',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Post ID', 'sell-my-images' ),
						),
						'attachment_id' => array(
							'type'        => 'integer',
							'description' => __( 'Attachment ID', 'sell-my-images' ),
						),
					),
					'required' => array( 'post_id', 'attachment_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'tracked' => array(
							'type' => 'boolean',
						),
					),
				),
			)
		);
	}

	/**
	 * Calculate prices for all resolution options.
	 *
	 * @param array $input Input parameters.
	 * @return array|WP_Error
	 */
	public static function calculate_prices( array $input ) {
		$attachment_id = $input['attachment_id'];

		$image_data = self::get_image_data( $attachment_id );
		if ( is_wp_error( $image_data ) ) {
			return $image_data;
		}

		$resolutions = Constants::VALID_RESOLUTIONS;
		$prices      = array();

		foreach ( $resolutions as $resolution ) {
			$cost_data = CostCalculator::calculate_cost_detailed( $image_data, $resolution );
			if ( ! is_wp_error( $cost_data ) && ! empty( $cost_data['customer_price'] ) ) {
				$dims = $cost_data['output_dimensions'] ?? array();
				$prices[] = array(
					'resolution'    => $resolution,
					'price'         => $cost_data['customer_price'],
					'output_width'  => $dims['width'] ?? null,
					'output_height' => $dims['height'] ?? null,
					'credits'       => $cost_data['credits'] ?? null,
					'available'     => true,
				);
			} else {
				$reason = is_wp_error( $cost_data ) ? $cost_data->get_error_message() : 'Price unavailable';
				$prices[] = array(
					'resolution' => $resolution,
					'available'  => false,
					'reason'     => $reason,
				);
			}
		}

		return array(
			'prices' => $prices,
			'image'  => array(
				'src'           => $image_data['src'],
				'width'         => $image_data['width'],
				'height'        => $image_data['height'],
				'attachment_id' => $image_data['attachment_id'],
			),
		);
	}

	/**
	 * Create checkout session with duplicate prevention.
	 *
	 * @param array $input Input parameters.
	 * @return array|WP_Error
	 */
	public static function create_checkout( array $input ) {
		if ( ! get_option( 'smi_enabled', '1' ) ) {
			return new \WP_Error( 'plugin_disabled', __( 'Plugin is currently disabled', 'sell-my-images' ), array( 'status' => 503 ) );
		}

		$payment_service = new PaymentService();
		$stripe_config   = $payment_service->validate_configuration();
		if ( is_wp_error( $stripe_config ) ) {
			return new \WP_Error( 'payment_not_configured', __( 'Payment system not configured', 'sell-my-images' ), array( 'status' => 500 ) );
		}

		$attachment_id = $input['attachment_id'];
		$post_id       = $input['post_id'];
		$resolution    = $input['resolution'];
		$email         = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : null;

		$image_data = self::get_image_data( $attachment_id );
		if ( is_wp_error( $image_data ) ) {
			return $image_data;
		}

		// Duplicate prevention: check for existing pending job with same attachment+resolution
		// created within the last 10 minutes. Reuse the job record but create a fresh Stripe session.
		$existing_job = self::find_recent_pending_job( $attachment_id, $resolution );
		$job_id       = null;

		if ( $existing_job ) {
			$job_id   = $existing_job->job_id;
			$job_data = array( 'job_id' => $job_id );
		}

		if ( ! $job_id ) {
			// Create new job record.
		$job_data = JobManager::create_job( array(
			'image_url'     => $image_data['src'],
			'resolution'    => $resolution,
			'email'         => $email,
			'post_id'       => $post_id,
			'attachment_id' => $image_data['attachment_id'],
			'image_width'   => $image_data['width'],
			'image_height'  => $image_data['height'],
		) );

			if ( is_wp_error( $job_data ) ) {
				return $job_data;
			}

			$job_id = $job_data['job_id'];

			// Store cost data.
			$cost_data = CostCalculator::calculate_cost_detailed( $image_data, $resolution );
			JobManager::update_cost_data( $job_id, $cost_data );
		}

		// Create Stripe checkout session.
		$checkout_result = $payment_service->create_checkout_session(
			$image_data,
			$resolution,
			$email,
			$job_id
		);

		if ( is_wp_error( $checkout_result ) ) {
			if ( ! $existing_job ) {
				JobManager::delete_job( $job_id );
			}
			return $checkout_result;
		}

		// Update job with checkout session ID.
		JobManager::update_checkout_session( $job_id, $checkout_result['session_id'] );

		return array(
			'job_id'       => $job_id,
			'checkout_url' => $checkout_result['checkout_url'],
			'amount'       => $checkout_result['amount'],
			'reused'       => (bool) $existing_job,
		);
	}

	/**
	 * Get job status.
	 *
	 * @param array $input Input parameters.
	 * @return array|WP_Error
	 */
	public static function get_job_status( array $input ) {
		$job_id = sanitize_text_field( $input['job_id'] );
		$job    = JobManager::get_job( $job_id );

		if ( ! $job || is_wp_error( $job ) ) {
			return new \WP_Error( 'job_not_found', __( 'Job not found', 'sell-my-images' ), array( 'status' => 404 ) );
		}

		$result = array(
			'job_id'         => $job->job_id,
			'status'         => $job->status,
			'payment_status' => $job->payment_status,
		);

		if ( 'completed' === $job->status && ! empty( $job->download_token ) ) {
			$result['download_url'] = rest_url( 'smi/v1/download/' . $job->download_token );
		}

		return $result;
	}

	/**
	 * Track button click.
	 *
	 * @param array $input Input parameters.
	 * @return array
	 */
	public static function track_click( array $input ) {
		$post_id       = $input['post_id'];
		$attachment_id = $input['attachment_id'];

		if ( class_exists( '\SellMyImages\Managers\AnalyticsTracker' ) ) {
			AnalyticsTracker::track_button_click( $post_id, $attachment_id );
		}

		return array( 'tracked' => true );
	}

	/**
	 * Find a recent pending job for the same image and resolution.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $resolution    Resolution string.
	 * @return object|null Job object or null.
	 */
	private static function find_recent_pending_job( int $attachment_id, string $resolution ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'smi_jobs';

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 600 ); // 10 minutes ago.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE attachment_id = %d
				AND resolution = %s
				AND status IN ('pending', 'abandoned')
				AND payment_status = 'pending'
				AND created_at > %s
				ORDER BY created_at DESC
				LIMIT 1",
				$attachment_id,
				$resolution,
				$cutoff
			)
		);
	}

	/**
	 * Get image data from attachment ID.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error
	 */
	private static function get_image_data( int $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new \WP_Error( 'invalid_attachment', __( 'Attachment not found', 'sell-my-images' ), array( 'status' => 404 ) );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$src      = wp_get_attachment_url( $attachment_id );

		return array(
			'src'           => $src,
			'attachment_id' => $attachment_id,
			'width'         => $metadata['width'] ?? 0,
			'height'        => $metadata['height'] ?? 0,
		);
	}
}
