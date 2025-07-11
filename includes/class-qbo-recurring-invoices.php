<?php
/**
 * QBO Recurring Invoices Class
 * 
 * Handles recurring invoice functionality and display
 */

if (!defined('ABSPATH')) {
    exit;
}

class QBO_Recurring_Invoices {
    
    private $core;
    
    public function __construct($core) {
        $this->core = $core;
        
        // AJAX handlers for recurring invoices
        add_action('wp_ajax_qbo_get_recurring_invoices', array($this, 'ajax_get_recurring_invoices'));
        add_action('wp_ajax_qbo_clear_recurring_invoices_cache', array($this, 'ajax_clear_recurring_invoices_cache'));
        add_action('wp_ajax_qbo_get_recurring_invoice_details', array($this, 'ajax_get_recurring_invoice_details'));
        add_action('wp_ajax_qbo_update_recurring_invoice', array($this, 'ajax_update_recurring_invoice'));
        add_action('wp_ajax_qbo_toggle_recurring_invoice_status', array($this, 'ajax_toggle_recurring_invoice_status'));
    }
    
    /**
     * Render the recurring invoices page
     */
    public function recurring_invoices_page() {
        echo '<div class="wrap">';
        echo '<h1>Recurring Invoices</h1>';
        
        // Check if QBO is connected
        $options = get_option($this->core->get_option_name());
        if (!isset($options['access_token']) || empty($options['access_token'])) {
            echo '<div class="notice notice-warning"><p>QuickBooks Online is not connected. Please configure the connection in <a href="' . admin_url('admin.php?page=qbo-settings') . '">Settings</a>.</p></div>';
            echo '</div>';
            return;
        }
        
        echo '<div class="qbo-recurring-invoices-container">';
        echo '<div class="qbo-toolbar">';
        echo '<button type="button" class="button button-secondary" id="refresh-recurring-invoices" title="Refresh List"><span class="dashicons dashicons-update"></span></button>';
        echo '<span class="toolbar-separator"></span>';
        echo '<button type="button" class="button button-secondary" id="hide-inactive-invoices" title="Hide Inactive"><span class="dashicons dashicons-hidden"></span></button>';
        echo '<button type="button" class="button button-secondary" id="show-inactive-invoices" style="display:none;" title="Show Inactive"><span class="dashicons dashicons-visibility"></span></button>';
        echo '</div>';
        
        echo '<div id="recurring-invoices-content">';
        echo '<p>Loading recurring invoices...</p>';
        echo '</div>';
        
        echo '</div>';
        
        // Add JavaScript for loading and refreshing
        $this->add_recurring_invoices_js();
        
        echo '</div>';
    }
    
    /**
     * Fetch recurring invoices from QuickBooks Online
     */
    public function fetch_recurring_invoices() {
        $cache_key = 'qbo_recurring_invoices_cache';
        $cache = get_option($cache_key, array());
        $now = time();
        
        // Check cache (30 minutes)
        if (isset($cache['timestamp']) && ($now - $cache['timestamp'] < 1800) && isset($cache['data'])) {
            return $cache['data'];
        }
        
        $options = get_option($this->core->get_option_name());
        if (!isset($options['access_token']) || !isset($options['realm_id'])) {
            return array();
        }
        
        $access_token = $options['access_token'];
        $company_id = $options['realm_id'];
        $base_url = 'https://quickbooks.api.intuit.com';
        
        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/text',
        );
        
        // Query for recurring transactions (all types, then filter)
        $query = "SELECT * FROM RecurringTransaction";
        $api_url = "$base_url/v3/company/$company_id/query?query=" . urlencode($query) . "&minorversion=65";
        
        $response = wp_remote_get($api_url, array('headers' => $headers, 'timeout' => 30));
        
        if (is_wp_error($response)) {
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $recurring_invoices = array();
        if (isset($data['QueryResponse']['RecurringTransaction'])) {
            // Filter for invoice-related recurring transactions
            foreach ($data['QueryResponse']['RecurringTransaction'] as $transaction) {
                // Check if this has Invoice data and RecurringInfo
                if (isset($transaction['Invoice']) && isset($transaction['Invoice']['RecurringInfo'])) {
                    $recurring_invoices[] = $transaction;
                }
            }
        }
        
        // Sort by name
        usort($recurring_invoices, function($a, $b) {
            $name_a = isset($a['Invoice']['RecurringInfo']['Name']) ? $a['Invoice']['RecurringInfo']['Name'] : '';
            $name_b = isset($b['Invoice']['RecurringInfo']['Name']) ? $b['Invoice']['RecurringInfo']['Name'] : '';
            return strcasecmp($name_a, $name_b);
        });
        
        // Save to cache
        update_option($cache_key, array('timestamp' => $now, 'data' => $recurring_invoices));
        
        return $recurring_invoices;
    }
    
