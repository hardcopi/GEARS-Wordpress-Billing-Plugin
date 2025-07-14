<?php

trait QBO_Ajax_Handler {
    protected function verify_ajax_nonce($action = 'qbo_ajax_nonce', $field = 'nonce') {
        check_ajax_referer($action, $field);
    }

    protected function send_json_success($data = null) {
        wp_send_json_success($data);
    }

    protected function send_json_error($message = 'Something went wrong') {
        wp_send_json_error($message);
    }

    // For DB error handling
    protected function get_db_error() {
        global $wpdb;
        return $wpdb->last_error ? $wpdb->last_error : 'DB error unknown';
    }

    // If you gotta capture output (try to avoid, but for legacy)
    protected function capture_and_check_output(callable $callback) {
        ob_start();
        call_user_func($callback);
        $output = ob_get_clean();
        if (strpos($output, 'notice-success') !== false) {
            return ['success' => true, 'message' => 'Operation successful!'];
        } else {
            preg_match('/<p>(.*?)<\/p>/', $output, $matches);
            $error = $matches[1] ?? 'Unknown error';
            return ['success' => false, 'message' => $error];
        }
    }
}