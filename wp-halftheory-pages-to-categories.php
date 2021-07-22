<?php
/*
Plugin Name: Half/theory Pages to Categories
Plugin URI: https://github.com/halftheory/wp-halftheory-pages-to-categories
GitHub Plugin URI: https://github.com/halftheory/wp-halftheory-pages-to-categories
Description: Half/theory Pages to Categories Plugin.
Author: Half/theory
Author URI: https://github.com/halftheory
Version: 2.0
Network: false
*/

/*
Available filters:
pagestocategories_template
pagestocategories_get_link_terms
pagestocategories_the_posts_pagination_args
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if ( ! class_exists('Halftheory_Helper_Plugin', false) && is_readable(dirname(__FILE__) . '/class-halftheory-helper-plugin.php') ) {
	include_once dirname(__FILE__) . '/class-halftheory-helper-plugin.php';
}

if ( ! class_exists('Halftheory_Pages_To_Categories', false) && class_exists('Halftheory_Helper_Plugin', false) ) :
	final class Halftheory_Pages_To_Categories extends Halftheory_Helper_Plugin {

        protected static $instance;
        public static $prefix;
        public static $active = false;
        public $registered_taxonomies = array();

        /* setup */

        protected function setup_globals( $plugin_basename = null, $prefix = null ) {
            parent::setup_globals($plugin_basename, $prefix);

            self::$active = $this->get_options_context('db', 'active');
            $this->plugin_description = __('Created by plugin: ') . $this->plugin_title;
            $this->options_posts = static::$prefix . '_posts';
            $this->postmeta_term_id = static::$prefix . '_term_id';
        }

        protected function setup_actions() {
            parent::setup_actions();

            // Stop if not active.
            if ( empty(self::$active) ) {
                return;
            }

            // init - must be before register_post_type!
            add_action('init', array( $this, 'init_register_taxonomy' ), 20);
            add_filter('term_link', array( $this, 'term_link' ), 20, 3);
            add_filter('get_terms', array( $this, 'get_terms' ), 20, 4);

            if ( $this->is_front_end() ) {
                // public.
                add_filter('wp_get_object_terms_args', array( $this, 'wp_get_object_terms_args' ), 20, 3);
                // the_content - after shortcodes (priority 11).
                add_filter('the_content', array( $this, 'the_content' ), 20);
                add_action('loop_end', array( $this, 'loop_end' ), 20);
                add_filter('term_description_rss', array( $this, 'term_description_rss' ), 20, 2);
                add_filter('term_description', array( $this, 'term_description' ), 20, 4);
            } else {
                // admin.
                $hierarchical_post_types = $this->get_options_context('db', 'hierarchical_post_types');
                if ( ! empty($hierarchical_post_types) ) {
                    foreach ( $hierarchical_post_types as $value ) {
                        add_action('add_meta_boxes_' . $value, array( $this, 'hierarchical_post_type_metaboxes' ));
                        add_action('save_post_' . $value, array( $this, 'hierarchical_post_type_save_post' ), 20, 3);
                    }
                    add_action('after_delete_post', array( $this, 'after_delete_post' ), 20);
                    add_action('admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 20);
                    add_filter('post_class', array( $this, 'post_class' ), 20, 3);
                }
            }
        }

        public static function plugin_uninstall() {
            static::$instance->delete_postmeta_terms_uninstall();
            static::$instance->delete_option_uninstall();
            parent::plugin_uninstall();
        }

        public function delete_postmeta_terms_uninstall() {
            $post_types = get_post_types(array('public' => true, 'hierarchical' => true), 'names');
            foreach ( $post_types as $key => $value ) {
                $args = array(
                    'post_type' => $key,
                    'sort_column' => 'menu_order',
                );
                $children = get_pages($args);
                if ( ! empty($children) ) {
                    foreach ( $children as $child ) {
                        $this->delete_term($child);
                    }
                }
            }
        }

        /* actions */

        public function init_register_taxonomy() {
            $hierarchical_post_types = $this->get_options_context('db', 'hierarchical_post_types');
            if ( empty($hierarchical_post_types) ) {
                return;
            }
            $taxonomy_object_types = $this->get_options_context('db', 'taxonomy_object_types');
            if ( empty($taxonomy_object_types) ) {
                return;
            }
            $options_posts = $this->get_options_posts();
            foreach ( $options_posts as $post_id => $arr ) {
                $post = get_post($post_id);
                if ( empty($post) ) {
                    continue;
                }
                if ( ! $this->post_is_taxonomy($post) ) {
                    continue;
                }
                if ( ! in_array($post->post_type, $hierarchical_post_types, true) ) {
                    continue;
                }
                $key = $post->post_name;
                if ( taxonomy_exists($key) ) {
                    continue;
                }
                if ( in_array($key, $this->wp_reserved_terms(), true) ) {
                    continue;
                }
                $post_title = get_post_field('post_title', $post, 'raw');
                if ( ! isset($arr['singular_name']) ) {
                    $arr['singular_name'] = $post_title;
                }
                if ( empty($arr['singular_name']) ) {
                    $arr['singular_name'] = $post_title;
                }
                if ( ! isset($arr['plural_name']) ) {
                    $arr['plural_name'] = $post_title;
                }
                if ( empty($arr['plural_name']) ) {
                    $arr['plural_name'] = $post_title;
                }
                $labels = array(
                    'name' => $post_title,
                    'singular_name' => $arr['singular_name'],
                    'search_items' => 'Search ' . $arr['plural_name'],
                    'all_items' => 'All ' . $arr['plural_name'],
                    'parent_item' => 'Parent ' . $arr['singular_name'],
                    'parent_item_colon' => 'Parent ' . $arr['singular_name'] . ':',
                    'edit_item' => 'Edit ' . $arr['singular_name'],
                    'update_item' => 'Update ' . $arr['singular_name'],
                    'add_new_item' => 'Add New ' . $arr['singular_name'],
                    'new_item_name' => 'New ' . $arr['singular_name'],
                    'menu_name' => $arr['plural_name'],
                    'no_terms' => 'No ' . strtolower($arr['plural_name']),
                );
                $query_var = false;
                $rewrite = false;
                if ( isset($arr['links']) ) {
                    if ( $arr['links'] === 'query_var' ) {
                        $query_var = $key;
                    } elseif ( $arr['links'] === 'rewrite' ) {
                        $query_var = $key;
                        $rewrite = array(
                            'hierarchical' => true,
                            'feed' => false,
                            'ep_mask' => EP_CATEGORIES,
                        );
                    } elseif ( $arr['links'] === 'term_link' ) {
                        $query_var = $key;
                    }
                }
                $res = register_taxonomy(
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
                        'update_count_callback' => '_update_post_term_count',
                    )
                );
                if ( ! is_wp_error($res) ) {
                    $arr['parent'] = $post;
                    $this->registered_taxonomies[ $key ] = $arr;
                }
            }
        }

        public function term_link( $termlink, $term, $taxonomy ) {
            if ( empty($this->registered_taxonomies) ) {
                return $termlink;
            }
            if ( ! $this->is_front_end() && ! headers_sent() ) {
                return $termlink;
            }
            if ( ! isset($this->registered_taxonomies[ $taxonomy ]) ) {
                return $termlink;
            }
            if ( ! isset($this->registered_taxonomies[ $taxonomy ]['links']) ) {
                return $termlink;
            }
            if ( $this->registered_taxonomies[ $taxonomy ]['links'] !== 'term_link' ) {
                return $termlink;
            }
            if ( $post = $this->get_post_from_term_id($term->term_id, $taxonomy) ) {
                $termlink = get_permalink($post);
            }
            return $termlink;
        }

        public function get_terms( $terms = array(), $taxonomy = array(), $query_vars = array(), $term_query = null ) {
            if ( empty($this->registered_taxonomies) ) {
                return $terms;
            }
            if ( ! $this->is_front_end() && wp_doing_ajax() ) {
                return $terms;
            }
            if ( count($terms) < 2 ) {
                return $terms;
            } elseif ( ! is_object(current($terms)) ) {
                return $terms;
            } elseif ( empty($taxonomy) ) {
                return $terms;
            } elseif ( count($taxonomy) > 1 ) {
                return $terms;
            }
            $tax = current($taxonomy);
            if ( ! isset($this->registered_taxonomies[ $tax ]) ) {
                return $terms;
            }
            if ( ! isset($this->registered_taxonomies[ $tax ]['order']) ) {
                return $terms;
            }
            if ( $this->registered_taxonomies[ $tax ]['order'] === 'post_title' ) {
                return $terms;
            } elseif ( $this->registered_taxonomies[ $tax ]['order'] === 'menu_order' ) {
                // get correct order of post_ids and related term_ids.
                if ( $children = $this->get_taxonomy_children_posts($this->registered_taxonomies[ $tax ]['parent'], $this->registered_taxonomies[ $tax ]['order']) ) {
                    $terms_old = $terms;
                    $terms = array();
                    foreach ( $children as $child ) {
                        $term_id = $this->get_postmeta($child->ID, $this->postmeta_term_id);
                        if ( empty($term_id) ) {
                            continue;
                        }
                        foreach ( $terms_old as $key => $value ) {
                            if ( $value->term_id === (int) $term_id ) {
                                $terms[] = $value;
                                unset($terms_old[ $key ]);
                                break;
                            }
                        }
                    }
                    // add any leftover terms to end of array.
                    if ( ! empty($terms_old) ) {
                        $terms = array_merge($terms, $terms_old);
                    }
                }
            }
            return $terms;
        }

        public function wp_get_object_terms_args( $args = array(), $object_ids = array(), $taxonomies = array() ) {
            if ( empty($this->registered_taxonomies) ) {
                return $args;
            }
            // some calls to get_the_taxonomies > wp_get_object_terms/get_object_term_cache > end up here.
            global $wp_taxonomies;
            $tax_total = 0;
            $order_arr = array();
            foreach ( $taxonomies as $taxonomy ) {
                if ( ! isset($wp_taxonomies[ $taxonomy ]) ) {
                    continue;
                }
                if ( isset($wp_taxonomies[ $taxonomy ]->_builtin) ) {
                    if ( $wp_taxonomies[ $taxonomy ]->_builtin ) {
                        continue;
                    }
                }
                if ( isset($this->registered_taxonomies[ $taxonomy ]) ) {
                    if ( isset($this->registered_taxonomies[ $taxonomy ]['order']) ) {
                        if ( ! empty($this->registered_taxonomies[ $taxonomy ]['order']) ) {
                            $order_arr[] = $this->registered_taxonomies[ $taxonomy ]['order'];
                        }
                    }
                }
                $tax_total++;
            }
            // only change args if 50% of taxonomies belong to plugin.
            if ( (float) count($order_arr) < ( $tax_total * 0.5 ) ) {
                return $args;
            }
            $order_arr = array_count_values($order_arr);
            $order = key($order_arr);
            if ( $order === 'post_title' ) {
                $args['orderby'] = 'name';
                $args['order'] = 'ASC';
            } elseif ( $order === 'menu_order' ) {
                $args['orderby'] = 'parent';
                $args['order'] = 'ASC';
                $args['update_term_meta_cache'] = false;
            }
            return $args;
        }

        public function the_content( $str = '' ) {
            if ( ! $this->the_content_conditions($str) ) {
                return $str;
            }
            $links = $this->get_link_terms();
            if ( ! empty($links) ) {
                $args = array(
                    'limit' => 1,
                );
                $str = $this->link_terms($str, $links, $args);
            }
            return $str;
        }

        public function loop_end( $wp_query ) {
            if ( ! is_main_query() ) {
                return;
            }
            if ( ! in_the_loop() ) {
                return;
            }
            if ( ! is_singular() ) {
                return;
            }
            if ( ! $wp_query->in_the_loop ) {
                return;
            }
            if ( ! $wp_query->is_singular ) {
                return;
            }
            if ( $parent = $this->can_append_posts($wp_query->posts) ) {
                $taxonomy_object_types = $this->get_options_context('db', 'taxonomy_object_types');
                if ( empty($taxonomy_object_types) ) {
                    $taxonomy_object_types = 'any';
                }
                $args = array(
                    'post_type' => $taxonomy_object_types,
                );
                $args = $this->get_taxonomy_posts_args($wp_query->posts[0], $args, $parent);
                // Save original posts.
                global $posts;
                $original_posts = $posts;
                // Query posts.
                $posts = query_posts($args);
                if ( empty($posts) ) {
                    $posts = $original_posts;
                    wp_reset_query();
                    return;
                }
                // remove this filter to prevent infinite looping.
                remove_action(current_action(), array( $this, __FUNCTION__ ), 20);
                // Start the loop.
                while ( have_posts() ) {
                    the_post();
                    global $post;
                    $template = apply_filters('pagestocategories_template', false, $post, $args);
                    if ( empty($template) ) {
                        $template = $this->get_template();
                    }
                    if ( $template ) {
                        load_template($template, false);
                    } else {
                        load_template(get_stylesheet_directory() . '/index.php', false);
                    }
                }
                // End the loop.
                if ( isset($this->registered_taxonomies[ $parent->post_name ]['posts_pagination']) ) {
                    if ( ! empty($this->registered_taxonomies[ $parent->post_name ]['posts_pagination']) ) {
                        // Previous/next page navigation.
                        $args = array(
                            'prev_text'          => __('Previous'),
                            'next_text'          => __('Next'),
                            'before_page_number' => '<span class="meta-nav screen-reader-text">' . __('Page') . '</span>',
                        );
                        the_posts_pagination( apply_filters('pagestocategories_the_posts_pagination_args', $args) );
                    }
                }
                $posts = $original_posts;
                wp_reset_query();
            }
        }

        public function term_description_rss( $value, $taxonomy ) {
            $str = trim(strip_tags($value));
            if ( strpos($str, $this->plugin_description) === 0 ) {
                return '';
            }
            return $value;
        }

        public function term_description( $value, $term_id, $taxonomy, $context ) {
            $str = trim(strip_tags($value));
            if ( strpos($str, $this->plugin_description) === 0 ) {
                return '';
            }
            return $value;
        }

        /* admin */

        public function menu_page() {
            $plugin = static::$instance;

            global $title;
            ?>
            <div class="wrap">
            <h2><?php echo $title; ?></h2>

            <?php
            if ( $plugin->save_menu_page(__FUNCTION__, 'save') ) {
                $save = function () use ( $plugin ) {
                    // get values.
                    $options = array();
                    foreach ( array_keys($plugin->get_options_context('default')) as $value ) {
                        $name = $plugin::$prefix . '_' . $value;
                        if ( ! isset($_POST[ $name ]) ) {
                            continue;
                        }
                        if ( $plugin->empty_notzero($_POST[ $name ]) ) {
                            continue;
                        }
                        $options[ $value ] = $_POST[ $name ];
                    }
                    // save it.
                    $updated = '<div class="updated"><p><strong>' . esc_html__('Options saved.') . '</strong></p></div>';
                    $error = '<div class="error"><p><strong>' . esc_html__('Error: There was a problem.') . '</strong></p></div>';
                    if ( ! empty($options) ) {
                        $options = $plugin->get_options_context('input', null, array(), $options);
                        if ( $plugin->update_option($plugin::$prefix, $options) ) {
                            echo $updated;
                        } else {
                            echo $error;
                        }
                    } else {
                        if ( $plugin->delete_option($plugin::$prefix) ) {
                            echo $updated;
                        } else {
                            echo $updated;
                        }
                    }
                };
                $save();
            } elseif ( $plugin->save_menu_page(__FUNCTION__, 'save_posts_clean') ) {
                $save = function () use ( $plugin ) {
                    // get values.
                    $name = $plugin::$prefix . '_save_posts_clean';
                    if ( ! isset($_POST[ $name ]) ) {
                        return;
                    }
                    if ( empty($_POST[ $name ]) ) {
                        return;
                    }
                    $remove = array_map('absint', $plugin->make_array($_POST[ $name ]));
                    $options_posts = $plugin->get_options_posts();
                    foreach ( $remove as $post_id ) {
                        if ( isset($options_posts[ $post_id ]) ) {
                            unset($options_posts[ $post_id ]);
                        }
                    }
                    if ( empty($options_posts) ) {
                        $plugin->delete_option($plugin->options_posts);
                    } else {
                        $plugin->update_option($plugin->options_posts, $options_posts);
                    }
                    echo '<div class="updated"><p><strong>' . esc_html__('Data was cleaned.') . '</strong></p></div>';
                };
                $save();
            }

            // Show the form.
            $options = $plugin->get_options_context('admin_form');
            ?>

            <form id="<?php echo esc_attr($plugin::$prefix); ?>-admin-form" name="<?php echo esc_attr($plugin::$prefix); ?>-admin-form" method="post" action="<?php echo esc_attr($_SERVER['REQUEST_URI']); ?>">
            <?php
            // Use nonce for verification.
            wp_nonce_field($plugin->plugin_basename, $plugin->plugin_name . '::' . __FUNCTION__);
            ?>
            <div id="poststuff">

            <p><label for="<?php echo esc_attr($plugin::$prefix); ?>_active"><input type="checkbox" id="<?php echo esc_attr($plugin::$prefix); ?>_active" name="<?php echo esc_attr($plugin::$prefix); ?>_active" value="1"<?php checked($options['active'], true); ?> /> <?php echo esc_html($plugin->plugin_title); ?> <?php esc_html_e('active?'); ?></label></p>

            <div class="postbox">
                <div class="inside">
                    <h4><?php esc_html_e('Hierarchical Post Types'); ?></h4>
                    <p><span class="description"><?php esc_html_e('The following post types can be converted to taxonomies.'); ?></span></p>
                    <?php
                    $post_types = array();
                    $arr = get_post_types(array('public' => true, 'hierarchical' => true), 'objects');
                    foreach ( $arr as $key => $value ) {
                        $post_types[ $key ] = $value->label;
                    }
                    foreach ( $post_types as $key => $value ) {
                        echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="' . esc_attr($plugin::$prefix) . '_hierarchical_post_types[]" value="' . esc_attr($key) . '"';
                        if ( in_array($key, $options['hierarchical_post_types'], true) ) {
                            checked($key, $key);
                        }
                        echo '> ' . esc_html($value) . '</label>';
                    }
                    ?>
                </div>
            </div>

            <div class="postbox">
                <div class="inside">
                    <h4><?php esc_html_e('Allowed Post Types'); ?></h4>
                    <p><span class="description"><?php esc_html_e('The above taxonomies will be available to the following post types.'); ?></span></p>
                    <?php
                    $post_types = array();
                    $arr = get_post_types(array( 'public' => true ), 'objects');
                    foreach ( $arr as $key => $value ) {
                        $post_types[ $key ] = $value->label;
                    }
                    foreach ( $post_types as $key => $value ) {
                        echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="' . esc_attr($plugin::$prefix) . '_taxonomy_object_types[]" value="' . esc_attr($key) . '"';
                        if ( in_array($key, $options['taxonomy_object_types'], true) ) {
                            checked($key, $key);
                        }
                        echo '> ' . esc_html($value) . '</label>';
                    }
                    ?>
                </div>
            </div>

            <?php submit_button(__('Update'), array( 'primary', 'large' ), 'save'); ?>

            <?php
            // clean posts data?
            $dirty = array();
            $options_posts = $plugin->get_options_posts();
            foreach ( $options_posts as $post_id => $arr ) {
                $p = get_post($post_id);
                if ( empty($p) ) {
                    $dirty[ $post_id ] = 'ID ' . $post_id;
                    continue;
                }
                if ( ! isset($arr['active']) ) {
                    $dirty[ $post_id ] = '<a href="' . esc_url(get_permalink($p)) . '">' . get_the_title($p) . '</a>';
                    continue;
                }
                if ( empty($arr['active']) ) {
                    $dirty[ $post_id ] = '<a href="' . esc_url(get_permalink($p)) . '">' . get_the_title($p) . '</a>';
                    continue;
                }
            }
            if ( ! empty($dirty) ) :
                ?>
            <div class="postbox">
                <div class="inside">
                    <h4><?php esc_html_e('Remove Inactive Post Data'); ?></h4>
                    <p><span class="description"><?php esc_html_e('The following posts have data, however they are inactive.'); ?></span></p>
                    <ul>
                    <?php
                    ksort($dirty);
                    foreach ( $dirty as $post_id => $value ) {
                        echo '<li><input type="checkbox" name="' . esc_attr($plugin::$prefix) . '_save_posts_clean[]" value="' . esc_attr($post_id) . '" /> ' . esc_html($value) . '</li>' . "\n";
                    }
                    ?>
                    </ul>
                </div>
            </div>
                <?php submit_button(__('Clean'), array( 'primary', 'large' ), 'save_posts_clean'); ?>
            <?php endif; ?>

            </div><!-- poststuff -->
            </form>

            </div><!-- wrap -->
            <?php
        }

        public function hierarchical_post_type_metaboxes( $post ) {
            if ( empty($post) ) {
                return;
            }
            // not on new posts.
            if ( $post->post_status === 'auto-draft' || empty($post->post_name) ) {
                return;
            }
            if ( $parent = $this->post_is_taxonomy_child($post) ) {
                add_meta_box(
                    $this->options_posts,
                    $this->plugin_title,
                    array( $this, 'hierarchical_post_type_metaboxes_child' ),
                    $post->post_type,
                    // side, normal.
                    'normal',
                    'low',
                    array( 'parent' => $parent )
                );
            } else {
                add_meta_box(
                    $this->options_posts,
                    $this->plugin_title,
                    array( $this, 'hierarchical_post_type_metaboxes_parent' ),
                    $post->post_type,
                    // side, normal.
                    'normal',
                    'low',
                    null
                );
            }
        }

        public function hierarchical_post_type_metaboxes_child( $post, $field = array() ) {
            if ( empty($field) ) {
                return;
            }
            if ( ! isset($field['args']['parent']) ) {
                return;
            }
            $parent = get_post($field['args']['parent']);
            if ( empty($parent) ) {
                return;
            }

            $arr = array_merge( $this->get_options_post_array(), $this->get_options_posts($parent->ID) );
            ?>
            <label class="screen-reader-text" for="<?php echo esc_attr($field['id']); ?>"><?php echo $field['title']; ?></label>
            <input type="hidden" id="<?php echo esc_attr($field['id']); ?>_parent_id" name="<?php echo esc_attr($field['id']); ?>_parent_id" value="<?php echo esc_attr($parent->ID); ?>" />

            <p>
            <?php
            esc_html_e('Parent:');
            edit_post_link(
                get_the_title($parent),
                ' <span class="edit-link">',
                '</span>',
                $parent
            );
            ?>
            </p>

            <p><label for="<?php echo esc_attr($field['id']); ?>_exclude"><input type="checkbox" id="<?php echo esc_attr($field['id']); ?>_exclude" name="<?php echo esc_attr($field['id']); ?>_exclude" value="<?php echo esc_attr($post->ID); ?>"<?php checked($this->in_array_int($post->ID, $arr['exclude']), 1); ?> /> <?php esc_html_e('Exclude from parent taxonomy.'); ?></label></p>
            <?php
            if ( isset($arr['append_posts']) ) {
                if ( ! empty($arr['append_posts']) ) {
                    ?>
            <p><label for="<?php echo esc_attr($field['id']); ?>_exclude_append_posts"><input type="checkbox" id="<?php echo esc_attr($field['id']); ?>_exclude_append_posts" name="<?php echo esc_attr($field['id']); ?>_exclude_append_posts" value="<?php echo esc_attr($post->ID); ?>"<?php checked($this->in_array_int($post->ID, $arr['exclude_append_posts']), 1); ?> /> <?php esc_html_e('Don\'t append taxonomy posts to page.'); ?></label></p>
                    <?php
                }
            }
            if ( isset($arr['link_terms']) ) {
                if ( ! empty($arr['link_terms']) ) {
                    ?>
            <p><label for="<?php echo esc_attr($field['id']); ?>_exclude_link_terms"><input type="checkbox" id="<?php echo esc_attr($field['id']); ?>_exclude_link_terms" name="<?php echo esc_attr($field['id']); ?>_exclude_link_terms" value="<?php echo esc_attr($post->ID); ?>"<?php checked($this->in_array_int($post->ID, $arr['exclude_link_terms']), 1); ?> /> <?php esc_html_e('Don\'t add links to this term in post content.'); ?></label></p>
                    <?php
                }
            }
        }

        public function hierarchical_post_type_metaboxes_parent( $post, $field = array() ) {
            if ( empty($field) ) {
                return;
            }
            $arr = array_merge( $this->get_options_post_array(), $this->get_options_posts($post->ID) );
            ?>
            <label class="screen-reader-text" for="<?php echo esc_attr($field['id']); ?>"><?php echo $field['title']; ?></label>

            <p><label for="<?php echo esc_attr($field['id']); ?>_active"><input type="checkbox" id="<?php echo esc_attr($field['id']); ?>_active" name="<?php echo esc_attr($field['id']); ?>_active" value="1"<?php checked($arr['active'], 1); ?> />
            <?php
            global $wp_post_types;
            echo $wp_post_types[ $post->post_type ]->labels->singular_name;
            esc_html_e(' is taxonomy?');
            ?>
            </label></p>

            <p><label for="<?php echo esc_attr($field['id']); ?>_include_children"><input type="checkbox" id="<?php echo esc_attr($field['id']); ?>_include_children" name="<?php echo esc_attr($field['id']); ?>_include_children" value="1"<?php checked($arr['include_children'], 1); ?> /> <?php esc_html_e('Include children?'); ?></label></p>

            <p><label for="<?php echo esc_attr($field['id']); ?>_order"><span style="min-width: 10em; display: inline-block;"><?php esc_html_e('Order by'); ?></span> <select name="<?php echo esc_attr($field['id']); ?>_order" id="<?php echo esc_attr($field['id']); ?>_order" style="min-width: 10em; width: auto;">
            <?php
            $select_arr = array('menu_order' => __('Page Order'), 'post_title' => __('Alphabetical'));
            foreach ( $select_arr as $key => $value ) {
                echo '<option value="' . esc_attr($key) . '"' . selected($arr['order'], $key, false) . '>' . esc_html($value) . '</option>' . "\n";
            }
            ?>
            </select></label></p>

            <p><label for="<?php echo esc_attr($field['id']); ?>_links"><span style="min-width: 10em; display: inline-block;"><?php esc_html_e('Rewrite Links'); ?></span> <select name="<?php echo esc_attr($field['id']); ?>_links" id="<?php echo esc_attr($field['id']); ?>_links" style="min-width: 10em; width: auto;">
            <?php
            $select_arr = array(
                '' => __('none: ?taxonomy=parent&term=child'),
                'query_var' => __('query_var: ?parent=child'),
                'rewrite' => __('rewrite: taxonomy overwrites post (resave Permalinks)'),
                'term_link' => __('term_link: post overwrites taxonomy'),
            );
            foreach ( $select_arr as $key => $value ) {
                echo '<option value="' . esc_attr($key) . '"' . selected($arr['links'], $key, false) . '>' . esc_html($value) . '</option>' . "\n";
            }
            ?>
            </select></label></p>

            <p><label for="<?php echo esc_attr($field['id']); ?>_append_posts"><input type="checkbox" id="<?php echo esc_attr($field['id']); ?>_append_posts" name="<?php echo esc_attr($field['id']); ?>_append_posts" value="1"<?php checked($arr['append_posts'], 1); ?> /> <?php esc_html_e('Append taxonomy posts to page?'); ?></label></p>

            <p><label for="<?php echo esc_attr($field['id']); ?>_posts_pagination"><input type="checkbox" id="<?php echo esc_attr($field['id']); ?>_posts_pagination" name="<?php echo esc_attr($field['id']); ?>_posts_pagination" value="1"<?php checked($arr['posts_pagination'], 1); ?> /> <?php esc_html_e('Posts use pagination?'); ?></label></p>

            <p><label for="<?php echo esc_attr($field['id']); ?>_link_terms"><input type="checkbox" id="<?php echo esc_attr($field['id']); ?>_link_terms" name="<?php echo esc_attr($field['id']); ?>_link_terms" value="1"<?php checked($arr['link_terms'], 1); ?> /> <?php esc_html_e('Add links to terms in post content?'); ?></label></p>

            <p><label for="<?php echo esc_attr($field['id']); ?>_singular_name"><span style="min-width: 10em; display: inline-block;"><?php esc_html_e('Singular name'); ?></span> <input type="text" name="<?php echo esc_attr($field['id']); ?>_singular_name" id="<?php echo esc_attr($field['id']); ?>_singular_name" style="min-width: 10em; width: auto;" value="<?php echo esc_attr($arr['singular_name']); ?>" /></label></p>

            <p><label for="<?php echo esc_attr($field['id']); ?>_plural_name"><span style="min-width: 10em; display: inline-block;"><?php esc_html_e('Plural name'); ?></span> <input type="text" name="<?php echo esc_attr($field['id']); ?>_plural_name" id="<?php echo esc_attr($field['id']); ?>_plural_name" style="min-width: 10em; width: auto;" value="<?php echo esc_attr($arr['plural_name']); ?>" /></label></p>
            <?php
        }

        public function hierarchical_post_type_save_post( $post_id, $post, $update = false ) {
            if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
                return;
            }
            if ( empty($update) ) {
                return;
            }
            // update options, only on Edit>Post page.
            if ( isset($_POST) ) {
                if ( isset($_POST['_wpnonce']) ) {
                    if ( wp_verify_nonce($_POST['_wpnonce'], 'update-post_' . $post_id) ) {
                        if ( isset($_POST[ $this->options_posts . '_parent_id' ]) ) {
                            $this->hierarchical_post_type_save_post_child($post_id, $post, (int) $_POST[ $this->options_posts . '_parent_id' ]);
                        } else {
                            $this->hierarchical_post_type_save_post_parent($post_id, $post);
                        }
                    }
                }
            }
            // update terms.
            if ( $parent = $this->post_is_taxonomy_child($post) ) {
                $this->save_post_update_terms_child($post, $parent);
            } elseif ( $this->post_is_taxonomy($post) ) {
                $this->save_post_update_terms_parent($post);
            }
        }

        private function hierarchical_post_type_save_post_child( $post_id, $post, $parent_id = 0 ) {
            if ( empty($parent_id) ) {
                return;
            }
            $child_keys = $this->get_options_post_int_keys();
            $values = array();
            foreach ( $child_keys as $key ) {
                $values[ $key ] = isset($_POST[ $this->options_posts . '_' . $key ]) ? absint($_POST[ $this->options_posts . '_' . $key ]) : false;
            }
            $options_posts = $this->get_options_posts();
            if ( ! isset($options_posts[ $parent_id ]) && empty(array_filter($values)) ) {
                return;
            } elseif ( ! isset($options_posts[ $parent_id ]) ) {
                $options_posts[ $parent_id ] = $this->get_options_post_array();
            }

            foreach ( $values as $key => $value ) {
                // set the array.
                if ( ! isset($options_posts[ $parent_id ][ $key ]) ) {
                    $options_posts[ $parent_id ][ $key ] = array();
                } else {
                    $options_posts[ $parent_id ][ $key ] = $this->make_array($options_posts[ $parent_id ][ $key ]);
                    $options_posts[ $parent_id ][ $key ] = array_map('absint', $options_posts[ $parent_id ][ $key ]);
                }
                // handle the value.
                if ( empty($options_posts[ $parent_id ][ $key ]) && $value === false ) {
                    continue;
                } elseif ( empty($options_posts[ $parent_id ][ $key ]) && $value ) {
                    $options_posts[ $parent_id ][ $key ] = array( $value );
                } elseif ( ! empty($options_posts[ $parent_id ][ $key ]) && $value === false ) {
                    if ( $this->in_array_int($post_id, $options_posts[ $parent_id ][ $key ]) ) {
                        $options_posts[ $parent_id ][ $key ] = array_diff($options_posts[ $parent_id ][ $key ], array( $post_id ));
                    }
                } elseif ( ! empty($options_posts[ $parent_id ][ $key ]) && $value ) {
                    if ( ! $this->in_array_int($value, $options_posts[ $parent_id ][ $key ]) ) {
                        $options_posts[ $parent_id ][ $key ][] = $value;
                    }
                }
                sort($options_posts[ $parent_id ][ $key ]);
            }
            // remove old parent - can't be child and parent.
            if ( isset($options_posts[ $post_id ]) ) {
                unset($options_posts[ $post_id ]);
            }
            if ( empty($options_posts) ) {
                $this->delete_option($this->options_posts);
            } else {
                $this->update_option($this->options_posts, $options_posts);
            }
        }

        private function hierarchical_post_type_save_post_parent( $post_id, $post ) {
            $defaults = $values = $this->get_options_post_array();
            foreach ( $defaults as $key => $value ) {
                if ( isset($_POST[ $this->options_posts . '_' . $key ]) ) {
                    $values[ $key ] = $_POST[ $this->options_posts . '_' . $key ];
                } elseif ( is_bool($value) ) {
                    $values[ $key ] = false;
                }
            }
            ksort($defaults);
            ksort($values);
            $options_posts = $this->get_options_posts();
            if ( ! isset($options_posts[ $post_id ]) ) {
                // new.
                if ( empty($values['active']) ) {
                    // no need to add.
                    return;
                }
                if ( $defaults === $values ) {
                    // no changes.
                    return;
                }
                $options_posts[ $post_id ] = $values;
            } elseif ( isset($options_posts[ $post_id ]) ) {
                // update.
                // 'exclude' - check and retain values.
                $child_keys = $this->get_options_post_int_keys();
                foreach ( $child_keys as $key ) {
                    if ( isset($options_posts[ $post_id ][ $key ]) ) {
                        $values[ $key ] = array();
                        foreach ( $this->make_array($options_posts[ $post_id ][ $key ]) as $value ) {
                            $p = get_post($value);
                            if ( ! empty($p) ) {
                                $values[ $key ][] = absint($value);
                            }
                        }
                        sort($values[ $key ]);
                    }
                }
                ksort($values);
                ksort($options_posts[ $post_id ]);
                if ( $options_posts[ $post_id ] === $values ) {
                    // no changes.
                    return;
                }
                if ( $defaults === $values ) {
                    unset($options_posts[ $post_id ]);
                } else {
                    $options_posts[ $post_id ] = $values;
                }
            }
            if ( empty($options_posts) ) {
                $this->delete_option($this->options_posts);
            } else {
                $this->update_option($this->options_posts, $options_posts);
            }
        }

        private function save_post_update_terms_child( $post, $parent ) {
            if ( $result = $this->post_is_taxonomy_child_active($post) ) {
                $args = array(
                    'post_type' => $post->post_type,
                    'post_status' => array( 'publish', 'inherit' ),
                    'sort_column' => 'menu_order',
                    'child_of' => $post->ID,
                );
                $children = get_pages($args);
                if ( $result === 'exclude' ) {
                    $this->delete_term($post, $parent);
                    // children.
                    if ( ! empty($children) ) {
                        foreach ( $children as $child ) {
                            $this->delete_term($child, $parent);
                        }
                    }
                } else {
                    $this->update_term($post, $parent);
                    // children.
                    if ( ! empty($children) ) {
                        foreach ( $children as $child ) {
                            $this->update_term($child, $parent);
                        }
                    }
                }
            }
        }

        private function save_post_update_terms_parent( $post ) {
            // update.
            if ( $children = $this->get_taxonomy_children_posts($post) ) {
                // check taxonomy slug.
                foreach ( $children as $child ) {
                    $term_id = $this->get_postmeta($child->ID, $this->postmeta_term_id);
                    if ( ! empty($term_id) ) {
                        $term = get_term( (int) $term_id);
                        if ( ! empty($term) && ! is_wp_error($term) ) {
                            if ( in_array($term->taxonomy, $this->wp_reserved_terms(), true) ) {
                                break;
                            } elseif ( $term->taxonomy !== $post->post_name ) {
                                global $wpdb;
                                $wpdb->query("UPDATE $wpdb->term_taxonomy SET taxonomy = '" . $post->post_name . "' WHERE taxonomy = '" . $term->taxonomy . "'");
                            }
                        }
                    }
                    break;
                }
                // update terms.
                foreach ( $children as $child ) {
                    $this->update_term($child, $post);
                }
            }
            // delete exclude.
            $arr = $this->get_options_posts($post->ID);
            if ( isset($arr['exclude']) ) {
                if ( ! empty($arr['exclude']) ) {
                    foreach ( $arr['exclude'] as $value ) {
                        if ( $exclude = get_post($value) ) {
                            $this->delete_term($exclude, $post);
                            // children.
                            $args = array(
                                'post_type' => $post->post_type,
                                'post_status' => array( 'publish', 'inherit' ),
                                'sort_column' => 'menu_order',
                                'child_of' => $exclude->ID,
                            );
                            $exclude_children = get_pages($args);
                            if ( ! empty($exclude_children) ) {
                                foreach ( $exclude_children as $child ) {
                                    $this->delete_term($child, $post);
                                }
                            }
                        }
                    }
                }
            }
        }

        public function after_delete_post( $post_id ) {
            // child.
            $this->delete_term($post_id);
            // parent.
            $options_posts = $this->get_options_posts();
            if ( isset($options_posts[ $post_id ]) ) {
                unset($options_posts[ $post_id ]);
            }
            if ( empty($options_posts) ) {
                $this->delete_option($this->options_posts);
            } else {
                $this->update_option($this->options_posts, $options_posts);
            }
        }

        public function admin_enqueue_scripts() {
            if ( ! function_exists('get_current_screen') ) {
                return;
            }
            if ( ! is_object(get_current_screen()) ) {
                return;
            }
            if ( strpos(get_current_screen()->id, 'edit-') === false ) {
                return;
            }
            global $typenow;
            if ( ! is_post_type_hierarchical($typenow) ) {
                return;
            }
            wp_enqueue_style(static::$prefix, plugins_url('/assets/css/pages-to-categories-admin.css', __FILE__), array(), $this->get_plugin_version(), 'screen');
        }

        public function post_class( $classes = array(), $class = array(), $post_id = 0 ) {
            if ( ! function_exists('get_current_screen') ) {
                return $classes;
            }
            if ( strpos(get_current_screen()->id, 'edit-') === false ) {
                return $classes;
            }
            global $typenow;
            if ( ! is_post_type_hierarchical($typenow) ) {
                return $classes;
            }
            if ( $this->post_is_taxonomy(get_post($post_id)) ) {
                $classes[] = static::$prefix . '-parent';
            } elseif ( $result = $this->post_is_taxonomy_child_active(get_post($post_id)) ) {
                if ( $result === 'exclude' ) {
                    return $classes;
                }
                $classes[] = static::$prefix . '-child';
            }
            return $classes;
        }

        /* functions */

        protected function get_options_default() {
            return apply_filters(static::$prefix . '_options_default',
                array(
                    'active' => false,
                    'hierarchical_post_types' => array(),
                    'taxonomy_object_types' => array(),
                )
            );
        }

        private function get_options_post_array() {
            return array(
                'active' => false,
                'include_children' => true,
                'order' => 'menu_order',
                'links' => '',
                'append_posts' => true,
                'posts_pagination' => true,
                'link_terms' => true,
                'singular_name' => '',
                'plural_name' => '',
                'exclude' => array(),
                'exclude_append_posts' => array(),
                'exclude_link_terms' => array(),
            );
        }

        private function get_options_post_int_keys() {
            return array( 'exclude', 'exclude_append_posts', 'exclude_link_terms' );
        }

        private function get_options_posts( $key = null, $default = array() ) {
            $res = $this->get_option($this->options_posts, $key, $default);
            if ( empty($res) ) {
                return $res;
            }
            $int_keys = $this->get_options_post_int_keys();
            $func = function ( &$arr ) use ( &$func, $int_keys ) {
                if ( is_array($arr) ) {
                    foreach ( $arr as $key => $value ) {
                        if ( in_array($key, $int_keys, true) && is_array($value) ) {
                            $arr[ $key ] = array_map('absint', $value);
                        } elseif ( is_array($value) ) {
                            $func($value);
                        }
                    }
                }
            };
            $func($res);
            return $res;
        }

        private function post_is_taxonomy( $post ) {
            $arr = $this->get_options_posts($post->ID);
            if ( isset($arr['active']) ) {
                if ( ! empty($arr['active']) ) {
                    return true;
                }
            }
            return false;
        }
        private function post_is_taxonomy_child( $post ) {
            $ancestors = get_ancestors($post->ID, $post->post_type);
            foreach ( $ancestors as $value ) {
                if ( $parent = get_post($value) ) {
                    if ( $this->post_is_taxonomy($parent) ) {
                        // more checks.
                        $arr = $this->get_options_posts($parent->ID);
                        if ( isset($arr['include_children']) ) {
                            if ( ! empty($arr['include_children']) ) {
                                return $parent;
                            }
                        }
                    }
                }
            }
            return false;
        }
        private function post_is_taxonomy_child_active( $post ) {
            if ( $parent = $this->post_is_taxonomy_child($post) ) {
                // more checks.
                $arr = $this->get_options_posts($parent->ID);
                if ( isset($arr['exclude']) ) {
                    if ( $this->in_array_int($post->ID, $arr['exclude']) ) {
                        return 'exclude';
                    }
                }
                // could be child of exclude.
                if ( $children = $this->get_taxonomy_children_posts($parent) ) {
                    $children_ids = array();
                    foreach ( $children as $child ) {
                        $children_ids[] = (int) $child->ID;
                    }
                    if ( $this->in_array_int($post->ID, $children_ids) ) {
                        return $parent;
                    }
                }
                return 'exclude';
            }
            return false;
        }

        private function get_taxonomy_children_posts( $post, $sort_column = 'menu_order' ) {
            if ( ! $this->post_is_taxonomy($post) ) {
                return false;
            }
            $arr = $this->get_options_posts($post->ID);
            if ( ! isset($arr['include_children']) ) {
                return false;
            } elseif ( empty($arr['include_children']) ) {
                return false;
            }
            $args = array(
                'post_type' => $post->post_type,
                'post_status' => array( 'publish', 'inherit' ),
                'sort_column' => $sort_column,
                'child_of' => $post->ID,
            );
            if ( isset($arr['exclude']) ) {
                $args['exclude'] = $arr['exclude'];
            }
            $children = get_pages($args);
            if ( empty($children) ) {
                return false;
            }
            return $children;
        }

        public function update_term( $post, $parent ) {
            if ( in_array($parent->post_name, $this->wp_reserved_terms(), true) ) {
                return;
            }
            $term_parent = 0;
            if ( $post->post_parent !== $parent->ID ) {
                $term_parent = $this->get_postmeta($post->post_parent, $this->postmeta_term_id);
            }
            $args = array(
                'description' => $this->plugin_description,
                'parent' => (int) $term_parent,
                'slug' => $post->post_name,
            );
            $term_id = $this->get_postmeta($post->ID, $this->postmeta_term_id);
            if ( empty($term_id) ) {
                // new.
                $arr = wp_insert_term($post->post_title, $parent->post_name, $args);
                if ( ! empty($arr) && ! is_wp_error($arr) ) {
                    $this->update_postmeta($post->ID, $this->postmeta_term_id, $arr['term_id']);
                }
            } else {
                // update.
                $args['name'] = $post->post_title;
                $arr = wp_update_term( (int) $term_id, $parent->post_name, $args);
            }
        }
        public function delete_term( $post, $parent = null ) {
            $post_id = null;
            if ( is_numeric($post) ) {
                $post_id = (int) $post;
            } elseif ( is_object($post) ) {
                if ( isset($post->ID) ) {
                    $post_id = (int) $post->ID;
                }
            }
            if ( empty($post_id) ) {
                return;
            }
            $term_id = $this->get_postmeta($post_id, $this->postmeta_term_id);
            if ( ! empty($term_id) ) {
                $taxonomy = null;
                if ( empty($parent) ) {
                    $term = get_term( (int) $term_id);
                    if ( ! empty($term) && ! is_wp_error($term) ) {
                        $taxonomy = $term->taxonomy;
                    } else {
                        return;
                    }
                } else {
                    $taxonomy = $parent->post_name;
                }
                wp_delete_term( (int) $term_id, $taxonomy);
                $this->delete_postmeta($post_id, $this->postmeta_term_id);
            }
        }

        private function wp_reserved_terms() {
            return array( 'attachment', 'attachment_id', 'author', 'author_name', 'calendar', 'cat', 'category', 'category__and', 'category__in', 'category__not_in', 'category_name', 'comments_per_page', 'comments_popup', 'customize_messenger_channel', 'customized', 'cpage', 'day', 'debug', 'error', 'exact', 'feed', 'fields', 'hour', 'link_category', 'm', 'minute', 'monthnum', 'more', 'name', 'nav_menu', 'nonce', 'nopaging', 'offset', 'order', 'orderby', 'p', 'page', 'page_id', 'paged', 'pagename', 'pb', 'perm', 'post', 'post__in', 'post__not_in', 'post_format', 'post_mime_type', 'post_status', 'post_tag', 'post_type', 'posts', 'posts_per_archive_page', 'posts_per_page', 'preview', 'robots', 's', 'search', 'second', 'sentence', 'showposts', 'static', 'subpost', 'subpost_id', 'tag', 'tag__and', 'tag__in', 'tag__not_in', 'tag_id', 'tag_slug__and', 'tag_slug__in', 'taxonomy', 'tb', 'term', 'theme', 'type', 'w', 'withcomments', 'withoutcomments', 'year' );
        }

        public function get_taxonomy_posts( $post = null, $args = array(), $parent = null ) {
            if ( empty($this->registered_taxonomies) ) {
                return false;
            }
            if ( empty($post) ) {
                wp_reset_postdata();
                $post = get_post(get_the_ID());
            } elseif ( is_numeric($post) ) {
                $post = get_post($post);
            }
            if ( ! is_object($post) ) {
                return false;
            }
            if ( empty($parent) ) {
                if ( $result = $this->post_is_taxonomy_child_active($post) ) {
                    if ( $result === 'exclude' ) {
                        return false;
                    }
                    $parent = $result;
                } else {
                    return false;
                }
            }
            if ( ! isset($this->registered_taxonomies[ $parent->post_name ]) ) {
                return false;
            }
            $args = $this->get_taxonomy_posts_args($post, $args, $parent);
            $posts = get_posts($args);
            if ( empty($posts) || is_wp_error($posts) ) {
                return false;
            }
            return $posts;
        }

        public function get_taxonomy_posts_args( $post = null, $args = array(), $parent = null ) {
            if ( ! is_object($post) || ! is_object($parent) ) {
                return $args;
            }
            $defaults = array(
                'tax_query' => array(
                    array(
                        'taxonomy' => $parent->post_name,
                        'field' => 'slug',
                        'terms' => $post->post_name,
                    ),
                ),
                'exclude' => array( $post->ID ),
                'post_type' => 'any',
            );
            // posts_pagination.
            $pagination = false;
            if ( isset($this->registered_taxonomies[ $parent->post_name ]['posts_pagination']) ) {
                if ( ! empty($this->registered_taxonomies[ $parent->post_name ]['posts_pagination']) ) {
                    $pagination = true;
                    if ( is_paged() ) {
                        global $paged;
                        $defaults['paged'] = $paged;
                    }
                }
            }
            if ( ! $pagination ) {
                $arr = array(
                    // get_posts.
                    'numberposts' => -1,
                    // query_posts.
                    'nopaging' => true,
                    'posts_per_page' => -1,
                    'ignore_sticky_posts' => true,
                );
                $defaults = array_merge($defaults, $arr);
            }
            $args = wp_parse_args($args, $defaults);
            return $args;
        }

        public function can_append_posts( $posts_array = array() ) {
            if ( empty($this->registered_taxonomies) ) {
                return false;
            }
            if ( count($posts_array) !== 1 ) {
                return false;
            }
            if ( $result = $this->post_is_taxonomy_child_active($posts_array[0]) ) {
                if ( $result === 'exclude' ) {
                    return false;
                }
                if ( ! isset($this->registered_taxonomies[ $result->post_name ]) ) {
                    return false;
                }
                if ( ! isset($this->registered_taxonomies[ $result->post_name ]['append_posts']) ) {
                    return false;
                }
                if ( empty($this->registered_taxonomies[ $result->post_name ]['append_posts']) ) {
                    return false;
                }
                $exclude_append_posts = isset($this->registered_taxonomies[ $result->post_name ]['exclude_append_posts']) ? $this->registered_taxonomies[ $result->post_name ]['exclude_append_posts'] : array();
                if ( $this->in_array_int($posts_array[0]->ID, $exclude_append_posts) ) {
                    return false;
                }
                return $result;
            }
            return false;
        }

        public function get_post_from_term_id( $term_id = 0, $taxonomy = null ) {
            $args = array(
                'meta_query' => array(
                    array(
                        'key' => $this->postmeta_term_id,
                        'value' => $term_id,
                    ),
                ),
                'numberposts' => 1,
                'post_type' => 'any',
            );
            if ( ! empty($taxonomy) ) {
                if ( isset($this->registered_taxonomies[ $taxonomy ]) ) {
                    $args['post_type'] = $this->registered_taxonomies[ $taxonomy ]['parent']->post_type;
                }
            }
            $posts = get_posts($args);
            if ( empty($posts) || is_wp_error($posts) ) {
                return false;
            }
            return $posts[0];
        }

        public function get_term_id_from_post_id( $post_id = 0 ) {
            $term_id = $this->get_postmeta($post_id, $this->postmeta_term_id);
            if ( empty($term_id) ) {
                return false;
            }
            return (int) $term_id;
        }

        public function get_link_terms( $post_id = 0 ) {
            $links = array();
            $options_posts = $this->get_options_posts();
            if ( empty($options_posts) ) {
                return $links;
            }
            if ( empty($post_id) && ( is_singular() || in_the_loop() ) ) {
                $post_id = get_the_ID();
            }
            foreach ( $options_posts as $parent_id => $arr ) {
                if ( ! isset($arr['link_terms']) ) {
                    continue;
                }
                if ( empty($arr['link_terms']) ) {
                    continue;
                }
                $parent = get_post($parent_id);
                if ( empty($parent) ) {
                    continue;
                }
                if ( $children = $this->get_taxonomy_children_posts($parent) ) {
                    $exclude_link_terms = isset($arr['exclude_link_terms']) ? $arr['exclude_link_terms'] : array();
                    foreach ( $children as $child ) {
                        if ( $this->in_array_int($child->ID, $exclude_link_terms) ) {
                            continue;
                        }
                        if ( $child->ID === $post_id ) {
                            continue;
                        }
                        $term_id = $this->get_postmeta($child->ID, $this->postmeta_term_id);
                        if ( ! empty($term_id) && ! isset($links[ $child->post_title ]) ) {
                            $links[ $child->post_title ] = set_url_scheme(get_term_link( (int) $term_id));
                        }
                    }
                }
            }
            return apply_filters('pagestocategories_get_link_terms', $links, $post_id);
        }
	}

	// Load the plugin.
	Halftheory_Pages_To_Categories::get_instance(true, plugin_basename(__FILE__));
endif;
