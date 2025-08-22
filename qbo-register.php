<?php
// Show PHP errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Board members who can access any team's data
$board_members = [
    'hardcopi@gmail.com',
    'michelleannelester@gmail.com',
    'scott@gears.org.in'
];

// Standalone QuickBooks Account Register Viewer
// Place this file in your plugin directory and access directly (e.g., /wp-content/plugins/your-plugin/qbo-register.php)
// Usage: qbo-register.php?account_id=144

// To make this respond to /mentors, add the following to your main plugin file (e.g., qbo-recurring-billing.php):
/*
add_action('init', function() {
    add_rewrite_rule('^mentors/?$', 'wp-content/plugins/qbo-recurring-billing/qbo-register.php', 'top');
});
*/
// Then, go to Settings > Permalinks in WordPress admin and click Save Changes to flush rewrite rules.

// Start PHP session for Google login
if (!session_id()) {
    session_start();
}

// Debug: Check if WordPress is already loaded
error_log('QBO Debug: WordPress functions available: ' . (function_exists('wp_remote_post') ? 'YES' : 'NO'));
error_log('QBO Debug: ABSPATH defined: ' . (defined('ABSPATH') ? 'YES' : 'NO'));

// WordPress should already be loaded when called through template_redirect
// Only attempt to load WordPress if functions aren't available and we're being accessed directly
if (!function_exists('wp_remote_post') && !defined('ABSPATH')) {
    error_log('QBO Debug: Attempting to load WordPress manually');
    // Try different paths for direct access
    $wp_load_paths = [
        '../../../wp-load.php',      // Standard path from plugin directory
        '../../../../wp-load.php',   // Some hosting configurations
    ];
    
    foreach ($wp_load_paths as $path) {
        if (file_exists($path)) {
            error_log('QBO Debug: Loading WordPress from: ' . $path);
            require_once($path);
            break;
        }
    }
    
    // Final check
    error_log('QBO Debug: After manual load - WordPress functions available: ' . (function_exists('wp_remote_post') ? 'YES' : 'NO'));
} else {
    error_log('QBO Debug: WordPress already available, no manual loading needed');
}

// Ensure WordPress is fully loaded before proceeding
if (!function_exists('wp_remote_post') || !function_exists('sanitize_text_field') || !defined('ABSPATH')) {
    error_log('QBO Debug: WordPress not properly loaded, showing error');
    die('WordPress environment not available. This page requires WordPress to be loaded.');
}

// Handle AJAX image upload
if (isset($_POST['action']) && $_POST['action'] === 'qbo_upload_team_image') {
    header('Content-Type: application/json');
    
    // Debug: Log that we're in the AJAX handler
    error_log('AJAX handler triggered for image upload');
    
    // Simple test response first
    if (!function_exists('wp_verify_nonce')) {
        echo json_encode(['success' => false, 'data' => 'WordPress functions not available']);
        exit;
    }
    
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'qbo_upload_team_image')) {
        error_log('Nonce verification failed');
        http_response_code(403);
        echo json_encode(['success' => false, 'data' => 'Security check failed.']);
        exit;
    }
    
    // Check if user is logged in via Google
    if (!isset($_SESSION['google_logged_in']) || $_SESSION['google_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['success' => false, 'data' => 'Authentication required.']);
        exit;
    }
    
    // Verify mentor permissions or board member access
    global $wpdb;
    $table_mentors = $wpdb->prefix . 'gears_mentors';
    $google_email = $_SESSION['google_user_email'] ?? '';
    
    // Check if user is a board member
    $is_board_member = in_array($google_email, $board_members);
    
    if (!$is_board_member) {
        // If not a board member, check mentor permissions
        $mentor = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_mentors WHERE email = %s", $google_email));
        
        if (!$mentor || !$mentor->team_id || $mentor->team_id != intval($_POST['team_id'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'data' => 'Access denied.']);
            exit;
        }
    }
    
    // Handle file upload
    if (!isset($_FILES['image_file']) || $_FILES['image_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'data' => 'No file uploaded or upload error.']);
        exit;
    }
    
    $image_type = sanitize_text_field($_POST['image_type']);
    if (!in_array($image_type, ['logo', 'team_photo'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'data' => 'Invalid image type.']);
        exit;
    }
    
    // Use WordPress media upload
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    
    $uploaded = media_handle_upload('image_file', 0, [], ['test_form' => false]);
    
    if (is_wp_error($uploaded)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'data' => $uploaded->get_error_message()]);
        exit;
    }
    
    $image_url = wp_get_attachment_url($uploaded);
    if (!$image_url) {
        http_response_code(500);
        echo json_encode(['success' => false, 'data' => 'Failed to get uploaded image URL.']);
        exit;
    }
    
    // Update team record
    $table_teams = $wpdb->prefix . 'gears_teams';
    $update_data = [$image_type => $image_url];
    $result = $wpdb->update($table_teams, $update_data, ['id' => intval($_POST['team_id'])], ['%s'], ['%d']);
    
    if ($result === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'data' => 'Failed to update team record.']);
        exit;
    }
    
    http_response_code(200);
    echo json_encode(['success' => true, 'data' => ['url' => $image_url]]);
    exit;
}

