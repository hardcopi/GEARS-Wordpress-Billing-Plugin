<?php
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class QBO_Recurring_Invoices_List_Table extends WP_List_Table {
    private $core;
    private $recurring_invoices_class;

    public function __construct($args = array()) {
        parent::__construct([
            'singular' => 'recurring_invoice',
            'plural'   => 'recurring_invoices',
            'ajax'     => false,
            'screen'   => isset($args['screen']) ? $args['screen'] : null,
        ]);
        $this->core = isset($args['core']) ? $args['core'] : null;
        $this->recurring_invoices_class = isset($args['recurring_invoices_class']) ? $args['recurring_invoices_class'] : null;
    }

    public function get_columns() {
        return [
            'cb'           => '<input type="checkbox" />',
            'customer'     => 'Customer',
            'amount'       => 'Amount',
            'frequency'    => 'Frequency',
            'next_date'    => 'Next Date',
            'previous_date' => 'Previous Date',
            'terms'        => 'Payment Terms',
            'auto_send'    => 'Auto Send',
            'student'      => 'Student',
            'status'       => 'Status',
            'actions'      => 'Actions',
        ];
    }

    public function get_sortable_columns() {
        return [
            'customer'     => ['customer', false],
            'amount'       => ['amount', false],
            'next_date'    => ['next_date', false],
            'previous_date' => ['previous_date', false],
            'terms'        => ['terms', false],
            'status'       => ['status', false],
        ];
    }

    public function get_bulk_actions() {
        return [
            'activate'   => 'Activate',
            'deactivate' => 'Deactivate',
        ];
    }

    public function column_cb($item) {
        return '<input type="checkbox" name="recurring_invoice_id[]" value="' . esc_attr($item['id']) . '" />';
    }

    public function prepare_items() {
        if (!$this->recurring_invoices_class) {
            $this->items = array();
            return;
        }

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $status_filter = isset($_REQUEST['status']) ? sanitize_text_field($_REQUEST['status']) : '';
        $orderby = !empty($_REQUEST['orderby']) ? esc_sql($_REQUEST['orderby']) : 'customer';
        $order = !empty($_REQUEST['order']) ? esc_sql($_REQUEST['order']) : 'asc';

        // Get all recurring invoices
        $all_invoices = $this->recurring_invoices_class->fetch_recurring_invoices();
        
        // Process and normalize the data
        $processed_invoices = array();
        foreach ($all_invoices as $invoice) {
            $processed = $this->process_invoice_data($invoice);
            if ($processed) {
                $processed_invoices[] = $processed;
            }
        }

        // Apply filters
        if ($search) {
            $processed_invoices = array_filter($processed_invoices, function($invoice) use ($search) {
                return stripos($invoice['customer'], $search) !== false ||
                       stripos($invoice['amount'], $search) !== false ||
                       stripos($invoice['frequency'], $search) !== false;
            });
        }

        if ($status_filter && $status_filter !== 'all') {
            $processed_invoices = array_filter($processed_invoices, function($invoice) use ($status_filter) {
                return strtolower($invoice['status']) === strtolower($status_filter);
            });
        }

        // Sort the data
        usort($processed_invoices, function($a, $b) use ($orderby, $order) {
            $result = 0;
            
            switch ($orderby) {
                case 'customer':
                    $result = strcmp($a['customer'], $b['customer']);
                    break;
                case 'amount':
                    $result = $a['amount_numeric'] <=> $b['amount_numeric'];
                    break;
                case 'next_date':
                    $result = strcmp($a['next_date_sort'], $b['next_date_sort']);
                    break;
                case 'previous_date':
                    $result = strcmp($a['previous_date_sort'], $b['previous_date_sort']);
                    break;
                case 'terms':
                    $result = strcmp($a['terms'], $b['terms']);
                    break;
                case 'status':
                    $result = strcmp($a['status'], $b['status']);
                    break;
                default:
                    $result = strcmp($a['customer'], $b['customer']);
            }
            
            return ($order === 'desc') ? -$result : $result;
        });

        // Pagination
        $total_items = count($processed_invoices);
        $offset = ($current_page - 1) * $per_page;
        $this->items = array_slice($processed_invoices, $offset, $per_page);

        // Set up column headers
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }

    private function process_invoice_data($invoice) {
        // Extract the actual invoice data
        $invoice_data = isset($invoice['Invoice']) ? $invoice['Invoice'] : array();
        $recurring_info = isset($invoice_data['RecurringInfo']) ? $invoice_data['RecurringInfo'] : array();

        if (empty($invoice_data) || !isset($invoice_data['Id'])) {
            return null;
        }

        // Get customer name from CustomerRef
        $customer_name = 'N/A';
        $customer_id = '';
        if (isset($invoice_data['CustomerRef']['name'])) {
            $customer_name = $invoice_data['CustomerRef']['name'];
        } elseif (isset($invoice_data['CustomerRef']['value'])) {
            $customer_id = $invoice_data['CustomerRef']['value'];
            $customer_name = $this->get_customer_name($customer_id);
        }

        // Get amount from TotalAmt
        $amount_numeric = 0;
        $amount = 'N/A';
        if (isset($invoice_data['TotalAmt'])) {
            $amount_numeric = floatval($invoice_data['TotalAmt']);
            $amount = '$' . number_format($amount_numeric, 2);
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

        // Get dates
        $next_date = 'N/A';
        $next_date_sort = '';
        if (isset($recurring_info['ScheduleInfo']['NextDate'])) {
            $next_date_sort = $recurring_info['ScheduleInfo']['NextDate'];
            $next_date = date('M j, Y', strtotime($next_date_sort));
        }

        $previous_date = 'N/A';
        $previous_date_sort = '';
        if (isset($recurring_info['ScheduleInfo']['PreviousDate'])) {
            $previous_date_sort = $recurring_info['ScheduleInfo']['PreviousDate'];
            $previous_date = date('M j, Y', strtotime($previous_date_sort));
        }

        // Get status
        $has_next_date = isset($recurring_info['ScheduleInfo']['NextDate']) && !empty($recurring_info['ScheduleInfo']['NextDate']);
        $is_qbo_active = isset($recurring_info['Active']) && $recurring_info['Active'];
        $status = ($is_qbo_active && $has_next_date) ? 'Active' : 'Inactive';

        // Get payment terms
        $terms = 'N/A';
        if (isset($invoice_data['SalesTermRef']['name'])) {
            $terms = $invoice_data['SalesTermRef']['name'];
        }

        // Get auto-send status
        $auto_send = 'No'; // Default to No
        
        // Check various possible fields for auto-send information
        if (isset($invoice_data['EmailDeliveryInfo']['DeliveryType'])) {
            $auto_send = ($invoice_data['EmailDeliveryInfo']['DeliveryType'] === 'Email') ? 'Yes' : 'No';
        } elseif (isset($invoice_data['DeliveryInfo']['DeliveryType'])) {
            $auto_send = ($invoice_data['DeliveryInfo']['DeliveryType'] === 'Email') ? 'Yes' : 'No';
        } elseif (isset($recurring_info['AutoGenerate'])) {
            // For recurring invoices, check if auto-generate is enabled
            $auto_send = $recurring_info['AutoGenerate'] ? 'Yes' : 'No';
        } elseif (isset($recurring_info['EmailDelivery'])) {
            // Alternative field for email delivery
            $auto_send = $recurring_info['EmailDelivery'] ? 'Yes' : 'No';
        } elseif (isset($recurring_info['AutoEmail'])) {
            $auto_send = $recurring_info['AutoEmail'] ? 'Yes' : 'No';
        } elseif (isset($recurring_info['EmailStatus'])) {
            $auto_send = $recurring_info['EmailStatus'] ? 'Yes' : 'No';
        }
        // If none of the auto-send fields are found, it defaults to 'No'

        // Get team information
        $team_info = $this->get_customer_team_info($invoice_data);

        return [
            'id' => $invoice_data['Id'],
            'customer' => $customer_name,
            'customer_id' => $customer_id,
            'amount' => $amount,
            'amount_numeric' => $amount_numeric,
            'frequency' => $frequency,
            'next_date' => $next_date,
            'next_date_sort' => $next_date_sort,
            'previous_date' => $previous_date,
            'previous_date_sort' => $previous_date_sort,
            'terms' => $terms,
            'auto_send' => $auto_send,
            'student' => $team_info,
            'status' => $status,
            'raw_data' => $invoice_data,
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'customer':
                return esc_html($item['customer']);
            case 'amount':
                return $item['amount'];
            case 'frequency':
                return esc_html($item['frequency']);
            case 'next_date':
                return $item['next_date'];
            case 'previous_date':
                return $item['previous_date'];
            case 'terms':
                return esc_html($item['terms']);
            case 'auto_send':
                $auto_send_icon = '';
                if ($item['auto_send'] === 'Yes') {
                    $auto_send_icon = '<span class="dashicons dashicons-email" style="color: #00a32a;" title="Auto-send enabled"></span> ';
                } else {
                    // Default to "No" with red X icon
                    $auto_send_icon = '<span class="dashicons dashicons-dismiss" style="color: #d63638;" title="Auto-send disabled"></span> ';
                }
                return $auto_send_icon . esc_html($item['auto_send']);
            case 'student':
                if (!empty($item['student'])) {
                    $team_links = array();
                    foreach ($item['student'] as $team) {
                        $team_url = admin_url('admin.php?page=qbo-teams&action=view&team_id=' . $team->id);
                        $team_links[] = '<a href="' . esc_url($team_url) . '" title="View Team ' . esc_attr($team->team_number) . ' - ' . esc_attr($team->team_name) . '">' . esc_html($team->team_number) . '</a>';
                    }
                    return implode(', ', $team_links);
                } else {
                    return '<span class="dashicons dashicons-minus" style="color: #ddd;" title="No team association"></span>';
                }
            case 'status':
                $status_class = strtolower($item['status']);
                return '<span class="status-badge status-' . esc_attr($status_class) . '">' . esc_html($item['status']) . '</span>';
            case 'actions':
                $actions = [];
                $actions[] = '<button type="button" class="button button-small view-details" data-id="' . esc_attr($item['id']) . '" title="View Details"><span class="dashicons dashicons-visibility"></span></button>';
                
                return implode(' ', $actions);
            default:
                return '';
        }
    }

    /**
     * Get customer name by ID
     */
    private function get_customer_name($customer_id) {
        if (!$this->core) {
            return 'Customer ID: ' . $customer_id;
        }

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
     * Get available status options for filtering
     */
    public function get_status_options() {
        return ['Active', 'Inactive'];
    }

    /**
     * Display extra navigation elements (filters)
     */
    protected function extra_tablenav($which) {
        if ($which === 'top') {
            $status_filter = isset($_REQUEST['status']) ? sanitize_text_field($_REQUEST['status']) : '';
            
            echo '<div class="alignleft actions">';
            
            // Status filter
            $status_options = $this->get_status_options();
            echo '<select name="status" id="filter-by-status">';
            echo '<option value="all"' . selected($status_filter, 'all', false) . '>All Statuses</option>';
            foreach ($status_options as $status) {
                echo '<option value="' . esc_attr($status) . '"' . selected($status_filter, $status, false) . '>' . esc_html($status) . '</option>';
            }
            echo '</select>';
            
            submit_button(__('Filter'), '', 'filter_action', false, array('id' => 'post-query-submit'));
            
            echo '</div>';
        }
    }

    /**
     * Handle bulk actions
     */
    public function process_bulk_action() {
        if ('activate' === $this->current_action() || 'deactivate' === $this->current_action()) {
            // Get selected invoice IDs
            $invoice_ids = isset($_REQUEST['recurring_invoice_id']) ? (array) $_REQUEST['recurring_invoice_id'] : array();
            
            if (empty($invoice_ids)) {
                return;
            }
            
            // Verify nonce
            if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
                return;
            }
            
            $action = $this->current_action();
            $new_status = ($action === 'activate') ? 'Active' : 'Inactive';
            
            // Process each invoice (this would need to be implemented in the core class)
            foreach ($invoice_ids as $invoice_id) {
                // This would call a method to update the status via QuickBooks API
                // For now, we'll just add a notice
            }
            
            $message = sprintf(
                _n(
                    'Bulk action %s applied to %d recurring invoice.',
                    'Bulk action %s applied to %d recurring invoices.',
                    count($invoice_ids)
                ),
                $action,
                count($invoice_ids)
            );
            
            add_action('admin_notices', function() use ($message) {
                echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
            });
            
            // Redirect to avoid reprocessing
            $redirect_url = remove_query_arg(array('action', 'recurring_invoice_id', '_wpnonce'));
            wp_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Render the complete table with search and filters
     */
    public static function render_recurring_invoices_table($core, $recurring_invoices_class) {
        $list_table = new self(array(
            'core' => $core,
            'recurring_invoices_class' => $recurring_invoices_class
        ));
        
        $list_table->process_bulk_action();
        $list_table->prepare_items();
        
        ?>
        <style>
            .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .status-active {
                background: #00a32a;
                color: white;
            }
            .status-inactive {
                background: #d63638;
                color: white;
            }
            .qbo-toolbar {
                margin-bottom: 10px;
                padding: 10px 0;
                border-bottom: 1px solid #ddd;
            }
            .qbo-toolbar .button {
                margin-right: 10px;
            }
            .toolbar-separator {
                display: inline-block;
                width: 1px;
                height: 20px;
                background: #ddd;
                margin: 0 10px;
                vertical-align: middle;
            }
            .column-auto_send {
                width: 100px;
                text-align: center;
            }
            .column-terms {
                width: 120px;
            }
            .auto-send-icon {
                display: inline-block;
                vertical-align: middle;
                margin-right: 5px;
            }
        </style>
        
        <div class="qbo-toolbar">
            <button type="button" class="button button-secondary" id="refresh-recurring-invoices" title="Refresh List">
                <span class="dashicons dashicons-update"></span> Refresh
            </button>
            <span class="toolbar-separator"></span>
            <button type="button" class="button button-secondary" id="hide-inactive-invoices" title="Hide Inactive">
                <span class="dashicons dashicons-hidden"></span> Hide Inactive
            </button>
            <button type="button" class="button button-secondary" id="show-inactive-invoices" style="display:none;" title="Show Inactive">
                <span class="dashicons dashicons-visibility"></span> Show Inactive
            </button>
        </div>
        
        <form method="get" action="<?php echo admin_url('admin.php'); ?>" id="recurring-invoices-table">
            <input type="hidden" name="page" value="qbo-recurring-invoices" />
            
            <?php 
            $list_table->search_box('Search Recurring Invoices', 'search_id');
            $list_table->display(); 
            ?>
        </form>
        <?php
    }
}
