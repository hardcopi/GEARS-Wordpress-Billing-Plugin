<?php
/**
 * Simple test script to check if the plugin database setup works
 * This simulates plugin activation to test table creation
 */

// Simulate WordPress environment (minimal)
define('ABSPATH', 'e:/Code/Wordpress/');

// Mock WordPress functions for testing
function get_option($option, $default = false) {
    return $default;
}

function add_action($hook, $callback, $priority = 10, $args = 1) {
    // Mock function
}

function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $function = '', $icon_url = '', $position = null) {
    // Mock function
}

function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function = '') {
    // Mock function
}

function register_setting($option_group, $option_name, $args = '') {
    // Mock function
}

function add_settings_section($id, $title, $callback, $page) {
    // Mock function
}

function add_settings_field($id, $title, $callback, $page, $section = 'default', $args = array()) {
    // Mock function
}

function register_activation_hook($file, $function) {
    // For testing, we'll call the function directly
    if (is_array($function)) {
        call_user_func($function);
    }
}

// Mock wpdb
class MockWPDB {
    public $prefix = 'wp_';
    
    public function get_charset_collate() {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
    }
    
    public function insert($table, $data, $format = null) {
        echo "INSERT INTO $table: " . print_r($data, true) . "\n";
        return 1; // Mock success
    }
    
    public function update($table, $data, $where, $format = null, $where_format = null) {
        echo "UPDATE $table: " . print_r($data, true) . " WHERE " . print_r($where, true) . "\n";
        return 1; // Mock success
    }
    
    public function delete($table, $where, $where_format = null) {
        echo "DELETE FROM $table WHERE " . print_r($where, true) . "\n";
        return 1; // Mock success
    }
    
    public function get_results($query) {
        echo "QUERY: $query\n";
        return array(); // Mock empty result
    }
    
    public function get_var($query) {
        echo "QUERY: $query\n";
        return 0; // Mock count
    }
    
    public function prepare($query) {
        return $query; // Simple mock
    }
}

$wpdb = new MockWPDB();

// Mock dbDelta function
function dbDelta($sql) {
    echo "Creating table with SQL:\n";
    echo $sql . "\n\n";
    return array('wp_gears_teams' => 'Created table wp_gears_teams', 'wp_gears_mentors' => 'Created table wp_gears_mentors');
}

// Now include and test the plugin
echo "Testing QuickBooks Online Recurring Billing Plugin Database Setup\n";
echo "================================================================\n\n";

// Include the plugin
include 'qbo-recurring-billing.php';

echo "\nPlugin loaded successfully!\n";
echo "Database tables should have been created above.\n";