// Handle useful links save
if (isset($_POST['action']) && $_POST['action'] === 'save_useful_link') {
    header('Content-Type: application/json');
    
    // Check if user is logged in via Google
    if (!isset($_SESSION['google_logged_in']) || $_SESSION['google_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
    
    // Verify mentor permissions or board member access
    global $wpdb;
    $table_mentors = $wpdb->prefix . 'gears_mentors';
    $table_teams = $wpdb->prefix . 'gears_teams';
    $google_email = $_SESSION['google_user_email'] ?? '';
    
    // Check if user is a board member
    $is_board_member = in_array($google_email, $board_members);
    
    if (!$is_board_member) {
        // If not a board member, check mentor permissions
        $mentor = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_mentors WHERE email = %s", $google_email));
        
        if (!$mentor || !$mentor->team_id || $mentor->team_id != intval($_POST['team_id'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied.']);
            exit;
        }
    }
    
    // Validate input
    $program = sanitize_text_field($_POST['program']);
    $name = sanitize_text_field($_POST['name']);
    $url = esc_url_raw($_POST['url']);
    $description = sanitize_textarea_field($_POST['description']);
    $team_id = intval($_POST['team_id']);
    $link_index = isset($_POST['link_index']) && $_POST['link_index'] !== '' ? intval($_POST['link_index']) : null;
    
    if (!in_array($program, ['FLL', 'FTC']) || empty($name) || empty($url)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input data.']);
        exit;
    }
    
    // Get current team data
    $team = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_teams WHERE id = %d", $team_id));
    if (!$team) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Team not found.']);
        exit;
    }
    
    // Get existing links
    $existing_links = $team->useful_links ? json_decode($team->useful_links, true) : [];
    if (!is_array($existing_links)) {
        $existing_links = [];
    }
    
    // Create new link data
    $link_data = [
        'program' => $program,
        'name' => $name,
        'url' => $url,
        'description' => $description
    ];
    
    // Add or update link
    if ($link_index !== null && isset($existing_links[$link_index])) {
        // Update existing link
        $existing_links[$link_index] = $link_data;
    } else {
        // Add new link
        $existing_links[] = $link_data;
    }
    
    // Save to database
    $result = $wpdb->update(
        $table_teams,
        ['useful_links' => json_encode($existing_links)],
        ['id' => $team_id],
        ['%s'],
        ['%d']
    );
    
    if ($result === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save link.']);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => 'Link saved successfully.']);
    exit;
}

// Handle useful links delete
if (isset($_POST['action']) && $_POST['action'] === 'delete_useful_link') {
    header('Content-Type: application/json');
    
    // Check if user is logged in via Google
    if (!isset($_SESSION['google_logged_in']) || $_SESSION['google_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
    
    // Verify mentor permissions or board member access
    global $wpdb;
    $table_mentors = $wpdb->prefix . 'gears_mentors';
    $table_teams = $wpdb->prefix . 'gears_teams';
    $google_email = $_SESSION['google_user_email'] ?? '';
    
    // Check if user is a board member
    $is_board_member = in_array($google_email, $board_members);
    
    if (!$is_board_member) {
        // If not a board member, check mentor permissions
        $mentor = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_mentors WHERE email = %s", $google_email));
        
        if (!$mentor || !$mentor->team_id || $mentor->team_id != intval($_POST['team_id'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied.']);
            exit;
        }
    }
    
    $team_id = intval($_POST['team_id']);
    $link_index = intval($_POST['link_index']);
    
    // Get current team data
    $team = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_teams WHERE id = %d", $team_id));
    if (!$team) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Team not found.']);
        exit;
    }
    
    // Get existing links
    $existing_links = $team->useful_links ? json_decode($team->useful_links, true) : [];
    if (!is_array($existing_links)) {
        $existing_links = [];
    }
    
    // Remove the link
    if (isset($existing_links[$link_index])) {
        array_splice($existing_links, $link_index, 1);
        
        // Save to database
        $result = $wpdb->update(
            $table_teams,
            ['useful_links' => json_encode($existing_links)],
            ['id' => $team_id],
            ['%s'],
            ['%d']
        );
        
        if ($result === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to delete link.']);
            exit;
        }
        
        echo json_encode(['success' => true, 'message' => 'Link deleted successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Link not found.']);
    }
    exit;
}

// Google OAuth credentials - REPLACE WITH YOUR OWN FROM GOOGLE CONSOLE
$google_client_id = '44830820494-vtuothjvb74ms5bqbfihr3bn53l54f4u.apps.googleusercontent.com'; // e.g., 'xxxx.apps.googleusercontent.com'
$google_client_secret = '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';
$google_redirect_uri = 'https://gears.org.in/wp-content/plugins/qbo-recurring-billing/qbo-register.php'; // Must match exactly in Google Console

// Handle Google OAuth callback
if (isset($_GET['code'])) {
    $token_url = 'https://oauth2.googleapis.com/token';
    $post_data = array(
        'code' => $_GET['code'],
        'client_id' => $google_client_id,
        'client_secret' => $google_client_secret,
        'redirect_uri' => $google_redirect_uri,
        'grant_type' => 'authorization_code',
    );
    $args = array(
        'body' => $post_data,
        'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
    );
    $response = wp_remote_post($token_url, $args);
    if (!is_wp_error($response)) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['access_token'])) {
            // Get user info
            $userinfo_url = 'https://www.googleapis.com/oauth2/v1/userinfo?access_token=' . $body['access_token'];
            $userinfo_response = wp_remote_get($userinfo_url);
            if (!is_wp_error($userinfo_response)) {
                $userinfo = json_decode(wp_remote_retrieve_body($userinfo_response), true);
                if (isset($userinfo['email'])) {
                    $_SESSION['google_logged_in'] = true;
                    $_SESSION['google_user_email'] = $userinfo['email'];
                    // Redirect to remove code from URL
                    header('Location: ' . $google_redirect_uri . (isset($_GET['account_id']) ? '?account_id=' . $_GET['account_id'] : ''));
                    exit;
                }
            }
        }
    }
}

// Check if logged in
if (!isset($_SESSION['google_logged_in']) || $_SESSION['google_logged_in'] !== true) {
    // Show login button
    $login_url = 'https://accounts.google.com/o/oauth2/v2/auth?scope=email&access_type=offline&include_granted_scopes=true&response_type=code&redirect_uri=' . urlencode($google_redirect_uri) . '&client_id=' . $google_client_id;
    echo '<!DOCTYPE html><html><head><title>Login Required</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"></head><body class="bg-light"><div class="container my-5 text-center"><h2>Login Required</h2><p>Please log in with Google to view the account register.</p><a href="' . $login_url . '" class="btn btn-primary">Login with Google</a></div></body></html>';
    exit;
}

// Mentor check and account_id selection
require_once(dirname(__FILE__) . '/includes/class-qbo-teams.php');
$google_email = $_SESSION['google_user_email'] ?? '';
if (!$google_email) {
    echo '<h2>Error: No Google email found.</h2>';
    exit;
}

global $wpdb;
$table_mentors = $wpdb->prefix . 'gears_mentors';
$table_teams = $wpdb->prefix . 'gears_teams';

// Check if user is a board member
$is_board_member = in_array($google_email, $board_members);

if ($is_board_member) {
    // Board members can view any team
    $selected_team_id = isset($_GET['team_id']) ? intval($_GET['team_id']) : null;
    
    if (!$selected_team_id) {
        // Show team selection page for board members (exclude archived teams)
        $all_teams = $wpdb->get_results("SELECT * FROM $table_teams WHERE (archived = 0 OR archived IS NULL) ORDER BY team_name");
        
        echo '<!DOCTYPE html><html><head><title>Select Team - Board Access</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"></head><body class="bg-light"><div class="container my-5"><div class="row justify-content-center"><div class="col-md-8"><div class="card shadow-lg"><div class="card-header bg-primary text-white text-center"><h3><i class="bi bi-shield-check me-2"></i>Board Member Access</h3><p class="mb-0">Select a team to view their information</p></div><div class="card-body"><div class="mb-3"><label class="form-label fw-bold">Available Teams:</label><div class="list-group">';
        
        foreach ($all_teams as $team_option) {
            $team_url = $_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') !== false ? '&' : '?') . 'team_id=' . $team_option->id;
            $has_bank = (!empty($team_option->bank_account_id)) ? '<i class="bi bi-bank text-success me-1"></i>' : '<i class="bi bi-bank text-muted me-1"></i>';
            $bank_status = (!empty($team_option->bank_account_id)) ? '<span class="badge bg-success ms-2">Bank Connected</span>' : '<span class="badge bg-secondary ms-2">No Bank</span>';
            echo '<a href="' . htmlentities($team_url) . '" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"><div>' . $has_bank . '<strong>' . htmlentities($team_option->team_name) . '</strong>' . $bank_status . '<br><small class="text-muted">Team #' . htmlentities($team_option->team_number) . ' - ' . htmlentities($team_option->program) . '</small></div><i class="bi bi-arrow-right"></i></a>';
        }
        
        echo '</div></div></div></div></div></div></div></body></html>';
        exit;
    }
    
    // Load selected team for board member
    $team = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_teams WHERE id = %d", $selected_team_id));
    if (!$team) {
        echo '<h2>Error: Selected team not found.</h2>';
        exit;
    }
} else {
    // Regular mentor access - check if they belong to a team
    $mentor = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_mentors WHERE email = %s", $google_email));
    if (!$mentor || !$mentor->team_id) {
        echo '<!DOCTYPE html><html><head><title>Access Denied</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-dark text-light"><div class="container my-5 text-center"><div class="alert alert-danger shadow-lg p-4" style="font-size:2rem;border-width:3px;border-color:#dc3545;"><span class="display-3 fw-bold text-danger">&#9888;</span><h2 class="fw-bold mt-3">ACCESS DENIED</h2><p class="lead">You must be a <span class="fw-bold text-danger">GEARS mentor</span> to access team information.<br><span class="text-danger">This incident will be reported.</span></p></div></div></body></html>';
        exit;
    }
    $team = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_teams WHERE id = %d", $mentor->team_id));
    if (!$team || empty($team->bank_account_id)) {
        echo '<h2>Error: No bank account associated with your team.</h2>';
        exit;
    }
}

$account_id = $team->bank_account_id;

// Check if team has a bank account for financial data
$has_bank_account = !empty($account_id);

// Handle cache refresh request
$force_refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
if ($force_refresh) {
    // Clear cached data for this account
    delete_transient('qbo_account_data_' . $account_id);
    delete_transient('qbo_transactions_' . $account_id);
    delete_transient('qbo_cache_time_' . $account_id);
    
    // Redirect to remove the refresh parameter from URL
    $redirect_url = strtok($_SERVER["REQUEST_URI"], '?');
    if (isset($_GET['account_id'])) {
        $redirect_url .= '?account_id=' . urlencode($_GET['account_id']);
    }
    header('Location: ' . $redirect_url);
    exit;
}

if ($has_bank_account) {
    // Get QBO credentials from plugin options
    $options = get_option('qbo_recurring_billing_options');
    if (!isset($options['access_token']) || !isset($options['realm_id'])) {
        echo '<h2>Error: QuickBooks credentials not found.</h2>';
        exit;
    }
    $access_token = $options['access_token'];
    $realm_id = $options['realm_id'];

    // Helper to make QBO API requests
    function qbo_api_request($endpoint, $access_token, $realm_id) {
        $url = 'https://quickbooks.api.intuit.com/v3/company/' . $realm_id . $endpoint;
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json',
            ),
            'timeout' => 30,
        );
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) return false;
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    // Try to get cached account data first (cache for 24 hours)
    $cache_key_account = 'qbo_account_data_' . $account_id;
    $account_data = get_transient($cache_key_account);
    $cache_timestamp_key = 'qbo_cache_time_' . $account_id;
    $cache_time = get_transient($cache_timestamp_key);
    
    if ($account_data === false) {
        // Cache miss - fetch from QuickBooks API
        $account_data = qbo_api_request('/account/' . urlencode($account_id), $access_token, $realm_id);
        if ($account_data && isset($account_data['Account'])) {
            // Cache for 24 hours (86400 seconds)
            set_transient($cache_key_account, $account_data, 86400);
            set_transient($cache_timestamp_key, current_time('mysql'), 86400);
            $cache_time = current_time('mysql');
        }
    }
    
    if (!$account_data || !isset($account_data['Account'])) {
        echo '<h2>Error: Account not found in QuickBooks.</h2>';
        exit;
    }
    $account_name = $account_data['Account']['Name'];
    $account_type = $account_data['Account']['AccountType'];
    $account_balance = isset($account_data['Account']['CurrentBalance']) ? $account_data['Account']['CurrentBalance'] : 0;

    // Try to get cached transactions data first (cache for 24 hours)
    $cache_key_transactions = 'qbo_transactions_' . $account_id;
    $entries = get_transient($cache_key_transactions);
    
    if ($entries === false) {
        // Cache miss - fetch transactions from QuickBooks API
        $types = array(
            'Purchase', 'JournalEntry', 'Deposit', 'Transfer', 'BillPayment', 'VendorCredit', 'CreditCardPayment', 'Payment', 'SalesReceipt'
        );
        $entries = array();
foreach ($types as $type) {
    $q = "SELECT * FROM $type ORDER BY TxnDate DESC MAXRESULTS 200";
    $endpoint = '/query?query=' . urlencode($q) . '&minorversion=65';
    $data = qbo_api_request($endpoint, $access_token, $realm_id);
    if (!$data || !isset($data['QueryResponse'][$type])) continue;
    foreach ($data['QueryResponse'][$type] as $txn) {
        // Filtering logic for each type
        if ($type === 'Purchase' && isset($txn['AccountRef']['value']) && $txn['AccountRef']['value'] == $account_id) {
            $date = $txn['TxnDate'] ?? '';
            $payee = $txn['EntityRef']['name'] ?? '';
            $desc = $txn['PrivateNote'] ?? '';
            if (empty($desc) && isset($txn['Line']) && is_array($txn['Line'])) {
                $desc = implode('; ', array_filter(array_map(function($line) { return $line['Description'] ?? ''; }, $txn['Line'])));
            }
            $amount = -abs((float)$txn['TotalAmt'] ?? 0);
            $display_type = (isset($txn['PaymentType']) && $txn['PaymentType'] === 'Check') ? 'Check' : 'Expenditure';
            $entries[] = array(
                'date' => $date,
                'type' => $display_type,
                'payee' => $payee,
                'desc' => $desc,
                'amount' => $amount,
            );
        } elseif ($type === 'JournalEntry' && isset($txn['Line'])) {
            foreach ($txn['Line'] as $line) {
                if (isset($line['JournalEntryLineDetail']['AccountRef']['value']) && $line['JournalEntryLineDetail']['AccountRef']['value'] == $account_id) {
                    $date = $txn['TxnDate'] ?? '';
                    $payee = isset($line['JournalEntryLineDetail']['Entity']['Name']) ? $line['JournalEntryLineDetail']['Entity']['Name'] : '';
                    $desc = $txn['PrivateNote'] ?? ($line['Description'] ?? '');
                    $posting_type = $line['JournalEntryLineDetail']['PostingType'] ?? '';
                    $amount = (float)($line['Amount'] ?? 0);
                    $sign = ($posting_type === 'Debit') ? 1 : -1; // Debit increases bank balance
                    $entries[] = array(
                'date' => $date,
                'type' => $type,
                'payee' => $payee,
                'desc' => $desc,
                'amount' => $sign * $amount,
            );
                }
            }
        } elseif ($type === 'Deposit' && isset($txn['DepositToAccountRef']['value']) && $txn['DepositToAccountRef']['value'] == $account_id) {
            $date = $txn['TxnDate'] ?? '';
            $payee = '';
            if (isset($txn['Line']) && is_array($txn['Line'])) {
                foreach ($txn['Line'] as $line) {
                    if (isset($line['DepositLineDetail']['EntityRef']['name'])) {
                        $payee = $line['DepositLineDetail']['EntityRef']['name'];
                        break;
                    }
                }
            }
            $desc = $txn['PrivateNote'] ?? '';
            $amount = abs((float)$txn['TotalAmt'] ?? 0);
            $entries[] = array(
                'date' => $date,
                'type' => $type,
                'payee' => $payee,
                'desc' => $desc,
                'amount' => $amount,
            );
        } elseif ($type === 'Transfer') {
            $date = $txn['TxnDate'] ?? '';
            $desc = $txn['PrivateNote'] ?? '';
            $amount = (float)$txn['Amount'] ?? 0;
            if (isset($txn['FromAccountRef']['value']) && $txn['FromAccountRef']['value'] == $account_id) {
                $payee = $txn['ToAccountRef']['name'] ?? '';
                $entries[] = array(
                    'date' => $date,
                    'type' => $type,
                    'payee' => $payee,
                    'desc' => $desc,
                    'amount' => -abs($amount),
                );
            }
            if (isset($txn['ToAccountRef']['value']) && $txn['ToAccountRef']['value'] == $account_id) {
                $payee = $txn['FromAccountRef']['name'] ?? '';
                $entries[] = array(
                    'date' => $date,
                    'type' => $type,
                    'payee' => $payee,
                    'desc' => $desc,
                    'amount' => abs($amount),
                );
            }
        } elseif ($type === 'BillPayment' && isset($txn['PayType']) && $txn['PayType'] === 'Check' && isset($txn['CheckPayment']['BankAccountRef']['value']) && $txn['CheckPayment']['BankAccountRef']['value'] == $account_id) {
            $date = $txn['TxnDate'] ?? '';
            $payee = $txn['VendorRef']['name'] ?? '';
            $desc = $txn['PrivateNote'] ?? '';
            $amount = -abs((float)$txn['TotalAmt'] ?? 0);
            $entries[] = array(
                'date' => $date,
                'type' => 'Check', // Or 'BillPayment'
                'payee' => $payee,
                'desc' => $desc,
                'amount' => $amount,
            );
        } elseif ($type === 'VendorCredit' && isset($txn['APAccountRef']['value']) && $txn['APAccountRef']['value'] == $account_id) {
            $date = $txn['TxnDate'] ?? '';
            $payee = $txn['VendorRef']['name'] ?? '';
            $desc = $txn['PrivateNote'] ?? '';
            $amount = abs((float)$txn['TotalAmt'] ?? 0);
            $entries[] = array(
                'date' => $date,
                'type' => $type,
                'payee' => $payee,
                'desc' => $desc,
                'amount' => $amount,
            );
        } elseif ($type === 'CreditCardPayment' && isset($txn['CreditCardAccountRef']['value']) && $txn['CreditCardAccountRef']['value'] == $account_id) {
            $date = $txn['TxnDate'] ?? '';
            $payee = $txn['EntityRef']['name'] ?? '';
            $desc = $txn['PrivateNote'] ?? '';
            $amount = -abs((float)$txn['TotalAmt'] ?? 0);
            $entries[] = array(
                'date' => $date,
                'type' => $type,
                'payee' => $payee,
                'desc' => $desc,
                'amount' => $amount,
            );
        } elseif ($type === 'Payment' && isset($txn['DepositToAccountRef']['value']) && $txn['DepositToAccountRef']['value'] == $account_id) {
            $date = $txn['TxnDate'] ?? '';
            $payee = $txn['CustomerRef']['name'] ?? '';
            $desc = $txn['PrivateNote'] ?? '';
            $amount = abs((float)$txn['TotalAmt'] ?? 0);
            $entries[] = array(
                'date' => $date,
                'type' => 'Deposit',
                'payee' => $payee,
                'desc' => $desc,
                'amount' => $amount,
            );
        } elseif ($type === 'SalesReceipt' && isset($txn['DepositToAccountRef']['value']) && $txn['DepositToAccountRef']['value'] == $account_id) {
            $date = $txn['TxnDate'] ?? '';
            $payee = $txn['CustomerRef']['name'] ?? '';
            $desc = $txn['PrivateNote'] ?? '';
            $amount = abs((float)$txn['TotalAmt'] ?? 0);
            $entries[] = array(
                'date' => $date,
                'type' => 'Deposit',
                'payee' => $payee,
                'desc' => $desc,
                'amount' => $amount,
            );
        }
    }
}

        // Cache the transactions for 24 hours (86400 seconds)
        set_transient($cache_key_transactions, $entries, 86400);
    }

// Sort by date descending
usort($entries, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// Compute running balances (starting from current balance, working backwards)
$running_balance = $account_balance;
foreach ($entries as &$entry) {
    $entry['balance'] = $running_balance;
    $running_balance -= $entry['amount'];
}
unset($entry); // Unset reference

} else {
    // No bank account - set default values for display
    $account_name = 'No Bank Account';
    $account_type = 'N/A';
    $account_balance = 0;
    $entries = array();
}

// Fetch team data for tabs
$mentors = $wpdb->get_results("SELECT * FROM $table_mentors WHERE team_id = " . intval($team->id));
$students = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gears_students WHERE team_id = " . intval($team->id) . " AND grade != 'Alumni'");
$alumni = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gears_students WHERE team_id = " . intval($team->id) . " AND grade = 'Alumni'");

// Output HTML as Bootstrap tabbed page
?><!DOCTYPE html>
<html><head>
<title>QuickBooks Account Register: <?php echo htmlentities($account_name); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

<?php
// Load WordPress media scripts for the media uploader
if (function_exists('wp_enqueue_media')) {
    wp_enqueue_media();
}
// Output any enqueued scripts/styles
if (function_exists('wp_head')) {
    wp_head();
}
?>

<style>
  .animated-header {
    background: linear-gradient(90deg, #f8f9fa 0%, #e3e6ff 50%, #f8f9fa 100%);
    background-size: 200% 200%;
    animation: gradientMove 6s ease-in-out infinite;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(80,80,120,0.08);
  }
  @keyframes gradientMove {
    0% {background-position: 0% 50%;}
    50% {background-position: 100% 50%;}
    100% {background-position: 0% 50%;}
  }
  .fade-in {
    opacity: 0;
    animation: fadeInLogo 1.2s ease forwards;
  }
  @keyframes fadeInLogo {
    to { opacity: 1; }
  }
  .tab-pane {
    animation: fadeInTab 0.7s;
  }
  @keyframes fadeInTab {
    from { opacity: 0; }
    to { opacity: 1; }
  }
  .nav-tabs .nav-link:hover {
    background: #e3e6ff;
    color: #2c3e50;
    transition: background 0.2s, color 0.2s;
  }
  .table-hover tbody tr:hover {
    background: #f0f4ff;
    transition: background 0.2s;
  }
  .upload-placeholder {
    border: 2px dashed #dee2e6;
    background: #f8f9fa;
    transition: all 0.3s ease;
  }
  .upload-placeholder:hover {
    border-color: #0d6efd;
    background: #e7f3ff;
  }
  .btn-upload {
    transition: all 0.2s ease;
  }
  .btn-upload:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  }
</style>
</head><body class="bg-light">

<div class="container my-4">
<div class="animated-header d-flex justify-content-between align-items-center mb-3 px-3 py-2" style="min-height:80px;">
  <div class="d-flex align-items-center">
    <img src="https://gears.org.in/wp-content/uploads/2023/11/gears-logo-transparent-white.png" alt="GEARS Logo" class="fade-in" style="height:64px;max-width:180px;object-fit:contain;background:transparent;border-radius:8px;padding:6px;">
    <?php if ($is_board_member): ?>
    <div class="ms-3">
      <select class="form-select form-select-sm" onchange="window.location.href=this.value" style="min-width: 200px;">
        <option value="">Switch Team...</option>
        <?php 
        $all_teams_for_dropdown = $wpdb->get_results("SELECT * FROM $table_teams WHERE (archived = 0 OR archived IS NULL) ORDER BY team_name");
        foreach ($all_teams_for_dropdown as $team_option): 
          $team_url = $_SERVER['REQUEST_URI'];
          $team_url = preg_replace('/[?&]team_id=\d+/', '', $team_url);
          $team_url .= (strpos($team_url, '?') !== false ? '&' : '?') . 'team_id=' . $team_option->id;
          $selected = ($team_option->id == $team->id) ? 'selected' : '';
        ?>
          <option value="<?php echo htmlentities($team_url); ?>" <?php echo $selected; ?>>
            <?php echo htmlentities($team_option->team_name); ?> (#<?php echo htmlentities($team_option->team_number); ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <small class="text-muted d-block mt-1"><i class="bi bi-shield-check"></i> Board Access</small>
    </div>
    <?php endif; ?>
  </div>
  <?php if (!empty($team->logo)): ?>
    <div class="d-flex align-items-center justify-content-end">
      <img src="<?php echo esc_url($team->logo); ?>" alt="Team Logo" class="fade-in" style="height:64px;max-width:180px;object-fit:contain;border-radius:8px;background:#f8f9fa;padding:6px;">
    </div>
  <?php endif; ?>
</div>
<div class="row mb-4">
  <div class="col-md-6 mx-auto">
    <div class="card shadow-lg border-0 animate__animated animate__fadeInDown" style="min-height: 150px;">
      <div class="card-body text-center">
        <h3 class="card-title mb-2"><?php echo htmlentities($account_name); ?> 
          <?php if ($has_bank_account): ?>
            <span class="badge bg-primary ms-2" style="font-size:1rem;"><?php echo htmlentities($account_type); ?></span>
          <?php else: ?>
            <span class="badge bg-secondary ms-2" style="font-size:1rem;">No Bank Account</span>
          <?php endif; ?>
        </h3>
        <?php if ($has_bank_account): ?>
          <p class="card-text mb-2"><strong>Current Balance:</strong> <span class="fs-4 text-success">$<?php echo number_format($account_balance, 2); ?></span></p>
          <div class="d-flex justify-content-center align-items-center gap-3">
            <small class="text-muted">
              <i class="bi bi-clock me-1"></i>
              <?php if ($cache_time): ?>
                Last updated: <?php echo date('M j, Y g:i A', strtotime($cache_time)); ?>
              <?php else: ?>
                Data cached for faster loading
              <?php endif; ?>
            </small>
            <a href="?refresh=1<?php echo isset($_GET['account_id']) ? '&account_id=' . urlencode($_GET['account_id']) : ''; ?>" 
               class="btn btn-outline-primary btn-sm" 
               title="Refresh account data from QuickBooks">
              <i class="bi bi-arrow-clockwise me-1"></i>Refresh Account
            </a>
          </div>
        <?php else: ?>
          <p class="card-text mb-0 text-muted">This team does not have banking information configured.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-6 mx-auto">
    <div class="card shadow-lg border-0 animate__animated animate__fadeInDown" style="min-height: 150px;">
      <div class="card-body text-center">
        If you have billing questions or website questions or comments<br>
        please email: <a href="mailto:gearsosceola@gmail.com">gearsosceola@gmail.com</a><br>
        <br>
        If you have GEARS program-related questions,<br>
        please contact <a href="mailto:scott@gears.org.in">scott@gears.org.in</a>
      </div>
    </div>
  </div>
</div>

<ul class="nav nav-tabs mb-3" id="registerTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="team-info-tab" data-bs-toggle="tab" data-bs-target="#team-info" type="button" role="tab" aria-controls="team-info" aria-selected="true">Team Info</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="communication-tab" data-bs-toggle="tab" data-bs-target="#communication" type="button" role="tab" aria-controls="communication" aria-selected="false">Communication</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#register" type="button" role="tab" aria-controls="register" aria-selected="false">Bank Register</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="mentors-tab" data-bs-toggle="tab" data-bs-target="#mentors" type="button" role="tab" aria-controls="mentors" aria-selected="false">Mentors</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="students-tab" data-bs-toggle="tab" data-bs-target="#students" type="button" role="tab" aria-controls="students" aria-selected="false">Students</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="alumni-tab" data-bs-toggle="tab" data-bs-target="#alumni" type="button" role="tab" aria-controls="alumni" aria-selected="false">Alumni</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="useful-links-tab" data-bs-toggle="tab" data-bs-target="#useful-links" type="button" role="tab" aria-controls="useful-links" aria-selected="false">Useful Links</button>
  </li>
</ul>
<div class="tab-content" id="registerTabsContent">
  <div class="tab-pane fade show active" id="team-info" role="tabpanel" aria-labelledby="team-info-tab">
    <div class="row">
      <div class="col-lg-7">
        <?php if (!empty($team->logo) || !empty($team->team_photo)): ?>
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-secondary text-white">
            <h6 class="mb-0"><i class="bi bi-images me-2"></i>Team Images</h6>
          </div>
          <div class="card-body text-center">
            <?php if (!empty($team->logo)): ?>
            <div class="mb-3">
              <h6 class="text-muted mb-2">Team Logo</h6>
              <img src="<?php echo esc_url($team->logo); ?>" alt="Team Logo" class="img-fluid rounded shadow-sm mb-2" style="max-height: 150px; max-width: 100%; object-fit: contain;" id="team-logo-img">
              <div>
                <button type="button" class="btn btn-sm btn-outline-primary btn-upload" onclick="document.getElementById('logo-upload').click()">
                  <i class="bi bi-upload"></i> Replace Logo
                </button>
                <input type="file" id="logo-upload" accept="image/*" style="display: none;" onchange="uploadImage('logo')">
              </div>
            </div>
            <?php else: ?>
            <div class="mb-3">
              <h6 class="text-muted mb-2">Team Logo</h6>
              <div class="border border-dashed rounded p-4 mb-2 upload-placeholder" style="min-height: 150px; display: flex; align-items: center; justify-content: center;">
                <span class="text-muted">No logo uploaded</span>
              </div>
              <div>
                <button type="button" class="btn btn-sm btn-primary btn-upload" onclick="document.getElementById('logo-upload').click()">
                  <i class="bi bi-upload"></i> Upload Logo
                </button>
                <input type="file" id="logo-upload" accept="image/*" style="display: none;" onchange="uploadImage('logo')">
              </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($team->team_photo)): ?>
            <div class="mb-3">
              <h6 class="text-muted mb-2">Team Photo</h6>
              <img src="<?php echo esc_url($team->team_photo); ?>" alt="Team Photo" class="img-fluid rounded shadow-sm mb-2" style="max-height: 200px; max-width: 100%; object-fit: cover;" id="team-photo-img">
              <div>
                <button type="button" class="btn btn-sm btn-outline-primary btn-upload" onclick="document.getElementById('photo-upload').click()">
                  <i class="bi bi-upload"></i> Replace Photo
                </button>
                <input type="file" id="photo-upload" accept="image/*" style="display: none;" onchange="uploadImage('team_photo')">
              </div>
            </div>
            <?php else: ?>
            <div class="mb-3">
              <h6 class="text-muted mb-2">Team Photo</h6>
              <div class="border border-dashed rounded p-4 mb-2 upload-placeholder" style="min-height: 200px; display: flex; align-items: center; justify-content: center;">
                <span class="text-muted">No photo uploaded</span>
              </div>
              <div>
                <button type="button" class="btn btn-sm btn-primary btn-upload" onclick="document.getElementById('photo-upload').click()">
                  <i class="bi bi-upload"></i> Upload Photo
                </button>
                <input type="file" id="photo-upload" accept="image/*" style="display: none;" onchange="uploadImage('team_photo')">
              </div>
            </div>
            <?php endif; ?>
            
            <!-- Upload Status Alert -->
            <div id="upload-status" class="alert" style="display: none;"></div>
          </div>
        </div>
        <?php endif; ?>
      </div>
      
      <div class="col-lg-5">
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Team Information</h5>
          </div>
          <div class="card-body">
            <div class="row mb-3">
              <div class="col-sm-5"><strong>Team Name:</strong></div>
              <div class="col-sm-7"><?php echo htmlentities($team->team_name ?? ''); ?></div>
            </div>
            <div class="row mb-3">
              <div class="col-sm-5"><strong>Team Number:</strong></div>
              <div class="col-sm-7"><?php echo htmlentities($team->team_number ?? ''); ?></div>
            </div>
            <div class="row mb-3">
              <div class="col-sm-5"><strong>Program:</strong></div>
              <div class="col-sm-7"><?php echo htmlentities($team->program ?? ''); ?></div>
            </div>
            <?php if (!empty($team->description)): ?>
            <div class="row mb-3">
              <div class="col-sm-5"><strong>Description:</strong></div>
              <div class="col-sm-7"><?php echo nl2br(htmlentities($team->description)); ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($team->website)): ?>
            <div class="row mb-3">
              <div class="col-sm-5"><strong>Website:</strong></div>
              <div class="col-sm-7"><a href="<?php echo esc_url($team->website); ?>" target="_blank" class="text-decoration-none"><?php echo htmlentities($team->website); ?></a></div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($team->facebook) || !empty($team->twitter) || !empty($team->instagram)): ?>
            <div class="row mb-3">
              <div class="col-sm-5"><strong>Social Media:</strong></div>
              <div class="col-sm-7">
                <?php if (!empty($team->facebook)): ?>
                  <a href="<?php echo esc_url($team->facebook); ?>" target="_blank" class="btn btn-sm btn-outline-primary me-2 mb-1">
                    <i class="bi bi-facebook"></i> Facebook
                  </a>
                <?php endif; ?>
                <?php if (!empty($team->twitter)): ?>
                  <a href="<?php echo esc_url($team->twitter); ?>" target="_blank" class="btn btn-sm btn-outline-info me-2 mb-1">
                    <i class="bi bi-twitter"></i> Twitter
                  </a>
                <?php endif; ?>
                <?php if (!empty($team->instagram)): ?>
                  <a href="<?php echo esc_url($team->instagram); ?>" target="_blank" class="btn btn-sm btn-outline-danger me-2 mb-1">
                    <i class="bi bi-instagram"></i> Instagram
                  </a>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($team->hall_of_fame) && $team->hall_of_fame): ?>
            <div class="row mb-3">
              <div class="col-sm-3"><strong>Recognition:</strong></div>
              <div class="col-sm-9"><span class="badge bg-warning text-dark fs-6"><i class="bi bi-trophy-fill me-1"></i>Hall of Fame</span></div>
            </div>
            <?php endif; ?>
          </div>
        </div>

                <div class="card shadow-sm">
          <div class="card-header bg-success text-white">
            <h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Team Statistics</h6>
          </div>
          <div class="card-body">
            <?php
            $mentor_count = count($mentors ?? []);
            $student_count = count($students ?? []);
            $alumni_count = count($alumni ?? []);
            ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span>Mentors:</span>
              <span class="badge bg-primary"><?php echo $mentor_count; ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span>Active Students:</span>
              <span class="badge bg-info"><?php echo $student_count; ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <span>Alumni:</span>
              <span class="badge bg-secondary"><?php echo $alumni_count; ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="tab-pane fade" id="register" role="tabpanel" aria-labelledby="register-tab">
    <?php if ($has_bank_account && !empty($entries)): ?>
    <table class="table table-striped table-bordered table-hover">
        <thead><tr><th>Date</th><th>Type</th><th>Payee</th><th>Description</th><th>Deposit</th><th>Withdrawal</th><th>Balance</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
        <tr>
            <td><?php echo htmlentities($entry['date']); ?></td>
            <td><?php echo htmlentities($entry['type']); ?></td>
            <td><?php echo htmlentities($entry['payee']); ?></td>
            <td><?php echo htmlentities($entry['desc']); ?></td>
            <td class="text-end">
                <?php echo ($entry['amount'] > 0) ? '$' . number_format($entry['amount'], 2) : ''; ?>
            </td>
            <td class="text-end text-danger fw-bold">
                <?php echo ($entry['amount'] < 0) ? '$' . number_format(abs($entry['amount']), 2) : ''; ?>
            </td>
            <td class="text-end">
                <?php echo '$' . number_format($entry['balance'], 2); ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php elseif ($has_bank_account): ?>
    <div class="alert alert-info mt-3">
        <i class="bi bi-info-circle me-2"></i>No transactions found for this team's bank account.
    </div>
    <?php else: ?>
    <div class="alert alert-warning mt-3">
        <i class="bi bi-exclamation-triangle me-2"></i><strong>No Bank Account Connected</strong><br>
        This team does not have a bank account linked for financial data.
    </div>
    <?php endif; ?>
  </div>
  <div class="tab-pane fade" id="mentors" role="tabpanel" aria-labelledby="mentors-tab">
    <?php if ($mentors && count($mentors) > 0): ?>
