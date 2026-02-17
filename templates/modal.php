<?php
/**
 * Modal Template
 * 
 * HTML structure for the image upscaling modal
 * 
 * @package SellMyImages
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div id="smi-modal" class="smi-modal smi-hidden">
    <div class="smi-modal-overlay"></div>
    <div class="smi-modal-container">
        <div class="smi-modal-content">
            <div class="smi-modal-header">
                <h2 class="smi-modal-title">Download High-Resolution</h2>
                <button type="button" class="smi-modal-close">&times;</button>
            </div>
            
            <div class="smi-modal-body">
                <!-- Loading state -->
                <div class="smi-loading smi-hidden">
                    <div class="smi-spinner"></div>
                    <p>Loading image information...</p>
                </div>
                
                <!-- Error message -->
                <div class="smi-error-message smi-hidden">
                    <div class="smi-error-icon">⚠️</div>
                    <div class="smi-error-text"></div>
                </div>
                
                <!-- Main content -->
                <div class="smi-modal-main smi-hidden">
                    <div class="smi-image-preview">
                        <img class="smi-preview-image no-lazyload" src="" alt="" data-no-lazy="1" />
                    </div>
                    
                    <div class="smi-upscale-options">
                        <h4>Choose Your Resolution</h4>
                        <div class="smi-resolution-options">
                            
                            <label class="smi-option" for="smi-resolution-4x">
                                <input type="radio" id="smi-resolution-4x" name="resolution" value="4x" checked />
                                <div class="smi-option-label">
                                    <strong>Standard Quality (4x)</strong>
                                    <div class="smi-option-details">Perfect for prints and web</div>
                                </div>
                                <div class="smi-option-price">Calculating...</div>
                            </label>
                            
                            <label class="smi-option" for="smi-resolution-8x">
                                <input type="radio" id="smi-resolution-8x" name="resolution" value="8x" />
                                <div class="smi-option-label">
                                    <strong>Premium Quality (8x)</strong>
                                    <div class="smi-option-details">Professional print quality</div>
                                </div>
                                <div class="smi-option-price">Calculating...</div>
                            </label>
                            
                        </div>
                    </div>
                    
                    <div class="smi-email-field">
                        <label for="smi-email">Email for download link:</label>
                        <input type="email" id="smi-email" name="email" placeholder="your@email.com" />
                        <p class="description">(Optional) Pre-fill your email, or provide it during Stripe checkout</p>
                    </div>
                    
                </div>
            </div>
            
            <div class="smi-modal-footer">
                <button type="button" class="smi-btn smi-btn-primary smi-process-btn" disabled>Checkout with Stripe</button>
                <div class="smi-footer-bottom">
                    <div class="smi-footer-nav">
                        <a href="<?php echo esc_url( get_option( 'smi_terms_conditions_url', '' ) ); ?>" target="_blank" rel="noopener noreferrer" class="smi-footer-nav-link smi-terms-footer-link"><?php esc_html_e( 'Terms & Conditions', 'sell-my-images' ); ?></a>
                        <span class="smi-footer-sep">·</span>
                        <a href="/hi-res-images/" class="smi-footer-nav-link"><?php esc_html_e( 'Learn More', 'sell-my-images' ); ?></a>
                        <span class="smi-footer-sep">·</span>
                        <a href="/upscale/" class="smi-footer-nav-link"><?php esc_html_e( 'Upscale Your Own', 'sell-my-images' ); ?></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>