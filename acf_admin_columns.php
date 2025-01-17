<?php
/**
 * Plugin Name: Admin Columns for ACF Fields
 * Plugin URI: https://wordpress.org/plugins/acf-admin-columns/
 * Description: Add columns for your ACF fields to post and taxonomy index pages in the WP backend.
 * Version: 0.3.2
 * Author: Florian Eickhorst
 * Author URI: http://www.fleimedia.com/
 * License: GPL
 */

class FleiACFAdminColumns
{

    const ACF_SETTING_NAME = 'admin_column';
    const ACF_SETTING_NAME_ENABLED = self::ACF_SETTING_NAME . '_enabled';
    const ACF_SETTING_NAME_POSITION = self::ACF_SETTING_NAME . '_position';
    const ACF_SETTING_NAME_WIDTH = self::ACF_SETTING_NAME . '_width';

    const COLUMN_NAME_PREFIX = 'acf_';

    protected static $instance;

    protected $exclude_field_types = array(
        'accordion',
        'clone',
        'flexible_content',
        'google_map',
        'group',
        'message',
        'repeater',
        'tab',
    );

    protected $admin_columns = array();

    protected $screen_is_post_type_index = false;
    protected $screen_is_taxonomy_index = false;
    protected $screen_is_user_index = false;

    protected function __construct()
    {
        // ACF related
        add_action('acf/init', array($this, 'wp_action_add_acf_actions')); // add ACF fields

        // WP admin index tables (e.g. "All posts" screens)
        add_action('parse_request', array($this, 'wp_action_prepare_columns'), 10);
        add_action('pre_get_terms', array($this, 'wp_action_prepare_columns'), 10);
        add_action('pre_get_users', array($this, 'wp_action_prepare_columns'), 10);

        add_action('pre_get_posts', array($this, 'wp_action_prepare_query_sort'));

    }

    /**
     * Returns the instance of this class
     * @return FleiACFAdminColumns
     */

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Add ACF hooks and handle versions
     *
     * @hook acf/init
     * @return void
     *
     */
    public function wp_action_add_acf_actions()
    {
        $this->exclude_field_types = apply_filters('acf/admin_columns/exclude_field_types', $this->exclude_field_types);
        $acf_version = acf_get_setting('version');
        $sections = acf_get_field_types();
        if ((version_compare($acf_version, '5.5.0', '<') || version_compare($acf_version, '5.6.0', '>=')) && version_compare($acf_version, '5.7.0', '<')) {
            foreach ($sections as $section) {
                foreach ($section as $type => $label) {
                    if (!in_array($type, $this->exclude_field_types)) {
                        add_action('acf/render_field_settings/type=' . $type, array($this, 'render_field_settings'), 1);
                    }
                }
            }
        } else {
            // >= 5.5.0 || < 5.6.0
            foreach ($sections as $type => $settings) {
                if (!in_array($type, $this->exclude_field_types)) {
                    add_action('acf/render_field_settings/type=' . $type, array($this, 'render_field_settings'), 1);
                }
            }
        }
    }