<table class="table table-striped table-bordered table-hover">
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Address</th></tr></thead>
      <tbody>
      <?php foreach ((array)$mentors as $mentor): ?>
        <tr>
          <td><?php echo htmlentities(trim($mentor->first_name . ' ' . $mentor->last_name)); ?></td>
          <td><?php echo htmlentities($mentor->email); ?></td>
          <td><?php echo htmlentities($mentor->phone); ?></td>
          <td><?php echo htmlentities($mentor->address); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="alert alert-info mt-3">No mentors found for this team.</div>
    <?php endif; ?>
  </div>
  <div class="tab-pane fade" id="students" role="tabpanel" aria-labelledby="students-tab">
    <?php if ($students && count($students) > 0): ?>
<table class="table table-striped table-bordered table-hover">
      <thead><tr><th>Name</th><th>Grade</th><th>First Year</th><th>Customer ID</th></tr></thead>
      <tbody>
      <?php foreach ((array)$students as $student): ?>
        <tr>
          <td><?php echo htmlentities(trim($student->first_name . ' ' . $student->last_name)); ?></td>
          <td><?php echo htmlentities($student->grade); ?></td>
          <td><?php echo htmlentities($student->first_year_first); ?></td>
          <td><?php echo htmlentities($student->customer_id); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="alert alert-info mt-3">No students found for this team.</div>
    <?php endif; ?>
  </div>
  <div class="tab-pane fade" id="alumni" role="tabpanel" aria-labelledby="alumni-tab">
    <?php if ($alumni && count($alumni) > 0): ?>
