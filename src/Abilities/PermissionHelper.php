<?php
/**
 * Permission helper for Sell My Images abilities.
 *
 * Centralizes permission logic to handle WP-CLI and web contexts.
 *
 * @package SellMyImages\Abilities
 * @since 1.7.2
 */

namespace SellMyImages\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PermissionHelper {

	/**
	 * Check if current context has admin-level permissions.
	 *
	 * Allows execution in:
	 * - WP-CLI context (command line runs as root/admin)
	 * - Standard web requests with logged-in admin user
	 *
	 * @since 1.7.2
	 *
	 * @return bool True if permission granted.
	 */
	public static function can_manage(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		return current_user_can( 'manage_options' );
	}
}
