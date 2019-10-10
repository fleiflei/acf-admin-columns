# Admin Columns for ACF Fields 
![Admin Columns For ACF Fields](screenshot-1.png "ScreenShot")
## Installation 
Depends on Advance Custom Fields plugin.
This section describes how to install the plugin and get it working.

1. Upload the plugin files to the `/wp-content/plugins/plugin-name` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Open ACF and enable "Admin Column" in any fields settings section.

Contributors: flei
Tags: advanced custom fields, acf, admin columns
Requires at least: 4.6
Tested up to: 5.1.1
Stable tag: 4.3
Requires PHP: 5.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Date: 01.10.2019
Version: 0.1

Allows you to enable columns for your ACF fields in post and taxonomy overviews (e.g. "All Posts") in the Wordpress admin backend. This plugin requires a recent version of plugin "Advanced Custom Fields" (ACF).

## Description 

Use this plugin to show ACF fields in the "All Posts" table view in the Wordpress admin backend.

Simply enable the new option "Admin Column" in your ACF field settings for any regular field (see exceptions below). Now there will be an extra column for your field shown in any overview of posts, pages, taxonomies or your custom post types or taxonomies (e.g. "All Pages").

You can use filters (see below) to control the plugins behaviour even more precisely.

Works on any regular ACF field (see exceptions below).
 
Compatible with Advanced Custom Fields 5.x 

Github: https://www.github.com/fleiflei/

## Usage: 

1. Install ACF and this plugin (see below)
2. In ACF open/create your "field group" within ACF and note the post type that this field group applies to (at the bottom). 
3. Open any field for editing (see exceptions below).
4. Enable the "Admin Column" option in the field settings. 
5. Enable post types and/or taxonomies for which the column should be shown.
6. Save the field group and go to the overview page of the post type or taxonomy (e.g. "Posts > All Posts", or "Pages > All Pages") that you noted above and notice the newly added column for your field.

## Advanced Usage ##

### Excluded ACF Fields ###

Due to their nature the option "Admin Column" is not shown in ACF for these fields:

 - Accordion
 - Clone
 - Flexible Content
 - Google Map
 - Group
 - Message
 - Repeater
 - Tab

### Change how the returned column value is displayed ###
Use filter "acf/admin_columns/$field_name" to alter the value that is returned 

## Filters ##
### acf/admin_columns/admin_columns ###
Allows you to change which columns are displayed on the current admin screen. 

#### Parameters ####
$fields - Array of all ACF fields to be shown in current screen.  

### acf/admin_columns/sortable_columns ###
Change which columns should be sortable. By default every column is sortable. 

#### Parameters ####
$columns - Array of all ACF fields to be shown in current screen.  

### acf/admin_columns/column/$field ###
Allows you to modify the output of a certain $field in every row of a posts table.

#### Parameters ####
$field_value - The field value   




== Frequently Asked Questions ==

None yet, feel free to ask.
