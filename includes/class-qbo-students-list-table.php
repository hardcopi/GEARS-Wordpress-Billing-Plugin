<?php
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class QBO_Students_List_Table extends WP_List_Table {
    private $team_id;
    private $students;

    public function __construct($args = array()) {
        parent::__construct([
            'singular' => 'student',
            'plural'   => 'students',
            'ajax'     => false
        ]);
        $this->team_id = isset($args['team_id']) ? intval($args['team_id']) : 0;
    }

    public function get_columns() {
        return [
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
    }

    public function prepare_items() {
        global $wpdb;
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $orderby = !empty($_REQUEST['orderby']) ? esc_sql($_REQUEST['orderby']) : 'student_name';
        $order = !empty($_REQUEST['order']) ? esc_sql($_REQUEST['order']) : 'asc';
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';

        $where = $wpdb->prepare('WHERE team_id = %d', $this->team_id);
        if ($search) {
            $where .= $wpdb->prepare(' AND (student_name LIKE %s OR parent_name LIKE %s)', "%{$search}%", "%{$search}%");
        }
        $sql = "SELECT * FROM {$wpdb->prefix}gears_students $where ORDER BY $orderby $order";
        $results = $wpdb->get_results($sql);
        $total_items = count($results);
        $paged_data = array_slice($results, ($current_page - 1) * $per_page, $per_page);
        $this->items = $paged_data;
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'student_name':
                return esc_html($item->student_name);
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
                return esc_html($item->parent_name ?: '');
            case 'balance':
                return '$' . number_format((float)$item->balance, 2);
            case 'status':
                $class = ($item->status === 'Active') ? 'status-active' : 'status-inactive';
                return '<span class="status-badge ' . esc_attr($class) . '">' . esc_html($item->status) . '</span>';
            case 'actions':
                $edit = '<a href="#" class="button button-small edit-student-btn" data-student-id="' . esc_attr($item->student_id) . '">Edit</a>';
                $delete = '<a href="#" class="button button-small button-link-delete delete-student-btn" data-student-id="' . esc_attr($item->student_id) . '" data-student-name="' . esc_attr($item->student_name) . '">Delete</a>';
                return $edit . ' ' . $delete;
            default:
                return '';
        }
    }

    public function get_sortable_columns() {
        return [
            'student_name' => ['student_name', true],
            'grade'        => ['grade', false],
            'tshirt_size'  => ['tshirt_size', false],
            'sex'          => ['sex', false],
            'first_year_first' => ['first_year_first', false],
            'parent_name'  => ['parent_name', false],
            'balance'      => ['balance', false],
            'status'       => ['status', false],
        ];
    }

    public function get_bulk_actions() {
        return [
            'delete' => 'Delete'
        ];
    }

    public function column_cb($item) {
        return '<input type="checkbox" name="student_id[]" value="' . esc_attr($item->student_id) . '" />';
    }
}
