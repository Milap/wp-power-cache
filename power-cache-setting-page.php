<?php
/**
 * Settings Page Template for WP Power Cache
 * Included by WR_Power_Cache::power_cache_setting_page()
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Get current tab from URL, default to 'debug_options'
$active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'debug_options';
?>

<div class="wrap">
    <h1>
        <span class="dashicons dashicons-performance" style="font-size: 30px; width: 30px; height: 30px; line-height: 30px;"></span> 
        <?php _e( 'WP Power Cache Settings', 'wp-power-cache' ); ?>
    </h1>
    <p><?php _e( 'Manage your static page cache and performance monitoring options.', 'wp-power-cache' ); ?></p>

    <hr class="wp-header-end">

    <h2 class="nav-tab-wrapper">
        <a href="?page=setting-power-cache&tab=debug_options" class="nav-tab <?php echo $active_tab == 'debug_options' ? 'nav-tab-active' : ''; ?>">
            <?php _e( 'General Settings', 'wp-power-cache' ); ?>
        </a>
        <a href="?page=setting-power-cache&tab=cache_info" class="nav-tab <?php echo $active_tab == 'cache_info' ? 'nav-tab-active' : ''; ?>">
            <?php _e( 'Cache Info', 'wp-power-cache' ); ?>
        </a>
        <a href="?page=setting-power-cache&tab=diagnostics" class="nav-tab <?php echo $active_tab == 'diagnostics' ? 'nav-tab-active' : ''; ?>">
            <?php _e( 'Diagnostics', 'wp-power-cache' ); ?>
        </a>
    </h2>

    <div class="wrpc-settings-content" style="margin-top: 20px;">
        
        <?php if ( $active_tab == 'debug_options' ) : ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields( 'wp-power-cache-group' );
                do_settings_sections( 'setting-power-cache' );
                submit_button();
                ?>
            </form>

            <hr>

            <div class="wrpc-danger-zone" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-left: 4px solid #d63638;">
                <h3 style="margin-top: 0; color: #d63638;"><?php _e( 'Danger Zone', 'wp-power-cache' ); ?></h3>
                <p><?php _e( 'Clearing the entire cache will delete all static HTML files. Your site will regenerate them on the next visit.', 'wp-power-cache' ); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field( 'wrpc_clear_cache_action', 'wrpc_nonce' ); ?>
                    <input type="submit" name="clear_all_cache" id="clear_all_cache" class="button button-link-delete" style="color: #d63638; border-color: #d63638;" value="<?php _e( 'Empty Entire Website Cache', 'wp-power-cache' ); ?>">
                </form>
            </div>

        <?php elseif ( $active_tab == 'cache_info' ) : ?>

            <div class="card" style="max-width: 100%; margin-top: 0;">
                <h3><?php _e( 'Storage Overview', 'wp-power-cache' ); ?></h3>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e( 'Detail', 'wp-power-cache' ); ?></th>
                            <th><?php _e( 'Value', 'wp-power-cache' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php _e( 'Total Cache Size', 'wp-power-cache' ); ?></strong></td>
                            <td><?php echo $this->get_cache_size(); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e( 'Cache Directory', 'wp-power-cache' ); ?></strong></td>
                            <td><code><?php echo esc_html( $this->get_cache_folder_path() ); ?></code></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e( 'Server Software', 'wp-power-cache' ); ?></strong></td>
                            <td><?php echo esc_html( $_SERVER['SERVER_SOFTWARE'] ); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e( 'PHP Version', 'wp-power-cache' ); ?></strong></td>
                            <td><?php echo phpversion(); ?></td>
                        </tr>
                    </tbody>
                </table>
                <p class="description" style="margin-top: 15px;">
                    <?php _e( 'Note: Static HTML files are stored securely in your plugin directory using a hashed folder name to prevent unauthorized browsing.', 'wp-power-cache' ); ?>
                </p>
            </div>

        <?php elseif ( $active_tab == 'diagnostics' ) : 
            $cache_path = $this->get_cache_folder_path();
            $is_writable = is_writable( $cache_path );
            $perm = file_exists( $cache_path ) ? decoct( fileperms( $cache_path ) & 0777 ) : 'N/A';
            ?>
            
            <div class="card" style="max-width: 100%; margin-top: 0;">
                <h3><?php _e( 'System Health Check', 'wp-power-cache' ); ?></h3>
                <p><?php _e( 'Review your server environment to ensure caching works correctly.', 'wp-power-cache' ); ?></p>
                
                <table class="widefat fixed striped" style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <th style="width: 25%;"><?php _e( 'Component', 'wp-power-cache' ); ?></th>
                            <th style="width: 25%;"><?php _e( 'Status', 'wp-power-cache' ); ?></th>
                            <th><?php _e( 'Recommendation', 'wp-power-cache' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php _e( 'Directory Writable', 'wp-power-cache' ); ?></strong></td>
                            <td>
                                <?php if ( $is_writable ) : ?>
                                    <span style="color: #46b450; font-weight: bold;">✔ <?php _e( 'Writable', 'wp-power-cache' ); ?></span>
                                <?php else : ?>
                                    <span style="color: #dc3232; font-weight: bold;">✘ <?php _e( 'Not Writable', 'wp-power-cache' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $is_writable ? '-' : __( 'Grant write permissions to the cache folder (chmod 755 or 775).', 'wp-power-cache' ); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e( 'Permissions', 'wp-power-cache' ); ?></strong></td>
                            <td><code><?php echo esc_html( $perm ); ?></code></td>
                            <td><?php echo ( $perm >= 755 ) ? '-' : __( 'Recommended permissions are 755 for security and functionality.', 'wp-power-cache' ); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e( 'Permalinks', 'wp-power-cache' ); ?></strong></td>
                            <td>
                                <?php if ( get_option('permalink_structure') ) : ?>
                                    <span style="color: #46b450; font-weight: bold;">✔ <?php _e( 'Enabled', 'wp-power-cache' ); ?></span>
                                <?php else : ?>
                                    <span style="color: #ffb900; font-weight: bold;">⚠ <?php _e( 'Plain Links', 'wp-power-cache' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo get_option('permalink_structure') ? '-' : __( 'Static caching won\'t work with "Plain Permalinks".', 'wp-power-cache' ); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e( 'Disk Space', 'wp-power-cache' ); ?></strong></td>
                            <td>
                                <?php 
                                $free_space = @disk_free_space( $cache_path );
                                echo $free_space ? number_format( $free_space / ( 1024 * 1024 * 1024 ), 2 ) . ' GB' : __( 'Unknown', 'wp-power-cache' );
                                ?>
                            </td>
                            <td><?php _e( 'Ensure your server has enough headroom to store HTML snapshots.', 'wp-power-cache' ); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </div>
</div>

<style>
    /* Custom styles for the switch UI in settings */
    .wrpc-switch {
        position: relative;
        display: inline-flex;
        align-items: center;
        cursor: pointer;
    }
    /* Increase card padding for better readability */
    .card {
        padding: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        background: #fff;
        border: 1px solid #ccd0d4;
    }
    code {
        background: #f0f0f1;
        padding: 3px 5px;
        border-radius: 3px;
    }
    .nav-tab-wrapper {
        margin-bottom: 20px;
    }
</style>