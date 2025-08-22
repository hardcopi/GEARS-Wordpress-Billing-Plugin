<?php
/*
Plugin Name: QBO Recurring Billing
Description: Integrates QuickBooks Online recurring billing with GEARS teams, mentors, and customers.
Version: 1.0.0
Author: Your Name
*/


// Define plugin constants
if (!defined('QBO_PLUGIN_DIR')) {
    define('QBO_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('QBO_PLUGIN_URL')) {
    define('QBO_PLUGIN_URL', plugin_dir_url(__FILE__));
}

add_action('init', function() {
    // Add custom query var for mentor dashboard
    add_rewrite_rule('^mentor-dashboard/?$', 'index.php?mentor_dashboard=1', 'top');
});

// Add query var support
add_filter('query_vars', function($vars) {
    $vars[] = 'mentor_dashboard';
    return $vars;
});

// Handle mentor dashboard template
add_action('template_redirect', function() {
    if (get_query_var('mentor_dashboard')) {
        // Set up the environment for the mentor dashboard
        add_filter('show_admin_bar', '__return_false');
        
        // Check if this is an AJAX request and handle it properly
        if (isset($_POST['action']) && $_POST['action'] === 'qbo_upload_team_image') {
            // Handle AJAX upload within WordPress context
            do_action('wp_ajax_nopriv_qbo_upload_team_image');
            do_action('wp_ajax_qbo_upload_team_image');
            return;
        }
        
        // For normal page loads, include the mentor dashboard file
        // Make sure WordPress globals are available
        global $wpdb;
        
        // Include the mentor dashboard file
        include(plugin_dir_path(__FILE__) . 'qbo-register.php');
        exit;
    }
});

// Include class files
require_once QBO_PLUGIN_DIR . 'includes/class-qbo-core.php';
require_once QBO_PLUGIN_DIR . 'includes/class-qbo-dashboard.php';
require_once QBO_PLUGIN_DIR . 'includes/class-qbo-settings.php';
require_once QBO_PLUGIN_DIR . 'includes/class-qbo-customers.php';
require_once QBO_PLUGIN_DIR . 'includes/class-qbo-recurring-invoices.php';
require_once QBO_PLUGIN_DIR . 'includes/class-qbo-recurring-invoices-list-table.php';
require_once QBO_PLUGIN_DIR . 'includes/class-qbo-teams.php';
require_once QBO_PLUGIN_DIR . 'includes/class-qbo-teams-list-table.php';
require_once QBO_PLUGIN_DIR . 'includes/class-qbo-students.php';
require_once QBO_PLUGIN_DIR . 'includes/class-qbo-students-management-list-table.php';
require_once QBO_PLUGIN_DIR . 'includes/class-qbo-mentors.php';
require_once QBO_PLUGIN_DIR . 'includes/class-qbo-reports.php';
require_once QBO_PLUGIN_DIR . 'includes/class-qbo-communications.php';

// Main plugin class
class QBORecurringBilling {
    private $core;
    private $dashboard;
    private $settings;
    private $customers;
    private $recurring_invoices;
    private $teams;
    private $students;
    private $mentors;
    private $reports;
    private $communications;

    public function __construct() {
        // Initialize core class first
        $this->core = new QBO_Core();
        // Initialize other classes with core dependency
        $this->dashboard = new QBO_Dashboard($this->core);
        $this->settings = new QBO_Settings($this->core);
        $this->customers = new QBO_Customers($this->core);
        $this->recurring_invoices = new QBO_Recurring_Invoices($this->core);
        $this->teams = new QBO_Teams($this->core, $GLOBALS['wpdb']);
        $this->students = new QBO_Students($this->core, $GLOBALS['wpdb']);
        $this->mentors = new QBO_Mentors($this->core);
        $this->reports = new QBO_Reports($this->core);
        $this->communications = new QBO_Communications($this->core);
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        // Handle OAuth callback
        add_action('admin_init', array($this->core, 'handle_oauth_callback'));
        
        // Register email AJAX handlers
        add_action('wp_ajax_send_mentor_email', 'qbo_handle_mentor_email');
        add_action('wp_ajax_nopriv_send_mentor_email', 'qbo_handle_mentor_email');
        
        // Add media uploader restrictions for email attachments
        add_filter('ajax_query_attachments_args', array($this, 'restrict_media_library_for_emails'), 10, 1);
        // Create database tables on activation
        register_activation_hook(__FILE__, array($this->core, 'create_database_tables'));
        
        // Flush rewrite rules on activation to ensure mentor-dashboard URL works
        register_activation_hook(__FILE__, array($this, 'flush_rewrite_rules_on_activation'));
        register_deactivation_hook(__FILE__, array($this, 'flush_rewrite_rules_on_deactivation'));
        
        // Enqueue admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    public function add_admin_menu() {
        // GEARS Dashboard as main menu with dashboard page
        add_menu_page(
            'GEARS Dashboard',
            'GEARS Dashboard',
            'manage_options',
            'gears-dashboard',
            array($this->dashboard, 'dashboard_page'),
            'dashicons-dashboard',
            3 // Move just under WordPress Dashboard
        );
        
        // Communications submenu under GEARS Dashboard
        add_submenu_page(
            'gears-dashboard',
            'Communications',
            'Communications',
            'manage_options',
            'qbo-communications',
            array($this->communications, 'communications_page')
        );
        
        // Customers submenu under GEARS Dashboard
        add_submenu_page(
            'gears-dashboard',
            'Customers',
            'Customers',
            'manage_options',
            'qbo-customer-list',
            array($this->customers, 'customer_list_page')
        );
        
        // Recurring Invoices submenu under GEARS Dashboard
        add_submenu_page(
            'gears-dashboard',
            'Recurring Invoices',
            'Recurring Invoices',
            'manage_options',
            'qbo-recurring-invoices',
            array($this->recurring_invoices, 'recurring_invoices_page')
        );
        
        // Teams submenu under GEARS Dashboard
        add_submenu_page(
            'gears-dashboard',
            'Teams',
            'Teams',
            'manage_options',
            'qbo-teams',
            array($this->teams, 'render_page') // Use render_page for full UI
        );
        
        // Students submenu under GEARS Dashboard (right after Teams)
        add_submenu_page(
            'gears-dashboard',
            'Students',
            'Students',
            'manage_options',
            'qbo-students',
            array($this->students, 'render_page')
        );
        
        // Mentors submenu under GEARS Dashboard
        add_submenu_page(
            'gears-dashboard',
            'Mentors',
            'Mentors',
            'manage_options',
            'qbo-mentors',
            array($this->mentors, 'mentors_page')
        );
        
        // Reports submenu under GEARS Dashboard
        add_submenu_page(
            'gears-dashboard',
            'Reports',
            'Reports',
            'manage_options',
            'qbo-reports',
            array($this->reports, 'reports_page')
        );
        
        // Useful Links submenu under GEARS Dashboard
        add_submenu_page(
            'gears-dashboard',
            'Useful Links',
            'Useful Links',
            'manage_options',
            'qbo-useful-links',
            array($this, 'useful_links_page')
        );
        
        // Settings submenu under GEARS Dashboard
        add_submenu_page(
            'gears-dashboard',
            'QBO Settings',
            'Settings',
            'manage_options',
            'qbo-settings',
            array($this->settings, 'settings_page')
        );
    }

    /**
     * Useful Links page handler
     */
    public function useful_links_page() {
        // Handle form submissions
        if ($_POST) {
            $this->handle_useful_links_form();
        }
        
        // Get current links from options
        $fll_links = get_option('qbo_useful_links_fll', []);
        $ftc_links = get_option('qbo_useful_links_ftc', []);
        
        ?>
        <div class="wrap">
            <h1>Useful Links Management</h1>
            <p>Manage useful links that will be shown to all teams of each program type.</p>
            
            <div class="useful-links-admin-container">
                <!-- FLL Links Section -->
                <div class="program-section">
                    <h2><span class="program-badge fll">FLL</span> FIRST Lego League Links</h2>
                    <button type="button" class="button button-primary" onclick="showAddLinkModal('FLL')">Add FLL Link</button>
                    
                    <div class="links-list" id="fll-links">
                        <?php if (empty($fll_links)): ?>
                            <p class="no-links">No FLL links added yet.</p>
                        <?php else: ?>
                            <?php foreach ($fll_links as $index => $link): ?>
                                <div class="link-item">
                                    <div class="link-content">
                                        <h4><a href="<?php echo esc_url($link['url']); ?>" target="_blank"><?php echo esc_html($link['name']); ?></a></h4>
                                        <p><?php echo esc_html($link['description']); ?></p>
                                    </div>
                                    <div class="link-actions">
                                        <button type="button" class="button" onclick="editLink('FLL', <?php echo $index; ?>)">Edit</button>
                                        <button type="button" class="button button-link-delete" onclick="deleteLink('FLL', <?php echo $index; ?>)">Delete</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- FTC Links Section -->
                <div class="program-section">
                    <h2><span class="program-badge ftc">FTC</span> FIRST Tech Challenge Links</h2>
                    <button type="button" class="button button-primary" onclick="showAddLinkModal('FTC')">Add FTC Link</button>
                    
                    <div class="links-list" id="ftc-links">
                        <?php if (empty($ftc_links)): ?>
                            <p class="no-links">No FTC links added yet.</p>
                        <?php else: ?>
                            <?php foreach ($ftc_links as $index => $link): ?>
                                <div class="link-item">
                                    <div class="link-content">
                                        <h4><a href="<?php echo esc_url($link['url']); ?>" target="_blank"><?php echo esc_html($link['name']); ?></a></h4>
                                        <p><?php echo esc_html($link['description']); ?></p>
                                    </div>
                                    <div class="link-actions">
                                        <button type="button" class="button" onclick="editLink('FTC', <?php echo $index; ?>)">Edit</button>
                                        <button type="button" class="button button-link-delete" onclick="deleteLink('FTC', <?php echo $index; ?>)">Delete</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Add/Edit Link Modal -->
        <div id="linkModal" class="useful-links-modal" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="modalTitle">Add Link</h3>
                    <button type="button" class="modal-close" onclick="closeLinkModal()">&times;</button>
                </div>
                <form id="linkForm" method="post">
                    <input type="hidden" name="action" value="save_link">
                    <input type="hidden" id="linkProgram" name="program">
                    <input type="hidden" id="linkIndex" name="index">
                    
                    <div class="form-field">
                        <label for="linkName">Link Name</label>
                        <input type="text" id="linkName" name="name" required>
                    </div>
                    
                    <div class="form-field">
                        <label for="linkUrl">URL</label>
                        <input type="url" id="linkUrl" name="url" required>
                    </div>
                    
                    <div class="form-field">
                        <label for="linkDescription">Description</label>
                        <textarea id="linkDescription" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="button" onclick="closeLinkModal()">Cancel</button>
                        <button type="submit" class="button button-primary">Save Link</button>
                    </div>
                </form>
            </div>
        </div>
        
        <style>
        .useful-links-admin-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 20px;
        }
        
        .program-section {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
        }
        
        .program-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            color: white;
            font-weight: bold;
            font-size: 12px;
        }
        
        .program-badge.fll {
            background-color: #28a745;
        }
        
        .program-badge.ftc {
            background-color: #ffc107;
            color: #000;
        }
        
        .links-list {
            margin-top: 15px;
        }
        
        .link-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 15px;
            border: 1px solid #e1e1e1;
            border-radius: 4px;
            margin-bottom: 10px;
            background: #f9f9f9;
        }
        
        .link-content {
            flex: 1;
        }
        
        .link-content h4 {
            margin: 0 0 5px 0;
        }
        
        .link-content p {
            margin: 0;
            color: #666;
        }
        
        .link-actions {
            display: flex;
            gap: 10px;
        }
        
        .button-link-delete {
            color: #a00;
        }
        
        .useful-links-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 100000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            padding: 0;
            border-radius: 4px;
            width: 90%;
            max-width: 500px;
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid #ddd;
            text-align: right;
        }
        
        .form-field {
            padding: 0 20px 15px;
        }
        
        .form-field label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .form-field input,
        .form-field textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .no-links {
            color: #666;
            font-style: italic;
            padding: 20px;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .useful-links-admin-container {
                grid-template-columns: 1fr;
            }
        }
        </style>
        
        <script>
        function showAddLinkModal(program) {
            document.getElementById('modalTitle').textContent = 'Add ' + program + ' Link';
            document.getElementById('linkProgram').value = program;
            document.getElementById('linkIndex').value = '';
            document.getElementById('linkForm').reset();
            document.getElementById('linkProgram').value = program; // Reset after form reset
            document.getElementById('linkModal').style.display = 'flex';
        }
        
        function editLink(program, index) {
            var links = <?php echo json_encode(['FLL' => $fll_links, 'FTC' => $ftc_links]); ?>;
            var link = links[program][index];
            
            document.getElementById('modalTitle').textContent = 'Edit ' + program + ' Link';
            document.getElementById('linkProgram').value = program;
            document.getElementById('linkIndex').value = index;
            document.getElementById('linkName').value = link.name;
            document.getElementById('linkUrl').value = link.url;
            document.getElementById('linkDescription').value = link.description;
            document.getElementById('linkModal').style.display = 'flex';
        }
        
        function deleteLink(program, index) {
            if (confirm('Are you sure you want to delete this link?')) {
                var form = document.createElement('form');
                form.method = 'post';
                form.innerHTML = '<input type="hidden" name="action" value="delete_link">' +
                               '<input type="hidden" name="program" value="' + program + '">' +
                               '<input type="hidden" name="index" value="' + index + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function closeLinkModal() {
            document.getElementById('linkModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        document.getElementById('linkModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeLinkModal();
            }
        });
        </script>
        <?php
    }
    
    /**
     * Handle useful links form submissions
     */
    private function handle_useful_links_form() {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        
        $action = sanitize_text_field($_POST['action']);
        $program = sanitize_text_field($_POST['program']);
        
        if (!in_array($program, ['FLL', 'FTC'])) {
            wp_die('Invalid program');
        }
        
        $option_name = 'qbo_useful_links_' . strtolower($program);
        $links = get_option($option_name, []);
        
        if ($action === 'save_link') {
            $name = sanitize_text_field($_POST['name']);
            $url = esc_url_raw($_POST['url']);
            $description = sanitize_textarea_field($_POST['description']);
            $index = isset($_POST['index']) && $_POST['index'] !== '' ? intval($_POST['index']) : null;
            
            if (empty($name) || empty($url)) {
                add_settings_error('useful_links', 'missing_fields', 'Name and URL are required.');
                return;
            }
            
            $link_data = [
                'name' => $name,
                'url' => $url,
                'description' => $description
            ];
            
            if ($index !== null && isset($links[$index])) {
                $links[$index] = $link_data;
                $message = 'Link updated successfully.';
            } else {
                $links[] = $link_data;
                $message = 'Link added successfully.';
            }
            
            update_option($option_name, $links);
            add_settings_error('useful_links', 'link_saved', $message, 'updated');
            
        } elseif ($action === 'delete_link') {
            $index = intval($_POST['index']);
            
            if (isset($links[$index])) {
                array_splice($links, $index, 1);
                update_option($option_name, $links);
                add_settings_error('useful_links', 'link_deleted', 'Link deleted successfully.', 'updated');
            }
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only enqueue on QBO plugin admin pages - use the same page list as core
        $qbo_pages = array(
            'toplevel_page_gears-dashboard', 
            'gears-dashboard_page_qbo-communications',
            'gears-dashboard_page_qbo-customer-list', 
            'gears-dashboard_page_qbo-teams', 
            'gears-dashboard_page_qbo-students',
            'gears-dashboard_page_qbo-mentors', 
            'gears-dashboard_page_qbo-settings',
            'gears-dashboard_page_qbo-recurring-invoices',
            'gears-dashboard_page_qbo-reports',
            'gears-dashboard_page_qbo-useful-links',
            'admin_page_qbo-view-invoices' // for the hidden invoices page
        );
        
        if (in_array($hook, $qbo_pages)) {
            // Enqueue CSS
            wp_enqueue_style('qbo-admin-css', QBO_PLUGIN_URL . 'assets/css/admin.css', array(), '1.0.0');
            wp_enqueue_style('qbo-modals-css', QBO_PLUGIN_URL . 'assets/css/modals.css', array(), '1.0.0');
            
            // Enqueue JS
            wp_enqueue_script('qbo-admin-js', QBO_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), '1.0.0', true);
            
            // Localize script for AJAX
            wp_localize_script('qbo-admin-js', 'qbo_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('qbo_ajax_nonce')
            ));
        }
    }

    /**
     * Get the recurring invoices instance
     */
    public function get_recurring_invoices_instance() {
        return $this->recurring_invoices;
    }
    
    /**
     * Flush rewrite rules on plugin activation
     */
    public function flush_rewrite_rules_on_activation() {
        // Make sure our rewrite rules are added
        add_rewrite_rule('^mentor-dashboard/?$', 'index.php?mentor_dashboard=1', 'top');
        flush_rewrite_rules();
    }
    
    /**
     * Flush rewrite rules on plugin deactivation
     */
    public function flush_rewrite_rules_on_deactivation() {
        flush_rewrite_rules();
    }
    
    /**
     * Restrict media library to email attachments folder
     */
    public function restrict_media_library_for_emails($query) {
        // Only apply restriction when we're in the email context
        if (isset($_REQUEST['context']) && $_REQUEST['context'] === 'email_attachments') {
            // Create the email attachments folder if it doesn't exist
            $upload_dir = wp_upload_dir();
            $email_attachments_dir = $upload_dir['basedir'] . '/email-attachments';
            
            if (!file_exists($email_attachments_dir)) {
                wp_mkdir_p($email_attachments_dir);
            }
            
            // Restrict to files in the email-attachments folder
            $query['meta_query'] = array(
                array(
                    'key' => '_wp_attached_file',
                    'value' => 'email-attachments/',
                    'compare' => 'LIKE'
                )
            );
        }
        
        return $query;
    }
}

