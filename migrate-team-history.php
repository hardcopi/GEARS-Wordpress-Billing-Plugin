<?php
/**
 * Manual Migration Script for Student Team History
 * 
 * Run this file directly in the browser ONCE to create the team history table
 * URL: your-site.com/wp-content/plugins/qbo-recurring-billing/migrate-team-history.php
 */

// Include WordPress
require_once '../../../wp-load.php';

// Check if user is admin
if (!current_user_can('manage_options')) {
    die('Access denied. You must be an administrator.');
}

echo '<h1>Student Team History Migration</h1>';
echo '<pre>';

// Include the core class
require_once 'includes/class-qbo-core.php';

try {
    $core = new QBO_Core();
    
    echo "1. Creating student team history table...\n";
    $core->create_database_tables();
    echo "✅ Database tables created/updated successfully!\n\n";
    
    echo "2. Running student migration...\n";
    $core->migrate_students_to_history();
    echo "✅ Student migration completed!\n\n";
    
    // Check results
    global $wpdb;
    $table_student_team_history = $wpdb->prefix . 'gears_student_team_history';
    
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_student_team_history");
    echo "📊 Total team history records: $count\n";
    
    if ($count > 0) {
        $sample = $wpdb->get_results("SELECT * FROM $table_student_team_history LIMIT 3");
        echo "\n📄 Sample records:\n";
        foreach ($sample as $record) {
            echo "- Student ID: {$record->student_id}, Team ID: {$record->team_id}, Program: {$record->program}, Current: {$record->is_current}\n";
        }
    }
    
    echo "\n🎉 Migration completed successfully!\n";
    echo "You can now use the team history feature in the student edit modal.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo '</pre>';
echo '<p><a href="' . admin_url('admin.php?page=qbo-students') . '">Go to Students Management</a></p>';
?>
