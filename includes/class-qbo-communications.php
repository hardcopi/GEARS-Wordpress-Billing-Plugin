<?php
/**
 * Communications functionality for QBO GEARS Plugin
 */
class QBO_Communications {
    private $core;

    public function __construct($core) {
        $this->core = $core;
        
        // Add AJAX handlers for communications
        add_action('wp_ajax_qbo_send_mentor_email', array($this, 'ajax_send_mentor_email'));
        add_action('wp_ajax_qbo_get_mentor_details', array($this, 'ajax_get_mentor_details'));
    }

    /**
     * Render the main communications page
     */
    public function communications_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        echo '<div class="wrap">';
        echo '<h1>Communications</h1>';
        echo '<p>Send emails and manage communications with mentors, students, and teams.</p>';
        
        echo '<div class="communication-cards">';
        
        // Email Mentor Card
        echo '<div class="communication-card">';
        echo '<h2><span class="dashicons dashicons-email-alt"></span> Email Mentor</h2>';
        echo '<p>Send personalized emails to individual mentors or groups of mentors.</p>';
        echo '<div class="card-actions">';
        echo '<button class="button button-primary" id="email-mentor-btn">Compose Email</button>';
        echo '</div>';
        echo '</div>';
        
        // Future communication options can go here
        echo '<div class="communication-card coming-soon">';
        echo '<h2><span class="dashicons dashicons-groups"></span> Team Notifications</h2>';
        echo '<p>Send notifications to entire teams (mentors and students).</p>';
        echo '<div class="card-actions">';
        echo '<button class="button" disabled>Coming Soon</button>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="communication-card coming-soon">';
        echo '<h2><span class="dashicons dashicons-megaphone"></span> Bulk Communications</h2>';
        echo '<p>Send announcements to all program participants.</p>';
        echo '<div class="card-actions">';
        echo '<button class="button" disabled>Coming Soon</button>';
        echo '</div>';
        echo '</div>';
        
        echo '</div>'; // Close communication-cards
        
        // Email Mentor Modal
        $this->render_email_mentor_modal();
        
        $this->add_communications_js();
        
