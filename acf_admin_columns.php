<?php
/**
 * Plugin Name: Admin Columns for ACF Fields
 * Plugin URI: https://wordpress.org/plugins/user-role-field-setting-for-acf/
 * Description: Add columns to the admin area of
 * Version: 0.1
 * Author: Florian Eickhorst
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

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('acf/init', array($this, 'add_acf_actions')); // add ACF fields
        add_action('parse_request', array($this, 'prepare_columns'), 10);
        add_action('pre_get_posts', array($this, 'action_prepare_query_sort'));
    }

    /**
     * Checks which columns to show on the current screen and attaches to the respective WP hooks
     */
    public function prepare_columns()
    {
        if (!$this->is_acf_active()) {
            return;
        }

        $screen = get_current_screen();

        if ($screen && $screen->base == 'edit' && $ptype = $screen->post_type) {

            // get all field groups for the current post type and check every containing field if it should become a

            $field_groups = acf_get_field_groups(array('post_type' => $ptype));

            foreach ($field_groups as $fgroup) {

                $fgroup_fields = acf_get_fields($fgroup);
                foreach ($fgroup_fields as $field) {
                    if (!isset($field[self::ACF_SETTING_NAME]) || $field[self::ACF_SETTING_NAME] == false) {
                        continue;
                    }

                    $this->admin_columns[$this->get_clean_column($field['name'])] = $field['label'];
                }

            }

            $this->admin_columns = apply_filters('acf/admin_columns/admin_columns', $this->admin_columns);

            if (!empty($this->admin_columns)) {
                add_filter('manage_' . $ptype . '_posts_columns', array($this, 'filter_manage_posts_columns')); // creates the columns
                add_filter('manage_' . $screen->id . '_sortable_columns', array($this, 'filter_manage_sortable_columns')); // make columns sortable
                add_action('manage_' . $ptype . '_posts_custom_column', array($this, 'action_manage_posts_custom_column'), 10, 2); // outputs the columns values for each post
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

        if ($query->query_vars && isset($query->query_vars['orderby'])) {
            $orderby = $query->query_vars['orderby'];

            if (array_key_exists($orderby, $this->admin_columns)) {

                // this makes sure we sort also when the custom field has never been set on some posts before
                $meta_query = array(
                    'relation' => 'OR',
                    array('key' => $this->get_clean_column($orderby), 'compare' => 'NOT EXISTS'), // 'NOT EXISTS' needs to go first for proper sorting
                    array('key' => $this->get_clean_column($orderby), 'compare' => 'EXISTS'),
                );

                $query->set('meta_query', $meta_query);
                $query->set('orderby', 'meta_value');
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
     * Displays the posts value inside of a columns cell
     *
     * @param $column
     * @param $post_id
     */
    public function action_manage_posts_custom_column($column, $post_id)
    {

        if (array_key_exists($column, $this->admin_columns)) {

            $clean_column = $this->get_clean_column($column);
            $field_value = get_field($clean_column, $post_id);

            $field_value = $this->render_column_field($column, $post_id);

//            $field_value = acf_format_value($field_value, $post_id, true);
            $field_value = apply_filters('acf/admin_columns/column/' . $clean_column, $field_value);

            echo $field_value;
        }
    }

    public function render_column_field($column, $post_id)
    {

        $clean_column = $this->get_clean_column($column);
        $field_value = get_field($clean_column, $post_id);

        if ($field_value) {
            $render_output = '';

            $field_properties = acf_get_field($clean_column, $post_id);
            $field_images = $field_value;
            $items_more = 0;

            switch ($field_properties['type']) {
                case 'color_picker':
                    $render_output .= '<div style="height:20px;width:100%;display:inline-block;background-color:' . $field_value . '">' . $field_value . '</div><br>';
                    break;
                case 'taxonomy': //@todo
                    $render_output = $field_value;
                    break;
                case 'file':
                    $render_output = $field_value['filename'] ?: ''; // @todo multiple values
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
                        $items_more = count($field_value) - 1;
                    }
                    $render_output = '<a href="' . get_edit_post_link($p, false) . '">' . $p->post_title . '</a>';

                    break;
                case 'user':
                    $u = $field_value;
                    if (is_array($field_value) && !empty($field_value)) {
                        $u = $field_value[0];
                        $items_more = count($field_value) - 1;
                    }
                    $render_output = '<a href="' . get_edit_user_link($u) . '">' . $u['display_name'] . '</a>';
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
                            $img = wp_get_attachment_image_src($preview_image_id, 'thumbnail');
                            if (is_array($img) && isset($img[0])) {
                                $preview_image_url = $img[0];
                            }
                        }

                        if ($preview_image_url) {
                            $render_output = "<img style='width:100%;height:auto;' src='$preview_image_url'>";
                        }

                        $items_more = count($field_value) - 1;
                    }
                    break;

                case 'text':
                case 'textarea':
                case 'number':
                case 'range':
                case 'email':
                case 'url':
                case 'password':
                case 'select':
                case 'checkbox':
                case 'radio':
                case 'button_group':
                case 'true_false':
                case 'page_link':
                case 'date_picker':
                case 'time_picker':
                default:
                    $render_output = $field_value;
            }

            if (is_array($render_output)) {
                $render_output = implode(', ', $render_output);
            }

            if ($items_more) {
                $render_output .= "<br>and $items_more more";
            }

            return $render_output;
        }
    }

    /**
     * Add ACF hooks and handle versions
     */
    public function add_acf_actions()
    {
        if (!$this->is_acf_active()) {
            return;
        }
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

    public function render_field_settings($field)
    {
        $args = array(
            'type'         => 'true_false',
            'ui'           => 1,
            'label'        => 'Admin Column',
            'name'         => self::ACF_SETTING_NAME,
            'instructions' => 'Show this field as a column in the admin post list.',
        );
        acf_render_field_setting($field, $args, false);
    }

    private function is_acf_active()
    {
        return (function_exists('acf_get_field_groups') && function_exists('acf_get_fields'));
    }

    /**
     * Return the "real" ACF field name, without the prefix
     * @param $dirty_column
     * @return mixed
     */
    private function get_clean_column($dirty_column) {
        $clean_column = str_replace(self::COLUMN_NAME_PREFIX, '', $dirty_column);
        return $clean_column;
    }

}

if (is_admin()) {
    $flei_acf_admin_columns = FleiACFAdminColumns::get_instance();
}
