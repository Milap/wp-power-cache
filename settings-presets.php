<?php
/**
 * Settings Presets for WP Power Cache
 * One-click configuration templates for different site types
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WRPC_Settings_Presets' ) ) :

    class WRPC_Settings_Presets {
        
        /**
         * Available presets
         */
        private static $presets = array(
            'e-commerce' => array(
                'label' => 'E-Commerce (WooCommerce)',
                'description' => 'Optimized for product pages and carts',
                'settings' => array(
                    'enable_ttl' => 1,
                    'cache_ttl' => 1800,  // 30 minutes
                    'minify_html' => 1,
                    'enable_logging' => 1,
                    'excluded_urls' => "/cart/\n/checkout/\n?add-to-cart\n/my-account/\n?product_id",
                    'developer_flag' => 0,
                    'debug_flag' => 0,
                ),
            ),
            'blog' => array(
                'label' => 'Blog/Content Site',
                'description' => 'Good for blogs and article sites',
                'settings' => array(
                    'enable_ttl' => 1,
                    'cache_ttl' => 3600,  // 1 hour
                    'minify_html' => 1,
                    'enable_logging' => 0,
                    'excluded_urls' => "/feed/\n?s=\n/search",
                    'developer_flag' => 0,
                    'debug_flag' => 0,
                ),
            ),
            'news' => array(
                'label' => 'News/Magazine',
                'description' => 'Short TTL for frequently updated content',
                'settings' => array(
                    'enable_ttl' => 1,
                    'cache_ttl' => 900,  // 15 minutes
                    'minify_html' => 1,
                    'enable_logging' => 0,
                    'excluded_urls' => "/feed/\n?s=\n/breaking-news",
                    'developer_flag' => 0,
                    'debug_flag' => 0,
                ),
            ),
            'corporate' => array(
                'label' => 'Corporate/Static',
                'description' => 'Long cache duration for stable content',
                'settings' => array(
                    'enable_ttl' => 1,
                    'cache_ttl' => 86400,  // 24 hours
                    'minify_html' => 1,
                    'enable_logging' => 0,
                    'excluded_urls' => "/contact\n/search\n/login",
                    'developer_flag' => 0,
                    'debug_flag' => 0,
                ),
            ),
            'aggressive' => array(
                'label' => 'Aggressive (Maximum Cache)',
                'description' => 'Most caching, least exclusions',
                'settings' => array(
                    'enable_ttl' => 1,
                    'cache_ttl' => 604800,  // 7 days
                    'minify_html' => 1,
                    'enable_logging' => 0,
                    'excluded_urls' => "/admin",
                    'developer_flag' => 0,
                    'debug_flag' => 0,
                ),
            ),
            'conservative' => array(
                'label' => 'Conservative (Maximum Freshness)',
                'description' => 'Short TTL with many exclusions',
                'settings' => array(
                    'enable_ttl' => 1,
                    'cache_ttl' => 300,  // 5 minutes
                    'minify_html' => 1,
                    'enable_logging' => 1,
                    'excluded_urls' => "/cart/\n/checkout/\n?add-to-cart\n/my-account/\n/account/\n?s=\n/search\n/feed/",
                    'developer_flag' => 0,
                    'debug_flag' => 0,
                ),
            ),
        );

        /**
         * Initialize
         */
        public static function init() {
            add_action( 'admin_init', array( __CLASS__, 'register_preset_action' ) );
        }

        /**
         * Get all available presets
         */
        public static function get_presets() {
            return self::$presets;
        }

        /**
         * Get preset by key
         */
        public static function get_preset( $key ) {
            return isset( self::$presets[ $key ] ) ? self::$presets[ $key ] : false;
        }

        /**
         * Register AJAX action for applying presets
         */
        public static function register_preset_action() {
            add_action( 'wp_ajax_wrpc_apply_preset', array( __CLASS__, 'handle_apply_preset' ) );
        }

        /**
         * Apply a preset to current settings
         */
        public static function apply_preset( $preset_key ) {
            $preset = self::get_preset( $preset_key );
            
            if ( ! $preset ) {
                return new WP_Error( 'invalid_preset', __( 'Invalid preset', 'wp-power-cache' ) );
            }

            $settings = (array) get_option( 'wp_power_cache_settings' );
            $new_settings = array_merge( $settings, $preset['settings'] );
            
            $result = update_option( 'wp_power_cache_settings', $new_settings );
            
            return $result;
        }

        /**
         * Handle AJAX preset application
         */
        public static function handle_apply_preset() {
            // Verify nonce
            if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wrpc_presets_nonce' ) ) {
                wp_send_json_error( array( 'message' => __( 'Security check failed', 'wp-power-cache' ) ) );
            }

            // Check permissions
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Permission denied', 'wp-power-cache' ) ) );
            }

            // Get preset key
            $preset_key = isset( $_POST['preset'] ) ? sanitize_text_field( $_POST['preset'] ) : '';
            
            if ( empty( $preset_key ) ) {
                wp_send_json_error( array( 'message' => __( 'No preset specified', 'wp-power-cache' ) ) );
            }

            // Apply preset
            $result = self::apply_preset( $preset_key );
            
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array( 
                'message' => sprintf( 
                    __( 'Preset "%s" applied successfully', 'wp-power-cache' ), 
                    esc_html( self::get_preset( $preset_key )['label'] )
                )
            ) );
        }

        /**
         * Render preset UI
         */
        public static function render_preset_selector() {
            $presets = self::get_presets();
            $current_preset = self::detect_current_preset();
            
            ?>
            <div style="padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">
                <h3 style="margin-top: 0;"><?php _e( 'Quick Setup Presets', 'wp-power-cache' ); ?></h3>
                <p style="color: #666; font-size: 13px;">
                    <?php _e( 'Select a preset to quickly configure cache settings for your site type.', 'wp-power-cache' ); ?>
                </p>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 12px; margin-top: 12px;">
                    <?php foreach ( $presets as $key => $preset ) : ?>
                        <div style="border: 2px solid <?php echo $current_preset === $key ? '#0073aa' : '#ddd'; ?>; padding: 12px; border-radius: 5px; background: <?php echo $current_preset === $key ? '#e7f3ff' : '#fff'; ?>; cursor: pointer;" class="wrpc-preset-card" data-preset="<?php echo esc_attr( $key ); ?>">
                            <strong style="display: block; color: #0073aa; margin-bottom: 4px;">
                                <?php if ( $current_preset === $key ) : ?>
                                    ✔ 
                                <?php endif; ?>
                                <?php echo esc_html( $preset['label'] ); ?>
                            </strong>
                            <p style="margin: 0; font-size: 12px; color: #666; line-height: 1.4;">
                                <?php echo esc_html( $preset['description'] ); ?>
                            </p>
                            <button class="button button-small wrpc-apply-preset" data-preset="<?php echo esc_attr( $key ); ?>" style="margin-top: 8px; width: 100%;">
                                <?php echo $current_preset === $key ? __( 'Currently Active', 'wp-power-cache' ) : __( 'Apply This Preset', 'wp-power-cache' ); ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php wp_nonce_field( 'wrpc_presets_nonce', 'wrpc_presets_nonce', false ); ?>
            </div>

            <script>
            jQuery(document).ready(function($) {
                $('.wrpc-apply-preset').on('click', function(e) {
                    e.preventDefault();
                    
                    var $btn = $(this);
                    var preset = $btn.data('preset');
                    var $card = $btn.closest('.wrpc-preset-card');
                    
                    $btn.prop('disabled', true).text('<?php _e( 'Applying...', 'wp-power-cache' ); ?>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wrpc_apply_preset',
                            preset: preset,
                            nonce: $('input[name="wrpc_presets_nonce"]').val()
                        },
                        success: function(response) {
                            if (response.success) {
                                // Update all buttons
                                $('.wrpc-apply-preset').prop('disabled', false);
                                $('.wrpc-preset-card').css('border-color', '#ddd').css('background-color', '#fff');
                                $('.wrpc-apply-preset').text('<?php _e( 'Apply This Preset', 'wp-power-cache' ); ?>');
                                
                                // Highlight active
                                $card.css('border-color', '#0073aa').css('background-color', '#e7f3ff');
                                $btn.text('<?php _e( 'Currently Active', 'wp-power-cache' ); ?>');
                                
                                // Show success message
                                var $notice = $('<div class="notice notice-success is-dismissible" style="margin: 15px 0;"><p><strong>' + response.data.message + '</strong></p></div>');
                                $('.wrpc-settings-content').prepend($notice);
                                
                                // Auto dismiss
                                setTimeout(function() {
                                    $notice.fadeOut(function() { $(this).remove(); });
                                }, 3000);
                                
                                // Reload settings after 1 second
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            }
                        },
                        error: function(xhr) {
                            $btn.prop('disabled', false).text('<?php _e( 'Apply This Preset', 'wp-power-cache' ); ?>');
                            alert('<?php _e( 'Error applying preset', 'wp-power-cache' ); ?>');
                        }
                    });
                });
            });
            </script>
            <?php
        }

        /**
         * Detect which preset matches current settings
         */
        private static function detect_current_preset() {
            $current = (array) get_option( 'wp_power_cache_settings' );
            
            foreach ( self::$presets as $key => $preset ) {
                $matches = true;
                
                // Check if main settings match
                foreach ( $preset['settings'] as $setting_key => $setting_value ) {
                    $current_value = isset( $current[ $setting_key ] ) ? $current[ $setting_key ] : '';
                    
                    if ( $setting_value !== $current_value ) {
                        $matches = false;
                        break;
                    }
                }
                
                if ( $matches ) {
                    return $key;
                }
            }
            
            return false;
        }
    }

    WRPC_Settings_Presets::init();

endif;