        echo '</div>'; // Close wrap
    }

    /**
     * Render the email mentor modal
     */
    private function render_email_mentor_modal() {
        ?>
        <div id="email-mentor-modal" class="gears-modal">
            <div class="gears-modal-content">
                <div class="gears-modal-header">
                    <h2><span class="dashicons dashicons-email-alt"></span> Email Mentor</h2>
                    <button type="button" class="gears-modal-close">&times;</button>
                </div>
                <div class="gears-modal-body">
                    <form id="email-mentor-form">
                        <div class="form-group">
                            <label for="mentor-select">Select Mentor *</label>
                            <select id="mentor-select" name="mentor_id" required>
                                <option value="">Choose a mentor...</option>
                                <?php $this->render_mentor_options(); ?>
                            </select>
                        </div>
                        
                        <div id="mentor-details" style="display: none;">
                            <div class="mentor-info-card">
                                <h4>Mentor Information</h4>
                                <div class="mentor-info-content">
                                    <p><strong>Name:</strong> <span id="mentor-name-display"></span></p>
                                    <p><strong>Email:</strong> <span id="mentor-email-display"></span></p>
                                    <p><strong>Team:</strong> <span id="mentor-team-display"></span></p>
                                    <p><strong>Phone:</strong> <span id="mentor-phone-display"></span></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="email-subject">Subject *</label>
                            <input type="text" id="email-subject" name="subject" required placeholder="Enter email subject">
                        </div>
                        
                        <div class="form-group">
                            <label for="email-message">Message *</label>
                            <textarea id="email-message" name="message" rows="10" required placeholder="Enter your message here..."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="send-copy" name="send_copy" checked>
                                Send a copy to myself
                            </label>
                        </div>
                    </form>
                </div>
                <div class="gears-modal-footer">
                    <button type="button" class="button" id="cancel-email">Cancel</button>
                    <button type="button" class="button button-primary" id="send-email">
                        <span class="dashicons dashicons-email-alt"></span> Send Email
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render mentor options for select dropdown
     */
    private function render_mentor_options() {
        global $wpdb;
        
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        $mentors = $wpdb->get_results("
            SELECT m.*, t.team_name, t.team_number,
                   CONCAT(m.first_name, ' ', m.last_name) as full_name
            FROM {$table_mentors} m
            LEFT JOIN {$table_teams} t ON m.team_id = t.id
            ORDER BY m.last_name, m.first_name
        ");
        
        foreach ($mentors as $mentor) {
            $team_info = '';
            if ($mentor->team_name) {
                $team_info = ' - ' . $mentor->team_name;
                if ($mentor->team_number) {
                    $team_info .= ' (#' . $mentor->team_number . ')';
                }
            }
            
            echo '<option value="' . esc_attr($mentor->id) . '">';
            echo esc_html($mentor->full_name . $team_info);
            echo '</option>';
        }
    }

    /**
     * Add JavaScript for communications functionality
     */
    private function add_communications_js() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Open email mentor modal
            $('#email-mentor-btn').on('click', function() {
                $('#email-mentor-modal').addClass('show');
            });
            
            // Close modal
            $('.gears-modal-close, #cancel-email').on('click', function() {
                $('#email-mentor-modal').removeClass('show');
                $('#email-mentor-form')[0].reset();
                $('#mentor-details').hide();
            });
            
            // Close modal when clicking overlay (but not the content)
            $('#email-mentor-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).removeClass('show');
                    $('#email-mentor-form')[0].reset();
                    $('#mentor-details').hide();
                }
            });
            
            // Load mentor details when selected
            $('#mentor-select').on('change', function() {
                var mentorId = $(this).val();
                
                if (mentorId) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'qbo_get_mentor_details',
                            mentor_id: mentorId,
                            nonce: '<?php echo wp_create_nonce('qbo_get_mentor_details'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                var mentor = response.data;
                                $('#mentor-name-display').text(mentor.full_name);
                                $('#mentor-email-display').text(mentor.email || 'No email provided');
                                $('#mentor-team-display').text(mentor.team_info || 'No team assigned');
                                $('#mentor-phone-display').text(mentor.phone || 'No phone provided');
                                $('#mentor-details').show();
                            }
                        },
                        error: function() {
                            alert('Error loading mentor details');
                        }
                    });
                } else {
                    $('#mentor-details').hide();
                }
            });
            
            // Send email
            $('#send-email').on('click', function() {
                var $button = $(this);
                var originalText = $button.html();
                
                // Validate form
                var mentorId = $('#mentor-select').val();
                var subject = $('#email-subject').val().trim();
                var message = (typeof tinymce !== 'undefined' && tinymce.get('email-message')) ? tinymce.get('email-message').getContent().trim() : $('#email-message').val().trim();
                
                if (!mentorId || !subject || !message || message === '' || message === '<p></p>') {
                    alert('Please fill in all required fields.');
                    return;
                }
                
                // Show loading state
                $button.html('<span class="dashicons dashicons-update-alt"></span> Sending...').prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'qbo_send_mentor_email',
                        mentor_id: mentorId,
                        subject: subject,
                        message: message,
                        send_copy: $('#send-copy').is(':checked'),
                        nonce: '<?php echo wp_create_nonce('qbo_send_mentor_email'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var debugInfo = response.data.debug_info || {};
                            var resultMessage = 'Email sent successfully!\n\n';
                            resultMessage += 'Details:\n';
                            resultMessage += '• Email System: ' + (debugInfo.email_system || 'Unknown') + '\n';
                            resultMessage += '• Mentor: ' + (debugInfo.mentor_name || 'Unknown') + '\n';
                            resultMessage += '• Email: ' + (debugInfo.mentor_email || 'Unknown') + '\n';
                            resultMessage += '• From: ' + (debugInfo.from_name || 'Unknown') + ' <' + (debugInfo.from_email || 'Unknown') + '>\n';
                            resultMessage += '• wp_mail Available: ' + (debugInfo.wp_mail_available ? 'Yes' : 'No') + '\n';
                            resultMessage += '• PHP mail Available: ' + (debugInfo.php_mail_available ? 'Yes' : 'No') + '\n';
                            resultMessage += '• Server: ' + (debugInfo.server_name || 'Unknown') + '\n';
                            
                            alert(resultMessage);
                            $('#email-mentor-modal').removeClass('show');
                            $('#email-mentor-form')[0].reset();
                            $('#mentor-details').hide();
                        } else {
                            var debugInfo = response.data.debug_info || {};
                            var errorMessage = 'Failed to send email!\n\n';
                            errorMessage += 'Debug Information:\n';
                            errorMessage += '• Email System: ' + (debugInfo.email_system || 'Unknown') + '\n';
                            errorMessage += '• Mentor: ' + (debugInfo.mentor_name || 'Unknown') + '\n';
                            errorMessage += '• Email: ' + (debugInfo.mentor_email || 'Unknown') + '\n';
                            errorMessage += '• From: ' + (debugInfo.from_name || 'Unknown') + ' <' + (debugInfo.from_email || 'Unknown') + '>\n';
                            errorMessage += '• wp_mail Available: ' + (debugInfo.wp_mail_available ? 'Yes' : 'No') + '\n';
                            errorMessage += '• PHP mail Available: ' + (debugInfo.php_mail_available ? 'Yes' : 'No') + '\n';
                            errorMessage += '• Server: ' + (debugInfo.server_name || 'Unknown') + '\n';
                            errorMessage += '\nCheck the WordPress debug log for more details.';
                            
                            alert(errorMessage);
                        }
                    },
                    error: function() {
                        alert('Error sending email. Please try again.');
                    },
                    complete: function() {
                        $button.html(originalText).prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler for getting mentor details
     */
    public function ajax_get_mentor_details() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'qbo_get_mentor_details')) {
            wp_send_json_error('Security check failed');
        }

        // Verify user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $mentor_id = intval($_POST['mentor_id']);

        global $wpdb;
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        $table_teams = $wpdb->prefix . 'gears_teams';

        $mentor = $wpdb->get_row($wpdb->prepare("
            SELECT m.*, t.team_name, t.team_number,
                   CONCAT(m.first_name, ' ', m.last_name) as full_name
            FROM {$table_mentors} m
            LEFT JOIN {$table_teams} t ON m.team_id = t.id
            WHERE m.id = %d
        ", $mentor_id));

        if (!$mentor) {
            wp_send_json_error('Mentor not found');
        }

        // Build team info string
        $team_info = '';
        if ($mentor->team_name) {
            $team_info = $mentor->team_name;
            if ($mentor->team_number) {
                $team_info .= ' (#' . $mentor->team_number . ')';
            }
        }

        $mentor->team_info = $team_info;

        wp_send_json_success($mentor);
    }

    /**
     * AJAX handler for sending mentor email
     */
    public function ajax_send_mentor_email() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'qbo_send_mentor_email')) {
            wp_send_json_error('Security check failed');
        }

        // Verify user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $mentor_id = intval($_POST['mentor_id']);
        $subject = sanitize_text_field($_POST['subject']);
        $message = wp_kses_post($_POST['message']);
        $send_copy = isset($_POST['send_copy']) && $_POST['send_copy'] === 'true';

        // Process images in the message - convert base64 to uploaded files
        $message = $this->process_message_images($message);

        global $wpdb;
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        $table_teams = $wpdb->prefix . 'gears_teams';

        $mentor = $wpdb->get_row($wpdb->prepare("
            SELECT m.*, t.team_name, t.team_number,
                   CONCAT(m.first_name, ' ', m.last_name) as full_name
            FROM {$table_mentors} m
            LEFT JOIN {$table_teams} t ON m.team_id = t.id
            WHERE m.id = %d
        ", $mentor_id));

        if (!$mentor) {
            wp_send_json_error('Mentor not found');
        }

        if (empty($mentor->email)) {
            wp_send_json_error('Mentor does not have an email address');
        }

        // Get current user info
        $current_user = wp_get_current_user();
        $from_name = $current_user->display_name ?: get_bloginfo('name');
        $from_email = $current_user->user_email ?: get_option('admin_email');

        // Use WordPress wp_mail only - skip WPForms to avoid complications
        $email_system = 'WordPress wp_mail()';
        
        // Create detailed debug info
        $debug_info = array(
            'email_system' => $email_system,
            'mentor_email' => $mentor->email,
            'mentor_name' => $mentor->full_name,
            'from_email' => $from_email,
            'from_name' => $from_name,
            'subject' => $subject,
            'wp_mail_available' => function_exists('wp_mail'),
            'php_mail_available' => function_exists('mail'),
            'server_name' => $_SERVER['SERVER_NAME'] ?? 'Unknown'
        );
        
        error_log('GEARS Email Debug: ' . print_r($debug_info, true));

        // Always use WordPress wp_mail
        $sent = $this->send_email_via_wpmail($mentor, $subject, $message, $from_name, $from_email, $send_copy);

        // Create response with detailed info
        $response_data = array(
            'sent' => $sent,
            'email_system' => $email_system,
            'mentor_name' => $mentor->full_name,
            'mentor_email' => $mentor->email,
            'debug_info' => $debug_info
        );

        if ($sent) {
            wp_send_json_success($response_data);
        } else {
            wp_send_json_error($response_data);
        }
    }

    /**
     * Send email using WPForms email system
     */
    private function send_email_via_wpforms($mentor, $subject, $message, $from_name, $from_email, $send_copy = false) {
        try {
            // Check if WPForms Pro is available (has better email features)
            if (class_exists('WPForms_Pro') && method_exists('WPForms_Pro', 'get_instance')) {
                // Use WPForms Pro email notifications
                $wpforms = wpforms();
                
                // Build team info for context
                $team_info = '';
                if ($mentor->team_name) {
                    $team_info = $mentor->team_name;
                    if ($mentor->team_number) {
                        $team_info .= ' (#' . $mentor->team_number . ')';
                    }
                }

                // Format email content
                $email_content = $this->format_email_content($mentor, $message, $team_info, $from_name);
                
                // Prepare email data
                $email_data = array(
                    'to_email' => $mentor->email,
                    'subject' => '[GEARS] ' . $subject,
                    'message' => $email_content,
                    'from_name' => $from_name,
                    'from_email' => $from_email,
                    'reply_to' => $from_email,
                    'content_type' => 'text/html'
                );

                // Add CC if requested
                if ($send_copy) {
                    $email_data['cc'] = $from_email;
                }

                // Use WPForms email notification system
                return wpforms()->get('notifications')->send_notification($email_data);
                
            } else {
                // Fallback to basic WPForms email if Pro not available
                return $this->send_email_via_wpmail($mentor, $subject, $message, $from_name, $from_email, $send_copy);
            }
            
        } catch (Exception $e) {
            error_log('WPForms email error: ' . $e->getMessage());
            // Fallback to wp_mail if WPForms fails
            return $this->send_email_via_wpmail($mentor, $subject, $message, $from_name, $from_email, $send_copy);
        }
    }

    /**
     * Send email using WordPress wp_mail (simplified version)
     */
    private function send_email_via_wpmail($mentor, $subject, $message, $from_name, $from_email, $send_copy = false) {
        // Build team info for email
        $team_info = '';
        if ($mentor->team_name) {
            $team_info = $mentor->team_name;
            if ($mentor->team_number) {
                $team_info .= ' (#' . $mentor->team_number . ')';
            }
        }

        // Create simple text email first to test
        $email_subject = '[GEARS] ' . $subject;
        
        // Simple text email for better compatibility
        $email_message = "Hello " . $mentor->full_name . ",\n\n";
        if ($team_info) {
            $email_message .= "Team: " . $team_info . "\n\n";
        }
        $email_message .= $message . "\n\n";
        $email_message .= "Best regards,\n";
        $email_message .= $from_name . "\n";
        $email_message .= "GEARS Program Administration\n\n";
        $email_message .= "---\n";
        $email_message .= "This email was sent from the GEARS Dashboard.";

        // Simple headers for better compatibility
        $headers = array(
            'From: ' . $from_name . ' <' . $from_email . '>'
        );

        // Add copy to sender if requested
        if ($send_copy) {
            $headers[] = 'Cc: ' . $from_email;
        }

        // Log detailed email attempt
        error_log('GEARS Email Attempt Details:');
        error_log('To: ' . $mentor->email);
        error_log('Subject: ' . $email_subject);
        error_log('From: ' . $from_name . ' <' . $from_email . '>');
        error_log('Message Length: ' . strlen($email_message) . ' characters');
        error_log('Headers: ' . print_r($headers, true));

        // Test if basic mail function exists
        if (!function_exists('wp_mail')) {
            error_log('GEARS Email Error: wp_mail function does not exist');
            return false;
        }

        if (!function_exists('mail')) {
            error_log('GEARS Email Error: PHP mail function does not exist');
            return false;
        }

        // Capture any WordPress mail errors
        $mail_error_message = '';
        $error_handler = function($wp_error) use (&$mail_error_message) {
            $mail_error_message = $wp_error->get_error_message();
            error_log('GEARS wp_mail Error: ' . $mail_error_message);
        };
        
        add_action('wp_mail_failed', $error_handler);

        // Send the email
        $result = wp_mail($mentor->email, $email_subject, $email_message, $headers);
        
        // Remove the error handler
        remove_action('wp_mail_failed', $error_handler);
        
        // Log result
        if ($result) {
            error_log('GEARS Email Success: wp_mail returned true');
        } else {
            error_log('GEARS Email Failed: wp_mail returned false');
            if ($mail_error_message) {
                error_log('GEARS Email Error Message: ' . $mail_error_message);
            }
        }

        return $result;
    }

    /**
     * Format email content with proper HTML structure
     */
    private function format_email_content($mentor, $message, $team_info, $from_name) {
        $email_content = '<html><body>';
        $email_content .= '<div style="font-family: \'Zain\', Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">';
        $email_content .= '<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">';
        $email_content .= '<h2 style="color: #333; margin: 0 0 10px 0;">Hello ' . esc_html($mentor->full_name) . ',</h2>';
        
        if ($team_info) {
            $email_content .= '<p style="color: #666; font-size: 14px; margin: 0;"><strong>Team:</strong> ' . esc_html($team_info) . '</p>';
        }
        $email_content .= '</div>';
        
        $email_content .= '<div style="margin: 20px 0; line-height: 1.6; color: #333;">';
        $email_content .= wp_kses_post($message);
        $email_content .= '</div>';
        
        $email_content .= '<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">';
        $email_content .= '<p style="margin: 0; color: #666;">Best regards,<br>' . esc_html($from_name) . '<br>GEARS Program Administration</p>';
        $email_content .= '</div>';
        
        $email_content .= '<div style="margin-top: 30px; padding: 15px; background: #f0f0f0; border-radius: 4px;">';
        $email_content .= '<p style="color: #888; font-size: 12px; margin: 0;">This email was sent from the GEARS Dashboard administration system.</p>';
        $email_content .= '</div>';
        $email_content .= '</div>';
        $email_content .= '</body></html>';

        return $email_content;
    }

    /**
     * Process base64 images in message content and upload them to WordPress media library
     */
    private function process_message_images($message) {
        // Find all base64 images in the message
        $pattern = '/<img[^>]+src="data:image\/([^;]+);base64,([^"]+)"[^>]*>/i';
        
        return preg_replace_callback($pattern, function($matches) {
            $image_type = $matches[1]; // jpg, png, gif, etc.
            $image_data = $matches[2]; // base64 data
            
            // Validate image type
            $allowed_types = array('jpeg', 'jpg', 'png', 'gif', 'webp');
            if (!in_array(strtolower($image_type), $allowed_types)) {
                return $matches[0]; // Return original if not allowed type
            }
            
            // Decode base64 data
            $image_content = base64_decode($image_data);
            if ($image_content === false) {
                return $matches[0]; // Return original if decode fails
            }
            
            // Create a temporary file
            $upload_dir = wp_upload_dir();
            $filename = 'email_image_' . uniqid() . '.' . $image_type;
            $filepath = $upload_dir['path'] . '/' . $filename;
            
            // Save the file
            if (file_put_contents($filepath, $image_content) === false) {
                return $matches[0]; // Return original if save fails
            }
            
            // Create attachment
            $filetype = wp_check_filetype($filename, null);
            $attachment = array(
                'guid' => $upload_dir['url'] . '/' . basename($filename),
                'post_mime_type' => $filetype['type'],
                'post_title' => sanitize_file_name($filename),
                'post_content' => '',
                'post_status' => 'inherit'
            );
            
            // Insert the attachment
            $attach_id = wp_insert_attachment($attachment, $filepath);
            
            if (is_wp_error($attach_id)) {
                unlink($filepath); // Clean up file if attachment creation fails
                return $matches[0]; // Return original
            }
            
            // Generate attachment metadata
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
            wp_update_attachment_metadata($attach_id, $attach_data);
            
            // Get the URL of the uploaded image
            $image_url = wp_get_attachment_url($attach_id);
            
            // Replace the base64 src with the uploaded image URL
            $img_tag = $matches[0];
            $img_tag = preg_replace('/src="data:image\/[^;]+;base64,[^"]+"/', 'src="' . $image_url . '"', $img_tag);
            
            return $img_tag;
        }, $message);
    }
}