<table class="table table-striped table-bordered table-hover">
      <thead><tr><th>Name</th><th>First Year</th><th>Customer ID</th></tr></thead>
      <tbody>
      <?php foreach ((array)$alumni as $student): ?>
        <tr>
          <td><?php echo htmlentities(trim($student->first_name . ' ' . $student->last_name)); ?></td>
          <td><?php echo htmlentities($student->first_year_first); ?></td>
          <td><?php echo htmlentities($student->customer_id); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="alert alert-info mt-3">No alumni found for this team.</div>
    <?php endif; ?>
  </div>
  <div class="tab-pane fade" id="useful-links" role="tabpanel" aria-labelledby="useful-links-tab">
    <div class="card shadow-sm">
      <div class="card-header bg-primary text-white">
        <h6 class="mb-0"><i class="bi bi-link-45deg me-2"></i>Useful Links</h6>
      </div>
      <div class="card-body">
        <div id="useful-links-container">
          <?php 
          // Get program-specific useful links from options
          $team_program = strtoupper($team->program ?? '');
          $program_links = [];
          
          if ($team_program === 'FLL') {
            $program_links = get_option('qbo_useful_links_fll', []);
          } elseif ($team_program === 'FTC') {
            $program_links = get_option('qbo_useful_links_ftc', []);
          }
          
          if (empty($program_links)): ?>
            <div class="alert alert-info mb-0">
              <i class="bi bi-info-circle me-2"></i>No useful links have been added for <?php echo htmlentities($team_program); ?> teams yet.
            </div>
          <?php else: ?>
            <div class="row">
              <?php foreach ($program_links as $link): ?>
                <div class="col-md-6 mb-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge bg-<?php echo $team_program === 'FLL' ? 'success' : 'warning'; ?> text-<?php echo $team_program === 'FLL' ? 'white' : 'dark'; ?>">
                          <?php echo htmlentities($team_program); ?>
                        </span>
                      </div>
                      <h6 class="card-title mb-2">
                        <a href="<?php echo htmlentities($link['url']); ?>" target="_blank" class="text-decoration-none">
                          <?php echo htmlentities($link['name']); ?>
                          <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                        </a>
                      </h6>
                      <p class="card-text text-muted small mb-0">
                        <?php echo htmlentities($link['description']); ?>
                      </p>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  
  <div class="tab-pane fade" id="communication" role="tabpanel" aria-labelledby="communication-tab">
    <div class="card shadow-sm">
      <div class="card-header bg-primary text-white">
        <h6 class="mb-0"><i class="bi bi-envelope me-2"></i>Send Email to Mentors</h6>
      </div>
      <div class="card-body">
        <form id="emailForm" method="post">
          <?php wp_nonce_field('qbo_send_email', 'email_nonce'); ?>
          <input type="hidden" name="action" value="send_mentor_email">
          <input type="hidden" name="team_id" value="<?php echo intval($team->id); ?>">
          
          <div class="mb-4">
            <h6 class="mb-3">Select Recipients:</h6>
            <?php if ($mentors && count($mentors) > 0): ?>
              <div class="row">
                <div class="col-md-12 mb-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="selectAll" onchange="toggleAllMentors()">
                    <label class="form-check-label fw-bold" for="selectAll">
                      Select All Mentors
                    </label>
                  </div>
                </div>
                <?php foreach ((array)$mentors as $index => $mentor): ?>
                  <div class="col-md-6 mb-2">
                    <div class="form-check">
                      <input class="form-check-input mentor-checkbox" type="checkbox" name="mentor_emails[]" 
                             value="<?php echo htmlentities($mentor->email); ?>" 
                             id="mentor_<?php echo $index; ?>"
                             data-name="<?php echo htmlentities(trim($mentor->first_name . ' ' . $mentor->last_name)); ?>">
                      <label class="form-check-label" for="mentor_<?php echo $index; ?>">
                        <?php echo htmlentities(trim($mentor->first_name . ' ' . $mentor->last_name)); ?>
                        <small class="text-muted d-block"><?php echo htmlentities($mentor->email); ?></small>
                      </label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>No mentors found for this team.
              </div>
            <?php endif; ?>
          </div>
          
          <?php if ($mentors && count($mentors) > 0): ?>
            <div class="mb-3">
              <label for="emailSubject" class="form-label">Subject</label>
              <input type="text" class="form-control" id="emailSubject" name="subject" required 
                     placeholder="Enter email subject">
            </div>
            
            <div class="mb-3">
              <label for="emailMessage" class="form-label">Message</label>
              <div class="d-flex justify-content-between align-items-center mb-2">
                <small class="form-text text-muted">You can use HTML formatting in your message.</small>
                <div>
                  <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="addMediaBtn">
                    <i class="bi bi-image me-1"></i>Add Media
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-primary" id="uploadFileBtn">
                    <i class="bi bi-upload me-1"></i>Upload File
                  </button>
                </div>
              </div>
              <div id="emailEditor" style="height: 300px;"></div>
              <textarea name="message" id="emailMessage" style="display: none;"></textarea>
              
              <!-- Hidden file input for direct file upload -->
              <input type="file" id="emailFileInput" accept="image/*,video/*,.pdf,.doc,.docx" style="display: none;" multiple>
              
              <!-- File upload progress and preview area -->
              <div id="fileUploadArea" class="mt-2" style="display: none;">
                <div id="uploadProgress" class="progress mb-2" style="display: none;">
                  <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                </div>
                <div id="uploadedFiles" class="d-flex flex-wrap gap-2"></div>
              </div>
            </div>
            
            <div class="mb-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="sendCopy" name="send_copy" value="1">
                <label class="form-check-label" for="sendCopy">
                  Send a copy to myself
                </label>
              </div>
            </div>
            
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <span id="recipientCount" class="text-muted small">0 recipients selected</span>
              </div>
              <button type="submit" class="btn btn-primary" id="sendEmailBtn" disabled>
                <i class="bi bi-send me-1"></i>Send Email
              </button>
            </div>
          <?php endif; ?>
        </form>
        
        <div id="emailStatus" class="mt-3" style="display: none;"></div>
      </div>
    </div>
  </div>
