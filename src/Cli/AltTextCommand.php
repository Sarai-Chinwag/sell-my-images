<?php
/**
 * WP-CLI Alt Text Command
 *
 * Audit and fix image attachments that are missing alt text.
 *
 * @package SellMyImages
 * @since 1.7.0
 */

namespace SellMyImages\Cli;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Audit and fix missing alt text on image attachments.
 */
class AltTextCommand {

	/**
	 * Show all image attachments that are missing alt text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smi alt-text audit
	 *
	 * @when after_wp_load
	 */
	public function audit( $args, $assoc_args ) {
		$ids = smi_get_attachments_missing_alt();

		if ( empty( $ids ) ) {
			WP_CLI::success( 'All image attachments have alt text. 🎉' );
			return;
		}

		WP_CLI::warning( sprintf( '%d attachment(s) are missing alt text:', count( $ids ) ) );
		WP_CLI::line( '' );

		$rows = array();
		foreach ( $ids as $id ) {
			$post      = get_post( $id );
			$generated = smi_generate_alt_for_attachment( $id );
			$rows[]    = array(
				'ID'            => $id,
				'Title'         => mb_substr( $post->post_title ?? '(none)', 0, 60 ),
				'Generated Alt' => mb_substr( $generated ?: '(could not generate)', 0, 60 ),
			);
		}

		Utils\format_items( 'table', $rows, array( 'ID', 'Title', 'Generated Alt' ) );

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( "%YRun `wp smi alt-text fix` to apply the generated alt text.%n" ) );
	}

	/**
	 * Fix all image attachments that are missing alt text.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Preview what would be changed without saving anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smi alt-text fix
	 *     wp smi alt-text fix --dry-run
	 *
	 * @when after_wp_load
	 */
	public function fix( $args, $assoc_args ) {
		$dry_run = Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$ids = smi_get_attachments_missing_alt();

		if ( empty( $ids ) ) {
			WP_CLI::success( 'No attachments missing alt text. Nothing to do.' );
			return;
		}

		$label = $dry_run ? '(dry run) ' : '';
		WP_CLI::line( WP_CLI::colorize( "%BFixing {$label}alt text for " . count( $ids ) . ' attachment(s)…%n' ) );
		WP_CLI::line( '' );

		$fixed    = 0;
		$skipped  = 0;
		$rows     = array();

		foreach ( $ids as $id ) {
			$result = smi_fix_attachment_alt( $id, $dry_run );

			if ( $result['alt'] ) {
				$rows[] = array(
					'ID'     => $result['id'],
					'Status' => $dry_run ? 'would fix' : 'fixed',
					'Alt'    => mb_substr( $result['alt'], 0, 80 ),
				);
				$fixed++;
			} else {
				$post   = get_post( $id );
				$rows[] = array(
					'ID'     => $id,
					'Status' => 'skipped — no title/context',
					'Alt'    => mb_substr( $post->post_title ?? '(none)', 0, 80 ),
				);
				$skipped++;
			}
		}

		Utils\format_items( 'table', $rows, array( 'ID', 'Status', 'Alt' ) );

		WP_CLI::line( '' );

		if ( $dry_run ) {
			WP_CLI::warning( sprintf( 'Dry run complete: %d would be fixed, %d skipped.', $fixed, $skipped ) );
		} else {
			WP_CLI::success( sprintf( 'Done: %d fixed, %d skipped.', $fixed, $skipped ) );
		}

		// Verify 100% coverage if not dry run
		if ( ! $dry_run ) {
			$remaining = smi_get_attachments_missing_alt();
			if ( empty( $remaining ) ) {
				WP_CLI::success( '100% alt text coverage achieved.' );
			} else {
				WP_CLI::warning( sprintf( '%d attachment(s) still missing alt text (no title/context available).', count( $remaining ) ) );
			}
		}
	}
}