    /**
     * Checks which columns to show on the current screen and attaches to the respective WP hooks
     *
     * @hook parse_request
     * @hook pre_get_terms
     * @hook pre_get_users
     * @return void
     *
     */
    public function wp_action_prepare_columns()
    {
        $screen = $this->get_screen();
        if (!empty($this->admin_columns) || !$screen || !$this->is_acf_active()) {
            return;
        }

        $field_group_location = '';
        $field_group_location_value = null;

        if ($this->screen_is_post_type_index) {
            $field_group_location = 'post_type';
            $field_group_location_value = $screen->post_type;
        } elseif ($this->screen_is_taxonomy_index) {
            $field_group_location = 'taxonomy';
            $field_group_location_value = $screen->taxonomy;
        } elseif ($this->screen_is_user_index) {
            $field_group_location = 'user_form';
        }

        $field_groups = [];
        foreach (acf_get_field_groups() as $acf_field_group) {
            if ($this->acf_field_group_has_location_type($acf_field_group['ID'], $field_group_location, $field_group_location_value)) {
                $field_groups[] = $acf_field_group;

                $acf_field_group_fields = acf_get_fields($acf_field_group);
                foreach ($acf_field_group_fields as $field) {
                    if (!isset($field[self::ACF_SETTING_NAME_ENABLED]) || $field[self::ACF_SETTING_NAME_ENABLED] == false) {
                        continue;
                    }

                    $this->admin_columns[self::COLUMN_NAME_PREFIX . $field['name']] = $field;
                }
            }
        }

        $this->admin_columns = apply_filters('acf/admin_columns/admin_columns', $this->admin_columns, $field_groups);

        if (!empty($this->admin_columns)) {
            if ($this->screen_is_post_type_index) {
                add_filter('manage_' . $screen->post_type . '_posts_columns', array($this, 'wp_filter_manage_posts_columns')); // creates the columns
                add_filter('manage_' . $screen->id . '_sortable_columns', array($this, 'wp_filter_manage_sortable_columns')); // make columns sortable
                add_action('manage_' . $screen->post_type . '_posts_custom_column', array($this, 'wp_filter_manage_custom_column'), 10, 2); // outputs the columns values for each post
                add_filter('posts_join', array($this, 'wp_filter_search_join'));
                add_filter('posts_where', array($this, 'wp_filter_search_where'));
                add_filter('posts_distinct', array($this, 'wp_filter_search_distinct'));
            } elseif ($this->screen_is_taxonomy_index) {
                add_filter('manage_edit-' . $screen->taxonomy . '_columns', array($this, 'wp_filter_manage_posts_columns')); // creates the columns
                add_filter('manage_' . $screen->taxonomy . '_custom_column', array($this, 'wp_filter_manage_taxonomy_custom_column'), 10, 3); // outputs the columns values for each post
                add_filter('manage_' . $screen->taxonomy . '_custom_column', array($this, 'wp_filter_manage_custom_column'), 10, 3); // outputs the columns values for each post
            } elseif ($this->screen_is_user_index) {
                add_filter('manage_users_columns', array($this, 'wp_filter_manage_posts_columns')); // creates the columns
                add_filter('manage_users_custom_column', array($this, 'wp_filter_manage_custom_column'), 10, 3); // outputs the columns values for each post
            }

            add_action('admin_head', array($this, 'wp_action_admin_head')); // add column styling, like column width
        }
    }

    /**
     * prepares WPs query object when sorting by ACF field
     *
     * @hook pre_get_posts
     *
     * @param $query WP_Query
     * @return mixed
     */
    public function wp_action_prepare_query_sort($query)
    {

        if ($query->query_vars && isset($query->query_vars['orderby']) && $this->is_acf_active() && $this->get_screen()) {

            $sortby_column = $query->query_vars['orderby'];

            if (is_string($sortby_column) && array_key_exists($sortby_column, $this->admin_columns)) {

                // this makes sure we sort also when the custom field has never been set on some posts before
                $meta_query = array(
                    'relation' => 'OR',
                    array('key' => $this->get_column_field_name($sortby_column), 'compare' => 'NOT EXISTS'), // 'NOT EXISTS' needs to go first for proper sorting
                    array('key' => $this->get_column_field_name($sortby_column), 'compare' => 'EXISTS'),
                );

                $query->set('meta_query', $meta_query);

                $sort_order_type = 'meta_value';

                // make numerical field ordering useful:
                $field_properties = acf_get_field($this->get_column_field_name($sortby_column));
                if (isset($field_properties['type']) && $field_properties['type'] === 'number') {
                    $sort_order_type = 'meta_value_num';
                }

                $sort_order_type = apply_filters('acf/admin_columns/sort_order_type', $sort_order_type, $field_properties);

                $query->set('orderby', $sort_order_type);
            }
        }

        return $query;
    }

