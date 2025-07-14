/**
 * Admin JavaScript for QBO Recurring Billing Plugin
 */

jQuery(document).ready(function($) {
    // Plugin admin page enhancements
    console.log('QBO Recurring Billing admin scripts loaded');
    
    // Add a class to the body to help identify plugin pages
    $('body').addClass('qbo-admin-page');
    
    // Any additional admin JS can be added here
});

function qboAjax(action, data, successCb, errorCb) {
    data.action = action;
    data.nonce = qbo_ajax.nonce;
    $.post(qbo_ajax.ajax_url, data)
        .done(successCb || function(resp) { console.log('Success:', resp); })
        .fail(errorCb || function() { console.error('AJAX fail'); });
}