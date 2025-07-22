<?php
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class QBO_Teams_List_Table extends WP_List_Table {
    private $show_archived;
    private $core;

    public function __construct($args = array()) {
        parent::__construct([
            'singular' => 'team',
            'plural'   => 'teams',
            'ajax'     => false,
            'screen'   => isset($args['screen']) ? $args['screen'] : null,
        ]);
        $this->show_archived = isset($args['show_archived']) ? $args['show_archived'] : false;
        $this->core = isset($args['core']) ? $args['core'] : null;
    }

    public function get_columns() {
        $columns = [
            'cb'           => '<input type="checkbox" />',
            'team_name'    => 'Team Name',
            'team_number'  => 'Team Number',
            'program'      => 'Program',
            'mentor_count' => 'Mentors',
            'student_count'=> 'Students',
            'hall_of_fame' => 'Hall of Fame',
            'actions'      => 'Actions',
        ];
        
        return $columns;
    }

    public function prepare_items() {
        global $wpdb;
        
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $orderby = !empty($_REQUEST['orderby']) ? esc_sql($_REQUEST['orderby']) : 'team_name';
        $order = !empty($_REQUEST['order']) ? esc_sql($_REQUEST['order']) : 'asc';
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $program_filter = isset($_REQUEST['program']) ? sanitize_text_field($_REQUEST['program']) : '';
        $hall_of_fame_filter = isset($_REQUEST['hall_of_fame']) ? sanitize_text_field($_REQUEST['hall_of_fame']) : '';

        $table_teams = $wpdb->prefix . 'gears_teams';
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        $table_students = $wpdb->prefix . 'gears_students';

        // Build WHERE clause
        $where_conditions = [];
        
        // Archive filter
        if ($this->show_archived) {
            $where_conditions[] = "t.archived = 1";
        } else {
            $where_conditions[] = "(t.archived = 0 OR t.archived IS NULL)";
        }
        
        // Search filter
        if ($search) {
            $where_conditions[] = $wpdb->prepare(
                '(t.team_name LIKE %s OR t.team_number LIKE %s OR t.description LIKE %s)', 
                "%{$search}%", 
                "%{$search}%", 
                "%{$search}%"
            );
        }
        
        // Program filter
        if ($program_filter && $program_filter !== 'all') {
            $where_conditions[] = $wpdb->prepare('t.program = %s', $program_filter);
        }
        
        // Hall of Fame filter
        if ($hall_of_fame_filter && $hall_of_fame_filter !== 'all') {
            if ($hall_of_fame_filter === 'yes') {
                $where_conditions[] = "t.hall_of_fame = 1";
            } else {
                $where_conditions[] = "(t.hall_of_fame = 0 OR t.hall_of_fame IS NULL)";
            }
        }
        
        $where = '';
        if (!empty($where_conditions)) {
            $where = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        // Main query with counts
        $base_query = "
            SELECT t.*, 
                   COALESCE(m.mentor_count, 0) as mentor_count,
                   COALESCE(s.student_count, 0) as student_count
            FROM $table_teams t
            LEFT JOIN (
                SELECT team_id, COUNT(*) as mentor_count 
                FROM $table_mentors 
                WHERE team_id IS NOT NULL
                GROUP BY team_id
            ) m ON t.id = m.team_id
            LEFT JOIN (
                SELECT team_id, COUNT(*) as student_count 
                FROM $table_students 
                WHERE team_id IS NOT NULL AND (grade != 'Alumni' OR grade IS NULL)
                GROUP BY team_id
            ) s ON t.id = s.team_id
            $where
        ";
        
        // Get total count for pagination
        $count_query = "SELECT COUNT(*) FROM ($base_query) as count_table";
        $total_items = $wpdb->get_var($count_query);
        
        // Get paginated results
        $offset = ($current_page - 1) * $per_page;
        $sql = "$base_query ORDER BY $orderby $order LIMIT $per_page OFFSET $offset";
        $this->items = $wpdb->get_results($sql);
        
        // Handle query errors
        if ($wpdb->last_error) {
            error_log('QBO Teams List Table Query Error: ' . $wpdb->last_error);
            // Fallback to simple query
            $simple_where = $this->show_archived ? "WHERE (archived = 1)" : "WHERE (archived = 0 OR archived IS NULL)";
            $simple_sql = "SELECT *, 0 as mentor_count, 0 as student_count FROM $table_teams $simple_where ORDER BY $orderby $order LIMIT $per_page OFFSET $offset";
            $this->items = $wpdb->get_results($simple_sql);
            $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_teams $simple_where");
        }
        
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
            case 'team_name':
                return '<strong>' . esc_html($item->team_name) . '</strong>';
            case 'team_number':
                return esc_html($item->team_number ?: 'N/A');
            case 'program':
                return $this->get_program_display($item->program);
            case 'mentor_count':
                $mentor_count = intval($item->mentor_count);
                if ($mentor_count == 0) {
                    return '<span style="color: #d63638; font-weight: bold;">' . $mentor_count . '</span>';
                } else {
                    return $mentor_count;
                }
            case 'student_count':
                $student_count = intval($item->student_count);
                if ($student_count < 3) {
                    return '<span style="color: #d63638; font-weight: bold;">' . $student_count . '</span>';
                } else {
                    return $student_count;
                }
            case 'hall_of_fame':
                return !empty($item->hall_of_fame) ? '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;" title="Hall of Fame Team"></span>' : '';
            case 'actions':
                $actions = [];
                
                // Edit/View button
                $edit_url = admin_url('admin.php?page=qbo-teams&action=view&team_id=' . intval($item->id));
                $actions[] = '<a href="' . esc_url($edit_url) . '" class="button button-small">Edit</a>';
                
                // Archive/Restore button
                if ($this->show_archived) {
                    $actions[] = '<button type="button" class="button button-small restore-team-btn" data-team-id="' . esc_attr($item->id) . '" data-team-name="' . esc_attr($item->team_name) . '">Restore</button>';
                } else {
                    $actions[] = '<button type="button" class="button button-small button-link-delete archive-team-btn" data-team-id="' . esc_attr($item->id) . '" data-team-name="' . esc_attr($item->team_name) . '">Move to Past</button>';
                }
                
                return implode(' ', $actions);
            default:
                return '';
        }
    }

    public function get_sortable_columns() {
        return [
            'team_name'     => ['team_name', true],
            'team_number'   => ['team_number', false],
            'program'       => ['program', false],
            'mentor_count'  => ['mentor_count', false],
            'student_count' => ['student_count', false],
            'hall_of_fame'  => ['hall_of_fame', false],
        ];
    }

    public function get_bulk_actions() {
        if ($this->show_archived) {
            return [
                'restore' => 'Restore'
            ];
        } else {
            return [
                'archive' => 'Move to Past Teams'
            ];
        }
    }

    public function column_cb($item) {
        return '<input type="checkbox" name="team_id[]" value="' . esc_attr($item->id) . '" />';
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
     * Get available program options for filtering
     */
    public function get_program_options() {
        global $wpdb;
        $table_teams = $wpdb->prefix . 'gears_teams';
        $archive_condition = $this->show_archived ? "archived = 1" : "(archived = 0 OR archived IS NULL)";
        
        $programs = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT program FROM $table_teams WHERE $archive_condition AND program IS NOT NULL AND program != ''",
            []
        ));
        return $programs;
    }
    
    /**
     * Display extra navigation elements (filters)
     */
    protected function extra_tablenav($which) {
        if ($which === 'top') {
            $program_filter = isset($_REQUEST['program']) ? sanitize_text_field($_REQUEST['program']) : '';
            $hall_of_fame_filter = isset($_REQUEST['hall_of_fame']) ? sanitize_text_field($_REQUEST['hall_of_fame']) : '';
            
            echo '<div class="alignleft actions">';
            
            // Program filter
            $program_options = $this->get_program_options();
            if (!empty($program_options)) {
                echo '<select name="program" id="filter-by-program">';
                echo '<option value="all"' . selected($program_filter, 'all', false) . '>All Programs</option>';
                foreach ($program_options as $program) {
                    echo '<option value="' . esc_attr($program) . '"' . selected($program_filter, $program, false) . '>' . esc_html($program) . '</option>';
                }
                echo '</select>';
            }
            
            // Hall of Fame filter (only for active teams)
            if (!$this->show_archived) {
                echo '<select name="hall_of_fame" id="filter-by-hall-of-fame">';
                echo '<option value="all"' . selected($hall_of_fame_filter, 'all', false) . '>All Teams</option>';
                echo '<option value="yes"' . selected($hall_of_fame_filter, 'yes', false) . '>Hall of Fame</option>';
                echo '<option value="no"' . selected($hall_of_fame_filter, 'no', false) . '>Regular Teams</option>';
                echo '</select>';
            }
            
            if (!empty($program_options) || !$this->show_archived) {
                submit_button(__('Filter'), '', 'filter_action', false, array('id' => 'post-query-submit'));
            }
            
            echo '</div>';
        }
    }
    
    /**
     * Handle bulk actions
     */
    public function process_bulk_action() {
        if (in_array($this->current_action(), ['archive', 'restore'])) {
            // Get selected team IDs
            $team_ids = isset($_REQUEST['team_id']) ? (array) $_REQUEST['team_id'] : array();
            
            if (empty($team_ids)) {
                return;
            }
            
            // Verify nonce
            if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
                return;
            }
            
            global $wpdb;
            $table_teams = $wpdb->prefix . 'gears_teams';
            
            $archive_value = ($this->current_action() === 'archive') ? 1 : 0;
            
            foreach ($team_ids as $team_id) {
                $team_id = intval($team_id);
                if ($team_id > 0) {
                    $wpdb->update(
                        $table_teams,
                        array('archived' => $archive_value),
                        array('id' => $team_id),
                        array('%d'),
                        array('%d')
                    );
                }
            }
            
            // Redirect to avoid reprocessing
            $redirect_url = remove_query_arg(array('action', 'team_id', '_wpnonce'));
            wp_redirect($redirect_url);
            exit;
        }
    }
    
    /**
     * Render the complete table with search and filters
     */
    public static function render_teams_table($show_archived = false, $core = null) {
        $list_table = new self(array(
            'show_archived' => $show_archived,
            'core' => $core
        ));
        
        $list_table->process_bulk_action();
        $list_table->prepare_items();
        
        $table_type = $show_archived ? 'archived' : 'active';
        $table_id = $show_archived ? 'archived-teams-table' : 'teams-table';
        $empty_message = $show_archived ? 'No past teams found.' : 'No teams found.';
        
        ?>
        <form method="get" action="<?php echo admin_url('admin.php'); ?>" id="<?php echo esc_attr($table_id); ?>">
            <!-- Preserve essential parameters -->
            <input type="hidden" name="page" value="qbo-teams" />
            <?php if ($show_archived): ?>
                <input type="hidden" name="show_archived" value="1" />
            <?php endif; ?>
            
            <?php 
            $list_table->search_box('Search ' . ($show_archived ? 'Past Teams' : 'Teams'), 'search_id');
            $list_table->display(); 
            ?>
        </form>
        <?php
    }
}
