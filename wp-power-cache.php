<?php
/**
 * Plugin Name: WP Power Cache
 * Author: Milap Patel, Nirav Desai
 * Description: Page / Post cache plugin with advanced TTL, URL exclusions, and logging.
 * Plugin URI: https://patelmilap.wordpress.com/
 * Author URI: https://youtube.com/c/codecanvas
 * Version: 2.0
 * Text Domain: wp-power-cache
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * 
 * ========================================
 * WP POWER CACHE - VERSION 2.0
 * ========================================
 * 
 * Core Features:
 * ✅ Static Page Caching
 * ✅ Advanced URL Exclusion (Regex + String)
 * ✅ Cache TTL/Expiration System
 * ✅ Modern Lazy Loading (No jQuery)
 * ✅ Full Audit Logging
 * ✅ HTML Minification
 * ✅ Directory Traversal Protection
 * ✅ Enhanced Diagnostics
 * ✅ Auto-Clear on Post Updates
 * ✅ Developer Mode & Performance Metrics
 */

if ( !defined( 'ABSPATH' ) ) exit;

// Define plugin constants
if ( ! defined( 'WRPC_VERSION' ) ) {
    define( 'WRPC_VERSION', '2.0' );
}

// Load external logic and lazy loading dependencies
require_once plugin_dir_path( __FILE__ ) . 'functions.php';
require_once plugin_dir_path( __FILE__ ) . 'wp-lazy-load.php';
require_once plugin_dir_path( __FILE__ ) . 'cache-manager.php';
require_once plugin_dir_path( __FILE__ ) . 'cache-logger.php';
require_once plugin_dir_path( __FILE__ ) . 'settings-presets.php';

/**
 * Register the activation hook to handle redirects to the settings page.
 */
register_activation_hook( __FILE__, array( 'WR_Power_Cache', 'plugin_activate' ) );

