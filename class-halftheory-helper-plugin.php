<?php
/*
Available filters:
halftheory_admin_menu_parent
{self::$prefix}_admin_menu_parent
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Halftheory_Helper_Plugin')) :
class Halftheory_Helper_Plugin {

	public static $plugin_basename;
	public static $prefix;
	public $options = array();

	/* setup */

	public function __construct($plugin_basename = '', $prefix = '', $load_actions = true) {
		$this->init($plugin_basename, $prefix);
		if ($load_actions) {
			$this->setup_actions();
		}
	}

	public function init($plugin_basename = '', $prefix = '') {
		if (isset($this->plugin_is_network)) {
			unset($this->plugin_is_network);
		}
		if (!empty($plugin_basename)) {
			self::$plugin_basename = $plugin_basename;
		}
		$this->plugin_name = get_called_class();
		$this->plugin_title = ucwords(str_replace('_', ' ', $this->plugin_name));
		if (!empty($prefix)) {
			self::$prefix = $prefix;
		}
		else {
			self::$prefix = sanitize_key($this->plugin_name);
			self::$prefix = preg_replace("/[^a-z0-9]/", "", self::$prefix);
		}
		$this->options = array();
	}

	protected function setup_actions() {
		// admin options
		if (!$this->is_front_end()) {
			if ($this->is_plugin_network()) {
				add_action('network_admin_menu', array($this,'admin_menu'));
				if (is_main_site()) {
					add_action('admin_menu', array($this,'admin_menu'));
				}
			}
			else {
				add_action('admin_menu', array($this,'admin_menu'));
			}
		}
	}

	/* admin */

	public function admin_menu() {
		if (empty(self::$prefix)) {
			return;
		}
		if (!is_array($GLOBALS['menu'])) {
			return;
		}

		$has_parent = false;
		$parent_slug = self::$prefix;
		$parent_name = apply_filters('halftheory_admin_menu_parent', 'Halftheory');
		$parent_name = apply_filters(self::$prefix.'_admin_menu_parent', $parent_name);

		// set parent to nothing to skip parent menu creation
		if (empty($parent_name)) {
			add_options_page(
				$this->plugin_title,
				$this->plugin_title,
				'manage_options',
				self::$prefix,
				array($this,'menu_page')
			);
			return;
		}

		// find top level menu if it exists
	    foreach ($GLOBALS['menu'] as $value) {
	    	if ($value[0] == $parent_name) {
	    		$parent_slug = $value[2];
	    		$has_parent = true;
	    		break;
	    	}
	    }

		// add top level menu if it doesn't exist
		if (!$has_parent) {
			add_menu_page(
				$this->plugin_title,
				$parent_name,
				'manage_options',
				$parent_slug,
				array($this,'menu_page')
			);
		}

		// add the menu
		add_submenu_page(
			$parent_slug,
			$this->plugin_title,
			$this->plugin_title,
			'manage_options',
			self::$prefix,
			array($this,'menu_page')
		);
	}

	public function menu_page() {
 		global $title;
		?>
		<div class="wrap">
			<h2><?php echo $title; ?></h2>
		<?php
 		$plugin = new self(self::$plugin_basename, self::$prefix, false);
 		?>
 		</div><!-- wrap --><?
 	}

	/* functions */

	public function is_plugin_network() {
		if (isset($this->plugin_is_network)) {
			return $this->plugin_is_network;
		}
		$res = false;
		if (is_multisite()) {
			if (!function_exists('is_plugin_active_for_network')) {
				@require_once(ABSPATH.'/wp-admin/includes/plugin.php');
			}
			if (function_exists('is_plugin_active_for_network')) {
				if (is_plugin_active_for_network(self::$plugin_basename)) {
					$res = true;
				}
			}
		}
		$this->plugin_is_network = $res;
		return $res;
	}

	// options
	private function get_option_name($name = '', $is_network = null) {
		if (empty($name)) {
			$name = self::$prefix;
		}
		if (is_null($is_network)) {
			$is_network = $this->is_plugin_network();
		}
		if ($is_network) {
			$name = substr($name, 0, 255);
		}
		else {
			$name = substr($name, 0, 191);
		}
		return $name;
	}
	public function get_option($name = '', $key = '', $default = array()) {
		$name = $this->get_option_name($name);
		if (!isset($this->options[$name])) {
			if ($this->is_plugin_network()) {
				$option = get_site_option($name, array());
			}
			else {
				$option = get_option($name, array());
			}
			$this->options[$name] = $option;
		}
		if (!empty($key) && is_array($this->options[$name])) {
			if (array_key_exists($key, $this->options[$name])) {
				return $this->options[$name][$key];
			}
			return $default;
		}
		return $this->options[$name];
	}
	public function update_option($name = '', $value) {
		$name = $this->get_option_name($name);
		if ($this->is_plugin_network()) {
			$bool = update_site_option($name, $value);
		}
		else {
			$bool = update_option($name, $value);
		}
		if ($bool !== false) {
			$this->options[$name] = $value;
		}
		return $bool;
	}
	public function delete_option($name = '') {
		$name = $this->get_option_name($name);
		if ($this->is_plugin_network()) {
			$bool = delete_site_option($name);
		}
		else {
			$bool = delete_option($name);
		}
		if ($bool !== false && isset($this->options[$name])) {
			unset($this->options[$name]);
		}
		return $bool;
	}
	public function delete_option_uninstall($name = '') {
		$name_single = $this->get_option_name($name, false);
		global $wpdb;
		if (is_multisite()) {
			$name_network = $this->get_option_name($name, true);
			$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE '".$name_network."%'");
			$current_blog_id = get_current_blog_id();
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '".$name_single."%'");
			}
			switch_to_blog($current_blog_id);
		}
		else {
			$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '".$name_single."%'");
		}
	}

	// transients
	private function get_transient_name($name = '', $is_network = null) {
		if (empty($name)) {
			$name = self::$prefix;
		}
		if (is_null($is_network)) {
			$is_network = $this->is_plugin_network();
		}
		if ($is_network) {
			$name = substr($name, 0, 167);
		}
		else {
			$name = substr($name, 0, 172);
		}
		return $name;
	}
	public function get_transient($transient = '') {
		$transient = $this->get_transient_name($transient);
		if ($this->is_plugin_network()) {
			$value = get_site_transient($transient);
		}
		else {
			$value = get_transient($transient);
		}
		return $value;
	}
	public function set_transient($transient = '', $value, $expiration = 0) {
		$transient = $this->get_transient_name($transient);
		if (is_string($expiration)) {
			$expiration = strtotime('+'.trim($expiration, " -+")) - time();
			if (!$expiration || $expiration < 0) {
				$expiration = 0;
			}
		}
		if ($this->is_plugin_network()) {
			$bool = set_site_transient($transient, $value, $expiration);
		}
		else {
			$bool = set_transient($transient, $value, $expiration);
		}
		return $bool;
	}
	public function delete_transient($transient = '') {
		$transient = $this->get_transient_name($transient);
		if ($this->is_plugin_network()) {
			$bool = delete_site_transient($transient);
		}
		else {
			$bool = delete_transient($transient);
		}
		return $bool;
	}
	public function delete_transient_uninstall($transient = '') {
		$transient_single = $this->get_transient_name($transient, false);
		global $wpdb;
		if (is_multisite()) {
			$transient_network = $this->get_transient_name($transient, true);
			$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE '_site_transient_".$transient_network."%' OR meta_key LIKE '_site_transient_timeout_".$transient_network."%'");
			$current_blog_id = get_current_blog_id();
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_".$transient_single."%' OR option_name LIKE '_transient_timeout_".$transient_single."%'");
			}
			switch_to_blog($current_blog_id);
		}
		else {
			$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_".$transient_single."%' OR option_name LIKE '_transient_timeout_".$transient_single."%'");
		}
	}

	// postmeta
	private function get_postmeta_name($name = '') {
		if (empty($name)) {
			$name = self::$prefix;
		}
		$name = substr($name, 0, 255);
		return $name;
	}
	public function delete_postmeta_uninstall($name = '') {
		$name = $this->get_postmeta_name($name);
		global $wpdb;
		if (is_multisite()) {
			$current_blog_id = get_current_blog_id();
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '".$name."%'");
			}
			switch_to_blog($current_blog_id);
		}
		else {
			$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '".$name."%'");
		}
	}

	// usermeta
	private function get_usermeta_name($name = '') {
		if (empty($name)) {
			$name = self::$prefix;
		}
		$name = substr($name, 0, 255);
		return $name;
	}
	public function delete_usermeta_uninstall($name = '') {
		$name = $this->get_usermeta_name($name);
		global $wpdb;
		$wpdb->query("DELETE FROM $wpdb->usermeta WHERE meta_key LIKE '".$name."%'");
	}

	/* functions-common */

	public function is_true($value) {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func($value);
		}
		if (is_bool($value)) {
			return $value;
		}
		elseif (empty($value)) {
			return false;
		}
		if (is_numeric($value)) {
			if ((int)$value === 1) {
				return true;
			}
			elseif ((int)$value === 0) {
				return false;
			}
		}
		if (is_string($value)) {
			if ($value == '1' || $value == 'true') {
				return true;
			}
			elseif ($value == '0' || $value == 'false') {
				return false;
			}
		}
		return false;
	}

	public function make_array($str = '', $sep = ',') {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func($str, $sep);
		}
		if (is_array($str)) {
			return $str;
		}
		if (empty($str)) {
			return array();
		}
		$arr = explode($sep, $str);
		$arr = array_map('trim', $arr);
		$arr = array_filter($arr);
		return $arr;
	}

	public function is_front_end() {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func();
		}
		if (is_admin() && !wp_doing_ajax()) {
			return false;
		}
		if (wp_doing_ajax()) {
			if (strpos($this->get_current_uri(), admin_url()) !== false) {
				return false;
			}
		}
		return true;
	}

	public function get_current_uri() {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func();
		}
	 	$res  = is_ssl() ? 'https://' : 'http://';
	 	$res .= $_SERVER['HTTP_HOST'];
	 	$res .= $_SERVER['REQUEST_URI'];
		if (wp_doing_ajax()) {
			if (!empty($_SERVER["HTTP_REFERER"])) {
				$res = $_SERVER["HTTP_REFERER"];
			}
		}
		return $res;
	}

}
endif;
?>