</div>
</div>

<script>
// JavaScript variables for AJAX requests
const adminAjaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
const emailNonce = '<?php echo wp_create_nonce('qbo_send_email'); ?>';

function uploadImage(imageType) {
    const fileInput = document.getElementById(imageType === 'logo' ? 'logo-upload' : 'photo-upload');
    const file = fileInput.files[0];
    
    if (!file) {
        return;
    }
    
    // Validate file type
    if (!file.type.startsWith('image/')) {
        showUploadStatus('Please select a valid image file.', 'danger');
        return;
    }
    
    // Validate file size (max 5MB)
    if (file.size > 5 * 1024 * 1024) {
        showUploadStatus('File size must be less than 5MB.', 'danger');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'qbo_upload_team_image');
    formData.append('image_type', imageType);
    formData.append('team_id', <?php echo intval($team->id); ?>);
    formData.append('image_file', file);
    formData.append('nonce', '<?php echo wp_create_nonce('qbo_upload_team_image'); ?>');
    
    // Show loading state
    showUploadStatus('Uploading image...', 'info');
    const uploadBtn = event.target.closest('div').querySelector('button');
    const originalText = uploadBtn.innerHTML;
    uploadBtn.innerHTML = '<i class="bi bi-spinner-border" style="font-size: 0.8rem;"></i> Uploading...';
    uploadBtn.disabled = true;
    
    // Upload via AJAX
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        return response.text(); // Get as text first to see what we're actually getting
    })
    .then(text => {
        console.log('Raw response:', text);
        try {
            const data = JSON.parse(text);
            if (data.success) {
                // Update the image display
                const imgElement = document.getElementById(imageType === 'logo' ? 'team-logo-img' : 'team-photo-img');
                if (imgElement) {
                    imgElement.src = data.data.url + '?t=' + Date.now(); // Add timestamp to force refresh
                } else {
                    // If no image was displayed before, reload the page to show the new image
                    location.reload();
                }
                
                // Update header logo if it's the logo being updated
                if (imageType === 'logo') {
                    const headerLogo = document.querySelector('.animated-header img[alt="Team Logo"]');
                    if (headerLogo) {
                        headerLogo.src = data.data.url + '?t=' + Date.now();
                    }
                }
                
                showUploadStatus('Image uploaded successfully!', 'success');
            } else {
                showUploadStatus(data.data || 'Upload failed. Please try again.', 'danger');
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            showUploadStatus('Server response error: ' + text.substring(0, 100), 'danger');
        }
    })
    .catch(error => {
        console.error('Upload error:', error);
        showUploadStatus('Upload failed. Please try again.', 'danger');
    })
    .finally(() => {
        // Restore button state
        uploadBtn.innerHTML = originalText;
        uploadBtn.disabled = false;
        fileInput.value = ''; // Clear the file input
    });
}

