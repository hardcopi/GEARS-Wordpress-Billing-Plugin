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
    
    // Any additional admin JS can be added 1111here

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
    });
}