if ( ! class_exists( 'WR_Power_Cache' ) ) :
    class WR_Power_Cache {
        private $cacheFolder = 'wp-power-cache';
        private $isFront = false;
        private $isAdmin = false;
        private $currentCacheFile = '';
        protected static $instance = null;
        protected static $startTime = 0;
        protected static $endTime = 0;
        private $isDev = false;
        private $cache_manager = null;
        private $cache_logger = null;
        
        /**
         * List of WordPress hooks that should trigger an automatic cache clear.
         */
        private $actions = array(
            'clear_cache' => [
                'comment_post',
                'permalink_structure_changed',
                'save_post',
                'wp_update_nav_menu',
                'wp_nav_menu_item_custom_fields_updated',
                'after_switch_theme'
            ]
        );

        /**
         * Constructor: Sets up paths, creates cache directories, and initializes hooks.
         */
        public function __construct() {

            // Hook to handle the admin-post action (must match the action in the URL)
            add_action( 'admin_post_wrpc_admin_bar_clear', array( $this, 'handle_admin_bar_clear' ) );

            if(!defined('WRPC_ROOT_DIR'))
                define( 'WRPC_ROOT_DIR', str_replace( '\\', '/', plugin_dir_path( __FILE__ ) ) );
            
            // Create a unique folder name based on the server name
            $this->cacheFolder = isset( $_SERVER['SERVER_NAME'] ) ? 'wp-cache-' . md5( $_SERVER['SERVER_NAME'] ) : $this->cacheFolder;
            
            $settings = (array) get_option( 'wp_power_cache_settings' );
            $this->isDev = isset($settings['developer_flag']) && $settings['developer_flag'] == 1;

            // Ensure the cache directory exists and has correct permissions
            if ( ! file_exists( WRPC_ROOT_DIR . $this->cacheFolder ) ) {
                if(wp_mkdir_p( WRPC_ROOT_DIR . $this->cacheFolder )) {
                    chmod( WRPC_ROOT_DIR . $this->cacheFolder, 0755 );
                }
            }

            // Initialize cache manager and logger
            $cache_enable_logging = isset( $settings['enable_logging'] ) && $settings['enable_logging'] == 1;
            $this->cache_logger = new WRPC_Cache_Logger( WRPC_ROOT_DIR . $this->cacheFolder, $cache_enable_logging );
            $this->cache_manager = new WRPC_Cache_Manager( WRPC_ROOT_DIR . $this->cacheFolder . '/', $this->cache_logger );

            $this->add_actions();
        }
        
        /**
         * Returns the absolute path to the current cache folder.
         * @return string
         */
        public function get_cache_folder_path() {
            return WRPC_ROOT_DIR . $this->cacheFolder . '/';
        }

        /**
         * Get cache manager instance
         * @return WRPC_Cache_Manager
         */
        public function get_cache_manager() {
            return $this->cache_manager;
        }

        /**
         * Initializes the output buffer for non-logged-in users on the front-end.
         * @param WP_Query $q
         */
        public function get_post_action_handler( $q ) {
            
            if ( ! get_option( 'permalink_structure' ) ) return;

            if ( is_admin() || is_user_logged_in() ) return;

            // Check if URL is excluded
            if ( $this->cache_manager->is_excluded_url() ) {
                return;
            }

            $this->currentCacheFile = $this->get_query_parameters( $q );

            // If a valid cache already exists, we don't need to buffer again
            if ( $this->cache_exists()) return;

            if ( $this->validCatchFile() ) {
                ob_start( array( $this, 'end_post_action_handler' ) );
            }
        }

        /**
         * Checks if a cached file exists and serves it directly, stopping further execution.
         */
        public function load_cache_action_handler() {
            $this->FrontOrAdmin();
            if ( $this->isFront && $this->cache_exists() ) {
                echo file_get_contents( $this->cache_file_path() );
                $this->end_page_load_time();
                exit;
            }
        }

        /**
         * Marks the start time of the page load for performance measurement.
         */
        public function start_page_load_time() {
            self::$startTime = microtime( true );
        }

        /**
         * Calculates final load time and displays the developer performance bar if enabled.
         */
        public function end_page_load_time() {
            self::$endTime = microtime( true );
            $finish = round( ( self::$endTime - self::$startTime ), 4 );
            
            if ( $this->isDev ) {
                wrpc_print_loading_time( $finish );
            } 
        }

        /**
         * Callback for the output buffer. Adds signatures and saves the final HTML to a file.
         * @param string $buffer The HTML content of the page.
         * @return string Modified HTML content.
         */
        public function end_post_action_handler( $buffer ) {
            if ( is_404() || empty($buffer) || is_user_logged_in() || is_admin() ) {
                return $buffer;
            }

            $settings = (array) get_option( 'wp_power_cache_settings' );
            
            // Add a timestamp signature to the source code if debugging is active
            if ( isset($settings['debug_flag']) && $settings['debug_flag'] == 1 ) {
                $signature = "<!-- This cached page generated by WP-Power-Cache on ". date( "d-m-Y H:i:s" ) ." -->\n";
				$signature .= "<!-- Version " . WRPC_VERSION . " -->\n";
                $buffer .= $signature; 
            }

            return $this->create_cache( $buffer );
        }

        /**
         * Filters out file types that should never be cached (images, flash, etc.).
         * @return bool
         */
        private function validCatchFile() {
            if ( empty($this->currentCacheFile) ) return false;
            $ext = pathinfo( $this->currentCacheFile, PATHINFO_EXTENSION );
            return !in_array( $ext, $this->invalid_file_extensions() );
        }

        /**
         * Provides an array of blacklisted file extensions.
         * @return array
         */
        private function invalid_file_extensions() {
            return array('gif', 'jpeg', 'jpg', 'png', 'swf', 'psd', 'bmp', 'tiff', 'ico');
        }

        /**
         * Determines the current cache filename based on the URI.
         * @param mixed $q
         * @return string
         */
        private function get_query_parameters( $q ) {
            return $this->get_standard_filename();
        }

        /**
         * Registers all plugin actions and filters.
         */
        private function add_actions() {
            add_action( 'init', array( $this, 'unified_clear_cache_handler' ) );

            // Hook into WordPress events for auto-clearing cache
            if ( !empty( $this->actions['clear_cache'] ) ) {
                foreach ( $this->actions['clear_cache'] as $hook ) {
                    add_action( $hook, array( $this, 'auto_clear_cache_wrapper' ), 20 );
                }
            }

            add_action( 'admin_init', array( $this, 'handle_activation_redirect' ) );
            add_action( 'init', array( $this, 'start_page_load_time' ) );
            add_action( 'template_redirect', array( $this, 'load_cache_action_handler' ), 1 );
            add_action( 'wp_loaded', array( $this, 'get_post_action_handler' ) );

            // Admin UI Hooks
            add_action( 'admin_menu', array( $this, 'power_cache_menu' ) );
            add_action( 'admin_init', array( $this, 'power_cache_init' ) );
            add_action( 'admin_notices', array($this, 'wp_power_cache_admin_notice'));
            add_action( 'admin_footer', array($this, 'add_auto_hide_notice_js'));
            add_action( 'admin_bar_menu', array( $this, 'add_cache_id_to_admin_bar' ), 100 );
            add_filter( 'plugin_action_links_'.plugin_basename(__FILE__), array($this, 'add_wp_power_cache_settings_link'));
            add_action( 'wp_footer', array( $this, 'show_cache_clear_notice' ) );
            add_action( 'admin_head', array( $this, 'add_confirm_clear_js' ) );
        }

        /**
         * Identifies if the current environment is the Admin dashboard or Public frontend.
         */
        private function FrontOrAdmin() {
            if ( is_admin() || is_user_logged_in() ) {
                $this->isAdmin = true;
            } else {
                $this->isFront = true;
            }
        }

        /**
         * Verifies if a cache file exists and has content.
         * @return string|bool Path to file or false.
         */
        private function cache_exists() {
            $path = $this->cache_file_path();
            return $this->cache_manager->is_cache_valid( $path ) ? $path : false;
        }

        /**
         * Returns the full server path for the current page's cache file.
         * We sanitize the name to ensure it's safe for the filesystem.
         * @return string
         */
        private function cache_file_path() {
            // Check if currentCacheFile is empty and try to populate it
            if ( empty( $this->currentCacheFile ) ) {
                $this->currentCacheFile = $this->get_standard_filename();
            }

            // sanitize_file_name can strip everything if the string starts with characters like "/"
            // Since we use MD5 or "index", it will be safe, but we'll be extra careful:
            $safe_name = sanitize_file_name( (string) $this->currentCacheFile );
            
            // If for some reason it's still empty, fallback to index
            if ( empty( $safe_name ) ) {
                $safe_name = 'index';
            }

            return WRPC_ROOT_DIR . $this->cacheFolder . '/' . $safe_name . '.html';
        }

        /**
         * Saves the minified buffer to the local file system.
         * @param string $buffer HTML content.
         * @param string $file_name Optional specific filename.
         * @return string
         */
        private function create_cache( $buffer, $file_name = '' ) {

            if ( $file_name != "" ) {
                $this->currentCacheFile = $file_name;
            }
            if ( $this->isFront && ! $this->cache_exists() ) {
                $cache_file = $this->cache_file_path();
                $this->cache_manager->create_cache( $buffer, $cache_file );
            }
            return $buffer;
        }

        /**
         * Minifies HTML buffer by removing excessive whitespace.
         * @param string $buffer
         * @return string
         */
        private function sanitize_output( $buffer ) {
            $search  = array( '/\>[^\S ]+/s', '/[^\S ]+\</s', '/(\s)+/s' );
            $replace = array( '>', '<', '\\1' );
            return preg_replace( $search, $replace, $buffer );
        }
        
        /**
         * Adds the plugin settings page to the WordPress admin menu.
         */
        public function power_cache_menu() {
            add_menu_page('WP Power Cache', 'WP Power Cache', 'manage_options', 'setting-power-cache', array($this, 'power_cache_setting_page'), 'dashicons-performance');
        }
        
        /**
         * Initializes Settings API sections and fields.
         */
        public function power_cache_init() {
            register_setting( 'wp-power-cache-group', 'wp_power_cache_settings' );
            $active_tab = $_GET['tab'] ?? 'debug_options';
            
            if( $active_tab == 'debug_options' ) {
                add_settings_section( 'developer_flag', __( 'Developer Mode', 'wp-power-cache' ), '__return_false', 'setting-power-cache' );
                add_settings_field( 'developer_flag_settings', __( 'Enable Developer Mode', 'wp-power-cache' ), array($this,'developer_flag_settings_callback'), 'setting-power-cache', 'developer_flag' );
                add_settings_field( 'debug_settings', __( 'Debugging', 'wp-power-cache' ), array($this,'debug_field_callback'), 'setting-power-cache','developer_flag');
                
                add_settings_section( 'cache_behavior', __( 'Cache Behavior', 'wp-power-cache' ), '__return_false', 'setting-power-cache' );
                add_settings_field( 'enable_ttl', __( 'Cache Expiration (TTL)', 'wp-power-cache' ), array($this, 'enable_ttl_callback'), 'setting-power-cache', 'cache_behavior' );
                add_settings_field( 'cache_ttl', __( 'Time to Live (seconds)', 'wp-power-cache' ), array($this, 'cache_ttl_callback'), 'setting-power-cache', 'cache_behavior' );
                add_settings_field( 'minify_html', __( 'Minify HTML', 'wp-power-cache' ), array($this, 'minify_html_callback'), 'setting-power-cache', 'cache_behavior' );
                
                add_settings_section( 'cache_exclusions', __( 'Exclude URLs from Cache', 'wp-power-cache' ), '__return_false', 'setting-power-cache' );
                add_settings_field( 'excluded_urls', __( 'Excluded URL Patterns', 'wp-power-cache' ), array($this, 'excluded_urls_callback'), 'setting-power-cache', 'cache_exclusions' );
                
                add_settings_section( 'logging', __( 'Logging & Diagnostics', 'wp-power-cache' ), '__return_false', 'setting-power-cache' );
                add_settings_field( 'enable_logging', __( 'Enable Cache Logging', 'wp-power-cache' ), array($this, 'enable_logging_callback'), 'setting-power-cache', 'logging' );
            }
        }
        
        /**
         * Callback for rendering the performance monitoring switch.
         */
        public function developer_flag_settings_callback() {
            $settings = (array) get_option( 'wp_power_cache_settings' );
            $val = $settings['developer_flag'] ?? 0;
            echo '<label class="wrpc-switch"><input type="checkbox" name="wp_power_cache_settings[developer_flag]" value="1"' . checked(1, $val, false) . '/><span style="margin-left: 10px;">' . esc_html__( 'Enable Performance Monitoring Bar', 'wp-power-cache-pro' ) . '</span></label>';
            echo '<p class="description">' . esc_html__( 'Display exact page execution time at the top of your website for performance auditing.', 'wp-power-cache-pro' ) . '</p>';
        }

        /**
         * Callback for rendering the HTML signature checkbox.
         */
        public function debug_field_callback() {
            $settings = (array) get_option( 'wp_power_cache_settings' );
            $val = $settings['debug_flag'] ?? 0;
            echo '<input type="checkbox" name="wp_power_cache_settings[debug_flag]" value="1"' . checked(1, $val, false) . '/>';
            echo '<span style="margin-left: 10px;">' . esc_html__( 'Append Cache Signature to HTML Source', 'wp-power-cache-pro' ) . '</span>';
            echo '<div style="background: #f9f9f9; border: 1px solid #ccd0d4; padding: 12px; margin-top: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; color: #666;">&lt;!-- This cached page generated by WP-Power-Cache Pro on ' . date( "d-m-Y H:i:s" ) . ' --&gt;</div>';
        }

        /**
         * Callback for TTL enable checkbox
         */
        public function enable_ttl_callback() {
            $settings = (array) get_option( 'wp_power_cache_settings' );
            $val = $settings['enable_ttl'] ?? 0;
            echo '<label><input type="checkbox" name="wp_power_cache_settings[enable_ttl]" value="1"' . checked(1, $val, false) . '/>';
            echo '<span style="margin-left: 10px;">' . esc_html__( 'Enable cache expiration', 'wp-power-cache-pro' ) . '</span></label>';
            echo '<p class="description">' . esc_html__( 'Automatically invalidate cached pages after the specified time interval.', 'wp-power-cache-pro' ) . '</p>';
        }

        /**
         * Callback for TTL input field
         */
        public function cache_ttl_callback() {
            $settings = (array) get_option( 'wp_power_cache_settings' );
            $ttl = $settings['cache_ttl'] ?? '3600';
            echo '<input type="number" name="wp_power_cache_settings[cache_ttl]" value="' . intval($ttl) . '" min="60" step="60" style="width: 150px;" />';
            echo '<p class="description">' . esc_html__( 'Time in seconds before cache expires (minimum: 60, default: 3600 = 1 hour)', 'wp-power-cache-pro' ) . '</p>';
        }

        /**
         * Callback for minify HTML checkbox
         */
        public function minify_html_callback() {
            $settings = (array) get_option( 'wp_power_cache_settings' );
            $val = $settings['minify_html'] ?? 1;
            echo '<label><input type="checkbox" name="wp_power_cache_settings[minify_html]" value="1"' . checked(1, $val, false) . '/>';
            echo '<span style="margin-left: 10px;">' . esc_html__( 'Minify HTML output', 'wp-power-cache-pro' ) . '</span></label>';
            echo '<p class="description">' . esc_html__( 'Reduce HTML file size by removing unnecessary whitespace.', 'wp-power-cache-pro' ) . '</p>';
        }

        /**
         * Callback for excluded URLs textarea
         */
        public function excluded_urls_callback() {
            $settings = (array) get_option( 'wp_power_cache_settings' );
            $excluded = isset($settings['excluded_urls']) ? $settings['excluded_urls'] : '';
            echo '<textarea name="wp_power_cache_settings[excluded_urls]" rows="6" cols="50" style="font-family: monospace;">' . esc_textarea($excluded) . '</textarea>';
            echo '<p class="description">';
            echo esc_html__( 'Enter URL patterns (one per line). Supports regex patterns starting with /. Examples:', 'wp-power-cache-pro' ) . '<br />';
            echo '<code>/cart/</code> - exclude cart pages<br />';
            echo '<code>?add-to-cart</code> - exclude URLs with add-to-cart parameter<br />';
            echo '<code>/account/</code> - exclude user account pages<br />';
            echo '</p>';
        }

        /**
         * Callback for logging checkbox
         */
        public function enable_logging_callback() {
            $settings = (array) get_option( 'wp_power_cache_settings' );
            $val = $settings['enable_logging'] ?? 0;
            echo '<label><input type="checkbox" name="wp_power_cache_settings[enable_logging]" value="1"' . checked(1, $val, false) . '/>';
            echo '<span style="margin-left: 10px;">' . esc_html__( 'Enable cache operations logging', 'wp-power-cache-pro' ) . '</span></label>';
            echo '<p class="description">' . esc_html__( 'Log all cache operations for debugging. Logs are stored in the cache directory.', 'wp-power-cache-pro' ) . '</p>';
        }
        
        /**
         * Loads the settings page HTML template.
         */
        public function power_cache_setting_page() {
            require_once plugin_dir_path( __FILE__ ) . 'power-cache-setting-page.php';
        }
        
        /**
         * Displays admin notices for cache clearing events and settings updates.
         */
        public function wp_power_cache_admin_notice() {
            $screen = get_current_screen();

            if ( strpos( $screen->id, 'setting-power-cache' ) === false ) {
                return;
            }

            if ( isset( $_GET['settings-updated'] ) ) {
                add_settings_error( 
                    'wp-power-cache-group', 
                    'settings_updated', 
                    __( 'Settings saved.', 'wp-power-cache-pro' ), 
                    'updated' 
                );
            }

            if ( isset( $_GET['wrpc_cleared'] ) ) {
                $type = $_GET['wrpc_cleared'];
                $msg = ( $type === 'all' ) ? 'Success: The entire cache folder has been cleared.' : 'Success: Cache for the specific page has been cleared.';
                
                echo '<div class="notice notice-success is-dismissible wrpc-auto-hide"><p><strong>' . esc_html( $msg ) . '</strong></p></div>';
            }

            settings_errors( 'wp-power-cache-group' );
        }

        /**
         * Injects JavaScript into the admin footer to fade out and remove notices after 3 seconds.
         */
        public function add_auto_hide_notice_js() {
            ?>
            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function() {
                    const notices = document.querySelectorAll('.wrpc-auto-hide');
                    notices.forEach(function(notice) {
                        setTimeout(function() {
                            notice.style.transition = "opacity 0.6s ease, transform 0.6s ease";
                            notice.style.opacity = "0";
                            notice.style.transform = "translateY(-10px)";
                            setTimeout(() => notice.remove(), 600);
                        }, 3000);
                    });
                });
            </script>
            <?php
        }
        
        /**
         * Adds a "Settings" link to the plugin on the main Plugins dashboard.
         * @param array $links
         * @return array
         */
        public function add_wp_power_cache_settings_link( $links ) {
            $settings_link = '<a href="admin.php?page=setting-power-cache">' . __( 'Settings' ) . '</a>';
            $pro_badge = '<span style="color: #d4af37; font-weight: bold;">PRO</span>';
            array_unshift( $links, $pro_badge );
            array_unshift( $links, $settings_link );
            return $links;
        }

        /**
         * Singleton pattern
         * @return WR_Power_Cache
         */
        public static function run() {
            if ( self::$instance == null ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Adds cache status and manual clearing controls to the WordPress Admin Bar.
         * @param WP_Admin_Bar $wp_admin_bar
         */
        public function add_cache_id_to_admin_bar( $wp_admin_bar ) {
            if ( ! current_user_can( 'manage_options' ) || is_admin() ) return;

            $settings = (array) get_option( 'wp_power_cache_settings' );
            
            $exists = $this->cache_exists();
            $status = $exists ? 'Cached' : 'Dynamic';
            $color  = $exists ? '#46b450' : '#ffb900';
            $total_size = $this->get_cache_size();
            $cache_age = $this->get_cache_age();
            
            $wp_admin_bar->add_node( array(
                'id'    => 'wrpc-cache-status',
                'title' => '<span style="color:' . $color . ';">● WP Power Cache Pro: ' . $status . '</span>',
                'href'  => admin_url( 'admin.php?page=setting-power-cache' ),
            ) );

            $wp_admin_bar->add_node( array(
                'id'     => 'wrpc-cache-size',
                'parent' => 'wrpc-cache-status',
                'title'  => 'Total Size: ' . $total_size,
            ) );

            if ( $exists ) {
                $wp_admin_bar->add_node( array(
                    'id'     => 'wrpc-cache-age',
                    'parent' => 'wrpc-cache-status',
                    'title'  => 'Cache Age: ' . $cache_age,
                ) );

                $wp_admin_bar->add_node( array(
                    'id'     => 'wrpc-clear-this',
                    'parent' => 'wrpc-cache-status',
                    'title'  => 'Clear This Page Cache',
                    'href'   => wp_nonce_url( add_query_arg( 'wrpc_clear_single', '1' ), 'wrpc_clear_single_action' ),
                ) );
            }

            $wp_admin_bar->add_node( array(
                'id'     => 'wrpc-empty-all',
                'parent' => 'wrpc-cache-status',
                'title'  => '<span style="color:#d63638;">Empty All Cache</span>',
                'href'   => wp_nonce_url( add_query_arg( 'wrpc_empty_all', '1' ), 'wrpc_empty_all_action' ),
                'meta'   => array( 'onclick' => 'return confirm("Delete ALL cached files?");' ),
            ) );
        }

        /**
         * Get cache age (how old the newest cache file is)
         * @return string Human-readable age
         */
        private function get_cache_age() {
            $cache_folder = $this->get_cache_folder_path();
            
            if ( ! is_dir( $cache_folder ) ) {
                return __( 'No cache', 'wp-power-cache-pro' );
            }

            $newest_time = 0;
            try {
                foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $cache_folder ) ) as $file ) {
                    if ( $file->isFile() ) {
                        $mtime = $file->getMTime();
                        if ( $mtime > $newest_time ) {
                            $newest_time = $mtime;
                        }
                    }
                }
            } catch ( Exception $e ) {
                return __( 'Unknown', 'wp-power-cache-pro' );
            }

            if ( $newest_time === 0 ) {
                return __( 'No files', 'wp-power-cache-pro' );
            }

            $age_seconds = time() - $newest_time;
            
            if ( $age_seconds < 60 ) {
                return sprintf( __( '%d seconds ago', 'wp-power-cache-pro' ), $age_seconds );
            }
            
            $minutes = floor( $age_seconds / 60 );
            if ( $minutes < 60 ) {
                return sprintf( __( '%d minutes ago', 'wp-power-cache-pro' ), $minutes );
            }
            
            $hours = floor( $age_seconds / 3600 );
            if ( $hours < 24 ) {
                return sprintf( __( '%d hours ago', 'wp-power-cache-pro' ), $hours );
            }
            
            $days = floor( $age_seconds / 86400 );
            return sprintf( __( '%d days ago', 'wp-power-cache-pro' ), $days );
        }

        /**
         * Calculates the total storage size of the cache directory.
         * @return string Human-readable size.
         */
        private function get_cache_size() {
            return $this->cache_manager->format_bytes( $this->cache_manager->get_cache_size_bytes() );
        }

        /**
         * Centralized handler for manual cache clearing (Single or Global).
         */
        public function unified_clear_cache_handler() {
            // Global Clearing Logic
            if ( (isset($_GET['wrpc_empty_all']) && check_admin_referer('wrpc_empty_all_action')) || isset($_POST['clear_all_cache']) ) {
                if ( isset($_POST['clear_all_cache']) ) check_admin_referer( 'wrpc_clear_cache_action', 'wrpc_nonce' );

                $this->cache_manager->clear_all_cache();

                wp_safe_redirect( add_query_arg( array('page' => 'setting-power-cache', 'wrpc_cleared' => 'all'), admin_url( 'admin.php' ) ) );
                exit;
            }

            // Single Page Clearing Logic
            if ( isset( $_GET['wrpc_clear_single'] ) && check_admin_referer( 'wrpc_clear_single_action' ) ) {
                $filename = $this->get_standard_filename() . '.html';
                $path = WRPC_ROOT_DIR . $this->cacheFolder . '/' . $filename;

                $this->cache_manager->delete_cache( $path );
                wp_safe_redirect( add_query_arg( 'wrpc_cleared', 'single', remove_query_arg( array( 'wrpc_clear_single', '_wpnonce' ) ) ) );
                exit;
            }
        }

        /**
         * Generates a consistent filename based on the current URL.
         * @return string
         */
        private function get_standard_filename() {
            if ( is_front_page() || is_home() ) {
                return 'index';
            }

            $uri = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
            $uri = trim( rtrim( $uri, '/' ) );

            if ( empty( $uri ) ) {
                return 'index';
            }

            return md5( $uri );
        }

        /**
         * Displays a temporary success overlay on the front-end after a manual clear action.
         */
        public function show_cache_clear_notice() {
            if ( ! isset( $_GET['wrpc_cleared'] ) ) return;

            $type = $_GET['wrpc_cleared'];
            $message = ($type === 'all') ? '✓ Entire Website Cache Cleared (Pro)' : '✓ Current Page Cache Cleared (Pro)';
            $bg_color = ($type === 'all') ? '#d63638' : '#46b450';

            echo '<div id="wrpc-notice" style="position: fixed; top: 50px; right: 20px; z-index: 99999; background: ' . esc_attr( $bg_color ) . '; color: #fff; padding: 12px 24px; border-radius: 5px; box-shadow: 0 4px 15px rgba(0,0,0,0.3); font-family: sans-serif; font-weight: 500; animation: wrpc_fadeout 4s forwards;">' . esc_html( $message ) . '</div>';
            echo '<style>@keyframes wrpc_fadeout { 0% {opacity: 0; transform: translateY(-20px);} 10% {opacity: 1; transform: translateY(0);} 80% {opacity: 1;} 100% {opacity: 0; visibility: hidden;} }</style>';
        }

        /**
         * Injects a confirmation dialog for global cache clearing in the admin head.
         */
        public function add_confirm_clear_js() {
            if ( isset($_GET['page']) && $_GET['page'] === 'setting-power-cache' ) {
                ?>
                <script type="text/javascript">
                    document.addEventListener('DOMContentLoaded', function() {
                        const clearBtn = document.querySelector('input[name="clear_all_cache"]');
                        if (clearBtn) {
                            clearBtn.addEventListener('click', function(e) {
                                if (!confirm('Are you sure you want to delete the entire cache folder?')) e.preventDefault();
                            });
                        }
                    });
                </script>
                <?php
            }
        }

        /**
         * Handles automatic cache clearing - smart invalidation for posts/pages/homepage.
         * @param int|mixed $post_id The ID of the post being updated.
         */
        public function auto_clear_cache_wrapper( $post_id = null ) {
            
            if ( $post_id && is_numeric( $post_id ) && get_post_type( $post_id ) !== 'wp_navigation' ) {
                
                $permalink = get_permalink( $post_id );
                $uri = parse_url( $permalink, PHP_URL_PATH );
                $uri = rtrim( $uri, '/' );
                
                $post_filename = empty( $uri ) ? 'index' : md5( $uri );
                $post_path = WRPC_ROOT_DIR . $this->cacheFolder . '/' . $post_filename . '.html';

                $this->cache_manager->delete_cache( $post_path );

                $index_path = WRPC_ROOT_DIR . $this->cacheFolder . '/index.html';
                $this->cache_manager->delete_cache( $index_path );

                return;
            }

            $this->cache_manager->clear_all_cache();
        }

        /**
         * Sets a temporary database flag to trigger a redirect upon plugin activation.
         */
        public static function plugin_activate() {
            set_transient( 'wrpc_activation_redirect', true, 30 );
        }

        /**
         * Handles the logic of redirecting the user to settings only once after activation.
         */
        public function handle_activation_redirect() {
            if ( get_transient( 'wrpc_activation_redirect' ) ) {
                delete_transient( 'wrpc_activation_redirect' );
                if ( is_admin() && !isset($_GET['activate-multi']) ) {
                    wp_safe_redirect( admin_url( 'admin.php?page=setting-power-cache' ) );
                    exit;
                }
            }
        }

        /**
         * Handles the click event from the Admin Bar "Empty Entire Cache" button.
         */
        public function handle_admin_bar_clear() {
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'wrpc_bar_clear_nonce' ) ) {
                wp_die( __( 'Security check failed.', 'wp-power-cache-pro' ) );
            }

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( __( 'You do not have permission to do this.', 'wp-power-cache-pro' ) );
            }

            $this->auto_clear_cache_wrapper();

            wp_safe_redirect( add_query_arg( 'wrpc_cleared', 'all', wp_get_referer() ) );
            exit;
        }
    }
    WR_Power_Cache::run();
endif;