function showUploadStatus(message, type) {
    const statusElement = document.getElementById('upload-status');
    statusElement.className = `alert alert-${type}`;
    statusElement.textContent = message;
    statusElement.style.display = 'block';
    
    // Auto-hide success messages after 3 seconds
    if (type === 'success') {
        setTimeout(() => {
            statusElement.style.display = 'none';
        }, 3000);
    }
}

// Useful Links Functions
function showAddLinkModal() {
    document.getElementById('linkModalTitle').textContent = 'Add Useful Link';
    document.getElementById('linkForm').reset();
    document.getElementById('linkIndex').value = '';
    document.getElementById('linkModal').style.display = 'block';
}

function editLink(index) {
    const links = <?php echo json_encode($useful_links ?? []); ?>;
    const link = links[index];
    
    document.getElementById('linkModalTitle').textContent = 'Edit Useful Link';
    document.getElementById('linkProgram').value = link.program;
    document.getElementById('linkName').value = link.name;
    document.getElementById('linkUrl').value = link.url;
    document.getElementById('linkDescription').value = link.description;
    document.getElementById('linkIndex').value = index;
    document.getElementById('linkModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('linkModal').style.display = 'none';
}

function saveLinkData() {
    const formData = new FormData(document.getElementById('linkForm'));
    formData.append('action', 'save_useful_link');
    formData.append('team_id', '<?php echo $team->customer_id; ?>');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload(); // Reload to show updated links
        } else {
            alert('Error saving link: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error saving link');
    });
}

