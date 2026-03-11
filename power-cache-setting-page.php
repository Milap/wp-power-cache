<?php
if ( !defined( 'ABSPATH' ) ) exit;
$active_tab = $_GET['tab'] ?? 'debug_options';
$wp_power_cache = new WR_Power_Cache();
$folder_Name = $wp_power_cache->get_cache_folder_path();
?>
<div class="wrap wrpc-admin-wrapper">
    <div class="wrpc-header" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
        <h1 style="display: flex; align-items: center;">
            <span class="dashicons dashicons-performance" style="font-size: 30px; width: 30px; height: 30px; margin-right: 10px;"></span>
            <?php esc_html_e('WP Power Cache Settings', 'wp-power-cache'); ?>
        </h1>
        <div class="wrpc-version-badge" style="background: #0073aa; color: #fff; padding: 5px 15px; border-radius: 20px; font-weight: 600;">v0.1.0</div>
    </div>

    <div class="wrpc-layout" style="display: grid; grid-template-columns: 3fr 1fr; gap: 20px;">
        
        <div class="wrpc-main-col">
            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="?page=setting-power-cache&tab=debug_options" class="nav-tab <?php echo $active_tab == 'debug_options' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-tools" style="vertical-align: text-bottom; font-size: 18px;"></span> General & Debug
                </a>
                <!-- <a href="?page=setting-power-cache&tab=other_options" class="nav-tab <?php echo $active_tab == 'other_options' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-plugins" style="vertical-align: text-bottom; font-size: 18px;"></span> Optimization
                </a> -->
            </h2>

            <div class="wrpc-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <form action="options.php" method="POST">
                    <?php 
                        settings_fields('wp-power-cache-group'); 
                        do_settings_sections('setting-power-cache');
                        submit_button(__('Update Configuration', 'wp-power-cache'), 'primary');
                    ?>
                </form>
            </div>
        </div>

        <div class="wrpc-sidebar">
            <div class="wrpc-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px;">
                <h3 style="margin-top: 0; display: flex; align-items: center;">
                    <span class="dashicons dashicons-trash" style="margin-right: 5px;"></span> Cache Control
                </h3>
                <p class="description" style="margin-bottom: 15px;">Clear all static HTML files to refresh your site content immediately.</p>
                <form action="" method="POST">
                    <?php wp_nonce_field( 'wrpc_clear_cache_action', 'wrpc_nonce' ); ?>
                    <input type="submit" name="clear_all_cache" class="button button-large" style="width: 100%; border-color: #d63638; color: #d63638;" value="Empty Cache Folder">
                </form>
            </div>

            <div class="wrpc-card" style="background: #f0f6fb; border-left: 4px solid #0073aa; padding: 15px;">
                <h4 style="margin: 0 0 10px 0;">System Info</h4>
                <p style="font-size: 12px; line-height: 1.5;">
                    <strong>PHP Version:</strong> <?php echo phpversion(); ?><br>
                    <strong>Directory:</strong> <code>
					<?php if(is_dir($folder_Name)) {
							echo $folder_Name;
						  }
						  else {
							  echo "Not allowed to create this folder, please create manually and set write permission";
							  echo "Path: $folder_Name";
						  }
					?></code>
                </p>
            </div>
        </div>
    </div>
</div>