    /**
     * Display recurring invoices list
     */
    public function display_recurring_invoices_list() {
        $recurring_invoices = $this->fetch_recurring_invoices();
        
        if (empty($recurring_invoices)) {
            echo '<div class="notice notice-info"><p>No recurring invoices found.</p></div>';
            return;
        }
        
        echo '<div class="qbo-recurring-invoices-list">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th scope="col">Customer</th>';
        echo '<th scope="col">Amount</th>';
        echo '<th scope="col">Frequency</th>';
        echo '<th scope="col">Next Date</th>';
        echo '<th scope="col">Previous Date</th>';
        echo '<th scope="col">Student</th>';
        echo '<th scope="col">Status</th>';
        echo '<th scope="col">Actions</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($recurring_invoices as $invoice) {
            $this->display_recurring_invoice_row($invoice);
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
    
    /**
     * Display a single recurring invoice row
     */
    private function display_recurring_invoice_row($invoice) {
        // Extract the actual invoice data
        $invoice_data = isset($invoice['Invoice']) ? $invoice['Invoice'] : array();
        $recurring_info = isset($invoice_data['RecurringInfo']) ? $invoice_data['RecurringInfo'] : array();
        
        // Get customer name from CustomerRef
        $customer_name = 'N/A';
        if (isset($invoice_data['CustomerRef']['name'])) {
            $customer_name = $invoice_data['CustomerRef']['name'];
        } elseif (isset($invoice_data['CustomerRef']['value'])) {
            $customer_id = $invoice_data['CustomerRef']['value'];
            $customer_name = $this->get_customer_name($customer_id);
        }
        
        // Get amount from TotalAmt
        $amount = 'N/A';
        if (isset($invoice_data['TotalAmt'])) {
            $amount = '$' . number_format(floatval($invoice_data['TotalAmt']), 2);
        }
        
        // Get frequency from ScheduleInfo
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
        
        // Get next date from ScheduleInfo
        $next_date = 'N/A';
        if (isset($recurring_info['ScheduleInfo']['NextDate'])) {
            $next_date = date('M j, Y', strtotime($recurring_info['ScheduleInfo']['NextDate']));
        }
        
        // Get previous date from ScheduleInfo
        $previous_date = 'N/A';
        if (isset($recurring_info['ScheduleInfo']['PreviousDate'])) {
            $previous_date = date('M j, Y', strtotime($recurring_info['ScheduleInfo']['PreviousDate']));
        }
        
        // Get status from RecurringInfo - also mark as inactive if no next date
        $has_next_date = isset($recurring_info['ScheduleInfo']['NextDate']) && !empty($recurring_info['ScheduleInfo']['NextDate']);
        $is_qbo_active = isset($recurring_info['Active']) && $recurring_info['Active'];
        
        // Mark as inactive if either QBO says inactive OR there's no next date
        $status = ($is_qbo_active && $has_next_date) ? 'Active' : 'Inactive';
        $status_class = strtolower($status);
        
        // Get team information for this customer
        $team_info = $this->get_customer_team_info($invoice_data);
        
        echo '<tr class="invoice-row status-row-' . $status_class . '">';
        echo '<td>' . esc_html($customer_name) . '</td>';
        echo '<td>' . $amount . '</td>';
        echo '<td>' . esc_html($frequency) . '</td>';
        echo '<td>' . $next_date . '</td>';
        echo '<td>' . $previous_date . '</td>';
        echo '<td class="student-column">';
        if (!empty($team_info)) {
            $team_links = array();
            foreach ($team_info as $team) {
                $team_url = admin_url('admin.php?page=qbo-teams&action=view&team_id=' . $team->id);
                $team_links[] = '<a href="' . esc_url($team_url) . '" title="View Team ' . esc_attr($team->team_number) . ' - ' . esc_attr($team->team_name) . '">' . esc_html($team->team_number) . '</a>';
            }
            echo implode(', ', $team_links);
        } else {
            echo '<span class="dashicons dashicons-minus" style="color: #ddd;" title="No team association"></span>';
        }
        echo '</td>';
        echo '<td><span class="status-' . $status_class . '">' . $status . '</span></td>';
        echo '<td>';
        echo '<button type="button" class="button button-small view-details" data-id="' . esc_attr($invoice_data['Id']) . '" title="View Details"><span class="dashicons dashicons-visibility"></span></button> ';
        echo '<button type="button" class="button button-small button-primary edit-invoice" data-id="' . esc_attr($invoice_data['Id']) . '" title="Edit"><span class="dashicons dashicons-edit"></span></button> ';
        echo '<button type="button" class="button button-small toggle-status" data-id="' . esc_attr($invoice_data['Id']) . '" data-status="' . esc_attr($status) . '" title="' . (($status === 'Active') ? 'Deactivate' : 'Activate') . '">';
        echo '<span class="dashicons dashicons-' . (($status === 'Active') ? 'controls-pause' : 'controls-play') . '"></span>';
        echo '</button>';
        echo '</td>';
        echo '</tr>';
    }
    
    /**
     * Get customer name by ID
     */
    private function get_customer_name($customer_id) {
        // Try to get from customers cache first
        $customers = $this->core->fetch_customers();
        foreach ($customers as $customer) {
            if (isset($customer['Id']) && $customer['Id'] == $customer_id) {
                return isset($customer['Name']) ? $customer['Name'] : 'Unknown Customer';
            }
        }
        return 'Customer ID: ' . $customer_id;
    }
    
    /**
     * Get team information for a customer's students
     */
    private function get_customer_team_info($invoice_data) {
        global $wpdb;
        
        // Get customer ID from invoice data
        if (!isset($invoice_data['CustomerRef']['value'])) {
            return array();
        }
        
        $customer_qbo_id = $invoice_data['CustomerRef']['value'];
        
        // Query to get team information for this customer's students
        $table_students = $wpdb->prefix . 'gears_students';
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT t.id, t.team_number, t.team_name 
             FROM $table_students s 
             JOIN $table_teams t ON s.team_id = t.id 
             WHERE s.customer_id = %s AND s.team_id IS NOT NULL
             ORDER BY t.team_number",
            $customer_qbo_id
        ));
        