function deleteLink(index) {
    if (confirm('Are you sure you want to delete this link?')) {
        const formData = new FormData();
        formData.append('action', 'delete_useful_link');
        formData.append('team_id', '<?php echo $team->customer_id; ?>');
        formData.append('link_index', index);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload(); // Reload to show updated links
            } else {
                alert('Error deleting link: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting link');
        });
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('linkModal');
    if (event.target == modal) {
        closeModal();
    }
}

// Communication Tab Functions
function toggleAllMentors() {
    const selectAll = document.getElementById('selectAll');
    const mentorCheckboxes = document.querySelectorAll('.mentor-checkbox');
    
    mentorCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
    
    updateRecipientCount();
}

function updateRecipientCount() {
    const checkedBoxes = document.querySelectorAll('.mentor-checkbox:checked');
    const count = checkedBoxes.length;
    const countElement = document.getElementById('recipientCount');
    const sendBtn = document.getElementById('sendEmailBtn');
    
    if (countElement) {
        countElement.textContent = `${count} recipient${count !== 1 ? 's' : ''} selected`;
    }
    
    if (sendBtn) {
        sendBtn.disabled = count === 0;
    }
}

// Initialize Quill editor for rich text email composition
let quill;
let mediaUploader;

document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM Content Loaded - Starting initialization');
    
    // Initialize rich text editor if communication tab exists
    const editorElement = document.getElementById('emailEditor');
    if (editorElement) {
        // Load Quill CSS and JS
        const quillCSS = document.createElement('link');
        quillCSS.rel = 'stylesheet';
        quillCSS.href = 'https://cdn.quilljs.com/1.3.6/quill.snow.css';
        document.head.appendChild(quillCSS);
        
        const quillJS = document.createElement('script');
        quillJS.src = 'https://cdn.quilljs.com/1.3.6/quill.min.js';
        quillJS.onload = function() {
            quill = new Quill('#emailEditor', {
                theme: 'snow',
                placeholder: 'Compose your message...',
                modules: {
                    toolbar: [
                        [{'header': [1, 2, 3, false]}],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{'color': []}, {'background': []}],
                        [{'align': []}],
                        [{'list': 'ordered'}, {'list': 'bullet'}],
                        ['link', 'image'],
                        ['clean']
                    ]
                }
            });
        };
        document.head.appendChild(quillJS);
        
        // Initialize WordPress Media Uploader
        initializeMediaUploader();
    }
    
    // Add event listeners for mentor checkboxes
    const mentorCheckboxes = document.querySelectorAll('.mentor-checkbox');
    mentorCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateRecipientCount);
    });
    
    // Handle email form submission
    const emailForm = document.getElementById('emailForm');
    if (emailForm) {
        emailForm.addEventListener('submit', function(e) {
            e.preventDefault();
            sendEmail();
        });
    }
    
    // Initial count update
    updateRecipientCount();
});

