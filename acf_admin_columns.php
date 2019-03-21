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

    private static $instance;

    private $exclude_field_types = array(
        'tab'      => 'tab',
        'clone'    => 'clone',
        'repeater' => 'repeater',
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

                    $this->admin_columns[$field['name']] = $field['label'];
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

        $orderby = $query->query_vars['orderby'];

        if (array_key_exists($orderby, $this->admin_columns)) {

            // this makes sure we sort also when the custom field has never been set on some posts before
            $meta_query = array(
                'relation' => 'OR',
                array('key' => $orderby, 'compare' => 'NOT EXISTS'), // 'NOT EXISTS' needs to go first for proper sorting
                array('key' => $orderby, 'compare' => 'EXISTS'),
            );

            $query->set('meta_query', $meta_query);
            $query->set('orderby', 'meta_value');
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
            $columns[($idx)] = $idx;
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
            $field_value = get_field($column, $post_id);

            $field_value = apply_filters('acf/admin_columns/column/' . $column, $field_value);

            echo $field_value;
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
                    if (!isset($exclude[$type])) {
                        add_action('acf/render_field_settings/type=' . $type, array($this, 'render_field_settings'), 1);
                    }
                }
            }
        } else {
            // >= 5.5.0 || < 5.6.0
            foreach ($sections as $type => $settings) {
                if (!isset($exclude[$type])) {
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

}

if (is_admin()) {
    $flei_acf_admin_columns = FleiACFAdminColumns::get_instance();
}
