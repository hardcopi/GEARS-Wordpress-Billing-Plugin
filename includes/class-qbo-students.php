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
    }
    
    /**
     * Render students page
     */
    public function render_page() {
        global $wpdb;
        
        $table_students = $wpdb->prefix . 'gears_students';
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        // Handle form submissions
        if ($_POST) {
            $this->handle_form_submissions($table_students);
        }
        
        // Get all students with team information and customer names
        $students = $wpdb->get_results("
            SELECT s.*, t.team_name 
            FROM $table_students s 
            LEFT JOIN $table_teams t ON s.team_id = t.id 
            ORDER BY s.customer_id, s.last_name, s.first_name
        ");
        
        // Add customer names to students data and count students per customer
        $customers_cache = get_option('qbo_recurring_billing_customers_cache', array());
        $customers_lookup = array();
        $customer_student_counts = array();
        
        if (isset($customers_cache['data']) && is_array($customers_cache['data'])) {
            foreach ($customers_cache['data'] as $customer) {
                $customers_lookup[$customer['Id']] = isset($customer['DisplayName']) ? $customer['DisplayName'] : '';
            }
        }
        
        foreach ($students as $student) {
            $student->customer_name = isset($customers_lookup[$student->customer_id]) ? $customers_lookup[$student->customer_id] : 'No Customer';
            
            // Count students per customer
            if (!empty($student->customer_id)) {
                if (!isset($customer_student_counts[$student->customer_id])) {
                    $customer_student_counts[$student->customer_id] = 0;
                }
                $customer_student_counts[$student->customer_id]++;
            }
        }
        
        // Add student count info to each student record
        foreach ($students as $student) {
            if (!empty($student->customer_id)) {
                $student->customer_student_count = $customer_student_counts[$student->customer_id];
                $student->is_multiple_students = $customer_student_counts[$student->customer_id] > 1;
            } else {
                $student->customer_student_count = 0;
                $student->is_multiple_students = false;
            }
        }
        
        // Get all teams for dropdown
        $teams = $wpdb->get_results("SELECT id, team_name FROM $table_teams ORDER BY team_name");
        
        // Get customers from cache for dropdown
        $customers = $this->get_customers_for_dropdown();
        
        $this->render_page_html($students, $teams, $customers);
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
            echo '<div class="notice notice-success"><p>Student added successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error adding student: ' . $wpdb->last_error . '</p></div>';
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
            echo '<div class="notice notice-success"><p>Student updated successfully!</p></div>';
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
                            <th scope="col">Actions</th>
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
                                            <span class="customer-student-count" style="color: #666; font-size: 0.9em;">(<?php echo $student->customer_student_count; ?> students)</span>
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
                
                $('#edit-student-modal').show();
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
}