    /**
     * Adds the designated columns to Wordpress admin list table.
     *
     * @hook manage_{$post_type}_posts_columns
     * @hook manage_edit-{$taxonomy}_columns
     * @hook manage_users_columns
     *
     * @param $columns array
     * @return array
     */
    public function wp_filter_manage_posts_columns($columns)
    {

        if (empty($this->admin_columns)) {
            return $columns;
        }

        $acf_columns = $this->admin_columns;

        // first we need to make sure we have all field properties and apply the position filter
        foreach ($acf_columns as $column_name => $field_properties) {
            if (empty($field_properties) || !is_array($field_properties)) {
                $acf_columns[$column_name] = acf_get_field($this->get_column_field_name($column_name)); // refresh field options if they are not set, e.g. after incorrectly applied filter acf/admin_columns/admin_columns
            }

            $column_position = empty($acf_columns[$column_name][self::ACF_SETTING_NAME_POSITION]) ? 0 : $acf_columns[$column_name][self::ACF_SETTING_NAME_POSITION];
            $acf_columns[$column_name][self::ACF_SETTING_NAME_POSITION] = apply_filters('acf/admin_columns/column_position', $column_position, $this->get_column_field_name($column_name), $acf_columns[$column_name]);
        }

        // next we need to sort our columns by their desired position in order to merge them with the existing columns in the right order
        uasort($acf_columns, static function ($a, $b) {
            if (!empty($a[self::ACF_SETTING_NAME_POSITION]) && !empty($b[self::ACF_SETTING_NAME_POSITION])) {
                return (int)$a[self::ACF_SETTING_NAME_POSITION] - (int)$b[self::ACF_SETTING_NAME_POSITION];
            }

            if (empty($a[self::ACF_SETTING_NAME_POSITION]) && !empty($b[self::ACF_SETTING_NAME_POSITION])) {
                return -1;
            }

            if (empty($b[self::ACF_SETTING_NAME_POSITION]) && !empty($a[self::ACF_SETTING_NAME_POSITION])) {
                return 1;
            }

            return 0;
        });

        // we'll merge the ACF columns with the existing columns and bring them all in the right order
        $columns_keys = array_keys($columns);
        foreach ($acf_columns as $aac_idx => $acf_column) {
            if (!empty($acf_column[self::ACF_SETTING_NAME_POSITION])) {
                array_splice($columns_keys, ((int)$acf_column[self::ACF_SETTING_NAME_POSITION] - 1), 0, $aac_idx);
            } else {
                $columns_keys[] = $aac_idx;
            }
        }

        // finally we prepare all column keys and labels for output
        $all_columns = array();
        foreach ($columns_keys as $column_key) {
            if (array_key_exists($column_key, $acf_columns)) {
                $all_columns[$column_key] = $acf_columns[$column_key]['label'];
            } else if (array_key_exists($column_key, $columns)) {
                $all_columns[$column_key] = $columns[$column_key];
            }
        }
        return $all_columns;
    }

    /**
     * Makes the columns sortable.
     *
     * @hook manage_{$post_type}_sortable_columns
     *
     * @param $columns array
     * @return array
     */
    public function wp_filter_manage_sortable_columns($columns)
    {

        foreach ($this->admin_columns as $idx => $acol) {
            $columns[$idx] = $idx;
        }

        return apply_filters('acf/admin_columns/sortable_columns', $columns);
    }

    /**
     * WP Hook for displaying the field value inside of a columns cell
     *
     * @hook manage_{$post_type}_posts_custom_column
     * @hook manage_{$taxonomy}_custom_column
     * @hook manage_users_custom_column
     *
     * @param $arg1 string
     * @param $arg2 mixed
     * @param $arg3 mixed
     * @return void
     */

    public function wp_filter_manage_custom_column($arg1, $arg2 = null, $arg3 = null)
    {

        $current_filter = current_filter();
        if ($current_filter) {
            $render_args = array();
            $echo_value = true;

            if (strpos($current_filter, '_posts_custom_column')) {
                $render_args['column'] = $arg1;
                $render_args['post_id'] = $arg2;
            } elseif (strpos($current_filter, '_custom_column')) {
                $render_args['column'] = $arg2;
                $render_args['post_id'] = $arg3;
                $echo_value = false;

                $screen = $this->get_screen();

                if ($this->screen_is_taxonomy_index) {
                    $render_args['post_id'] = $screen->taxonomy . '_' . $render_args['post_id'];
                } elseif ($this->screen_is_user_index) {
                    $render_args['post_id'] = 'user_' . $render_args['post_id'];
                }

            }
            if (array_key_exists($render_args['column'], $this->admin_columns)) {
                $field_name = $this->get_column_field_name($render_args['column']);
                $rendered_field_value = $this->render_column_field($render_args);
                $rendered_field_value = apply_filters_deprecated('acf/admin_columns/column/' . $field_name, array($rendered_field_value), '0.2.0', 'acf/admin_columns/render_output');
                $rendered_field_value = apply_filters_deprecated('acf/admin_columns/column/' . $field_name . '/value', array($rendered_field_value), '0.2.2', 'acf/admin_columns/render_output');
                if ($echo_value) {
                    echo $rendered_field_value;
                } else {
                    return $rendered_field_value;
                }
            }
        }

    }

