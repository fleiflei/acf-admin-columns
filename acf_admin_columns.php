<?php
/**
 * Plugin Name: Admin Columns for ACF Fields
 * Plugin URI: https://wordpress.org/plugins/acf-admin-columns/
 * Description: Add columns for your ACF fields to post and taxonomy index pages in the WP backend.
 * Version: 0.2.0
 * Author: Florian Eickhorst
 * Author URI: http://www.fleimedia.com/
 * License: GPL
 */

class FleiACFAdminColumns
{

    const ACF_SETTING_NAME = 'admin_column';
    const COLUMN_NAME_PREFIX = 'acf_';

    private static $instance;

    private $exclude_field_types = array(
        'accordion',
        'clone',
        'flexible_content',
        'google_map',
        'group',
        'message',
        'repeater',
        'tab',
    );

    private $admin_columns = array();

    private $screen_is_post_type_index = false;
    private $screen_is_taxonomy_index = false;
    private $screen_is_user_index = false;

    private function __construct()
    {
        // ACF related
        add_action('acf/init', array($this, 'action_add_acf_actions')); // add ACF fields

        // WP admin index tables (e.g. "All posts" screens)
        add_action('parse_request', array($this, 'action_prepare_columns'), 10);
        add_action('pre_get_terms', array($this, 'action_prepare_columns'), 10);
        add_action('pre_get_users', array($this, 'action_prepare_columns'), 10);

        add_action('pre_get_posts', array($this, 'action_prepare_query_sort'));

    }

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Add ACF hooks and handle versions
     */
    public function action_add_acf_actions()
    {
        $exclude = apply_filters('acf/admin_columns/exclude_field_types', $this->exclude_field_types);
        $acf_version = acf_get_setting('version');
        $sections = acf_get_field_types();
        if ((version_compare($acf_version, '5.5.0', '<') || version_compare($acf_version, '5.6.0', '>=')) && version_compare($acf_version, '5.7.0', '<')) {
            foreach ($sections as $section) {
                foreach ($section as $type => $label) {
                    if (!in_array($type, $exclude)) {
                        add_action('acf/render_field_settings/type=' . $type, array($this, 'render_field_settings'), 1);
                    }
                }
            }
        } else {
            // >= 5.5.0 || < 5.6.0
            foreach ($sections as $type => $settings) {
                if (!in_array($type, $exclude)) {
                    add_action('acf/render_field_settings/type=' . $type, array($this, 'render_field_settings'), 1);
                }
            }
        }
    }

