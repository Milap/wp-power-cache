<?php
/**
 * Cache Manager Class
 * Handles all cache operations: creation, retrieval, expiration, logging
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WRPC_Cache_Manager' ) ) :

    class WRPC_Cache_Manager {
        
        private $cache_folder = '';
        private $logger = null;
        private $settings = array();

        /**
         * Constructor
         */
        public function __construct( $cache_folder, $logger = null ) {
            $this->cache_folder = $cache_folder;
            $this->logger = $logger;
            $this->settings = (array) get_option( 'wp_power_cache_settings' );
        }

        /**
         * Get logger instance
         */
        public function get_logger() {
            return $this->logger;
        }

        /**
         * Get cache folder path
         */
        public function get_cache_folder_path() {
            return $this->cache_folder;
        }

        /**
         * Check if URL should be excluded from caching
         * @param string $url
         * @return bool
         */
        public function is_excluded_url( $url = '' ) {
            if ( empty( $url ) ) {
                $url = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
            }

            // Get exclusion list from settings
            $exclusions = isset( $this->settings['excluded_urls'] ) ? 
                explode( "\n", sanitize_textarea_field( $this->settings['excluded_urls'] ) ) : array();

            foreach ( $exclusions as $pattern ) {
                $pattern = trim( $pattern );
                if ( empty( $pattern ) ) continue;

                // Support regex patterns
                if ( strpos( $pattern, '/' ) === 0 ) {
                    if ( @preg_match( $pattern, $url ) ) return true;
                } else {
                    // Simple string match
                    if ( strpos( $url, $pattern ) !== false ) return true;
                }
            }

            return false;
        }

        /**
         * Check if a cache file exists and is not expired
         * @param string $cache_file
         * @return bool
         */
        public function is_cache_valid( $cache_file ) {
            if ( ! file_exists( $cache_file ) || filesize( $cache_file ) <= 0 ) {
                return false;
            }

            // Check if TTL is enabled and cache is expired
            if ( isset( $this->settings['enable_ttl'] ) && $this->settings['enable_ttl'] == 1 ) {
                $ttl = isset( $this->settings['cache_ttl'] ) ? (int) $this->settings['cache_ttl'] : 3600;
                $file_time = filemtime( $cache_file );
                $current_time = time();

                if ( ( $current_time - $file_time ) > $ttl ) {
                    if ( $this->logger ) {
                        $this->logger->log( 'Cache expired', $cache_file );
                    }
                    return false;
                }
            }

            return true;
        }

        /**
         * Create a cache file
         * @param string $buffer HTML content
         * @param string $cache_file Path to cache file
         * @return bool
         */
        public function create_cache( $buffer, $cache_file ) {
            // Verify directory traversal safety
            if ( ! $this->is_safe_path( $cache_file ) ) {
                if ( $this->logger ) {
                    $this->logger->log( 'Security: Directory traversal attempt blocked', $cache_file );
                }
                return false;
            }

            // Minify if enabled
            if ( isset( $this->settings['minify_html'] ) && $this->settings['minify_html'] == 1 ) {
                $buffer = $this->minify_html( $buffer );
            }

            $result = @file_put_contents( $cache_file, $buffer );

            if ( $result ) {
                if ( $this->logger ) {
                    $this->logger->log( 'Cache created', $cache_file );
                }
                @chmod( $cache_file, 0644 );
                return true;
            }

            if ( $this->logger ) {
                $this->logger->log( 'Error creating cache', $cache_file );
            }
            return false;
        }

        /**
         * Minify HTML content
         * @param string $buffer
         * @return string
         */
        private function minify_html( $buffer ) {
            $search = array( '/\>[^\S ]+/s', '/[^\S ]+\</s', '/(\s)+/s' );
            $replace = array( '>', '<', '\\1' );
            return preg_replace( $search, $replace, $buffer );
        }

        /**
         * Delete a cache file
         * @param string $cache_file
         * @return bool
         */
        public function delete_cache( $cache_file ) {
            if ( ! file_exists( $cache_file ) ) return true;

            if ( ! $this->is_safe_path( $cache_file ) ) {
                if ( $this->logger ) {
                    $this->logger->log( 'Security: Directory traversal attempt blocked', $cache_file );
                }
                return false;
            }

            $result = @unlink( $cache_file );

            if ( $result && $this->logger ) {
                $this->logger->log( 'Cache deleted', $cache_file );
            }

            return $result;
        }

        /**
         * Clear cache directory
         * @return bool
         */
        public function clear_all_cache() {
            global $wp_filesystem;

            if ( empty( $wp_filesystem ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }

            if ( ! is_dir( $this->cache_folder ) ) {
                return true;
            }

            if ( $wp_filesystem->delete( $this->cache_folder, true ) ) {
                if ( ! file_exists( $this->cache_folder ) ) {
                    wp_mkdir_p( $this->cache_folder );
                    @chmod( $this->cache_folder, 0755 );
                }

                if ( $this->logger ) {
                    $this->logger->log( 'Full cache cleared', 'all' );
                }
                return true;
            }

            return false;
        }

        /**
         * Get total cache size
         * @return int Size in bytes
         */
        public function get_cache_size_bytes() {
            $transient_key = 'wrpc_cache_size';
            $cached_size = get_transient( $transient_key );

            if ( $cached_size !== false ) {
                return $cached_size;
            }

            $size = 0;
            if ( ! is_dir( $this->cache_folder ) ) return 0;

            try {
                foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->cache_folder ) ) as $file ) {
                    if ( $file->isFile() ) $size += $file->getSize();
                }
            } catch ( Exception $e ) {
                return 0;
            }

            // Cache the size for 1 hour
            set_transient( $transient_key, $size, 3600 );

            return $size;
        }

        /**
         * Format bytes to human-readable size
         * @param int $bytes
         * @return string
         */
        public function format_bytes( $bytes = 0 ) {
            // Cast to int and handle zero case
            $bytes = (int) $bytes;
            
            if ( $bytes === 0 ) return '0 B';
            
            $units = array( 'B', 'KB', 'MB', 'GB' );
            $power = floor( log( abs( $bytes ), 1024 ) );
            
            // Ensure power doesn't exceed available units
            if ( $power >= count( $units ) ) {
                $power = count( $units ) - 1;
            }
            
            return number_format( $bytes / pow( 1024, $power ), 2 ) . ' ' . $units[$power];
        }

        /**
         * Verify path is within cache directory (prevent directory traversal)
         * @param string $path
         * @return bool
         */
        private function is_safe_path( $path ) {
            $real_cache_dir = realpath( $this->cache_folder );
            $real_path = realpath( dirname( $path ) );

            if ( $real_cache_dir === false || $real_path === false ) {
                return false;
            }

            return strpos( $real_path, $real_cache_dir ) === 0;
        }

        /**
         * Count cached files
         * @return int
         */
        public function get_cached_files_count() {
            $count = 0;
            if ( ! is_dir( $this->cache_folder ) ) return 0;

            try {
                foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->cache_folder ) ) as $file ) {
                    if ( $file->isFile() ) $count++;
                }
            } catch ( Exception $e ) {
                return 0;
            }

            return $count;
        }

        /**
         * Get cache statistics
         * @return array
         */
        public function get_cache_stats() {
            return array(
                'total_files' => $this->get_cached_files_count(),
                'total_size_bytes' => $this->get_cache_size_bytes(),
                'total_size_formatted' => $this->format_bytes( $this->get_cache_size_bytes() ),
                'cache_dir' => $this->cache_folder,
                'is_writable' => is_writable( $this->cache_folder ),
            );
        }
    }

endif;