    /**
     * WP Hook for displaying the field value inside of a columns cell taxonomy index pages
     *
     * @hook
     * @param $column
     * @param $post_id
     */
    public function wp_filter_manage_taxonomy_custom_column($content, $column, $post_id)
    {

        if (array_key_exists($column, $this->admin_columns)) {

            $field_name = $this->get_column_field_name($column);

            $screen = get_current_screen();
            $taxonomy = $screen->taxonomy;

            $rendered_field_value = $this->render_column_field(array('column' => $column, 'post_id' => $post_id, 'taxonomy' => $taxonomy));
            $rendered_field_value = apply_filters_deprecated('acf/admin_columns/column/' . $field_name, $rendered_field_value, '0.2.2', 'acf/admin_columns/render_output');

            $content = $rendered_field_value;
        }

        return $content;
    }

    /**
     * WP Hook for joining ACF fields to the search query
     *
     * @hook posts_join
     *
     * @param $join string
     * @return string
     */
    public function wp_filter_search_join($join)
    {

        if ($this->get_search_term()) {
            global $wpdb;
            $join .= ' LEFT JOIN ' . $wpdb->postmeta . ' AS ' . self::COLUMN_NAME_PREFIX . $wpdb->postmeta . ' ON ' . $wpdb->posts . '.ID = ' . self::COLUMN_NAME_PREFIX . $wpdb->postmeta . '.post_id ';
        }

        return $join;
    }

    /**
     * WP Hook for searching in ACF fields
     *
     * @hook posts_where
     *
     * @param $where string
     * @return string
     */

    public function wp_filter_search_where($where)
    {
        if ($this->get_search_term()) {

            global $wpdb;

            $where_column_sql = '';
            foreach ($this->admin_columns as $column => $description) {
                $column = $this->get_column_field_name($column);
                $where_column_sql .= " OR (" . self::COLUMN_NAME_PREFIX . $wpdb->postmeta . ".meta_key ='$column' AND " . self::COLUMN_NAME_PREFIX . $wpdb->postmeta . ".meta_value LIKE $1)";
            }

            $where = preg_replace(
                "/\(\s*" . $wpdb->posts . ".post_title\s+LIKE\s*(\'[^\']+\')\s*\)/",
                "(" . $wpdb->posts . ".post_title LIKE $1)" . $where_column_sql,
                $where);
        }

        return $where;

    }

    /**
     * @hook posts_distinct
     *
     * @param $where
     * @return mixed|string
     */
    public function wp_filter_search_distinct($where)
    {

        if ($this->get_search_term()) {
            return "DISTINCT";
        }

        return $where;
    }

    /**
     * Output column styles to the admin head.
     *
     * @hook admin_head
     *
     * @return void
     */
    public function wp_action_admin_head()
    {

        $all_column_styles = array();

        foreach ($this->admin_columns as $column => $field_properties) {
            $column_styles = '';

            $column_width = isset($field_properties[self::ACF_SETTING_NAME_WIDTH]) ? trim($field_properties[self::ACF_SETTING_NAME_WIDTH]) : '';
            if (!empty($column_width)) {
                $column_styles .= 'width:' . $column_width . ';';
            }

            if (!empty($column_styles)) {
                $all_column_styles[$column] = apply_filters('acf/admin_columns/column_styles', $column_styles, $this->get_column_field_name($column), $field_properties);
            }
        }

        if (!empty($all_column_styles)) {
            echo '<style>';
            foreach ($all_column_styles as $column => $column_styles) {
                echo '.column-' . $column . '{' . $column_styles . '}';
            }
            echo '</style>';
        }

    }


