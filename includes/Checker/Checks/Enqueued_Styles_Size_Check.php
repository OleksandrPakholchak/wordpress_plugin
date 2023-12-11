<?php
/**
 * Class WordPress\Plugin_Check\Checker\Checks\Enqueued_Styles_Size_Check
 *
 * @package plugin-check
 */

namespace WordPress\Plugin_Check\Checker\Checks;

use Exception;
use WordPress\Plugin_Check\Checker\Check_Categories;
use WordPress\Plugin_Check\Checker\Check_Result;
use WordPress\Plugin_Check\Checker\Preparations\Demo_Posts_Creation_Preparation;
use WordPress\Plugin_Check\Checker\With_Shared_Preparations;
use WordPress\Plugin_Check\Traits\Amend_Check_Result;
use WordPress\Plugin_Check\Traits\Stable_Check;
use WordPress\Plugin_Check\Traits\URL_Aware;

/**
 * Check for enqueued style sizes.
 *
 * @since n.e.x.t
 */
class Enqueued_Styles_Size_Check extends Abstract_Runtime_Check implements With_Shared_Preparations {

	use Amend_Check_Result;
	use Stable_Check;
	use URL_Aware;

	/**
	 * Threshold for style size to surface a warning for.
	 *
	 * @since n.e.x.t
	 * @var int
	 */
	private $threshold_size;

	/**
	 * List of viewable post types.
	 *
	 * @since n.e.x.t
	 * @var array
	 */
	private $viewable_post_types;

	/**
	 * Set the threshold size for style sizes to surface warnings.
	 *
	 * @since n.e.x.t
	 *
	 * @param int $threshold_size The threshold in bytes for style size to surface warnings.
	 */
	public function __construct( $threshold_size = 300000 ) {
		$this->threshold_size = $threshold_size;
	}

	/**
	 * Gets the categories for the check.
	 *
	 * Every check must have at least one category.
	 *
	 * @since n.e.x.t
	 *
	 * @return array The categories for the check.
	 */
	public function get_categories() {
		return array( Check_Categories::CATEGORY_PERFORMANCE );
	}

	/**
	 * Runs this preparation step for the environment and returns a cleanup function.
	 *
	 * @since n.e.x.t
	 *
	 * @return callable Cleanup function to revert any changes made here.
	 *
	 * @throws Exception Thrown when preparation fails.
	 */
	public function prepare() {
		$orig_styles = isset( $GLOBALS['wp_styles'] ) ? $GLOBALS['wp_styles'] : null;

		// Backup the original values for the global state.
		$this->backup_globals();

		return function () use ( $orig_styles ) {
			if ( is_null( $orig_styles ) ) {
				unset( $GLOBALS['wp_styles'] );
			} else {
				$GLOBALS['wp_styles'] = $orig_styles;
			}

			$this->restore_globals();
		};
	}

	/**
	 * Returns an array of shared preparations for the check.
	 *
	 * @since n.e.x.t
	 *
	 * @return array Returns a map of $class_name => $constructor_args pairs. If the class does not
	 *               need any constructor arguments, it would just be an empty array.
	 */
	public function get_shared_preparations() {
		$demo_posts = array_map(
			static function ( $post_type ) {
				return array(
					'post_title'   => "Demo {$post_type} post",
					'post_content' => 'Test content',
					'post_type'    => $post_type,
					'post_status'  => 'publish',
				);
			},
			$this->get_viewable_post_types()
		);

		return array(
			Demo_Posts_Creation_Preparation::class => array( $demo_posts ),
		);
	}

	/**
	 * Runs the check on the plugin and amends results.
	 *
	 * @since n.e.x.t
	 *
	 * @param Check_Result $result The check results to amend and the plugin context.
	 */
	public function run( Check_Result $result ) {
		$this->run_for_urls(
			$this->get_urls(),
			function ( $url ) use ( $result ) {
				$this->check_url( $result, $url );
			}
		);
	}

