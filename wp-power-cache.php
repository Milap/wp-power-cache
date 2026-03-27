<?php
/**
 * Plugin Name: WP Power Cache
 * Author: Milap Patel, Nirav Desai
 * Description: A simple Page / Post cache plugin.
 * Plugin URI: https://patelmilap.wordpress.com/
 * Author URI: https://youtube.com/c/codecanvas
 * Version: 1.0
 * Text Domain: wp-power-cache
 * Requires at least: 5.0
 * Requires PHP: 8.0
 */

if ( !defined( 'ABSPATH' ) ) exit;

// Load external logic and lazy loading dependencies
require_once plugin_dir_path( __FILE__ ) . 'functions.php';
require_once plugin_dir_path( __FILE__ ) . 'wp-lazy-load.php';

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
            $this->cacheFolder = isset( $_SERVER['SERVER_NAME'] ) ? md5( $_SERVER['SERVER_NAME'] ) : $this->cacheFolder;
            
            $settings = (array) get_option( 'wp_power_cache_settings' );
            $this->isDev = isset($settings['developer_flag']) && $settings['developer_flag'] == 1;

            // Ensure the cache directory exists and has correct permissions
            if ( ! file_exists( WRPC_ROOT_DIR . $this->cacheFolder ) ) {
                if(wp_mkdir_p( WRPC_ROOT_DIR . $this->cacheFolder )) {
                    chmod( WRPC_ROOT_DIR . $this->cacheFolder, 0755 );
                }
            }
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
         * Initializes the output buffer for non-logged-in users on the front-end.
         * @param WP_Query $q
         */
        public function get_post_action_handler( $q ) {
            
            if ( ! get_option( 'permalink_structure' ) ) return;

            if ( is_admin() || is_user_logged_in() ) return;

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
				$signature .= "\n";
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
            add_action( 'admin_footer', array($this, 'add_auto_hide_notice_js')); // JS for hiding notices
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
            return ( file_exists( $path ) && filesize($path) > 0 ) ? $path : false;
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
                $buffer = $this->sanitize_output( $buffer );
                file_put_contents( $this->cache_file_path(), $buffer );
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
            }
            // No specific settings fields needed for 'diagnostics' as it's read-only data
        }
        
        /**
         * Callback for rendering the performance monitoring switch.
         */
        public function developer_flag_settings_callback() {
            $settings = (array) get_option( 'wp_power_cache_settings' );
            $val = $settings['developer_flag'] ?? 0;
            echo '<label class="wrpc-switch"><input type="checkbox" name="wp_power_cache_settings[developer_flag]" value="1"' . checked(1, $val, false) . '/><span style="margin-left: 10px;">' . esc_html__( 'Enable Performance Monitoring Bar', 'wp-power-cache' ) . '</span></label>';
            echo '<p class="description">' . esc_html__( 'Display exact page execution time at the top of your website for performance auditing.', 'wp-power-cache' ) . '</p>';
        }

        /**
         * Callback for rendering the HTML signature checkbox.
         */
        public function debug_field_callback() {
            $settings = (array) get_option( 'wp_power_cache_settings' );
            $val = $settings['debug_flag'] ?? 0;
            echo '<input type="checkbox" name="wp_power_cache_settings[debug_flag]" value="1"' . checked(1, $val, false) . '/>';
            echo '<span style="margin-left: 10px;">' . esc_html__( 'Append Cache Signature to HTML Source', 'wp-power-cache' ) . '</span>';
            echo '<div style="background: #f9f9f9; border: 1px solid #ccd0d4; padding: 12px; margin-top: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; color: #666;">&lt;!-- This cached page generated by WP-Power-Cache on ' . date( "d-m-Y H:i:s" ) . ' --&gt;</div>';
        }
        
        /**
         * Loads the settings page HTML template.
         */
        public function power_cache_setting_page() {
            require_once plugin_dir_path( __FILE__ ) . 'power-cache-setting-page.php';
        }
        
        /**
         * Displays admin notices for cache clearing events and settings updates.
         * Restricted to the plugin's own settings page to prevent cluttering other admin screens.
         */
        public function wp_power_cache_admin_notice() {
            // 1. Get the current screen object
            $screen = get_current_screen();

            // 2. Only proceed if we are on our specific plugin settings page
            // The screen ID is usually 'toplevel_page_setting-power-cache'
            if ( strpos( $screen->id, 'setting-power-cache' ) === false ) {
                return;
            }

            // 3. Check for the standard settings updated message
            if ( isset( $_GET['settings-updated'] ) ) {
                add_settings_error( 
                    'wp-power-cache-group', 
                    'settings_updated', 
                    __( 'Settings saved.', 'wp-power-cache' ), 
                    'updated' 
                );
            }

            // 4. Check for our custom "Cache Cleared" flag
            if ( isset( $_GET['wrpc_cleared'] ) ) {
                $type = $_GET['wrpc_cleared'];
                $msg = ( $type === 'all' ) ? 'Success: The entire cache folder has been cleared.' : 'Success: Cache for the specific page has been cleared.';
                
                // Wrap in our custom class for the auto-hide JS logic
                echo '<div class="notice notice-success is-dismissible wrpc-auto-hide"><p><strong>' . esc_html( $msg ) . '</strong></p></div>';
            }

            // Display the errors/notices collected
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
                        }, 3000); // 3 Seconds delay
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
            array_unshift( $links, $settings_link );
            return $links;
        }

        /**
         * Singleton pattern: Ensures only one instance of the plugin class exists.
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
                
                $wp_admin_bar->add_node( array(
                    'id'    => 'wrpc-cache-status',
                    'title' => '<span style="color:' . $color . ';">● WP Power Cache: ' . $status . '</span>',
                    'href'  => admin_url( 'admin.php?page=setting-power-cache' ),
                ) );

                $wp_admin_bar->add_node( array(
                    'id'     => 'wrpc-cache-size',
                    'parent' => 'wrpc-cache-status',
                    'title'  => 'Total Size: ' . $total_size,
                ) );
                
                if ( $exists ) {
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
         * Calculates the total storage size of the cache directory.
         * @return string Human-readable size.
         */
        private function get_cache_size() {
            $size = 0;
            $directory = WRPC_ROOT_DIR . $this->cacheFolder;
            if ( ! is_dir( $directory ) ) return '0 B';

            foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory ) ) as $file ) {
                if ( $file->isFile() ) $size += $file->getSize();
            }

            if ( $size === 0 ) return '0 B';
            $units = array( 'B', 'KB', 'MB', 'GB' );
            $power = $size > 0 ? floor( log( $size, 1024 ) ) : 0;
            return number_format( $size / pow( 1024, $power ), 2 ) . ' ' . $units[$power];
        }

        /**
         * Centralized handler for manual cache clearing (Single or Global).
         */
        public function unified_clear_cache_handler() {
            // Global Clearing Logic
            if ( (isset($_GET['wrpc_empty_all']) && check_admin_referer('wrpc_empty_all_action')) || isset($_POST['clear_all_cache']) ) {
                if ( isset($_POST['clear_all_cache']) ) check_admin_referer( 'wrpc_clear_cache_action', 'wrpc_nonce' );

                wrpc_recursive_dir_delete( WRPC_ROOT_DIR . $this->cacheFolder );
                if ( ! file_exists( WRPC_ROOT_DIR . $this->cacheFolder ) ) wp_mkdir_p( WRPC_ROOT_DIR . $this->cacheFolder );

                wp_safe_redirect( add_query_arg( array('page' => 'setting-power-cache', 'wrpc_cleared' => 'all'), admin_url( 'admin.php' ) ) );
                exit;
            }

            // Single Page Clearing Logic
            if ( isset( $_GET['wrpc_clear_single'] ) && check_admin_referer( 'wrpc_clear_single_action' ) ) {
                $filename = $this->get_standard_filename() . '.html';
                $path = WRPC_ROOT_DIR . $this->cacheFolder . '/' . $filename;

                if ( file_exists( $path ) ) unlink( $path );
                wp_safe_redirect( add_query_arg( 'wrpc_cleared', 'single', remove_query_arg( array( 'wrpc_clear_single', '_wpnonce' ) ) ) );
                exit;
            }
        }

         /**
         * Generates a consistent filename based on the current URL.
         * Falls back to 'index' for the homepage.
         * @return string
         */
        private function get_standard_filename() {
            // If it's the home page, always return index
            if ( is_front_page() || is_home() ) {
                return 'index';
            }

            // Get the Request URI and strip query strings (everything after ?)
            $uri = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

            // Normalize: Remove trailing slashes and trim whitespace
            $uri = trim( rtrim( $uri, '/' ) );

            // If the URI is empty after trimming, it's the homepage
            if ( empty( $uri ) ) {
                return 'index';
            }

            // Return an MD5 hash of the URI to ensure a safe, fixed-length filename
            return md5( $uri );
        }

        /**
         * Displays a temporary success overlay on the front-end after a manual clear action.
         */
        public function show_cache_clear_notice() {
            if ( ! isset( $_GET['wrpc_cleared'] ) ) return;

            $type = $_GET['wrpc_cleared'];
            $message = ($type === 'all') ? '✓ Entire Website Cache Cleared' : '✓ Current Page Cache Cleared';
            $bg_color = ($type === 'all') ? '#d63638' : '#46b450';

            echo '<div id="wrpc-notice" style="position: fixed; top: 50px; right: 20px; z-index: 99999; background: ' . $bg_color . '; color: #fff; padding: 12px 24px; border-radius: 5px; box-shadow: 0 4px 15px rgba(0,0,0,0.3); font-family: sans-serif; font-weight: 500; animation: wrpc_fadeout 4s forwards;">' . $message . '</div>';
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
         * Handles automatic cache clearing.
         * Clears the specific post cache AND the homepage cache when a post is updated.
         * Performs a full site clear for global WordPress events.
         * * @param int|mixed $post_id The ID of the post being updated.
         */
        public function auto_clear_cache_wrapper( $post_id = null ) {
            
            // Check if we have a valid numeric Post ID (e.g., from 'save_post')
            if ( $post_id && is_numeric( $post_id ) && get_post_type( $post_id ) !== 'wp_navigation' ) {
                
                // 1. CLEAR THE SPECIFIC POST
                $permalink = get_permalink( $post_id );
                $uri = parse_url( $permalink, PHP_URL_PATH );
                $uri = rtrim( $uri, '/' );
                
                $post_filename = empty( $uri ) ? 'index' : md5( $uri );
                $post_path = WRPC_ROOT_DIR . $this->cacheFolder . '/' . $post_filename . '.html';

                if ( file_exists( $post_path ) ) {
                    unlink( $post_path );
                }

                // 2. CLEAR THE HOMEPAGE (index.html)
                // We do this because the homepage usually lists recent posts or excerpts
                $index_path = WRPC_ROOT_DIR . $this->cacheFolder . '/index.html';
                if ( file_exists( $index_path ) ) {
                    unlink( $index_path );
                }

                // Exit now so we don't trigger the global "Empty All" fallback below
                return;
            }

            /**
             * FALLBACK: Global Clear
             * Triggers for events like 'after_switch_theme' or 'wp_update_nav_menu'
             * where we cannot determine a specific page to clear.
             */
            $cache_dir = WRPC_ROOT_DIR . $this->cacheFolder;
            
            // Use the helper function from your functions.php to wipe the folder
            wrpc_recursive_dir_delete( $cache_dir );
            
            // Re-create the folder so the plugin can continue working immediately
            if ( ! file_exists( $cache_dir ) ) {
                wp_mkdir_p( $cache_dir );
                chmod( $cache_dir, 0755 );
            }
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
            // Security check
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'wrpc_bar_clear_nonce' ) ) {
                wp_die( __( 'Security check failed.', 'wp-power-cache' ) );
            }

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( __( 'You do not have permission to do this.', 'wp-power-cache' ) );
            }

            // Call your existing global clear method
            $this->auto_clear_cache_wrapper();

            // Redirect back to the page they were on with a success flag
            wp_safe_redirect( add_query_arg( 'wrpc_cleared', 'all', wp_get_referer() ) );
            exit;
        }
    }
    WR_Power_Cache::run();
endif;