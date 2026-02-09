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

$image_id         = $attributes['imageId'] ?? null;
$image_url        = $attributes['imageUrl'] ?? '';
$blur_amount      = $attributes['blurAmount'] ?? 2;
$initial_position = $attributes['initialPosition'] ?? 50;

// If no image, don't render anything.
if ( empty( $image_url ) && empty( $image_id ) ) {
    return;
}

// Get image URL from ID if not set.
if ( empty( $image_url ) && ! empty( $image_id ) ) {
    $image_url = wp_get_attachment_url( $image_id );
}

if ( empty( $image_url ) ) {
    return;
}

// Get wrapper attributes.
$wrapper_attributes = get_block_wrapper_attributes( array(
    'class' => 'smi-comparison-block',
) );

// Generate unique ID for this instance.
$unique_id = 'smi-comparison-' . wp_unique_id();
?>

<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
    <div class="smi-comparison-container">
        <p class="smi-comparison-instructions">Drag the slider to compare</p>
        <div 
            id="<?php echo esc_attr( $unique_id ); ?>" 
            class="smi-comparison-slider" 
            data-blur="<?php echo esc_attr( $blur_amount ); ?>"
            data-position="<?php echo esc_attr( $initial_position ); ?>"
        >
            <div class="smi-comparison-after-wrap">
                <img 
                    src="<?php echo esc_url( $image_url ); ?>" 
                    alt="<?php esc_attr_e( 'Enhanced image', 'sell-my-images' ); ?>"
                    class="no-lazyload"
                    data-no-lazy="1"
                >
            </div>
            <div class="smi-comparison-before-wrap" style="width: <?php echo esc_attr( $initial_position ); ?>%;">
                <img 
                    src="<?php echo esc_url( $image_url ); ?>" 
                    alt="<?php esc_attr_e( 'Original image', 'sell-my-images' ); ?>"
                    class="no-lazyload"
                    data-no-lazy="1"
                    style="filter: blur(<?php echo esc_attr( $blur_amount ); ?>px) saturate(0.85);"
                >
            </div>
            <div class="smi-comparison-handle" style="left: <?php echo esc_attr( $initial_position ); ?>%;">
                <div class="smi-comparison-handle-circle"></div>
            </div>
        </div>
        <div class="smi-comparison-labels">
            <span><?php esc_html_e( 'Original', 'sell-my-images' ); ?></span>
            <span><?php esc_html_e( '8x Enhanced', 'sell-my-images' ); ?> ✨</span>
        </div>
    </div>
</div>