    /**
     * Renders the field value for a given column
     *
     * @param $args array
     * @return mixed|string
     */
    public function render_column_field($args)
    {

        $column = $args['column'];
        $post_id = isset($args['post_id']) ? $args['post_id'] : false;

        $field_name = $this->get_column_field_name($column);
        $field_properties = acf_get_field($field_name, $post_id);

        // field values
        $field_value = get_field($field_name, $post_id);
        $original_field_value = $field_value;

        // if the field is really empty $field_value should be null
        if ($original_field_value === false && $field_properties['type'] !== 'true_false') {
            $field_value = null;
        }

        $render_output = '';


        $field_images = $field_value;
        $preview_item_count = 1;
        $remaining_items_count = 0;
        $render_raw = false;
        $render_raw = apply_filters_deprecated('acf/admin_columns/column/' . $field_name . '/render_raw', array($render_raw, $field_properties, $original_field_value, $post_id), '0.2.2', 'acf/admin_columns/render_raw');
        $render_raw = apply_filters('acf/admin_columns/render_raw', $render_raw, $field_properties, $original_field_value, $post_id);

        if (empty($field_value) && !empty($field_properties['default_value'])) {
            $field_value = apply_filters('acf/admin_columns/default_value', $field_properties['default_value'], $field_properties, $field_value, $post_id);
        }

        $field_value = apply_filters_deprecated('acf/admin_columns/column/' . $field_name . '/before_render_value', array($field_value, $field_properties, $post_id), '0.2.2', 'acf/admin_columns/before_render_output');
        $field_value = apply_filters('acf/admin_columns/before_render_output', $field_value, $field_properties, $post_id);

        if (!$render_raw) {

            switch ($field_properties['type']) {
                case 'color_picker':
                    if ($field_value) {
                        $render_output .= '<div style="display:inline-block;height:20px;width:100%;background-color:' . $field_value . ';white-space:nowrap;">' . $field_value . '</div><br>';
                    }
                    break;
                case 'taxonomy':
                    if (is_array($field_value)) {
                        foreach ($field_value as $field_taxonomy) {
                            $render_output .= $field_taxonomy->name . ' (ID ' . $field_taxonomy->term_id . ')<br>';
                        }
                    }
                    break;
                case 'file':
                    $render_output = isset($field_value['filename']) ? $field_value['filename'] : ''; // @todo multiple values
                    break;
                case 'wysiwyg':
                    $render_output = wp_trim_excerpt(strip_tags(strval($field_value)));
                    break;
                case 'link':
                    if (is_array($field_value) && isset($field_value['url'])) {
                        $render_output = $field_value['url'];
                    }
                    break;
                case 'post_object':
                case 'relationship':
                    $related_post = $field_value;
                    if (is_array($field_value) && !empty($field_value)) {
                        $related_post = $field_value[0]; // use the first post as preview
                        $remaining_items_count = count($field_value) - 1;
                    }
                    if ($related_post) {
                        $render_output = '<a href="' . get_edit_post_link($related_post, false) . '">' . get_the_title($related_post) . '</a>';
                    }
                    break;
                case 'user':
                    if (!empty($field_value)) {
                        if (!empty($field_properties['multiple'])) {
                            $remaining_items_count = count($field_value) - 1;
                            $field_value = $field_value[0];
                        }

                        if (is_array($field_value) && !empty($field_value['ID'])) {
                            $user = get_user_by('id', $field_value['ID']);
                        } elseif ($field_value instanceof \WP_User) {
                            $user = $field_value;
                        } elseif ((int)$field_value > 0) {
                            $user = get_user_by('id', (int)$field_value);
                        }

                        if (!empty($user)) {
                            $render_output = '<a href="' . get_edit_user_link($user->ID) . '">' . $user->user_login . (!empty($user->display_name) && $user->user_login !== $user->display_name ? ' (' . $user->display_name . ')' : '') . '</a>';
                        }
                    }
                    break;
                case 'image':
                    $field_images = array($field_value);
                case 'gallery':

                    if (!empty($field_images)) {
                        $preview_image = $field_images[0]; // use first image as preview
                        $preview_image_id = 0;
                        $preview_image_url = '';

                        if (is_array($field_value) && isset($preview_image['ID'])) {
                            $preview_image_id = $preview_image['ID'];
                        } else if ((int)$preview_image > 0) {
                            $preview_image_id = (int)$preview_image;
                        }

                        if (filter_var($preview_image, FILTER_VALIDATE_URL)) { // is the field return value a url string?
                            $preview_image_url = $preview_image;
                        } else if ($preview_image_id > 0) { // return value is either image array or id
                            $preview_image_size = apply_filters('acf/admin_columns/preview_image_size', 'thumbnail', $field_properties, $field_value, $post_id);
                            $img = wp_get_attachment_image_src($preview_image_id, $preview_image_size);
                            if (is_array($img) && isset($img[0])) {
                                $preview_image_url = $img[0];
                            }
                        }

                        $preview_image_url = apply_filters('acf/admin_columns/preview_image_url', $preview_image_url, $field_properties, $field_value, $post_id);

                        if ($preview_image_url) {
                            $render_output = "<img style='width:100%;height:auto;' src='$preview_image_url'>";

                            $remaining_items_count = count($field_images) - $preview_item_count;
                        }
                    }
                    break;
                case 'radio':
                case 'checkbox':
                case 'select':
                    if (!empty($field_value) && isset($field_properties['return_format'])) {
                        if ($field_properties['type'] === 'checkbox' || (!empty($field_properties['multiple']))) {
                            $render_output = array();
                            foreach ($field_value as $field_value_item) {
                                $render_output[] = $this->render_value_label_field($field_properties['return_format'], $field_properties['choices'], $field_value_item);
                            }
                        } else {
                            $render_output = $this->render_value_label_field($field_properties['return_format'], $field_properties['choices'], $field_value);
                        }
                        break;
                    }
                case 'number':
                case 'true_false':
                case 'text':
                case 'textarea':
                case 'range':
                case 'email':
                case 'url':
                case 'password':
                case 'button_group':
                case 'page_link':
                case 'date_picker':
                case 'time_picker':
                default:
                    $render_output = $field_value;
            }

            // wrap link around URL field value
            $link_wrap_url = apply_filters('acf/admin_columns/link_wrap_url', true, $field_properties, $original_field_value, $post_id);
            if ($link_wrap_url && filter_var($render_output, FILTER_VALIDATE_URL)) {
                $render_output = '<a href="' . $render_output . '">' . $render_output . '</a>';
            }

            // convert array entries to column string
            if (is_array($render_output)) {
                $array_render_separator = apply_filters('acf/admin_columns/array_render_separator', ', ', $field_properties, $original_field_value, $post_id);
                $render_output = implode($array_render_separator, $render_output);
            }

            // default "no value" or "empty" output
            if (empty($render_output) && $field_value === null) {
                $render_output = apply_filters('acf/admin_columns/no_value_placeholder', '—', $field_properties, $original_field_value, $post_id);
            }
        }

        // search term highlighting
        if ($search_term = $this->get_search_term()) {

            $search_preg_replace_pattern = '<span style="background-color:#FFFF66; color:#000000;">\\0</span>';
            $search_preg_replace_pattern = apply_filters_deprecated('acf/admin_columns/search/highlight_preg_replace_pattern', array($search_preg_replace_pattern, $search_term, $field_properties, $original_field_value, $post_id), '0.2.2', 'acf/admin_columns/highlight_search_term_preg_replace_pattern');
            $search_preg_replace_pattern = apply_filters('acf/admin_columns/highlight_search_term_preg_replace_pattern', $search_preg_replace_pattern, $search_term, $field_properties, $original_field_value, $post_id);

            $render_output = preg_replace('#' . preg_quote($search_term) . '#i', $search_preg_replace_pattern, $render_output);
        }

        if (!empty($remaining_items_count)) {
            $render_output .= "<br>and $remaining_items_count more";
        }

        return apply_filters('acf/admin_columns/render_output', $render_output, $field_properties, $original_field_value, $post_id);
    }

