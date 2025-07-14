<?php

// Assuming the trait is included via main file or autoloader
// If not, add: require_once QBO_PLUGIN_DIR . 'includes/traits/ajax-handler.php';

class QBO_Teams {
    use QBO_Ajax_Handler; // Add this to use the trait

    private $core;
    private $database;
    private $table_schema = [ 'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT', 'team_name' => 'VARCHAR(255)', 'team_number' => 'VARCHAR(255)', 'program' => 'VARCHAR(50)', 'description' => 'TEXT', 'logo' => 'VARCHAR(255)', 'team_photo' => 'VARCHAR(255)', 'facebook' => 'VARCHAR(255)', 'twitter' => 'VARCHAR(255)', 'instagram' => 'VARCHAR(255)', 'website' => 'VARCHAR(255)', 'archived' => 'TINYINT(1) DEFAULT 0', 'hall_of_fame' => 'TINYINT(1) DEFAULT 0' ];

    public function __construct($core, $database) {
        $this->core = $core;
        $this->database = $database;
        // Ensure database schema is up to date
        $this->ensure_database_schema();
        // AJAX handlers
        add_action('wp_ajax_qbo_add_team', array($this, 'ajax_add_team'));
        add_action('wp_ajax_qbo_update_team', array($this, 'ajax_update_team'));
        add_action('wp_ajax_qbo_edit_team', array($this, 'ajax_edit_team'));
        add_action('wp_ajax_qbo_get_team_students', array($this, 'ajax_get_team_students'));
        add_action('wp_ajax_qbo_get_team_mentors', array($this, 'ajax_get_team_mentors'));
        add_action('wp_ajax_get_mentor_data', array($this, 'ajax_get_mentor_data'));
        add_action('wp_ajax_delete_mentor', array($this, 'ajax_delete_mentor'));
        add_action('wp_ajax_get_student_data', array($this, 'ajax_get_student_data'));
        add_action('wp_ajax_delete_student', array($this, 'ajax_delete_student'));
        add_action('wp_ajax_qbo_archive_team', array($this, 'ajax_archive_team'));
        add_action('wp_ajax_qbo_restore_team', array($this, 'ajax_restore_team'));
        add_action('wp_ajax_qbo_get_team_data', array($this, 'ajax_get_team_data'));
        // Register the new invoices page
        add_action('admin_menu', array($this, 'register_invoices_page'));
    }

    /**
     * Ensure database schema is up to date
     */
    private function ensure_database_schema() {
        global $wpdb;
        $table_teams = $wpdb->prefix . 'gears_teams';
        // Check if archived column exists, if not add it
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_teams LIKE 'archived'");
        if (empty($column_exists)) {
            // Add the archived column
            $wpdb->query("ALTER TABLE $table_teams ADD COLUMN archived TINYINT(1) DEFAULT 0");
        }
        // Check if hall_of_fame column exists, if not add it
        $hall_of_fame_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_teams LIKE 'hall_of_fame'");
        if (empty($hall_of_fame_exists)) {
            // Add the hall_of_fame column
            $wpdb->query("ALTER TABLE $table_teams ADD COLUMN hall_of_fame TINYINT(1) DEFAULT 0");
        }
    }