	/**
	 * Gets the list of URLs to run this check for.
	 *
	 * @since n.e.x.t
	 *
	 * @return array List of URL strings (either full URLs or paths).
	 *
	 * @throws Exception Thrown when a post type URL cannot be retrieved.
	 */
	protected function get_urls() {
		$urls = array( home_url() );

		foreach ( $this->get_viewable_post_types() as $post_type ) {
			$posts = get_posts(
				array(
					'posts_per_page' => 1,
					'post_type'      => $post_type,
					'post_status'    => array( 'publish', 'inherit' ),
				)
			);

			if ( ! isset( $posts[0] ) ) {
				throw new Exception(
					sprintf(
						/* translators: %s: The Post Type name. */
						__( 'Unable to retrieve post URL for post type: %s', 'plugin-check' ),
						$post_type
					)
				);
			}

			$urls[] = get_permalink( $posts[0] );
		}

		return $urls;
	}

	/**
	 * Amends the given result by running the check for the given URL.
	 *
	 * @since n.e.x.t
	 *
	 * @param Check_Result $result The check result to amend, including the plugin context to check.
	 * @param string       $url    URL to run the check for.
	 *
	 * @throws Exception Thrown when the check fails with a critical error (unrelated to any errors detected as part of
	 *                   the check).
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	protected function check_url( Check_Result $result, $url ) {
		// Reset the WP_Styles instance.
		unset( $GLOBALS['wp_styles'] );

		// Run enqueue functions wrapped in an output buffer in case of any callbacks printing styles
		// directly. This is discouraged, but some plugins or themes are still doing it.
		ob_start();
		wp_enqueue_scripts();
		wp_styles()->do_items();
		wp_styles()->do_footer_items();
		ob_get_clean();

		$plugin_styles     = array();
		$plugin_style_size = 0;

		foreach ( wp_styles()->done as $handle ) {
			$style = wp_styles()->registered[ $handle ];

			if ( ! $style->src || strpos( $style->src, $result->plugin()->url() ) !== 0 ) {
				continue;
			}

			// Get size of style src.
			$style_path = str_replace( $result->plugin()->url(), $result->plugin()->path(), $style->src );
			$style_size = function_exists( 'wp_filesize' ) ? wp_filesize( $style_path ) : filesize( $style_path );

			// Get size of additional inline styles.
			if ( ! empty( $style->extra['after'] ) ) {
				foreach ( $style->extra['after'] as $extra ) {
					$style_size += ( is_string( $extra ) ) ? mb_strlen( $extra, '8bit' ) : 0;
				}
			}

			if ( ! empty( $style->extra['before'] ) ) {
				foreach ( $style->extra['before'] as $extra ) {
					$style_size += ( is_string( $extra ) ) ? mb_strlen( $extra, '8bit' ) : 0;
				}
			}

			$plugin_styles[]    = array(
				'path' => $style_path,
				'size' => $style_size,
			);
			$plugin_style_size += $style_size;
		}

		if ( $plugin_style_size > $this->threshold_size ) {
			foreach ( $plugin_styles as $plugin_style ) {
				$this->add_result_warning_for_file(
					$result,
					sprintf(
						'This style has a size of %1$s which in combination with the other styles enqueued on %2$s exceeds the style size threshold of %3$s.',
						size_format( $plugin_style['size'] ),
						$url,
						size_format( $this->threshold_size )
					),
					'EnqueuedStylesSize.StyleSizeGreaterThanThreshold',
					$plugin_style['path']
				);
			}
		}
	}

	/**
	 * Returns an array of viewable post types.
	 *
	 * @since n.e.x.t
	 *
	 * @return array Array of viewable post type slugs.
	 */
	private function get_viewable_post_types() {
		if ( ! is_array( $this->viewable_post_types ) ) {
			$this->viewable_post_types = array_filter( get_post_types(), 'is_post_type_viewable' );
		}

		return $this->viewable_post_types;
	}
}