function sendEmail() {
    const form = document.getElementById('emailForm');
    const statusDiv = document.getElementById('emailStatus');
    const sendBtn = document.getElementById('sendEmailBtn');
    
    // Get selected recipients
    const selectedEmails = Array.from(document.querySelectorAll('.mentor-checkbox:checked'))
        .map(cb => cb.value);
    
    if (selectedEmails.length === 0) {
        showEmailStatus('Please select at least one recipient.', 'danger');
        return;
    }
    
    // Get form data
    const subject = document.getElementById('emailSubject').value.trim();
    if (!subject) {
        showEmailStatus('Please enter a subject.', 'danger');
        return;
    }
    
    // Get message from Quill editor
    let message = '';
    if (quill) {
        message = quill.root.innerHTML;
        document.getElementById('emailMessage').value = message;
    }
    
    if (!message || message.trim() === '<p><br></p>') {
        showEmailStatus('Please enter a message.', 'danger');
        return;
    }
    
    // Show loading state
    const originalBtnText = sendBtn.innerHTML;
    sendBtn.innerHTML = '<i class="bi bi-spinner-border spinner-border-sm me-1"></i>Sending...';
    sendBtn.disabled = true;
    
    // Prepare form data
    const formData = new FormData(form);
    
    // Debug log the form data
    console.log('QBO Email Debug: Sending email with data:');
    for (let pair of formData.entries()) {
        console.log(pair[0] + ': ' + pair[1]);
    }
    
    // Send email via AJAX
    fetch('/wp-admin/admin-ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('QBO Email Debug: Response status:', response.status);
        console.log('QBO Email Debug: Response headers:', response.headers);
        return response.text().then(text => {
            console.log('QBO Email Debug: Raw response:', text);
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('QBO Email Debug: Failed to parse JSON:', e);
                throw new Error('Invalid JSON response');
            }
        });
    })
    .then(data => {
        console.log('QBO Email Debug: Parsed response:', data);
        if (data.success) {
            showEmailStatus(data.message || 'Email sent successfully!', 'success');
            // Reset form
            form.reset();
            if (quill) {
                quill.setContents([]);
            }
            updateRecipientCount();
        } else {
            showEmailStatus(data.message || 'Failed to send email. Please try again.', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showEmailStatus('An error occurred while sending the email. Please try again.', 'danger');
    })
    .finally(() => {
        sendBtn.innerHTML = originalBtnText;
        sendBtn.disabled = false;
    });
}

function showEmailStatus(message, type) {
    const statusDiv = document.getElementById('emailStatus');
    statusDiv.className = `alert alert-${type}`;
    statusDiv.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>${message}`;
    statusDiv.style.display = 'block';
    
    // Auto-hide success messages after 5 seconds
    if (type === 'success') {
        setTimeout(() => {
            statusDiv.style.display = 'none';
        }, 5000);
    }
}

// Initialize WordPress Media Uploader with folder restriction
function initializeMediaUploader() {
    // Check if WordPress media library is available
    if (typeof wp !== 'undefined' && wp.media) {
        // Create the media uploader
        mediaUploader = wp.media({
            title: 'Select Media for Email',
            button: {
                text: 'Insert into Email'
            },
            multiple: false,
            library: {
                type: ['image', 'video', 'audio'],
                uploadedTo: null
            },
            // Add context to restrict to email attachments folder
            frame: 'select',
            state: 'library'
        });
        
        // Add context parameter for folder restriction
        mediaUploader.on('open', function() {
            // Set context for the AJAX query
            if (mediaUploader.state().get('library')) {
                mediaUploader.state().get('library').props.set('context', 'email_attachments');
            }
        });

        // When a file is selected, insert it into the editor
        mediaUploader.on('select', function() {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            insertMediaIntoEditor(attachment);
        });
        
        // Add event listener to the "Add Media" button
        const addMediaBtn = document.getElementById('addMediaBtn');
        if (addMediaBtn) {
            addMediaBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (mediaUploader) {
                    mediaUploader.open();
                } else {
                    // Fallback: trigger file input
                    document.getElementById('emailFileInput').click();
                }
            });
        }
        
    } else {
        // Hide the add media button if WordPress media library isn't available
        const addMediaBtn = document.getElementById('addMediaBtn');
        if (addMediaBtn) {
            addMediaBtn.style.display = 'none';
        }
    }
    
    // Initialize file upload functionality (outside of Quill initialization)
    const uploadFileBtn = document.getElementById('uploadFileBtn');
    const emailFileInput = document.getElementById('emailFileInput');
    
    console.log('Looking for upload elements:', {
        uploadFileBtn: uploadFileBtn,
        uploadFileBtnId: uploadFileBtn ? uploadFileBtn.id : 'NOT FOUND',
        emailFileInput: emailFileInput,
        emailFileInputId: emailFileInput ? emailFileInput.id : 'NOT FOUND'
    });
    
    if (uploadFileBtn && emailFileInput) {
        console.log('Binding event listeners to upload elements');
        uploadFileBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Upload File button clicked');
            emailFileInput.click();
        });
        
        // Handle file selection
        emailFileInput.addEventListener('change', function(e) {
            const files = e.target.files;
            console.log('Files selected:', files.length);
            if (files.length > 0) {
                handleFileUploads(files);
            }
        });
    } else {
        console.log('Upload button or file input not found:', {
            uploadFileBtn: !!uploadFileBtn,
            emailFileInput: !!emailFileInput
        });
    }
}

// Handle multiple file uploads for email attachments
async function handleFileUploads(files) {
    console.log('handleFileUploads called with', files.length, 'files');
    
    const fileUploadArea = document.getElementById('fileUploadArea');
    const uploadProgress = document.getElementById('uploadProgress');
    const progressBar = uploadProgress.querySelector('.progress-bar');
    const uploadedFiles = document.getElementById('uploadedFiles');
    
    console.log('Upload elements found:', {
        fileUploadArea: !!fileUploadArea,
        uploadProgress: !!uploadProgress,
        progressBar: !!progressBar,
        uploadedFiles: !!uploadedFiles
    });
    
    // Show upload area and progress
    fileUploadArea.style.display = 'block';
    uploadProgress.style.display = 'block';
    
    let totalFiles = files.length;
    let completedFiles = 0;
    
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        
        // Validate file size (max 10MB)
        if (file.size > 10 * 1024 * 1024) {
            showEmailStatus(`File "${file.name}" is too large. Maximum size is 10MB.`, 'error');
            continue;
        }
        
        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 
                             'video/mp4', 'video/avi', 'video/mov', 'application/pdf', 
                             'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        
        if (!allowedTypes.includes(file.type)) {
            showEmailStatus(`File type "${file.type}" is not allowed.`, 'error');
            continue;
        }
        
        try {
            const uploadedFile = await uploadSingleFile(file);
            if (uploadedFile.success) {
                addFileToEditor(uploadedFile.data);
                addFilePreview(uploadedFile.data);
            } else {
                showEmailStatus(`Failed to upload "${file.name}": ${uploadedFile.message}`, 'error');
            }
        } catch (error) {
            showEmailStatus(`Error uploading "${file.name}": ${error.message}`, 'error');
        }
        
        completedFiles++;
        const progress = (completedFiles / totalFiles) * 100;
        progressBar.style.width = progress + '%';
    }
    
    // Hide progress bar after completion
    setTimeout(() => {
        uploadProgress.style.display = 'none';
    }, 1000);
}

// Upload a single file to the server
function uploadSingleFile(file) {
    return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('action', 'upload_email_attachment');
        formData.append('file', file);
        formData.append('nonce', emailNonce);
        
        fetch(adminAjaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => resolve(data))
        .catch(error => reject(error));
    });
}

// Add uploaded file to the Quill editor
function addFileToEditor(fileData) {
    if (!quill) return;
    
    const range = quill.getSelection(true);
    const index = range ? range.index : quill.getLength();
    
    if (fileData.type.startsWith('image/')) {
        // Insert image
        quill.insertEmbed(index, 'image', fileData.url);
        quill.insertText(index + 1, '\n');
        quill.setSelection(index + 2);
    } else {
        // Insert other files as links
        quill.insertText(index, fileData.filename, 'link', fileData.url);
        quill.insertText(index + fileData.filename.length, '\n');
        quill.setSelection(index + fileData.filename.length + 1);
    }
}

// Add file preview to the upload area
function addFilePreview(fileData) {
    const uploadedFiles = document.getElementById('uploadedFiles');
    
    const filePreview = document.createElement('div');
    filePreview.className = 'border rounded p-2 d-flex align-items-center';
    filePreview.style.maxWidth = '200px';
    
    let iconClass = 'bi-file-earmark';
    if (fileData.type.startsWith('image/')) {
        iconClass = 'bi-image';
    } else if (fileData.type.startsWith('video/')) {
        iconClass = 'bi-camera-video';
    } else if (fileData.type === 'application/pdf') {
        iconClass = 'bi-file-earmark-pdf';
    } else if (fileData.type.includes('word')) {
        iconClass = 'bi-file-earmark-word';
    }
    
    filePreview.innerHTML = `
        <i class="bi ${iconClass} me-2"></i>
        <div class="flex-grow-1 text-truncate">
            <small>${fileData.filename}</small><br>
            <small class="text-muted">${formatFileSize(fileData.size)}</small>
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="removeFilePreview(this, '${fileData.url}')">
            <i class="bi bi-trash"></i>
        </button>
    `;
    
    uploadedFiles.appendChild(filePreview);
}

// Remove file preview and from editor
function removeFilePreview(button, fileUrl) {
    // Remove from preview area
    button.closest('.border').remove();
    
    // TODO: Remove from editor content if needed
    // This would require tracking which images/links correspond to which uploads
}

// Format file size for display
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}
function insertMediaIntoEditor(attachment) {
    if (!quill) return;
    
    const range = quill.getSelection(true);
    const index = range ? range.index : quill.getLength();
    
    if (attachment.type === 'image') {
        // Insert image
        quill.insertEmbed(index, 'image', attachment.url);
        quill.insertText(index + 1, '\n');
        quill.setSelection(index + 2);
    } else if (attachment.type === 'video') {
        // Insert video as a link (since email clients don't support embedded video well)
        quill.insertText(index, attachment.title || 'Video', 'link', attachment.url);
        quill.insertText(index + (attachment.title || 'Video').length, '\n');
    } else {
        // Insert other media as a link
        quill.insertText(index, attachment.title || attachment.filename || 'Media File', 'link', attachment.url);
        quill.insertText(index + (attachment.title || attachment.filename || 'Media File').length, '\n');
    }
    
    showEmailStatus('Media inserted successfully!', 'success');
}
</script>

<!-- Useful Links Modal -->
<div id="linkModal" class="modal" style="display: none; position: fixed; z-index: 1050; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-lg" style="margin: 5% auto; position: relative;">
        <div class="modal-content" style="background-color: #fff; border-radius: 0.5rem; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);">
            <div class="modal-header" style="padding: 1rem; border-bottom: 1px solid #dee2e6;">
                <h5 class="modal-title" id="linkModalTitle">Add Useful Link</h5>
                <button type="button" class="btn-close" onclick="closeModal()" style="background: none; border: none; font-size: 1.5rem; line-height: 1; cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body" style="padding: 1rem;">
                <form id="linkForm">
                    <input type="hidden" id="linkIndex" name="link_index">
                    <div class="mb-3">
                        <label for="linkProgram" class="form-label">Program</label>
                        <select class="form-select" id="linkProgram" name="program" required>
                            <option value="">Select Program</option>
                            <option value="FLL">FLL (FIRST Lego League)</option>
                            <option value="FTC">FTC (FIRST Tech Challenge)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="linkName" class="form-label">Link Name</label>
                        <input type="text" class="form-control" id="linkName" name="name" required placeholder="e.g., Challenge Guide">
                    </div>
                    <div class="mb-3">
                        <label for="linkUrl" class="form-label">URL</label>
                        <input type="url" class="form-control" id="linkUrl" name="url" required placeholder="https://example.com">
                    </div>
                    <div class="mb-3">
                        <label for="linkDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="linkDescription" name="description" rows="3" placeholder="Brief description of this link"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="padding: 1rem; border-top: 1px solid #dee2e6;">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveLinkData()">Save Link</button>
            </div>
        </div>
    </div>
</div>

</body></html>