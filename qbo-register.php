<?php
// Show PHP errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
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
session_start();

// Load WordPress core early for wp_remote_post
require_once('../../../wp-load.php');

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
$account_id = $team->bank_account_id;

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

// Fetch account info
$account_data = qbo_api_request('/account/' . urlencode($account_id), $access_token, $realm_id);
if (!$account_data || !isset($account_data['Account'])) {
    echo '<h2>Error: Account not found in QuickBooks.</h2>';
    exit;
}
$account_name = $account_data['Account']['Name'];
$account_type = $account_data['Account']['AccountType'];
$account_balance = isset($account_data['Account']['CurrentBalance']) ? $account_data['Account']['CurrentBalance'] : 0;

// Fetch transactions (register)
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


// Output HTML as Bootstrap tabbed page
?><!DOCTYPE html>
<html><head>
<title>QuickBooks Account Register: <?php echo htmlentities($account_name); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
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
</style>
</head><body class="bg-light">

<div class="container my-4">
<div class="animated-header d-flex justify-content-between align-items-center mb-3 px-3 py-2" style="min-height:80px;">
  <div class="d-flex align-items-center">
    <img src="https://gears.org.in/wp-content/uploads/2023/11/gears-logo-transparent-white.png" alt="GEARS Logo" class="fade-in" style="height:64px;max-width:180px;object-fit:contain;background:transparent;border-radius:8px;padding:6px;">
  </div>
  <?php if (!empty($team->logo)): ?>
    <div class="d-flex align-items-center justify-content-end">
      <img src="<?php echo esc_url($team->logo); ?>" alt="Team Logo" class="fade-in" style="height:64px;max-width:180px;object-fit:contain;border-radius:8px;background:#f8f9fa;padding:6px;">
    </div>
  <?php endif; ?>
</div>
<div class="row mb-4">
  <div class="col-md-6 mx-auto">
    <div class="card shadow-lg border-0 animate__animated animate__fadeInDown">
      <div class="card-body text-center">
        <h3 class="card-title mb-2"><?php echo htmlentities($account_name); ?> <span class="badge bg-primary ms-2" style="font-size:1rem;"><?php echo htmlentities($account_type); ?></span></h3>
        <p class="card-text mb-0"><strong>Current Balance:</strong> <span class="fs-4 text-success">$<?php echo number_format($account_balance, 2); ?></span></p>
      </div>
    </div>
  </div>
</div>

<ul class="nav nav-tabs mb-3" id="registerTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="register-tab" data-bs-toggle="tab" data-bs-target="#register" type="button" role="tab" aria-controls="register" aria-selected="true">Bank Register</button>
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
</ul>
<div class="tab-content" id="registerTabsContent">
  <div class="tab-pane fade show active" id="register" role="tabpanel" aria-labelledby="register-tab">
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
  </div>
  <div class="tab-pane fade" id="mentors" role="tabpanel" aria-labelledby="mentors-tab">
    <?php
    $mentors = $wpdb->get_results("SELECT * FROM $table_mentors WHERE team_id = " . intval($team->id));
    if ($mentors && count($mentors) > 0): ?>
<table class="table table-striped table-bordered table-hover">
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Address</th><th>Notes</th></tr></thead>
      <tbody>
      <?php foreach ((array)$mentors as $mentor): ?>
        <tr>
          <td><?php echo htmlentities(trim($mentor->first_name . ' ' . $mentor->last_name)); ?></td>
          <td><?php echo htmlentities($mentor->email); ?></td>
          <td><?php echo htmlentities($mentor->phone); ?></td>
          <td><?php echo htmlentities($mentor->address); ?></td>
          <td><?php echo htmlentities($mentor->notes); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="alert alert-info mt-3">No mentors found for this team.</div>
    <?php endif; ?>
  </div>
  <div class="tab-pane fade" id="students" role="tabpanel" aria-labelledby="students-tab">
    <?php
    $students = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gears_students WHERE team_id = " . intval($team->id) . " AND grade != 'Alumni'");
    if ($students && count($students) > 0): ?>
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
    <?php
    $alumni = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gears_students WHERE team_id = " . intval($team->id) . " AND grade = 'Alumni'");
    if ($alumni && count($alumni) > 0): ?>
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
</div>
</div>
</body></html>