    /**
     * Render teams page
     */
    public function render_page() {
        // Check if we're viewing a team's details
        if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['team_id'])) {
            $this->render_team_details_page(intval($_GET['team_id']));
            return;
        }
        global $wpdb;
        $table_teams = $wpdb->prefix . 'gears_teams';
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        // Handle form submissions
        if ($_POST) {
            $this->handle_form_submissions($table_teams, $table_mentors);
        }
        // Get all teams (excluding archived ones if the column exists)
        // First check if the archived column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_teams LIKE 'archived'");
        if (!empty($column_exists)) {
            // Column exists, filter out archived teams for active list
            $teams = $wpdb->get_results("SELECT * FROM $table_teams WHERE (archived = 0 OR archived IS NULL) ORDER BY team_name ASC");
            // Get archived teams separately
            $archived_teams = $wpdb->get_results("SELECT * FROM $table_teams WHERE archived = 1 ORDER BY team_name ASC");
        } else {
            // Column doesn't exist, get all teams
            $teams = $wpdb->get_results("SELECT * FROM $table_teams ORDER BY team_name ASC");
            $archived_teams = []; // No archived teams if column doesn't exist
        }
        // Render the page
        ?>
        <div class="wrap">
            <h1>Teams</h1>
            <button id="add-team-btn" class="button button-primary">Add New Team</button>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Team Name</th>
                        <th>Team Number</th>
                        <th>Program</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($teams) : ?>
                        <?php foreach ($teams as $team) : ?>
                            <tr>
                                <td><?php echo esc_html($team->id); ?></td>
                                <td><?php echo esc_html($team->team_name); ?></td>
                                <td><?php echo esc_html($team->team_number); ?></td>
                                <td><?php echo esc_html($team->program); ?></td>
                                <td>
                                    <button class="button edit-team" data-team-id="<?php echo esc_attr($team->id); ?>">Edit</button>
                                    <button class="button view-team" data-team-id="<?php echo esc_attr($team->id); ?>">View</button>
                                    <button class="button archive-team" data-team-id="<?php echo esc_attr($team->id); ?>">Archive</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="5">No teams found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Archived Teams Section -->
            <?php if (!empty($archived_teams)) : ?>
                <h2>Archived Teams</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Team Name</th>
                            <th>Team Number</th>
                            <th>Program</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archived_teams as $team) : ?>
                            <tr>
                                <td><?php echo esc_html($team->id); ?></td>
                                <td><?php echo esc_html($team->team_name); ?></td>
                                <td><?php echo esc_html($team->team_number); ?></td>
                                <td><?php echo esc_html($team->program); ?></td>
                                <td>
                                    <button class="button restore-team" data-team-id="<?php echo esc_attr($team->id); ?>">Restore</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Add/Edit Team Modal -->
        <div id="team-modal" class="modal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2 id="modal-title">Add Team</h2>
                <form id="team-form">
                    <input type="hidden" id="team_id" name="team_id">
                    <label for="team_name">Team Name:</label>
                    <input type="text" id="team_name" name="team_name" required><br>
                    <label for="team_number">Team Number:</label>
                    <input type="text" id="team_number" name="team_number"><br>
                    <label for="program">Program:</label>
                    <input type="text" id="program" name="program"><br>
                    <label for="description">Description:</label>
                    <textarea id="description" name="description"></textarea><br>
                    <label for="logo">Logo URL:</label>
                    <input type="text" id="logo" name="logo"><br>
                    <label for="team_photo">Team Photo URL:</label>
                    <input type="text" id="team_photo" name="team_photo"><br>
                    <label for="facebook">Facebook:</label>
                    <input type="text" id="facebook" name="facebook"><br>
                    <label for="twitter">Twitter:</label>
                    <input type="text" id="twitter" name="twitter"><br>
                    <label for="instagram">Instagram:</label>
                    <input type="text" id="instagram" name="instagram"><br>
                    <label for="website">Website:</label>
                    <input type="text" id="website" name="website"><br>
                    <label for="hall_of_fame">Hall of Fame:</label>
                    <input type="checkbox" id="hall_of_fame" name="hall_of_fame"><br>
                    <button type="submit" class="button button-primary">Save Team</button>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render team details page
     */
    private function render_team_details_page($team_id) {
        global $wpdb;
        $table_teams = $wpdb->prefix . 'gears_teams';
        $team = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_teams WHERE id = %d", $team_id));
        if (!$team) {
            echo '<div class="wrap"><h1>Team not found</h1></div>';
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($team->team_name); ?> Details</h1>
            <p><strong>Team Number:</strong> <?php echo esc_html($team->team_number); ?></p>
            <p><strong>Program:</strong> <?php echo esc_html($team->program); ?></p>
            <p><strong>Description:</strong> <?php echo esc_html($team->description); ?></p>
            <!-- Add more team details here -->
            <a href="<?php echo admin_url('admin.php?page=qbo-teams'); ?>" class="button">Back to Teams</a>
        </div>
        <?php
    }

    /**
     * Handle form submissions
     */
    private function handle_form_submissions($table_teams, $table_mentors) {
        if (isset($_POST['action']) && $_POST['action'] === 'add_team') {
            $this->handle_add_team($table_teams);
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_team') {
            $this->handle_update_team($table_teams);
        } elseif (isset($_POST['action']) && $_POST['action'] === 'add_mentor') {
            $this->handle_add_mentor($table_mentors);
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_mentor') {
            $this->handle_update_mentor($table_mentors);
        }
    }

    /**
     * Handle add team
     */
    private function handle_add_team($table_teams) {
        global $wpdb;
        $data = [
            'team_name' => sanitize_text_field($_POST['team_name']),
            'team_number' => sanitize_text_field($_POST['team_number']),
            'program' => sanitize_text_field($_POST['program']),
            'description' => sanitize_textarea_field($_POST['description']),
            'logo' => esc_url_raw($_POST['logo']),
            'team_photo' => esc_url_raw($_POST['team_photo']),
            'facebook' => esc_url_raw($_POST['facebook']),
            'twitter' => esc_url_raw($_POST['twitter']),
            'instagram' => esc_url_raw($_POST['instagram']),
            'website' => esc_url_raw($_POST['website']),
            'hall_of_fame' => isset($_POST['hall_of_fame']) ? 1 : 0,
        ];
        $result = $wpdb->insert($table_teams, $data);
        if ($result) {
            echo '<div class="notice notice-success"><p>Team added successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error adding team: ' . esc_html($wpdb->last_error) . '</p></div>';
        }
    }

    /**
     * Handle update team
     */
    private function handle_update_team($table_teams) {
        global $wpdb;
        $team_id = intval($_POST['team_id']);
        $data = [
            'team_name' => sanitize_text_field($_POST['team_name']),
            'team_number' => sanitize_text_field($_POST['team_number']),
            'program' => sanitize_text_field($_POST['program']),
            'description' => sanitize_textarea_field($_POST['description']),
            'logo' => esc_url_raw($_POST['logo']),
            'team_photo' => esc_url_raw($_POST['team_photo']),
            'facebook' => esc_url_raw($_POST['facebook']),
            'twitter' => esc_url_raw($_POST['twitter']),
            'instagram' => esc_url_raw($_POST['instagram']),
            'website' => esc_url_raw($_POST['website']),
            'hall_of_fame' => isset($_POST['hall_of_fame']) ? 1 : 0,
        ];
        $result = $wpdb->update($table_teams, $data, ['id' => $team_id]);
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>Team updated successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error updating team: ' . esc_html($wpdb->last_error) . '</p></div>';
        }
    }

    /**
     * AJAX add team
     */
    public function ajax_add_team() {
        $this->verify_ajax_nonce();
        $result = $this->capture_and_check_output(function() {
            global $wpdb;
            $table_teams = $wpdb->prefix . 'gears_teams';
            $this->handle_add_team($table_teams);
        });
        if ($result['success']) {
            $this->send_json_success($result['message']);
        } else {
            $this->send_json_error($result['message']);
        }
    }

    /**
     * AJAX update team
     */
    public function ajax_update_team() {
        $this->verify_ajax_nonce();
        $result = $this->capture_and_check_output(function() {
            global $wpdb;
            $table_teams = $wpdb->prefix . 'gears_teams';
            $this->handle_update_team($table_teams);
        });
        if ($result['success']) {
            $this->send_json_success($result['message']);
        } else {
            $this->send_json_error($result['message']);
        }
    }

    /**
     * AJAX edit team (get data)
     */
    public function ajax_edit_team() {
        $this->verify_ajax_nonce();
        global $wpdb;
        $team_id = intval($_POST['team_id']);
        $table_teams = $wpdb->prefix . 'gears_teams';
        $team = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_teams WHERE id = %d", $team_id));
        if ($team) {
            $this->send_json_success($team);
        } else {
            $this->send_json_error('Team not found');
        }
    }

    /**
     * AJAX get team students
     */
    public function ajax_get_team_students() {
        $this->verify_ajax_nonce();
        global $wpdb;
        $team_id = intval($_POST['team_id']);
        $table_students = $wpdb->prefix . 'gears_students';
        $students = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_students WHERE team_id = %d", $team_id));
        $this->send_json_success($students);
    }

    /**
     * AJAX get team mentors
     */
    public function ajax_get_team_mentors() {
        $this->verify_ajax_nonce();
        global $wpdb;
        $team_id = intval($_POST['team_id']);
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        $mentors = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_mentors WHERE team_id = %d", $team_id));
        $this->send_json_success($mentors);
    }

    /**
     * AJAX get mentor data
     */
    public function ajax_get_mentor_data() {
        $this->verify_ajax_nonce();
        global $wpdb;
        $mentor_id = intval($_POST['mentor_id']);
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        $mentor = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_mentors WHERE id = %d", $mentor_id));
        if ($mentor) {
            $this->send_json_success($mentor);
        } else {
            $this->send_json_error('Mentor not found');
        }
    }

    /**
     * AJAX delete mentor
     */
    public function ajax_delete_mentor() {
        $this->verify_ajax_nonce();
        global $wpdb;
        $mentor_id = intval($_POST['mentor_id']);
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        $result = $wpdb->delete($table_mentors, ['id' => $mentor_id]);
        if ($result) {
            $this->send_json_success('Mentor deleted successfully');
        } else {
            $this->send_json_error('Error deleting mentor: ' . $this->get_db_error());
        }
    }

    /**
     * AJAX get student data
     */
    public function ajax_get_student_data() {
        $this->verify_ajax_nonce();
        global $wpdb;
        $student_id = intval($_POST['student_id']);
        $table_students = $wpdb->prefix . 'gears_students';
        $student = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_students WHERE id = %d", $student_id));
        if ($student) {
            $this->send_json_success($student);
        } else {
            $this->send_json_error('Student not found');
        }
    }

    /**
     * AJAX delete student
     */
    public function ajax_delete_student() {
        $this->verify_ajax_nonce();
        global $wpdb;
        $student_id = intval($_POST['student_id']);
        $table_students = $wpdb->prefix . 'gears_students';
        $result = $wpdb->delete($table_students, ['id' => $student_id]);
        if ($result) {
            $this->send_json_success('Student deleted successfully');
        } else {
            $this->send_json_error('Error deleting student: ' . $this->get_db_error());
        }
    }

    /**
     * AJAX archive team
     */
    public function ajax_archive_team() {
        $this->verify_ajax_nonce();
        global $wpdb;
        $team_id = intval($_POST['team_id']);
        $table_teams = $wpdb->prefix . 'gears_teams';
        $result = $wpdb->update($table_teams, ['archived' => 1], ['id' => $team_id]);
        if ($result !== false) {
            $this->send_json_success('Team archived successfully');
        } else {
            $this->send_json_error('Error archiving team: ' . $this->get_db_error());
        }
    }

    /**
     * AJAX restore team
     */
    public function ajax_restore_team() {
        $this->verify_ajax_nonce();
        global $wpdb;
        $team_id = intval($_POST['team_id']);
        $table_teams = $wpdb->prefix . 'gears_teams';
        $result = $wpdb->update($table_teams, ['archived' => 0], ['id' => $team_id]);
        if ($result !== false) {
            $this->send_json_success('Team restored successfully');
        } else {
            $this->send_json_error('Error restoring team: ' . $this->get_db_error());
        }
    }

    /**
     * AJAX get team data
     */
    public function ajax_get_team_data() {
        $this->verify_ajax_nonce();
        global $wpdb;
        $team_id = intval($_POST['team_id']);
        $table_teams = $wpdb->prefix . 'gears_teams';
        $team = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_teams WHERE id = %d", $team_id));
        if ($team) {
            $this->send_json_success($team);
        } else {
            $this->send_json_error('Team not found');
        }
    }

    /**
     * Register invoices page (hidden)
     */
    public function register_invoices_page() {
        add_submenu_page(
            null, // Hide from menu
            'View Invoices',
            'View Invoices',
            'manage_options',
            'qbo-view-invoices',
            array($this, 'render_invoices_page')
        );
    }

    /**
     * Render invoices page
     */
    public function render_invoices_page() {
        // Implementation for viewing invoices
        echo '<div class="wrap"><h1>View Invoices</h1></div>';
    }
}