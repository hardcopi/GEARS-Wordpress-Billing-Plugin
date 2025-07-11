<?php
/**
 * Manual table creation script
 * Run this to create the missing database tables
 */

// Include WordPress
require_once '../../../wp-config.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

// Get database connection
global $wpdb;

$charset_collate = $wpdb->get_charset_collate();

echo "Creating database tables...\n";

// Create gears_teams table
$table_teams = $wpdb->prefix . 'gears_teams';

$sql_teams = "CREATE TABLE $table_teams (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    team_name varchar(255) NOT NULL,
    team_number varchar(50) DEFAULT '',
    program varchar(255) DEFAULT '',
    description text,
    logo varchar(255) DEFAULT '',
    team_photo varchar(255) DEFAULT '',
    facebook varchar(255) DEFAULT '',
    twitter varchar(255) DEFAULT '',
    instagram varchar(255) DEFAULT '',
    website varchar(255) DEFAULT '',
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) $charset_collate;";

dbDelta($sql_teams);
echo "Created/updated table: $table_teams\n";

// Create gears_mentors table
$table_mentors = $wpdb->prefix . 'gears_mentors';

$sql_mentors = "CREATE TABLE $table_mentors (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    mentor_name varchar(255) NOT NULL,
    email varchar(255) DEFAULT '',
    phone varchar(50) DEFAULT '',
    team_id mediumint(9) DEFAULT NULL,
    bio text,
    specialties text,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY team_id (team_id)
) $charset_collate;";

dbDelta($sql_mentors);
echo "Created/updated table: $table_mentors\n";

// Create gears_students table
$table_students = $wpdb->prefix . 'gears_students';

$sql_students = "CREATE TABLE $table_students (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    first_name varchar(255) NOT NULL,
    last_name varchar(255) NOT NULL,
    grade varchar(50) DEFAULT '',
    team_id mediumint(9) DEFAULT NULL,
    customer_id varchar(50) DEFAULT NULL,
    first_year_first year DEFAULT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY team_id (team_id),
    KEY customer_id (customer_id),
    KEY student_name (first_name, last_name)
) $charset_collate;";

dbDelta($sql_students);
echo "Created/updated table: $table_students\n";

echo "Database tables created successfully!\n";

// Check if tables exist
$tables = array($table_teams, $table_mentors, $table_students);
foreach ($tables as $table) {
    $result = $wpdb->get_var("SHOW TABLES LIKE '$table'");
    if ($result) {
        echo "✓ Table $table exists\n";
    } else {
        echo "✗ Table $table does not exist\n";
    }
}

echo "\nDone!\n";