    /**
     * Renders the field within ACF's field setting UI
     *
     * @param $field
     * @return void
     *
     */
    public function render_field_settings($field)
    {

        /*
         * General settings switch
         */
        $field_settings = array(
            array(
                'type' => 'true_false',
                'ui' => 1,
                'label' => 'Admin Column',
                'name' => self::ACF_SETTING_NAME_ENABLED,
                'instructions' => 'Enable admin column for this field in post archive pages.',
            ),
            array(
                'type' => 'number',
                'min' => 1,
                'ui' => 1,
                'label' => 'Admin Column Position',
                'name' => self::ACF_SETTING_NAME_POSITION,
                'instructions' => 'Position of the admin column. Leave empty to append to the end.',
                'conditional_logic' => array(
                    array(
                        array(
                            'field' => self::ACF_SETTING_NAME_ENABLED,
                            'operator' => '==',
                            'value' => '1',
                        ),
                    ),
                ),
            ),
            array(
                'type' => 'text',
                'ui' => 1,
                'label' => 'Admin Column Width',
                'name' => self::ACF_SETTING_NAME_WIDTH,
                'instructions' => 'Width of the admin column. Specify as CSS value, e.g. "100px" or "20%". Leave empty to use the default auto width.',
                'conditional_logic' => array(
                    array(
                        array(
                            'field' => self::ACF_SETTING_NAME_ENABLED,
                            'operator' => '==',
                            'value' => '1',
                        ),
                    ),
                ),
            )
        );

        foreach ($field_settings as $settings_args) {
            $settings_args['class'] = isset($settings_args['class']) ? $settings_args['class'] . ' aac-field-settings-' . $settings_args['name'] : '';
            acf_render_field_setting($field, $settings_args, false);
        }

    }

