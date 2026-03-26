<?php
/**
 * Cache Logger Class
 * Handles logging of cache operations for debugging and audit trails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WRPC_Cache_Logger' ) ) :

    class WRPC_Cache_Logger {
        
        private $log_file = '';
        private $enabled = false;
        private $max_log_size = 1048576; // 1MB

        /**
         * Constructor
         */
        public function __construct( $log_dir, $enabled = true ) {
            $this->log_file = $log_dir . '/wrpc-cache.log';
            $this->enabled = $enabled;
        }

        /**
         * Log a message
         * @param string $action
         * @param string $details
         */
        public function log( $action, $details = '' ) {
            if ( ! $this->enabled ) return;

            $timestamp = current_time( 'mysql' );
            $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
            $user_id = get_current_user_id();

            $log_message = sprintf(
                "[%s] Action: %s | User: %d | IP: %s | Details: %s\n",
                $timestamp,
                sanitize_text_field( $action ),
                $user_id,
                $ip,
                sanitize_text_field( $details )
            );

            $this->write_log( $log_message );
        }

        /**
         * Write log to file
         * @param string $message
         */
        private function write_log( $message ) {
            if ( ! is_writable( dirname( $this->log_file ) ) ) {
                return;
            }

            // Check if log file exceeds max size
            if ( file_exists( $this->log_file ) && filesize( $this->log_file ) > $this->max_log_size ) {
                $this->rotate_log();
            }

            @file_put_contents( $this->log_file, $message, FILE_APPEND );
        }

        /**
         * Rotate log file when it exceeds max size
         */
        private function rotate_log() {
            $backup_file = $this->log_file . '.' . date( 'Y-m-d_H-i-s' ) . '.bak';
            @rename( $this->log_file, $backup_file );
        }

        /**
         * Get recent log entries
         * @param int $limit
         * @return array
         */
        public function get_recent_logs( $limit = 50 ) {
            if ( ! file_exists( $this->log_file ) ) {
                return array();
            }

            $lines = @file( $this->log_file );
            if ( ! $lines ) return array();

            // Get last N lines
            $lines = array_slice( $lines, -$limit );
            return array_reverse( $lines );
        }

        /**
         * Clear logs
         */
        public function clear_logs() {
            if ( file_exists( $this->log_file ) ) {
                @unlink( $this->log_file );
            }
            // Also remove backup files
            $pattern = dirname( $this->log_file ) . '/wrpc-cache.log.*.bak';
            foreach ( @glob( $pattern ) as $file ) {
                @unlink( $file );
            }
        }
    }

endif;
