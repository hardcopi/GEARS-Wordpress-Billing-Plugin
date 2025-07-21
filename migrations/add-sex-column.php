<?php
// Migration script to add 'sex' column to gears_students table if it does not exist
// Usage: Run this script once in your WordPress environment (e.g., wp-cli eval-file or include in a maintenance page)

// Ensure WordPress is loaded so $wpdb is available
if (!defined('ABSPATH')) {
    $wp_load_path = '../../../../wp-load.php';
    print $wp_load_path . "<br>";
    if (file_exists($wp_load_path)) {
        require_once $wp_load_path;
    } else {
        exit("Could not find wp-load.php. Please run this script from within your WordPress installation.\n");
    }
}

global $wpdb;
$table = $wpdb->prefix . 'gears_students';
$column = 'sex';

$column_exists = $wpdb->get_results($wpdb->prepare(
    "SHOW COLUMNS FROM `$table` LIKE %s",
    $column
));

if (empty($column_exists)) {
    $alter = $wpdb->query(
        "ALTER TABLE `$table` ADD `sex` VARCHAR(16) NULL AFTER `tshirt_size`"
    );
    if ($alter !== false) {
        echo "Column 'sex' added successfully.";
    } else {
        echo "Failed to add column 'sex'. Error: " . $wpdb->last_error;
    }
} else {
    echo "Column 'sex' already exists.";
}