    /**
     * checks whether ACF plugin is active
     * @return bool
     */
    protected function is_acf_active()
    {
        return (function_exists('acf_get_field_groups') && function_exists('acf_get_fields'));
    }

    /**
     * Returns the current screen object
     * @return bool|WP_Screen
     */
    protected function get_screen()
    {

        if (function_exists('get_current_screen') && $screen = get_current_screen()) {
            $this->screen_is_post_type_index = $screen->base === 'edit' && $screen->post_type;
            $this->screen_is_taxonomy_index = $screen->base === 'edit-tags' && $screen->taxonomy;
            $this->screen_is_user_index = $screen->base === 'users';

            if ($this->screen_is_post_type_index || $this->screen_is_taxonomy_index || $this->screen_is_user_index) {
                return $screen;
            }
        }

        return false;
    }

    /**
     * Returns the search term if the current screen is a post type index and a search is active
     * @return bool|string
     */
    protected function get_search_term()
    {

        $search_term = false;
        if (!empty($_GET['s'])) {
            $search_term = $_GET['s'];
        }

        return $search_term;
    }

    /**
     * Return the "real" ACF field name, without the prefix
     * @param $colum_name
     * @return mixed
     */
    protected function get_column_field_name($colum_name)
    {
        return str_replace(self::COLUMN_NAME_PREFIX, '', $colum_name);
    }

    /**
     * Renders the value of a select, radio or checkbox field based on the return format
     *
     * @param $return_format
     * @param $choices
     * @param $field_value
     * @return mixed|string
     */
    protected function render_value_label_field($return_format, $choices, $field_value)
    {
        if (empty($field_value)) {
            return $field_value;
        }
        $render_output = $field_value;
        $value = '';
        $label = '';

        if ($return_format === 'value' && !empty($choices[$field_value])) {
            $value = $field_value;
            $label = $choices[$field_value];
        } else if ($return_format === 'label' && in_array($field_value, $choices)) {
            $value = array_search($field_value, $choices);
            $label = $field_value;
        } else if ($return_format === 'array' && is_array($field_value) && array_key_exists('value', $field_value) && array_key_exists('label', $field_value)) {
            $value = $field_value['value'];
            $label = $field_value['label'];
        }

        if (!empty($value) && !empty($label)) {
            $render_output = $value;
            if ($value !== $label) {
                $render_output .= ' (' . $label . ')';
            }
        }

        return $render_output;
    }

    protected function acf_field_group_has_location_type($post_id, $location, $location_value = null)
    {
        if (empty($post_id) || empty($location)) {
            return false;
        }

        $field_group = acf_get_field_group($post_id);

        if (empty($field_group['location'])) {
            return false;
        }

        foreach ($field_group['location'] as $rule_group) {
            $params = array_column($rule_group, 'param');

            if (in_array($location, $params, true)) {
                if ($location_value !== null && !in_array($location_value, array_column($rule_group, 'value'), true)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

}

if (is_admin()) {
    $flei_acf_admin_columns = FleiACFAdminColumns::get_instance();
}
