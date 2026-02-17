<?php
/**
 * Plugin Name: Sell My Images
 * Plugin URI: https://chubes.net/
 * Description: Monetize your website images by selling high-resolution versions with AI upscaling and secure payment processing. Perfect for photographers, bloggers, and content creators.
 * Version: 1.3.3
 * Author: Chris Huber
 * Author URI: https://chubes.net/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sell-my-images
 * Domain Path: /languages
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: stripe-integration
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'SMI_VERSION', '1.3.3' );
define( 'SMI_PLUGIN_FILE', __FILE__ );
define( 'SMI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SMI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main Sell My Images Plugin Class
 */
class SellMyImages {
    
    /**
     * Single instance of the plugin
     */
    private static $instance = null;
    
    /**
     * Plugin initialization
     */
    public function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
    }
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        // Comparison slider assets now handled by the block itself
        add_action( 'smi_daily_cleanup', array( $this, 'run_daily_cleanup' ) );

        // Plugin activation/deactivation hooks
        register_activation_hook( SMI_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( SMI_PLUGIN_FILE, array( $this, 'deactivate' ) );
    }
    
    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'sell-my-images', false, dirname( SMI_PLUGIN_BASENAME ) . '/languages' );
    }
    
    /**
     * Plugin initialization
     */
    public function init() {
        // Initialize admin functionality (for admin pages and admin AJAX requests)
        if ( is_admin() ) {
            $this->init_admin();
        }
        
        // Initialize frontend functionality (for frontend AND AJAX requests)
        if ( ! is_admin() || wp_doing_ajax() ) {
            $this->init_frontend();
        }
        
        // Initialize Abilities API integration
        \SellMyImages\Abilities\InventoryAbilities::init();
        \SellMyImages\Abilities\UploadAbilities::init();
        \SellMyImages\Abilities\AnalyticsAbilities::init();
        
        // Register blocks
        $this->register_blocks();
    }
    
    /**
     * Register Gutenberg blocks
     */
    private function register_blocks() {
        // Register the image uploader block if build exists
        $block_path = SMI_PLUGIN_DIR . 'build/blocks/image-uploader';
        if ( file_exists( $block_path . '/block.json' ) ) {
            register_block_type( $block_path );
        }

        // Register the comparison slider block if build exists
        $comparison_path = SMI_PLUGIN_DIR . 'build/blocks/comparison-slider';
        if ( file_exists( $comparison_path . '/block.json' ) ) {
            register_block_type( $comparison_path );
        }
    }
    
    private function init_admin() {
        new \SellMyImages\Admin\AdminInit();
    }
    
    private function init_frontend() {
        \SellMyImages\Managers\WebhookManager::init();
        new \SellMyImages\Content\BlockProcessor();
        new \SellMyImages\Api\RestApi();
        new \SellMyImages\Services\PaymentService();
        new \SellMyImages\Services\UpscalingService();
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Load Composer autoloader
        require_once SMI_PLUGIN_DIR . 'vendor/autoload.php';
    }
    
    public function enqueue_frontend_assets() {
        $should_load = false;
        
        // Load on singular posts where buttons should show
        if ( is_singular() && \SellMyImages\Content\FilterManager::should_show_buttons() ) {
            $should_load = true;
        }
        
        // Allow external themes/plugins to trigger asset loading
        $should_load = apply_filters( 'smi_load_assets', $should_load );
        
        if ( ! $should_load ) {
            return;
        }
        
        // Use file modification time for asset versioning to auto-bust caches when files change
        $js_file = SMI_PLUGIN_DIR . 'assets/js/modal.js';
        $css_file = SMI_PLUGIN_DIR . 'assets/css/modal.css';
        $js_version = SMI_VERSION;
        $css_version = SMI_VERSION;
        if ( file_exists( $js_file ) ) {
            $js_version = filemtime( $js_file );
        }
        if ( file_exists( $css_file ) ) {
            $css_version = filemtime( $css_file );
        }

        wp_enqueue_script( 'smi-modal', SMI_PLUGIN_URL . 'assets/js/modal.js', array( 'jquery' ), $js_version, true );
        wp_enqueue_style( 'smi-modal', SMI_PLUGIN_URL . 'assets/css/modal.css', array(), $css_version );
        
        // Localize script for AJAX
        wp_localize_script( 'smi-modal', 'smi_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'smi_nonce' ),
            'post_id'  => get_the_ID(), // Add current post ID for JavaScript
            'strings'  => array(
                'processing' => __( 'Processing...', 'sell-my-images' ),
                'error'      => __( 'An error occurred. Please try again.', 'sell-my-images' ),
                'download'   => __( 'Download', 'sell-my-images' ),
            ),
            'terms_conditions_url' => get_option( 'smi_terms_conditions_url', '' ),
            'contact_url' => get_option( 'smi_contact_url', home_url( '/contact' ) ),
        ) );
        
        // Add REST API settings for modal JavaScript
        wp_localize_script( 'smi-modal', 'wpApiSettings', array(
            'root'  => esc_url_raw( rest_url() ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ) );
        
        // Output modal HTML to footer
        add_action( 'wp_footer', array( $this, 'output_modal_html' ) );
    }

    public function output_modal_html() {
        $template_path = SMI_PLUGIN_DIR . 'templates/modal.php';
        if ( file_exists( $template_path ) ) {
            include $template_path;
        }
    }
    
    // Admin assets are enqueued by AdminInit on specific SMI pages.
    
    /*
     * Plugin activation
     */
    public function activate() {
        // Create database tables using DatabaseManager
        if ( ! \SellMyImages\Managers\DatabaseManager::create_tables() ) {
            // Database table creation failed during activation
            wp_die( esc_html__( 'Failed to create database tables during plugin activation.', 'sell-my-images' ) );
        }

        // Set default options
        $this->set_default_options();

        // Schedule daily cleanup cron
        $this->schedule_cleanup_cron();

        // Clear rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Unschedule daily cleanup cron
        $this->unschedule_cleanup_cron();

        // Clear rewrite rules
        flush_rewrite_rules();
    }
    
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $default_options = \SellMyImages\Config\Constants::DEFAULT_OPTIONS;

        foreach ( $default_options as $option_name => $option_value ) {
            if ( ! get_option( $option_name ) ) {
                add_option( $option_name, $option_value );
            }
        }
    }

    /**
     * Schedule daily cleanup cron job
     */
    private function schedule_cleanup_cron() {
        if ( ! wp_next_scheduled( 'smi_daily_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'smi_daily_cleanup' );
        }
    }

    /**
     * Unschedule daily cleanup cron job
     */
    private function unschedule_cleanup_cron() {
        wp_clear_scheduled_hook( 'smi_daily_cleanup' );
    }

    /**
     * Run daily cleanup tasks
     */
    public function run_daily_cleanup() {
        \SellMyImages\Managers\DatabaseManager::cleanup_expired_downloads();
        \SellMyImages\Managers\DatabaseManager::cleanup_failed_jobs();
        \SellMyImages\Managers\DatabaseManager::cleanup_abandoned_jobs();
        \SellMyImages\Managers\DatabaseManager::cleanup_job_uploads();
        \SellMyImages\Managers\UploadManager::cleanup_expired_uploads();
    }
}

// Initialize the plugin
function sell_my_images() {
    return SellMyImages::get_instance();
}

// Start the plugin
sell_my_images();