/**
 * Handle mentor email sending (standalone function)
 */
function qbo_handle_mentor_email() {
    // Add debugging
    error_log('QBO Email Debug: Function called');
    error_log('QBO Email Debug: POST data - ' . print_r($_POST, true));
    
    // Verify nonce
    if (!isset($_POST['email_nonce']) || !wp_verify_nonce($_POST['email_nonce'], 'qbo_send_email')) {
        error_log('QBO Email Debug: Nonce verification failed');
        wp_send_json(['success' => false, 'message' => 'Security check failed.']);
    }
    
    error_log('QBO Email Debug: Nonce verified successfully');
    
    // Get form data
    $team_id = intval($_POST['team_id'] ?? 0);
    $subject = sanitize_text_field($_POST['subject'] ?? '');
    $message = wp_kses_post($_POST['message'] ?? '');
    $mentor_emails = $_POST['mentor_emails'] ?? [];
    $send_copy = isset($_POST['send_copy']) && $_POST['send_copy'] === '1';
    
    // Validate required fields
    if (empty($subject)) {
        wp_send_json(['success' => false, 'message' => 'Subject is required.']);
    }
    
    if (empty($message)) {
        wp_send_json(['success' => false, 'message' => 'Message is required.']);
    }
    
    if (empty($mentor_emails) || !is_array($mentor_emails)) {
        wp_send_json(['success' => false, 'message' => 'Please select at least one recipient.']);
    }
    
    // Sanitize email addresses
    $sanitized_emails = [];
    foreach ($mentor_emails as $email) {
        $clean_email = sanitize_email($email);
        if (is_email($clean_email)) {
            $sanitized_emails[] = $clean_email;
        }
    }
    
    if (empty($sanitized_emails)) {
        wp_send_json(['success' => false, 'message' => 'No valid email addresses found.']);
    }
    
    // Get team information for context
    global $wpdb;
    $teams_table = $wpdb->prefix . 'gears_teams';
    error_log('QBO Email Debug: Looking for team ID: ' . $team_id);
    error_log('QBO Email Debug: Teams table: ' . $teams_table);
    
    $team = $wpdb->get_row($wpdb->prepare("SELECT * FROM $teams_table WHERE id = %d", $team_id));
    error_log('QBO Email Debug: Team query result: ' . print_r($team, true));
    error_log('QBO Email Debug: WordPress DB error: ' . $wpdb->last_error);
    
    if (!$team) {
        wp_send_json(['success' => false, 'message' => 'Team not found.']);
    }
    
    // Prepare email headers
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_option('admin_email')
    ];
    
    // Add sender's email to copy list if requested
    if ($send_copy) {
        $current_user = wp_get_current_user();
        if ($current_user && is_email($current_user->user_email)) {
            $sanitized_emails[] = $current_user->user_email;
        }
    }
    
    // Prepare email body with team context
    $email_body = '<html><body>';
    $email_body .= '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
    $email_body .= '<div style="background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px;">';
    $email_body .= '<h3 style="margin: 0; color: #333;">Message from ' . esc_html($team->team_name) . ' (' . esc_html($team->program) . ')</h3>';
    $email_body .= '</div>';
    $email_body .= '<div style="padding: 20px; background-color: #ffffff; border: 1px solid #e9ecef; border-radius: 5px;">';
    $email_body .= $message;
    $email_body .= '</div>';
    $email_body .= '<div style="margin-top: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 5px; font-size: 12px; color: #6c757d;">';
    $email_body .= '<p>This email was sent from the GEARS mentor dashboard for team: <strong>' . esc_html($team->team_name) . '</strong></p>';
    $email_body .= '</div>';
    $email_body .= '</div>';
    $email_body .= '</body></html>';
    
    // Send emails
    $sent_count = 0;
    $failed_emails = [];
    
    error_log('QBO Email Debug: About to send ' . count($sanitized_emails) . ' emails');
    error_log('QBO Email Debug: Recipients - ' . implode(', ', $sanitized_emails));
    error_log('QBO Email Debug: Subject - ' . $subject);
    
    foreach ($sanitized_emails as $email) {
        error_log('QBO Email Debug: Attempting to send to ' . $email);
        $sent = wp_mail($email, $subject, $email_body, $headers);
        error_log('QBO Email Debug: wp_mail result for ' . $email . ' - ' . ($sent ? 'SUCCESS' : 'FAILED'));
        
        if ($sent) {
            $sent_count++;
        } else {
            $failed_emails[] = $email;
        }
    }
    
    error_log('QBO Email Debug: Final results - Sent: ' . $sent_count . ', Failed: ' . count($failed_emails));
    
    if ($sent_count === 0) {
        $response = ['success' => false, 'message' => 'Failed to send any emails. Please check your email configuration.'];
    } elseif (!empty($failed_emails)) {
        $message = "Sent {$sent_count} email(s) successfully, but failed to send to: " . implode(', ', $failed_emails);
        $response = ['success' => true, 'message' => $message];
    } else {
        $response = ['success' => true, 'message' => "Successfully sent email to {$sent_count} recipient(s)."];
    }
    
    // For WordPress AJAX, we need to output JSON and die
    if (function_exists('wp_send_json')) {
        wp_send_json($response);
    } else {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    global $qbo_recurring_billing;
    $qbo_recurring_billing = new QBORecurringBilling();
});
