<?php
/**
 * SMI Inventory Abilities
 * 
 * WordPress Abilities API integration for content inventory analysis.
 * Helps identify posts that need more images (images = products).
 *
 * @package SellMyImages\Abilities
 */

namespace SellMyImages\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class InventoryAbilities {

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
     * Register all inventory abilities
     */
    public static function register_abilities(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        wp_register_ability(
            'sell-my-images/scan-low-image-posts',
            array(
                'label'               => __( 'Scan Low-Image Posts', 'sell-my-images' ),
                'description'         => __( 'Find posts with few images relative to content length. These are opportunities to add more sellable products.', 'sell-my-images' ),
                'category'            => 'content',
                'execute_callback'    => array( __CLASS__, 'scan_low_image_posts' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'min_images' => array(
                            'type'        => 'integer',
                            'description' => __( 'Minimum images required (default: 2)', 'sell-my-images' ),
                            'default'     => 2,
                        ),
                        'words_per_image' => array(
                            'type'        => 'integer',
                            'description' => __( 'Max words allowed per image (default: 500)', 'sell-my-images' ),
                            'default'     => 500,
                        ),
                        'limit' => array(
                            'type'        => 'integer',
                            'description' => __( 'Maximum posts to return (default: 50)', 'sell-my-images' ),
                            'default'     => 50,
                        ),
                        'post_type' => array(
                            'type'        => 'string',
                            'description' => __( 'Post type to scan (default: post)', 'sell-my-images' ),
                            'default'     => 'post',
                        ),
                    ),
                ),
                'output_schema'       => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'           => array( 'type' => 'integer' ),
                            'title'        => array( 'type' => 'string' ),
                            'url'          => array( 'type' => 'string' ),
                            'image_count'  => array( 'type' => 'integer' ),
                            'word_count'   => array( 'type' => 'integer' ),
                            'images_needed' => array( 'type' => 'integer' ),
                            'reason'       => array( 'type' => 'string' ),
                        ),
                    ),
                ),
            )
        );

        wp_register_ability(
            'sell-my-images/get-image-stats',
            array(
                'label'               => __( 'Get Image Stats', 'sell-my-images' ),
                'description'         => __( 'Get image count and word count statistics for a specific post.', 'sell-my-images' ),
                'category'            => 'content',
                'execute_callback'    => array( __CLASS__, 'get_image_stats' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'required'   => array( 'post_id' ),
                    'properties' => array(
                        'post_id' => array(
                            'type'        => 'integer',
                            'description' => __( 'The post ID to analyze', 'sell-my-images' ),
                        ),
                    ),
                ),
                'output_schema'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'              => array( 'type' => 'integer' ),
                        'title'           => array( 'type' => 'string' ),
                        'url'             => array( 'type' => 'string' ),
                        'image_count'     => array( 'type' => 'integer' ),
                        'word_count'      => array( 'type' => 'integer' ),
                        'words_per_image' => array( 'type' => 'number' ),
                        'images'          => array( 
                            'type' => 'array',
                            'items' => array(
                                'type' => 'object',
                                'properties' => array(
                                    'id'  => array( 'type' => 'integer' ),
                                    'url' => array( 'type' => 'string' ),
                                    'alt' => array( 'type' => 'string' ),
                                ),
                            ),
                        ),
                    ),
                ),
            )
        );

        wp_register_ability(
            'sell-my-images/get-inventory-summary',
            array(
                'label'               => __( 'Get Inventory Summary', 'sell-my-images' ),
                'description'         => __( 'Get an overview of image inventory across all posts.', 'sell-my-images' ),
                'category'            => 'content',
                'execute_callback'    => array( __CLASS__, 'get_inventory_summary' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'post_type' => array(
                            'type'        => 'string',
                            'description' => __( 'Post type to analyze (default: post)', 'sell-my-images' ),
                            'default'     => 'post',
                        ),
                    ),
                ),
                'output_schema'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'total_posts'       => array( 'type' => 'integer' ),
                        'total_images'      => array( 'type' => 'integer' ),
                        'avg_images_per_post' => array( 'type' => 'number' ),
                        'posts_with_0_images' => array( 'type' => 'integer' ),
                        'posts_with_1_image'  => array( 'type' => 'integer' ),
                        'posts_with_2plus'    => array( 'type' => 'integer' ),
                        'opportunity_posts'   => array( 'type' => 'integer' ),
                    ),
                ),
            )
        );
    }

    /**
     * Scan for posts with low image counts
     */
    public static function scan_low_image_posts( array $input ): array {
        $min_images = isset( $input['min_images'] ) ? absint( $input['min_images'] ) : 2;
        $words_per_image = isset( $input['words_per_image'] ) ? absint( $input['words_per_image'] ) : 500;
        $limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : 50;
        $post_type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'post';

        $posts = get_posts( array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $low_image_posts = array();

        foreach ( $posts as $post ) {
            $stats = self::analyze_post( $post );
            
            $needs_images = false;
            $reason = '';
            $images_needed = 0;
            
            // Check minimum images
            if ( $stats['image_count'] < $min_images ) {
                $needs_images = true;
                $images_needed = $min_images - $stats['image_count'];
                $reason = sprintf( 'Only %d images (minimum: %d)', $stats['image_count'], $min_images );
            }
            
            // Check words per image ratio
            if ( $stats['word_count'] > 0 && $stats['image_count'] > 0 ) {
                $ratio = $stats['word_count'] / $stats['image_count'];
                if ( $ratio > $words_per_image ) {
                    $needs_images = true;
                    $ideal_images = ceil( $stats['word_count'] / $words_per_image );
                    $images_needed = max( $images_needed, $ideal_images - $stats['image_count'] );
                    $reason = sprintf( '%d words / %d images = %.0f words per image (max: %d)', 
                        $stats['word_count'], $stats['image_count'], $ratio, $words_per_image );
                }
            } elseif ( $stats['word_count'] > $words_per_image && $stats['image_count'] === 0 ) {
                $needs_images = true;
                $images_needed = max( $min_images, ceil( $stats['word_count'] / $words_per_image ) );
                $reason = sprintf( '%d words with 0 images', $stats['word_count'] );
            }
            
            if ( $needs_images ) {
                $low_image_posts[] = array(
                    'id'            => $post->ID,
                    'title'         => $post->post_title,
                    'url'           => get_permalink( $post->ID ),
                    'image_count'   => $stats['image_count'],
                    'word_count'    => $stats['word_count'],
                    'images_needed' => $images_needed,
                    'reason'        => $reason,
                );
            }
            
            if ( count( $low_image_posts ) >= $limit ) {
                break;
            }
        }

        return $low_image_posts;
    }

    /**
     * Get detailed image stats for a single post
     */
    public static function get_image_stats( array $input ): array {
        if ( ! isset( $input['post_id'] ) ) {
            return array( 'error' => 'post_id is required' );
        }

        $post = get_post( absint( $input['post_id'] ) );
        if ( ! $post ) {
            return array( 'error' => 'Post not found' );
        }

        $stats = self::analyze_post( $post );
        $stats['id'] = $post->ID;
        $stats['title'] = $post->post_title;
        $stats['url'] = get_permalink( $post->ID );
        $stats['words_per_image'] = $stats['image_count'] > 0 
            ? round( $stats['word_count'] / $stats['image_count'], 1 ) 
            : null;

        return $stats;
    }

    /**
     * Get overall inventory summary
     */
    public static function get_inventory_summary( array $input ): array {
        $post_type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'post';

        $posts = get_posts( array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ) );

        $total_images = 0;
        $posts_with_0 = 0;
        $posts_with_1 = 0;
        $posts_with_2plus = 0;
        $opportunity_posts = 0;

        foreach ( $posts as $post ) {
            $stats = self::analyze_post( $post );
            $total_images += $stats['image_count'];
            
            if ( $stats['image_count'] === 0 ) {
                $posts_with_0++;
                $opportunity_posts++;
            } elseif ( $stats['image_count'] === 1 ) {
                $posts_with_1++;
                $opportunity_posts++;
            } else {
                $posts_with_2plus++;
            }
            
            // Also count high word count / low image posts as opportunities
            if ( $stats['image_count'] >= 2 && $stats['word_count'] > 0 ) {
                $ratio = $stats['word_count'] / $stats['image_count'];
                if ( $ratio > 500 ) {
                    $opportunity_posts++;
                }
            }
        }

        return array(
            'total_posts'         => count( $posts ),
            'total_images'        => $total_images,
            'avg_images_per_post' => count( $posts ) > 0 ? round( $total_images / count( $posts ), 2 ) : 0,
            'posts_with_0_images' => $posts_with_0,
            'posts_with_1_image'  => $posts_with_1,
            'posts_with_2plus'    => $posts_with_2plus,
            'opportunity_posts'   => $opportunity_posts,
        );
    }

    /**
     * Analyze a post for image and word counts
     */
    private static function analyze_post( \WP_Post $post ): array {
        $content = $post->post_content;
        $images = array();
        
        // Check for featured image first
        $featured_id = get_post_thumbnail_id( $post->ID );
        $has_featured = false;
        if ( $featured_id ) {
            $has_featured = true;
            $featured_url = wp_get_attachment_url( $featured_id );
            $featured_alt = get_post_meta( $featured_id, '_wp_attachment_image_alt', true );
            $images[] = array(
                'id'  => $featured_id,
                'url' => $featured_url,
                'alt' => $featured_alt ?: '',
                'is_featured' => true,
            );
        }
        
        // Count images in content
        preg_match_all( '/<img[^>]+>/i', $content, $img_matches );
        $content_image_count = count( $img_matches[0] );
        
        // Extract image details from content
        preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*(?:alt=["\']([^"\']*)["\'])?[^>]*>/i', $content, $matches, PREG_SET_ORDER );
        foreach ( $matches as $match ) {
            $img = array(
                'url' => $match[1],
                'alt' => isset( $match[2] ) ? $match[2] : '',
                'is_featured' => false,
            );
            // Try to get attachment ID from URL
            $attachment_id = attachment_url_to_postid( $match[1] );
            if ( $attachment_id ) {
                $img['id'] = $attachment_id;
            }
            $images[] = $img;
        }
        
        // Total image count = featured + content images
        $image_count = ( $has_featured ? 1 : 0 ) + $content_image_count;
        
        // Count words (strip HTML first)
        $text = wp_strip_all_tags( $content );
        $word_count = str_word_count( $text );
        
        return array(
            'image_count'         => $image_count,
            'content_image_count' => $content_image_count,
            'has_featured'        => $has_featured,
            'word_count'          => $word_count,
            'images'              => $images,
        );
    }

    /**
     * Permission check
     */
    public static function can_manage(): bool {
        return PermissionHelper::can_manage();
    }
}