        return $results ? $results : array();
    }

    /**
     * AJAX handler for getting recurring invoices
     */
    public function ajax_get_recurring_invoices() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        ob_start();
        $this->display_recurring_invoices_list();
        $html = ob_get_clean();
        
        wp_send_json_success($html);
    }
    
    /**
     * AJAX handler for clearing recurring invoices cache
     */
    public function ajax_clear_recurring_invoices_cache() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        delete_option('qbo_recurring_invoices_cache');
        wp_send_json_success('Cache cleared');
    }
    
    /**
     * AJAX handler for getting detailed recurring invoice information
     */
    public function ajax_get_recurring_invoice_details() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        $invoice_id = sanitize_text_field($_POST['invoice_id']);
        if (empty($invoice_id)) {
            wp_send_json_error('Invoice ID is required');
        }
        
        // Get all recurring invoices from cache or API
        $recurring_invoices = $this->fetch_recurring_invoices();
        
        // Find the specific invoice
        $found_invoice = null;
        foreach ($recurring_invoices as $invoice) {
            if (isset($invoice['Invoice']['Id']) && $invoice['Invoice']['Id'] == $invoice_id) {
                $found_invoice = $invoice;
                break;
            }
        }
        
        if (!$found_invoice) {
            wp_send_json_error('Invoice not found');
        }
        
        // Generate detailed HTML
        ob_start();
        $this->display_invoice_details($found_invoice);
        $html = ob_get_clean();
        
        wp_send_json_success($html);
    }
    
    /**
     * AJAX handler for updating recurring invoice
     */
    public function ajax_update_recurring_invoice() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        $invoice_id = sanitize_text_field($_POST['invoice_id']);
        if (empty($invoice_id)) {
            wp_send_json_error('Invoice ID is required');
        }
        
        // Get the form data
        $name = sanitize_text_field($_POST['name'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $frequency = sanitize_text_field($_POST['frequency'] ?? '');
        $num_interval = intval($_POST['num_interval'] ?? 1);
        $next_date = sanitize_text_field($_POST['next_date'] ?? '');
        $customer_memo = sanitize_textarea_field($_POST['customer_memo'] ?? '');
        
        // Validate required fields
        if (empty($name) || $amount <= 0) {
            wp_send_json_error('Name and amount are required');
        }
        
        try {
            $result = $this->update_recurring_invoice_in_qbo($invoice_id, array(
                'name' => $name,
                'amount' => $amount,
                'frequency' => $frequency,
                'num_interval' => $num_interval,
                'next_date' => $next_date,
                'customer_memo' => $customer_memo
            ));
            
            if ($result) {
                // Clear cache to force refresh
                delete_option('qbo_recurring_invoices_cache');
                wp_send_json_success('Invoice updated successfully');
            } else {
                wp_send_json_error('Failed to update invoice in QuickBooks');
            }
        } catch (Exception $e) {
            wp_send_json_error('Error updating invoice: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for toggling recurring invoice status
     */
    public function ajax_toggle_recurring_invoice_status() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        $invoice_id = sanitize_text_field($_POST['invoice_id']);
        $current_status = sanitize_text_field($_POST['current_status']);
        
        if (empty($invoice_id) || empty($current_status)) {
            wp_send_json_error('Invoice ID and current status are required');
        }
        
        // Determine new status
        $new_status = ($current_status === 'Active') ? false : true;
        
        try {
            $result = $this->update_recurring_invoice_status_in_qbo($invoice_id, $new_status);
            
            if ($result) {
                // Clear cache to force refresh
                delete_option('qbo_recurring_invoices_cache');
                $status_text = $new_status ? 'activated' : 'deactivated';
                wp_send_json_success("Invoice $status_text successfully");
            } else {
                wp_send_json_error('Failed to update invoice status in QuickBooks');
            }
        } catch (Exception $e) {
            wp_send_json_error('Error updating invoice status: ' . $e->getMessage());
        }
    }
    
    /**
     * Update recurring invoice in QuickBooks Online
     */
    private function update_recurring_invoice_in_qbo($invoice_id, $data) {
        $options = get_option($this->core->get_option_name());
        if (!isset($options['access_token']) || !isset($options['realm_id'])) {
            throw new Exception('QuickBooks connection not available');
        }
        
        $access_token = $options['access_token'];
        $company_id = $options['realm_id'];
        $base_url = 'https://quickbooks.api.intuit.com';
        
        // First, get the current invoice to get the sync token
        $current_invoice = $this->get_recurring_invoice_from_qbo($invoice_id);
        if (!$current_invoice) {
            throw new Exception('Could not retrieve current invoice');
        }
        
        $invoice_data = $current_invoice['Invoice'];
        
        // Update the invoice data with new values
        $invoice_data['RecurringInfo']['Name'] = $data['name'];
        $invoice_data['TotalAmt'] = $data['amount'];
        
        // Update frequency if provided
        if (!empty($data['frequency'])) {
            $invoice_data['RecurringInfo']['ScheduleInfo']['IntervalType'] = $data['frequency'];
            $invoice_data['RecurringInfo']['ScheduleInfo']['NumInterval'] = $data['num_interval'];
        }
        
        // Update next date if provided
        if (!empty($data['next_date'])) {
            $invoice_data['RecurringInfo']['ScheduleInfo']['NextDate'] = $data['next_date'];
        }
        
        // Update customer memo if provided
        if (!empty($data['customer_memo'])) {
            $invoice_data['CustomerMemo'] = array('value' => $data['customer_memo']);
        }
        
        // Prepare the update request
        $update_data = array(
            'RecurringTransaction' => array(
                'Invoice' => $invoice_data
            )
        );
        
        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        );
        
        $api_url = "$base_url/v3/company/$company_id/recurringtransaction?minorversion=65";
        
        $response = wp_remote_post($api_url, array(
            'headers' => $headers,
            'body' => json_encode($update_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        // Check for errors
        if (isset($result['Fault'])) {
            $error_msg = isset($result['Fault']['Error'][0]['Detail']) 
                ? $result['Fault']['Error'][0]['Detail'] 
                : 'Unknown QuickBooks error';
            throw new Exception($error_msg);
        }
        
        return isset($result['QueryResponse']['RecurringTransaction']);
    }
    
    /**
     * Update recurring invoice status in QuickBooks Online
     */
    private function update_recurring_invoice_status_in_qbo($invoice_id, $active_status) {
        $options = get_option($this->core->get_option_name());
        if (!isset($options['access_token']) || !isset($options['realm_id'])) {
            throw new Exception('QuickBooks connection not available');
        }
        
        $access_token = $options['access_token'];
        $company_id = $options['realm_id'];
        $base_url = 'https://quickbooks.api.intuit.com';
        
        // First, get the current invoice to get the sync token
        $current_invoice = $this->get_recurring_invoice_from_qbo($invoice_id);
        if (!$current_invoice) {
            throw new Exception('Could not retrieve current invoice');
        }
        
        $invoice_data = $current_invoice['Invoice'];
        
        // Update the active status
        $invoice_data['RecurringInfo']['Active'] = $active_status;
        
        // Prepare the update request
        $update_data = array(
            'RecurringTransaction' => array(
                'Invoice' => $invoice_data
            )
        );
        
        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        );
        
        $api_url = "$base_url/v3/company/$company_id/recurringtransaction?minorversion=65";
        
        $response = wp_remote_post($api_url, array(
            'headers' => $headers,
            'body' => json_encode($update_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        // Check for errors
        if (isset($result['Fault'])) {
            $error_msg = isset($result['Fault']['Error'][0]['Detail']) 
                ? $result['Fault']['Error'][0]['Detail'] 
                : 'Unknown QuickBooks error';
            throw new Exception($error_msg);
        }
        
        return isset($result['QueryResponse']['RecurringTransaction']);
    }
    
    /**
     * Get a specific recurring invoice from QuickBooks Online
     */
    private function get_recurring_invoice_from_qbo($invoice_id) {
        $options = get_option($this->core->get_option_name());
        if (!isset($options['access_token']) || !isset($options['realm_id'])) {
            return false;
        }
        
        $access_token = $options['access_token'];
        $company_id = $options['realm_id'];
        $base_url = 'https://quickbooks.api.intuit.com';
        
        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/text',
        );
        
        // Query for the specific recurring transaction
        $query = "SELECT * FROM RecurringTransaction WHERE Invoice.Id = '$invoice_id'";
        $api_url = "$base_url/v3/company/$company_id/query?query=" . urlencode($query) . "&minorversion=65";
        
        $response = wp_remote_get($api_url, array('headers' => $headers, 'timeout' => 30));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['QueryResponse']['RecurringTransaction']) && !empty($data['QueryResponse']['RecurringTransaction'])) {
            return $data['QueryResponse']['RecurringTransaction'][0];
        }
        
        return false;
    }

    /**
     * Display detailed information for a recurring invoice
     */
    private function display_invoice_details($invoice) {
        $invoice_data = isset($invoice['Invoice']) ? $invoice['Invoice'] : array();
        $recurring_info = isset($invoice_data['RecurringInfo']) ? $invoice_data['RecurringInfo'] : array();
        $schedule_info = isset($recurring_info['ScheduleInfo']) ? $recurring_info['ScheduleInfo'] : array();
        
        echo '<div class="qbo-invoice-details">';
        
        // Header
        echo '<div class="invoice-header">';
        echo '<h3>' . esc_html($recurring_info['Name'] ?? 'Recurring Invoice') . '</h3>';
        echo '<p class="invoice-id">Invoice ID: ' . esc_html($invoice_data['Id'] ?? 'N/A') . '</p>';
        echo '</div>';
        
        // Basic Information
        echo '<div class="details-section">';
        echo '<h4>Basic Information</h4>';
        echo '<table class="details-table">';
        echo '<tr><td><strong>Customer:</strong></td><td>' . esc_html($invoice_data['CustomerRef']['name'] ?? 'N/A') . '</td></tr>';
        echo '<tr><td><strong>Total Amount:</strong></td><td>$' . number_format(floatval($invoice_data['TotalAmt'] ?? 0), 2) . '</td></tr>';
        echo '<tr><td><strong>Currency:</strong></td><td>' . esc_html($invoice_data['CurrencyRef']['name'] ?? 'N/A') . '</td></tr>';
        echo '<tr><td><strong>Sales Terms:</strong></td><td>' . esc_html($invoice_data['SalesTermRef']['name'] ?? 'N/A') . '</td></tr>';
        echo '<tr><td><strong>Balance:</strong></td><td>$' . number_format(floatval($invoice_data['Balance'] ?? 0), 2) . '</td></tr>';
        echo '</table>';
        echo '</div>';
        
        // Recurring Information
        echo '<div class="details-section">';
        echo '<h4>Recurring Settings</h4>';
        echo '<table class="details-table">';
        echo '<tr><td><strong>Status:</strong></td><td><span class="status-' . strtolower($recurring_info['Active'] ? 'active' : 'inactive') . '">' . ($recurring_info['Active'] ? 'Active' : 'Inactive') . '</span></td></tr>';
        echo '<tr><td><strong>Recurrence Type:</strong></td><td>' . esc_html($recurring_info['RecurType'] ?? 'N/A') . '</td></tr>';
        echo '<tr><td><strong>Interval:</strong></td><td>' . esc_html($schedule_info['IntervalType'] ?? 'N/A') . '</td></tr>';
        echo '<tr><td><strong>Frequency:</strong></td><td>Every ' . esc_html($schedule_info['NumInterval'] ?? 'N/A') . ' ' . strtolower($schedule_info['IntervalType'] ?? '') . '</td></tr>';
        if (isset($schedule_info['DayOfMonth'])) {
            echo '<tr><td><strong>Day of Month:</strong></td><td>' . esc_html($schedule_info['DayOfMonth']) . '</td></tr>';
        }
        echo '<tr><td><strong>Start Date:</strong></td><td>' . (isset($schedule_info['StartDate']) ? date('M j, Y', strtotime($schedule_info['StartDate'])) : 'N/A') . '</td></tr>';
        echo '<tr><td><strong>Next Date:</strong></td><td>' . (isset($schedule_info['NextDate']) ? date('M j, Y', strtotime($schedule_info['NextDate'])) : 'N/A') . '</td></tr>';
        echo '<tr><td><strong>Previous Date:</strong></td><td>' . (isset($schedule_info['PreviousDate']) ? date('M j, Y', strtotime($schedule_info['PreviousDate'])) : 'N/A') . '</td></tr>';
        echo '</table>';
        echo '</div>';
        
        // Line Items
        if (isset($invoice_data['Line']) && is_array($invoice_data['Line'])) {
            echo '<div class="details-section">';
            echo '<h4>Line Items</h4>';
            echo '<table class="details-table line-items-table">';
            echo '<thead><tr><th>Description</th><th>Qty</th><th>Rate</th><th>Amount</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($invoice_data['Line'] as $line) {
                if ($line['DetailType'] === 'SalesItemLineDetail') {
                    $description = $line['Description'] ?? '';
                    $qty = $line['SalesItemLineDetail']['Qty'] ?? 1;
                    $rate = $line['SalesItemLineDetail']['UnitPrice'] ?? 0;
                    $amount = $line['Amount'] ?? 0;
                    
                    echo '<tr>';
                    echo '<td>' . esc_html($description) . '</td>';
                    echo '<td>' . esc_html($qty) . '</td>';
                    echo '<td>$' . number_format(floatval($rate), 2) . '</td>';
                    echo '<td>$' . number_format(floatval($amount), 2) . '</td>';
                    echo '</tr>';
                }
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }
        
        // Billing Address
        if (isset($invoice_data['BillAddr'])) {
            echo '<div class="details-section">';
            echo '<h4>Billing Address</h4>';
            echo '<div class="address-block">';
            if (isset($invoice_data['BillAddr']['Line1'])) echo '<div>' . esc_html($invoice_data['BillAddr']['Line1']) . '</div>';
            if (isset($invoice_data['BillAddr']['Line2'])) echo '<div>' . esc_html($invoice_data['BillAddr']['Line2']) . '</div>';
            if (isset($invoice_data['BillAddr']['Line3'])) echo '<div>' . esc_html($invoice_data['BillAddr']['Line3']) . '</div>';
            if (isset($invoice_data['BillAddr']['Line4'])) echo '<div>' . esc_html($invoice_data['BillAddr']['Line4']) . '</div>';
            echo '</div>';
            echo '</div>';
        }
        
        // Customer Message
        if (isset($invoice_data['CustomerMemo']['value'])) {
            echo '<div class="details-section">';
            echo '<h4>Customer Message</h4>';
            echo '<div class="customer-memo">' . esc_html($invoice_data['CustomerMemo']['value']) . '</div>';
            echo '</div>';
        }
        
        // Metadata
        if (isset($invoice_data['MetaData'])) {
            echo '<div class="details-section">';
            echo '<h4>Metadata</h4>';
            echo '<table class="details-table">';
            echo '<tr><td><strong>Created:</strong></td><td>' . (isset($invoice_data['MetaData']['CreateTime']) ? date('M j, Y g:i A', strtotime($invoice_data['MetaData']['CreateTime'])) : 'N/A') . '</td></tr>';
            echo '<tr><td><strong>Last Updated:</strong></td><td>' . (isset($invoice_data['MetaData']['LastUpdatedTime']) ? date('M j, Y g:i A', strtotime($invoice_data['MetaData']['LastUpdatedTime'])) : 'N/A') . '</td></tr>';
            echo '<tr><td><strong>Sync Token:</strong></td><td>' . esc_html($invoice_data['SyncToken'] ?? 'N/A') . '</td></tr>';
            echo '</table>';
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Add JavaScript for recurring invoices functionality
     */
    private function add_recurring_invoices_js() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Cookie helper functions
            function setCookie(name, value, days) {
                var expires = "";
                if (days) {
                    var date = new Date();
                    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                    expires = "; expires=" + date.toUTCString();
                }
                document.cookie = name + "=" + (value || "") + expires + "; path=/";
            }
            
            function getCookie(name) {
                var nameEQ = name + "=";
                var ca = document.cookie.split(';');
                for(var i = 0; i < ca.length; i++) {
                    var c = ca[i];
                    while (c.charAt(0) == ' ') c = c.substring(1, c.length);
                    if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
                }
                return null;
            }
            
            // Load recurring invoices on page load
            loadRecurringInvoices();
            
            // Apply saved state after loading invoices
            function applySavedState() {
                var hideInactive = getCookie('qbo_hide_inactive_invoices');
                if (hideInactive === 'true') {
                    $('.status-row-inactive').hide();
                    $('#hide-inactive-invoices').hide();
                    $('#show-inactive-invoices').show();
                } else {
                    $('.status-row-inactive').show();
                    $('#hide-inactive-invoices').show();
                    $('#show-inactive-invoices').hide();
                }
                updateInvoiceCount();
            }
            
            // Refresh button
            $('#refresh-recurring-invoices').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true);
                btn.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-update-alt');
                
                // Clear cache first
                $.post(ajaxurl, {
                    action: 'qbo_clear_recurring_invoices_cache',
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function() {
                    // Then reload the list
                    loadRecurringInvoices();
                    btn.prop('disabled', false);
                    btn.find('.dashicons').removeClass('dashicons-update-alt').addClass('dashicons-update');
                });
            });
            
            // Hide inactive invoices button
            $('#hide-inactive-invoices').on('click', function() {
                $('.status-row-inactive').hide();
                $(this).hide();
                $('#show-inactive-invoices').show();
                setCookie('qbo_hide_inactive_invoices', 'true', 30); // Save for 30 days
                updateInvoiceCount();
            });
            
            // Show inactive invoices button
            $('#show-inactive-invoices').on('click', function() {
                $('.status-row-inactive').show();
                $(this).hide();
                $('#hide-inactive-invoices').show();
                setCookie('qbo_hide_inactive_invoices', 'false', 30); // Save for 30 days
                updateInvoiceCount();
            });
            
            // View details functionality
            $(document).on('click', '.view-details', function() {
                var invoiceId = $(this).data('id');
                var btn = $(this);
                
                btn.prop('disabled', true);
                btn.find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-update');
                
                $.post(ajaxurl, {
                    action: 'qbo_get_recurring_invoice_details',
                    invoice_id: invoiceId,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    btn.prop('disabled', false);
                    btn.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-visibility');
                    
                    if (response.success) {
                        showDetailsModal(response.data);
                    } else {
                        alert('Error loading invoice details: ' + (response.data || 'Unknown error'));
                    }
                }).fail(function() {
                    btn.prop('disabled', false);
                    btn.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-visibility');
                    alert('Failed to load invoice details. Please try again.');
                });
            });
            
            // Edit invoice functionality
            $(document).on('click', '.edit-invoice', function() {
                var invoiceId = $(this).data('id');
                var btn = $(this);
                var row = btn.closest('tr');
                
                btn.prop('disabled', true);
                btn.find('.dashicons').removeClass('dashicons-edit').addClass('dashicons-update');
                
                // Get current invoice data
                $.post(ajaxurl, {
                    action: 'qbo_get_recurring_invoice_details',
                    invoice_id: invoiceId,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    btn.prop('disabled', false);
                    btn.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-edit');
                    
                    if (response.success) {
                        showEditModal(invoiceId, response.data, row);
                    } else {
                        alert('Error loading invoice details: ' + (response.data || 'Unknown error'));
                    }
                }).fail(function() {
                    btn.prop('disabled', false);
                    btn.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-edit');
                    alert('Failed to load invoice details. Please try again.');
                });
            });
            
            // Toggle status functionality
            $(document).on('click', '.toggle-status', function() {
                var invoiceId = $(this).data('id');
                var currentStatus = $(this).data('status');
                var btn = $(this);
                var row = btn.closest('tr');
                
                var action = (currentStatus === 'Active') ? 'deactivate' : 'activate';
                if (!confirm('Are you sure you want to ' + action + ' this recurring invoice?')) {
                    return;
                }
                
                btn.prop('disabled', true);
                btn.find('.dashicons').removeClass('dashicons-controls-pause dashicons-controls-play').addClass('dashicons-update');
                
                $.post(ajaxurl, {
                    action: 'qbo_toggle_recurring_invoice_status',
                    invoice_id: invoiceId,
                    current_status: currentStatus,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        // Reload the entire list to show updated status
                        loadRecurringInvoices();
                        alert(response.data);
                    } else {
                        btn.prop('disabled', false);
                        var iconClass = (currentStatus === 'Active') ? 'dashicons-controls-pause' : 'dashicons-controls-play';
                        btn.find('.dashicons').removeClass('dashicons-update').addClass(iconClass);
                        alert('Error: ' + (response.data || 'Unknown error'));
                    }
                }).fail(function() {
                    btn.prop('disabled', false);
                    var iconClass = (currentStatus === 'Active') ? 'dashicons-controls-pause' : 'dashicons-controls-play';
                    btn.find('.dashicons').removeClass('dashicons-update').addClass(iconClass);
                    alert('Failed to update invoice status. Please try again.');
                });
            });

            // Close modal functionality
            $(document).on('click', '.qbo-modal-close, .qbo-modal-overlay', function(e) {
                if (e.target === this) {
                    $('.qbo-modal').remove();
                }
            });
            
            // Escape key to close modal
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27) { // ESC key
                    $('.qbo-modal').remove();
                }
            });
            
            function loadRecurringInvoices() {
                $('#recurring-invoices-content').html('<p>Loading recurring invoices...</p>');
                
                $.post(ajaxurl, {
                    action: 'qbo_get_recurring_invoices',
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#recurring-invoices-content').html(response.data);
                        // Apply saved state after content is loaded
                        setTimeout(function() {
                            applySavedState();
                        }, 100);
                    } else {
                        $('#recurring-invoices-content').html('<div class="notice notice-error"><p>Failed to load recurring invoices.</p></div>');
                    }
                }).fail(function() {
                    $('#recurring-invoices-content').html('<div class="notice notice-error"><p>Failed to load recurring invoices.</p></div>');
                });
            }
            
            function updateInvoiceCount() {
                setTimeout(function() {
                    var totalRows = $('.invoice-row').length;
                    var visibleRows = $('.invoice-row:visible').length;
                    var activeRows = $('.status-row-active').length;
                    var inactiveRows = $('.status-row-inactive').length;
                    var hiddenRows = $('.status-row-inactive:hidden').length;
                    
                    // Remove existing count if present
                    $('.invoice-count').remove();
                    
                    var countText = 'Showing ' + visibleRows + ' of ' + totalRows + ' invoices';
                    if (hiddenRows > 0) {
                        countText += ' (' + hiddenRows + ' inactive hidden)';
                    }
                    countText += ' | Active: ' + activeRows + ', Inactive: ' + inactiveRows;
                    
                    $('.qbo-toolbar').append('<span class="invoice-count">' + countText + '</span>');
                }, 100);
            }
            
            function showDetailsModal(content) {
                // Remove existing modal
                $('.qbo-modal').remove();
                
                // Create modal
                var modal = $('<div class="qbo-modal qbo-modal-overlay">' +
                    '<div class="qbo-modal-content">' +
                    '<div class="qbo-modal-header">' +
                    '<h2>Recurring Invoice Details</h2>' +
                    '<button type="button" class="qbo-modal-close">&times;</button>' +
                    '</div>' +
                    '<div class="qbo-modal-body">' + content + '</div>' +
                    '<div class="qbo-modal-footer">' +
                    '<button type="button" class="button qbo-modal-close"><span class="dashicons dashicons-no-alt"></span> Close</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>');
                
                $('body').append(modal);
                modal.fadeIn(200);
            }
            
            function showEditModal(invoiceId, detailsContent, row) {
                // Remove existing modal
                $('.qbo-modal').remove();
                
                // Extract current values from the details content or row
                var currentName = '';
                var nameMatch = detailsContent.match(/<h3>(.*?)<\/h3>/);
                if (nameMatch) {
                    currentName = nameMatch[1].replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
                }
                
                var currentAmount = row.find('td:nth-child(2)').text().replace('$', '').replace(',', '');
                var currentFrequency = row.find('td:nth-child(3)').text();
                var currentCustomer = row.find('td:nth-child(1)').text();
                
                // Parse frequency to get interval type and number
                var intervalType = 'Monthly';
                var numInterval = 1;
                if (currentFrequency.toLowerCase().includes('every')) {
                    var parts = currentFrequency.split(' ');
                    if (parts.length >= 3) {
                        numInterval = parseInt(parts[1]) || 1;
                        intervalType = parts[2].charAt(0).toUpperCase() + parts[2].slice(1);
                    }
                } else if (currentFrequency !== 'N/A') {
                    intervalType = currentFrequency;
                }
                
                // Get current next date from row
                var currentNextDate = row.find('td:nth-child(4)').text();
                var nextDateValue = '';
                if (currentNextDate !== 'N/A') {
                    // Convert "Dec 15, 2024" format to "2024-12-15"
                    try {
                        var dateObj = new Date(currentNextDate);
                        if (!isNaN(dateObj.getTime())) {
                            nextDateValue = dateObj.toISOString().split('T')[0];
                        }
                    } catch (e) {
                        // Keep empty if parsing fails
                    }
                }
                
                // Extract customer memo from details if available
                var customerMemo = '';
                var memoMatch = detailsContent.match(/<div class="customer-memo">(.*?)<\/div>/);
                if (memoMatch) {
                    customerMemo = memoMatch[1].replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
                }
                
                var formHtml = '<form id="edit-invoice-form">' +
                    '<table class="form-table">' +
                    '<tr>' +
                    '<th scope="row"><label for="invoice-name">Invoice Name *</label></th>' +
                    '<td><input type="text" id="invoice-name" name="name" value="' + currentName + '" class="regular-text" required /></td>' +
                    '</tr>' +
                    '<tr>' +
                    '<th scope="row"><label>Customer</label></th>' +
                    '<td><span class="description">' + currentCustomer + ' (cannot be changed)</span></td>' +
                    '</tr>' +
                    '<tr>' +
                    '<th scope="row"><label for="invoice-amount">Total Amount * ($)</label></th>' +
                    '<td><input type="number" id="invoice-amount" name="amount" value="' + currentAmount + '" class="regular-text" step="0.01" min="0" required /></td>' +
                    '</tr>' +
                    '<tr>' +
                    '<th scope="row"><label for="invoice-frequency">Frequency</label></th>' +
                    '<td>' +
                    '<select id="invoice-frequency" name="frequency" class="regular-text">' +
                    '<option value="Weekly"' + (intervalType === 'Weekly' ? ' selected' : '') + '>Weekly</option>' +
                    '<option value="Monthly"' + (intervalType === 'Monthly' ? ' selected' : '') + '>Monthly</option>' +
                    '<option value="Quarterly"' + (intervalType === 'Quarterly' ? ' selected' : '') + '>Quarterly</option>' +
                    '<option value="Yearly"' + (intervalType === 'Yearly' ? ' selected' : '') + '>Yearly</option>' +
                    '</select>' +
                    '</td>' +
                    '</tr>' +
                    '<tr>' +
                    '<th scope="row"><label for="invoice-interval">Every X periods</label></th>' +
                    '<td><input type="number" id="invoice-interval" name="num_interval" value="' + numInterval + '" class="small-text" min="1" max="99" /></td>' +
                    '</tr>' +
                    '<tr>' +
                    '<th scope="row"><label for="invoice-next-date">Next Date</label></th>' +
                    '<td><input type="date" id="invoice-next-date" name="next_date" value="' + nextDateValue + '" class="regular-text" /></td>' +
                    '</tr>' +
                    '<tr>' +
                    '<th scope="row"><label for="invoice-memo">Customer Message</label></th>' +
                    '<td><textarea id="invoice-memo" name="customer_memo" class="large-text" rows="3">' + customerMemo + '</textarea></td>' +
                    '</tr>' +
                    '</table>' +
                    '</form>';
                
                // Create modal
                var modal = $('<div class="qbo-modal qbo-modal-overlay">' +
                    '<div class="qbo-modal-content qbo-edit-modal">' +
                    '<div class="qbo-modal-header">' +
                    '<h2>Edit Recurring Invoice</h2>' +
                    '<button type="button" class="qbo-modal-close">&times;</button>' +
                    '</div>' +
                    '<div class="qbo-modal-body">' + formHtml + '</div>' +
                    '<div class="qbo-modal-footer">' +
                    '<button type="button" class="button qbo-modal-close"><span class="dashicons dashicons-no-alt"></span> Cancel</button>' +
                    '<button type="button" class="button button-primary" id="save-invoice-changes" data-invoice-id="' + invoiceId + '"><span class="dashicons dashicons-saved"></span> Save Changes</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>');
                
                $('body').append(modal);
                modal.fadeIn(200);
                
                // Focus on first input
                setTimeout(function() {
                    $('#invoice-name').focus();
                }, 300);
            }
            
            // Save invoice changes
            $(document).on('click', '#save-invoice-changes', function() {
                var btn = $(this);
                var invoiceId = btn.data('invoice-id');
                var form = $('#edit-invoice-form');
                
                // Basic validation
                var name = $('#invoice-name').val().trim();
                var amount = parseFloat($('#invoice-amount').val());
                
                if (!name) {
                    alert('Invoice name is required.');
                    $('#invoice-name').focus();
                    return;
                }
                
                if (!amount || amount <= 0) {
                    alert('Amount must be greater than 0.');
                    $('#invoice-amount').focus();
                    return;
                }
                
                btn.prop('disabled', true);
                btn.html('<span class="dashicons dashicons-update"></span> Saving...');
                
                // Serialize form data
                var formData = {
                    action: 'qbo_update_recurring_invoice',
                    invoice_id: invoiceId,
                    name: name,
                    amount: amount,
                    frequency: $('#invoice-frequency').val(),
                    num_interval: parseInt($('#invoice-interval').val()) || 1,
                    next_date: $('#invoice-next-date').val(),
                    customer_memo: $('#invoice-memo').val(),
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                };
                
                $.post(ajaxurl, formData, function(response) {
                    if (response.success) {
                        $('.qbo-modal').remove();
                        loadRecurringInvoices(); // Reload the list
                        alert('Invoice updated successfully!');
                    } else {
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Changes');
                        alert('Error: ' + (response.data || 'Unknown error'));
                    }
                }).fail(function() {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Changes');
                    alert('Failed to save changes. Please try again.');
                });
            });
        });
        </script>
        
        <style>
        .qbo-recurring-invoices-container {
            margin-top: 20px;
        }
        
        .qbo-toolbar {
            margin-bottom: 20px;
            padding: 10px 0;
            border-bottom: 1px solid #ddd;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .toolbar-separator {
            width: 1px;
            height: 20px;
            background-color: #ddd;
            margin: 0 5px;
        }
        
        .invoice-count {
            margin-left: auto;
            color: #666;
            font-size: 13px;
            font-weight: 500;
        }
        
        .qbo-recurring-invoices-list table {
            margin-top: 0;
        }
        
        .status-active {
            color: #46b450;
            font-weight: bold;
        }
        
        .status-inactive {
            color: #dc3232;
            font-weight: bold;
        }
        
        .view-details {
            margin-right: 5px;
        }
        
        /* Hide/Show animation */
        .status-row-inactive {
            transition: opacity 0.3s ease;
        }
        
        .status-row-inactive:hidden {
            display: none !important;
        }
        
        /* Modal Styles */
        .qbo-modal {
            display: none;
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        
        .qbo-modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 0;
            border: none;
            border-radius: 4px;
            width: 90%;
            max-width: 1000px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .qbo-modal-header {
            padding: 20px 30px;
            border-bottom: 1px solid #ddd;
            background-color: #f8f9fa;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 4px 4px 0 0;
        }
        
        .qbo-modal-header h2 {
            margin: 0;
            color: #333;
        }
        
        .qbo-modal-close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            border: none;
            background: none;
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }
        
        .qbo-modal-close:hover,
        .qbo-modal-close:focus {
            color: #000;
            text-decoration: none;
        }
        
        .qbo-modal-body {
            padding: 30px;
        }
        
        .qbo-modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #ddd;
            background-color: #f8f9fa;
            text-align: right;
            border-radius: 0 0 4px 4px;
        }
        
        /* Edit Modal Styles */
        .qbo-edit-modal {
            max-width: 600px;
        }
        
        .qbo-edit-modal .form-table {
            width: 100%;
        }
        
        .qbo-edit-modal .form-table th {
            width: 200px;
            text-align: left;
            padding: 20px 10px 20px 0;
            vertical-align: top;
        }
        
        .qbo-edit-modal .form-table td {
            padding: 20px 0;
        }
        
        .qbo-edit-modal .regular-text {
            width: 100%;
            max-width: 300px;
        }
        
        .qbo-edit-modal .large-text {
            width: 100%;
            max-width: 300px;
        }
        
        .qbo-edit-modal .small-text {
            width: 60px;
        }
        
        .qbo-edit-modal .description {
            color: #666;
            font-style: italic;
        }
        
        .qbo-modal-footer {
            text-align: right;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }
        
        .qbo-modal-footer .button {
            margin-left: 10px;
        }
        
        /* Dashicon styles for buttons */
        .button .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
            line-height: 16px;
            vertical-align: middle;
        }
        
        .qbo-toolbar .button {
            min-width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .qbo-toolbar .button .dashicons {
            margin: 0;
        }
        
        .wp-list-table .button-small {
            min-width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 5px;
        }
        
        .wp-list-table .button-small .dashicons {
            font-size: 14px;
            width: 14px;
            height: 14px;
            line-height: 14px;
            margin: 0;
        }
        
        .qbo-modal-footer .button .dashicons {
            margin-right: 5px;
        }
        
        /* Rotating icon for loading states */
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .dashicons-update-alt,
        .button:disabled .dashicons-update {
            animation: spin 1s linear infinite;
        }

        @media (max-width: 768px) {
            .qbo-modal-content {
                width: 95%;
                margin: 2% auto;
            }
            
            .qbo-modal-header,
            .qbo-modal-body,
            .qbo-modal-footer {
                padding: 15px 20px;
            }
            
            .details-table td:first-child {
                width: auto;
                display: block;
                background-color: transparent;
                padding-bottom: 2px;
                border-bottom: none;
            }
            
            .details-table td:last-child {
                display: block;
                padding-top: 2px;
                padding-bottom: 15px;
                border-bottom: 1px solid #f0f0f0;
            }
        }
        </style>
        <?php
    }
}