    /**
     * Checks which columns to show on the current screen and attaches to the respective WP hooks
     */
    public function action_prepare_columns()
    {
        $screen = $this->is_valid_admin_screen();
        if (!$this->is_acf_active() || $this->admin_columns || !$screen) {
            return;
        }

        $field_groups_args = array();

        if ($this->screen_is_post_type_index) {
            $field_groups_args['post_type'] = $screen->post_type;
        } elseif ($this->screen_is_taxonomy_index) {
            $field_groups_args['taxonomy'] = $screen->taxonomy;
        } elseif ($this->screen_is_user_index) {
            $field_groups_args['user_form'] = 'all';
        }

        // get all field groups for the current post type and check every containing field if it should become a
        $field_groups = acf_get_field_groups($field_groups_args);

        foreach ($field_groups as $fgroup) {

            $fgroup_fields = acf_get_fields($fgroup);
            foreach ($fgroup_fields as $field) {
                if (!isset($field[self::ACF_SETTING_NAME . '_enabled']) || $field[self::ACF_SETTING_NAME . '_enabled'] == false) {
                    continue;
                }

                if ($this->screen_is_taxonomy_index && (!isset($field[self::ACF_SETTING_NAME . '_taxonomies']) || (is_array($field[self::ACF_SETTING_NAME . '_taxonomies']) && array_search($screen->taxonomy, $field[self::ACF_SETTING_NAME . '_taxonomies']) === false))) {
                    continue;
                }

                $this->admin_columns[self::COLUMN_NAME_PREFIX . $field['name']] = $field['label'];
            }

        }

        $this->admin_columns = apply_filters('acf/admin_columns/admin_columns', $this->admin_columns);

        if (!empty($this->admin_columns)) {
            if ($this->screen_is_post_type_index) {
                add_filter('manage_' . $screen->post_type . '_posts_columns', array($this, 'filter_manage_posts_columns')); // creates the columns
                add_filter('manage_' . $screen->id . '_sortable_columns', array($this, 'filter_manage_sortable_columns')); // make columns sortable
//                add_action('manage_' . $screen->post_type . '_posts_custom_column', array($this, 'action_manage_posts_custom_column'), 10, 2); // outputs the columns values for each post
                add_action('manage_' . $screen->post_type . '_posts_custom_column', array($this, 'filter_manage_custom_column'), 10, 2); // outputs the columns values for each post

                add_filter('posts_join', array($this, 'filter_search_join'));
                add_filter('posts_where', array($this, 'filter_search_where'));
                add_filter('posts_distinct', array($this, 'filter_search_distinct'));

            } elseif ($this->screen_is_taxonomy_index) {
                add_filter('manage_edit-' . $screen->taxonomy . '_columns', array($this, 'filter_manage_posts_columns')); // creates the columns
                add_filter('manage_' . $screen->taxonomy . '_custom_column', array($this, 'filter_manage_taxonomy_custom_column'), 10, 3); // outputs the columns values for each post
                add_filter('manage_' . $screen->taxonomy . '_custom_column', array($this, 'filter_manage_custom_column'), 10, 3); // outputs the columns values for each post
            } elseif ($this->screen_is_user_index) {
                add_filter('manage_users_columns', array($this, 'filter_manage_posts_columns')); // creates the columns
                add_filter('manage_users_custom_column', array($this, 'filter_manage_custom_column'), 10, 3); // outputs the columns values for each post
            }
        }
    }

    /**
     * prepares WPs query object when ordering by ACF field
     *
     * @param $query
     * @return mixed
     */
    public function action_prepare_query_sort($query)
    {

        if ($this->is_acf_active() && $this->is_valid_admin_screen() && $query->query_vars && isset($query->query_vars['orderby'])) {
            $orderby = $query->query_vars['orderby'];

            if (is_string($orderby) && array_key_exists($orderby, $this->admin_columns)) {

                // this makes sure we sort also when the custom field has never been set on some posts before
                $meta_query = array(
                    'relation' => 'OR',
                    array('key' => $this->get_clean_column($orderby), 'compare' => 'NOT EXISTS'), // 'NOT EXISTS' needs to go first for proper sorting
                    array('key' => $this->get_clean_column($orderby), 'compare' => 'EXISTS'),
                );

                $query->set('meta_query', $meta_query);

                $order_type = 'meta_value';

                // make numerical field ordering useful:
                $field_properties = acf_get_field($this->get_clean_column($orderby));
                if (isset($field_properties['type']) && $field_properties['type'] == 'number') {
                    $order_type = 'meta_value_num';
                }

                $order_type = apply_filters('acf/admin_columns/sort_order_type', $order_type, $orderby);

                $query->set('orderby', $order_type);
            }
        }

        return $query;
    }

    /**
     * Adds the designated columns to Wordpress admin post list table.
     *
     * @param $columns array passed by Wordpress
     * @return array
     */
    public function filter_manage_posts_columns($columns)
    {

        if (!empty($this->admin_columns)) {
            $columns = array_merge($columns, $this->admin_columns);
        }

        return $columns;
    }

    /**
     * Makes our columns rendered as sortable.
     *
     * @param $columns
     * @return mixed
     */
    public function filter_manage_sortable_columns($columns)
    {

        foreach ($this->admin_columns as $idx => $acol) {
            $columns[$idx] = $idx;
        }

        $columns = apply_filters('acf/admin_columns/sortable_columns', $columns);

        return $columns;
    }

