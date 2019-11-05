<?php
/*
Available filters:
pagestocategories_template_names
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Halftheory_Helper_Plugin')) {
	@include_once(dirname(__FILE__).'/class-halftheory-helper-plugin.php');
}

if (!class_exists('Pages_To_Categories') && class_exists('Halftheory_Helper_Plugin')) :
final class Pages_To_Categories extends Halftheory_Helper_Plugin {

	public static $active = false;
	public static $registered_taxonomies = array();

	/* setup */

	public function init($plugin_basename = '', $prefix = '') {
		parent::init($plugin_basename, $prefix);
		self::$active = $this->get_option(self::$prefix, 'active', false);
		$this->options_posts = self::$prefix.'_posts';
		$this->postmeta_term_id = self::$prefix.'_term_id';
	}

	protected function setup_actions() {
		parent::setup_actions();

		// stop if not active
		if (!empty(self::$active)) {
			add_action('init', array($this,'init_register_taxonomy'), 20); // must be before register_post_type!
			add_filter('term_link', array($this,'term_link'), 20, 3);
			add_filter('get_terms', array($this,'get_terms'), 20, 4);
			if ($this->is_front_end()) {
				//add_filter('the_posts', array($this,'the_posts'), 20, 2); // prefer loop_end for now
				add_action('loop_end', array($this,'loop_end'), 20);
			}
		}

		// admin
		if (!$this->is_front_end()) {
			$hierarchical_post_types = $this->get_option(self::$prefix, 'hierarchical_post_types', array());
			if (!empty($hierarchical_post_types)) {
				foreach ($hierarchical_post_types as $value) {
					add_action('add_meta_boxes_'.$value, array($this,'hierarchical_post_type_metaboxes'));
					add_action('save_post_'.$value, array($this,'hierarchical_post_type_save_post'), 20, 3);
				}
				add_action('after_delete_post', array($this,'after_delete_post'), 20);
				add_action('admin_enqueue_scripts', array($this,'admin_enqueue_scripts'), 20);
				add_filter('post_class', array($this,'post_class'), 20, 3);
			}
		}
	}

	/* actions */

	public function init_register_taxonomy() {
		$hierarchical_post_types = $this->get_option(self::$prefix, 'hierarchical_post_types', array());
		if (empty($hierarchical_post_types)) {
			return;
		}
		$taxonomy_object_types = $this->get_option(self::$prefix, 'taxonomy_object_types', array());
		if (empty($taxonomy_object_types)) {
			return;
		}
		$options_posts = $this->get_option($this->options_posts, null, array());
		foreach ($options_posts as $post_id => $arr) {
			$post = get_post($post_id);
			if (empty($post)) {
				continue;
			}
			if (!$this->post_is_taxonomy($post)) {
				continue;
			}
			if (!in_array($post->post_type, $hierarchical_post_types)) {
				continue;
			}
			$key = $post->post_name;
			if (taxonomy_exists($key)) {
				continue;
			}
			if (in_array($key, $this->wp_reserved_terms())) {
				continue;
			}
			$post_title = get_post_field('post_title', $post, 'raw');
			if (!isset($arr['singular_name'])) {
				$arr['singular_name'] = $post_title;
			}
			if (empty($arr['singular_name'])) {
				$arr['singular_name'] = $post_title;
			}
			if (!isset($arr['plural_name'])) {
				$arr['plural_name'] = $post_title;
			}
			if (empty($arr['plural_name'])) {
				$arr['plural_name'] = $post_title;
			}
			$labels = array(
				'name' => $post_title,
				'singular_name' => $arr['singular_name'],
				'search_items' => 'Search '.$arr['plural_name'],
				'all_items' => 'All '.$arr['plural_name'],
				'parent_item' => 'Parent '.$arr['singular_name'],
				'parent_item_colon' => 'Parent '.$arr['singular_name'].':',
				'edit_item' => 'Edit '.$arr['singular_name'],
				'update_item' => 'Update '.$arr['singular_name'],
				'add_new_item' => 'Add New '.$arr['singular_name'],
				'new_item_name' => 'New '.$arr['singular_name'],
				'menu_name' => $arr['plural_name'],
			);
			$query_var = false;
			$rewrite = false;
			if (isset($arr['links'])) {
				if ($arr['links'] == 'query_var') {
					$query_var = $key;
				}
				elseif ($arr['links'] == 'rewrite') {
					$query_var = $key;
					$rewrite = array(
						'hierarchical' => true,
						'feed' => false,
						'ep_mask' => EP_CATEGORIES,
					);
				}
				elseif ($arr['links'] == 'term_link') {
					$query_var = $key;
				}
			}
			register_taxonomy(
				$key,
				$taxonomy_object_types,
				array(
					'description' => $arr['plural_name'],
					'hierarchical' => true,
					'labels' => $labels,
					'public' => true,
					'show_ui' => true,
					'show_in_nav_menus' => false,
					'query_var' => $query_var,
					'rewrite' => $rewrite,
					'update_count_callback' => '_update_post_term_count'
				)
			);
			$arr['parent'] = $post;
			self::$registered_taxonomies[$key] = $arr;
		}
	}

	public function term_link($termlink, $term, $taxonomy) {
		if (empty(self::$registered_taxonomies)) {
			return $termlink;
		}
		if (!headers_sent()) {
			return $termlink;
		}
		if (!isset(self::$registered_taxonomies[$taxonomy])) {
			return $termlink;
		}
		if (!isset(self::$registered_taxonomies[$taxonomy]['links'])) {
			return $termlink;
		}
		if (self::$registered_taxonomies[$taxonomy]['links'] !== 'term_link') {
			return $termlink;
		}
		$args = array(
			'meta_query' => array(
				array(
					'key' => $this->postmeta_term_id,
					'value' => $term->term_id
				)
			),
			'post_type' => self::$registered_taxonomies[$taxonomy]['parent']->post_type,
			'numberposts' => 1
		);
		$posts = get_posts($args);
		if (empty($posts) || is_wp_error($posts)) {
			return $termlink;
		}
		$termlink = get_the_permalink($posts[0]);
		return $termlink;
	}

	public function get_terms($terms = array(), $taxonomy = array(), $query_vars, $term_query) {
		if (empty(self::$registered_taxonomies)) {
			return $terms;
		}
		if (!headers_sent()) {
			return $terms;
		}
		if (count($terms) < 2) {
			return $terms;
		}
		elseif (!is_object(current($terms))) {
			return $terms;
		}
		elseif (empty($taxonomy)) {
			return $terms;
		}
		elseif (count($taxonomy) > 1) {
			return $terms;
		}
		$tax = current($taxonomy);
		if (!isset(self::$registered_taxonomies[$tax])) {
			return $terms;
		}
		if (!isset(self::$registered_taxonomies[$tax]['order'])) {
			return $terms;
		}
		if (self::$registered_taxonomies[$tax]['order'] == 'post_title') {
			return $terms;
		}
		// get correct order of post_ids and related term_ids
		if ($children = $this->get_taxonomy_children_posts(self::$registered_taxonomies[$tax]['parent'], self::$registered_taxonomies[$tax]['order'])) {
			$terms_old = $terms;
			$terms = array();
			foreach ($children as $child) {
				$term_id = get_post_meta($child->ID, $this->postmeta_term_id, true);
				if (empty($term_id)) {
					continue;
				}
				foreach ($terms_old as $key => $value) {
					if ($value->term_id == (int)$term_id) {
						$terms[] = $value;
						unset($terms_old[$key]);
						break;
					}
				}
			}
			// add any leftover terms to end of array
			if (!empty($terms_old)) {
				$terms = array_merge($terms, $terms_old);
			}
		}
		return $terms;
	}

	public function the_posts($posts, $wp_query) {
		if (!is_main_query()) {
			return $posts;
		}
		if ($parent = self::can_append_posts($wp_query->posts)) {
			$taxonomy_object_types = $this->get_option(self::$prefix, 'taxonomy_object_types', array());
			if (empty($taxonomy_object_types)) {
				$taxonomy_object_types = 'any';
			}
			$args = array(
				'post_type' => $taxonomy_object_types,
			);
			if ($posts_tax = self::get_taxonomy_posts($posts[0], $args, $parent)) {
				$posts = array_merge($posts, $posts_tax);
				// change wp_query vars
				$wp_query->is_singular = false;
				$wp_query->is_archive = true;
				$wp_query->posts = $posts;
				$wp_query->found_posts = count($posts);
			}
		}
		return $posts;
	}

	public function loop_end($wp_query) {
		if (!in_the_loop()) {
			return;
		}
		if ($parent = self::can_append_posts($wp_query->posts)) {
			$taxonomy_object_types = $this->get_option(self::$prefix, 'taxonomy_object_types', array());
			if (empty($taxonomy_object_types)) {
				$taxonomy_object_types = 'any';
			}
			$args = array(
				'post_type' => $taxonomy_object_types,
			);
			$args = self::get_taxonomy_posts_args($wp_query->posts[0], $args, $parent);
			$posts = query_posts($args);
			if (empty($posts)) {
				wp_reset_query();
				return;
			}
			// remove this filter to prevent infinite looping
			remove_action(current_action(), array($this,__FUNCTION__), 20);
			$template_names = array(
				'loop.php',
				'index.php',
			);
			$template_names = apply_filters('pagestocategories_template_names', $template_names, $posts, $args);
			locate_template($template_names, true);
			wp_reset_query();
		}
	}

	/* admin */

	public function menu_page() {
 		global $title;
		?>
		<div class="wrap">
			<h2><?php echo $title; ?></h2>
		<?php
 		$plugin = new self(self::$plugin_basename, self::$prefix, false);

		if ($plugin->save_menu_page()) {
        	$save = function() use ($plugin) {
				// get values
				$options_arr = $plugin->get_options_array();
				$options = array();
				foreach ($options_arr as $value) {
					$name = $plugin::$prefix.'_'.$value;
					if (!isset($_POST[$name])) {
						continue;
					}
					if (empty($_POST[$name])) {
						continue;
					}
					$options[$value] = $_POST[$name];
				}
				// save it
	            $updated = '<div class="updated"><p><strong>Options saved.</strong></p></div>';
	            $error = '<div class="error"><p><strong>Error: There was a problem.</strong></p></div>';
				if (!empty($options)) {
		            if ($plugin->update_option($plugin::$prefix, $options)) {
		            	echo $updated;
		            }
		        	else {
		        		// where there changes?
		        		$options_old = $plugin->get_option($plugin::$prefix, null, array());
		        		ksort($options_old);
		        		ksort($options);
		        		if ($options_old !== $options) {
		            		echo $error;
		            	}
		            	else {
			            	echo $updated;
		            	}
		        	}
				}
				else {
		            if ($plugin->delete_option()) {
		            	echo $updated;
		            }
		        	else {
		            	echo $updated;
		        	}
				}
			};
			$save();
        } // save

		// show the form
		$options_arr = $plugin->get_options_array();
		$options = $plugin->get_option($plugin::$prefix, null, array());
		$options = array_merge( array_fill_keys($options_arr, null), $options );
		?>
	    <form id="<?php echo $plugin::$prefix; ?>-admin-form" name="<?php echo $plugin::$prefix; ?>-admin-form" method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
		<?php
		// Use nonce for verification
		wp_nonce_field(self::$plugin_basename, $plugin->plugin_name.'::'.__FUNCTION__);
		?>
	    <div id="poststuff">

        <p><label for="<?php echo $plugin::$prefix; ?>_active"><input type="checkbox" id="<?php echo $plugin::$prefix; ?>_active" name="<?php echo $plugin::$prefix; ?>_active" value="1"<?php checked($options['active'], 1); ?> /> <?php echo $plugin->plugin_title; ?> <?php _e('active?'); ?></label></p>

		<div class="postbox">
			<div class="inside">
		        <h4><?php _e('Hierarchical Post Types'); ?></h4>
		        <p><span class="description"><?php _e('The following post types can be converted to taxonomies.'); ?></span></p>
		        <?php
		        $post_types = array();
		        $arr = get_post_types(array('public' => true, 'hierarchical' => true), 'objects');
		        foreach ($arr as $key => $value) {
	        		$post_types[$key] = $value->label;
		        }
		        $options['hierarchical_post_types'] = $plugin->make_array($options['hierarchical_post_types']);
		        foreach ($post_types as $key => $value) {
					echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin::$prefix.'_hierarchical_post_types[]" value="'.$key.'"';
					if (in_array($key, $options['hierarchical_post_types'])) {
						checked($key, $key);
					}
					echo '> '.$value.'</label>';
		        }
		        ?>
			</div>
		</div>

		<div class="postbox">
			<div class="inside">
		        <h4><?php _e('Allowed Post Types'); ?></h4>
		        <p><span class="description"><?php _e('The above taxonomies will be available to the following post types.'); ?></span></p>
		        <?php
		        $post_types = array();
		        $arr = get_post_types(array('public' => true), 'objects');
		        foreach ($arr as $key => $value) {
	        		$post_types[$key] = $value->label;
		        }
		        $options['taxonomy_object_types'] = $plugin->make_array($options['taxonomy_object_types']);
		        foreach ($post_types as $key => $value) {
					echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin::$prefix.'_taxonomy_object_types[]" value="'.$key.'"';
					if (in_array($key, $options['taxonomy_object_types'])) {
						checked($key, $key);
					}
					echo '> '.$value.'</label>';
		        }
		        ?>
			</div>
		</div>

        <?php submit_button(__('Update'), array('primary','large'), 'save'); ?>

        </div><!-- poststuff -->
    	</form>

 		</div><!-- wrap --><?
 	}

	public function hierarchical_post_type_metaboxes($post) {
		if (empty($post)) {
			return;
		}
		// not on new posts
		if ($post->post_status == 'auto-draft' || empty($post->post_name)) {
			return;
		}
		if ($parent = $this->post_is_taxonomy_child($post)) {
			add_meta_box(
				$this->options_posts,
				$this->plugin_title,
				array($this, 'hierarchical_post_type_metaboxes_child'),
				$post->post_type,
				'normal', // side, normal
				'low',
				array('parent' => $parent)
			);
		}
		else {
			add_meta_box(
				$this->options_posts,
				$this->plugin_title,
				array($this, 'hierarchical_post_type_metaboxes_parent'),
				$post->post_type,
				'normal', // side, normal
				'low',
				null
			);
		}
	}

	public function hierarchical_post_type_metaboxes_child($post, $field = array()) {
		if (empty($field)) {
			return;
		}
		if (!isset($field['args']['parent'])) {
			return;
		}
		$parent = $field['args']['parent'];
		$arr = array_merge( $this->get_options_post_array(), $this->get_option($this->options_posts, $parent->ID, array()) );
		?>
		<label class="screen-reader-text" for="<?php echo $field['id']; ?>"><?php echo $field['title']; ?></label>
		<input type="hidden" id="<?php echo $field['id']; ?>_parent_id" name="<?php echo $field['id']; ?>_parent_id" value="<?php echo $parent->ID; ?>" />

		<p><?php _e('Parent:'); ?> <a href="<?php the_permalink($parent); ?>"><?php echo get_the_title($parent); ?></a></p>

        <p><label for="<?php echo $field['id']; ?>_exclude"><input type="checkbox" id="<?php echo $field['id']; ?>_exclude" name="<?php echo $field['id']; ?>_exclude" value="<?php echo $post->ID; ?>"<?php checked(in_array($post->ID, $arr['exclude']), 1); ?> /> <?php _e('Exclude from parent taxonomy?'); ?></label></p>
		<?
	}

	public function hierarchical_post_type_metaboxes_parent($post, $field = array()) {
		if (empty($field)) {
			return;
		}
		$arr = array_merge( $this->get_options_post_array(), $this->get_option($this->options_posts, $post->ID, array()) );
		?>
		<label class="screen-reader-text" for="<?php echo $field['id']; ?>"><?php echo $field['title']; ?></label>

        <p><label for="<?php echo $field['id']; ?>_active"><input type="checkbox" id="<?php echo $field['id']; ?>_active" name="<?php echo $field['id']; ?>_active" value="1"<?php checked($arr['active'], 1); ?> /> <?php 
		global $wp_post_types;
		echo $wp_post_types[$post->post_type]->labels->singular_name;
        _e(' is taxonomy?'); ?></label></p>

        <p><label for="<?php echo $field['id']; ?>_include_children"><input type="checkbox" id="<?php echo $field['id']; ?>_include_children" name="<?php echo $field['id']; ?>_include_children" value="1"<?php checked($arr['include_children'], 1); ?> /> <?php _e('Include children?'); ?></label></p>

		<p><label for="<?php echo $field['id']; ?>_order"><span style="min-width: 10em; display: inline-block;"><?php _e('Order by'); ?></span> <select name="<?php echo $field['id']; ?>_order" id="<?php echo $field['id']; ?>_order" style="min-width: 10em; width: auto;">
		<?php
		$select_arr = array('menu_order' => __('Page Order'), 'post_title' => __('Alphabetical'));
		foreach ($select_arr as $key => $value) {
			echo '<option value="'.esc_attr($key).'"'.selected($arr['order'], $key, false).'>'.esc_html($value).'</option>'."\n";
		}
		?>
		</select></label></p>

		<p><label for="<?php echo $field['id']; ?>_links"><span style="min-width: 10em; display: inline-block;"><?php _e('Rewrite Links'); ?></span> <select name="<?php echo $field['id']; ?>_links" id="<?php echo $field['id']; ?>_links" style="min-width: 10em; width: auto;">
		<?php
		$select_arr = array(
			'' => __('none: ?taxonomy=parent&term=child'),
			'query_var' => __('query_var: ?parent=child'),
			'rewrite' => __('rewrite: taxonomy overwrites post (resave Permalinks)'),
			'term_link' => __('term_link: post overwrites taxonomy'),
		);
		foreach ($select_arr as $key => $value) {
			echo '<option value="'.esc_attr($key).'"'.selected($arr['links'], $key, false).'>'.esc_html($value).'</option>'."\n";
		}
		?>
		</select></label></p>

        <p><label for="<?php echo $field['id']; ?>_append_posts"><input type="checkbox" id="<?php echo $field['id']; ?>_append_posts" name="<?php echo $field['id']; ?>_append_posts" value="1"<?php checked($arr['append_posts'], 1); ?> /> <?php _e('Append taxonomy posts to page?'); ?></label></p>

		<p><label for="<?php echo $field['id']; ?>_singular_name"><span style="min-width: 10em; display: inline-block;"><?php _e('Singular name'); ?></span> <input type="text" name="<?php echo $field['id']; ?>_singular_name" id="<?php echo $field['id']; ?>_singular_name" style="min-width: 10em; width: auto;" value="<?php echo esc_attr($arr['singular_name']); ?>" /></label></p>

		<p><label for="<?php echo $field['id']; ?>_plural_name"><span style="min-width: 10em; display: inline-block;"><?php _e('Plural name'); ?></span> <input type="text" name="<?php echo $field['id']; ?>_plural_name" id="<?php echo $field['id']; ?>_plural_name" style="min-width: 10em; width: auto;" value="<?php echo esc_attr($arr['plural_name']); ?>" /></label></p>
		<?
	}

	public function hierarchical_post_type_save_post($post_id, $post, $update) {
    	if (empty($update)) {
    		return;
    	}
    	// update options, only on Edit>Post page
		if (!empty($_POST)) {
			if (isset($_POST['_wpnonce'])) {
				if (wp_verify_nonce($_POST['_wpnonce'], 'update-post_'.$post_id)) {
					if (isset($_POST[$this->options_posts.'_parent_id'])) {
						$this->hierarchical_post_type_save_post_child($post_id, $post, (int)$_POST[$this->options_posts.'_parent_id']);
					}
					else {
						$this->hierarchical_post_type_save_post_parent($post_id, $post);
					}
				}
			}
		}
		// update terms
		if ($parent = $this->post_is_taxonomy_child($post)) {
			$this->save_post_update_terms_child($post, $parent);
		}
		elseif ($this->post_is_taxonomy($post)) {
			$this->save_post_update_terms_parent($post);
		}
	}

	private function hierarchical_post_type_save_post_child($post_id, $post, $parent_id = 0) {
    	if (empty($parent_id)) {
    		return;
    	}
		$options_posts = $this->get_option($this->options_posts, null, array());
		if (!isset($options_posts[$parent_id]) && !isset($_POST[$this->options_posts.'_exclude'])) {
			return;
		}
		elseif (!isset($options_posts[$parent_id]) && isset($_POST[$this->options_posts.'_exclude'])) {
			$options_posts[$parent_id] = $this->get_options_post_array();
			$options_posts[$parent_id]['exclude'] = array((int)$_POST[$this->options_posts.'_exclude']);
		}
		elseif (isset($options_posts[$parent_id]) && !isset($_POST[$this->options_posts.'_exclude'])) {
			if (!isset($options_posts[$parent_id]['exclude'])) {
				return;
			}
			elseif (empty($options_posts[$parent_id]['exclude'])) {
				return;
			}
			elseif (!in_array($post_id, $options_posts[$parent_id]['exclude'])) {
				return;
			}
			elseif (in_array($post_id, $options_posts[$parent_id]['exclude'])) {
				$options_posts[$parent_id]['exclude'] = array_diff($options_posts[$parent_id]['exclude'], array($post_id));
			}
		}
		elseif (isset($options_posts[$parent_id]) && isset($_POST[$this->options_posts.'_exclude'])) {
			if (!isset($options_posts[$parent_id]['exclude'])) {
				$options_posts[$parent_id]['exclude'] = array((int)$_POST[$this->options_posts.'_exclude']);
			}
			elseif (empty($options_posts[$parent_id]['exclude'])) {
				$options_posts[$parent_id]['exclude'] = array((int)$_POST[$this->options_posts.'_exclude']);
			}
			elseif (!in_array($post_id, $options_posts[$parent_id]['exclude'])) {
				$options_posts[$parent_id]['exclude'][] = (int)$_POST[$this->options_posts.'_exclude'];
			}
			elseif (in_array($post_id, $options_posts[$parent_id]['exclude'])) {
				return;
			}
		}
		// remove old parent - can't be child and parent
		if (isset($options_posts[$post_id])) {
			unset($options_posts[$post_id]);
		}
		$this->update_option($this->options_posts, $options_posts);
	}

	private function hierarchical_post_type_save_post_parent($post_id, $post) {
		$arr = $values = $this->get_options_post_array();
		foreach ($arr as $key => $value) {
			if (isset($_POST[$this->options_posts.'_'.$key])) {
				$values[$key] = $_POST[$this->options_posts.'_'.$key];
			}
			elseif(is_bool($value)) {
				$values[$key] = false;
			}
		}
		ksort($arr);
		ksort($values);
		$options_posts = $this->get_option($this->options_posts, null, array());
		if (!isset($options_posts[$post_id])) {
			if ($arr === $values) {
				return;
			}
			$options_posts[$post_id] = $values;
		}
		elseif (isset($options_posts[$post_id])) {
			if (isset($options_posts[$post_id]['exclude'])) {
				$values['exclude'] = $options_posts[$post_id]['exclude'];
				ksort($values);
			}
			ksort($options_posts[$post_id]);
			if ($options_posts[$post_id] === $values) {
				return;
			}
			if ($arr === $values) {
				unset($options_posts[$post_id]);
			}
			else {
				$options_posts[$post_id] = $values;
			}
		}
		if (empty($options_posts)) {
			$this->delete_option($this->options_posts);
		}
		else {
			$this->update_option($this->options_posts, $options_posts);
		}
	}

	private function save_post_update_terms_child($post, $parent) {
		if ($result = $this->post_is_taxonomy_child_active($post)) {
			$args = array(
				'post_type' => $post->post_type,
				'post_status' => array('publish', 'pending', 'inherit'),
				'sort_column' => 'menu_order',
				'child_of' => $post->ID,
			);
			$children = get_pages($args);
			if ($result === 'exclude') {
				$this->delete_term($post, $parent);
				// children
				if (!empty($children)) {
					foreach ($children as $child) {
						$this->delete_term($child, $parent);
					}
				}
			}
			else {
				$this->update_term($post, $parent);
				// children
				if (!empty($children)) {
					foreach ($children as $child) {
						$this->update_term($child, $parent);
					}
				}
			}
		}
	}

	private function save_post_update_terms_parent($post) {
		// update
		if ($children = $this->get_taxonomy_children_posts($post)) {
			// check taxonomy slug
			foreach ($children as $child) {
				$term_id = get_post_meta($child->ID, $this->postmeta_term_id, true);
				if (!empty($term_id)) {
					$term = get_term((int)$term_id);
					if (!empty($term) && !is_wp_error($term)) {
						if (in_array($term->taxonomy, $this->wp_reserved_terms())) {
							break;
						}
						elseif ($term->taxonomy !== $post->post_name) {
							global $wpdb;
							$wpdb->query("UPDATE $wpdb->term_taxonomy SET taxonomy = '".$post->post_name."' WHERE taxonomy = '".$term->taxonomy."'");
						}
					}
				}
				break;
			}
			// update terms
			foreach ($children as $child) {
				$this->update_term($child, $post);
			}
		}
		// delete exclude
		$arr = $this->get_option($this->options_posts, $post->ID, array());
		if (isset($arr['exclude'])) {
			if (!empty($arr['exclude'])) {
				foreach ($arr['exclude'] as $value) {
					if ($exclude = get_post($value)) {
						$this->delete_term($exclude, $post);
						// children
						$args = array(
							'post_type' => $post->post_type,
							'post_status' => array('publish', 'pending', 'inherit'),
							'sort_column' => 'menu_order',
							'child_of' => $exclude->ID,
						);
						$exclude_children = get_pages($args);
						if (!empty($exclude_children)) {
							foreach ($exclude_children as $child) {
								$this->delete_term($child, $post);
							}
						}
					}
				}
			}
		}
	}

	public function after_delete_post($post_id) {
		$this->delete_term($post_id);
	}

	public function admin_enqueue_scripts() {
		if (!function_exists('get_current_screen')) {
			return;
		}
		if (strpos(get_current_screen()->id, 'edit-') === false) {
			return;
		}
		global $typenow;
		if (!is_post_type_hierarchical($typenow)) {
			return;
		}
		wp_enqueue_style(self::$prefix, plugins_url('/assets/css/pages-to-categories-admin.css', __FILE__), array(), null, 'screen');
	}

	public function post_class($classes = array(), $class, $post_id) {
		if (!function_exists('get_current_screen')) {
			return $classes;
		}
		if (strpos(get_current_screen()->id, 'edit-') === false) {
			return $classes;
		}
		global $typenow;
		if (!is_post_type_hierarchical($typenow)) {
			return $classes;
		}
		if ($this->post_is_taxonomy(get_post($post_id))) {
			$classes[] = self::$prefix.'-parent';
		}
		elseif ($result = $this->post_is_taxonomy_child_active(get_post($post_id))) {
			if ($result === 'exclude') {
				return $classes;
			}
			$classes[] = self::$prefix.'-child';
		}
		return $classes;
	}

	/* functions */

    private function get_options_array() {
		return array(
			'active',
			'hierarchical_post_types',
			'taxonomy_object_types',
		);
    }

    private function get_options_post_array() {
		return array(
			'active' => false,
			'include_children' => true,
			'order' => 'menu_order',
			'links' => '',
			'append_posts' => true,
			'singular_name' => '',
			'plural_name' => '',
			'exclude' => array(),
		);
    }

	private function post_is_taxonomy($post) {
		$arr = $this->get_option($this->options_posts, $post->ID, array());
		if (isset($arr['active'])) {
			if (!empty($arr['active'])) {
				return true;
			}
		}
		return false;
	}
	private function post_is_taxonomy_child($post) {
		$ancestors = get_ancestors($post->ID, $post->post_type);
		foreach ($ancestors as $value) {
			if ($parent = get_post($value)) {
				if ($this->post_is_taxonomy($parent)) {
					// more checks
					$arr = $this->get_option($this->options_posts, $parent->ID, array());
					if (isset($arr['include_children'])) {
						if (!empty($arr['include_children'])) {
							return $parent;
						}
					}
				}
			}
		}
		return false;
	}
	private function post_is_taxonomy_child_active($post) {
		if ($parent = $this->post_is_taxonomy_child($post)) {
			// more checks
			$arr = $this->get_option($this->options_posts, $parent->ID, array());
			if (isset($arr['exclude'])) {
				if (in_array($post->ID, $arr['exclude'])) {
					return 'exclude';
				}
			}
			// could be child of exclude
			if ($children = $this->get_taxonomy_children_posts($parent)) {
				$children_ids = array();
				foreach ($children as $child) {
					$children_ids[] = $child->ID;
				}
				if (in_array($post->ID, $children_ids)) {
					return $parent;
				}
			}
			return 'exclude';
		}
		return false;
	}

	private function get_taxonomy_children_posts($post, $sort_column = 'menu_order') {
		if (!$this->post_is_taxonomy($post)) {
			return false;
		}
		$arr = $this->get_option($this->options_posts, $post->ID, array());
		if (!isset($arr['include_children'])) {
			return false;
		}
		elseif (empty($arr['include_children'])) {
			return false;
		}
		$args = array(
			'post_type' => $post->post_type,
			'post_status' => array('publish', 'pending', 'inherit'),
			'sort_column' => $sort_column,
			'child_of' => $post->ID,
		);
		if (isset($arr['exclude'])) {
			$args['exclude'] = $arr['exclude'];
		}
		$children = get_pages($args);
		if (empty($children)) {
			return false;
		}
		return $children;
	}

	public function update_term($post, $parent) {
		if (in_array($parent->post_name, $this->wp_reserved_terms())) {
			return;
		}
		$term_parent = 0;
		if ($post->post_parent !== $parent->ID) {
			$term_parent = get_post_meta($post->post_parent, $this->postmeta_term_id, true);
		}
		$args = array(
			'description' => __('Created by plugin: ').$this->plugin_title,
			'parent' => (int)$term_parent,
			'slug' => $post->post_name,
		);
		$term_id = get_post_meta($post->ID, $this->postmeta_term_id, true);
		// new
		if (empty($term_id)) {
			$arr = wp_insert_term($post->post_title, $parent->post_name, $args);
			if (!empty($arr) && !is_wp_error($arr)) {
				update_post_meta($post->ID, $this->postmeta_term_id, $arr['term_id']);
			}
		}
		// update
		else {
			$args['name'] = $post->post_title;
			$arr = wp_update_term((int)$term_id, $parent->post_name, $args);
		}
	}
	public function delete_term($post, $parent = null) {
		$post_id = null;
		if (is_numeric($post)) {
			$post_id = (int)$post;
		}
		elseif (is_object($post)) {
			if (isset($post->ID)) {
				$post_id = $post->ID;
			}
		}
		if (empty($post_id)) {
			return;
		}
		$term_id = get_post_meta($post_id, $this->postmeta_term_id, true);
		if (!empty($term_id)) {
			$taxonomy = null;
			if (empty($parent)) {
				$term = get_term((int)$term_id);
				if (!empty($term) && !is_wp_error($term)) {
					$taxonomy = $term->taxonomy;
				}
				else {
					return;
				}
			}
			else {
				$taxonomy = $parent->post_name;
			}
			wp_delete_term((int)$term_id, $taxonomy);
			delete_post_meta($post_id, $this->postmeta_term_id);
		}
	}

	private function wp_reserved_terms() {
		return array('attachment','attachment_id','author','author_name','calendar','cat','category','category__and','category__in','category__not_in','category_name','comments_per_page','comments_popup','customize_messenger_channel','customized','cpage','day','debug','error','exact','feed','fields','hour','link_category','m','minute','monthnum','more','name','nav_menu','nonce','nopaging','offset','order','orderby','p','page','page_id','paged','pagename','pb','perm','post','post__in','post__not_in','post_format','post_mime_type','post_status','post_tag','post_type','posts','posts_per_archive_page','posts_per_page','preview','robots','s','search','second','sentence','showposts','static','subpost','subpost_id','tag','tag__and','tag__in','tag__not_in','tag_id','tag_slug__and','tag_slug__in','taxonomy','tb','term','theme','type','w','withcomments','withoutcomments','year');
	}

	public static function get_taxonomy_posts($post = null, $args = array(), $parent = null) {
		if (empty(self::$registered_taxonomies)) {
			return false;
		}
		if (empty($post)) {
			wp_reset_postdata();
			$post = get_post(get_the_ID());
		}
		elseif (is_numeric($post)) {
			$post = get_post($post);
		}
		if (!is_object($post)) {
			return false;
		}
		if (empty($parent)) {
			$plugin = new self(self::$plugin_basename, self::$prefix, false);
			if ($result = $plugin->post_is_taxonomy_child_active($post)) {
				if ($result === 'exclude') {
					return false;
				}
				$parent = $result;
			}
			else {
				return false;
			}
		}
		if (!isset(self::$registered_taxonomies[$parent->post_name])) {
			return false;
		}
		$args = self::get_taxonomy_posts_args($post, $args, $parent);
		$posts = get_posts($args);
		if (empty($posts) || is_wp_error($posts)) {
			return false;
		}
		return $posts;
	}

	public static function get_taxonomy_posts_args($post = null, $args = array(), $parent = null) {
		if (!is_object($post) || !is_object($parent)) {
			return $args;
		}
		$defaults = array(
			'tax_query' => array(
				array(
					'taxonomy' => $parent->post_name,
					'field' => 'slug',
					'terms' => $post->post_name,
				)
			),
			'exclude' => array($post->ID),
			'post_type' => 'any',
			'numberposts' => -1
		);
		$args = wp_parse_args($args, $defaults);
		return $args;
	}

	public static function can_append_posts($posts_array = array()) {
		if (empty(self::$registered_taxonomies)) {
			return false;
		}
		if (count($posts_array) !== 1) {
			return false;
		}
		$plugin = new self(self::$plugin_basename, self::$prefix, false);
		if ($result = $plugin->post_is_taxonomy_child_active($posts_array[0])) {
			if ($result === 'exclude') {
				return false;
			}
			if (!isset(self::$registered_taxonomies[$result->post_name])) {
				return false;
			}
			if (!isset(self::$registered_taxonomies[$result->post_name]['append_posts'])) {
				return false;
			}
			if (empty(self::$registered_taxonomies[$result->post_name]['append_posts'])) {
				return false;
			}
			return $result;
		}
		return false;
	}

	/* install */

	public function delete_postmeta_terms_uninstall() {
		$post_types = get_post_types(array('public' => true, 'hierarchical' => true), 'objects');
		foreach ($post_types as $key => $value) {
			$args = array(
				'post_type' => $key,
				'sort_column' => 'menu_order',
			);
			$children = get_pages($args);
			if (!empty($children)) {
				foreach ($children as $child) {
					$this->delete_term($child);
				}
			}
		}
	}

}
endif;
?>