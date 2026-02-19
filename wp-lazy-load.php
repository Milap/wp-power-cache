<?php
if ( !defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WR_LazyLoad_Images' ) ) :

	class WR_LazyLoad_Images {
		const version = '0.1.0';
		protected static $enabled = true;

		public static function init() {
			if ( is_admin() ) return;
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enque_scripts' ) );
			add_action( 'wp_head', array( __CLASS__, 'add_filters' ), 9999 );
			add_action( 'wp_footer', array( __CLASS__, 'lazy_js_call' ) );
		}

		public static function add_filters() {
			add_filter( 'the_content', array( __CLASS__, 'enable_img_placeholders' ), 99 );
			add_filter( 'post_thumbnail_html', array( __CLASS__, 'enable_img_placeholders' ), 11 );
			add_filter( 'get_avatar', array( __CLASS__, 'enable_img_placeholders' ), 11 );
		}

		public static function enque_scripts() {
			wp_enqueue_script( 
				'wr-lazy-load-js', 
				plugins_url( 'assets/js/lazy-load.js', __FILE__ ), 
				array('jquery'), 
				self::version, 
				true 
			);
		}

		public static function enable_img_placeholders( $content ) {
			if ( ! self::$enabled || is_feed() || is_preview() ) {
				return $content;
			}

			if ( false !== strpos( $content, 'data-original' ) ) {
				return $content;
			}

			return preg_replace_callback( '#<(img)([^>]+?)(>(.*?)</\\1>|[\/]?>)#si', array(__CLASS__, 'process_image'), $content );
		}

		public static function process_image( $matches ) {
			$placeholder_image = plugins_url( 'assets/img/trans.gif', __FILE__ );
			$old_attributes_str = $matches[2];
			$old_attributes = wp_kses_hair( $old_attributes_str, wp_allowed_protocols() );

			if ( empty( $old_attributes['src'] ) ) {
				return $matches[0];
			}

			$image_src = $old_attributes['src']['value'];
			$old_class = $old_attributes['class']['value'] ?? '';
			$old_attributes['class']['value'] = trim($old_class . ' lazy');
			
			// Modern addition: browser native lazy loading
			$old_attributes['loading']['value'] = 'lazy';

			unset( $old_attributes['src'] );
			$new_attributes_str = self::build_attributes_string( $old_attributes );

			return sprintf( 
				'<img src="%1$s" data-original="%2$s" %3$s><noscript>%4$s</noscript>', 
				esc_url( $placeholder_image ), 
				esc_url( $image_src ), 
				$new_attributes_str, 
				$matches[0] 
			);
		}

		private static function build_attributes_string( $attributes ) {
			$string = array();
			foreach ( $attributes as $name => $attribute ) {
				$string[] = sprintf( '%s="%s"', esc_attr($name), esc_attr($attribute['value']) );
			}
			return implode( ' ', $string );
		}

		public static function lazy_js_call() {
			?>
			<script>
				jQuery(function($) {
					$("img.lazy").lazyload({
						threshold : 200,
						effect : "fadeIn"
					});
				});
			</script>
			<?php
		}
	}
	WR_LazyLoad_Images::init();
endif;