    /**
     * WP Hook for displaying the field value inside of a columns cell in posts index pages
     *
     * @hook
     * @param $column
     * @param $post_id
     */
    public function action_manage_posts_custom_column($column, $post_id)
    {

        if (array_key_exists($column, $this->admin_columns)) {

            $clean_column = $this->get_clean_column($column);

            $field_value = $this->render_column_field(array('column' => $column, 'post_id' => $post_id));

            $field_value = apply_filters_deprecated('acf/admin_columns/column/' . $clean_column, $field_value, '0.2.0', 'acf/admin_columns/column/' . $clean_column . '/value');
            $field_value = apply_filters('acf/admin_columns/column/' . $clean_column . '/value', $field_value);

            echo $field_value;
        }
    }

    public function filter_manage_custom_column($arg1, $arg2 = null, $arg3 = null)
    {

        $current_filter = current_filter();
        if ($current_filter) {
            $render_args = array();
            $is_action = true;

            if (strpos($current_filter, '_posts_custom_column')) {
                $render_args['column'] = $arg1;
                $render_args['post_id'] = $arg2;
            } elseif (strpos($current_filter, '_custom_column')) {
                $render_args['column'] = $arg2;
                $render_args['post_id'] = $arg3;
                $is_action = false;

                $screen = $this->is_valid_admin_screen();

                if ($this->screen_is_taxonomy_index) {
                    $render_args['post_id'] = $screen->taxonomy . '_' . $render_args['post_id'];
                } elseif ($this->screen_is_user_index) {
                    $render_args['post_id'] = 'user_' . $render_args['post_id'];
                }

            }
            if (array_key_exists($render_args['column'], $this->admin_columns)) {
                $clean_column = $this->get_clean_column($render_args['column']);
                $field_value = $this->render_column_field($render_args);
                $field_value = apply_filters_deprecated('acf/admin_columns/column/' . $clean_column, array($field_value), '0.2.0', 'acf/admin_columns/column/' . $clean_column . '/value');
                $field_value = apply_filters('acf/admin_columns/column/' . $clean_column . '/value', $field_value);
                if ($is_action) {
                    echo $field_value;
                } else {
                    return $field_value;
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
    public function filter_manage_taxonomy_custom_column($content, $column, $post_id)
    {

        if (array_key_exists($column, $this->admin_columns)) {

            $clean_column = $this->get_clean_column($column);

            $screen = get_current_screen();
            $taxonomy = $screen->taxonomy;

            $field_value = $this->render_column_field(array('column' => $column, 'post_id' => $post_id, 'taxonomy' => $taxonomy));

            $field_value = apply_filters('acf/admin_columns/column/' . $clean_column, $field_value);

            $content = $field_value;
        }

        return $content;
    }

    public function filter_search_join($join)
    {

        if ($this->is_search()) {
            global $wpdb;
            $join .= ' LEFT JOIN ' . $wpdb->postmeta . ' AS ' . self::COLUMN_NAME_PREFIX . $wpdb->postmeta . ' ON ' . $wpdb->posts . '.ID = ' . self::COLUMN_NAME_PREFIX . $wpdb->postmeta . '.post_id ';
        }

        return $join;
    }

    public function filter_search_where($where)
    {
        if ($this->is_search()) {

            global $wpdb;

            $where_column_sql = '';
            foreach ($this->admin_columns as $admin_column => $description) {
                $admin_column = $this->get_clean_column($admin_column);
                $where_column_sql .= " OR (" . self::COLUMN_NAME_PREFIX . $wpdb->postmeta . ".meta_key ='$admin_column' AND " . self::COLUMN_NAME_PREFIX . $wpdb->postmeta . ".meta_value LIKE $1)";
            }

            $where = preg_replace(
                "/\(\s*" . $wpdb->posts . ".post_title\s+LIKE\s*(\'[^\']+\')\s*\)/",
                "(" . $wpdb->posts . ".post_title LIKE $1)" . $where_column_sql,
                $where);
        }

        return $where;

    }

    public function filter_search_distinct($where)
    {

        if ($this->is_search()) {
            return "DISTINCT";
        }

        return $where;
    }

    /**
     * Retrieves a field and returns the formatted value based on field type for displaying in "All posts" screen table columns
     *
     * @param $column
     * @param $post_id
     * @return mixed|string
     */
    public function render_column_field($args = array())
    {

        $column = $args['column'];
        $post_id = isset($args['post_id']) ? $args['post_id'] : false;

        $clean_column = $this->get_clean_column($column);

        $field_value = get_field($clean_column, $post_id);

        $render_output = '';

        $field_properties = acf_get_field($clean_column, $post_id);
        $field_images = $field_value;
        $preview_item_count = 1;
        $remaining_items_count = 0;
        $render_raw            = apply_filters('acf/admin_columns/column/' . $clean_column . '/render_raw', false, $field_properties, $field_value, $post_id);

        if (empty($field_value) && !empty($field_properties['default_value'])) {
            $field_value = apply_filters('acf/admin_columns/default_value', $field_properties['default_value'], $field_properties, $field_value, $post_id);
        }

        $field_value = apply_filters('acf/admin_columns/column/' . $clean_column . '/before_render_value', $field_value, $field_properties, $post_id);

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
                        $render_output .= $field_taxonomy->name . ' (' . $field_taxonomy->term_id . ')';
                    }
                }
                break;
            case 'file':
                $render_output = isset($field_value['filename']) ? $field_value['filename'] : ''; // @todo multiple values
                break;
            case 'wysiwyg':
                $render_output = wp_trim_excerpt(strip_tags($field_value));
                break;
            case 'link':
                if (is_array($field_value) && isset($field_value['url'])) {
                    $render_output = $field_value['url'];
                }
                break;
            case 'post_object':
            case 'relationship':
                $p = $field_value;
                if (is_array($field_value) && !empty($field_value)) {
                    $p = $field_value[0];
                    $remaining_items_count = count($field_value) - 1;
                }
                if ($p) {
                    $render_output = '<a href="' . get_edit_post_link($p, false) . '">' . get_the_title($p) . '</a>';
                }
                break;
            case 'user':
                $u = $field_value;
                if (is_array($field_value) && !empty($field_value)) {
                    $u = $field_value[0];
                    $render_output = '<a href="' . get_edit_user_link($u) . '">' . $u['display_name'] . '</a>';
                    $remaining_items_count = count($field_value) - 1;
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
                    } else if (intval($preview_image) > 0) {
                        $preview_image_id = intval($preview_image);
                    }

                    if (filter_var($preview_image, FILTER_VALIDATE_URL)) {
                        $preview_image_url = $preview_image;
                    } else if ($preview_image_id > 0) {
                        $preview_image_size = apply_filters('acf/admin_columns/preview_image_size', 'thumbnail', $field_properties, $field_value);
                        $img = wp_get_attachment_image_src($preview_image_id, $preview_image_size);
                        if (is_array($img) && isset($img[0])) {
                            $preview_image_url = $img[0];
                        }
                    }

                    $preview_image_url = apply_filters('acf/admin_columns/preview_image_url', $preview_image_url, $field_properties, $field_value);

                    if ($preview_image_url) {
                        $render_output = "<img style='width:100%;height:auto;' src='$preview_image_url'>";

                        if ($field_images) {
                            $remaining_items_count = count($field_images) - $preview_item_count;
                        }
                    }
                }
                break;
            case 'number':
            case 'true_false':
            case 'text':
            case 'textarea':
                $render_raw = true;
                case 'checkbox':
                case 'radio':
            case 'select':
                    if (!empty($field_properties['choices'][$field_value])) {
                        $render_output = $field_properties['choices'][$field_value] . ' (' . $field_value . ')';
                        break;
                    }
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

            $link_wrap_url = apply_filters('acf/admin_columns/link_wrap_url', true, $field_properties, $field_value, $post_id);

        if (filter_var($render_output, FILTER_VALIDATE_URL) && $link_wrap_url) {
            $render_output = '<a href="' . $render_output . '">' . $render_output . '</a>';
        }

        // list array entries
        if (is_array($render_output)) {
            $render_output = implode(', ', $render_output);
        }

        }


        // default "no value" or "empty" output
        if (empty($render_output) && !$render_raw && $field_properties['type'] !== 'true_false' ) {
            $render_output = apply_filters('acf/admin_columns/no_value_placeholder', '—', $field_properties, $field_value, $post_id);
        }

        // search term highlighting
        if ($search_term = $this->is_search()) {
            $search_preg_replace_pattern = apply_filters('acf/admin_columns/search/highlight_preg_replace_pattern', '<span style="background-color:#FFFF66; color:#000000;">\\0</span>', $search_term, $field_properties, $field_value, $post_id);
            $render_output = preg_replace('#' . preg_quote($search_term) . '#i', $search_preg_replace_pattern, $render_output);
        }

        if (!empty($remaining_items_count)) {
            $render_output .= "<br>and $remaining_items_count more";
        }

        return apply_filters('acf/admin_columns/render_output', $render_output, $field_properties, $field_value, $post_id);
    }

