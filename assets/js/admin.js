/**
 * Admin JavaScript for QBO Recurring Billing Plugin
 */

if (typeof jQuery === 'undefined') {
    console.error('jQuery is not loaded. Please ensure jQuery is enqueued before admin.js.');
} else {
    jQuery(document).ready(function($) {
    // Plugin admin page enhancements
    console.log('QBO Recurring Billing admin scripts loaded');
    
    // Add a class to the body to help identify plugin pages
    $('body').addClass('qbo-admin-page');
    
    // Any additional admin JS can be added here

    // Handler to populate edit student modal fields
    $(document).on('click', '.edit-student-btn', function() {
        console.log("Edit button clicked for student ID:", $(this).data('student-id'));
        var studentId = $(this).data('student-id');
        var student = null;
        if (window.qboStudents && Array.isArray(window.qboStudents)) {
            student = window.qboStudents.find(function(s) { return String(s.student_id) === String(studentId); });
        }
        if (!student) return;

        // Set form fields
        $('#edit-student-first-name').val(student.first_name || '');
        $('#edit-student-last-name').val(student.last_name || '');
        $('#edit-sex').val(student.sex || '');
        // Normalize t-shirt size value to match option values
        var tshirtVal = (student.tshirt_size || '').toUpperCase().trim();
        $('#edit-tshirt-size').val(tshirtVal).trigger('change');
        console.log("Setting t-shirt size to:", tshirtVal);
        $('#edit-student-grade').val(student.grade || '');
        $('#edit-student-id').val(student.student_id || '');
        // If you have other fields, set them here
    });

    // Teams Archive/Restore functionality
    // Handle archive team button clicks
    $(document).on('click', '.archive-team-btn', function() {
        var teamId = $(this).data('team-id');
        var teamName = $(this).data('team-name');
        
        if (confirm('Are you sure you want to move "' + teamName + '" to past teams? This will hide it from the main teams list but preserve all data.')) {
            archiveTeam(teamId);
        }
    });
    
    // Handle restore team button clicks
    $(document).on('click', '.restore-team-btn', function() {
        var teamId = $(this).data('team-id');
        var teamName = $(this).data('team-name');
        
        if (confirm('Are you sure you want to restore "' + teamName + '"? This will make it visible in the main teams list again.')) {
            restoreTeam(teamId);
        }
    });

    // Archive team function
    function archiveTeam(teamId) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'qbo_archive_team',
                team_id: teamId,
                nonce: $('#team_nonce').val() || ''
            },
            beforeSend: function() {
                $('.archive-team-btn[data-team-id="' + teamId + '"]').prop('disabled', true).text('Archiving...');
            },
            success: function(response) {
                if (response.success) {
                    // Refresh the page to update the table
                    location.reload();
                } else {
                    alert('Error archiving team: ' + (response.data || 'Unknown error'));
                    $('.archive-team-btn[data-team-id="' + teamId + '"]').prop('disabled', false).text('Move to Past');
                }
            },
            error: function() {
                alert('Error archiving team. Please try again.');
                $('.archive-team-btn[data-team-id="' + teamId + '"]').prop('disabled', false).text('Move to Past');
            }
        });
    }
    
    // Restore team function
    function restoreTeam(teamId) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'qbo_restore_team',
                team_id: teamId,
                nonce: $('#team_nonce').val() || ''
            },
            beforeSend: function() {
                $('.restore-team-btn[data-team-id="' + teamId + '"]').prop('disabled', true).text('Restoring...');
            },
            success: function(response) {
                if (response.success) {
                    // Refresh the page to update the table
                    location.reload();
                } else {
                    alert('Error restoring team: ' + (response.data || 'Unknown error'));
                    $('.restore-team-btn[data-team-id="' + teamId + '"]').prop('disabled', false).text('Restore');
                }
            },
            error: function() {
                alert('Error restoring team. Please try again.');
                $('.restore-team-btn[data-team-id="' + teamId + '"]').prop('disabled', false).text('Restore');
            }
        });
    }

    // Student Retirement functionality
    // Handle retire student button clicks
    $(document).on('click', '.retire-student-btn', function() {
        var studentId = $(this).data('student-id');
        var studentName = $(this).data('student-name');
        var teamId = $(this).data('team-id');
        
        var confirmMessage = 'Are you sure you want to retire "' + studentName + '"?\n\n';
        confirmMessage += 'This will:\n';
        confirmMessage += '• Change their grade to "Alumni"\n';
        confirmMessage += '• Keep them as an alumni of their current team\n';
        if (teamId && teamId > 0) {
            confirmMessage += '• Add their current team to their history with reason "Retired"\n';
        }
        confirmMessage += '\nThis action cannot be easily undone.';
        
        if (confirm(confirmMessage)) {
            retireStudent(studentId, studentName);
        }
    });

    // Retire student function
    function retireStudent(studentId, studentName) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'qbo_retire_student',
                student_id: studentId,
                nonce: qbo_ajax.nonce || ''
            },
            beforeSend: function() {
                $('.retire-student-btn[data-student-id="' + studentId + '"]').prop('disabled', true).text('Retiring...');
            },
            success: function(response) {
                if (response.success) {
                    alert('Student retired successfully!\n\n' + (response.data.message || ''));
                    // Refresh the page to update the table
                    location.reload();
                } else {
                    alert('Error retiring student: ' + (response.data.message || 'Unknown error'));
                    $('.retire-student-btn[data-student-id="' + studentId + '"]').prop('disabled', false).text('Retire');
                }
            },
            error: function() {
                alert('Error retiring student. Please try again.');
                $('.retire-student-btn[data-student-id="' + studentId + '"]').prop('disabled', false).text('Retire');
            }
        });
    }

    });
}
