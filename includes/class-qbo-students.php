<?php
/**
 * QBO Students Class
 * 
 * Handles students management functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class QBO_Students {
    
    private $core;
    private $database;
    
    public function __construct($core, $database) {
        $this->core = $core;
        $this->database = $database;
        
        // AJAX handlers
        add_action('wp_ajax_qbo_add_student', array($this, 'ajax_add_student'));
        add_action('wp_ajax_qbo_edit_student', array($this, 'ajax_edit_student'));
        add_action('wp_ajax_qbo_delete_student', array($this, 'ajax_delete_student'));
        add_action('wp_ajax_qbo_get_students', array($this, 'ajax_get_students'));
        add_action('wp_ajax_qbo_get_customer_students', array($this, 'ajax_get_customer_students'));
        add_action('wp_ajax_qbo_add_team_history', array($this, 'ajax_add_team_history'));
        add_action('wp_ajax_qbo_delete_team_history', array($this, 'ajax_delete_team_history'));
        add_action('wp_ajax_qbo_get_team_history', array($this, 'ajax_get_team_history'));
        add_action('wp_ajax_qbo_retire_student', array($this, 'ajax_retire_student'));
    }
    
    /**
     * Render students page
     */
    public function render_page() {
        global $wpdb;
        
        $table_students = $wpdb->prefix . 'gears_students';
        
        // Handle form submissions
        if ($_POST) {
            $this->handle_form_submissions($table_students);
        }
        
        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'fll';
        if (!in_array($current_tab, ['fll', 'ftc', 'alumni'])) {
            $current_tab = 'fll';
        }
        
        // Create and prepare the list table
        $list_table = new QBO_Students_Management_List_Table($current_tab);
        $list_table->process_bulk_action();
        $list_table->prepare_items();
        
        // Get teams and customers for the modal
        $table_teams = $wpdb->prefix . 'gears_teams';
        $teams = $wpdb->get_results("SELECT id, team_name FROM $table_teams ORDER BY team_name");
        $customers = $this->get_customers_for_dropdown();
        
        // Get student counts for each tab
        $fll_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_students WHERE grade IN ('K', '1', '2', '3', '4', '5', '6', '7', '8') OR grade IS NULL OR grade = ''");
        $ftc_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_students WHERE grade IN ('9', '10', '11', '12')");
        $alumni_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_students WHERE LOWER(grade) = 'alumni'");
        
        $this->render_page_html_with_list_table($list_table, $teams, $customers, $current_tab, $fll_count, $ftc_count, $alumni_count);
    }
    
    /**
     * Handle form submissions
     */
    private function handle_form_submissions($table_students) {
        global $wpdb;
        
        if (isset($_POST['add_student']) && wp_verify_nonce($_POST['student_nonce'], 'add_student_action')) {
            $this->handle_add_student($table_students);
        } elseif (isset($_POST['update_student']) && wp_verify_nonce($_POST['student_edit_nonce'], 'update_student_action')) {
            $this->handle_update_student($table_students);
        } elseif (isset($_POST['delete_student']) && wp_verify_nonce($_POST['delete_nonce'], 'delete_student_action')) {
            $this->handle_delete_student($table_students);
        }
    }
    
    /**
     * Handle add student
     */
    private function handle_add_student($table_students) {
        global $wpdb;
        
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $grade = sanitize_text_field($_POST['grade']);
        $team_id = intval($_POST['team_id']);
        $customer_id = sanitize_text_field($_POST['customer_id']);
        $first_year = intval($_POST['first_year']);
        $tshirt_size = isset($_POST['tshirt_size']) ? sanitize_text_field($_POST['tshirt_size']) : '';

        echo '<div style="color:blue;">DEBUG: ADD STUDENT: tshirt_size from POST: ' . htmlspecialchars($tshirt_size) . '</div>';
        
        $insert_data = array(
            'first_name' => $first_name,
            'last_name' => $last_name,
            'grade' => $grade,
            'team_id' => $team_id,
            'customer_id' => $customer_id,
            'first_year_first' => $first_year,
            'tshirt_size' => $tshirt_size,
            'created_at' => current_time('mysql')
        );
        echo '<div style="color:orange;">DEBUG: ADD STUDENT SQL DATA: <pre>' . print_r($insert_data, true) . '</pre></div>';
        
        $result = $wpdb->insert(
            $table_students,
            $insert_data,
            array('%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s')
        );
        
        if ($result !== false) {
            // Get the new student ID
            $student_id = $wpdb->insert_id;
            
            // Create initial team history entry if team is assigned
            if ($team_id && $team_id > 0) {
                $this->handle_team_change($student_id, 0, $team_id, intval($grade));
            }
            
            $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'fll';
            wp_redirect(admin_url('admin.php?page=qbo-students&tab=' . $current_tab . '&added=1'));
            exit;
        } else {
            echo '<div class="notice notice-error"><p>Error adding student: ' . $wpdb->last_error . '</p></div>';
        }
    }
    
    /**
     * Handle team change for student
     */
    private function handle_team_change($student_id, $old_team_id, $new_team_id, $grade_level) {
        global $wpdb;
        
        // Skip if no actual team change
        if ($old_team_id == $new_team_id) {
            return;
        }
        
        $table_student_team_history = $wpdb->prefix . 'gears_student_team_history';
        
        // End the current team assignment if it exists
        if ($old_team_id && $old_team_id > 0) {
            $wpdb->update(
                $table_student_team_history,
                array(
                    'end_date' => date('Y-m-d'),
                    'is_current' => 0,
                    'updated_at' => current_time('mysql')
                ),
                array(
                    'student_id' => $student_id,
                    'team_id' => $old_team_id,
                    'is_current' => 1
                ),
                array('%s', '%d', '%s'),
                array('%d', '%d', '%d')
            );
        }
        
        // Add new team assignment if new team is selected
        if ($new_team_id && $new_team_id > 0) {
            // Determine program based on grade level
            $program = '';
            if ($grade_level >= 4 && $grade_level <= 8) {
                $program = 'FLL';
            } elseif ($grade_level >= 9 && $grade_level <= 12) {
                $program = 'FTC';
            } else {
                $program = 'Alumni';
            }
            
            $wpdb->insert(
                $table_student_team_history,
                array(
                    'student_id' => $student_id,
                    'team_id' => $new_team_id,
                    'start_date' => date('Y-m-d'),
                    'end_date' => null,
                    'season' => date('Y') . '-' . (date('Y') + 1),
                    'program' => $program,
                    'is_current' => 1,
                    'reason_for_change' => $old_team_id ? 'Team transfer' : 'Initial assignment',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
            );
        }
    }
    
    /**
     * Handle update student
     */
    private function handle_update_student($table_students) {
        global $wpdb;
        
        $student_id = intval($_POST['student_id']);
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $grade = sanitize_text_field($_POST['grade']);
        $new_team_id = intval($_POST['team_id']);
        $customer_id = sanitize_text_field($_POST['customer_id']);
        $first_year = intval($_POST['first_year']);
        $tshirt_size = isset($_POST['tshirt_size']) ? sanitize_text_field($_POST['tshirt_size']) : '';

        echo '<div style="color:blue;">DEBUG: UPDATE STUDENT: tshirt_size from POST: ' . htmlspecialchars($tshirt_size) . '</div>';
        
        // Get current student data to check for team changes
        $current_student = $wpdb->get_row($wpdb->prepare(
            "SELECT team_id, grade_level FROM $table_students WHERE id = %d",
            $student_id
        ));
        
        $old_team_id = $current_student ? intval($current_student->team_id) : 0;
        
        $update_data = array(
            'first_name' => $first_name,
            'last_name' => $last_name,
            'grade' => $grade,
            'team_id' => $new_team_id,
            'customer_id' => $customer_id,
            'first_year_first' => $first_year,
            'tshirt_size' => $tshirt_size
        );
        echo '<div style="color:orange;">DEBUG: UPDATE STUDENT SQL DATA: <pre>' . print_r($update_data, true) . '</pre></div>';
        
        $result = $wpdb->update(
            $table_students,
            $update_data,
            array('id' => $student_id),
            array('%s', '%s', '%s', '%d', '%s', '%d', '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            // Handle team change in history table
            $this->handle_team_change($student_id, $old_team_id, $new_team_id, intval($grade));
            
            $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'fll';
            wp_redirect(admin_url('admin.php?page=qbo-students&tab=' . $current_tab . '&updated=1'));
            exit;
        } else {
            echo '<div class="notice notice-error"><p>Error updating student: ' . $wpdb->last_error . '</p></div>';
        }
    }
    
    /**
     * Handle delete student
     */
    private function handle_delete_student($table_students) {
        global $wpdb;
        
        $student_id = intval($_POST['student_id']);
        
        $result = $wpdb->delete(
            $table_students,
            array('id' => $student_id),
            array('%d')
        );
        
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>Student deleted successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error deleting student: ' . $wpdb->last_error . '</p></div>';
        }
    }
    
    /**
     * Get customers from cache for dropdown
     */
    private function get_customers_for_dropdown() {
        $cache = get_option('qbo_recurring_billing_customers_cache', array());
        $customers = array();
        
        if (isset($cache['data']) && is_array($cache['data'])) {
            foreach ($cache['data'] as $customer) {
                $display_name = isset($customer['DisplayName']) ? $customer['DisplayName'] : '';
                $customer_id = isset($customer['Id']) ? $customer['Id'] : '';
                
                if (!empty($customer_id) && !empty($display_name)) {
                    $customers[] = array(
                        'id' => $customer_id,
                        'name' => $display_name
                    );
                }
            }
        }
        
        // Sort customers by name
        usort($customers, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        return $customers;
    }
    
    /**
     * Get team history for a student
     */
    public function get_student_team_history($student_id) {
        global $wpdb;
        
        $table_student_team_history = $wpdb->prefix . 'gears_student_team_history';
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        $history = $wpdb->get_results($wpdb->prepare("
            SELECT 
                sth.id,
                sth.student_id,
                sth.team_id,
                sth.start_date,
                sth.end_date,
                sth.season,
                sth.program,
                sth.is_current,
                sth.reason_for_change,
                sth.created_at,
                t.team_name
            FROM $table_student_team_history sth
            LEFT JOIN $table_teams t ON sth.team_id = t.id
            WHERE sth.student_id = %d
            ORDER BY sth.start_date DESC, sth.created_at DESC
        ", $student_id));
        
        return $history;
    }
    
    /**
     * Render page HTML
     */
    private function render_page_html($students, $teams, $customers) {
        ?>
        <div class="wrap">
            <h1>Students Management</h1>
            
            <!-- Add New Student Button -->
            <button id="open-student-modal" class="button button-primary" style="margin-bottom:20px;">Add New Student</button>

            <!-- Modal for Add Student -->
            <div id="student-modal-overlay" class="gears-modal-overlay">
                <div class="gears-modal">
                    <span class="gears-modal-close" id="close-student-modal">&times;</span>
                    <div class="team-form-card" style="box-shadow:none; border:none; max-width:100%; margin:0; padding:0;">
                        <h2 style="border:none; padding-bottom:0; margin-top:0;">Add New Student</h2>
                        <form method="post" enctype="multipart/form-data" id="add-student-form">
                            <?php wp_nonce_field('add_student_action', 'student_nonce'); ?>
                            <table class="form-table">
                                <tr><th><label for="first_name">First Name *</label></th><td><input type="text" id="first_name" name="first_name" required class="regular-text" /></td></tr>
                                <tr><th><label for="last_name">Last Name *</label></th><td><input type="text" id="last_name" name="last_name" required class="regular-text" /></td></tr>
                                <tr><th><label for="grade">Grade Level</label></th><td>
                                    <select id="grade" name="grade" class="regular-text">
                                        <option value="">Select grade...</option>
                                        <option value="K">Kindergarten</option>
                                        <option value="1">1st Grade</option>
                                        <option value="2">2nd Grade</option>
                                        <option value="3">3rd Grade</option>
                                        <option value="4">4th Grade</option>
                                        <option value="5">5th Grade</option>
                                        <option value="6">6th Grade</option>
                                        <option value="7">7th Grade</option>
                                        <option value="8">8th Grade</option>
                                        <option value="9">9th Grade</option>
                                        <option value="10">10th Grade</option>
                                        <option value="11">11th Grade</option>
                                        <option value="12">12th Grade</option>
                                        <option value="Alumni">Alumni</option>
                                    </select>
                                </td></tr>
                                <tr><th><label for="team_id">Team Affiliation</label></th><td>
                                    <select name="team_id" id="team_id">
                                        <option value="0">No Team</option>
                                        <?php foreach ($teams as $team): ?>
                                            <option value="<?php echo esc_attr($team->id); ?>">
                                                <?php echo esc_html($team->team_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td></tr>
                                <tr><th><label for="first_year_first">First Year in FIRST</label></th><td><input type="text" id="first_year_first" name="first_year_first" class="regular-text" /></td></tr>
                                <tr><th><label for="customer_id">Customer Affiliation</label></th><td>
                                    <select name="customer_id" id="customer_id">
                                        <option value="">No Customer</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo esc_attr($customer['id']); ?>">
                                                <?php echo esc_html($customer['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td></tr>
                            </table>
                            <p class="submit"><input type="submit" name="add_student" class="button-primary" value="Add Student" /></p>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Students List -->
            <div class="card" style="max-width: none; margin-top: 20px;">
                <h2>Current Students</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col">First Name</th>
                            <th scope="col">Last Name</th>
                            <th scope="col">Grade</th>
                            <th scope="col">Team</th>
                            <th scope="col">Customer</th>
                            <th scope="col">First Year in FIRST</th>
                            <th scope="col" style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="7">No students found. Add your first student above!</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo esc_html($student->first_name); ?></td>
                                    <td><?php echo esc_html($student->last_name); ?></td>
                                    <td>
                                        <?php 
                                        if ($student->grade) {
                                            if (strtolower($student->grade) === 'alumni') {
                                                echo esc_html($student->grade);
                                            } else {
                                                echo esc_html($student->grade) . 'th Grade';
                                            }
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo esc_html($student->team_name ?: 'No Team'); ?></td>
                                    <td>
                                        <?php echo esc_html($student->customer_name ?: 'No Customer'); ?>
                                        <?php if ($student->is_multiple_students): ?>
                                            <span class="customer-student-count" style="color: #666; fonize: 0.9em;">(<?php echo $student->customer_student_count; ?> students)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($student->first_year_first); ?></td>
                                    <td>
                                        <button type="button" class="button edit-student-btn" 
                                                data-student-id="<?php echo esc_attr($student->id); ?>"
                                                data-first-name="<?php echo esc_attr($student->first_name); ?>"
                                                data-last-name="<?php echo esc_attr($student->last_name); ?>"
                                                data-grade="<?php echo esc_attr($student->grade); ?>"
                                                data-team-id="<?php echo esc_attr($student->team_id); ?>"
                                                data-customer-id="<?php echo esc_attr($student->customer_id); ?>"
                                                data-first-year="<?php echo esc_attr($student->first_year_first); ?>">
                                            Edit
                                        </button>
                                        <form method="post" style="display: inline;">
                                            <?php wp_nonce_field('delete_student_action', 'delete_nonce'); ?>
                                            <input type="hidden" name="student_id" value="<?php echo esc_attr($student->id); ?>" />
                                            <input type="submit" name="delete_student" value="Delete" class="button button-secondary" 
                                                   onclick="return confirm('Are you sure you want to delete this student?')" />
                                        </form>
                                        <?php if (!empty($student->customer_id)): ?>
                                            <button type="button" class="button button-small view-customer-students" 
                                                    data-customer-id="<?php echo esc_attr($student->customer_id); ?>"
                                                    data-customer-name="<?php echo esc_attr($student->customer_name); ?>"
                                                    title="View all students for this customer">
                                                <span class="dashicons dashicons-groups"></span>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Edit Student Modal -->
        <div id="edit-student-modal" style="display: none;">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2>Edit Student</h2>
                <form method="post" id="edit-student-form">
                    <?php wp_nonce_field('update_student_action', 'student_edit_nonce'); ?>
                    <input type="hidden" name="student_id" id="edit-student-id" />
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="edit-first-name">First Name</label></th>
                            <td><input name="first_name" type="text" id="edit-first-name" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit-last-name">Last Name</label></th>
                            <td><input name="last_name" type="text" id="edit-last-name" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit-grade">Grade</label></th>
                            <td>
                                <select name="grade" id="edit-grade" required>
                                    <option value="">Select Grade</option>
                                    <option value="K">Kindergarten</option>
                                    <option value="1">1st Grade</option>
                                    <option value="2">2nd Grade</option>
                                    <option value="3">3rd Grade</option>
                                    <option value="4">4th Grade</option>
                                    <option value="5">5th Grade</option>
                                    <option value="6">6th Grade</option>
                                    <option value="7">7th Grade</option>
                                    <option value="8">8th Grade</option>
                                    <option value="9">9th Grade</option>
                                    <option value="10">10th Grade</option>
                                    <option value="11">11th Grade</option>
                                    <option value="12">12th Grade</option>
                                    <option value="Alumni">Alumni</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit-team-id">Team Affiliation (optional)</label></th>
                            <td>
                                <select name="team_id" id="edit-team-id">
                                    <option value="0">No Team</option>
                                    <?php foreach ($teams as $team): ?>
                                        <option value="<?php echo esc_attr($team->id); ?>">
                                            <?php echo esc_html($team->team_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit-customer-id">Customer Affiliation</label></th>
                            <td>
                                <select name="customer_id" id="edit-customer-id">
                                    <option value="">No Customer</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo esc_attr($customer['id']); ?>">
                                            <?php echo esc_html($customer['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit-first-year">First Year in FIRST (optional)</label></th>
                            <td>
                                <input name="first_year" type="number" id="edit-first-year" class="regular-text" 
                                       min="2000" max="<?php echo date('Y'); ?>" />
                            </td>
                        </tr>
                    </table>
                    
                    <!-- Team History Section -->
                    <div id="team-history-section" style="margin-top: 30px;">
                        <h3 style="border-bottom: 1px solid #ddd; padding-bottom: 10px;">Team History</h3>
                        
                        <!-- Add Former Team Form -->
                        <div style="background: #f9f9f9; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                            <h4 style="margin-top: 0;">Add Former Team</h4>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="history-team-id">Team <span style="color: red;">*</span></label></th>
                                    <td>
                                        <select id="history-team-id" class="regular-text">
                                            <option value="">Select Team</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?php echo esc_attr($team->id); ?>">
                                                    <?php echo esc_html($team->team_name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="history-reason">Reason <span style="color: red;">*</span></label></th>
                                    <td><input type="text" id="history-reason" class="regular-text" placeholder="e.g., Team transfer, Graduation" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="history-start-date">Start Date</label></th>
                                    <td><input type="date" id="history-start-date" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="history-end-date">End Date</label></th>
                                    <td><input type="date" id="history-end-date" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="history-season">Season</label></th>
                                    <td><input type="text" id="history-season" class="regular-text" placeholder="e.g., 2023-2024" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="history-program">Program</label></th>
                                    <td>
                                        <select id="history-program" class="regular-text">
                                            <option value="">Select Program</option>
                                            <option value="FLL">FLL (First LEGO League)</option>
                                            <option value="FTC">FTC (First Tech Challenge)</option>
                                            <option value="Alumni">Alumni</option>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                            <button type="button" id="add-team-history" class="button button-secondary" onclick="addTeamHistoryInline('');">Add Former Team</button>
                            <button type="button" id="debug-team-history" class="button button-link" style="margin-left: 10px;" onclick="console.log('Debug inline click!'); alert('Debug inline click!');">Debug Click</button>
                        </div>
                        
                        <!-- Current Team History Display -->
                        <div id="team-history-list">
                            <h4>Team History</h4>
                            <div id="team-history-items">
                                <!-- Team history will be loaded here via AJAX -->
                            </div>
                        </div>
                        
                        <script>
                        console.log('Modal script loaded - first modal - functions are global now');
                        </script>
                    </div>
                    
                    <?php submit_button('Update Student', 'primary', 'update_student'); ?>
                </form>
            </div>
        </div>
        
        <!-- Modal for View Customer Students -->
        <div id="customer-students-modal" style="display: none;">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2 id="customer-students-title">Students for Customer</h2>
                <div id="customer-students-list">Loading...</div>
            </div>
        </div>
        
        <style>
        /* Modal styles */
        #edit-student-modal,
        #customer-students-modal {
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            position: relative;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            right: 15px;
            top: 10px;
        }
        
        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .gears-modal-overlay {
            display: none;
            position: fixed;
            z-index: 99999;
            left: 0; top: 0; width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.4);
        }
        .gears-modal {
            background: #fff;
            border-radius: 8px;
            max-width: 600px;
            margin: 5% auto;
            padding: 30px 20px 20px 20px;
            position: relative;
            box-shadow: 0 4px 32px rgba(0,0,0,0.18);
            max-height: 80vh;
            overflow-y: auto;
        }
        .gears-modal-close {
            position: absolute;
            top: 10px; right: 18px;
            font-size: 28px;
            color: #888;
            cursor: pointer;
            font-weight: bold;
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
        </style>
        
        <script>
        // Global functions defined at page load to ensure they're always available
        console.log('Global script is loading...');
        
        window.addTeamHistoryInline = function(prefix) {
            console.log('addTeamHistoryInline called with prefix:', prefix);
            
            var studentId = document.getElementById('edit-student-id').value;
            var teamId = document.getElementById('history-team-id' + prefix).value;
            var startDate = document.getElementById('history-start-date' + prefix).value;
            var endDate = document.getElementById('history-end-date' + prefix).value;
            var season = document.getElementById('history-season' + prefix).value;
            var program = document.getElementById('history-program' + prefix).value;
            var reason = document.getElementById('history-reason' + prefix).value;
            
            console.log('Form values:', {studentId: studentId, teamId: teamId, reason: reason});
            
            // Only require team and reason
            if (!teamId || !reason) {
                alert('Please fill in the required fields: Team and Reason.');
                return;
            }
            
            // Use jQuery for AJAX if available, otherwise use vanilla JS
            if (typeof jQuery !== 'undefined') {
                jQuery.post(ajaxurl, {
                    action: 'qbo_add_team_history',
                    student_id: studentId,
                    team_id: teamId,
                    start_date: startDate,
                    end_date: endDate,
                    season: season,
                    program: program,
                    reason: reason,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        // Clear the form
                        document.getElementById('history-team-id' + prefix).value = '';
                        document.getElementById('history-start-date' + prefix).value = '';
                        document.getElementById('history-end-date' + prefix).value = '';
                        document.getElementById('history-season' + prefix).value = '';
                        document.getElementById('history-program' + prefix).value = '';
                        document.getElementById('history-reason' + prefix).value = '';
                        // Reload team history
                        if (typeof window.loadTeamHistoryInline === 'function') {
                            window.loadTeamHistoryInline(studentId);
                        }
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            } else {
                alert('jQuery not available for AJAX request');
            }
        };
        
        console.log('addTeamHistoryInline function defined');
        
        window.loadTeamHistoryInline = function(studentId) {
            if (typeof jQuery !== 'undefined') {
                jQuery.post(ajaxurl, {
                    action: 'qbo_get_team_history',
                    student_id: studentId,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success && response.data.history) {
                        var historyHtml = '<h4>Team History:</h4><ul>';
                        response.data.history.forEach(function(entry) {
                            historyHtml += '<li>';
                            historyHtml += '<strong>Team:</strong> ' + entry.team_name + ' ';
                            if (entry.start_date) historyHtml += '<strong>Start:</strong> ' + entry.start_date + ' ';
                            if (entry.end_date) historyHtml += '<strong>End:</strong> ' + entry.end_date + ' ';
                            if (entry.reason) historyHtml += '<strong>Reason:</strong> ' + entry.reason + ' ';
                            historyHtml += '<button type="button" onclick="deleteTeamHistoryInline(' + entry.id + ', ' + studentId + ')" class="button button-small">Delete</button>';
                            historyHtml += '</li>';
                        });
                        historyHtml += '</ul>';
                        
                        // Update both modals
                        var historyContainer = document.getElementById('team-history-display');
                        if (historyContainer) historyContainer.innerHTML = historyHtml;
                        
                        var historyContainer2 = document.getElementById('team-history-display-2');
                        if (historyContainer2) historyContainer2.innerHTML = historyHtml;
                    } else {
                        console.log('No team history found or error loading');
                    }
                }).fail(function() {
                    console.log('AJAX request failed for loading team history');
                });
            } else {
                console.log('jQuery not available for loading team history');
            }
        };
        
        window.deleteTeamHistoryInline = function(historyId, studentId) {
            if (!confirm('Are you sure you want to delete this team history entry?')) {
                return;
            }
            
            if (typeof jQuery !== 'undefined') {
                jQuery.post(ajaxurl, {
                    action: 'qbo_delete_team_history',
                    history_id: historyId,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        window.loadTeamHistoryInline(studentId);
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            }
        };

        console.log('All global functions defined. Testing window.addTeamHistoryInline:', typeof window.addTeamHistoryInline);
        console.log('Global functions setup complete.');
        </script>
        
        <?php echo '<script>console.log("About to start global script section...");</script>'; ?>
        
        <script>
        // Global functions defined at page load to ensure they're always available
        console.log('Global script is loading...');
        
        window.addTeamHistoryInline = function(prefix) {
            console.log('addTeamHistoryInline called with prefix:', prefix);
            
            var studentId = document.getElementById('edit-student-id').value;
            var teamId = document.getElementById('history-team-id' + prefix).value;
            var startDate = document.getElementById('history-start-date' + prefix).value;
            var endDate = document.getElementById('history-end-date' + prefix).value;
            var season = document.getElementById('history-season' + prefix).value;
            var program = document.getElementById('history-program' + prefix).value;
            var reason = document.getElementById('history-reason' + prefix).value;
            
            console.log('Form values:', {studentId: studentId, teamId: teamId, reason: reason});
            
            // Only require team and reason
            if (!teamId || !reason) {
                alert('Please fill in the required fields: Team and Reason.');
                return;
            }
            
            // Use jQuery for AJAX if available, otherwise use vanilla JS
            if (typeof jQuery !== 'undefined') {
                jQuery.post(ajaxurl, {
                    action: 'qbo_add_team_history',
                    student_id: studentId,
                    team_id: teamId,
                    start_date: startDate,
                    end_date: endDate,
                    season: season,
                    program: program,
                    reason: reason,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        // Clear the form
                        document.getElementById('history-team-id' + prefix).value = '';
                        document.getElementById('history-start-date' + prefix).value = '';
                        document.getElementById('history-end-date' + prefix).value = '';
                        document.getElementById('history-season' + prefix).value = '';
                        document.getElementById('history-program' + prefix).value = '';
                        document.getElementById('history-reason' + prefix).value = '';
                        // Reload team history
                        if (typeof window.loadTeamHistoryInline === 'function') {
                            window.loadTeamHistoryInline(studentId);
                        }
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            } else {
                alert('jQuery not available for AJAX request');
            }
        };
        
        console.log('addTeamHistoryInline function defined');
        
        window.loadTeamHistoryInline = function(studentId) {
            if (typeof jQuery !== 'undefined') {
                jQuery.post(ajaxurl, {
                    action: 'qbo_get_team_history',
                    student_id: studentId,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success && response.data.history) {
                        var historyHtml = '<h4>Team History:</h4><ul>';
                        response.data.history.forEach(function(entry) {
                            historyHtml += '<li>';
                            historyHtml += '<strong>Team:</strong> ' + entry.team_name + ' ';
                            if (entry.start_date) historyHtml += '<strong>Start:</strong> ' + entry.start_date + ' ';
                            if (entry.end_date) historyHtml += '<strong>End:</strong> ' + entry.end_date + ' ';
                            if (entry.reason) historyHtml += '<strong>Reason:</strong> ' + entry.reason + ' ';
                            historyHtml += '<button type="button" onclick="deleteTeamHistoryInline(' + entry.id + ', ' + studentId + ')" class="button button-small">Delete</button>';
                            historyHtml += '</li>';
                        });
                        historyHtml += '</ul>';
                        
                        // Update both modals
                        var historyContainer = document.getElementById('team-history-display');
                        if (historyContainer) historyContainer.innerHTML = historyHtml;
                        
                        var historyContainer2 = document.getElementById('team-history-display-2');
                        if (historyContainer2) historyContainer2.innerHTML = historyHtml;
                    } else {
                        console.log('No team history found or error loading');
                    }
                }).fail(function() {
                    console.log('AJAX request failed for loading team history');
                });
            } else {
                console.log('jQuery not available for loading team history');
            }
        };
        
        window.deleteTeamHistoryInline = function(historyId, studentId) {
            if (!confirm('Are you sure you want to delete this team history entry?')) {
                return;
            }
            
            if (typeof jQuery !== 'undefined') {
                jQuery.post(ajaxurl, {
                    action: 'qbo_delete_team_history',
                    history_id: historyId,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        window.loadTeamHistoryInline(studentId);
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            }
        };

        console.log('All global functions defined. Testing window.addTeamHistoryInline:', typeof window.addTeamHistoryInline);
        console.log('Global functions setup complete.');

        jQuery(document).ready(function($) {
            // Edit student button click
            $('.edit-student-btn').on('click', function() {
                var studentId = $(this).data('student-id');
                var firstName = $(this).data('first-name');
                var lastName = $(this).data('last-name');
                var grade = $(this).data('grade');
                var teamId = $(this).data('team-id');
                var customerId = $(this).data('customer-id');
                var firstYear = $(this).data('first-year');
                
                $('#edit-student-id').val(studentId);
                $('#edit-first-name').val(firstName);
                $('#edit-last-name').val(lastName);
                $('#edit-grade').val(grade);
                $('#edit-team-id').val(teamId);
                $('#edit-customer-id').val(customerId);
                $('#edit-first-year').val(firstYear);
                
                // Load team history for this student
                if (typeof window.loadTeamHistoryInline !== 'undefined') {
                    window.loadTeamHistoryInline(studentId);
                } else {
                    loadTeamHistory(studentId);
                }
                
                $('#edit-student-modal').show();
            });
            
            // Add team history functionality (for both modals) - using event delegation
            $(document).on('click', '#add-team-history, #add-team-history-2', function() {
                console.log('Add team history button clicked!'); // Debug line
                
                var isSecondModal = $(this).attr('id').includes('-2');
                var prefix = isSecondModal ? '-2' : '';
                
                console.log('Modal prefix:', prefix); // Debug line
                console.log('Button element:', this); // Debug line
                
                var studentId = $('#edit-student-id').val();
                var teamId = $('#history-team-id' + prefix).val();
                var startDate = $('#history-start-date' + prefix).val();
                var endDate = $('#history-end-date' + prefix).val();
                var season = $('#history-season' + prefix).val();
                var program = $('#history-program' + prefix).val();
                var reason = $('#history-reason' + prefix).val();
                
                console.log('Form values:', {studentId, teamId, reason}); // Debug line
                
                // Only require team and reason
                if (!teamId || !reason) {
                    alert('Please fill in the required fields: Team and Reason.');
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'qbo_add_team_history',
                    student_id: studentId,
                    team_id: teamId,
                    start_date: startDate,
                    end_date: endDate,
                    season: season,
                    program: program,
                    reason: reason,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        // Clear the form
                        $('#history-team-id' + prefix).val('');
                        $('#history-start-date' + prefix).val('');
                        $('#history-end-date' + prefix).val('');
                        $('#history-season' + prefix).val('');
                        $('#history-program' + prefix).val('');
                        $('#history-reason' + prefix).val('');
                        // Reload team history
                        loadTeamHistory(studentId);
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            });
            
            // Function to load team history (updated to support both modals)
            function loadTeamHistory(studentId) {
                $.post(ajaxurl, {
                    action: 'qbo_get_team_history',
                    student_id: studentId,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        var historyHtml = '';
                        if (response.data.length > 0) {
                            response.data.forEach(function(item) {
                                var endDateDisplay = item.end_date ? item.end_date : 'Present';
                                var currentBadge = item.is_current == '1' ? '<span style="background: #00a32a; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px;">CURRENT</span>' : '';
                                
                                historyHtml += '<div style="border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; border-radius: 4px;">';
                                historyHtml += '<strong>' + item.team_name + '</strong> ' + currentBadge + '<br>';
                                historyHtml += '<small>Program: ' + item.program + ' | Season: ' + item.season + '</small><br>';
                                historyHtml += '<small>Period: ' + item.start_date + ' to ' + endDateDisplay + '</small><br>';
                                if (item.reason_for_change) {
                                    historyHtml += '<small>Reason: ' + item.reason_for_change + '</small><br>';
                                }
                                if (item.is_current != '1') {
                                    historyHtml += '<button type="button" class="delete-history button-link-delete" data-history-id="' + item.id + '" style="margin-top: 5px;">Delete</button>';
                                }
                                historyHtml += '</div>';
                            });
                        } else {
                            historyHtml = '<p style="color: #666; font-style: italic;">No team history found.</p>';
                        }
                        // Update both possible history containers
                        $('#team-history-items').html(historyHtml);
                        $('#team-history-items-2').html(historyHtml);
                    }
                });
            }
            
            // Debug buttons for team history
            $(document).on('click', '#debug-team-history, #debug-team-history-2', function() {
                console.log('Debug button clicked!');
                alert('Debug: Modal content is accessible! Button ID: ' + $(this).attr('id'));
            });
            
            // Delete team history
            $(document).on('click', '.delete-history', function() {
                if (!confirm('Are you sure you want to delete this team history entry?')) {
                    return;
                }
                
                var historyId = $(this).data('history-id');
                var studentId = $('#edit-student-id').val();
                
                $.post(ajaxurl, {
                    action: 'qbo_delete_team_history',
                    history_id: historyId,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        loadTeamHistory(studentId);
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            });
            
            // Customer Students Modal
            $('.view-customer-students').on('click', function() {
                var customerId = $(this).data('customer-id');
                var customerName = $(this).data('customer-name');
                
                $('#customer-students-title').text('Students for ' + customerName);
                $('#customer-students-list').html('Loading...');
                $('#customer-students-modal').show();
                
                // Fetch students for this customer
                $.post(ajaxurl, {
                    action: 'qbo_get_customer_students',
                    customer_id: customerId,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success && response.data.length > 0) {
                        var html = '<table class="wp-list-table widefat fixed striped">';
                        html += '<thead><tr>';
                        html += '<th>First Name</th>';
                        html += '<th>Last Name</th>';
                        html += '<th>Grade</th>';
                        html += '<th>Team</th>';
                        html += '<th>First Year in FIRST</th>';
                        html += '</tr></thead>';
                        html += '<tbody>';
                        
                        response.data.forEach(function(student) {
                            html += '<tr>';
                            html += '<td>' + student.first_name + '</td>';
                            html += '<td>' + student.last_name + '</td>';
                            // Format grade display
                            var gradeDisplay = student.grade || 'N/A';
                            if (gradeDisplay && gradeDisplay !== 'N/A' && gradeDisplay.toLowerCase() !== 'alumni') {
                                gradeDisplay = gradeDisplay + 'th Grade';
                            }
                            html += '<td>' + gradeDisplay + '</td>';
                            html += '<td>' + (student.team_name || 'No Team') + '</td>';
                            html += '<td>' + student.first_year_first + '</td>';
                            html += '</tr>';
                        });
                        
                        html += '</tbody></table>';
                        $('#customer-students-list').html(html);
                    } else {
                        $('#customer-students-list').html('<p>No students found for this customer.</p>');
                    }
                }).fail(function() {
                    $('#customer-students-list').html('<p>Error loading students.</p>');
                });
            });
            
            // Close modal
            $('.close').on('click', function() {
                $('#edit-student-modal, #customer-students-modal, #student-modal-overlay').hide();
            });
            
            // Close modal when clicking outside
            $(window).on('click', function(event) {
                if (event.target.id === 'edit-student-modal' || event.target.id === 'customer-students-modal' || event.target.id === 'student-modal-overlay') {
                    $('#edit-student-modal, #customer-students-modal, #student-modal-overlay').hide();
                }
            });
            
            // Open student modal
            var openBtn = document.getElementById('open-student-modal');
            var modal = document.getElementById('student-modal-overlay');
            var closeBtn = document.getElementById('close-student-modal');
            if (openBtn && modal && closeBtn) {
                openBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    modal.style.display = 'block';
                });
                closeBtn.addEventListener('click', function() {
                    modal.style.display = 'none';
                });
                window.addEventListener('click', function(event) {
                    if (event.target === modal) {
                        modal.style.display = 'none';
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    /**
     * Render students page with WordPress list table
     */
    private function render_page_html_with_list_table($list_table, $teams, $customers, $current_tab, $fll_count, $ftc_count, $alumni_count) {
        echo '<script>console.log("Method start - render_page_html_with_list_table is executing");</script>';
        
        // Add global functions immediately at the start of the method output
        ?>
        <script>
        // Global functions for team history management - defined early in page load
        console.log('Setting up global functions in render_page_html_with_list_table...');
        
        window.addTeamHistoryInline = function(prefix) {
            console.log('addTeamHistoryInline called with prefix:', prefix);
            
            var studentId = document.getElementById('edit-student-id').value;
            var teamId = document.getElementById('history-team-id' + prefix).value;
            var startDate = document.getElementById('history-start-date' + prefix).value;
            var endDate = document.getElementById('history-end-date' + prefix).value;
            var season = document.getElementById('history-season' + prefix).value;
            var program = document.getElementById('history-program' + prefix).value;
            var reason = document.getElementById('history-reason' + prefix).value;
            
            console.log('Form values:', {studentId: studentId, teamId: teamId, reason: reason});
            
            // Only require team and reason
            if (!teamId || !reason) {
                alert('Please fill in the required fields: Team and Reason.');
                return;
            }
            
            // Use jQuery for AJAX if available, otherwise use vanilla JS
            if (typeof jQuery !== 'undefined') {
                jQuery.post(ajaxurl, {
                    action: 'qbo_add_team_history',
                    student_id: studentId,
                    team_id: teamId,
                    start_date: startDate,
                    end_date: endDate,
                    season: season,
                    program: program,
                    reason: reason,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        // Clear the form
                        document.getElementById('history-team-id' + prefix).value = '';
                        document.getElementById('history-start-date' + prefix).value = '';
                        document.getElementById('history-end-date' + prefix).value = '';
                        document.getElementById('history-season' + prefix).value = '';
                        document.getElementById('history-program' + prefix).value = '';
                        document.getElementById('history-reason' + prefix).value = '';
                        // Reload team history
                        if (typeof window.loadTeamHistoryInline === 'function') {
                            window.loadTeamHistoryInline(studentId);
                        }
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            } else {
                alert('jQuery not available for AJAX request');
            }
        };
        
        window.loadTeamHistoryInline = function(studentId) {
            console.log('loadTeamHistoryInline called with studentId:', studentId);
            if (typeof jQuery !== 'undefined') {
                jQuery.post(ajaxurl, {
                    action: 'qbo_get_team_history',
                    student_id: studentId,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    console.log('Team history response:', response);
                    if (response.success && response.data && response.data.history) {
                        console.log('Team history data:', response.data.history);
                        var historyHtml = '<h4>Team History:</h4><ul>';
                        response.data.history.forEach(function(entry) {
                            historyHtml += '<li>';
                            historyHtml += '<strong>Team:</strong> ' + (entry.team_name || 'Unknown Team') + ' ';
                            if (entry.start_date) historyHtml += '<strong>Start:</strong> ' + entry.start_date + ' ';
                            if (entry.end_date) historyHtml += '<strong>End:</strong> ' + entry.end_date + ' ';
                            if (entry.reason_for_change) historyHtml += '<strong>Reason:</strong> ' + entry.reason_for_change + ' ';
                            historyHtml += '<button type="button" onclick="deleteTeamHistoryInline(' + entry.id + ', ' + studentId + ')" class="button button-small">Delete</button>';
                            historyHtml += '</li>';
                        });
                        historyHtml += '</ul>';
                        
                        console.log('Generated history HTML:', historyHtml);
                        
                        // Update both modals
                        var historyContainer = document.getElementById('team-history-items');
                        if (historyContainer) {
                            historyContainer.innerHTML = historyHtml;
                            console.log('Updated team-history-items container');
                        } else {
                            console.log('team-history-items container not found');
                        }
                        
                        var historyContainer2 = document.getElementById('team-history-items-2');
                        if (historyContainer2) {
                            historyContainer2.innerHTML = historyHtml;
                            console.log('Updated team-history-items-2 container');
                        } else {
                            console.log('team-history-items-2 container not found');
                        }
                    } else {
                        console.log('No team history found or error loading');
                        console.log('Response details:', response);
                        // Still update the containers to show "no history"
                        var noHistoryHtml = '<h4>Team History:</h4><p>No team history found.</p>';
                        var historyContainer = document.getElementById('team-history-items');
                        if (historyContainer) historyContainer.innerHTML = noHistoryHtml;
                        var historyContainer2 = document.getElementById('team-history-items-2');
                        if (historyContainer2) historyContainer2.innerHTML = noHistoryHtml;
                    }
                }).fail(function(xhr, status, error) {
                    console.log('AJAX request failed for loading team history');
                    console.log('Error details:', xhr, status, error);
                });
            } else {
                console.log('jQuery not available for loading team history');
            }
        };
        
        window.deleteTeamHistoryInline = function(historyId, studentId) {
            if (!confirm('Are you sure you want to delete this team history entry?')) {
                return;
            }
            
            if (typeof jQuery !== 'undefined') {
                jQuery.post(ajaxurl, {
                    action: 'qbo_delete_team_history',
                    history_id: historyId,
                    nonce: '<?php echo wp_create_nonce("qbo_ajax_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        window.loadTeamHistoryInline(studentId);
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            }
        };

        console.log('Global functions defined in render_page_html_with_list_table. Testing window.addTeamHistoryInline:', typeof window.addTeamHistoryInline);
        
        // Test function availability on page load
        setTimeout(function() {
            console.log('Testing global functions after page load...');
            console.log('window object:', window);
            console.log('window.addTeamHistoryInline:', window.addTeamHistoryInline);
            console.log('typeof window.addTeamHistoryInline:', typeof window.addTeamHistoryInline);
            
            if (typeof window.addTeamHistoryInline === 'undefined') {
                console.log('Function not found. All window properties:', Object.getOwnPropertyNames(window));
            } else {
                console.log('SUCCESS: addTeamHistoryInline function is available!');
            }
        }, 1000);
        </script>
        
        <?php
        ?>
        <style>
            .student-count {
                background: #f0f0f1;
                color: #50575e;
                border-radius: 9px;
                padding: 2px 6px;
                font-size: 11px;
                font-weight: 600;
                margin-left: 5px;
            }
            .nav-tab-active .student-count {
                background: #fff;
                color: #2271b1;
            }
            
            /* Edit Modal Styles */
            #edit-student-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 100000;
                display: none;
            }
            #edit-student-modal .modal-content {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: white;
                padding: 20px;
                border-radius: 5px;
                max-width: 500px;
                width: 90%;
                max-height: 80vh;
                overflow-y: auto;
            }
            #edit-student-modal .close {
                position: absolute;
                top: 10px;
                right: 15px;
                font-size: 24px;
                cursor: pointer;
                color: #666;
            }
            #edit-student-modal .close:hover {
                color: #000;
            }
        </style>
        
        <div class="wrap">
            <h1>Students Management</h1>
            
            <?php
            // Display success messages
            if (isset($_GET['added']) && $_GET['added'] == '1') {
                echo '<div class="notice notice-success is-dismissible"><p>Student added successfully!</p></div>';
            }
            if (isset($_GET['updated']) && $_GET['updated'] == '1') {
                echo '<div class="notice notice-success is-dismissible"><p>Student updated successfully!</p></div>';
            }
            if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
                echo '<div class="notice notice-success is-dismissible"><p>Student deleted successfully!</p></div>';
            }
            
            // Add migration trigger for team history
            if (isset($_GET['run_migration']) && $_GET['run_migration'] == '1') {
                $core = new QBO_Core();
                $result = $core->force_run_migration();
                echo '<div class="notice notice-success is-dismissible"><p>Team history migration completed!</p></div>';
            }
            
            // Show migration button if team history table doesn't exist
            global $wpdb;
            $table_name = $wpdb->prefix . 'gears_student_team_history';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
            if (!$table_exists) {
                echo '<div class="notice notice-warning"><p>Team history feature requires database migration. <a href="' . admin_url('admin.php?page=qbo-students&run_migration=1') . '" class="button button-primary">Run Migration</a></p></div>';
            }
            
            // Debug: Add test button to verify JavaScript is working
            echo '<button type="button" id="test-js-button" class="button button-secondary" style="margin: 10px 0;">Test JavaScript</button>';
            echo '<script>
            jQuery(document).ready(function($) {
                $("#test-js-button").on("click", function() {
                    console.log("Testing global functions...");
                    console.log("window object:", window);
                    console.log("window.addTeamHistoryInline:", window.addTeamHistoryInline);
                    console.log("typeof window.addTeamHistoryInline:", typeof window.addTeamHistoryInline);
                    
                    if (typeof window.addTeamHistoryInline === "function") {
                        alert("addTeamHistoryInline is available globally!");
                    } else {
                        alert("addTeamHistoryInline is NOT available globally!");
                        console.error("Function not found. All window properties:", Object.keys(window));
                    }
                    console.log("Test button clicked - JavaScript is working!");
                });
            });
            </script>';
            ?>
            
            <!-- Tab Navigation -->
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo admin_url('admin.php?page=qbo-students&tab=fll'); ?>" 
                   class="nav-tab <?php echo $current_tab === 'fll' ? 'nav-tab-active' : ''; ?>">
                    FLL (K-8th Grade)
                    <span class="student-count"><?php echo $fll_count; ?></span>
                </a>
                <a href="<?php echo admin_url('admin.php?page=qbo-students&tab=ftc'); ?>" 
                   class="nav-tab <?php echo $current_tab === 'ftc' ? 'nav-tab-active' : ''; ?>">
                    FTC (9th-12th Grade)
                    <span class="student-count"><?php echo $ftc_count; ?></span>
                </a>
                <a href="<?php echo admin_url('admin.php?page=qbo-students&tab=alumni'); ?>" 
                   class="nav-tab <?php echo $current_tab === 'alumni' ? 'nav-tab-active' : ''; ?>">
                    Alumni
                    <span class="student-count"><?php echo $alumni_count; ?></span>
                </a>
            </h2>
            
            <!-- Add New Student Button -->
            <button id="open-student-modal" class="button button-primary" style="margin: 20px 0;">Add New Student</button>

            <!-- Modal for Add Student -->
            <div id="student-modal-overlay" class="gears-modal-overlay">
                <div class="gears-modal">
                    <span class="gears-modal-close" id="close-student-modal">&times;</span>
                    <div class="team-form-card" style="box-shadow:none; border:none; max-width:100%; margin:0; padding:0;">
                        <h2 style="border:none; padding-bottom:0; margin-top:0;">Add New Student</h2>
                        <form method="post" enctype="multipart/form-data" id="add-student-form">
                            <?php wp_nonce_field('add_student_action', 'student_nonce'); ?>
                            <table class="form-table">
                                <tr><th><label for="first_name">First Name *</label></th><td><input type="text" id="first_name" name="first_name" required class="regular-text" /></td></tr>
                                <tr><th><label for="last_name">Last Name *</label></th><td><input type="text" id="last_name" name="last_name" required class="regular-text" /></td></tr>
                                <tr><th><label for="grade">Grade Level</label></th><td>
                                    <select id="grade" name="grade" class="regular-text">
                                        <option value="">Select grade...</option>
                                        <option value="K">Kindergarten</option>
                                        <option value="1">1st Grade</option>
                                        <option value="2">2nd Grade</option>
                                        <option value="3">3rd Grade</option>
                                        <option value="4">4th Grade</option>
                                        <option value="5">5th Grade</option>
                                        <option value="6">6th Grade</option>
                                        <option value="7">7th Grade</option>
                                        <option value="8">8th Grade</option>
                                        <option value="9">9th Grade</option>
                                        <option value="10">10th Grade</option>
                                        <option value="11">11th Grade</option>
                                        <option value="12">12th Grade</option>
                                        <option value="Alumni">Alumni</option>
                                    </select>
                                </td></tr>
                                <tr><th><label for="team_id">Team Affiliation</label></th><td>
                                    <select name="team_id" id="team_id">
                                        <option value="0">No Team</option>
                                        <?php foreach ($teams as $team): ?>
                                            <option value="<?php echo esc_attr($team->id); ?>">
                                                <?php echo esc_html($team->team_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td></tr>
                                <tr><th><label for="tshirt_size">T-Shirt Size</label></th><td>
                                    <select name="tshirt_size" id="tshirt_size" class="regular-text">
                                        <option value="">Select size...</option>
                                        <option value="YS">Youth Small</option>
                                        <option value="YM">Youth Medium</option>
                                        <option value="YL">Youth Large</option>
                                        <option value="YXL">Youth XL</option>
                                        <option value="AS">Adult Small</option>
                                        <option value="AM">Adult Medium</option>
                                        <option value="AL">Adult Large</option>
                                        <option value="AXL">Adult XL</option>
                                        <option value="A2XL">Adult 2XL</option>
                                        <option value="A3XL">Adult 3XL</option>
                                        <option value="A4XL">Adult 4XL</option>
                                        <option value="A5XL">Adult 5XL</option>
                                        <option value="A6XL">Adult 6XL</option>
                                    </select>
                                </td></tr>
                                <tr><th><label for="customer_id">Customer Affiliation</label></th><td>
                                    <select name="customer_id" id="customer_id">
                                        <option value="">No Customer</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo esc_attr($customer['id']); ?>">
                                                <?php echo esc_html($customer['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td></tr>
                            </table>
                            <p class="submit"><input type="submit" name="add_student" class="button-primary" value="Add Student" /></p>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- WordPress List Table -->
            <form method="get" id="students-filter">
                <input type="hidden" name="page" value="<?php echo $_REQUEST['page']; ?>" />
                <input type="hidden" name="tab" value="<?php echo esc_attr($current_tab); ?>" />
                <?php 
                $list_table->search_box('Search Students', 'student_search');
                $list_table->display(); 
                ?>
            </form>
        </div>
        
        <!-- Edit Student Modal -->
        <div id="edit-student-modal" style="display: none;">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2>Edit Student</h2>
                <form method="post" id="edit-student-form">
                    <?php wp_nonce_field('update_student_action', 'student_edit_nonce'); ?>
                    <input type="hidden" name="student_id" id="edit-student-id" />
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="edit-first-name">First Name</label></th>
                            <td><input type="text" name="first_name" id="edit-first-name" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit-last-name">Last Name</label></th>
                            <td><input type="text" name="last_name" id="edit-last-name" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit-grade">Grade</label></th>
                            <td>
                                <select name="grade" id="edit-grade" class="regular-text">
                                    <option value="">Select grade...</option>
                                    <option value="K">Kindergarten</option>
                                    <option value="1">1st Grade</option>
                                    <option value="2">2nd Grade</option>
                                    <option value="3">3rd Grade</option>
                                    <option value="4">4th Grade</option>
                                    <option value="5">5th Grade</option>
                                    <option value="6">6th Grade</option>
                                    <option value="7">7th Grade</option>
                                    <option value="8">8th Grade</option>
                                    <option value="9">9th Grade</option>
                                    <option value="10">10th Grade</option>
                                    <option value="11">11th Grade</option>
                                    <option value="12">12th Grade</option>
                                    <option value="Alumni">Alumni</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit-team-id">Team</label></th>
                            <td>
                                <select name="team_id" id="edit-team-id">
                                    <option value="0">No Team</option>
                                    <?php foreach ($teams as $team): ?>
                                        <option value="<?php echo esc_attr($team->id); ?>">
                                            <?php echo esc_html($team->team_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit-tshirt-size">T-Shirt Size</label></th>
                            <td>
                                <select name="tshirt_size" id="edit-tshirt-size" class="regular-text">
                                    <option value="">Select size...</option>
                                    <option value="YS">Youth Small</option>
                                    <option value="YM">Youth Medium</option>
                                    <option value="YL">Youth Large</option>
                                    <option value="YXL">Youth XL</option>
                                    <option value="AS">Adult Small</option>
                                    <option value="AM">Adult Medium</option>
                                    <option value="AL">Adult Large</option>
                                    <option value="AXL">Adult XL</option>
                                    <option value="A2XL">Adult 2XL</option>
                                    <option value="A3XL">Adult 3XL</option>
                                    <option value="A4XL">Adult 4XL</option>
                                    <option value="A5XL">Adult 5XL</option>
                                    <option value="A6XL">Adult 6XL</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit-customer-id">Customer</label></th>
                            <td>
                                <select name="customer_id" id="edit-customer-id">
                                    <option value="">No Customer</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo esc_attr($customer['id']); ?>">
                                            <?php echo esc_html($customer['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- Team History Section -->
                    <div id="team-history-section-2" style="margin-top: 30px;">
                        <h3 style="border-bottom: 1px solid #ddd; padding-bottom: 10px;">Team History</h3>
                        
                        <!-- Add Former Team Form -->
                        <div style="background: #f9f9f9; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                            <h4 style="margin-top: 0;">Add Former Team</h4>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="history-team-id-2">Team <span style="color: red;">*</span></label></th>
                                    <td>
                                        <select id="history-team-id-2" class="regular-text">
                                            <option value="">Select Team</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?php echo esc_attr($team->id); ?>">
                                                    <?php echo esc_html($team->team_name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="history-reason-2">Reason <span style="color: red;">*</span></label></th>
                                    <td><input type="text" id="history-reason-2" class="regular-text" placeholder="e.g., Team transfer, Graduation" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="history-start-date-2">Start Date</label></th>
                                    <td><input type="date" id="history-start-date-2" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="history-end-date-2">End Date</label></th>
                                    <td><input type="date" id="history-end-date-2" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="history-season-2">Season</label></th>
                                    <td><input type="text" id="history-season-2" class="regular-text" placeholder="e.g., 2023-2024" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="history-program-2">Program</label></th>
                                    <td>
                                        <select id="history-program-2" class="regular-text">
                                            <option value="">Select Program</option>
                                            <option value="FLL">FLL (First LEGO League)</option>
                                            <option value="FTC">FTC (First Tech Challenge)</option>
                                            <option value="Alumni">Alumni</option>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                            <button type="button" id="add-team-history-2" class="button button-secondary" onclick="addTeamHistoryInline('-2');">Add Former Team</button>
                            <button type="button" id="debug-team-history-2" class="button button-link" style="margin-left: 10px;" onclick="console.log('Debug inline click 2!'); alert('Debug inline click 2!');">Debug Click</button>
                        </div>
                        
                        <!-- Current Team History Display -->
                        <div id="team-history-list-2">
                            <h4>Team History</h4>
                            <div id="team-history-items-2">
                                <!-- Team history will be loaded here via AJAX -->
                            </div>
                        </div>
                    </div>
                    
                    <p class="submit">
                        <input type="submit" name="update_student" class="button-primary" value="Update Student" />
                        <input type="button" class="button" value="Cancel" onclick="$('#edit-student-modal').hide();" />
                    </p>
                </form>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Edit student button functionality - use event delegation for dynamically generated content
            $(document).on('click', '.edit-student-btn', function() {
                var studentId = $(this).data('student-id');
                var firstName = $(this).data('first-name');
                var lastName = $(this).data('last-name');
                var grade = $(this).data('grade');
                var teamId = $(this).data('team-id');
                var customerId = $(this).data('customer-id');
                var tshirtSize = $(this).data('tshirt-size');
                
                $('#edit-student-id').val(studentId);
                $('#edit-first-name').val(firstName);
                $('#edit-last-name').val(lastName);
                $('#edit-grade').val(grade);
                $('#edit-team-id').val(teamId);
                $('#edit-customer-id').val(customerId);
                $('#edit-tshirt-size').val(tshirtSize);
                
                // Load team history for this student
                if (typeof window.loadTeamHistoryInline === 'function') {
                    window.loadTeamHistoryInline(studentId);
                } else {
                    console.log('loadTeamHistoryInline function not available');
                }
                
                $('#edit-student-modal').show();
            });
            
            // Close modal functionality
            $('.close').on('click', function() {
                $('#edit-student-modal').hide();
            });
            
            $(window).on('click', function(event) {
                if (event.target.id === 'edit-student-modal' || event.target.id === 'student-modal-overlay') {
                    $('#edit-student-modal, #student-modal-overlay').hide();
                }
            });
            
            // Open student modal
            var openBtn = document.getElementById('open-student-modal');
            var modal = document.getElementById('student-modal-overlay');
            var closeBtn = document.getElementById('close-student-modal');
            if (openBtn && modal && closeBtn) {
                openBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    modal.style.display = 'block';
                });
                closeBtn.addEventListener('click', function() {
                    modal.style.display = 'none';
                });
                window.addEventListener('click', function(event) {
                    if (event.target === modal) {
                        modal.style.display = 'none';
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    
    /**
     * AJAX handler to add student
     */
    public function ajax_add_student() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        global $wpdb;
        $table_students = $wpdb->prefix . 'gears_students';
        
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $grade = sanitize_text_field($_POST['grade']);
        $team_id = intval($_POST['team_id']);
        $customer_id = sanitize_text_field($_POST['customer_id']);
        $first_year = intval($_POST['first_year']);
        
        $result = $wpdb->insert(
            $table_students,
            array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'grade' => $grade,
                'team_id' => $team_id,
                'customer_id' => $customer_id,
                'first_year_first' => $first_year,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%d', '%s', '%d', '%s')
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Student added successfully!'));
        } else {
            wp_send_json_error(array('message' => 'Error adding student: ' . $wpdb->last_error));
        }
    }
    
    /**
     * AJAX handler to edit student
     */
    public function ajax_edit_student() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        global $wpdb;
        $table_students = $wpdb->prefix . 'gears_students';
        
        $student_id = intval($_POST['student_id']);
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $grade = sanitize_text_field($_POST['grade']);
        $team_id = intval($_POST['team_id']);
        $customer_id = sanitize_text_field($_POST['customer_id']);
        $first_year = intval($_POST['first_year']);
        
        $result = $wpdb->update(
            $table_students,
            array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'grade' => $grade,
                'team_id' => $team_id,
                'customer_id' => $customer_id,
                'first_year_first' => $first_year
            ),
            array('id' => $student_id),
            array('%s', '%s', '%s', '%d', '%s', '%d'),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Student updated successfully!'));
        } else {
            wp_send_json_error(array('message' => 'Error updating student: ' . $wpdb->last_error));
        }
    }
    
    /**
     * AJAX handler to delete student
     */
    public function ajax_delete_student() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        global $wpdb;
        $table_students = $wpdb->prefix . 'gears_students';
        
        $student_id = intval($_POST['student_id']);
        
        $result = $wpdb->delete(
            $table_students,
            array('id' => $student_id),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Student deleted successfully!'));
        } else {
            wp_send_json_error(array('message' => 'Error deleting student: ' . $wpdb->last_error));
        }
    }
    
    /**
     * AJAX handler to get students (for team modal)
     */
    public function ajax_get_students() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        global $wpdb;
        $table_students = $wpdb->prefix . 'gears_students';
        
        $team_id = intval($_POST['team_id']);
        
        $students = $wpdb->get_results($wpdb->prepare("
            SELECT first_name, last_name, grade, first_year_first 
            FROM $table_students 
            WHERE team_id = %d 
            ORDER BY last_name, first_name
        ", $team_id));
        
        wp_send_json_success($students);
    }

    /**
     * AJAX handler to get students for a specific customer
     */
    public function ajax_get_customer_students() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        global $wpdb;
        $table_students = $wpdb->prefix . 'gears_students';
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        $customer_id = sanitize_text_field($_POST['customer_id']);
        
        $students = $wpdb->get_results($wpdb->prepare("
            SELECT s.first_name, s.last_name, s.grade, s.first_year_first, t.team_name
            FROM $table_students s
            LEFT JOIN $table_teams t ON s.team_id = t.id
            WHERE s.customer_id = %s 
            ORDER BY s.last_name, s.first_name
        ", $customer_id));
        
        wp_send_json_success($students);
    }
    
    /**
     * AJAX handler to get team history for a student
     */
    public function ajax_get_team_history() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        $student_id = intval($_POST['student_id']);
        
        if (!$student_id) {
            wp_send_json_error(array('message' => 'Invalid student ID.'));
            return;
        }
        
        $history = $this->get_student_team_history($student_id);
        wp_send_json_success(array('history' => $history));
    }
    
    /**
     * AJAX handler to add team history entry
     */
    public function ajax_add_team_history() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        global $wpdb;
        $table_student_team_history = $wpdb->prefix . 'gears_student_team_history';
        
        $student_id = intval($_POST['student_id']);
        $team_id = intval($_POST['team_id']);
        $start_date = !empty($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : current_time('Y-m-d');
        $end_date = !empty($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : null;
        $season = !empty($_POST['season']) ? sanitize_text_field($_POST['season']) : null;
        $program = !empty($_POST['program']) ? sanitize_text_field($_POST['program']) : null;
        $reason = sanitize_text_field($_POST['reason']);
        
        // Validate required fields - only team and reason are required
        if (!$student_id || !$team_id || !$reason) {
            wp_send_json_error(array('message' => 'Team and reason are required.'));
            return;
        }
        
        // Insert the history entry
        $result = $wpdb->insert(
            $table_student_team_history,
            array(
                'student_id' => $student_id,
                'team_id' => $team_id,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'season' => $season,
                'program' => $program,
                'is_current' => 0, // Former teams are not current
                'reason_for_change' => $reason,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Team history added successfully!'));
        } else {
            wp_send_json_error(array('message' => 'Error adding team history: ' . $wpdb->last_error));
        }
    }
    
    /**
     * AJAX handler to delete team history entry
     */
    public function ajax_delete_team_history() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        global $wpdb;
        $table_student_team_history = $wpdb->prefix . 'gears_student_team_history';
        
        $history_id = intval($_POST['history_id']);
        
        if (!$history_id) {
            wp_send_json_error(array('message' => 'Invalid history ID.'));
            return;
        }
        
        // Don't allow deletion of current team assignments
        $history_entry = $wpdb->get_row($wpdb->prepare(
            "SELECT is_current FROM $table_student_team_history WHERE id = %d",
            $history_id
        ));
        
        if ($history_entry && $history_entry->is_current) {
            wp_send_json_error(array('message' => 'Cannot delete current team assignment. Use the edit student form to change teams.'));
            return;
        }
        
        $result = $wpdb->delete(
            $table_student_team_history,
            array('id' => $history_id),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Team history deleted successfully!'));
        } else {
            wp_send_json_error(array('message' => 'Error deleting team history: ' . $wpdb->last_error));
        }
    }
    
    /**
     * AJAX handler to retire a student
     */
    public function ajax_retire_student() {
        check_ajax_referer('qbo_ajax_nonce', 'nonce');
        
        global $wpdb;
        $table_students = $wpdb->prefix . 'gears_students';
        $table_student_team_history = $wpdb->prefix . 'gears_student_team_history';
        
        $student_id = intval($_POST['student_id']);
        
        if (!$student_id) {
            wp_send_json_error(array('message' => 'Invalid student ID.'));
            return;
        }
        
        // Get current student data
        $student = $wpdb->get_row($wpdb->prepare(
            "SELECT id, first_name, last_name, grade, team_id FROM $table_students WHERE id = %d",
            $student_id
        ));
        
        if (!$student) {
            wp_send_json_error(array('message' => 'Student not found.'));
            return;
        }
        
        // Check if student is already retired/alumni
        if (strtolower($student->grade) === 'alumni') {
            wp_send_json_error(array('message' => 'Student is already retired.'));
            return;
        }
        
        // Begin transaction-like operations
        $success = true;
        $error_message = '';
        
        // If student has a current team, add it to team history with "retired" reason
        if ($student->team_id && $student->team_id > 0) {
            // First, end any current team assignments
            $wpdb->update(
                $table_student_team_history,
                array(
                    'is_current' => 0,
                    'end_date' => current_time('Y-m-d'),
                    'updated_at' => current_time('mysql')
                ),
                array(
                    'student_id' => $student_id,
                    'is_current' => 1
                ),
                array('%d', '%s', '%s'),
                array('%d', '%d')
            );
            
            // Add the current team to history with "retired" reason
            $result = $wpdb->insert(
                $table_student_team_history,
                array(
                    'student_id' => $student_id,
                    'team_id' => $student->team_id,
                    'start_date' => current_time('Y-m-d'), // Use today as both start and end for retirement
                    'end_date' => current_time('Y-m-d'),
                    'season' => date('Y') . '-' . (date('Y') + 1), // Current season
                    'program' => $this->determine_program_by_grade($student->grade),
                    'is_current' => 0,
                    'reason_for_change' => 'Retired',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
            );
            
            if ($result === false) {
                $success = false;
                $error_message = 'Failed to add team history entry: ' . $wpdb->last_error;
            }
        }
        
        // Update student: mark as alumni but keep team affiliation
        if ($success) {
            $result = $wpdb->update(
                $table_students,
                array(
                    'grade' => 'Alumni'
                    // Keep team_id so they show as alumni of that team
                ),
                array('id' => $student_id),
                array('%s'),
                array('%d')
            );
            
            if ($result === false) {
                $success = false;
                $error_message = 'Failed to update student status: ' . $wpdb->last_error;
            }
        }
        
        if ($success) {
            wp_send_json_success(array(
                'message' => sprintf('Student %s %s has been retired successfully!', 
                    $student->first_name, $student->last_name)
            ));
        } else {
            wp_send_json_error(array('message' => $error_message ?: 'Unknown error occurred while retiring student.'));
        }
    }
    
    /**
     * Helper function to determine program by grade
     */
    private function determine_program_by_grade($grade) {
        $grade_num = intval($grade);
        if ($grade === 'K' || ($grade_num >= 1 && $grade_num <= 8)) {
            return 'FLL';
        } elseif ($grade_num >= 9 && $grade_num <= 12) {
            return 'FTC';
        } else {
            return 'Alumni';
        }
    }
}
