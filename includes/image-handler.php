<?php
/**
 * Image Handler — Alt Text Generation Utilities
 *
 * Generates descriptive alt text from attachment title/post context,
 * fixes existing attachments missing alt text, and hooks new uploads
 * so they always get alt text automatically.
 *
 * @package SellMyImages
 * @since 1.7.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// Auto-generate alt text for new uploads
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Auto-populate alt text when a new attachment is uploaded.
 *
 * Fires after WordPress generates attachment metadata, which is the last
 * reliable hook before the attachment is considered "saved".
 *
 * @param array $metadata      The attachment metadata.
 * @param int   $attachment_id The attachment post ID.
 * @return array Unmodified metadata (this is a filter, so we must return it).
 */
function smi_auto_alt_on_upload( $metadata, $attachment_id ) {
	// Only images
	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		return $metadata;
	}

	// Skip if alt text already set
	$existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
	if ( ! empty( $existing ) ) {
		return $metadata;
	}

	$alt = smi_generate_alt_for_attachment( $attachment_id );
	if ( $alt ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
	}

	return $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'smi_auto_alt_on_upload', 10, 2 );

// ──────────────────────────────────────────────────────────────────────────────
// Core functions
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Generate the best available alt text for an attachment.
 *
 * Resolution order:
 *   1. Clean attachment title (if it looks like an article headline)
 *   2. Core subject extracted from an AI image-gen prompt (title)
 *   3. Parent post title
 *   4. Title of any post that uses this image as its featured image
 *
 * @param int $attachment_id The attachment post ID.
 * @return string Alt text, or empty string if nothing usable found.
 */
