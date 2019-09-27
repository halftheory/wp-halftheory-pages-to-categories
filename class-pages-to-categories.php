<?php
/*
Available filters:
pagestocategories_post_types
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Halftheory_Helper_Plugin')) {
	@include_once(dirname(__FILE__).'/class-halftheory-helper-plugin.php');
}

if (!class_exists('Pages_To_Categories') && class_exists('Halftheory_Helper_Plugin')) :
final class Pages_To_Categories extends Halftheory_Helper_Plugin {

	public function __construct($plugin_basename = '') {
		parent::__construct($plugin_basename);
		parent::setup_actions();

		// stop if not active
		/*
		$active = $this->get_option('active', false);
		if (empty($active)) {
			return;
		}
		*/

		// shortcode
		#$this->shortcode = '';
		#add_shortcode($this->shortcode, array($this, 'shortcode'));
	}

	/* functions-common */

	/* admin */

	public function menu_page() {
 		global $title;
		?>
		<div class="wrap">
			<h2><?php echo $title; ?></h2>
		<?php
 		$plugin = new Pages_To_Categories();
 		?>
 		</div><!-- wrap --><?
 	}

	/* functions */

    private function get_options_array() {
		return array(
			'active',
			'home_url',
			'redirect_urls',
			'post_types',
			'behavior_open',
			'behavior_close',
			'behavior_escape',
		);
    }

	/* shortcode */

	public function shortcode($atts = array(), $content = '', $shortcode = '') {
		if (!in_the_loop()) {
			return '';
		}
		return;
	}

	/* filters */

}
endif;
?>