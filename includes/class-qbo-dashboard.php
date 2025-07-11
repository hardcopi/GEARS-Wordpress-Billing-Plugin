<?php
/**
 * QBO Dashboard Class
 * 
 * Handles the main dashboard page with overview and quick stats
 */

if (!defined('ABSPATH')) {
    exit;
}

class QBO_Dashboard {
    
    private $core;
    
    public function __construct($core) {
        $this->core = $core;
    }
    
    /**
     * Render the dashboard page
     */
    public function dashboard_page() {
        ?>
        <div class="wrap">
            <h1>GEARS Billing Dashboard</h1>
            
            <div class="dashboard-widgets-wrap">
                <div id="dashboard-widgets" class="metabox-holder">
                    <div class="postbox-container" style="width: 49%; float: left; margin-right: 2%;">
                        <?php $this->render_quick_stats(); ?>
                        <?php $this->render_recent_activity(); ?>
                    </div>
                    <div class="postbox-container" style="width: 49%; float: left;">
                        <?php $this->render_quick_actions(); ?>
                        <?php $this->render_system_info(); ?>
                        <?php $this->render_mentors_and_teams(); ?>
                    </div>
                </div>
            </div>
            
            <div style="clear: both;"></div>
            
            <?php $this->render_connection_status(); ?>
        </div>
        
        <style>
        .dashboard-widget {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            margin-bottom: 20px;
            padding: 0;
        }
        .dashboard-widget h3 {
            background: #f6f7f7;
            border-bottom: 1px solid #c3c4c7;
            margin: 0;
            padding: 8px 15px;
            font-size: 14px;
            line-height: 1.4;
        }
        .dashboard-widget-content {
            padding: 15px;
        }
        .stat-box {
            text-align: center;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #0073aa;
        }
        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }
        .connection-status {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .status-connected {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .status-disconnected {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .quick-action-btn {
            display: block;
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            text-align: center;
            text-decoration: none;
            background: #0073aa;
            color: white;
            border-radius: 4px;
        }
        .quick-action-btn:hover {
            background: #005a87;
            color: white;
        }
        
        /* Responsive design for stat boxes */
        @media (max-width: 1200px) {
            .stat-box {
                width: 31% !important;
                margin-bottom: 10px;
            }
        }
        
        @media (max-width: 768px) {
            .stat-box {
                width: 48% !important;
                margin-bottom: 10px;
            }
        }
        
        @media (max-width: 480px) {
            .stat-box {
                width: 100% !important;
                margin-bottom: 10px;
            }
        }
        </style>
        <?php
    }
    
    /**
     * Render connection status
     */
    private function render_connection_status() {
        $options = get_option($this->core->get_option_name());
        $is_connected = isset($options['access_token']) && !empty($options['access_token']);
        
        $status_class = $is_connected ? 'status-connected' : 'status-disconnected';
        $status_text = $is_connected ? '✓ Connected to QuickBooks Online' : '✗ Not connected to QuickBooks Online';
        $status_message = $is_connected ? 
            'Your QuickBooks Online integration is active and working properly.' : 
            'Please configure your QuickBooks Online credentials in Settings to enable billing features.';
        
        echo '<div class="connection-status ' . $status_class . '">';
        echo '<h3>' . $status_text . '</h3>';
        echo '<p>' . $status_message . '</p>';
        echo '</div>';
    }
    
    /**
     * Render quick stats widget
     */
    private function render_quick_stats() {
        echo '<div class="dashboard-widget">';
        echo '<div style="text-align: center; padding: 20px 15px 15px 15px; border-bottom: 1px solid #eee;">';
        echo '<img src="https://gears.org.in/wp-content/uploads/2023/11/gears-logo-transparent.png" alt="GEARS Logo" style="max-width: 200px; height: auto;" />';
        echo '</div>';
        echo '<h3>Quick Stats</h3>';
        echo '<div class="dashboard-widget-content">';
        
        // Get student counts (active vs alumni)
        global $wpdb;
        $student_table = $wpdb->prefix . 'gears_students';
        $active_student_count = $wpdb->get_var("SELECT COUNT(*) FROM $student_table WHERE (grade != 'Alumni' OR grade IS NULL)");
        $alumni_count = $wpdb->get_var("SELECT COUNT(*) FROM $student_table WHERE grade = 'Alumni'");
        
        // Get team counts (active vs archived)
        $table_name = $wpdb->prefix . 'gears_teams';
        
        // Check if archived column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'archived'");
        
        if (!empty($column_exists)) {
            // Column exists, count active and archived teams separately
            $active_team_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE (archived = 0 OR archived IS NULL)");
            $archived_team_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE archived = 1");
        } else {
            // Column doesn't exist yet, all teams are considered active
            $active_team_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            $archived_team_count = 0;
        }
        
        // Get mentor count
        $mentor_table = $wpdb->prefix . 'gears_mentors';
        $mentor_count = $wpdb->get_var("SELECT COUNT(*) FROM $mentor_table");
        
        echo '<div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;">';
        
        echo '<div class="stat-box" style="width: 19%;">';
        echo '<div class="stat-number">' . ($active_student_count ?: 0) . '</div>';
        echo '<div class="stat-label">Active Students</div>';
        echo '</div>';
        
        echo '<div class="stat-box" style="width: 19%;">';
        echo '<div class="stat-number">' . ($alumni_count ?: 0) . '</div>';
        echo '<div class="stat-label">Alumni</div>';
        echo '</div>';
        
        echo '<div class="stat-box" style="width: 19%;">';
        echo '<div class="stat-number">' . ($active_team_count ?: 0) . '</div>';
        echo '<div class="stat-label">Active Teams</div>';
        echo '</div>';
        
        echo '<div class="stat-box" style="width: 19%;">';
        echo '<div class="stat-number">' . ($archived_team_count ?: 0) . '</div>';
        echo '<div class="stat-label">Past Teams</div>';
        echo '</div>';
        
        echo '<div class="stat-box" style="width: 19%;">';
        echo '<div class="stat-number">' . ($mentor_count ?: 0) . '</div>';
        echo '<div class="stat-label">Mentors</div>';
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Render recent activity widget
     */
    private function render_recent_activity() {
        echo '<div class="dashboard-widget">';
        echo '<h3>Recent Activity</h3>';
        echo '<div class="dashboard-widget-content">';
        
        // Get cache info to show last sync times
        $customer_cache = get_option('qbo_recurring_billing_customers_cache', array());
        $last_customer_sync = isset($customer_cache['timestamp']) ? 
            date('M j, Y H:i:s', $customer_cache['timestamp']) : 'Never';
        
        echo '<ul style="margin: 0; padding-left: 20px;">';
        echo '<li><strong>Last Customer Sync:</strong> ' . esc_html($last_customer_sync) . '</li>';
        
        // Show token refresh info if available
        $options = get_option($this->core->get_option_name());
        if (isset($options['token_refreshed_at'])) {
            $last_token_refresh = date('M j, Y H:i:s', $options['token_refreshed_at']);
            echo '<li><strong>Last Token Refresh:</strong> ' . esc_html($last_token_refresh) . '</li>';
        }
        
        echo '<li><strong>Plugin Version:</strong> ' . esc_html($this->core->get_version()) . '</li>';
        echo '</ul>';
        
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Render system info widget
     */
    private function render_system_info() {
        echo '<div class="dashboard-widget">';
        echo '<h3>System Information</h3>';
        echo '<div class="dashboard-widget-content">';
        
        $options = get_option($this->core->get_option_name());
        
        echo '<table class="form-table">';
        
        echo '<tr>';
        echo '<th>QuickBooks Company ID:</th>';
        echo '<td>' . esc_html($options['realm_id'] ?? 'Not configured') . '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>Access Token Status:</th>';
        echo '<td>' . (isset($options['access_token']) && !empty($options['access_token']) ? 
            '<span style="color: green;">Active</span>' : 
            '<span style="color: red;">Missing</span>') . '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>Refresh Token:</th>';
        echo '<td>' . (isset($options['refresh_token']) && !empty($options['refresh_token']) ? 
            '<span style="color: green;">Available</span>' : 
            '<span style="color: orange;">Not available</span>') . '</td>';
        echo '</tr>';
        
        echo '</table>';
        
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Render quick actions widget
     */
    private function render_quick_actions() {
        echo '<div class="dashboard-widget">';
        echo '<h3>Quick Actions</h3>';
        echo '<div class="dashboard-widget-content">';
        
        echo '<a href="' . admin_url('admin.php?page=qbo-customer-list') . '" class="quick-action-btn">View Customers</a>';
        echo '<a href="' . admin_url('admin.php?page=qbo-teams') . '" class="quick-action-btn">Manage Teams</a>';
        echo '<a href="' . admin_url('admin.php?page=qbo-mentors') . '" class="quick-action-btn">Manage Mentors</a>';
        echo '<a href="' . admin_url('admin.php?page=qbo-settings') . '" class="quick-action-btn">Plugin Settings</a>';
        
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Render mentors and teams lists
     */
    private function render_mentors_and_teams() {
        global $wpdb;
        
        echo '<div class="dashboard-widget">';
        echo '<h3>Mentors & Teams</h3>';
        echo '<div class="dashboard-widget-content">';
        
        // Get mentors
        $mentor_table = $wpdb->prefix . 'gears_mentors';
        $mentors = $wpdb->get_results("SELECT * FROM $mentor_table ORDER BY mentor_name LIMIT 5");
        
        echo '<h4 style="margin-top: 0;">Recent Mentors</h4>';
        if (!empty($mentors)) {
            echo '<ul style="margin: 0 0 15px 0; padding-left: 20px;">';
            foreach ($mentors as $mentor) {
                echo '<li>' . esc_html($mentor->mentor_name);
                if (!empty($mentor->email)) {
                    echo ' <small>(' . esc_html($mentor->email) . ')</small>';
                }
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p style="margin: 0 0 15px 0; color: #666;"><em>No mentors found.</em></p>';
        }
        
        // Get teams
        $teams_table = $wpdb->prefix . 'gears_teams';
        $teams = $wpdb->get_results("SELECT * FROM $teams_table ORDER BY team_name LIMIT 5");
        
        echo '<h4>Recent Teams</h4>';
        if (!empty($teams)) {
            echo '<ul style="margin: 0; padding-left: 20px;">';
            foreach ($teams as $team) {
                echo '<li>' . esc_html($team->team_name);
                if (!empty($team->team_number)) {
                    echo ' <small>(#' . esc_html($team->team_number) . ')</small>';
                }
                if (!empty($team->program)) {
                    echo ' <small>[' . esc_html($team->program) . ']</small>';
                }
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p style="margin: 0; color: #666;"><em>No teams found.</em></p>';
        }
        
        echo '</div>';
        echo '</div>';
    }
}
