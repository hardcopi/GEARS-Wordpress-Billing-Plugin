<?php
/**
 * QBO Teams Class
 * 
 * Handles teams management functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class QBO_Teams {
    
    private $core;
    private $database;
    
    private $teams_new_fields = [
        'logo' => 'VARCHAR(255)',
        'team_photo' => 'VARCHAR(255)',
        'facebook' => 'VARCHAR(255)',
        'twitter' => 'VARCHAR(255)',
        'instagram' => 'VARCHAR(255)',
        'website' => 'VARCHAR(255)',
        'archived' => 'TINYINT(1) DEFAULT 0',
        'hall_of_fame' => 'TINYINT(1) DEFAULT 0'
    ];
    
    public function __construct($core, $database) {
        $this->core = $core;
        $this->database = $database;
        
        // Ensure database schema is up to date
        $this->ensure_database_schema();
        
        // AJAX handlers
        add_action('wp_ajax_qbo_add_team', array($this, 'ajax_add_team'));
        add_action('wp_ajax_qbo_update_team', array($this, 'ajax_update_team'));
        add_action('wp_ajax_qbo_edit_team', array($this, 'ajax_edit_team'));
        add_action('wp_ajax_qbo_get_team_students', array($this, 'ajax_get_team_students'));
        add_action('wp_ajax_qbo_get_team_mentors', array($this, 'ajax_get_team_mentors'));
        add_action('wp_ajax_get_mentor_data', array($this, 'ajax_get_mentor_data'));
        add_action('wp_ajax_delete_mentor', array($this, 'ajax_delete_mentor'));
        add_action('wp_ajax_get_student_data', array($this, 'ajax_get_student_data'));
        add_action('wp_ajax_delete_student', array($this, 'ajax_delete_student'));
        add_action('wp_ajax_qbo_archive_team', array($this, 'ajax_archive_team'));
        add_action('wp_ajax_qbo_restore_team', array($this, 'ajax_restore_team'));
        add_action('wp_ajax_qbo_get_team_data', array($this, 'ajax_get_team_data'));
        // Register the new invoices page
        add_action('admin_menu', array($this, 'register_invoices_page'));
    }
    
    /**
     * Ensure database schema is up to date
     */
    private function ensure_database_schema() {
        global $wpdb;
        
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        // Check if archived column exists, if not add it
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_teams LIKE 'archived'");
        
        if (empty($column_exists)) {
            // Add the archived column
            $wpdb->query("ALTER TABLE $table_teams ADD COLUMN archived TINYINT(1) DEFAULT 0");
        }
        
        // Check if hall_of_fame column exists, if not add it
        $hall_of_fame_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_teams LIKE 'hall_of_fame'");
        
        if (empty($hall_of_fame_exists)) {
            // Add the hall_of_fame column
            $wpdb->query("ALTER TABLE $table_teams ADD COLUMN hall_of_fame TINYINT(1) DEFAULT 0");
        }
    }
    
    /**
     * Render teams page
     */
    public function render_page() {
        // Check if we're viewing a team's details
        if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['team_id'])) {
            $this->render_team_details_page(intval($_GET['team_id']));
            return;
        }
        
        global $wpdb;
        
        $table_teams = $wpdb->prefix . 'gears_teams';
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        
        // Handle form submissions
        if ($_POST) {
            $this->handle_form_submissions($table_teams, $table_mentors);
        }
        
        // Get all teams (excluding archived ones if the column exists)
        // First check if the archived column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_teams LIKE 'archived'");
        
        if (!empty($column_exists)) {
            // Column exists, filter out archived teams for active list
            $teams = $wpdb->get_results("SELECT * FROM $table_teams WHERE (archived = 0 OR archived IS NULL) ORDER BY team_name");
            // Get archived teams separately
            $archived_teams = $wpdb->get_results("SELECT * FROM $table_teams WHERE archived = 1 ORDER BY team_name");
        } else {
            // Column doesn't exist yet, get all teams as active
            $teams = $wpdb->get_results("SELECT * FROM $table_teams ORDER BY team_name");
            $archived_teams = array();
        }
        
        $this->render_page_html($teams, $archived_teams);
    }
    
    /**
     * Handle form submissions
     */
    private function handle_form_submissions($table_teams, $table_mentors) {
        global $wpdb;
        
        if (isset($_POST['add_team'])) {
            if (isset($_POST['team_nonce']) && wp_verify_nonce($_POST['team_nonce'], 'add_team_action')) {
                $this->handle_add_team($table_teams);
            } else {
                echo '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
            }
        } elseif (isset($_POST['add_mentor'])) {
            if (isset($_POST['mentor_nonce']) && wp_verify_nonce($_POST['mentor_nonce'], 'add_mentor_action')) {
                $this->handle_add_mentor($table_mentors);
            } else {
                echo '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
            }
        } elseif (isset($_POST['edit_mentor'])) {
            if (isset($_POST['edit_mentor_nonce']) && wp_verify_nonce($_POST['edit_mentor_nonce'], 'edit_mentor_action')) {
                $this->handle_edit_mentor($table_mentors);
            } else {
                echo '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
            }
        } elseif (isset($_POST['add_student'])) {
            if (isset($_POST['student_nonce']) && wp_verify_nonce($_POST['student_nonce'], 'add_student_action')) {
                $this->handle_add_student();
            } else {
                echo '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
            }
        } elseif (isset($_POST['edit_student'])) {
            if (isset($_POST['edit_student_nonce']) && wp_verify_nonce($_POST['edit_student_nonce'], 'edit_student_action')) {
                $this->handle_edit_student();
            } else {
                echo '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
            }
        } elseif (isset($_POST['update_team']) && wp_verify_nonce($_POST['team_edit_nonce'], 'update_team_action')) {
            $this->handle_update_team($table_teams);
        } elseif (isset($_POST['delete_team']) && wp_verify_nonce($_POST['delete_nonce'], 'delete_team_action')) {
            $this->handle_delete_team($table_teams, $table_mentors);
        }
    }
    
    /**
     * Handle add team
     */
    private function handle_add_team($table_teams) {
        global $wpdb;
        
        $team_name = sanitize_text_field($_POST['team_name']);
        $team_number = sanitize_text_field($_POST['team_number']);
        $program = sanitize_text_field($_POST['program']);
        $description = sanitize_textarea_field($_POST['description']);
        $facebook = sanitize_text_field($_POST['facebook']);
        $twitter = sanitize_text_field($_POST['twitter']);
        $instagram = sanitize_text_field($_POST['instagram']);
        $website = esc_url_raw($_POST['website']);
        $hall_of_fame = isset($_POST['hall_of_fame']) ? 1 : 0;
        $logo = '';
        $team_photo = '';
        
        // Handle file uploads
        if (!empty($_FILES['logo']['name'])) {
            $uploaded = media_handle_upload('logo', 0);
            if (!is_wp_error($uploaded)) {
                $logo = wp_get_attachment_url($uploaded);
            }
        }
        if (!empty($_FILES['team_photo']['name'])) {
            $uploaded = media_handle_upload('team_photo', 0);
            if (!is_wp_error($uploaded)) {
                $team_photo = wp_get_attachment_url($uploaded);
            }
        }
        
        $errors = array();
        if (empty($team_name)) {
            $errors[] = 'Team name is required.';
        }
        
        // Check for duplicate team name
        if (!empty($team_name)) {
            $existing_team = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_teams WHERE team_name = %s",
                $team_name
            ));
            if ($existing_team) {
                $errors[] = 'A team with this name already exists.';
            }
        }
        
        // Check for duplicate team number if provided
        if (!empty($team_number)) {
            $existing_number = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_teams WHERE team_number = %s",
                $team_number
            ));
            if ($existing_number) {
                $errors[] = 'A team with this number already exists.';
            }
        }
        
        if (empty($errors)) {
            $insert_data = array(
                'team_name' => $team_name,
                'team_number' => $team_number,
                'program' => $program,
                'description' => $description,
                'logo' => $logo,
                'team_photo' => $team_photo,
                'facebook' => $facebook,
                'twitter' => $twitter,
                'instagram' => $instagram,
                'website' => $website,
                'hall_of_fame' => $hall_of_fame
            );
            
            $result = $wpdb->insert(
                $table_teams,
                $insert_data,
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
            );
            
            if ($result === false) {
                echo '<div class="notice notice-error"><p>Error adding team: ' . esc_html($wpdb->last_error) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Team added successfully!</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>' . implode('<br>', array_map('esc_html', $errors)) . '</p></div>';
        }
    }
    
    /**
     * Handle update team
     */
    private function handle_update_team($table_teams) {
        global $wpdb;
        
        $team_id = intval($_POST['team_id']);
        $team_name = sanitize_text_field($_POST['team_name']);
        $team_number = sanitize_text_field($_POST['team_number']);
        $program = sanitize_text_field($_POST['program']);
        $description = sanitize_textarea_field($_POST['description']);
        $facebook = sanitize_text_field($_POST['facebook']);
        $twitter = sanitize_text_field($_POST['twitter']);
        $instagram = sanitize_text_field($_POST['instagram']);
        $website = esc_url_raw($_POST['website']);
        $hall_of_fame = isset($_POST['hall_of_fame']) ? 1 : 0;
        
        $errors = array();
        if (empty($team_name)) {
            $errors[] = 'Team name is required.';
        }
        
        // Check for duplicate team name, excluding current team
        if (!empty($team_name)) {
            $existing_team = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_teams WHERE team_name = %s AND id != %d",
                $team_name, $team_id
            ));
            if ($existing_team) {
                $errors[] = 'A team with this name already exists.';
            }
        }
        
        // Check for duplicate team number, excluding current team
        if (!empty($team_number)) {
            $existing_number = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_teams WHERE team_number = %s AND id != %d",
                $team_number, $team_id
            ));
            if ($existing_number) {
                $errors[] = 'A team with this number already exists.';
            }
        }
        
        if (empty($errors)) {
            $update_data = array(
                'team_name' => $team_name,
                'team_number' => $team_number,
                'program' => $program,
                'description' => $description,
                'facebook' => $facebook,
                'twitter' => $twitter,
                'instagram' => $instagram,
                'website' => $website,
                'hall_of_fame' => $hall_of_fame
            );
            
            // Handle file uploads - only update if new file is uploaded
            if (!empty($_FILES['logo']['name'])) {
                $uploaded = media_handle_upload('logo', 0);
                if (!is_wp_error($uploaded)) {
                    $update_data['logo'] = wp_get_attachment_url($uploaded);
                }
            }
            
            if (!empty($_FILES['team_photo']['name'])) {
                $uploaded = media_handle_upload('team_photo', 0);
                if (!is_wp_error($uploaded)) {
                    $update_data['team_photo'] = wp_get_attachment_url($uploaded);
                }
            }
            
            $result = $wpdb->update(
                $table_teams,
                $update_data,
                array('id' => $team_id),
                null, // Let WordPress determine the format
                array('%d')
            );
            
            if ($result === false) {
                echo '<div class="notice notice-error"><p>Error updating team: ' . esc_html($wpdb->last_error) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Team updated successfully!</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>' . implode('<br>', array_map('esc_html', $errors)) . '</p></div>';
        }
    }
    
    /**
     * Handle delete team
     */
    private function handle_delete_team($table_teams, $table_mentors) {
        global $wpdb;
        
        $team_id = intval($_POST['team_id']);
        if ($team_id > 0) {
            // Update mentors to remove team association
            $wpdb->update($table_mentors, array('team_id' => null), array('team_id' => $team_id), array('%d'), array('%d'));
            
            // Delete the team
            $result = $wpdb->delete($table_teams, array('id' => $team_id), array('%d'));
            
            if ($result === false) {
                echo '<div class="notice notice-error"><p>Error deleting team.</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Team deleted successfully!</p></div>';
            }
        }
    }
    
    /**
     * Register the Details admin page
     */
    public function register_invoices_page() {
        add_submenu_page(
            null, // No menu item, direct access only
            'Details',
            'Details',
            'manage_options',
            'qbo-view-invoices',
            array($this, 'render_view_invoices_page')
        );
    }
    
    /**
     * Render the Details page for a customer
     */
    public function render_view_invoices_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Add enhanced CSS for modern invoice cards
        echo '<style>
            .invoice-card {
                background: linear-gradient(145deg, #ffffff, #f8f9fa);
                border: 1px solid #e0e1e2;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 15px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                transition: all 0.3s ease;
                position: relative;
                overflow: hidden;
            }
            .invoice-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 16px rgba(0,0,0,0.12);
                border-color: #007cba;
            }
            .invoice-card::before {
                content: "";
                position: absolute;
                top: 0;
                left: 0;
                width: 4px;
                height: 100%;
                background: linear-gradient(145deg, #007cba, #005a87);
            }
            .invoice-card h3 {
                margin: 0 0 15px 0;
                font-size: 18px;
                font-weight: 600;
                color: #1e1e1e;
                padding-left: 12px;
                position: relative;
            }
            .invoice-card h3::after {
                content: "";
                position: absolute;
                bottom: -5px;
                left: 12px;
                width: 30px;
                height: 2px;
                background: #007cba;
                border-radius: 1px;
            }
            .invoice-card p {
                margin: 8px 0;
                font-size: 14px;
                color: #4f4f4f;
                padding-left: 12px;
                display: flex;
                align-items: center;
            }
            .invoice-card p strong {
                color: #2c3e50;
                font-weight: 600;
                min-width: 90px;
                margin-right: 10px;
            }
            .invoice-card .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .invoice-card .status-active {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            .invoice-card .status-inactive {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            .invoice-card .amount-highlight {
                font-size: 16px;
                font-weight: bold;
                color: #007cba;
                background: rgba(0, 124, 186, 0.1);
                padding: 2px 6px;
                border-radius: 4px;
            }
            /* Status badges for table cells */
            .status-badge {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
            }
            .status-active {
                background: #00a32a;
                color: white;
            }
            .status-inactive {
                background: #d63638;
                color: white;
            }
            .team-section {
                background: white;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .team-section h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
        </style>';
        
        $customer_id = isset($_GET['member_id']) ? sanitize_text_field($_GET['member_id']) : '';
        
        if (empty($customer_id)) {
            echo '<div class="wrap">';
            echo '<h1>Member Details</h1>';
            echo '<div class="notice notice-error"><p>No member selected.</p></div>';
            echo '<p><a href="javascript:history.back()" class="button">← Back</a></p>';
            echo '</div>';
            return;
        }
        
        // Get customer details from cache
        $cache = get_option('qbo_recurring_billing_customers_cache', array());
        $customer = null;
        
        if (isset($cache['data']) && is_array($cache['data'])) {
            foreach ($cache['data'] as $cached_customer) {
                if ($cached_customer['Id'] == $customer_id) {
                    $customer = $cached_customer;
                    break;
                }
            }
        }
        
        if (!$customer) {
            echo '<div class="wrap">';
            echo '<h1>Member Details</h1>';
            echo '<div class="notice notice-error"><p>Member not found.</p></div>';
            echo '<p><a href="javascript:history.back()" class="button">← Back</a></p>';
            echo '</div>';
            return;
        }
        
        // Parse customer data
        $parsed = $this->core->parse_company_name($customer['CompanyName'] ?? '');
        $customer_name = $customer['Name'] ?? $customer['CompanyName'] ?? 'Unknown Member';
        $contact_name = !empty($customer['GivenName']) || !empty($customer['FamilyName']) 
            ? trim(($customer['GivenName'] ?? '') . ' ' . ($customer['FamilyName'] ?? ''))
            : $customer_name;
        
        // Get invoices for this customer
        $invoices = $this->core->fetch_customer_invoices($customer_id);
        
        // Find active recurring invoice (if any)
        $active_recurring_invoice = null;
        if (!empty($invoices) && is_array($invoices)) {
            foreach ($invoices as $inv) {
                // Adjust this condition to match your actual recurring invoice flag/field
                if (!empty($inv['is_recurring']) && $inv['is_recurring'] && !empty($inv['status']) && strtolower($inv['status']) === 'active') {
                    $active_recurring_invoice = $inv;
                    break;
                }
            }
        }
        
        // Fetch recurring invoices for the member
        $recurring_invoices = $this->core->fetch_recurring_invoices_by_member($customer_id);
        
        ?>
        <div class="wrap">
            <h1>Member Details</h1>
            
            <!-- Navigation -->
            <p><a href="javascript:history.back()" class="button">← Back</a></p>
            
            <!-- Member Details Card -->
            <div class="customer-details-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #eee;">Member Details</h2>
                
                <div class="customer-details-grid" style="display: grid; grid-template-columns: 1fr; gap: 20px; margin-top: 15px;">
                    <div class="customer-info-section">
                        <h3 style="margin-top: 0; color: #23282d;">Basic Information</h3>
                        <table class="customer-info-table" style="width: 100%; border-collapse: collapse;">
                            <?php if (!empty($customer['PrimaryEmailAddr']['Address'])): ?>
                            <tr>
                                <td style="padding: 8px 0; font-weight: bold; width: 40%;">Email:</td>
                                <td style="padding: 8px 0;">
                                    <a href="mailto:<?php echo esc_attr($customer['PrimaryEmailAddr']['Address']); ?>">
                                        <?php echo esc_html($customer['PrimaryEmailAddr']['Address']); ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td style="padding: 8px 0; font-weight: bold; width: 40%;">Customer ID:</td>
                                <td style="padding: 8px 0;"><?php echo esc_html($customer_id); ?></td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 0; font-weight: bold;">Name:</td>
                                <td style="padding: 8px 0;"><?php echo esc_html($customer_name); ?></td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 0; font-weight: bold;">Contact:</td>
                                <td style="padding: 8px 0;"><?php echo esc_html($contact_name); ?></td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 0; font-weight: bold;">Balance:</td>
                                <td style="padding: 8px 0; <?php echo isset($customer['Balance']) && $customer['Balance'] > 0 ? 'color: #d63638; font-weight: bold;' : 'color: #00a32a;'; ?>">
                                    $<?php echo number_format($customer['Balance'] ?? 0, 2); ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Students Section -->
            <?php
            // Get students associated with this member
            global $wpdb;
            $table_students = $wpdb->prefix . 'gears_students';
            $students = $wpdb->get_results($wpdb->prepare("
                SELECT s.first_name, s.last_name, s.grade, s.first_year_first, s.team_id
                FROM $table_students s
                WHERE s.customer_id = %s 
                ORDER BY s.last_name, s.first_name
            ", $customer_id));
            
            if (!empty($students)):
            ?>
            <div class="students-section" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #eee;">Associated Students</h2>
                
                <div class="students-summary" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-left: 4px solid #00a32a;">
                    <strong>Summary:</strong> <?php echo count($students); ?> student(s) associated with this member
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Grade</th>
                            <th>First Year</th>
                            <th>Team</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <?php
                            $student_name = trim($student->first_name . ' ' . $student->last_name);
                            
                            // Get team name if team_id is set
                            $team_name = 'N/A';
                            $team_link = '';
                            if (!empty($student->team_id)) {
                                $table_teams = $wpdb->prefix . 'gears_teams';
                                $team = $wpdb->get_row($wpdb->prepare("
                                    SELECT team_name, team_number 
                                    FROM $table_teams 
                                    WHERE id = %d
                                ", $student->team_id));
                                
                                if ($team) {
                                    $team_name = $team->team_name;
                                    if (!empty($team->team_number)) {
                                        $team_name .= ' (Team ' . $team->team_number . ')';
                                    }
                                    $team_link = admin_url('admin.php?page=qbo-teams&action=view&team_id=' . $student->team_id);
                                }
                            }
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($student_name); ?></strong></td>
                                <td><?php echo esc_html($student->grade ?: 'N/A'); ?></td>
                                <td><?php echo esc_html($student->first_year_first ?: 'N/A'); ?></td>
                                <td>
                                    <?php if (!empty($team_link)): ?>
                                        <a href="<?php echo esc_url($team_link); ?>" title="View Team Details">
                                            <?php echo esc_html($team_name); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo esc_html($team_name); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Recurring Invoices Section -->
            <div class="team-section" id="recurring-invoices-section">
                <h2>Recurring Invoices</h2>
                <?php if (!empty($recurring_invoices)): ?>
                    <?php foreach ($recurring_invoices as $invoice): ?>
                        <?php 
                        // Extract data from the recurring invoice structure
                        $invoice_data = isset($invoice['Invoice']) ? $invoice['Invoice'] : array();
                        $recurring_info = isset($invoice_data['RecurringInfo']) ? $invoice_data['RecurringInfo'] : array();
                        
                        $name = isset($recurring_info['Name']) ? $recurring_info['Name'] : 'Recurring Invoice';
                        $amount = isset($invoice_data['TotalAmt']) ? '$' . number_format(floatval($invoice_data['TotalAmt']), 2) : 'N/A';
                        
                        // Get status from RecurringInfo Active field (boolean)
                        $status = isset($recurring_info['Active']) && $recurring_info['Active'] ? 'Active' : 'Inactive';
                        
                        // Get frequency
                        $frequency = 'N/A';
                        if (isset($recurring_info['ScheduleInfo']['IntervalType'])) {
                            $interval_type = $recurring_info['ScheduleInfo']['IntervalType'];
                            $num_interval = isset($recurring_info['ScheduleInfo']['NumInterval']) ? $recurring_info['ScheduleInfo']['NumInterval'] : 1;
                            
                            if ($num_interval == 1) {
                                $frequency = $interval_type;
                            } else {
                                $frequency = "Every $num_interval " . strtolower($interval_type);
                            }
                        }
                        
                        // Get next date
                        $next_date = isset($recurring_info['ScheduleInfo']['NextDate']) ? date('M j, Y', strtotime($recurring_info['ScheduleInfo']['NextDate'])) : 'N/A';
                        ?>
                        <div class="invoice-card">
                            <h3><?php echo esc_html($name); ?></h3>
                            <p><strong>Amount:</strong> <span class="amount-highlight"><?php echo esc_html($amount); ?></span></p>
                            <p><strong>Frequency:</strong> <?php echo esc_html($frequency); ?></p>
                            <p><strong>Next Date:</strong> <?php echo esc_html($next_date); ?></p>
                            <p><strong>Status:</strong> 
                                <span class="status-badge status-<?php echo strtolower($status) === 'active' ? 'active' : 'inactive'; ?>">
                                    <?php echo esc_html($status); ?>
                                </span>
                            </p>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No recurring invoices found for this member.</p>
                <?php endif; ?>
            </div>
            
            <!-- Invoices Section -->
            <div class="invoices-section" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #eee;">Invoices</h2>

                <?php if (empty($invoices)): ?>
                    <div class="notice notice-info">
                        <p>No invoices found for this member.</p>
                    </div>
                <?php endif; ?>

                <?php if ($active_recurring_invoice): ?>
                    <div style="margin-bottom: 15px; padding: 10px 15px; background: #e6f7ff; border-left: 4px solid #1890ff; border-radius: 4px;">
                        <strong>Active Recurring Invoice:</strong>
                        <a href="<?php echo esc_url($active_recurring_invoice['url'] ?? '#'); ?>" target="_blank" style="font-weight: bold; color: #1890ff; text-decoration: underline;">
                            View Invoice #<?php echo esc_html($active_recurring_invoice['DocNumber'] ?? $active_recurring_invoice['id'] ?? ''); ?>
                        </a>
                    </div>
                <?php endif; ?>

                <?php if (!empty($invoices)): ?>
                    <div class="invoices-summary" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-left: 4px solid #0073aa;">
                        <strong>Summary:</strong> <?php echo count($invoices); ?> invoice(s) found
                        <?php
                        $total_amount = 0;
                        $total_balance = 0;
                        $paid_count = 0;
                        $overdue_count = 0;
                        
                        foreach ($invoices as $invoice) {
                            $total_amount += floatval($invoice['TotalAmt'] ?? 0);
                            $balance = floatval($invoice['Balance'] ?? 0);
                            $total_balance += $balance;
                            
                            if ($balance <= 0) {
                                $paid_count++;
                            } elseif (isset($invoice['DueDate']) && strtotime($invoice['DueDate']) < time()) {
                                $overdue_count++;
                            }
                        }
                        ?>
                        | Total Amount: $<?php echo number_format($total_amount, 2); ?>
                        | Outstanding Balance: $<?php echo number_format($total_balance, 2); ?>
                        | Paid: <?php echo $paid_count; ?>
                        | Overdue: <?php echo $overdue_count; ?>
                    </div>
                    
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Due Date</th>
                                <th>Amount</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <?php
                                $balance = floatval($invoice['Balance'] ?? 0);
                                $status = 'Unknown';
                                $status_class = '';
                                
                                if ($balance <= 0) {
                                    $status = 'Paid';
                                    $status_class = 'status-paid';
                                } elseif (isset($invoice['DueDate']) && strtotime($invoice['DueDate']) < time()) {
                                    $status = 'Overdue';
                                    $status_class = 'status-overdue';
                                } else {
                                    $status = 'Open';
                                    $status_class = 'status-open';
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html($invoice['DocNumber'] ?? 'N/A'); ?></td>
                                    <td><?php echo esc_html(isset($invoice['TxnDate']) ? date('M j, Y', strtotime($invoice['TxnDate'])) : 'N/A'); ?></td>
                                    <td><?php echo esc_html(isset($invoice['DueDate']) ? date('M j, Y', strtotime($invoice['DueDate'])) : 'N/A'); ?></td>
                                    <td>$<?php echo number_format($invoice['TotalAmt'] ?? 0, 2); ?></td>
                                    <td style="<?php echo $balance > 0 ? 'color: #d63638; font-weight: bold;' : 'color: #00a32a;'; ?>">
                                        $<?php echo number_format($balance, 2); ?>
                                    </td>
                                    <td>
                                        <span class="<?php echo $status_class; ?>" style="
                                            padding: 4px 8px; 
                                            border-radius: 3px; 
                                            font-size: 12px; 
                                            font-weight: bold;
                                            <?php 
                                            if ($status_class === 'status-paid') echo 'background: #00a32a; color: white;';
                                            elseif ($status_class === 'status-overdue') echo 'background: #d63638; color: white;';
                                            elseif ($status_class === 'status-open') echo 'background: #dba617; color: white;';
                                            ?>
                                        "><?php echo esc_html($status); ?></span>
                                    </td>
                                    <td>
                                        <button type="button" class="button button-small view-invoice" 
                                                data-invoice-id="<?php echo esc_attr($invoice['Id']); ?>" 
                                                data-invoice-number="<?php echo esc_attr($invoice['DocNumber'] ?? 'N/A'); ?>" 
                                                title="View Invoice" style="margin-right: 5px;">
                                            <span class="dashicons dashicons-visibility"></span>
                                        </button>
                                        <button type="button" class="button button-small delete-invoice" 
                                                data-invoice-id="<?php echo esc_attr($invoice['Id']); ?>" 
                                                data-invoice-number="<?php echo esc_attr($invoice['DocNumber'] ?? 'N/A'); ?>" 
                                                title="Delete Invoice">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <!-- Cache info and force refresh button -->
                <?php
                $cache_key = 'qbo_recurring_billing_invoices_cache_' . $customer_id;
                $invoice_cache = get_option($cache_key, array());
                $last_cached = isset($invoice_cache['timestamp']) ? date('M j, Y H:i:s', $invoice_cache['timestamp']) : 'Never';
                ?>
                <div style="margin-top: 15px;">
                    <strong>Last cache:</strong> <?php echo esc_html($last_cached); ?>
                    <button type="button" class="button" id="force-refresh-invoices" data-member-id="<?php echo esc_attr($customer_id); ?>">Force Refresh</button>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($){
            // Handle force refresh
            $(document).off("click", "#force-refresh-invoices").on("click", "#force-refresh-invoices", function(){
                var btn = $(this);
                btn.prop("disabled", true).text("Refreshing...");
                var customerId = btn.data("member-id"); // Fixed: use member-id instead of customer-id
                
                $.post(ajaxurl, {
                    action: "qbo_clear_invoice_cache",
                    nonce: "<?php echo wp_create_nonce('qbo_clear_cache'); ?>", // Use qbo_clear_cache nonce
                    customer_id: customerId
                }, function(resp){
                    if (resp.success) {
                        btn.text("Reloading...");
                        setTimeout(function(){
                            window.location.reload();
                        }, 1000);
                    } else {
                        alert("Error clearing cache: " + (resp.data || 'Unknown error'));
                        btn.prop("disabled", false).text("Force Refresh");
                    }
                }).fail(function(){
                    alert("Error clearing cache. Please try again.");
                    btn.prop("disabled", false).text("Force Refresh");
                });
            });
            
            // Handle view invoice button clicks
            $(document).off("click", ".view-invoice").on("click", ".view-invoice", function(e){
                e.preventDefault();
                var invoiceId = $(this).data("invoice-id");
                var invoiceNumber = $(this).data("invoice-number");
                
                // You can implement invoice viewing functionality here
                alert("View Invoice #" + invoiceNumber + " (ID: " + invoiceId + ")");
            });
            
            // Handle delete invoice button clicks
            $(document).off("click", ".delete-invoice").on("click", ".delete-invoice", function(e){
                e.preventDefault();
                var button = $(this);
                var invoiceId = button.data("invoice-id");
                var invoiceNumber = button.data("invoice-number");
                
                if (confirm("Are you sure you want to delete Invoice #" + invoiceNumber + "? This action cannot be undone.")) {
                    button.prop("disabled", true).html('<span class="dashicons dashicons-update spin"></span>');
                    
                    $.post(ajaxurl, {
                        action: "qbo_delete_invoice",
                        invoice_id: invoiceId,
                        nonce: "<?php echo wp_create_nonce('qbo_delete_invoice_nonce'); ?>"
                    }, function(response){
                        if (response.success) {
                            // Clear the invoice cache before refreshing
                            var customerId = $("#force-refresh-invoices").data("member-id");
                            $.post(ajaxurl, {
                                action: "qbo_clear_invoice_cache",
                                nonce: "<?php echo wp_create_nonce('qbo_clear_cache'); ?>",
                                customer_id: customerId
                            }, function(){
                                // Auto-refresh the page after successful deletion and cache clear
                                window.location.reload();
                            });
                        } else {
                            alert("Error deleting invoice: " + response.data);
                            button.prop("disabled", false).html('<span class="dashicons dashicons-trash"></span>');
                        }
                    }).fail(function(){
                        alert("Error deleting invoice. Please try again.");
                        button.prop("disabled", false).html('<span class="dashicons dashicons-trash"></span>');
                    });
                }
            });
            
            // Close modal when clicking the X button
            $(document).on('click', '.gears-modal-close', function() {
                var modalOverlay = $(this).closest('.gears-modal-overlay');
                modalOverlay.removeClass('active');
                modalOverlay.find('form').trigger('reset');
            });

            // Close modal when clicking outside of it
            $(document).on('click', '.gears-modal-overlay', function(e) {
                if (e.target === this) {
                    $(this).removeClass('active');
                    $(this).find('form').trigger('reset');
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get program display with logo
     */
    private function get_program_display($program) {
        switch (strtoupper($program)) {
            case 'FTC':
                return '<img src="https://gears.org.in/wp-content/uploads/2025/07/FIRSTTech_iconHorz_RGB.png" alt="FTC" class="program-logo" title="FIRST Tech Challenge">';
            case 'FLL':
                return '<img src="https://gears.org.in/wp-content/uploads/2025/07/FIRSTLego_iconHorz_RGB.png" alt="FLL" class="program-logo" title="FIRST LEGO League">';
            case 'FRC':
                return '<img src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHJlY3Qgd2lkdGg9IjI0IiBoZWlnaHQ9IjI0IiByeD0iNCIgZmlsbD0iI0Q2MzYzOCIvPgo8dGV4dCB4PSIxMiIgeT0iMTYiIGZvbnQtZmFtaWx5PSJBcmlhbCwgc2Fucy1zZXJpZiIgZm9udC1zaXplPSIxMCIgZm9udC13ZWlnaHQ9ImJvbGQiIGZpbGw9IndoaXRlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5GUkM8L3RleHQ+Cjwvc3ZnPg==" alt="FRC" class="program-logo" title="FIRST Robotics Competition">';
            default:
                return esc_html($program);
        }
    }
    
    /**
     * Render page HTML
     */
    private function render_page_html($teams, $archived_teams = array()) {
        ?>
        <style>
            /* Program Logo Styling */
            .program-logo {
                height: 24px;
                width: auto;
                vertical-align: middle;
                border-radius: 2px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            }
            
            .team-form-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                max-width: 600px;
            }
            .team-form-card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .team-logo-thumb, .team-photo-thumb {
                width: 48px;
                height: 48px;
                object-fit: cover;
                border-radius: 4px;
                border: 1px solid #ccc;
                background: #f9f9f9;
            }
            .social-link {
                margin-right: 5px;
            }
            /* Modal Form Styling - using shared modals.css */
            .team-details-container {
                max-width: 1200px;
                display: flex;
                gap: 32px;
                margin: 0 auto;
            }
            .team-details-main {
                flex: 0 0 66%;
                max-width: 66%;
                min-width: 0;
            }
            .team-details-sidebar {
                flex: 0 0 34%;
                max-width: 34%;
                min-width: 280px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 24px;
                box-sizing: border-box;
                display: flex;
                flex-direction: column;
                gap: 24px;
                height: fit-content;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            }
            
            /* Responsive design for mobile devices */
            @media (max-width: 768px) {
                .team-details-container {
                    flex-direction: column;
                    gap: 20px;
                }
                .team-details-main,
                .team-details-sidebar {
                    flex: none;
                    max-width: 100%;
                    min-width: 0;
                }
            }
            .team-logo {
                width: 100%;
                max-width: 250px;
                height: auto;
                object-fit: contain;
                border-radius: 8px;
                border: 1px solid #ddd;
                background: #fafbfc;
                display: block;
                margin: 0 auto;
            }
            .team-photo {
                width: 100%;
                height: auto;
                border-radius: 8px;
                border: 1px solid #ddd;
                background: #fafbfc;
                display: block;
                margin: 0 auto;
            }
            .team-social-links {
                display: flex;
                flex-direction: column;
                gap: 12px;
                width: 100%;
            }
            .team-social-links a {
                display: flex;
                align-items: center;
                text-decoration: none;
                padding: 8px 12px;
                border-radius: 6px;
                background: #f8f9fa;
                border: 1px solid #e0e1e2;
                transition: all 0.2s ease;
                color: #2c3e50;
            }
            .team-social-links a:hover {
                background: #e9ecef;
                border-color: #007cba;
                color: #007cba;
                transform: translateY(-1px);
            }
            .team-social-links .dashicons {
                margin-right: 8px;
                font-size: 16px;
            }
            .sidebar-section {
                width: 100%;
            }
            .sidebar-section h3 {
                font-size: 16px;
                font-weight: 600;
                margin: 0 0 12px 0;
                color: #2c3e50;
                text-align: center;
                border-bottom: 2px solid #e0e1e2;
                padding-bottom: 8px;
            }
            .team-website {
                text-align: center;
                margin: 16px 0;
            }
            .team-website a {
                display: inline-block;
                padding: 10px 16px;
                background: #007cba;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 600;
                transition: background 0.2s ease;
            }
            .team-website a:hover {
                background: #005a87;
            }
            .team-main-info h1 {
                margin-top: 0;
                margin-bottom: 10px;
            }
            .team-section {
                background: white;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .team-section h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .invoice-card {
                background: linear-gradient(145deg, #ffffff, #f8f9fa);
                border: 1px solid #e0e1e2;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 15px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                transition: all 0.3s ease;
                position: relative;
                overflow: hidden;
            }
            .invoice-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 16px rgba(0,0,0,0.12);
                border-color: #007cba;
            }
            .invoice-card::before {
                content: "";
                position: absolute;
                top: 0;
                left: 0;
                width: 4px;
                height: 100%;
                background: linear-gradient(145deg, #007cba, #005a87);
            }
            .invoice-card h3 {
                margin: 0 0 15px 0;
                font-size: 18px;
                font-weight: 600;
                color: #1e1e1e;
                padding-left: 12px;
                position: relative;
            }
            .invoice-card h3::after {
                content: "";
                position: absolute;
                bottom: -5px;
                left: 12px;
                width: 30px;
                height: 2px;
                background: #007cba;
                border-radius: 1px;
            }
            .invoice-card p {
                margin: 8px 0;
                font-size: 14px;
                color: #4f4f4f;
                padding-left: 12px;
                display: flex;
                align-items: center;
            }
            .invoice-card p strong {
                color: #2c3e50;
                font-weight: 600;
                min-width: 90px;
                margin-right: 10px;
            }
            .invoice-card .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .invoice-card .status-active {
                background: #00a32a;
                color: white;
            }
            .invoice-card .status-inactive {
                background: #d63638;
                color: white;
            }
            .invoice-card .amount-highlight {
                font-size: 16px;
                font-weight: bold;
                color: #007cba;
                background: rgba(0, 124, 186, 0.1);
                padding: 2px 6px;
                border-radius: 4px;
            }
            
            /* Modal Form Styling for consistent appearance */
            .gears-modal .form-row {
                margin-bottom: 20px;
            }
            
            .gears-modal .form-row label {
                display: block;
                font-weight: 600;
                margin-bottom: 6px;
                color: #23282d;
                font-size: 14px;
            }
            
            .gears-modal .form-input {
                width: 100%;
                padding: 8px 12px;
                font-size: 14px;
                line-height: 1.4;
                border: 1px solid #ddd;
                border-radius: 4px;
                background-color: #fff;
                box-shadow: inset 0 1px 2px rgba(0,0,0,.07);
                transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
                box-sizing: border-box;
            }
            
            .gears-modal .form-input:focus {
                border-color: #0073aa;
                box-shadow: 0 0 0 1px #0073aa;
                outline: none;
            }
            
            .gears-modal textarea.form-input {
                resize: vertical;
                min-height: 80px;
            }
            
            .gears-modal select.form-input {
                height: auto;
                padding: 7px 12px;
            }
            
            .gears-modal .form-actions {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #e1e1e1;
                text-align: right;
            }
            
            .gears-modal .form-actions .button {
                margin-left: 10px;
            }
            
            .gears-modal .form-actions .button:first-child {
                margin-left: 0;
            }
            
            /* File input styling */
            .gears-modal input[type="file"].form-input {
                padding: 6px;
                border: 1px dashed #ddd;
                background-color: #f9f9f9;
            }
            
            .gears-modal input[type="file"].form-input:hover {
                border-color: #0073aa;
                background-color: #f1f8ff;
            }
            
            /* Required field indicator */
            .gears-modal label span[style*="color: red"] {
                color: #d63638 !important;
                font-weight: normal;
            }
            
            /* Preview containers for images */
            .gears-modal div[id*="preview"] img {
                max-width: 150px;
                max-height: 100px;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 4px;
                background: #fff;
            }
            
            /* Responsive adjustments */
            @media (max-width: 768px) {
                .gears-modal {
                    margin: 20px !important;
                    max-width: calc(100% - 40px) !important;
                }
                
                .gears-modal .form-actions {
                    text-align: center;
                }
                
                .gears-modal .form-actions .button {
                    margin: 5px;
                    min-width: 100px;
                }
            }
        </style>
        
        <div class="wrap">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1>Teams Management</h1>
                <button type="button" class="button button-primary" id="add-team-btn">Add New Team</button>
            </div>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Team Name</th>
                        <th>Team Number</th>
                        <th>Program</th>
                        <th>Hall of Fame</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($teams)) : ?>
                        <?php foreach ($teams as $team) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($team->team_name); ?></strong></td>
                                <td><?php echo esc_html($team->team_number); ?></td>
                                <td><?php echo $this->get_program_display($team->program); ?></td>
                                <td><?php echo !empty($team->hall_of_fame) ? '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;" title="Hall of Fame Team"></span>' : ''; ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=qbo-teams&action=view&team_id=' . intval($team->id)); ?>" class="button button-small">View Details</a>
                                    <button type="button" class="button button-small edit-team-btn" data-team-id="<?php echo intval($team->id); ?>">Edit</button>
                                    <button type="button" class="button button-small button-link-delete archive-team-btn" data-team-id="<?php echo intval($team->id); ?>" data-team-name="<?php echo esc_attr($team->team_name); ?>">Move to Past</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="5"><em>No teams found.</em></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Past Teams Section -->
            <?php if (!empty($archived_teams)): ?>
                <div style="margin-top: 40px;">
                    <h2 style="color: #666; border-bottom: 1px solid #ddd; padding-bottom: 10px;">Past Teams</h2>
                    <p style="color: #666; font-style: italic; margin-bottom: 15px;">These teams are from previous seasons and are hidden from the main list.</p>
                    
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Team Name</th>
                                <th>Team Number</th>
                                <th>Program</th>
                                <th>Hall of Fame</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($archived_teams as $team) : ?>
                                <tr style="opacity: 0.7;">
                                    <td><strong><?php echo esc_html($team->team_name); ?></strong> <span style="color: #999; font-size: 11px;">(Past)</span></td>
                                    <td><?php echo esc_html($team->team_number); ?></td>
                                    <td><?php echo $this->get_program_display($team->program); ?></td>
                                    <td><?php echo !empty($team->hall_of_fame) ? '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;" title="Hall of Fame Team"></span>' : ''; ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=qbo-teams&action=view&team_id=' . intval($team->id)); ?>" class="button button-small">View Details</a>
                                        <button type="button" class="button button-small edit-team-btn" data-team-id="<?php echo intval($team->id); ?>">Edit</button>
                                        <button type="button" class="button button-small button-primary restore-team-btn" data-team-id="<?php echo intval($team->id); ?>" data-team-name="<?php echo esc_attr($team->team_name); ?>">Restore</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <!-- Add Team Modal -->
            <div id="add-team-modal-overlay" class="gears-modal-overlay">
                <div class="gears-modal" style="max-width: 600px;">
                    <div class="gears-modal-header">
                        <h2>Add New Team</h2>
                        <span class="gears-modal-close">&times;</span>
                    </div>
                    <div class="gears-modal-content">
                        <form id="add-team-form" method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field('add_team_action', 'team_nonce'); ?>
                            <input type="hidden" name="add_team" value="1" />
                            
                            <div class="form-row">
                                <label for="team_name">Team Name <span style="color: red;">*</span></label>
                                <input type="text" id="team_name" name="team_name" class="form-input" required />
                            </div>
                            
                            <div class="form-row">
                                <label for="team_number">Team Number</label>
                                <input type="text" id="team_number" name="team_number" class="form-input" />
                            </div>
                            
                            <div class="form-row">
                                <label for="program">Program</label>
                                <select id="program" name="program" class="form-input">
                                    <option value="">Select Program</option>
                                    <option value="FTC">FTC (FIRST Tech Challenge)</option>
                                    <option value="FLL">FLL (FIRST LEGO League)</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            
                            <div class="form-row">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" class="form-input" rows="3" placeholder="Brief description of the team..."></textarea>
                            </div>
                            
                            <div class="form-row">
                                <label for="logo">Team Logo</label>
                                <input type="file" id="logo" name="logo" class="form-input" accept="image/*" />
                            </div>
                            
                            <div class="form-row">
                                <label for="team_photo">Team Photo</label>
                                <input type="file" id="team_photo" name="team_photo" class="form-input" accept="image/*" />
                            </div>
                            
                            <div class="form-row">
                                <label for="website">Website</label>
                                <input type="url" id="website" name="website" class="form-input" placeholder="https://" />
                            </div>
                            
                            <div class="form-row">
                                <label for="facebook">Facebook</label>
                                <input type="url" id="facebook" name="facebook" class="form-input" placeholder="https://facebook.com/..." />
                            </div>
                            
                            <div class="form-row">
                                <label for="twitter">Twitter/X</label>
                                <input type="url" id="twitter" name="twitter" class="form-input" placeholder="https://x.com/..." />
                            </div>
                            
                            <div class="form-row">
                                <label for="instagram">Instagram</label>
                                <input type="url" id="instagram" name="instagram" class="form-input" placeholder="https://instagram.com/..." />
                            </div>
                            
                            <div class="form-row">
                                <label>
                                    <input type="checkbox" id="hall_of_fame" name="hall_of_fame" value="1" />
                                    <span style="margin-left: 8px;">Hall of Fame Team</span>
                                </label>
                                <p style="font-size: 12px; color: #666; margin-top: 5px;">Check this box if this team is inducted into the Hall of Fame</p>
                            </div>
                            
                            <div class="form-actions">
                                <button type="button" class="button" onclick="closeAddTeamModal()">Cancel</button>
                                <button type="submit" name="add_team" class="button button-primary">Add Team</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Edit Team Modal -->
            <div id="edit-team-modal-overlay" class="gears-modal-overlay">
                <div class="gears-modal" style="max-width: 600px;">
                    <div class="gears-modal-header">
                        <h2>Edit Team</h2>
                        <span class="gears-modal-close">&times;</span>
                    </div>
                    <div class="gears-modal-content">
                        <form id="edit-team-form" method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field('update_team_action', 'team_edit_nonce'); ?>
                            <input type="hidden" name="update_team" value="1" />
                            <input type="hidden" name="team_id" id="edit-team-id" value="" />
                            
                            <div class="form-row">
                                <label for="edit_team_name">Team Name <span style="color: red;">*</span></label>
                                <input type="text" id="edit_team_name" name="team_name" class="form-input" required />
                            </div>
                            
                            <div class="form-row">
                                <label for="edit_team_number">Team Number</label>
                                <input type="text" id="edit_team_number" name="team_number" class="form-input" />
                            </div>
                            
                            <div class="form-row">
                                <label for="edit_program">Program</label>
                                <select id="edit_program" name="program" class="form-input">
                                    <option value="">Select Program</option>
                                    <option value="FTC">FTC (FIRST Tech Challenge)</option>
                                    <option value="FLL">FLL (FIRST LEGO League)</option>
                                    <option value="FRC">FRC (FIRST Robotics Competition)</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            
                            <div class="form-row">
                                <label for="edit_description">Description</label>
                                <textarea id="edit_description" name="description" class="form-input" rows="4" placeholder="Brief description of the team..."></textarea>
                            </div>
                            
                            <div class="form-row">
                                <label for="edit_logo">Team Logo</label>
                                <input type="file" id="edit_logo" name="logo" class="form-input" accept="image/*" />
                                <div id="current-logo-preview" style="margin-top: 10px;"></div>
                            </div>
                            
                            <div class="form-row">
                                <label for="edit_team_photo">Team Photo</label>
                                <input type="file" id="edit_team_photo" name="team_photo" class="form-input" accept="image/*" />
                                <div id="current-photo-preview" style="margin-top: 10px;"></div>
                            </div>
                            
                            <div class="form-row">
                                <label for="edit_website">Website</label>
                                <input type="url" id="edit_website" name="website" class="form-input" placeholder="https://" />
                            </div>
                            
                            <div class="form-row">
                                <label for="edit_facebook">Facebook</label>
                                <input type="url" id="edit_facebook" name="facebook" class="form-input" placeholder="https://facebook.com/..." />
                            </div>
                            
                            <div class="form-row">
                                <label for="edit_twitter">Twitter/X</label>
                                <input type="url" id="edit_twitter" name="twitter" class="form-input" placeholder="https://x.com/..." />
                            </div>
                            
                            <div class="form-row">
                                <label for="edit_instagram">Instagram</label>
                                <input type="url" id="edit_instagram" name="instagram" class="form-input" placeholder="https://instagram.com/..." />
                            </div>
                            
                            <div class="form-row">
                                <label>
                                    <input type="checkbox" id="edit_hall_of_fame" name="hall_of_fame" value="1" />
                                    <span style="margin-left: 8px;">Hall of Fame Team</span>
                                </label>
                                <p style="font-size: 12px; color: #666; margin-top: 5px;">Check this box if this team is inducted into the Hall of Fame</p>
                            </div>
                            
                            <div class="form-actions">
                                <button type="button" class="button" onclick="closeEditTeamModal()">Cancel</button>
                                <button type="submit" name="update_team" class="button button-primary">Update Team</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <script type="text/javascript">
        // Make sure ajaxurl is available
        if (typeof ajaxurl === 'undefined') {
            var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        }
        
        window.qboCustomerListVars = window.qboCustomerListVars || {};
        qboCustomerListVars.invoicesPageUrl = "<?php echo esc_js(admin_url('admin.php?page=qbo-view-invoices')); ?>";
        
        // Add Team Modal Functions
        function openAddTeamModal() {
            document.getElementById('add-team-modal-overlay').classList.add('active');
        }
        
        function closeAddTeamModal() {
            document.getElementById('add-team-modal-overlay').classList.remove('active');
            document.getElementById('add-team-form').reset();
        }
        
        // Edit Team Modal Functions
        function openEditTeamModal() {
            document.getElementById('edit-team-modal-overlay').classList.add('active');
        }
        
        function closeEditTeamModal() {
            document.getElementById('edit-team-modal-overlay').classList.remove('active');
            document.getElementById('edit-team-form').reset();
        }
        
        // Event Listeners
        jQuery(document).ready(function($) {
            // Open modal when Add Team button is clicked
            $('#add-team-btn').on('click', function() {
                openAddTeamModal();
            });
            
            // Close modal when X or overlay is clicked
            $('.gears-modal-close, #add-team-modal-overlay, #edit-team-modal-overlay').on('click', function(e) {
                if (e.target === this) {
                    if ($(this).attr('id') === 'edit-team-modal-overlay' || $(this).closest('#edit-team-modal-overlay').length) {
                        closeEditTeamModal();
                    } else {
                        closeAddTeamModal();
                    }
                }
            });
            
            // Handle edit team button clicks
            $('.edit-team-btn').on('click', function() {
                var teamId = $(this).data('team-id');
                loadTeamData(teamId);
            });
            
            // Handle archive team button clicks
            $('.archive-team-btn').on('click', function() {
                var teamId = $(this).data('team-id');
                var teamName = $(this).data('team-name');
                
                if (confirm('Are you sure you want to move "' + teamName + '" to past teams? This will hide it from the main teams list but preserve all data.')) {
                    archiveTeam(teamId);
                }
            });
            
            // Handle restore team button clicks
            $('.restore-team-btn').on('click', function() {
                var teamId = $(this).data('team-id');
                var teamName = $(this).data('team-name');
                
                if (confirm('Are you sure you want to restore "' + teamName + '"? This will make it visible in the main teams list again.')) {
                    restoreTeam(teamId);
                }
            });
            
            // Handle form submission
            $('#add-team-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData(this);
                formData.append('action', 'qbo_add_team');
                var submitBtn = $(this).find('button[type="submit"]');
                var originalText = submitBtn.text();
                
                // Disable submit button and show loading state
                submitBtn.prop('disabled', true).text('Adding Team...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            closeAddTeamModal();
                            alert('Team added successfully!');
                            window.location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Unknown error'));
                            submitBtn.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('Error adding team: ' + error);
                        submitBtn.prop('disabled', false).text(originalText);
                    }
                });
            });
            
            // Handle edit team form submission
            $('#edit-team-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData(this);
                formData.append('action', 'qbo_update_team');
                var submitBtn = $(this).find('button[type="submit"]');
                var originalText = submitBtn.text();
                
                // Disable submit button and show loading state
                submitBtn.prop('disabled', true).text('Updating Team...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            closeEditTeamModal();
                            alert('Team updated successfully!');
                            window.location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Unknown error'));
                            submitBtn.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('Error updating team: ' + error);
                        submitBtn.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        
        // Function to load team data for editing
        function loadTeamData(teamId) {
            jQuery.post(ajaxurl, {
                action: 'qbo_get_team_data',
                team_id: teamId,
                nonce: '<?php echo wp_create_nonce('qbo_get_team_data'); ?>'
            }, function(response) {
                if (response.success && response.data) {
                    var team = response.data;
                    jQuery('#edit-team-id').val(teamId);
                    jQuery('#edit_team_name').val(team.team_name || '');
                    jQuery('#edit_team_number').val(team.team_number || '');
                    jQuery('#edit_program').val(team.program || '');
                    jQuery('#edit_description').val(team.description || '');
                    jQuery('#edit_website').val(team.website || '');
                    jQuery('#edit_facebook').val(team.facebook || '');
                    jQuery('#edit_twitter').val(team.twitter || '');
                    jQuery('#edit_instagram').val(team.instagram || '');
                    jQuery('#edit_hall_of_fame').prop('checked', team.hall_of_fame == '1');
                    
                    // Show current images if they exist
                    if (team.logo) {
                        jQuery('#current-logo-preview').html('<img src="' + team.logo + '" style="max-width: 100px; height: auto;" alt="Current Logo">');
                    } else {
                        jQuery('#current-logo-preview').html('');
                    }
                    
                    if (team.team_photo) {
                        jQuery('#current-photo-preview').html('<img src="' + team.team_photo + '" style="max-width: 100px; height: auto;" alt="Current Photo">');
                    } else {
                        jQuery('#current-photo-preview').html('');
                    }
                    
                    openEditTeamModal();
                } else {
                    alert('Error loading team data: ' + (response.data || 'Unknown error'));
                }
            }).fail(function() {
                alert('Error loading team data. Please try again.');
            });
        }
        
        // Function to archive a team
        function archiveTeam(teamId) {
            jQuery.post(ajaxurl, {
                action: 'qbo_archive_team',
                team_id: teamId,
                nonce: '<?php echo wp_create_nonce('qbo_archive_team'); ?>'
            }, function(response) {
                if (response.success) {
                    alert('Team moved to past teams successfully!');
                    window.location.reload();
                } else {
                    alert('Error moving team to past teams: ' + (response.data || 'Unknown error'));
                }
            }).fail(function() {
                alert('Error moving team to past teams. Please try again.');
            });
        }
        
        // Function to restore a team
        function restoreTeam(teamId) {
            jQuery.post(ajaxurl, {
                action: 'qbo_restore_team',
                team_id: teamId,
                nonce: '<?php echo wp_create_nonce('qbo_restore_team'); ?>'
            }, function(response) {
                if (response.success) {
                    alert('Team restored successfully!');
                    window.location.reload();
                } else {
                    alert('Error restoring team: ' + (response.data || 'Unknown error'));
                }
            }).fail(function() {
                alert('Error restoring team. Please try again.');
            });
        }
        </script>
        <?php
    }

    /**
     * AJAX handler for editing team data
     */
    public function ajax_edit_team() {
        check_ajax_referer('edit_team_nonce', 'nonce');
        
        global $wpdb;
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        $team_id = intval($_POST['team_id']);
        $team_name = sanitize_text_field($_POST['team_name']);
        $team_number = sanitize_text_field($_POST['team_number']);
        $program = sanitize_text_field($_POST['program']);
        $description = sanitize_textarea_field($_POST['description']);
        $hall_of_fame = isset($_POST['hall_of_fame']) ? 1 : 0;
        
        if ($team_id > 0 && !empty($team_name)) {
            $result = $wpdb->update(
                $table_teams,
                array(
                    'team_name' => $team_name,
                    'team_number' => $team_number,
                    'program' => $program,
                    'description' => $description,
                    'hall_of_fame' => $hall_of_fame
                ),
                array('id' => $team_id),
                array('%s', '%s', '%s', '%s', '%d'),
                array('%d')
            );
            
            if ($result !== false) {
                wp_send_json_success('Team updated successfully');
            } else {
                wp_send_json_error('Failed to update team');
            }
        } else {
            wp_send_json_error('Invalid team data');
        }
    }
    
    /**
     * AJAX handler to get students for a team from the Students database table
     */
    public function ajax_get_team_students() {
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'qbo_get_customers')) {
            wp_send_json_error('Unauthorized');
        }
        
        $team_id = isset($_POST['team_id']) ? intval($_POST['team_id']) : 0;
        
        if (!$team_id) {
            wp_send_json_success(array());
        }
        
        global $wpdb;
        $table_students = $wpdb->prefix . 'gears_students';
        
        // Get students for this team from the database
        $students = $wpdb->get_results($wpdb->prepare("
            SELECT s.id, s.first_name, s.last_name, s.grade, s.first_year_first, s.customer_id
            FROM $table_students s
            WHERE s.team_id = %d 
            ORDER BY s.last_name, s.first_name
        ", $team_id));
        
        if (!$students) {
            wp_send_json_success(array());
        }
        
        // Get QBO customer cache to lookup customer info and balances
        $cache = get_option('qbo_recurring_billing_customers_cache', array());
        $customers_lookup = array();
        
        if (isset($cache['data']) && is_array($cache['data'])) {
            foreach ($cache['data'] as $customer) {
                $customers_lookup[$customer['Id']] = $customer;
            }
        }
        
        // Get recurring invoices to check for active status
        $recurring_invoices_class = new QBO_Recurring_Invoices($this->core);
        $all_recurring_invoices = $recurring_invoices_class->fetch_recurring_invoices();
        
        // Create lookup for active recurring invoices by customer ID
        $active_recurring_lookup = array();
        foreach ($all_recurring_invoices as $recurring_invoice) {
            if (isset($recurring_invoice['Invoice'])) {
                $invoice_data = $recurring_invoice['Invoice'];
                $recurring_info = isset($invoice_data['RecurringInfo']) ? $invoice_data['RecurringInfo'] : array();
                
                // Check if this recurring invoice is active
                $is_active = isset($recurring_info['Active']) && $recurring_info['Active'] === true;
                
                if ($is_active && isset($invoice_data['CustomerRef']['value'])) {
                    $customer_id = $invoice_data['CustomerRef']['value'];
                    $active_recurring_lookup[$customer_id] = true;
                }
            }
        }
        
        $result = array();
        foreach ($students as $student) {
            $student_name = esc_html(trim($student->first_name . ' ' . $student->last_name));
            $customer_info = null;
            $parent_name = 'No Customer';
            $balance = 0.00;
            $status = 'Inactive'; // Default status
            
            // Get customer info if customer_id is set
            if (!empty($student->customer_id) && isset($customers_lookup[$student->customer_id])) {
                $customer_info = $customers_lookup[$student->customer_id];
                
                // Get parent name from customer data
                $display_name = isset($customer_info['DisplayName']) ? $customer_info['DisplayName'] : '';
                $first_name = isset($customer_info['GivenName']) ? $customer_info['GivenName'] : '';
                $last_name = isset($customer_info['FamilyName']) ? $customer_info['FamilyName'] : '';
                
                // Construct parent name - prefer GivenName/FamilyName, fallback to DisplayName
                if (!empty($first_name) || !empty($last_name)) {
                    $parent_name = trim($first_name . ' ' . $last_name);
                } elseif (!empty($display_name)) {
                    $parent_name = $display_name;
                } else {
                    $parent_name = 'Unknown Customer';
                }
                
                // Get balance by fetching customer invoices and summing their balances
                $balance = 0.00;
                if (!empty($student->customer_id)) {
                    $customer_invoices = $this->core->fetch_customer_invoices($student->customer_id);
                    if (is_array($customer_invoices)) {
                        foreach ($customer_invoices as $invoice) {
                            $invoice_balance = floatval($invoice['Balance'] ?? 0);
                            $balance += $invoice_balance;
                        }
                    }
                }
                
                // Determine status based on active recurring invoices
                // Check if this customer has any active recurring invoices
                if (isset($active_recurring_lookup[$student->customer_id])) {
                    $status = 'Active';
                } else {
                    $status = 'Inactive';
                }
            }
            
            $result[] = array(
                'student_id' => $student->id,
                'student_name' => $student_name,
                'parent_name' => esc_html($parent_name),
                'grade' => esc_html($student->grade),
                'first_year_first' => esc_html($student->first_year_first),
                'balance' => $balance,
                'customer_id' => $student->customer_id,
                'status' => $status
            );
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX handler to get team mentors
     */
    public function ajax_get_team_mentors() {
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'qbo_get_customers')) {
            wp_send_json_error('Unauthorized');
        }
        
        $team_id = isset($_POST['team_id']) ? intval($_POST['team_id']) : 0;
        
        if (!$team_id) {
            wp_send_json_success(array());
        }
        
        global $wpdb;
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        
        // Get mentors for this team from the database
        $mentors = $wpdb->get_results($wpdb->prepare("
            SELECT m.id, m.first_name, m.last_name, m.email, m.phone, m.address, m.notes
            FROM $table_mentors m
            WHERE m.team_id = %d 
            ORDER BY m.last_name, m.first_name
        ", $team_id));
        
        if (!$mentors) {
            wp_send_json_success(array());
        }
        
        $result = array();
        foreach ($mentors as $mentor) {
            $full_name = trim($mentor->first_name . ' ' . $mentor->last_name);
            
            $result[] = array(
                'id' => $mentor->id,
                'first_name' => esc_html($mentor->first_name),
                'last_name' => esc_html($mentor->last_name),
                'full_name' => esc_html($full_name),
                'email' => esc_html($mentor->email),
                'phone' => esc_html($mentor->phone),
                'address' => esc_html($mentor->address),
                'notes' => esc_html($mentor->notes)
            );
        }
        
        wp_send_json_success($result);
    }

    /**
     * AJAX handler to get team data for editing
     */
    public function ajax_get_team_data() {
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'qbo_get_team_data')) {
            wp_send_json_error('Unauthorized');
        }
        
        $team_id = isset($_POST['team_id']) ? intval($_POST['team_id']) : 0;
        
        if (!$team_id) {
            wp_send_json_error('Invalid team ID');
        }
        
        global $wpdb;
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        $team = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_teams WHERE id = %d", $team_id));
        
        if (!$team) {
            wp_send_json_error('Team not found');
        }
        
        wp_send_json_success($team);
    }

    /**
     * AJAX handler to get mentor data for editing
     */
    public function ajax_get_mentor_data() {
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'get_mentor_data')) {
            wp_send_json_error('Unauthorized');
        }
        
        $mentor_id = isset($_POST['mentor_id']) ? intval($_POST['mentor_id']) : 0;
        
        if (!$mentor_id) {
            wp_send_json_error('Invalid mentor ID');
        }
        
        global $wpdb;
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        
        $mentor = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_mentors WHERE id = %d", $mentor_id));
        
        if (!$mentor) {
            wp_send_json_error('Mentor not found');
        }
        
        wp_send_json_success($mentor);
    }
    
    /**
     * AJAX handler to delete a mentor
     */
    public function ajax_delete_mentor() {
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'delete_mentor')) {
            wp_send_json_error('Unauthorized');
        }
        
        $mentor_id = isset($_POST['mentor_id']) ? intval($_POST['mentor_id']) : 0;
        
        if (!$mentor_id) {
            wp_send_json_error('Invalid mentor ID');
        }
        
        global $wpdb;
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        
        $result = $wpdb->delete($table_mentors, array('id' => $mentor_id), array('%d'));
        
        if ($result === false) {
            wp_send_json_error('Failed to delete mentor');
        }
        
        wp_send_json_success('Mentor deleted successfully');
    }

    /**
     * AJAX handler to get student data for editing
     */
    public function ajax_get_student_data() {
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'get_student_data')) {
            wp_send_json_error('Unauthorized');
        }
        
        $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
        
        if (!$student_id) {
            wp_send_json_error('Invalid student ID');
        }
        
        global $wpdb;
        $table_students = $wpdb->prefix . 'gears_students';
        
        $student = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_students WHERE id = %d", $student_id));
        
        if (!$student) {
            wp_send_json_error('Student not found');
        }
        
        wp_send_json_success($student);
    }
    
    /**
     * AJAX handler to delete a student
     */
    public function ajax_delete_student() {
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'delete_student')) {
            wp_send_json_error('Unauthorized');
        }
        
        $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
        
        if (!$student_id) {
            wp_send_json_error('Invalid student ID');
        }
        
        global $wpdb;
        $table_students = $wpdb->prefix . 'gears_students';
        
        $result = $wpdb->delete($table_students, array('id' => $student_id), array('%d'));
        
        if ($result === false) {
            wp_send_json_error('Failed to delete student');
        }
        
        wp_send_json_success('Student deleted successfully');
    }

    /**
     * AJAX handler to archive a team
     */
    public function ajax_archive_team() {
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'qbo_archive_team')) {
            wp_send_json_error('Unauthorized');
        }
        
        $team_id = isset($_POST['team_id']) ? intval($_POST['team_id']) : 0;
        
        if (!$team_id) {
            wp_send_json_error('Invalid team ID');
        }
        
        global $wpdb;
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        // Ensure the archived column exists
        $this->ensure_database_schema();
        
        // Update the team to set archived = 1
        $result = $wpdb->update(
            $table_teams,
            array('archived' => 1),
            array('id' => $team_id),
            array('%d'),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error('Failed to move team to past teams: ' . $wpdb->last_error);
        }
        
        wp_send_json_success('Team moved to past teams successfully');
    }

    /**
     * AJAX handler to restore a team (unarchive)
     */
    public function ajax_restore_team() {
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'qbo_restore_team')) {
            wp_send_json_error('Unauthorized');
        }
        
        $team_id = isset($_POST['team_id']) ? intval($_POST['team_id']) : 0;
        
        if (!$team_id) {
            wp_send_json_error('Invalid team ID');
        }
        
        global $wpdb;
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        // Ensure the archived column exists
        $this->ensure_database_schema();
        
        // Update the team to set archived = 0
        $result = $wpdb->update(
            $table_teams,
            array('archived' => 0),
            array('id' => $team_id),
            array('%d'),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error('Failed to restore team: ' . $wpdb->last_error);
        }
        
        wp_send_json_success('Team restored successfully');
    }

    /**
     * Stub method for Teams submenu page
     */
    public function teams_page() {
        echo '<div class="wrap"><h1>Teams</h1><p>This is the Teams admin page. Implement your team management UI here.</p></div>';
    }

    /**
     * Render team details page
     */
    public function render_team_details_page($team_id) {
        global $wpdb;
        
        $table_teams = $wpdb->prefix . 'gears_teams';
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        
        // Handle form submissions for team details page
        if ($_POST) {
            $this->handle_form_submissions($table_teams, $table_mentors);
        }
        
        // Get team details
        $team = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_teams WHERE id = %d", $team_id));
        
        if (!$team) {
            echo '<div class="wrap">';
            echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">';
            echo '<h1>Team Details</h1>';
            echo '<a href="' . admin_url('admin.php?page=qbo-teams') . '" class="button">Back to Teams</a>';
            echo '</div>';
            echo '<div class="notice notice-error"><p>Team not found.</p></div>';
            echo '</div>';
            return;
        }
        
        // Get recurring invoices for this team
        $recurring_invoices = $this->core->fetch_recurring_invoices_by_team($team_id);
        
        // Enqueue shared modal CSS
        if (function_exists('wp_enqueue_style')) {
            wp_enqueue_style('qbo-gears-modals', plugins_url('assets/css/modals.css', dirname(__FILE__, 2) . '/qbo-recurring-billing.php'), array(), null);
        }
        ?>
        
        <style>
            /* Team Details Page Specific Styles */
            .team-details-container {
                max-width: 1200px;
                display: flex !important;
                gap: 32px;
                margin: 0 auto;
            }
            .team-details-main {
                flex: 0 0 66% !important;
                max-width: 66%;
                min-width: 0;
            }
            .team-details-sidebar {
                flex: 0 0 34% !important;
                max-width: 34%;
                min-width: 280px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 24px;
                box-sizing: border-box;
                display: flex;
                flex-direction: column;
                gap: 24px;
                height: fit-content;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            }
            
            .team-logo {
                width: 100%;
                max-width: 250px;
                height: auto;
                object-fit: contain;
                border-radius: 8px;
                border: 1px solid #ddd;
                background: #fafbfc;
                display: block;
                margin: 0 auto;
            }
            .team-photo {
                width: 100%;
                height: auto;
                border-radius: 8px;
                border: 1px solid #ddd;
                background: #fafbfc;
                display: block;
                margin: 0 auto;
            }
            .team-social-links {
                display: flex;
                flex-direction: column;
                gap: 12px;
                width: 100%;
            }
            .team-social-links a {
                display: flex;
                align-items: center;
                text-decoration: none;
                padding: 8px 12px;
                border-radius: 6px;
                background: #f8f9fa;
                border: 1px solid #e0e1e2;
                transition: all 0.2s ease;
                color: #2c3e50;
            }
            .team-social-links a:hover {
                background: #e9ecef;
                border-color: #007cba;
                color: #007cba;
                transform: translateY(-1px);
            }
            .team-social-links .dashicons {
                margin-right: 8px;
                font-size: 16px;
            }
            .sidebar-section {
                width: 100%;
            }
            .sidebar-section h3 {
                font-size: 16px;
                font-weight: 600;
                margin: 0 0 12px 0;
                color: #2c3e50;
                text-align: center;
                border-bottom: 2px solid #e0e1e2;
                padding-bottom: 8px;
            }
            .team-website {
                text-align: center;
                margin: 16px 0;
            }
            .team-website a {
                display: inline-block;
                padding: 10px 16px;
                background: #007cba;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 600;
                transition: background 0.2s ease;
            }
            .team-website a:hover {
                background: #005a87;
            }
            .team-section {
                background: white;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .team-section h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            
            /* Responsive design for mobile devices */
            @media (max-width: 768px) {
                .team-details-container {
                    flex-direction: column !important;
                    gap: 20px;
                }
                .team-details-main,
                .team-details-sidebar {
                    flex: none !important;
                    max-width: 100% !important;
                    min-width: 0;
                }
            }
        </style>
        
        <div class="wrap">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1><?php echo esc_html($team->team_name); ?>
                    <?php if (!empty($team->team_number)): ?>
                        <small>(#<?php echo esc_html($team->team_number); ?>)</small>
                    <?php endif; ?>
                </h1>
                <a href="<?php echo admin_url('admin.php?page=qbo-teams'); ?>" class="button">Back to Teams</a>
            </div>
            <div class="team-details-container">
                <div class="team-details-main">
                    <div class="team-main-info">
                        <?php if (!empty($team->program)): ?>
                            <div style="margin-bottom:10px;">
                            <?php
                            $program = strtolower(trim($team->program));
                            if ($program === 'ftc') {
                                echo '<img src="https://gears.org.in/wp-content/uploads/2025/07/FIRSTTech_iconHorz_RGB.png" alt="FTC Logo" style="width:250px;max-width:100%;height:auto;vertical-align:middle;">';
                            } elseif ($program === 'fll') {
                            echo '<img src="https://gears.org.in/wp-content/uploads/2025/07/FIRSTLego_iconHorz_RGB.png" alt="FLL Logo" style="width:250px;max-width:100%;height:auto;vertical-align:middle;">';
                        } else {
                            echo '<strong>Program:</strong> ' . esc_html($team->program);
                        }
                        ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($team->description)): ?>
                <div class="team-section">
                    <h2>Description</h2>
                    <p><?php echo esc_html($team->description); ?></p>
                </div>
                <?php endif; ?>
                <!-- Team Mentors Section -->
                <div class="team-section" id="team-mentors-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <h2 style="margin: 0;">Team Mentors</h2>
                        <button id="add-mentor-btn" class="button button-primary button-small">Add New Mentor</button>
                    </div>
                    <div id="team-mentors-list">Loading...</div>
                </div>
                <!-- Students Section -->
                <div class="team-section" id="team-students-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <h2 style="margin: 0;">Students in this Team</h2>
                        <button id="add-student-btn" class="button button-primary button-small">Add New Student</button>
                    </div>
                    <div id="team-students-list">Loading...</div>
                </div>
                <!-- Alumni Section -->
                <div class="team-section" id="team-alumni-section">
                    <h2>Alumni of this Team</h2>
                    <div id="team-alumni-list">Loading...</div>
                </div>
            </div>
            <div class="team-details-sidebar">
                <?php if (!empty($team->logo)): ?>
                    <div class="sidebar-section">
                        <h3>Team Logo</h3>
                        <img src="<?php echo esc_url($team->logo); ?>" alt="Team Logo" class="team-logo">
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($team->website) || !empty($team->facebook) || !empty($team->twitter) || !empty($team->instagram)): ?>
                    <div class="sidebar-section">
                        <h3>Contact & Social Media</h3>
                        
                        <?php if (!empty($team->website)): ?>
                            <div class="team-website">
                                <a href="<?php echo esc_url($team->website); ?>" target="_blank" rel="noopener">
                                    <span class="dashicons dashicons-admin-site-alt3"></span> Visit Website
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($team->facebook) || !empty($team->twitter) || !empty($team->instagram)): ?>
                            <div class="team-social-links">
                                <?php if (!empty($team->facebook)): ?>
                                    <a href="<?php echo esc_url($team->facebook); ?>" target="_blank" rel="noopener">
                                        <span class="dashicons dashicons-facebook"></span> Facebook
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($team->twitter)): ?>
                                    <a href="<?php echo esc_url($team->twitter); ?>" target="_blank" rel="noopener">
                                        <span class="dashicons dashicons-twitter"></span> Twitter
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($team->instagram)): ?>
                                    <a href="<?php echo esc_url($team->instagram); ?>" target="_blank" rel="noopener">
                                        <span class="dashicons dashicons-instagram"></span> Instagram
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($team->team_photo)): ?>
                    <div class="sidebar-section">
                        <h3>Team Photo</h3>
                        <img src="<?php echo esc_url($team->team_photo); ?>" alt="Team Photo" class="team-photo">
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <script type="text/javascript">
        // Make sure ajaxurl is available
        if (typeof ajaxurl === 'undefined') {
            var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        }
        
        window.qboCustomerListVars = window.qboCustomerListVars || {};
        qboCustomerListVars.invoicesPageUrl = "<?php echo esc_js(admin_url('admin.php?page=qbo-view-invoices')); ?>";
        qboCustomerListVars.nonce = "<?php echo wp_create_nonce('qbo_get_customers'); ?>";
        </script>
        
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var teamId = <?php echo intval($team_id); ?>;
                
                // Load mentors for this team
                loadTeamMentors();
                
                // Fetch and display students for this team
                if (typeof qboCustomerListVars !== 'undefined') {
                    jQuery.post(ajaxurl, {
                        action: 'qbo_get_team_students',
                        team_id: teamId,
                        nonce: qboCustomerListVars.nonce
                    }, function(resp) {
                        var studentsDiv = document.getElementById('team-students-list');
                        var alumniDiv = document.getElementById('team-alumni-list');
                        if (resp.success && Array.isArray(resp.data) && resp.data.length) {
                            var students = [];
                            var alumni = [];
                            resp.data.forEach(function(student) {
                                if ((student.grade || '').toLowerCase() === 'alumni') {
                                    alumni.push(student);
                                } else {
                                    students.push(student);
                                }
                            });
                            // Students table
                            if (students.length) {
                                var html = '<table class="wp-list-table widefat fixed striped">';
                                html += '<thead><tr>';
                                html += '<th>Student Name</th>';
                                html += '<th style="width: 45px;">Grade</th>';
                                html += '<th style="width: 45px;">First Year</th>';
                                html += '<th>Parent Name</th>';
                                html += '<th nowrap style="width: 45px;">Balance</th>';
                                html += '<th style="width: 45px;">Status</th>';
                                html += '<th>Actions</th>';
                                html += '</tr></thead>';
                                html += '<tbody>';
                                students.forEach(function(student) {
                                    html += '<tr>';
                                    html += '<td><strong>' + student.student_name + '</strong></td>';
                                    // Format grade display
                                    var gradeDisplay = student.grade || 'N/A';
                                    if (gradeDisplay && gradeDisplay !== 'N/A' && gradeDisplay !== 'Alumni') {
                                        gradeDisplay = gradeDisplay + 'th Grade';
                                    }
                                    html += '<td>' + gradeDisplay + '</td>';
                                    html += '<td>' + (student.first_year_first || 'N/A') + '</td>';
                                    html += '<td>' + student.parent_name + '</td>';
                                    html += '<td>$' + parseFloat(student.balance).toFixed(2) + '</td>';
                                    // Add status column with styling
                                    var statusClass = student.status === 'Active' ? 'status-active' : 'status-inactive';
                                    html += '<td><span class="status-badge ' + statusClass + '">' + student.status + '</span></td>';
                                    html += '<td nowrap>';
                                    if (student.customer_id) {
                                        html += '<a href="' + qboCustomerListVars.invoicesPageUrl + '&member_id=' + encodeURIComponent(student.customer_id) + '" class="button button-small view-student-invoices">' +
                                               'Details</a> ';
                                    }
                                    // Add Edit and Delete buttons for students
                                    html += '<button type="button" class="button button-small edit-student-btn" data-student-id="' + student.student_id + '">Edit</button> ';
                                    html += '<button type="button" class="button button-small button-link-delete delete-student-btn" data-student-id="' + student.student_id + '" data-student-name="' + student.student_name + '">Delete</button>';
                                    html += '</td>';
                                    html += '</tr>';
                                });
                                html += '</tbody></table>';
                                studentsDiv.innerHTML = html;
                            } else {
                                studentsDiv.innerHTML = '<em>No students assigned to this team.</em>';
                            }
                            // Alumni table
                            if (alumni.length) {
                                var htmlA = '<table class="wp-list-table widefat fixed striped">';
                                htmlA += '<thead><tr>';
                                htmlA += '<th>Name</th>';
                                htmlA += '<th style="width: 45px;">First Year</th>';
                                htmlA += '<th>Parent Name</th>';
                                htmlA += '<th nowrap style="width: 45px;">Balance</th>';
                                htmlA += '<th style="width: 45px;">Status</th>';
                                htmlA += '<th>Actions</th>';
                                htmlA += '</tr></thead>';
                                html += '<tbody>';
                                alumni.forEach(function(student) {
                                    htmlA += '<tr>';
                                    htmlA += '<td><strong>' + student.student_name + '</strong></td>';
                                    htmlA += '<td>' + (student.first_year_first || 'N/A') + '</td>';
                                    htmlA += '<td>' + student.parent_name + '</td>';
                                    htmlA += '<td>$' + parseFloat(student.balance).toFixed(2) + '</td>';
                                    // Add status column with styling
                                    var statusClass = student.status === 'Active' ? 'status-active' : 'status-inactive';
                                    htmlA += '<td><span class="status-badge ' + statusClass + '">' + student.status + '</span></td>';
                                    htmlA += '<td>';
                                    if (student.customer_id) {
                                        htmlA += '<a href="' + qboCustomerListVars.invoicesPageUrl + '&member_id=' + encodeURIComponent(student.customer_id) + '" class="button button-small view-student-invoices">' +
                                               'Details</a> ';
                                    }
                                    // Add Edit and Delete buttons for alumni
                                    htmlA += '<button type="button" class="button button-small edit-student-btn" data-student-id="' + student.student_id + '">Edit</button> ';
                                    htmlA += '<button type="button" class="button button-small button-link-delete delete-student-btn" data-student-id="' + student.student_id + '" data-student-name="' + student.student_name + '">Delete</button>';
                                    htmlA += '</td>';
                                    htmlA += '</tr>';
                                });
                                htmlA += '</tbody></table>';
                                alumniDiv.innerHTML = htmlA;
                            } else {
                                alumniDiv.innerHTML = '<em>No alumni for this team.</em>';
                            }
                        } else {
                            studentsDiv.innerHTML = '<em>No students assigned to this team.</em>';
                            alumniDiv.innerHTML = '<em>No alumni for this team.</em>';
                        }
                    }).fail(function() {
                        document.getElementById('team-students-list').innerHTML = '<em>Error loading students.</em>';
                        document.getElementById('team-alumni-list').innerHTML = '<em>Error loading alumni.</em>';
                    });
                } else {
                    document.getElementById('team-students-list').innerHTML = '<em>Unable to load students (missing nonce).</em>';
                    document.getElementById('team-alumni-list').innerHTML = '<em>Unable to load alumni (missing nonce).</em>';
                }
                
                // Add modal handlers
                $('#add-mentor-btn').click(function() {
                    openAddMentorModal();
                });
                
                $('#add-student-btn').click(function() {
                    openAddStudentModal();
                });
                
                // Handle edit mentor button clicks (using event delegation)
                $(document).on('click', '.edit-mentor-btn', function() {
                    var mentorId = $(this).data('mentor-id');
                    loadMentorData(mentorId);
                });
                
                // Handle delete mentor button clicks (using event delegation)
                $(document).on('click', '.delete-mentor-btn', function() {
                    var mentorId = $(this).data('mentor-id');
                    var mentorName = $(this).data('mentor-name');
                    
                    if (confirm('Are you sure you want to delete mentor "' + mentorName + '"? This action cannot be undone.')) {
                        deleteMentor(mentorId, mentorName);
                    }
                });
                
                // Handle edit student button clicks (using event delegation)
                $(document).on('click', '.edit-student-btn', function() {
                    var studentId = $(this).data('student-id');
                    loadStudentData(studentId);
                });
                
                // Handle delete student button clicks (using event delegation)
                $(document).on('click', '.delete-student-btn', function() {
                    var studentId = $(this).data('student-id');
                    var studentName = $(this).data('student-name');
                    
                    if (confirm('Are you sure you want to delete student "' + studentName + '"? This action cannot be undone.')) {
                        deleteStudent(studentId, studentName);
                    }
                });
                
                // Handle form submissions via AJAX
                $('#add-mentor-form').submit(function(e) {
                    e.preventDefault();
                    var formData = new FormData(this);
                    
                    $.ajax({
                        url: '<?php echo admin_url('admin.php?page=qbo-teams&action=view&team_id=' . intval($team_id)); ?>',
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(response) {
                            // Check if the response contains success or error messages
                            var $response = $(response);
                            var $successNotice = $response.find('.notice-success');
                            var $errorNotice = $response.find('.notice-error');
                            
                            if ($successNotice.length > 0) {
                                // Success - close modal and reload page
                                closeAddMentorModal();
                                alert('Mentor added successfully!');
                                location.reload();
                            } else if ($errorNotice.length > 0) {
                                // Show error message
                                var errorText = $errorNotice.find('p').text();
                                alert('Error: ' + errorText);
                            } else {
                                // No specific notice found, assume success
                                closeAddMentorModal();
                                alert('Mentor added successfully!');
                                location.reload();
                            }
                        },
                        error: function() {
                            alert('Error adding mentor. Please try again.');
                        }
                    });
                });
                
                // Handle edit mentor form submission
                $('#edit-mentor-form').submit(function(e) {
                    e.preventDefault();
                    var formData = new FormData(this);
                    
                    $.ajax({
                        url: '<?php echo admin_url('admin.php?page=qbo-teams&action=view&team_id=' . intval($team_id)); ?>',
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(response) {
                            // Check if the response contains success or error messages
                            var $response = $(response);
                            var $successNotice = $response.find('.notice-success');
                            var $errorNotice = $response.find('.notice-error');
                            
                            if ($successNotice.length > 0) {
                                // Success - close modal and reload mentors
                                closeEditMentorModal();
                                alert('Mentor updated successfully!');
                                loadTeamMentors(); // Reload just the mentors instead of whole page
                            } else if ($errorNotice.length > 0) {
                                // Show error message
                                var errorText = $errorNotice.find('p').text();
                                alert('Error: ' + errorText);
                            } else {
                                // No specific notice found, assume success
                                closeEditMentorModal();
                                alert('Mentor updated successfully!');
                                loadTeamMentors(); // Reload just the mentors instead of whole page
                            }
                        },
                        error: function() {
                            alert('Error updating mentor. Please try again.');
                        }
                    });
                });
                
                $('#add-student-form').submit(function(e) {
                    e.preventDefault();
                    var formData = new FormData(this);
                    
                    $.ajax({
                        url: '<?php echo admin_url('admin.php?page=qbo-teams&action=view&team_id=' . intval($team_id)); ?>',
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(response) {
                            // Check if the response contains success or error messages
                            var $response = $(response);
                            var $successNotice = $response.find('.notice-success');
                            var $errorNotice = $response.find('.notice-error');
                            
                            if ($successNotice.length > 0) {
                                // Success - close modal and reload page
                                closeAddStudentModal();
                                alert('Student added successfully!');
                                location.reload();
                            } else if ($errorNotice.length > 0) {
                                // Show error message
                                var errorText = $errorNotice.find('p').text();
                                alert('Error: ' + errorText);
                            } else {
                                // No specific notice found, assume success
                                closeAddStudentModal();
                                alert('Student added successfully!');
                                location.reload();
                            }
                        },
                        error: function() {
                            alert('Error adding student. Please try again.');
                        }
                    });
                });
                
                // Handle edit student form submission
                $('#edit-student-form').submit(function(e) {
                    e.preventDefault();
                    var formData = new FormData(this);
                    
                    $.ajax({
                        url: '<?php echo admin_url('admin.php?page=qbo-teams&action=view&team_id=' . intval($team_id)); ?>',
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(response) {
                            // Check if the response contains success or error messages
                            var $response = $(response);
                            var $successNotice = $response.find('.notice-success');
                            var $errorNotice = $response.find('.notice-error');
                            
                            if ($successNotice.length > 0) {
                                // Success - close modal and reload page
                                closeEditStudentModal();
                                alert('Student updated successfully!');
                                location.reload();
                            } else if ($errorNotice.length > 0) {
                                // Show error message
                                var errorText = $errorNotice.find('p').text();
                                alert('Error: ' + errorText);
                            } else {
                                // No specific notice found, assume success
                                closeEditStudentModal();
                                alert('Student updated successfully!');
                                location.reload();
                            }
                        },
                        error: function() {
                            alert('Error updating student. Please try again.');
                        }
                    });
                });
            });
            
            // Modal functions
            function openAddMentorModal() {
                document.getElementById('add-mentor-modal-overlay').classList.add('active');
            }
            
            function closeAddMentorModal() {
                document.getElementById('add-mentor-modal-overlay').classList.remove('active');
                document.getElementById('add-mentor-form').reset();
            }
            
            function openAddStudentModal() {
                document.getElementById('add-student-modal-overlay').classList.add('active');
            }
            
            function closeAddStudentModal() {
                document.getElementById('add-student-modal-overlay').classList.remove('active');
                document.getElementById('add-student-form').reset();
            }
            
            function openEditMentorModal() {
                document.getElementById('edit-mentor-modal-overlay').classList.add('active');
            }
            
            function closeEditMentorModal() {
                document.getElementById('edit-mentor-modal-overlay').classList.remove('active');
                document.getElementById('edit-mentor-form').reset();
            }
            
            function openEditStudentModal() {
                document.getElementById('edit-student-modal-overlay').classList.add('active');
            }
            
            function closeEditStudentModal() {
                document.getElementById('edit-student-modal-overlay').classList.remove('active');
                document.getElementById('edit-student-form').reset();
            }
            
            function loadMentorData(mentorId) {
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_mentor_data',
                        mentor_id: mentorId,
                        nonce: '<?php echo wp_create_nonce('get_mentor_data'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            const mentor = response.data;
                            document.getElementById('edit-mentor-id').value = mentor.id;
                            document.getElementById('edit-mentor-first-name').value = mentor.first_name || '';
                            document.getElementById('edit-mentor-last-name').value = mentor.last_name || '';
                            document.getElementById('edit-mentor-email').value = mentor.email || '';
                            document.getElementById('edit-mentor-phone').value = mentor.phone || '';
                            document.getElementById('edit-mentor-address').value = mentor.address || '';
                            document.getElementById('edit-mentor-notes').value = mentor.notes || '';
                            openEditMentorModal();
                        } else {
                            alert('Error loading mentor data: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('An error occurred while loading mentor data.');
                    }
                });
            }
            
            function deleteMentor(mentorId, mentorName) {
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'delete_mentor',
                        mentor_id: mentorId,
                        nonce: '<?php echo wp_create_nonce('delete_mentor'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Mentor deleted successfully!');
                            // Reload the mentors list
                            loadTeamMentors();
                        } else {
                            alert('Error deleting mentor: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('An error occurred while deleting mentor.');
                    }
                });
            }
            
            function loadStudentData(studentId) {
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_student_data',
                        student_id: studentId,
                        nonce: '<?php echo wp_create_nonce('get_student_data'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            const student = response.data;
                            document.getElementById('edit-student-id').value = student.id;
                            document.getElementById('edit-student-first-name').value = student.first_name || '';
                            document.getElementById('edit-student-last-name').value = student.last_name || '';
                            document.getElementById('edit-student-grade').value = student.grade || '';
                            document.getElementById('edit-student-first-year').value = student.first_year_first || '';
                            document.getElementById('edit-student-customer-id').value = student.customer_id || '';
                            openEditStudentModal();
                        } else {
                            alert('Error loading student data: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('An error occurred while loading student data.');
                    }
                });
            }
            
            function deleteStudent(studentId, studentName) {
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'delete_student',
                        student_id: studentId,
                        nonce: '<?php echo wp_create_nonce('delete_student'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Student deleted successfully!');
                            // Reload the page to refresh both students and alumni lists
                            location.reload();
                        } else {
                            alert('Error deleting student: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('An error occurred while deleting student.');
                    }
                });
            }
            
            function loadTeamMentors() {
                var teamId = <?php echo intval($team_id); ?>;
                
                // Fetch and display mentors for this team
                if (typeof qboCustomerListVars !== 'undefined') {
                    jQuery.post(ajaxurl, {
                        action: 'qbo_get_team_mentors',
                        team_id: teamId,
                        nonce: qboCustomerListVars.nonce
                    }, function(resp) {
                        var mentorsDiv = document.getElementById('team-mentors-list');
                        if (resp.success && Array.isArray(resp.data) && resp.data.length) {
                            var html = '<table class="wp-list-table widefat fixed striped">';
                            html += '<thead><tr>';
                            html += '<th>Name</th>';
                            html += '<th>Email</th>';
                            html += '<th>Phone</th>';
                            html += '<th align=right>Actions</th>';
                            html += '</tr></thead>';
                            html += '<tbody>';
                            
                            resp.data.forEach(function(mentor) {
                                html += '<tr>';
                                html += '<td><strong>' + mentor.full_name + '</strong></td>';
                                html += '<td>' + (mentor.email || 'N/A') + '</td>';
                                html += '<td>' + (mentor.phone || 'N/A') + '</td>';
                                html += '<td align=right>';
                                html += '<button type="button" class="button button-small edit-mentor-btn" data-mentor-id="' + mentor.id + '">Edit</button> ';
                                html += '<button type="button" class="button button-small button-link-delete delete-mentor-btn" data-mentor-id="' + mentor.id + '" data-mentor-name="' + mentor.full_name + '">Delete</button>';
                                html += '</td>';
                                html += '</tr>';
                            });
                            
                            html += '</tbody></table>';
                            mentorsDiv.innerHTML = html;
                        } else {
                            mentorsDiv.innerHTML = '<em>No mentors assigned to this team.</em>';
                        }
                    }).fail(function() {
                        document.getElementById('team-mentors-list').innerHTML = '<em>Error loading mentors.</em>';
                    });
                } else {
                    document.getElementById('team-mentors-list').innerHTML = '<em>Unable to load mentors (missing nonce).</em>';
                }
            }
            
            // Close modal when clicking the X button
            $(document).on('click', '.gears-modal-close', function() {
                var modalOverlay = $(this).closest('.gears-modal-overlay');
                modalOverlay.removeClass('active');
                modalOverlay.find('form').trigger('reset');
            });

            // Close modal when clicking outside of it
            $(document).on('click', '.gears-modal-overlay', function(e) {
                if (e.target === this) {
                    $(this).removeClass('active');
                    $(this).find('form').trigger('reset');
                }
            });
        </script>
        
        <!-- Add Mentor Modal -->
        <div id="add-mentor-modal-overlay" class="gears-modal-overlay">
            <div class="gears-modal">
                <span class="gears-modal-close" id="close-add-mentor-modal">&times;</span>
                <div class="mentor-form-card" style="box-shadow:none; border:none; max-width:100%; margin:0; padding:0;">
                    <h2 style="border:none; padding-bottom:0; margin-top:0;">Add New Mentor</h2>
                    <form method="post" id="add-mentor-form">
                        <?php wp_nonce_field('add_mentor_action', 'mentor_nonce'); ?>
                        <input type="hidden" name="add_mentor" value="1" />
                        <input type="hidden" name="team_id" value="<?php echo intval($team_id); ?>" />
                        <table class="form-table">
                            <tr>
                                <th scope="row">First Name <span style="color:red;">*</span></th>
                                <td><input type="text" name="first_name" required style="width: 100%;" /></td>
                            </tr>
                            <tr>
                                <th scope="row">Last Name <span style="color:red;">*</span></th>
                                <td><input type="text" name="last_name" required style="width: 100%;" /></td>
                            </tr>
                            <tr>
                                <th scope="row">Email</th>
                                <td><input type="email" name="email" style="width: 100%;" /></td>
                            </tr>
                            <tr>
                                <th scope="row">Phone</th>
                                <td><input type="text" name="phone" style="width: 100%;" /></td>
                            </tr>
                            <tr>
                                <th scope="row">Address</th>
                                <td><textarea name="address" rows="3" style="width: 100%;"></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row">Notes</th>
                                <td><textarea name="notes" rows="3" style="width: 100%;"></textarea></td>
                            </tr>
                        </table>
                        <p class="submit">
                            <input type="submit" class="button-primary" value="Add Mentor" />
                            <button type="button" class="button" onclick="closeAddMentorModal()">Cancel</button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Edit Mentor Modal -->
        <div id="edit-mentor-modal-overlay" class="gears-modal-overlay">
            <div class="gears-modal">
                <span class="gears-modal-close" id="close-edit-mentor-modal">&times;</span>
                <div class="mentor-form-card" style="box-shadow:none; border:none; max-width:100%; margin:0; padding:0;">
                    <h2 style="border:none; padding-bottom:0; margin-top:0;">Edit Mentor</h2>
                    <form method="post" id="edit-mentor-form">
                        <?php wp_nonce_field('edit_mentor_action', 'edit_mentor_nonce'); ?>
                        <input type="hidden" name="edit_mentor" value="1" />
                        <input type="hidden" name="team_id" value="<?php echo intval($team_id); ?>" />
                        <input type="hidden" name="mentor_id" id="edit-mentor-id" />
                        <table class="form-table">
                            <tr>
                                <th scope="row">First Name <span style="color:red;">*</span></th>
                                <td><input type="text" name="first_name" id="edit-mentor-first-name" required style="width: 100%;" /></td>
                            </tr>
                            <tr>
                                <th scope="row">Last Name <span style="color:red;">*</span></th>
                                <td><input type="text" name="last_name" id="edit-mentor-last-name" required style="width: 100%;" /></td>
                            </tr>
                            <tr>
                                <th scope="row">Email</th>
                                <td><input type="email" name="email" id="edit-mentor-email" style="width: 100%;" /></td>
                            </tr>
                            <tr>
                                <th scope="row">Phone</th>
                                <td><input type="text" name="phone" id="edit-mentor-phone" style="width: 100%;" /></td>
                            </tr>
                            <tr>
                                <th scope="row">Address</th>
                                <td><textarea name="address" id="edit-mentor-address" rows="3" style="width: 100%;"></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row">Notes</th>
                                <td><textarea name="notes" id="edit-mentor-notes" rows="3" style="width: 100%;"></textarea></td>
                            </tr>
                        </table>
                        <p class="submit">
                            <input type="submit" class="button-primary" value="Update Mentor" />
                            <button type="button" class="button" onclick="closeEditMentorModal()">Cancel</button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Add Student Modal -->
        <div id="add-student-modal-overlay" class="gears-modal-overlay">
            <div class="gears-modal">
                <span class="gears-modal-close" id="close-add-student-modal">&times;</span>
                <div class="team-form-card" style="box-shadow:none; border:none; max-width:100%; margin:0; padding:0;">
                    <h2 style="border:none; padding-bottom:0; margin-top:0;">Add New Student</h2>
                    <form method="post" id="add-student-form">
                        <?php wp_nonce_field('add_student_action', 'student_nonce'); ?>
                        <input type="hidden" name="add_student" value="1" />
                        <input type="hidden" name="team_id" value="<?php echo intval($team_id); ?>" />
                        <table class="form-table">
                            <tr><th><label for="first_name">First Name *</label></th><td><input type="text" id="first_name" name="first_name" required class="regular-text" /></td></tr>
                            <tr><th><label for="last_name">Last Name *</label></th><td><input type="text" id="last_name" name="last_name" required class="regular-text" /></td></tr>
                            <tr><th><label for="grade">Grade Level</label></th><td>
                                <select id="grade" name="grade" class="regular-text">
                                    <option value="">Select grade...</option>
                                    <option value="9">9th Grade</option>
                                    <option value="10">10th Grade</option>
                                    <option value="11">11th Grade</option>
                                    <option value="12">12th Grade</option>
                                    <option value="Alumni">Alumni</option>
                                </select>
                            </td></tr>
                            <tr><th><label for="first_year_first">First Year</label></th><td><input type="text" id="first_year_first" name="first_year_first" class="regular-text" /></td></tr>
                            <tr><th><label for="customer_id">Customer Affiliation</label></th><td>
                                <select name="customer_id" id="customer_id">
                                    <option value="">No Customer</option>
                                    <?php
                                    // Get customers from QBO cache
                                    $cache = get_option('qbo_recurring_billing_customers_cache', array());
                                    if (isset($cache['data']) && is_array($cache['data'])) {
                                        foreach ($cache['data'] as $customer) {
                                            $customer_name = '';
                                            // Try to build a proper display name
                                            if (!empty($customer['DisplayName'])) {
                                                $customer_name = $customer['DisplayName'];
                                            } elseif (!empty($customer['CompanyName'])) {
                                                $customer_name = $customer['CompanyName'];
                                            } elseif (!empty($customer['GivenName']) || !empty($customer['FamilyName'])) {
                                                $customer_name = trim(($customer['GivenName'] ?? '') . ' ' . ($customer['FamilyName'] ?? ''));
                                            } else {
                                                $customer_name = 'Customer #' . $customer['Id'];
                                            }
                                            echo '<option value="' . esc_attr($customer['Id']) . '">' . esc_html($customer_name) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </td></tr>
                        </table>
                        <p class="submit">
                            <input type="submit" class="button-primary" value="Add Student" />
                            <button type="button" class="button" onclick="closeAddStudentModal()">Cancel</button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Edit Student Modal -->
        <div id="edit-student-modal-overlay" class="gears-modal-overlay">
            <div class="gears-modal">
                <span class="gears-modal-close" id="close-edit-student-modal">&times;</span>
                <div class="team-form-card" style="box-shadow:none; border:none; max-width:100%; margin:0; padding:0;">
                    <h2 style="border:none; padding-bottom:0; margin-top:0;">Edit Student</h2>
                    <form method="post" id="edit-student-form">
                        <?php wp_nonce_field('edit_student_action', 'edit_student_nonce'); ?>
                        <input type="hidden" name="edit_student" value="1" />
                        <input type="hidden" name="team_id" value="<?php echo intval($team_id); ?>" />
                        <input type="hidden" name="student_id" id="edit-student-id" />
                        <table class="form-table">
                            <tr><th><label for="edit-student-first-name">First Name *</label></th><td><input type="text" id="edit-student-first-name" name="first_name" required class="regular-text" /></td></tr>
                            <tr><th><label for="edit-student-last-name">Last Name *</label></th><td><input type="text" id="edit-student-last-name" name="last_name" required class="regular-text" /></td></tr>
                            <tr><th><label for="edit-student-grade">Grade Level</label></th><td>
                                <select id="edit-student-grade" name="grade" class="regular-text">
                                    <option value="">Select grade...</option>
                                    <option value="9">9th Grade</option>
                                    <option value="10">10th Grade</option>
                                    <option value="11">11th Grade</option>
                                    <option value="12">12th Grade</option>
                                    <option value="Alumni">Alumni</option>
                                </select>
                            </td></tr>
                            <tr><th><label for="edit-student-first-year">First Year</label></th><td><input type="text" id="edit-student-first-year" name="first_year_first" class="regular-text" /></td></tr>
                            <tr><th><label for="edit-student-customer-id">Customer Affiliation</label></th><td>
                                <select name="customer_id" id="edit-student-customer-id">
                                    <option value="">No Customer</option>
                                    <?php
                                    // Get customers from QBO cache
                                    $cache = get_option('qbo_recurring_billing_customers_cache', array());
                                    if (isset($cache['data']) && is_array($cache['data'])) {
                                        foreach ($cache['data'] as $customer) {
                                            $customer_name = '';
                                            // Try to build a proper display name
                                            if (!empty($customer['DisplayName'])) {
                                                $customer_name = $customer['DisplayName'];
                                            } elseif (!empty($customer['CompanyName'])) {
                                                $customer_name = $customer['CompanyName'];
                                            } elseif (!empty($customer['GivenName']) || !empty($customer['FamilyName'])) {
                                                $customer_name = trim(($customer['GivenName'] ?? '') . ' ' . ($customer['FamilyName'] ?? ''));
                                            } else {
                                                $customer_name = 'Customer #' . $customer['Id'];
                                            }
                                            echo '<option value="' . esc_attr($customer['Id']) . '">' . esc_html($customer_name) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </td></tr>
                        </table>
                        <p class="submit">
                            <input type="submit" class="button-primary" value="Update Student" />
                            <button type="button" class="button" onclick="closeEditStudentModal()">Cancel</button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        
        </div> <!-- Close wrap -->
        <?php
    }
    
    /**
     * Handle add mentor
     */
    private function handle_add_mentor($table_mentors) {
        global $wpdb;
        
        $team_id = intval($_POST['team_id']);
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);
        $address = sanitize_textarea_field($_POST['address']);
        $notes = sanitize_textarea_field($_POST['notes']);
        
        if (!empty($first_name) && !empty($last_name)) {
            $result = $wpdb->insert(
                $table_mentors,
                array(
                    'team_id' => $team_id > 0 ? $team_id : null,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'phone' => $phone,
                    'address' => $address,
                    'notes' => $notes
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            
            if ($result === false) {
                echo '<div class="notice notice-error"><p>Error adding mentor: ' . esc_html($wpdb->last_error) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Mentor added successfully!</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>First name and last name are required.</p></div>';
        }
    }

    /**
     * Handle edit mentor
     */
    private function handle_edit_mentor($table_mentors) {
        global $wpdb;
        
        $mentor_id = intval($_POST['mentor_id']);
        $team_id = intval($_POST['team_id']);
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);
        $address = sanitize_textarea_field($_POST['address']);
        $notes = sanitize_textarea_field($_POST['notes']);
        
        if (!empty($first_name) && !empty($last_name) && $mentor_id > 0) {
            $result = $wpdb->update(
                $table_mentors,
                array(
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'phone' => $phone,
                    'address' => $address,
                    'notes' => $notes
                ),
                array('id' => $mentor_id),
                array('%s', '%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );
            
            if ($result === false) {
                echo '<div class="notice notice-error"><p>Error updating mentor: ' . esc_html($wpdb->last_error) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Mentor updated successfully!</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>First name, last name, and valid mentor ID are required.</p></div>';
        }
    }

    /**
     * Handle add student
     */
    private function handle_add_student() {
        global $wpdb;
        
        $table_students = $wpdb->prefix . 'gears_students';
        
        $team_id = intval($_POST['team_id']);
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $grade = sanitize_text_field($_POST['grade']);
        $first_year_first = sanitize_text_field($_POST['first_year_first']);
        $customer_id = sanitize_text_field($_POST['customer_id']);
        
        if (!empty($first_name) && !empty($last_name)) {
            $result = $wpdb->insert(
                $table_students,
                array(
                    'team_id' => $team_id > 0 ? $team_id : null,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'grade' => $grade,
                    'first_year_first' => $first_year_first,
                    'customer_id' => !empty($customer_id) ? $customer_id : null
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s')
            );
            
            if ($result === false) {
                echo '<div class="notice notice-error"><p>Error adding student.</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Student added successfully!</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>First name and last name are required.</p></div>';
        }
    }

    /**
     * Handle edit student
     */
    private function handle_edit_student() {
        global $wpdb;
        
        $table_students = $wpdb->prefix . 'gears_students';
        
        $student_id = intval($_POST['student_id']);
        $team_id = intval($_POST['team_id']);
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $grade = sanitize_text_field($_POST['grade']);
        $first_year_first = sanitize_text_field($_POST['first_year_first']);
        $customer_id = sanitize_text_field($_POST['customer_id']);
        
        if (!empty($first_name) && !empty($last_name) && $student_id > 0) {
            $result = $wpdb->update(
                $table_students,
                array(
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'grade' => $grade,
                    'first_year_first' => $first_year_first,
                    'customer_id' => !empty($customer_id) ? $customer_id : null
                ),
                array('id' => $student_id),
                array('%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );
            
            if ($result === false) {
                echo '<div class="notice notice-error"><p>Error updating student.</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Student updated successfully!</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>First name and last name are required.</p></div>';
        }
    }

    /**
     * AJAX handler for adding a team
     */
    public function ajax_add_team() {
        if (!current_user_can('manage_options') || !isset($_POST['team_nonce']) || !wp_verify_nonce($_POST['team_nonce'], 'add_team_action')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table_teams = $wpdb->prefix . 'gears_teams';

        // Set the $_POST flag for handle_add_team
        $_POST['add_team'] = '1';
        
        // Capture the output
        ob_start();
        $this->handle_add_team($table_teams);
        $output = ob_get_clean();

        // Check if it was successful
        if (strpos($output, 'notice-success') !== false) {
            wp_send_json_success('Team added successfully!');
        } else {
            // Extract error message
            preg_match('/<p>(.*?)<\/p>/', $output, $matches);
            $error_message = isset($matches[1]) ? $matches[1] : 'Unknown error occurred';
            wp_send_json_error($error_message);
        }
    }

    /**
     * AJAX handler for updating a team
     */
    public function ajax_update_team() {
        if (!current_user_can('manage_options') || !isset($_POST['team_edit_nonce']) || !wp_verify_nonce($_POST['team_edit_nonce'], 'update_team_action')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table_teams = $wpdb->prefix . 'gears_teams';

        // Set the $_POST flag for handle_update_team
        $_POST['update_team'] = '1';
        
        // Capture the output
        ob_start();
        $this->handle_update_team($table_teams);
        $output = ob_get_clean();

        // Check if it was successful
        if (strpos($output, 'notice-success') !== false) {
            wp_send_json_success('Team updated successfully!');
        } else {
            // Extract error message
            preg_match('/<p>(.*?)<\/p>/', $output, $matches);
            $error_message = isset($matches[1]) ? $matches[1] : 'Unknown error occurred';
            wp_send_json_error($error_message);
        }
    }
}
