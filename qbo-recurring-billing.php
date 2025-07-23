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
    add_rewrite_rule('^mentor-dashboard/?$', 'wp-content/plugins/qbo-recurring-billing/qbo-register.php', 'top');
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
        // Create database tables on activation
        register_activation_hook(__FILE__, array($this->core, 'create_database_tables'));
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
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    global $qbo_recurring_billing;
    $qbo_recurring_billing = new QBORecurringBilling();
});
