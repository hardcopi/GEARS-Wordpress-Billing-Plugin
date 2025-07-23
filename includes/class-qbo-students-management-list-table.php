<?php

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class QBO_Students_Management_List_Table extends WP_List_Table {
    
    private $tab;
    
    public function __construct($tab = 'fll') {
        parent::__construct(array(
            'singular' => 'student',
            'plural'   => 'students',
            'ajax'     => false
        ));
        $this->tab = $tab;
    }
    
    public function prepare_items() {
        global $wpdb;
        
        $table_students = $wpdb->prefix . 'gears_students';
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        // Handle sorting
        $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'last_name';
        $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'asc';
        
        // Handle search
        $search = (!empty($_REQUEST['s'])) ? trim($_REQUEST['s']) : '';
        
        // Handle pagination
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        
        // Build query
        $where_clause = '';
        $search_params = array();
        
        // Add tab-based filtering
        if ($this->tab === 'fll') {
            // FLL: Kindergarten through 8th grade
            $where_clause = "WHERE (s.grade IN ('K', '1', '2', '3', '4', '5', '6', '7', '8') OR s.grade IS NULL OR s.grade = '')";
        } elseif ($this->tab === 'ftc') {
            // FTC: 9th through 12th grade
            $where_clause = "WHERE s.grade IN ('9', '10', '11', '12')";
        } elseif ($this->tab === 'alumni') {
            // Alumni: Alumni designation
            $where_clause = "WHERE LOWER(s.grade) = 'alumni'";
        }
        
        if (!empty($search)) {
            $search_condition = "(s.first_name LIKE %s OR s.last_name LIKE %s OR s.grade LIKE %s OR t.team_name LIKE %s)";
            if (!empty($where_clause)) {
                $where_clause .= " AND " . $search_condition;
            } else {
                $where_clause = "WHERE " . $search_condition;
            }
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $search_params = array($search_term, $search_term, $search_term, $search_term);
        }
        
        // Validate orderby
        $allowed_orderby = array('first_name', 'last_name', 'grade', 'team_name', 'customer_name');
        if (!in_array($orderby, $allowed_orderby)) {
            $orderby = 'last_name';
        }
        
        // Validate order
        $order = strtoupper($order);
        if (!in_array($order, array('ASC', 'DESC'))) {
            $order = 'ASC';
        }
        
        // Handle team filter
        $team_filter = (!empty($_REQUEST['team_filter'])) ? intval($_REQUEST['team_filter']) : '';
        if (!empty($team_filter)) {
            $where_clause .= empty($where_clause) ? "WHERE s.team_id = %d" : " AND s.team_id = %d";
            $search_params[] = $team_filter;
        }
        
        // Get total count for pagination
        $count_query = "SELECT COUNT(*) FROM $table_students s LEFT JOIN $table_teams t ON s.team_id = t.id $where_clause";
        if (!empty($search_params)) {
            $total_items = $wpdb->get_var($wpdb->prepare($count_query, $search_params));
        } else {
            $total_items = $wpdb->get_var($count_query);
        }
        
        // Get students data
        $query = "
            SELECT s.*, t.team_name 
            FROM $table_students s 
            LEFT JOIN $table_teams t ON s.team_id = t.id 
            $where_clause
            ORDER BY $orderby $order
            LIMIT %d OFFSET %d
        ";
        
        $query_params = array_merge($search_params, array($per_page, $offset));
        $students = $wpdb->get_results($wpdb->prepare($query, $query_params));
        
        // Add customer names to students data
        $customers_cache = get_option('qbo_recurring_billing_customers_cache', array());
        $customers_lookup = array();
        $customer_student_counts = array();
        
        if (isset($customers_cache['data']) && is_array($customers_cache['data'])) {
            foreach ($customers_cache['data'] as $customer) {
                $customers_lookup[$customer['Id']] = isset($customer['DisplayName']) ? $customer['DisplayName'] : '';
            }
        }
        
        // Count students per customer for the filtered results
        $all_students_query = "SELECT s.customer_id FROM $table_students s LEFT JOIN $table_teams t ON s.team_id = t.id";
        $all_students = $wpdb->get_results($all_students_query);
        
        foreach ($all_students as $student) {
            if (!empty($student->customer_id)) {
                if (!isset($customer_student_counts[$student->customer_id])) {
                    $customer_student_counts[$student->customer_id] = 0;
                }
                $customer_student_counts[$student->customer_id]++;
            }
        }
        
        foreach ($students as $student) {
            $student->customer_name = isset($customers_lookup[$student->customer_id]) ? $customers_lookup[$student->customer_id] : 'No Customer';
            
            if (!empty($student->customer_id)) {
                $student->customer_student_count = $customer_student_counts[$student->customer_id];
                $student->is_multiple_students = $customer_student_counts[$student->customer_id] > 1;
            } else {
                $student->customer_student_count = 0;
                $student->is_multiple_students = false;
            }
        }
        
        $this->items = $students;
        
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ));
    }
    
    public function get_columns() {
        return array(
            'cb'            => '<input type="checkbox" />',
            'first_name'    => 'First Name',
            'last_name'     => 'Last Name', 
            'grade'         => 'Grade',
            'team_name'     => 'Team',
            'customer_name' => 'Customer',
            'tshirt_size'   => 'T-Shirt Size',
            'actions'       => 'Actions'
        );
    }
    
    public function get_sortable_columns() {
        return array(
            'first_name'    => array('first_name', false),
            'last_name'     => array('last_name', false),
            'grade'         => array('grade', false),
            'team_name'     => array('team_name', false),
            'customer_name' => array('customer_name', false)
        );
    }
    
    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="students[]" value="%s" />',
            $item->id
        );
    }
    
    public function column_first_name($item) {
        return esc_html($item->first_name);
    }
    
    public function column_last_name($item) {
        return esc_html($item->last_name);
    }
    
    public function column_grade($item) {
        if ($item->grade) {
            if (strtolower($item->grade) === 'alumni') {
                return esc_html($item->grade);
            } else {
                return esc_html($item->grade) . 'th Grade';
            }
        } else {
            return '<span class="na-value text-hidden">N/A</span>';
        }
    }
    
    public function column_team_name($item) {
        if ($item->team_name && $item->team_id) {
            $team_link = admin_url('admin.php?page=qbo-teams&action=view&team_id=' . $item->team_id);
            return '<a href="' . esc_url($team_link) . '" title="View Team Details">' . esc_html($item->team_name) . '</a>';
        } else {
            return esc_html('No Team');
        }
    }
    
    public function column_customer_name($item) {
        $output = esc_html($item->customer_name ?: 'No Customer');
        if ($item->is_multiple_students) {
            $output .= ' <span class="customer-student-count" style="color: #666; font-size: 0.9em;">(' . $item->customer_student_count . ' students)</span>';
        }
        return $output;
    }
    
    public function column_tshirt_size($item) {
        return $item->tshirt_size ? esc_html($item->tshirt_size) : '<span class="na-value text-hidden">N/A</span>';
    }
    
    public function column_actions($item) {
        $actions = array();
        
        $edit_button = sprintf(
            '<button type="button" class="button edit-student-btn" 
                    data-student-id="%s"
                    data-first-name="%s"
                    data-last-name="%s"
                    data-grade="%s"
                    data-team-id="%s"
                    data-customer-id="%s"
                    data-tshirt-size="%s">
                Edit
            </button>',
            esc_attr($item->id),
            esc_attr($item->first_name),
            esc_attr($item->last_name),
            esc_attr($item->grade),
            esc_attr($item->team_id),
            esc_attr($item->customer_id),
            esc_attr($item->tshirt_size)
        );
        
        $delete_form = sprintf(
            '<form method="post" style="display: inline; margin-left: 5px;">
                %s
                <input type="hidden" name="student_id" value="%s" />
                <input type="submit" name="delete_student" value="Delete" class="button button-secondary" 
                       onclick="return confirm(\'Are you sure you want to delete this student?\');" />
            </form>',
            wp_nonce_field('delete_student_action', 'delete_nonce', true, false),
            esc_attr($item->id)
        );
        
        // Add retire button (only for non-alumni students)
        $retire_button = '';
        if (strtolower($item->grade) !== 'alumni') {
            $retire_button = sprintf(
                '<button type="button" class="button button-small retire-student-btn" 
                        data-student-id="%s"
                        data-student-name="%s %s"
                        data-team-id="%s"
                        style="margin-left: 5px; background-color: #d63638; color: white;">
                    Retire
                </button>',
                esc_attr($item->id),
                esc_attr($item->first_name),
                esc_attr($item->last_name),
                esc_attr($item->team_id)
            );
        }
        
        return $edit_button . $delete_form . $retire_button;
    }
    
    public function get_bulk_actions() {
        return array(
            'delete' => 'Delete'
        );
    }
    
    public function process_bulk_action() {
        if ('delete' === $this->current_action()) {
            if (!empty($_REQUEST['students'])) {
                if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-students')) {
                    wp_die('Security check failed');
                }
                
                global $wpdb;
                $table_students = $wpdb->prefix . 'gears_students';
                
                $student_ids = array_map('intval', $_REQUEST['students']);
                $placeholders = implode(',', array_fill(0, count($student_ids), '%d'));
                
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM $table_students WHERE id IN ($placeholders)",
                    $student_ids
                ));
                
                if ($deleted !== false) {
                    add_action('admin_notices', function() use ($deleted) {
                        echo '<div class="notice notice-success"><p>' . sprintf(_n('%d student deleted.', '%d students deleted.', $deleted), $deleted) . '</p></div>';
                    });
                }
            }
        }
    }
    
    public function extra_tablenav($which) {
        if ($which === 'top') {
            global $wpdb;
            $table_teams = $wpdb->prefix . 'gears_teams';
            $teams = $wpdb->get_results("SELECT id, team_name FROM $table_teams ORDER BY team_name");
            
            $selected_team = isset($_REQUEST['team_filter']) ? $_REQUEST['team_filter'] : '';
            ?>
            <div class="alignleft actions">
                <select name="team_filter">
                    <option value="">All Teams</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?php echo esc_attr($team->id); ?>" <?php selected($selected_team, $team->id); ?>>
                            <?php echo esc_html($team->team_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button('Filter', 'secondary', 'filter_action', false); ?>
            </div>
            <?php
        }
    }
    
    public function no_items() {
        _e('No students found.');
    }
}
