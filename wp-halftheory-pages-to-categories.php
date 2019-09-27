<?php
/*
Plugin Name: Half/theory Pages to Categories
Plugin URI: https://github.com/halftheory/wp-halftheory-pages-to-categories
GitHub Plugin URI: https://github.com/halftheory/wp-halftheory-pages-to-categories
Description: Pages to Categories
Author: Half/theory
Author URI: https://github.com/halftheory
Version: 1.0
Network: false
*/

/*
Available filters:
pagestocategories_deactivation(string $db_prefix)
pagestocategories_uninstall(string $db_prefix)
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Pages_To_Categories_Plugin')) :
final class Pages_To_Categories_Plugin {

	public function __construct() {
		@include_once(dirname(__FILE__).'/class-pages-to-categories.php');
		$this->subclass = new Pages_To_Categories(plugin_basename(__FILE__));
	}

	public static function init() {
		$plugin = new self;
		return $plugin;
	}

	public static function activation() {
		$plugin = new self;
		return $plugin;
	}

	public static function deactivation() {
		$plugin = new self;
		apply_filters('pagestocategories_deactivation', $plugin->subclass::$prefix);
		return;
	}

	public static function uninstall() {
		$plugin = new self;
		if ($plugin->subclass) {
			$plugin->subclass->delete_transient_uninstall();
			$plugin->subclass->delete_option_uninstall();
		}
		apply_filters('pagestocategories_uninstall', $plugin->subclass::$prefix);
		return;
	}

}
// Load the plugin.
add_action('init', array('Pages_To_Categories_Plugin', 'init'));
endif;

register_activation_hook(__FILE__, array('Pages_To_Categories_Plugin', 'activation'));
register_deactivation_hook(__FILE__, array('Pages_To_Categories_Plugin', 'deactivation'));
function Pages_To_Categories_Plugin_uninstall() {
	Pages_To_Categories_Plugin::uninstall();
};
register_uninstall_hook(__FILE__, 'Pages_To_Categories_Plugin_uninstall');
?>