    /**
     * Outputs the field within ACF's field setting UI
     * @param $field
     */
    public function render_field_settings($field)
    {

        $setting_name_enabled = self::ACF_SETTING_NAME . '_enabled';
        $setting_active = isset($field[$setting_name_enabled]) && !!$field[$setting_name_enabled];

        /*
         * General settings switch
         */
        $field_settings = array(
            array(
                'type' => 'true_false',
                'ui' => 1,
                'label' => 'Admin Column',
                'name' => $setting_name_enabled,
                'instructions' => 'Enable admin column for this field in post archive pages.',
            ),
        );

        foreach ($field_settings as $settings_args) {
            $settings_args['class'] = isset($settings_args['class']) ? $settings_args['class'].' aac-field-settings-' . $settings_args['name'] : '';
            acf_render_field_setting($field, $settings_args, false);
        }

    }

    private function get_supported_post_types()
    {
        return $post_types = get_post_types(array('show_ui' => true, 'show_in_menu' => true));
    }

    /**
     * checks whether ACF plugin is active
     * @return bool
     */
    private function is_acf_active()
    {
        return (function_exists('acf_get_field_groups') && function_exists('acf_get_fields'));
    }

    private function is_valid_admin_screen()
    {

        if (function_exists('get_current_screen') && $screen = get_current_screen()) {
            $this->screen_is_post_type_index = $screen->base == 'edit' && $screen->post_type;
            $this->screen_is_taxonomy_index = $screen->base == 'edit-tags' && $screen->taxonomy;
            $this->screen_is_user_index = $screen->base == 'users';

            if ($this->screen_is_post_type_index || $this->screen_is_taxonomy_index || $this->screen_is_user_index) {
                return $screen;
            }
        }

        return false;
    }

    private function is_search()
    {

        $search_term = false;
        if (isset($_GET['s']) && !empty($_GET['s'])) {
            $search_term = $_GET['s'];
        }

        return $search_term;
    }

    /**
     * Return the "real" ACF field name, without the prefix
     * @param $dirty_column
     * @return mixed
     */
    private function get_clean_column($dirty_column)
    {
        $clean_column = str_replace(self::COLUMN_NAME_PREFIX, '', $dirty_column);

        return $clean_column;
    }

}

if (is_admin()) {
    $flei_acf_admin_columns = FleiACFAdminColumns::get_instance();
}
