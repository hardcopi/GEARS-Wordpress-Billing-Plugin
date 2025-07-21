<?php
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class QBO_Mentors_List_Table extends WP_List_Table {
    private $team_id;

    public function __construct($args = array()) {
        parent::__construct([
            'singular' => 'mentor',
            'plural'   => 'mentors',
            'ajax'     => false,
            'screen'   => isset($args['screen']) ? $args['screen'] : null,
        ]);
        $this->team_id = isset($args['team_id']) ? intval($args['team_id']) : 0;
    }

    public function get_columns() {
        return [
            'cb'           => '<input type="checkbox" />',
            'mentor_name'  => 'Mentor Name',
            'email'        => 'Email',
            'phone'        => 'Phone',
            'address'      => 'Address',
            'notes'        => 'Notes',
            'actions'      => 'Actions',
        ];
    }

    public function prepare_items() {
        global $wpdb;
        
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $orderby = !empty($_REQUEST['orderby']) ? esc_sql($_REQUEST['orderby']) : 'last_name';
        $order = !empty($_REQUEST['order']) ? esc_sql($_REQUEST['order']) : 'asc';
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';

        // Build WHERE clause
        $where_conditions = [$wpdb->prepare('team_id = %d', $this->team_id)];
        
        // Search filter
        if ($search) {
            $where_conditions[] = $wpdb->prepare(
                '(CONCAT(first_name, " ", last_name) LIKE %s OR email LIKE %s OR phone LIKE %s)', 
                "%{$search}%", 
                "%{$search}%", 
                "%{$search}%"
            );
        }
        
        $where = 'WHERE ' . implode(' AND ', $where_conditions);
        
        // Get total count for pagination
        $total_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}gears_mentors $where";
        $total_items = $wpdb->get_var($total_sql);
        
        // Get paginated results
        $offset = ($current_page - 1) * $per_page;
        $sql = "SELECT * FROM {$wpdb->prefix}gears_mentors $where ORDER BY $orderby $order LIMIT $per_page OFFSET $offset";
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
            case 'mentor_name':
                $mentor_name = '';
                if (!empty($item->first_name) || !empty($item->last_name)) {
                    $mentor_name = trim($item->first_name . ' ' . $item->last_name);
                } elseif (!empty($item->mentor_name)) {
                    $mentor_name = $item->mentor_name;
                }
                return esc_html($mentor_name ?: 'N/A');
            case 'email':
                return esc_html($item->email ?: 'N/A');
            case 'phone':
                return esc_html($item->phone ?: 'N/A');
            case 'address':
                return esc_html($item->address ?: 'N/A');
            case 'notes':
                $notes = $item->notes ?: 'N/A';
                // Truncate long notes
                if (strlen($notes) > 50) {
                    $notes = substr($notes, 0, 47) . '...';
                }
                return esc_html($notes);
            case 'actions':
                $actions = [];
                
                $mentor_name = '';
                if (!empty($item->first_name) || !empty($item->last_name)) {
                    $mentor_name = trim($item->first_name . ' ' . $item->last_name);
                } elseif (!empty($item->mentor_name)) {
                    $mentor_name = $item->mentor_name;
                }
                
                $actions[] = '<a href="#" class="button button-small edit-mentor-btn" data-mentor-id="' . esc_attr($item->id) . '">Edit</a>';
                $actions[] = '<a href="#" class="button button-small button-link-delete delete-mentor-btn" data-mentor-id="' . esc_attr($item->id) . '" data-mentor-name="' . esc_attr($mentor_name) . '">Delete</a>';
                
                return implode(' ', $actions);
            default:
                return '';
        }
    }

    public function get_sortable_columns() {
        return [
            'mentor_name' => ['last_name', true],
            'email'       => ['email', false],
            'phone'       => ['phone', false],
        ];
    }

    public function get_bulk_actions() {
        return [
            'delete' => 'Delete'
        ];
    }

    public function column_cb($item) {
        return '<input type="checkbox" name="mentor_id[]" value="' . esc_attr($item->id) . '" />';
    }
    
    /**
     * Handle bulk actions
     */
    public function process_bulk_action() {
        if ('delete' === $this->current_action()) {
            // Get selected mentor IDs
            $mentor_ids = isset($_REQUEST['mentor_id']) ? (array) $_REQUEST['mentor_id'] : array();
            
            if (empty($mentor_ids)) {
                return;
            }
            
            // Verify nonce
            if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
                return;
            }
            
            global $wpdb;
            
            foreach ($mentor_ids as $mentor_id) {
                $mentor_id = intval($mentor_id);
                if ($mentor_id > 0) {
                    $wpdb->delete(
                        $wpdb->prefix . 'gears_mentors',
                        array('id' => $mentor_id),
                        array('%d')
                    );
                }
            }
            
            // Redirect to avoid reprocessing
            $redirect_url = remove_query_arg(array('action', 'mentor_id', '_wpnonce'));
            wp_redirect($redirect_url);
            exit;
        }
    }
    
    /**
     * Render the complete table with search and filters
     */
    public static function render_mentors_table($team_id) {
        $list_table = new self(array(
            'team_id' => $team_id
        ));
        
        $list_table->process_bulk_action();
        $list_table->prepare_items();
        
        ?>
        <form method="get" action="<?php echo admin_url('admin.php'); ?>" id="mentors-table">
            <!-- Preserve essential parameters for team view -->
            <input type="hidden" name="page" value="qbo-teams" />
            <input type="hidden" name="action" value="view" />
            <input type="hidden" name="team_id" value="<?php echo esc_attr($team_id); ?>" />
            
            <?php 
            $list_table->search_box('Search Mentors', 'search_id');
            $list_table->display(); 
            ?>
        </form>
        <?php
    }
}