function smi_generate_alt_for_attachment( $attachment_id ) {
	$attachment = get_post( $attachment_id );
	if ( ! $attachment ) {
		return '';
	}

	// 1. Try the attachment's own title
	$title = trim( $attachment->post_title );
	if ( $title ) {
		$alt = smi_generate_alt_from_title( $title );
		if ( $alt ) {
			return $alt;
		}
	}

	// 2. Try the parent post title
	if ( $attachment->post_parent ) {
		$parent = get_post( $attachment->post_parent );
		if ( $parent && ! empty( $parent->post_title ) ) {
			return smi_clean_headline( $parent->post_title );
		}
	}

	// 3. Find any post that uses this as a featured image
	$featured_post_ids = get_posts( array(
		'post_type'      => 'any',
		'post_status'    => 'publish',
		'meta_key'       => '_thumbnail_id',
		'meta_value'     => $attachment_id,
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );

	if ( ! empty( $featured_post_ids ) ) {
		$featured_post = get_post( $featured_post_ids[0] );
		if ( $featured_post && ! empty( $featured_post->post_title ) ) {
			return smi_clean_headline( $featured_post->post_title );
		}
	}

	return '';
}

/**
 * Generate alt text from a title string.
 *
 * Handles two cases:
 *   - Article-style headlines  ("The Spiritual Meaning of Goldfinches")
 *   - AI image-gen prompts     ("A vibrant peacock feather, photorealistic …")
 *
 * @param string $title The raw title.
 * @return string Clean alt text (max 125 chars), or empty string.
 */
function smi_generate_alt_from_title( $title ) {
	$title = trim( $title );
	if ( empty( $title ) ) {
		return '';
	}

	// Skip obviously useless titles
	if ( preg_match( '/^(untitled|image|img|photo|picture|\d+)$/i', $title ) ) {
		return '';
	}

	// Detect AI image-gen prompt by presence of photography/technical terms
	$ai_markers = array(
		'photorealistic', 'macro photography', 'landscape photography',
		'nature photography', 'editorial photograph', 'shallow depth of field',
		'bokeh', 'depth of field', 'soft morning light', 'warm tones',
		'cool tones', 'golden hour', 'aspect ratio', '3:4', '4:3', '16:9',
		'f/', 'ISO ', 'mm lens', 'composition:', 'close-mid view',
		'portrait orientation', 'dark background', 'white background',
	);

	$is_ai_prompt = false;
	foreach ( $ai_markers as $marker ) {
		if ( stripos( $title, $marker ) !== false ) {
			$is_ai_prompt = true;
			break;
		}
	}

	if ( $is_ai_prompt ) {
		return smi_extract_subject_from_prompt( $title );
	}

	return smi_clean_headline( $title );
}

/**
 * Extract the core visual subject from an AI image-generation prompt.
 *
 * Strategy: find the earliest occurrence of a technical photography/AI term
 * and take everything before it as the "subject".  Falls back to the first
 * sentence if no technical term is found early enough.
 *
 * Example: "A vibrant peacock feather with iridescent blue-green colors,
 *            photorealistic macro photography, dark background"
 *       → "Vibrant peacock feather with iridescent blue-green colors"
 *
 * Example: "A serene, early-dawn scene showing a variety of small songbirds
 *            perched along a slender branch in soft morning light. Composition:
 *            vertical (3:4) …"
 *       → "Serene, early-dawn scene showing a variety of small songbirds
 *            perched along a slender branch in"
 *
 * @param string $prompt The AI prompt string.
 * @return string Extracted subject (max 125 chars).
 */
function smi_extract_subject_from_prompt( $prompt ) {
	// Technical terms that signal "description ends here"
	$ai_markers = array(
		'photorealistic', 'macro photography', 'landscape photography',
		'nature photography', 'editorial photograph', 'shallow depth of field',
		'depth of field', 'bokeh', 'soft morning light', 'warm tones',
		'cool tones', 'golden hour', 'aspect ratio', '3:4', '4:3', '16:9',
		'f/', 'ISO ', 'mm lens', 'composition:', 'close-mid view',
		'portrait orientation', 'dark background', 'white background',
		'photorealistic', 'soft ethereal', 'dramatic sunset', 'ethereal',
	);

	// Find the earliest occurrence of any technical marker
	$cut = mb_strlen( $prompt );
	foreach ( $ai_markers as $marker ) {
		$pos = mb_stripos( $prompt, $marker );
		if ( $pos !== false && $pos < $cut ) {
			$cut = $pos;
		}
	}

	$subject = trim( mb_substr( $prompt, 0, $cut ), " \t\n\r\0\x0B,." );

	// If cut happened too early (< 20 chars), fall back to the first sentence
	if ( mb_strlen( $subject ) < 20 ) {
		$parts   = explode( '.', $prompt );
		$subject = trim( $parts[0] );
	}

	// Strip leading articles ("A ", "An ", "The ")
	$subject = preg_replace( '/^(A|An|The)\s+/i', '', $subject );

	// Capitalise first letter
	if ( $subject ) {
		$subject = mb_strtoupper( mb_substr( $subject, 0, 1 ) ) . mb_substr( $subject, 1 );
	}

	return mb_substr( $subject, 0, 125 );
}

/**
 * Clean a plain headline for use as alt text.
 *
 * Strips HTML, trims, and enforces the 125-char accessibility limit.
 * Truncation happens at the nearest word boundary so we never cut mid-word.
 *
 * @param string $headline Raw headline string.
 * @return string Clean alt text.
 */
function smi_clean_headline( $headline ) {
	$clean = wp_strip_all_tags( $headline );
	$clean = trim( $clean );

	if ( mb_strlen( $clean ) <= 125 ) {
		return $clean;
	}

	// Truncate at the last word boundary within 125 chars
	$truncated = mb_substr( $clean, 0, 125 );
	$last_space = mb_strrpos( $truncated, ' ' );
	if ( $last_space !== false ) {
		$truncated = mb_substr( $truncated, 0, $last_space );
	}

	return $truncated;
}

/**
 * Return all image attachments that are missing alt text.
 *
 * @return int[] Array of attachment IDs.
 */
function smi_get_attachments_missing_alt() {
	global $wpdb;

	$ids = $wpdb->get_col(
		"SELECT p.ID
		 FROM {$wpdb->posts} p
		 WHERE p.post_type   = 'attachment'
		   AND p.post_mime_type LIKE 'image/%'
		   AND NOT EXISTS (
		       SELECT 1
		       FROM   {$wpdb->postmeta} pm
		       WHERE  pm.post_id   = p.ID
		         AND  pm.meta_key  = '_wp_attachment_image_alt'
		         AND  pm.meta_value != ''
		   )"
	);

	return array_map( 'intval', $ids );
}

/**
 * Fix alt text for a single attachment.
 *
 * @param int  $attachment_id The attachment post ID.
 * @param bool $dry_run       If true, return the generated alt without saving.
 * @return array{id:int, alt:string, saved:bool} Result array.
 */
function smi_fix_attachment_alt( $attachment_id, $dry_run = false ) {
	$alt   = smi_generate_alt_for_attachment( $attachment_id );
	$saved = false;

	if ( $alt && ! $dry_run ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		$saved = true;
	}

	return array(
		'id'    => $attachment_id,
		'alt'   => $alt,
		'saved' => $saved,
	);
}

/**
 * Fix alt text for all attachments currently missing it.
 *
 * @param bool $dry_run If true, report what would be changed without saving.
 * @return array[] Array of result arrays from smi_fix_attachment_alt().
 */
function smi_fix_all_missing_alt( $dry_run = false ) {
	$ids     = smi_get_attachments_missing_alt();
	$results = array();

	foreach ( $ids as $id ) {
		$results[] = smi_fix_attachment_alt( $id, $dry_run );
	}

	return $results;
}
