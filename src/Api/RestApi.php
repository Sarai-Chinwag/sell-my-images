<?php
/**
 * REST API Class
 *
 * Minimal REST endpoints that cannot be replaced by Abilities API
 * (e.g., file downloads that serve binary responses).
 *
 * All other endpoints have been migrated to CheckoutAbilities.php.
 *
 * @package SellMyImages
 * @since 2.0.0
 */

namespace SellMyImages\Api;

use SellMyImages\Managers\DownloadManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestApi {

	const NAMESPACE = 'smi/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	/**
	 * Register REST API endpoints.
	 *
	 * Only the download endpoint remains — it serves binary file responses
	 * which cannot be handled by the Abilities API.
	 */
	public function register_endpoints() {
		register_rest_route(
			self::NAMESPACE,
			'/download/(?P<token>[a-zA-Z0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download_image' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Serve image download via token.
	 *
	 * @param \WP_REST_Request $request Request object.
	 */
	public function download_image( $request ) {
		$token = $request->get_param( 'token' );
		DownloadManager::serve_download( $token );
		exit;
	}
}
