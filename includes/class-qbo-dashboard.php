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
    private $screen_id = 'toplevel_page_gears-dashboard';
    
    public function __construct($core) {
        $this->core = $core;
        
        // Hook into admin_init to register metaboxes for our specific screen
        add_action('load-toplevel_page_gears-dashboard', array($this, 'register_dashboard_metaboxes'));
        
        // Handle AJAX requests for saving metabox order and state
        add_action('wp_ajax_gears_save_dashboard_layout', array($this, 'save_dashboard_layout'));
    }
    
    /**
     * Register dashboard metaboxes
     */
    public function register_dashboard_metaboxes() {
        // Register all dashboard widgets as metaboxes
        add_meta_box(
            'gears_quick_stats',
            'Quick Stats',
            array($this, 'render_quick_stats_metabox'),
            $this->screen_id,
            'normal',
            'high'
        );
        
        add_meta_box(
            'gears_charts',
            'Student Demographics',
            array($this, 'render_charts_metabox'),
            $this->screen_id,
            'normal',
            'default'
        );
        
        add_meta_box(
            'gears_recent_activity',
            'Recent Activity',
            array($this, 'render_recent_activity_metabox'),
            $this->screen_id,
            'normal',
            'default'
        );
        
        add_meta_box(
            'gears_quick_actions',
            'Quick Actions',
            array($this, 'render_quick_actions_metabox'),
            $this->screen_id,
            'side',
            'high'
        );
        
        add_meta_box(
            'gears_system_info',
            'System Information',
            array($this, 'render_system_info_metabox'),
            $this->screen_id,
            'side',
            'default'
        );
        
        add_meta_box(
            'gears_mentors_teams',
            'Mentors & Teams',
            array($this, 'render_mentors_teams_metabox'),
            $this->screen_id,
            'side',
            'default'
        );
    }
    
    /**
     * Save dashboard layout via AJAX
     */
    public function save_dashboard_layout() {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(-1);
        }
        
        // Verify nonce
        check_ajax_referer('gears_dashboard_layout', 'security');
        
        // Get the layout data
        $layout = array();
        
        if (isset($_POST['order'])) {
            $layout['order'] = $_POST['order'];
        }
        
        if (isset($_POST['hidden'])) {
            $layout['hidden'] = $_POST['hidden'];
        }
        
        if (isset($_POST['columns'])) {
            $layout['columns'] = absint($_POST['columns']);
        }
        
        // Save the layout for the current user
        $user_id = get_current_user_id();
        update_user_meta($user_id, 'gears_dashboard_layout', $layout);
        
        wp_die(1); // Success
    }
    
    /**
     * Metabox callback for Quick Stats
     */
    public function render_quick_stats_metabox() {
        echo '<div style="text-align: center; padding: 20px 15px 15px 15px; border-bottom: 1px solid #eee;">';
        echo '<img src="https://gears.org.in/wp-content/uploads/2023/11/gears-logo-transparent.png" alt="GEARS Logo" style="max-width: 200px; height: auto;" />';
        echo '</div>';
        
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
    }
    
    /**
     * Metabox callback for Charts
     */
    public function render_charts_metabox() {
        global $wpdb;
        $student_table = $wpdb->prefix . 'gears_students';
        $teams_table = $wpdb->prefix . 'gears_teams';
        
        // Get gender distribution - first let's see what values actually exist
        $gender_data = $wpdb->get_results("
            SELECT 
                CASE 
                    WHEN TRIM(UPPER(sex)) = 'M' OR TRIM(UPPER(sex)) = 'MALE' THEN 'Male'
                    WHEN TRIM(UPPER(sex)) = 'F' OR TRIM(UPPER(sex)) = 'FEMALE' THEN 'Female'
                    ELSE 'Not Specified'
                END as gender,
                COUNT(*) as count
            FROM $student_table 
            WHERE (grade != 'Alumni' OR grade IS NULL)
            GROUP BY 
                CASE 
                    WHEN TRIM(UPPER(sex)) = 'M' OR TRIM(UPPER(sex)) = 'MALE' THEN 'Male'
                    WHEN TRIM(UPPER(sex)) = 'F' OR TRIM(UPPER(sex)) = 'FEMALE' THEN 'Female'
                    ELSE 'Not Specified'
                END
        ");
        
        // Debug: Let's also get the raw values to see what's actually in the database
        $debug_gender_values = $wpdb->get_results("
            SELECT DISTINCT sex, COUNT(*) as count 
            FROM $student_table 
            WHERE (grade != 'Alumni' OR grade IS NULL)
            GROUP BY sex
        ");
        
        // Add debug info as HTML comment for troubleshooting
        echo '<!-- DEBUG Gender Values: ';
        foreach ($debug_gender_values as $debug_row) {
            echo 'Sex: "' . $debug_row->sex . '" (Count: ' . $debug_row->count . ') ';
        }
        echo '-->';
        
        // Get grade distribution
        $grade_data = $wpdb->get_results("
            SELECT 
                CASE 
                    WHEN grade IS NULL OR grade = '' THEN 'Not Specified'
                    ELSE grade
                END as grade,
                COUNT(*) as count
            FROM $student_table 
            WHERE (grade != 'Alumni' OR grade IS NULL)
            GROUP BY 
                CASE 
                    WHEN grade IS NULL OR grade = '' THEN 'Not Specified'
                    ELSE grade
                END
            ORDER BY count DESC
        ");
        
        // Get program distribution from teams
        $program_data = $wpdb->get_results("
            SELECT 
                CASE 
                    WHEN program IS NULL OR program = '' THEN 'Not Specified'
                    ELSE program
                END as program,
                COUNT(*) as count
            FROM $teams_table 
            WHERE (archived = 0 OR archived IS NULL)
            GROUP BY 
                CASE 
                    WHEN program IS NULL OR program = '' THEN 'Not Specified'
                    ELSE program
                END
            ORDER BY count DESC
        ");
        
        // Get team student distribution
        $team_student_data = $wpdb->get_results("
            SELECT 
                t.team_name,
                COUNT(s.id) as student_count
            FROM $teams_table t
            LEFT JOIN $student_table s ON t.id = s.team_id AND (s.grade != 'Alumni' OR s.grade IS NULL)
            WHERE (t.archived = 0 OR t.archived IS NULL)
            GROUP BY t.id, t.team_name
            HAVING student_count > 0
            ORDER BY student_count DESC
            LIMIT 10
        ");
        
        // Display charts in a responsive grid
        echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">';
        
        // Gender Distribution Chart
        echo '<div>';
        echo '<h4>Gender Distribution</h4>';
        echo '<canvas id="genderChart" width="300" height="200"></canvas>';
        echo '</div>';
        
        // Grade Distribution Chart
        echo '<div>';
        echo '<h4>Grade Distribution</h4>';
        echo '<canvas id="gradeChart" width="300" height="200"></canvas>';
        echo '</div>';
        
        echo '</div>';
        
        // Program and Team charts
        echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">';
        
        // Program Distribution Chart
        echo '<div>';
        echo '<h4>Programs</h4>';
        echo '<canvas id="programChart" width="300" height="200"></canvas>';
        echo '</div>';
        
        // Team Student Distribution Chart
        if (!empty($team_student_data)) {
            echo '<div>';
            echo '<h4>Students per Team</h4>';
            echo '<canvas id="teamStudentChart" width="300" height="200"></canvas>';
            echo '</div>';
        }
        
        echo '</div>';
        
        // Add Chart.js initialization
        echo '<script type="text/javascript">';
        echo 'document.addEventListener("DOMContentLoaded", function() {';
        
        // Gender Chart Data
        $gender_labels = [];
        $gender_counts = [];
        $gender_colors = ['#36A2EB', '#FF6384', '#FFCE56'];
        
        foreach ($gender_data as $row) {
            $gender_labels[] = $row->gender;
            $gender_counts[] = $row->count;
        }
        
        echo 'var genderCtx = document.getElementById("genderChart").getContext("2d");';
        echo 'new Chart(genderCtx, {';
        echo 'type: "doughnut",';
        echo 'data: {';
        echo 'labels: ' . json_encode($gender_labels) . ',';
        echo 'datasets: [{';
        echo 'data: ' . json_encode($gender_counts) . ',';
        echo 'backgroundColor: ' . json_encode(array_slice($gender_colors, 0, count($gender_labels))) . ',';
        echo 'borderWidth: 2,';
        echo 'borderColor: "#fff"';
        echo '}]';
        echo '},';
        echo 'options: {';
        echo 'responsive: true,';
        echo 'maintainAspectRatio: false,';
        echo 'plugins: {';
        echo 'legend: { position: "bottom", labels: { padding: 15, usePointStyle: true } },';
        echo 'tooltip: { callbacks: { label: function(context) { return context.label + ": " + context.parsed + " students"; } } }';
        echo '}';
        echo '}';
        echo '});';
        
        // Grade Chart Data
        $grade_labels = [];
        $grade_counts = [];
        $grade_colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF'];
        
        foreach ($grade_data as $row) {
            $grade_labels[] = $row->grade;
            $grade_counts[] = $row->count;
        }
        
        echo 'var gradeCtx = document.getElementById("gradeChart").getContext("2d");';
        echo 'new Chart(gradeCtx, {';
        echo 'type: "pie",';
        echo 'data: {';
        echo 'labels: ' . json_encode($grade_labels) . ',';
        echo 'datasets: [{';
        echo 'data: ' . json_encode($grade_counts) . ',';
        echo 'backgroundColor: ' . json_encode(array_slice($grade_colors, 0, count($grade_labels))) . ',';
        echo 'borderWidth: 2,';
        echo 'borderColor: "#fff"';
        echo '}]';
        echo '},';
        echo 'options: {';
        echo 'responsive: true,';
        echo 'maintainAspectRatio: false,';
        echo 'plugins: {';
        echo 'legend: { position: "bottom", labels: { padding: 10, usePointStyle: true, font: { size: 11 } } },';
        echo 'tooltip: { callbacks: { label: function(context) { return "Grade " + context.label + ": " + context.parsed + " students"; } } }';
        echo '}';
        echo '}';
        echo '});';
        
        // Program Chart Data
        $program_labels = [];
        $program_counts = [];
        $program_colors = ['#007cba', '#00a32a', '#d63638', '#ff8c00'];
        
        foreach ($program_data as $row) {
            $program_labels[] = $row->program;
            $program_counts[] = $row->count;
        }
        
        echo 'var programCtx = document.getElementById("programChart").getContext("2d");';
        echo 'new Chart(programCtx, {';
        echo 'type: "bar",';
        echo 'data: {';
        echo 'labels: ' . json_encode($program_labels) . ',';
        echo 'datasets: [{';
        echo 'label: "Number of Teams",';
        echo 'data: ' . json_encode($program_counts) . ',';
        echo 'backgroundColor: ' . json_encode(array_slice($program_colors, 0, count($program_labels))) . ',';
        echo 'borderColor: ' . json_encode(array_slice($program_colors, 0, count($program_labels))) . ',';
        echo 'borderWidth: 1';
        echo '}]';
        echo '},';
        echo 'options: {';
        echo 'responsive: true,';
        echo 'maintainAspectRatio: false,';
        echo 'scales: {';
        echo 'y: { beginAtZero: true, ticks: { stepSize: 1 } }';
        echo '},';
        echo 'plugins: {';
        echo 'legend: { display: false },';
        echo 'tooltip: { callbacks: { label: function(context) { return context.parsed.y + " teams"; } } }';
        echo '}';
        echo '}';
        echo '});';
        
        // Team Student Distribution Chart
        if (!empty($team_student_data)) {
            $team_labels = [];
            $team_student_counts = [];
            $team_colors = ['#007cba', '#00a32a', '#d63638', '#ff8c00', '#9966ff', '#ff6384', '#36a2eb', '#ffce56', '#4bc0c0', '#c9cbcf'];
            
            foreach ($team_student_data as $row) {
                $team_labels[] = $row->team_name;
                $team_student_counts[] = $row->student_count;
            }
            
            echo 'var teamStudentCtx = document.getElementById("teamStudentChart").getContext("2d");';
            echo 'new Chart(teamStudentCtx, {';
            echo 'type: "bar",';
            echo 'data: {';
            echo 'labels: ' . json_encode($team_labels) . ',';
            echo 'datasets: [{';
            echo 'label: "Number of Students",';
            echo 'data: ' . json_encode($team_student_counts) . ',';
            echo 'backgroundColor: ' . json_encode(array_slice($team_colors, 0, count($team_labels))) . ',';
            echo 'borderColor: ' . json_encode(array_slice($team_colors, 0, count($team_labels))) . ',';
            echo 'borderWidth: 1';
            echo '}]';
            echo '},';
            echo 'options: {';
            echo 'responsive: true,';
            echo 'maintainAspectRatio: false,';
            echo 'indexAxis: "y",';
            echo 'scales: {';
            echo 'x: { beginAtZero: true, ticks: { stepSize: 1 } }';
            echo '},';
            echo 'plugins: {';
            echo 'legend: { display: false },';
            echo 'tooltip: { callbacks: { label: function(context) { return context.parsed.x + " students"; } } }';
            echo '}';
            echo '}';
            echo '});';
        }
        
        echo '});';
        echo '</script>';
    }
    
    /**
     * Metabox callback for Recent Activity
     */
    public function render_recent_activity_metabox() {
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
    }
    
    /**
     * Metabox callback for Quick Actions
     */
    public function render_quick_actions_metabox() {
        echo '<a href="' . admin_url('admin.php?page=qbo-communications') . '" class="quick-action-btn">Communications</a>';
        echo '<a href="' . admin_url('admin.php?page=qbo-customer-list') . '" class="quick-action-btn">View Customers</a>';
        echo '<a href="' . admin_url('admin.php?page=qbo-recurring-invoices') . '" class="quick-action-btn">Recurring Invoices</a>';
        echo '<a href="' . admin_url('admin.php?page=qbo-teams') . '" class="quick-action-btn">Manage Teams</a>';
        echo '<a href="' . admin_url('admin.php?page=qbo-students') . '" class="quick-action-btn">Manage Students</a>';
        echo '<a href="' . admin_url('admin.php?page=qbo-mentors') . '" class="quick-action-btn">Manage Mentors</a>';
        echo '<a href="' . admin_url('admin.php?page=qbo-reports') . '" class="quick-action-btn">Generate Reports</a>';
        echo '<a href="' . admin_url('admin.php?page=qbo-settings') . '" class="quick-action-btn">Plugin Settings</a>';
    }
    
    /**
     * Metabox callback for System Info
     */
    public function render_system_info_metabox() {
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
    }
    
    /**
     * Metabox callback for Mentors & Teams
     */
    public function render_mentors_teams_metabox() {
        global $wpdb;
        
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
    }
    
    /**
     * Render the dashboard page
     */
    public function dashboard_page() {
        // Enqueue WordPress postbox scripts for draggable metaboxes
        wp_enqueue_script('postbox');
        wp_enqueue_script('dashboard');
        
        // Get current user's layout preferences
        $user_id = get_current_user_id();
        $layout = get_user_meta($user_id, 'gears_dashboard_layout', true);
        if (!is_array($layout)) {
            $layout = array();
        }
        
        // Set up screen options for columns
        $columns = isset($layout['columns']) ? $layout['columns'] : 2;
        ?>
        <div class="wrap">
            <h1>GEARS Billing Dashboard</h1>
            
            <div id="dashboard-widgets-wrap">
                <div id="dashboard-widgets" class="metabox-holder columns-<?php echo esc_attr($columns); ?>">
                    <div class="postbox-container" id="postbox-container-1">
                        <div id="normal-sortables" class="meta-box-sortables ui-sortable">
                            <?php do_meta_boxes($this->screen_id, 'normal', null); ?>
                        </div>
                    </div>
                    
                    <div class="postbox-container" id="postbox-container-2">
                        <div id="side-sortables" class="meta-box-sortables ui-sortable">
                            <?php do_meta_boxes($this->screen_id, 'side', null); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div style="clear: both;"></div>
            
            <?php $this->render_connection_status(); ?>
        </div>
        
        <!-- Include Chart.js from CDN -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        
        <style>
        /* WordPress Metabox Styling */
        .metabox-holder .postbox-container .meta-box-sortables {
            min-height: 300px;
        }
        
        .postbox-container {
            float: left;
        }
        
        .columns-1 .postbox-container {
            width: 100%;
        }
        
        .columns-2 .postbox-container {
            width: 49%;
        }
        
        .columns-2 #postbox-container-2 {
            margin-right: 0;
        }
        
        /* Custom styling for dashboard widgets */
        .postbox .inside {
            margin: 0;
            padding: 12px;
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
        
        /* Chart container styling */
        .postbox canvas {
            max-height: 200px;
        }
        
        .postbox h4 {
            color: #23282d;
            font-size: 13px;
            margin: 0 0 10px 0;
            font-weight: 600;
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
            
            .columns-2 .postbox-container {
                width: 100%;
                margin-right: 0;
            }
            
            /* Stack charts vertically on mobile */
            .postbox .inside > div[style*="grid"] {
                grid-template-columns: 1fr !important;
            }
        }

        @media (max-width: 480px) {
            .stat-box {
                width: 100% !important;
                margin-bottom: 10px;
            }
        }
        </style>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Initialize WordPress postbox functionality
            postboxes.add_postbox_toggles('<?php echo esc_js($this->screen_id); ?>');
            
            // Save layout changes
            $('.meta-box-sortables').on('sortstop', function() {
                var order = {};
                $('.meta-box-sortables').each(function() {
                    var column_id = $(this).attr('id');
                    order[column_id] = $(this).sortable('toArray');
                });
                
                $.post(ajaxurl, {
                    action: 'gears_save_dashboard_layout',
                    security: '<?php echo wp_create_nonce("gears_dashboard_layout"); ?>',
                    order: order
                });
            });
            
            // Save column layout changes
            $('input[name="screen_columns"]').change(function() {
                var columns = $(this).val();
                $('#dashboard-widgets').removeClass('columns-1 columns-2').addClass('columns-' + columns);
                
                $.post(ajaxurl, {
                    action: 'gears_save_dashboard_layout',
                    security: '<?php echo wp_create_nonce("gears_dashboard_layout"); ?>',
                    columns: columns
                });
            });
        });
        </script>
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
}
