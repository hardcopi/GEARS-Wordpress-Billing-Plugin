<?php
// Show PHP errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Standalone QuickBooks Account Register Viewer
// Place this file in your plugin directory and access directly (e.g., /wp-content/plugins/your-plugin/qbo-register.php)
// Usage: qbo-register.php?account_id=144

// Load WordPress core
require_once('../../../wp-load.php');

// Get account_id from query string
$account_id = isset($_GET['account_id']) ? trim($_GET['account_id']) : '';
if (!$account_id) {
    echo '<h2>Error: No account_id specified. Use ?account_id=XXX</h2>';
    exit;
}

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
$query = "SELECT * FROM AccountBasedExpenseLineDetail WHERE AccountRef = '$account_id'";
// Instead, fetch all relevant transaction types and filter in PHP
$types = array(
    'Purchase', 'JournalEntry', 'Deposit', 'Transfer', 'BillPayment'
);
$entries = array();
foreach ($types as $type) {
    $q = "SELECT * FROM $type ORDER BY TxnDate DESC MAXRESULTS 200";
    $endpoint = '/query?query=' . urlencode($q) . '&minorversion=65';
    $data = qbo_api_request($endpoint, $access_token, $realm_id);
    if (!$data || !isset($data['QueryResponse'][$type])) continue;
    foreach ($data['QueryResponse'][$type] as $txn) {
        // Filtering logic for each type
        if ($type === 'Purchase' && isset($txn['Line'])) {
            foreach ($txn['Line'] as $line) {
                if (isset($line['AccountBasedExpenseLineDetail']['AccountRef']['value']) && $line['AccountBasedExpenseLineDetail']['AccountRef']['value'] == $account_id) {
                    $entries[] = array(
                        'date' => $txn['TxnDate'] ?? '',
                        'type' => $type,
                        'payee' => $txn['PayeeRef']['name'] ?? '',
                        'desc' => $txn['PrivateNote'] ?? ($line['Description'] ?? ''),
                        'amount' => -abs($line['Amount'] ?? $txn['TotalAmt'] ?? 0),
                    );
                }
            }
        } elseif ($type === 'JournalEntry' && isset($txn['Line'])) {
            foreach ($txn['Line'] as $line) {
                if (isset($line['AccountRef']['value']) && $line['AccountRef']['value'] == $account_id) {
                    $sign = (isset($line['PostingType']) && $line['PostingType'] === 'Credit') ? 1 : -1;
                    $entries[] = array(
                        'date' => $txn['TxnDate'] ?? '',
                        'type' => $type,
                        'payee' => $line['Entity']['Name'] ?? '',
                        'desc' => $txn['PrivateNote'] ?? ($line['Description'] ?? ''),
                        'amount' => $sign * abs($line['Amount'] ?? 0),
                    );
                }
            }
        } elseif ($type === 'Deposit' && isset($txn['DepositToAccountRef']['value']) && $txn['DepositToAccountRef']['value'] == $account_id) {
            $entries[] = array(
                'date' => $txn['TxnDate'] ?? '',
                'type' => $type,
                'payee' => '',
                'desc' => $txn['PrivateNote'] ?? '',
                'amount' => abs($txn['TotalAmt'] ?? 0),
            );
        } elseif ($type === 'Transfer') {
            if (isset($txn['FromAccountRef']['value']) && $txn['FromAccountRef']['value'] == $account_id) {
                $entries[] = array(
                    'date' => $txn['TxnDate'] ?? '',
                    'type' => $type . ' Out',
                    'payee' => $txn['ToAccountRef']['name'] ?? '',
                    'desc' => $txn['PrivateNote'] ?? '',
                    'amount' => -abs($txn['Amount'] ?? $txn['TotalAmt'] ?? 0),
                );
            }
            if (isset($txn['ToAccountRef']['value']) && $txn['ToAccountRef']['value'] == $account_id) {
                $entries[] = array(
                    'date' => $txn['TxnDate'] ?? '',
                    'type' => $type . ' In',
                    'payee' => $txn['FromAccountRef']['name'] ?? '',
                    'desc' => $txn['PrivateNote'] ?? '',
                    'amount' => abs($txn['Amount'] ?? $txn['TotalAmt'] ?? 0),
                );
            }
        } elseif ($type === 'BillPayment' && isset($txn['BankAccountRef']['value']) && $txn['BankAccountRef']['value'] == $account_id) {
            $entries[] = array(
                'date' => $txn['TxnDate'] ?? '',
                'type' => $type,
                'payee' => $txn['VendorRef']['name'] ?? '',
                'desc' => $txn['PrivateNote'] ?? '',
                'amount' => -abs($txn['TotalAmt'] ?? 0),
            );
        }
    }
}

// Sort by date descending
usort($entries, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// Output HTML
?><!DOCTYPE html>
<html><head>
<title>QuickBooks Account Register: <?php echo htmlentities($account_name); ?></title>
<style>
body { font-family: Arial, sans-serif; background: #f8f8f8; }
table { border-collapse: collapse; width: 100%; background: #fff; }
th, td { border: 1px solid #ccc; padding: 8px; }
th { background: #f0f0f0; }
tr:nth-child(even) { background: #f9f9f9; }
</style>
</head><body>
<h2>QuickBooks Account Register</h2>
<h3><?php echo htmlentities($account_name); ?> (<?php echo htmlentities($account_type); ?>)</h3>
<p><strong>Current Balance:</strong> $<?php echo number_format($account_balance, 2); ?></p>
<table>
<thead><tr><th>Date</th><th>Type</th><th>Payee</th><th>Description</th><th>Amount</th></tr></thead>
<tbody>
<?php foreach ($entries as $entry): ?>
<tr>
    <td><?php echo htmlentities($entry['date']); ?></td>
    <td><?php echo htmlentities($entry['type']); ?></td>
    <td><?php echo htmlentities($entry['payee']); ?></td>
    <td><?php echo htmlentities($entry['desc']); ?></td>
    <td style="text-align:right;">$<?php echo number_format($entry['amount'], 2); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body></html>
