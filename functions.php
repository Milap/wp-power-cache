<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * Recursive directory deletion using WP_Filesystem
 */
if ( ! function_exists( 'wrpc_recursive_dir_delete' ) ) {
	function wrpc_recursive_dir_delete( $path ) {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( $wp_filesystem->is_dir( $path ) ) {
			$wp_filesystem->delete( $path, true );
			$wp_filesystem->mkdir( $path ); // Recreate empty cache folder
		}
	}
}

if ( ! function_exists( 'wrpc_print_loading_time' ) ) {
	function wrpc_print_loading_time( $time ) {
		echo '<div id="wrpc-timer" style="position: fixed; top: 0; left: 0; right: 0; background: #333; color: #fff; text-align: center; padding: 10px; z-index: 99999;">';
		printf( esc_html__( 'Page executed in %s seconds (Cached by WP Power Cache)', 'wp-power-cache' ), esc_html($time) );
		echo '</div>';
	}
}