<?php
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class QBO_Students_List_Table extends WP_List_Table {
    private $team_id;
    private $students;
    private $show_alumni;
    private $core;

    public function __construct($args = array()) {
        parent::__construct([
            'singular' => 'student',
            'plural'   => 'students',
            'ajax'     => false,
            'screen'   => isset($args['screen']) ? $args['screen'] : null,
        ]);
        $this->team_id = isset($args['team_id']) ? intval($args['team_id']) : 0;
        $this->show_alumni = isset($args['show_alumni']) ? $args['show_alumni'] : false;
        $this->core = isset($args['core']) ? $args['core'] : null;
    }

    public function get_columns() {
        $columns = [
            'cb'           => '<input type="checkbox" />',
            'student_name' => 'Student Name',
            'grade'        => 'Grade',
            'tshirt_size'  => 'T-Shirt Size',
            'sex'          => 'Sex',
            'first_year_first' => 'First Year',
            'parent_name'  => 'Parent Name',
            'balance'      => 'Balance',
            'status'       => 'Status',
            'actions'      => 'Actions',
        ];
        
        // Hide some columns for alumni view
        if ($this->show_alumni) {
            unset($columns['grade'], $columns['tshirt_size'], $columns['sex']);
        }
        
        return $columns;
    }

    public function prepare_items() {
        global $wpdb;
        
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $orderby = !empty($_REQUEST['orderby']) ? esc_sql($_REQUEST['orderby']) : 'first_name';
        $order = !empty($_REQUEST['order']) ? esc_sql($_REQUEST['order']) : 'asc';
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $status_filter = isset($_REQUEST['status']) ? sanitize_text_field($_REQUEST['status']) : '';
        $grade_filter = isset($_REQUEST['grade']) ? sanitize_text_field($_REQUEST['grade']) : '';

        // Build WHERE clause
        $where_conditions = [$wpdb->prepare('team_id = %d', $this->team_id)];
        
        // Alumni/Student filter
        if ($this->show_alumni) {
            $where_conditions[] = "LOWER(grade) = 'alumni'";
        } else {
            $where_conditions[] = "(grade IS NULL OR grade = '' OR LOWER(grade) != 'alumni')";
        }
        
        // Search filter
        if ($search) {
            $where_conditions[] = $wpdb->prepare(
                '(CONCAT(first_name, " ", last_name) LIKE %s OR first_name LIKE %s OR last_name LIKE %s)', 
                "%{$search}%", 
                "%{$search}%", 
                "%{$search}%"
            );
        }
        
        // Status filter
        if ($status_filter && $status_filter !== 'all') {
            $where_conditions[] = $wpdb->prepare('status = %s', $status_filter);
        }
        
        // Grade filter
        if ($grade_filter && $grade_filter !== 'all') {
            $where_conditions[] = $wpdb->prepare('grade = %s', $grade_filter);
        }
        
        $where = 'WHERE ' . implode(' AND ', $where_conditions);
        
        // Get total count for pagination
        $total_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}gears_students $where";
        $total_items = $wpdb->get_var($total_sql);
        
        // Get paginated results
        $offset = ($current_page - 1) * $per_page;
        $sql = "SELECT * FROM {$wpdb->prefix}gears_students $where ORDER BY $orderby $order LIMIT $per_page OFFSET $offset";
        $this->items = $wpdb->get_results($sql);
        
        // Set up column headers for WordPress list table
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

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'student_name':
                $student_name = trim($item->first_name . ' ' . $item->last_name);
                return esc_html($student_name);
            case 'grade':
                if (strtolower($item->grade) === 'alumni') return 'Alumni';
                if ($item->grade === 'K') return 'Kindergarten';
                return esc_html($item->grade ? $item->grade . 'th Grade' : 'N/A');
            case 'tshirt_size':
                return esc_html($item->tshirt_size ?: 'N/A');
            case 'sex':
                return esc_html($item->sex ?: 'N/A');
            case 'first_year_first':
                return esc_html($item->first_year_first ?: 'N/A');
            case 'parent_name':
                // Get parent name from local cache only (no API calls)
                if (!empty($item->customer_id)) {
                    $parent_name = '';
                    
                    // Get customer info from local cache only
                    $cache = get_option('qbo_recurring_billing_customers_cache', array());
                    
                    if (isset($cache['data']) && is_array($cache['data'])) {
                        foreach ($cache['data'] as $cached_customer) {
                            if (isset($cached_customer['Id']) && $cached_customer['Id'] == $item->customer_id) {
                                $display_name = isset($cached_customer['DisplayName']) ? $cached_customer['DisplayName'] : '';
                                $company_name = isset($cached_customer['CompanyName']) ? $cached_customer['CompanyName'] : '';
                                $first_name = isset($cached_customer['GivenName']) ? $cached_customer['GivenName'] : '';
                                $last_name = isset($cached_customer['FamilyName']) ? $cached_customer['FamilyName'] : '';
                                
                                if (!empty($display_name)) {
                                    $parent_name = $display_name;
                                } elseif (!empty($company_name)) {
                                    $parent_name = $company_name;
                                } elseif (!empty($first_name) || !empty($last_name)) {
                                    $parent_name = trim($first_name . ' ' . $last_name);
                                }
                                break;
                            }
                        }
                    }
                    
                    // If we still don't have a parent name, show fallback
                    return !empty($parent_name) ? esc_html($parent_name) : 'No Parent Linked';
                } else {
                    return 'No Parent Linked';
                }
            case 'balance':
                // For now, return 0 - this would require QuickBooks integration
                return '$0.00'; // TODO: Implement balance calculation
            case 'status':
                // Check for recurring invoices to determine status
                $status = 'Inactive';
                
                if (!empty($item->customer_id)) {
                    // Get recurring invoices from local cache only
                    $recurring_cache = get_option('qbo_recurring_invoices_cache', array());
                    
                    if (isset($recurring_cache['data']) && is_array($recurring_cache['data'])) {
                        foreach ($recurring_cache['data'] as $invoice) {
                            // Check if this recurring invoice belongs to the customer
                            $customer_matches = false;
                            
                            if (isset($invoice['customer_ref']['value']) && $invoice['customer_ref']['value'] == $item->customer_id) {
                                $customer_matches = true;
                            } elseif (isset($invoice['Invoice']['CustomerRef']['value']) && $invoice['Invoice']['CustomerRef']['value'] == $item->customer_id) {
                                $customer_matches = true;
                            }
                            
                            if ($customer_matches) {
                                $status = 'Active';
                                break;
                            }
                        }
                    }
                }
                
                $class = ($status === 'Active') ? 'status-active' : 'status-inactive';
                return '<span class="status-badge ' . esc_attr($class) . '">' . esc_html($status) . '</span>';
            case 'actions':
                $actions = [];
                
                // Always add Details button - it will either go to customer page or show popup
                $student_name = trim($item->first_name . ' ' . $item->last_name);
                if (!empty($item->customer_id)) {
                    // If customer_id exists, link to customer detail page
                    $invoices_url = admin_url('admin.php?page=qbo-view-invoices&member_id=' . urlencode($item->customer_id));
                    $actions[] = '<a href="' . esc_url($invoices_url) . '" class="button button-small">Details</a>';
                } else {
                    // If no customer_id, show button that triggers popup
                    $actions[] = '<button type="button" class="button button-small no-customer-btn" onclick="showNoCustomerPopup(\'' . esc_js($student_name) . '\')">Details</button>';
                }
                
                $actions[] = '<a href="#" class="button button-small edit-student-btn" data-student-id="' . esc_attr($item->id) . '">Edit</a>';
                $actions[] = '<a href="#" class="button button-small button-link-delete delete-student-btn" data-student-id="' . esc_attr($item->id) . '" data-student-name="' . esc_attr($student_name) . '">Delete</a>';
                
                return implode(' ', $actions);
            default:
                return '';
        }
    }

    public function get_sortable_columns() {
        return [
            'student_name' => ['first_name', true],
            'grade'        => ['grade', false],
            'tshirt_size'  => ['tshirt_size', false],
            'sex'          => ['sex', false],
            'first_year_first' => ['first_year_first', false],
        ];
    }

    public function get_bulk_actions() {
        return [
            'delete' => 'Delete'
        ];
    }

    public function column_cb($item) {
        return '<input type="checkbox" name="student_id[]" value="' . esc_attr($item->id) . '" />';
    }
    
    /**
     * Get available status options for filtering
     */
    public function get_status_options() {
        global $wpdb;
        $statuses = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT status FROM {$wpdb->prefix}gears_students WHERE team_id = %d AND status IS NOT NULL AND status != ''",
            $this->team_id
        ));
        return $statuses;
    }
    
    /**
     * Get available grade options for filtering
     */
    public function get_grade_options() {
        global $wpdb;
        $grades = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT grade FROM {$wpdb->prefix}gears_students WHERE team_id = %d AND grade IS NOT NULL AND grade != '' AND LOWER(grade) != 'alumni'",
            $this->team_id
        ));
        return $grades;
    }
    
    /**
     * Display extra navigation elements (filters)
     */
    protected function extra_tablenav($which) {
        if ($which === 'top') {
            $status_filter = isset($_REQUEST['status']) ? sanitize_text_field($_REQUEST['status']) : '';
            $grade_filter = isset($_REQUEST['grade']) ? sanitize_text_field($_REQUEST['grade']) : '';
            
            echo '<div class="alignleft actions">';
            
            // Status filter
            $status_options = $this->get_status_options();
            if (!empty($status_options)) {
                echo '<select name="status" id="filter-by-status">';
                echo '<option value="all"' . selected($status_filter, 'all', false) . '>All Statuses</option>';
                foreach ($status_options as $status) {
                    echo '<option value="' . esc_attr($status) . '"' . selected($status_filter, $status, false) . '>' . esc_html($status) . '</option>';
                }
                echo '</select>';
            }
            
            // Grade filter (only for non-alumni view)
            if (!$this->show_alumni) {
                $grade_options = $this->get_grade_options();
                if (!empty($grade_options)) {
                    echo '<select name="grade" id="filter-by-grade">';
                    echo '<option value="all"' . selected($grade_filter, 'all', false) . '>All Grades</option>';
                    foreach ($grade_options as $grade) {
                        $grade_display = $grade;
                        if ($grade === 'K') {
                            $grade_display = 'Kindergarten';
                        } elseif (is_numeric($grade)) {
                            $grade_display = $grade . 'th Grade';
                        }
                        echo '<option value="' . esc_attr($grade) . '"' . selected($grade_filter, $grade, false) . '>' . esc_html($grade_display) . '</option>';
                    }
                    echo '</select>';
                }
            }
            
            if (!empty($status_options) || (!$this->show_alumni && !empty($grade_options))) {
                submit_button(__('Filter'), '', 'filter_action', false, array('id' => 'post-query-submit'));
            }
            
            echo '</div>';
        }
    }
    
    /**
     * Handle bulk actions
     */
    public function process_bulk_action() {
        if ('delete' === $this->current_action()) {
            // Get selected student IDs
            $student_ids = isset($_REQUEST['student_id']) ? (array) $_REQUEST['student_id'] : array();
            
            if (empty($student_ids)) {
                return;
            }
            
            // Verify nonce
            if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
                return;
            }
            
            global $wpdb;
            
            foreach ($student_ids as $student_id) {
                $student_id = intval($student_id);
                if ($student_id > 0) {
                    $wpdb->delete(
                        $wpdb->prefix . 'gears_students',
                        array('id' => $student_id),
                        array('%d')
                    );
                }
            }
            
            // Redirect to avoid reprocessing
            $redirect_url = remove_query_arg(array('action', 'student_id', '_wpnonce'));
            wp_redirect($redirect_url);
            exit;
        }
    }
    
    /**
     * Render the complete table with search and filters
     */
    public static function render_students_table($team_id, $show_alumni = false, $core = null) {
        $list_table = new self(array(
            'team_id' => $team_id,
            'show_alumni' => $show_alumni,
            'core' => $core
        ));
        
        $list_table->process_bulk_action();
        $list_table->prepare_items();
        
        $table_type = $show_alumni ? 'alumni' : 'students';
        $table_id = $show_alumni ? 'alumni-table' : 'students-table';
        $empty_message = $show_alumni ? 'No alumni for this team.' : 'No students assigned to this team.';
        
        ?>
        <form method="get" action="<?php echo admin_url('admin.php'); ?>" id="<?php echo esc_attr($table_id); ?>">
            <!-- Preserve essential parameters for team view -->
            <input type="hidden" name="page" value="qbo-teams" />
            <input type="hidden" name="action" value="view" />
            <input type="hidden" name="team_id" value="<?php echo esc_attr($team_id); ?>" />
            
            <?php 
            $list_table->search_box('Search ' . ($show_alumni ? 'Alumni' : 'Students'), 'search_id');
            $list_table->display(); 
            ?>
        </form>
        <?php
    }
}
