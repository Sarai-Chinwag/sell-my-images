<?php
/**
 * Comparison Slider Block - Server-side rendering
 *
 * @package SellMyImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$before_image_id  = $attributes['beforeImageId'] ?? null;
$before_image_url = $attributes['beforeImageUrl'] ?? '';
$after_image_id   = $attributes['afterImageId'] ?? null;
$after_image_url  = $attributes['afterImageUrl'] ?? '';
$initial_position = $attributes['initialPosition'] ?? 50;
$before_label     = $attributes['beforeLabel'] ?? __( 'Original', 'sell-my-images' );
$after_label      = $attributes['afterLabel'] ?? __( 'Enhanced', 'sell-my-images' );

// Get image URLs from IDs if not set.
if ( empty( $before_image_url ) && ! empty( $before_image_id ) ) {
    $before_image_url = wp_get_attachment_url( $before_image_id );
}

if ( empty( $after_image_url ) && ! empty( $after_image_id ) ) {
    $after_image_url = wp_get_attachment_url( $after_image_id );
}

// If either image is missing, don't render anything.
if ( empty( $before_image_url ) || empty( $after_image_url ) ) {
    return;
}

// Get wrapper attributes.
$wrapper_attributes = get_block_wrapper_attributes( array(
    'class' => 'smi-comparison-block',
) );

// Generate unique ID for this instance.
$unique_id = 'smi-comparison-' . wp_unique_id();

// Calculate clip-path from position: 0 → inset(0 100% 0 0), 50 → inset(0 50% 0 0), 100 → inset(0 0% 0 0)
$clip_right = 100 - (int) $initial_position;
?>

<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
    <div class="smi-comparison-container">
        <p class="smi-comparison-instructions"><?php esc_html_e( 'Drag the slider to compare', 'sell-my-images' ); ?></p>
        <div
            id="<?php echo esc_attr( $unique_id ); ?>"
            class="smi-comparison-slider"
            data-position="<?php echo esc_attr( $initial_position ); ?>"
        >
            <div class="smi-comparison-before">
                <img
                    src="<?php echo esc_url( $before_image_url ); ?>"
                    alt="<?php echo esc_attr( $before_label ); ?>"
                    class="no-lazyload"
                    data-no-lazy="1"
                >
            </div>
            <div class="smi-comparison-after" style="clip-path: inset(0 <?php echo esc_attr( $clip_right ); ?>% 0 0);">
                <img
                    src="<?php echo esc_url( $after_image_url ); ?>"
                    alt="<?php echo esc_attr( $after_label ); ?>"
                    class="no-lazyload"
                    data-no-lazy="1"
                >
            </div>
            <div class="smi-comparison-handle" style="left: <?php echo esc_attr( $initial_position ); ?>%;">
                <div class="smi-comparison-handle-circle"></div>
            </div>
        </div>
        <div class="smi-comparison-labels">
            <span><?php echo esc_html( $after_label ); ?> ✨</span>
            <span><?php echo esc_html( $before_label ); ?></span>
        </div>
    </div>
</div>