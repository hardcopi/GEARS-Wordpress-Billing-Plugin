<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Class QBO_Mentors
 * 
 * Handles mentor management functionality for the QBO Recurring Billing plugin
 */
class QBO_Mentors {
    
    private $core;
    private $table_teams;
    private $table_mentors;
    
    public function __construct($core) {
        global $wpdb;
        
        $this->core = $core;
        $this->table_teams = $wpdb->prefix . 'gears_teams';
        $this->table_mentors = $wpdb->prefix . 'gears_mentors';
        
        // Add AJAX hooks
        add_action('wp_ajax_qbo_edit_mentor', array($this, 'ajax_edit_mentor'));
        add_action('wp_ajax_qbo_get_team_mentors', array($this, 'ajax_get_team_mentors'));
    }
    
    /**
     * Display the mentors management page
     */
    public function mentors_page() {
        global $wpdb;
        
        // Handle form submissions
        if ($_POST) {
            if (isset($_POST['add_mentor']) && wp_verify_nonce($_POST['mentor_nonce'], 'add_mentor_action')) {
                $team_id = intval($_POST['team_id']);
                $first_name = sanitize_text_field($_POST['first_name']);
                $last_name = sanitize_text_field($_POST['last_name']);
                $email = sanitize_email($_POST['email']);
                $phone = sanitize_text_field($_POST['phone']);
                $address = sanitize_textarea_field($_POST['address']);
                $notes = sanitize_textarea_field($_POST['notes']);
                
                if (!empty($first_name) && !empty($last_name)) {
                    $result = $wpdb->insert(
                        $this->table_mentors,
                        array(
                            'team_id' => $team_id > 0 ? $team_id : null,
                            'first_name' => $first_name,
                            'last_name' => $last_name,
                            'email' => $email,
                            'phone' => $phone,
                            'address' => $address,
                            'notes' => $notes
                        ),
                        array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
                    );
                    
                    if ($result === false) {
                        echo '<div class="notice notice-error"><p>Error adding mentor.</p></div>';
                    } else {
                        echo '<div class="notice notice-success"><p>Mentor added successfully!</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error"><p>First name and last name are required.</p></div>';
                }
            } elseif (isset($_POST['delete_mentor']) && wp_verify_nonce($_POST['delete_nonce'], 'delete_mentor_action')) {
                $mentor_id = intval($_POST['mentor_id']);
                if ($mentor_id > 0) {
                    $result = $wpdb->delete($this->table_mentors, array('id' => $mentor_id), array('%d'));
                    
                    if ($result === false) {
                        echo '<div class="notice notice-error"><p>Error deleting mentor.</p></div>';
                    } else {
                        echo '<div class="notice notice-success"><p>Mentor deleted successfully!</p></div>';
                    }
                }
            }
        }
        
        // Get all teams and mentors
        $teams = $wpdb->get_results("SELECT * FROM $this->table_teams ORDER BY team_name");
        $mentors = $wpdb->get_results("
            SELECT m.*, t.team_name 
            FROM $this->table_mentors m 
            LEFT JOIN $this->table_teams t ON m.team_id = t.id 
            ORDER BY m.last_name, m.first_name
        ");
        
        ?>
        <style>
            .mentor-form-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                max-width: 600px;
            }
            .mentor-form-card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            /* Modal styles */
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
        </style>
        <div class="wrap">
            <h1>Mentors Management
                <button id="open-mentor-modal" class="button button-primary" style="float:right;">Add New Mentor</button>
            </h1>
            
            <!-- Modal for Add Mentor -->
            <div id="mentor-modal-overlay" class="gears-modal-overlay">
                <div class="gears-modal">
                    <span class="gears-modal-close" id="close-mentor-modal">&times;</span>
                    <div class="mentor-form-card" style="box-shadow:none; border:none; max-width:100%; margin:0; padding:0;">
                        <h2 style="border:none; padding-bottom:0; margin-top:0;">Add New Mentor</h2>
                        <form method="post">
                            <?php wp_nonce_field('add_mentor_action', 'mentor_nonce'); ?>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Team</th>
                                    <td>
                                        <select name="team_id" style="width: 100%;">
                                            <option value="0">No Team</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?php echo intval($team->id); ?>">
                                                    <?php echo esc_html($team->team_name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">First Name <span style="color:red;">*</span></th>
                                    <td><input type="text" name="first_name" required style="width: 100%;" /></td>
                                </tr>
                                <tr>
                                    <th scope="row">Last Name <span style="color:red;">*</span></th>
                                    <td><input type="text" name="last_name" required style="width: 100%;" /></td>
                                </tr>
                                <tr>
                                    <th scope="row">Email</th>
                                    <td><input type="email" name="email" style="width: 100%;" /></td>
                                </tr>
                                <tr>
                                    <th scope="row">Phone</th>
                                    <td><input type="text" name="phone" style="width: 100%;" /></td>
                                </tr>
                                <tr>
                                    <th scope="row">Address</th>
                                    <td><textarea name="address" rows="3" style="width: 100%;"></textarea></td>
                                </tr>
                                <tr>
                                    <th scope="row">Notes</th>
                                    <td><textarea name="notes" rows="3" style="width: 100%;"></textarea></td>
                                </tr>
                            </table>
                            <p class="submit">
                                <input type="submit" name="add_mentor" class="button-primary" value="Add Mentor" />
                            </p>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Mentors List -->
            <h2>Mentors (<?php echo count($mentors); ?>)</h2>
            <?php if ($mentors): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Team</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>Notes</th>
                            <th>Created</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mentors as $mentor): ?>
                            <tr>
                                <td><strong><?php echo esc_html($mentor->first_name . ' ' . $mentor->last_name); ?></strong></td>
                                <td><?php echo esc_html($mentor->team_name ?: 'No Team'); ?></td>
                                <td><?php echo esc_html($mentor->email); ?></td>
                                <td><?php echo esc_html($mentor->phone); ?></td>
                                <td><?php echo esc_html($mentor->address); ?></td>
                                <td><?php echo esc_html($mentor->notes); ?></td>
                                <td><?php echo esc_html($mentor->created_at ?? 'N/A'); ?></td>
                                <td>
                                    <button class="button button-small edit-mentor" 
                                            data-mentor-id="<?php echo intval($mentor->id); ?>"
                                            data-mentor-name="<?php echo esc_attr($mentor->first_name . ' ' . $mentor->last_name); ?>"
                                            title="Edit Mentor">
                                        <span class="dashicons dashicons-edit"></span>
                                    </button>
                                    <form method="post" style="display: inline-block; margin-left: 5px;" 
                                          onsubmit="return confirm('Are you sure you want to delete this mentor?');">
                                        <?php wp_nonce_field('delete_mentor_action', 'delete_nonce'); ?>
                                        <input type="hidden" name="mentor_id" value="<?php echo intval($mentor->id); ?>" />
                                        <button type="submit" name="delete_mentor" class="button button-small" 
                                                title="Delete Mentor" style="color: #a00;">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No mentors found. Add your first mentor above.</p>
            <?php endif; ?>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var openBtn = document.getElementById('open-mentor-modal');
            var modal = document.getElementById('mentor-modal-overlay');
            var closeBtn = document.getElementById('close-mentor-modal');
            
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
     * AJAX handler for editing mentors
     */
    public function ajax_edit_mentor() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'qbo_edit_mentor_nonce')) {
            wp_die('Security check failed');
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $mentor_id = intval($_POST['mentor_id']);
        
        if ($mentor_id <= 0) {
            wp_send_json_error('Invalid mentor ID');
        }
        
        // Get mentor data
        $mentor = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_mentors WHERE id = %d",
            $mentor_id
        ));
        
        if (!$mentor) {
            wp_send_json_error('Mentor not found');
        }
        
        // Get all teams for the dropdown
        $teams = $wpdb->get_results("SELECT * FROM $this->table_teams ORDER BY team_name");
        
        // Build the edit form HTML
        $html = '<div style="max-width: 500px;">';
        $html .= '<h3>Edit Mentor: ' . esc_html($mentor->first_name . ' ' . $mentor->last_name) . '</h3>';
        $html .= '<form id="edit-mentor-form">';
        $html .= '<input type="hidden" name="mentor_id" value="' . intval($mentor->id) . '" />';
        $html .= '<table class="form-table">';
        
        $html .= '<tr>';
        $html .= '<th scope="row">Team</th>';
        $html .= '<td>';
        $html .= '<select name="team_id" style="width: 100%;">';
        $html .= '<option value="0"' . ($mentor->team_id ? '' : ' selected') . '>No Team</option>';
        foreach ($teams as $team) {
            $selected = ($mentor->team_id == $team->id) ? ' selected' : '';
            $html .= '<option value="' . intval($team->id) . '"' . $selected . '>' . esc_html($team->team_name) . '</option>';
        }
        $html .= '</select>';
        $html .= '</td>';
        $html .= '</tr>';
        
        $html .= '<tr>';
        $html .= '<th scope="row">First Name <span style="color:red;">*</span></th>';
        $html .= '<td><input type="text" name="first_name" value="' . esc_attr($mentor->first_name) . '" required style="width: 100%;" /></td>';
        $html .= '</tr>';
        
        $html .= '<tr>';
        $html .= '<th scope="row">Last Name <span style="color:red;">*</span></th>';
        $html .= '<td><input type="text" name="last_name" value="' . esc_attr($mentor->last_name) . '" required style="width: 100%;" /></td>';
        $html .= '</tr>';
        
        $html .= '<tr>';
        $html .= '<th scope="row">Email</th>';
        $html .= '<td><input type="email" name="email" value="' . esc_attr($mentor->email) . '" style="width: 100%;" /></td>';
        $html .= '</tr>';
        
        $html .= '<tr>';
        $html .= '<th scope="row">Phone</th>';
        $html .= '<td><input type="text" name="phone" value="' . esc_attr($mentor->phone) . '" style="width: 100%;" /></td>';
        $html .= '</tr>';
        
        $html .= '<tr>';
        $html .= '<th scope="row">Address</th>';
        $html .= '<td><textarea name="address" rows="3" style="width: 100%;">' . esc_textarea($mentor->address) . '</textarea></td>';
        $html .= '</tr>';
        
        $html .= '<tr>';
        $html .= '<th scope="row">Notes</th>';
        $html .= '<td><textarea name="notes" rows="3" style="width: 100%;">' . esc_textarea($mentor->notes) . '</textarea></td>';
        $html .= '</tr>';
        
        $html .= '</table>';
        $html .= '<p class="submit">';
        $html .= '<button type="submit" class="button-primary">Update Mentor</button>';
        $html .= ' <button type="button" onclick="closeMentorEditModal()" class="button">Cancel</button>';
        $html .= '</p>';
        $html .= '</form>';
        $html .= '</div>';
        
        wp_send_json_success($html);
    }
    
    /**
     * Get mentors by team ID
     */
    public function get_mentors_by_team($team_id) {
        global $wpdb;
        
        if (empty($team_id)) {
            return array();
        }
        
        $mentors = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, t.team_name 
             FROM $this->table_mentors m 
             LEFT JOIN $this->table_teams t ON m.team_id = t.id 
             WHERE m.team_id = %d 
             ORDER BY m.first_name, m.last_name",
            $team_id
        ));
        
        return $mentors ? $mentors : array();
    }
    
    /**
     * AJAX handler to get mentors for a team
     */
    public function ajax_get_team_mentors() {
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'qbo_get_customers')) {
            wp_send_json_error('Unauthorized');
        }
        
        $team_id = isset($_POST['team_id']) ? intval($_POST['team_id']) : 0;
        
        if (!$team_id) {
            wp_send_json_success(array());
        }
        
        $mentors = $this->get_mentors_by_team($team_id);
        
        $mentor_data = array();
        foreach ($mentors as $mentor) {
            $mentor_data[] = array(
                'id' => $mentor->id,
                'first_name' => $mentor->first_name,
                'last_name' => $mentor->last_name,
                'full_name' => $mentor->first_name . ' ' . $mentor->last_name,
                'email' => $mentor->email,
                'phone' => $mentor->phone,
                'address' => $mentor->address,
                'notes' => $mentor->notes
            );
        }
        
        wp_send_json_success($mentor_data);
    }
}
