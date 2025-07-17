<?php
/**
 * QBO Core Class
 * 
 * Handles core functionality, API operations, and common utilities
 */

if (!defined('ABSPATH')) {
    exit;
}

class QBO_Core {
    /**
     * Fetch recent transactions for a given bank account from QuickBooks Online
     * Returns array of ['date' => ..., 'description' => ..., 'amount' => ..., 'balance' => ...]
     */
    public function fetch_bank_account_ledger($account_id, $limit = 25) {
        $options = get_option($this->option_name);
        if (!isset($options['access_token']) || !isset($options['realm_id']) || empty($account_id)) {
            error_log('QBO Ledger: Missing access_token, realm_id, or account_id');
            return array();
        }
        $access_token = $options['access_token'];
        $realm_id = $options['realm_id'];
        $entries = array();
        $debugged = false;

        // 1. JournalEntry (use Line.AccountRef, lowercase keywords, no Description field)
        $query = sprintf(
            "SELECT Id, TxnDate, PrivateNote, TotalAmt, Line FROM JournalEntry WHERE Line.AccountRef = '%s' order by TxnDate desc startposition 1 maxresults %d",
            esc_sql($account_id),
            intval($limit)
        );
        $type = 'JournalEntry';
        $url = 'https://quickbooks.api.intuit.com/v3/company/' . $realm_id . '/query?query=' . urlencode($query) . '&minorversion=65';
        error_log('QBO Ledger Query: Type=' . $type . ' Query=' . $query . ' URL=' . $url);
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/text',
            ),
            'timeout' => 30,
        );
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            error_log('QBO Ledger: WP_Error for ' . $type . ': ' . $response->get_error_message());
        } else {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (!$debugged) {
                error_log('QBO Ledger Debug: Type=' . $type . ' Query=' . $query . ' URL=' . $url);
                error_log('QBO Ledger Debug: Raw response for ' . $type . ': ' . $body);
                $debugged = true;
            }
            if (isset($data['Fault'])) {
                error_log('QBO Ledger: API Fault for ' . $type . ': ' . print_r($data['Fault'], true));
            }
            if (isset($data['QueryResponse'][$type])) {
                foreach ($data['QueryResponse'][$type] as $txn) {
                    $date = $txn['TxnDate'] ?? '';
                    $desc = $txn['PrivateNote'] ?? '';
                    $amount = $txn['TotalAmt'] ?? null;
                    if (empty($desc) && isset($txn['Line']) && is_array($txn['Line'])) {
                        foreach ($txn['Line'] as $line) {
                            if (!empty($line['Description'])) {
                                $desc = $line['Description'];
                                break;
                            }
                        }
                    }
                    $entries[] = array(
                        'date' => $date,
                        'description' => $desc,
                        'amount' => $amount,
                        'balance' => '',
                        'type' => $type,
                    );
                }
            } else {
                error_log('QBO Ledger: No entries for ' . $type . ' (QueryResponse=' . print_r($data['QueryResponse'] ?? [], true) . ')');
            }
        }

        // 2. Deposit (DepositToAccountRef)
        $query = sprintf(
            "SELECT Id, TxnDate, PrivateNote, TotalAmt, Line FROM Deposit WHERE DepositToAccountRef = '%s' order by TxnDate desc maxresults %d",
            esc_sql($account_id),
            intval($limit)
        );
        $type = 'Deposit';
        $url = 'https://quickbooks.api.intuit.com/v3/company/' . $realm_id . '/query?query=' . urlencode($query) . '&minorversion=65';
        error_log('QBO Ledger Query: Type=' . $type . ' Query=' . $query . ' URL=' . $url);
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            error_log('QBO Ledger: WP_Error for ' . $type . ': ' . $response->get_error_message());
        } else {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (isset($data['Fault'])) {
                error_log('QBO Ledger: API Fault for ' . $type . ': ' . print_r($data['Fault'], true));
            }
            if (isset($data['QueryResponse'][$type])) {
                foreach ($data['QueryResponse'][$type] as $txn) {
                    $date = $txn['TxnDate'] ?? '';
                    $desc = $txn['PrivateNote'] ?? '';
                    $amount = $txn['TotalAmt'] ?? null;
                    if (empty($desc) && isset($txn['Line']) && is_array($txn['Line'])) {
                        foreach ($txn['Line'] as $line) {
                            if (!empty($line['Description'])) {
                                $desc = $line['Description'];
                                break;
                            }
                        }
                    }
                    $entries[] = array(
                        'date' => $date,
                        'description' => $desc,
                        'amount' => $amount,
                        'balance' => '',
                        'type' => $type,
                    );
                }
            } else {
                error_log('QBO Ledger: No entries for ' . $type . ' (QueryResponse=' . print_r($data['QueryResponse'] ?? [], true) . ')');
            }
        }

        // 3. Payment (cannot filter by DepositToAccountRef, so skip or fetch all and filter in PHP - here, skip)

        // 4. Transfer (run two queries: FromAccountRef and ToAccountRef)
        foreach (['FromAccountRef', 'ToAccountRef'] as $transfer_field) {
            $query = sprintf(
                "SELECT Id, TxnDate, PrivateNote, TotalAmt, Line FROM Transfer WHERE %s = '%s' order by TxnDate desc maxresults %d",
                $transfer_field,
                esc_sql($account_id),
                intval($limit)
            );
            $type = 'Transfer';
            $url = 'https://quickbooks.api.intuit.com/v3/company/' . $realm_id . '/query?query=' . urlencode($query) . '&minorversion=65';
            error_log('QBO Ledger Query: Type=' . $type . ' Query=' . $query . ' URL=' . $url);
            $response = wp_remote_get($url, $args);
            if (is_wp_error($response)) {
                error_log('QBO Ledger: WP_Error for ' . $type . ': ' . $response->get_error_message());
                continue;
            }
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (isset($data['Fault'])) {
                error_log('QBO Ledger: API Fault for ' . $type . ': ' . print_r($data['Fault'], true));
            }
            if (isset($data['QueryResponse'][$type])) {
                foreach ($data['QueryResponse'][$type] as $txn) {
                    $date = $txn['TxnDate'] ?? '';
                    $desc = $txn['PrivateNote'] ?? '';
                    $amount = $txn['TotalAmt'] ?? null;
                    if (empty($desc) && isset($txn['Line']) && is_array($txn['Line'])) {
                        foreach ($txn['Line'] as $line) {
                            if (!empty($line['Description'])) {
                                $desc = $line['Description'];
                                break;
                            }
                        }
                    }
                    $entries[] = array(
                        'date' => $date,
                        'description' => $desc,
                        'amount' => $amount,
                        'balance' => '',
                        'type' => $type,
                    );
                }
            } else {
                error_log('QBO Ledger: No entries for ' . $type . ' (QueryResponse=' . print_r($data['QueryResponse'] ?? [], true) . ')');
            }
        }

        // 5. BillPayment (remove Description from SELECT)
        $query = sprintf(
            "SELECT Id, TxnDate, PrivateNote, TotalAmt, Line FROM BillPayment WHERE BankAccountRef = '%s' order by TxnDate desc maxresults %d",
            esc_sql($account_id),
            intval($limit)
        );
        $type = 'BillPayment';
        $url = 'https://quickbooks.api.intuit.com/v3/company/' . $realm_id . '/query?query=' . urlencode($query) . '&minorversion=65';
        error_log('QBO Ledger Query: Type=' . $type . ' Query=' . $query . ' URL=' . $url);
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            error_log('QBO Ledger: WP_Error for ' . $type . ': ' . $response->get_error_message());
        } else {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (isset($data['Fault'])) {
                error_log('QBO Ledger: API Fault for ' . $type . ': ' . print_r($data['Fault'], true));
            }
            if (isset($data['QueryResponse'][$type])) {
                foreach ($data['QueryResponse'][$type] as $txn) {
                    $date = $txn['TxnDate'] ?? '';
                    $desc = $txn['PrivateNote'] ?? '';
                    $amount = $txn['TotalAmt'] ?? null;
                    if (empty($desc) && isset($txn['Line']) && is_array($txn['Line'])) {
                        foreach ($txn['Line'] as $line) {
                            if (!empty($line['Description'])) {
                                $desc = $line['Description'];
                                break;
                            }
                        }
                    }
                    $entries[] = array(
                        'date' => $date,
                        'description' => $desc,
                        'amount' => $amount,
                        'balance' => '',
                        'type' => $type,
                    );
                }
            } else {
                error_log('QBO Ledger: No entries for ' . $type . ' (QueryResponse=' . print_r($data['QueryResponse'] ?? [], true) . ')');
            }
        }

        // Sort all entries by date descending
        usort($entries, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        // Limit to $limit most recent
        return array_slice($entries, 0, $limit);
    }

    /**
     * Fetch all bank accounts from QuickBooks Online
     * Returns array of ['Id' => ..., 'Name' => ...]
     */
    public function fetch_bank_accounts() {
        $options = get_option($this->option_name);
        if (!isset($options['access_token']) || !isset($options['realm_id'])) {
            return array();
        }
        $access_token = $options['access_token'];
        $realm_id = $options['realm_id'];
        $query = "SELECT Id, Name, AccountType FROM Account WHERE AccountType = 'Bank'";
        $url = 'https://quickbooks.api.intuit.com/v3/company/' . $realm_id . '/query?query=' . urlencode($query);
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/text',
            ),
            'timeout' => 30,
        );
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return array();
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $accounts = array();
        if (isset($data['QueryResponse']['Account'])) {
            foreach ($data['QueryResponse']['Account'] as $acct) {
                $accounts[] = array(
                    'Id' => $acct['Id'],
                    'Name' => $acct['Name'],
                );
            }
        }
        return $accounts;
    }
    
    private $option_name = 'qbo_recurring_billing_options';
    private $plugin_version = '1.1.0';
    
    public function __construct() {
        // Hook into WordPress init
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'handle_oauth_callback'));
        
        // AJAX handlers for customer invoices
        add_action('wp_ajax_qbo_get_customer_invoices', array($this, 'ajax_get_customer_invoices'));
        add_action('wp_ajax_qbo_delete_invoice', array($this, 'ajax_delete_invoice'));
        add_action('wp_ajax_qbo_view_invoice', array($this, 'ajax_view_invoice'));
        add_action('wp_ajax_qbo_clear_invoice_cache', array($this, 'ajax_clear_invoice_cache'));
        add_action('wp_ajax_qbo_clear_customer_cache', array($this, 'ajax_clear_customer_cache'));
        add_action('wp_ajax_qbo_get_customer_list', array($this, 'ajax_get_customer_list'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Scheduled events
        add_action('qbo_refresh_token_hook', array($this, 'cron_refresh_token'));
        $this->schedule_token_refresh();
    }
    
    public function init() {
        // Initialize core functionality
    }
    
    /**
     * Get plugin option name
     */
    public function get_option_name() {
        return $this->option_name;
    }
    
    /**
     * Get plugin version
     */
    public function get_version() {
        return $this->plugin_version;
    }
    
    /**
     * Create database tables on plugin activation
     */
    public function create_database_tables() {
        global $wpdb;
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Create gears_teams table
        $table_teams = $wpdb->prefix . 'gears_teams';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql_teams = "CREATE TABLE $table_teams (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            team_name varchar(255) NOT NULL,
            team_number varchar(50) DEFAULT '',
            program varchar(255) DEFAULT '',
            description text,
            logo varchar(255) DEFAULT '',
            team_photo varchar(255) DEFAULT '',
            facebook varchar(255) DEFAULT '',
            twitter varchar(255) DEFAULT '',
            instagram varchar(255) DEFAULT '',
            website varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        dbDelta($sql_teams);
        
        // Create gears_mentors table
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        
        $sql_mentors = "CREATE TABLE $table_mentors (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            mentor_name varchar(255) NOT NULL,
            email varchar(255) DEFAULT '',
            phone varchar(50) DEFAULT '',
            team_id mediumint(9) DEFAULT NULL,
            bio text,
            specialties text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY team_id (team_id)
        ) $charset_collate;";
        
        dbDelta($sql_mentors);
        
        // Create gears_students table
        $table_students = $wpdb->prefix . 'gears_students';
        
        $sql_students = "CREATE TABLE $table_students (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            first_name varchar(255) NOT NULL,
            last_name varchar(255) NOT NULL,
            grade varchar(50) DEFAULT '',
            team_id mediumint(9) DEFAULT NULL,
            customer_id varchar(50) DEFAULT NULL,
            first_year_first year DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY team_id (team_id),
            KEY customer_id (customer_id),
            KEY student_name (first_name, last_name)
        ) $charset_collate;";
        
        dbDelta($sql_students);
    }
    
    /**
     * Handle OAuth callback from QuickBooks
     */
    public function handle_oauth_callback() {
        if (isset($_GET['code']) && isset($_GET['realmId']) && isset($_GET['state'])) {
            // Verify the state parameter for CSRF protection
            if (!wp_verify_nonce($_GET['state'], 'qbo_oauth_state')) {
                wp_redirect(admin_url('admin.php?page=qbo-settings&oauth=error&error=invalid_state'));
                exit;
            }
            
            $options = get_option($this->option_name, array());
            
            // Save realm ID automatically
            $options['realm_id'] = sanitize_text_field($_GET['realmId']);
            
            // Exchange code for tokens
            $token_response = $this->exchange_code_for_tokens($_GET['code']);
            
            if ($token_response && isset($token_response['access_token'])) {
                $options['access_token'] = $token_response['access_token'];
                if (isset($token_response['refresh_token'])) {
                    $options['refresh_token'] = $token_response['refresh_token'];
                }
                
                update_option($this->option_name, $options);
                
                // Redirect to remove code from URL
                wp_redirect(admin_url('admin.php?page=qbo-settings&oauth=success'));
                exit;
            } else {
                wp_redirect(admin_url('admin.php?page=qbo-settings&oauth=error'));
                exit;
            }
        }
    }
    
    /**
     * Exchange authorization code for access tokens
     */
    private function exchange_code_for_tokens($code) {
        $options = get_option($this->option_name);
        
        if (!isset($options['client_id']) || !isset($options['client_secret']) || !isset($options['redirect_uri'])) {
            return false;
        }
        
        $token_url = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
        
        $body = array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $options['redirect_uri']
        );
        
        $headers = array(
            'Authorization' => 'Basic ' . base64_encode($options['client_id'] . ':' . $options['client_secret']),
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded'
        );
        
        $response = wp_remote_post($token_url, array(
            'headers' => $headers,
            'body' => http_build_query($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    /**
     * Refresh access token using refresh token
     */
    public function refresh_access_token() {
        $options = get_option($this->option_name);
        
        if (!isset($options['refresh_token']) || !isset($options['client_id']) || !isset($options['client_secret'])) {
            return false;
        }
        
        $token_url = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
        
        $body = array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $options['refresh_token']
        );
        
        $headers = array(
            'Authorization' => 'Basic ' . base64_encode($options['client_id'] . ':' . $options['client_secret']),
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded'
        );
        
        $response = wp_remote_post($token_url, array(
            'headers' => $headers,
            'body' => http_build_query($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('QBO Token Refresh Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);
        
        if ($response_code === 200 && isset($token_data['access_token'])) {
            $options['access_token'] = $token_data['access_token'];
            if (isset($token_data['refresh_token'])) {
                $options['refresh_token'] = $token_data['refresh_token'];
            }
            // Store token refresh timestamp for proactive refresh
            $options['token_refreshed_at'] = time();
            update_option($this->option_name, $options);
            return true;
        } else {
            error_log('QBO Token Refresh Failed: ' . $response_body);
            return false;
        }
        
        return false;
    }

    /**
     * Check if token needs proactive refresh (refresh before it expires)
     */
    public function should_refresh_token() {
        $options = get_option($this->option_name);
        
        if (!isset($options['token_refreshed_at']) || !isset($options['refresh_token'])) {
            return false;
        }
        
        // Refresh token proactively after 50 minutes (tokens expire after 1 hour)
        $refresh_threshold = 50 * 60; // 50 minutes
        return (time() - $options['token_refreshed_at']) > $refresh_threshold;
    }

    /**
     * Proactively refresh token if needed
     */
    public function maybe_refresh_token() {
        if ($this->should_refresh_token()) {
            return $this->refresh_access_token();
        }
        return true;
    }
    
    /**
     * Make API request to QuickBooks
     */
    public function make_qbo_request($endpoint, $method = 'GET', $body = null) {
        $options = get_option($this->option_name);
        
        if (!isset($options['access_token']) || !isset($options['realm_id'])) {
            return false;
        }
        
        // Proactively refresh token if needed
        $this->maybe_refresh_token();
        
        $base_url = 'https://quickbooks.api.intuit.com/v3/company/' . $options['realm_id'];
        $url = $base_url . $endpoint;
        
        $headers = array(
            'Authorization' => 'Bearer ' . $options['access_token'],
            'Accept' => 'application/json'
        );
        
        if ($method === 'POST' || $method === 'PUT') {
            $headers['Content-Type'] = 'application/json';
        }
        
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30
        );
        
        if ($body && ($method === 'POST' || $method === 'PUT')) {
            $args['body'] = json_encode($body);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Try to refresh token if unauthorized
        if ($response_code === 401) {
            if ($this->refresh_access_token()) {
                // Retry the request with new token
                $options = get_option($this->option_name);
                $headers['Authorization'] = 'Bearer ' . $options['access_token'];
                $args['headers'] = $headers;
                
                $response = wp_remote_request($url, $args);
                if (!is_wp_error($response)) {
                    $response_body = wp_remote_retrieve_body($response);
                }
            }
        }
        
        return json_decode($response_body, true);
    }
    
    /**
     * Fetch customers from QuickBooks, with 1-hour cache
     */
    public function fetch_customers($limit = 1000) {
        $cache_key = 'qbo_recurring_billing_customers_cache';
        $cache = get_option($cache_key, array());
        $now = time();
        if (isset($cache['timestamp']) && ($now - $cache['timestamp'] < 3600) && isset($cache['data'])) {
            return $cache['data'];
        }
        
        $options = get_option($this->option_name);
        if (!isset($options['access_token']) || !isset($options['realm_id'])) {
            return false;
        }
        $access_token = $options['access_token'];
        $realm_id = $options['realm_id'];
        $query = 'SELECT * FROM Customer';
        if ($limit > 0) {
            $query .= ' MAXRESULTS ' . intval($limit);
        }
        $url = 'https://quickbooks.api.intuit.com/v3/company/' . $realm_id . '/query?query=' . urlencode($query);
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/text',
            ),
            'timeout' => 30
        );
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return false;
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (isset($data['QueryResponse']['Customer'])) {
            $customers = $data['QueryResponse']['Customer'];
            foreach ($customers as &$customer) {
                $parsed = $this->parse_company_name(isset($customer['CompanyName']) ? $customer['CompanyName'] : '');
                $customer['parsed_team'] = $parsed['team'];
                $customer['parsed_program'] = $parsed['program'];
                $customer['parsed_student'] = $parsed['student'];
            }
            // Save to cache
            update_option($cache_key, array('timestamp' => $now, 'data' => $customers));
            return $customers;
        }
        return false;
    }
    
    /**
     * Fetch invoices for a specific customer (paged, legacy compatible, with 1-hour cache)
     */
    public function fetch_customer_invoices($customer_id) {
        $cache_key = 'qbo_recurring_billing_invoices_cache_' . $customer_id;
        $cache = get_option($cache_key, array());
        $now = time();
        if (isset($cache['timestamp']) && ($now - $cache['timestamp'] < 3600) && isset($cache['data'])) {
            return $cache['data'];
        }

        $options = get_option($this->option_name);
        if (!isset($options['access_token']) || !isset($options['realm_id'])) {
            echo '<pre>Missing access token or realm ID</pre>';
            return array();
        }

        $all_invoices = array();
        $start_position = 1;
        $max_results = 100;
        $base_url = 'https://quickbooks.api.intuit.com';
        $access_token = $options['access_token'];
        $company_id = $options['realm_id'];
        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/text',
        );
        do {
            $query = "SELECT * FROM Invoice WHERE CustomerRef = '$customer_id' STARTPOSITION $start_position MAXRESULTS $max_results";
            $api_url = "$base_url/v3/company/$company_id/query?query=" . urlencode($query) . "&minorversion=65";
            $response = wp_remote_get($api_url, array('headers' => $headers, 'timeout' => 30));
            if (is_wp_error($response)) {
                break;
            }
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            $batch_invoices = array();
            if (isset($data['QueryResponse']['Invoice'])) {
                $batch_invoices = $data['QueryResponse']['Invoice'];
                $all_invoices = array_merge($all_invoices, $batch_invoices);
            }
            $start_position += $max_results;
        } while (count($batch_invoices) === $max_results);

        // Sort invoices by date (newest first)
        usort($all_invoices, function($a, $b) {
            $date_a = isset($a['TxnDate']) ? strtotime($a['TxnDate']) : 0;
            $date_b = isset($b['TxnDate']) ? strtotime($b['TxnDate']) : 0;
            return $date_b - $date_a;
        });

        // Save to cache
        update_option($cache_key, array('timestamp' => $now, 'data' => $all_invoices));
        return $all_invoices;
    }
    
    /**
     * Delete an invoice (fetches invoice by Id using query, as in legacy)
     */
    public function delete_invoice($invoice_id, $sync_token = null) {
        $options = get_option($this->option_name);
        if (!isset($options['access_token']) || !isset($options['realm_id'])) {
            return false;
        }
        $access_token = $options['access_token'];
        $company_id = $options['realm_id'];
        $base_url = 'https://quickbooks.api.intuit.com';
        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        );
        // If sync_token is not provided, fetch it first
        if (!$sync_token) {
            $query = "SELECT * FROM Invoice WHERE Id = '{$invoice_id}'";
            $api_url = "$base_url/v3/company/$company_id/query?query=" . urlencode($query) . "&minorversion=65";
            $response = wp_remote_get($api_url, array('headers' => $headers, 'timeout' => 30));
            if (is_wp_error($response)) {
                return false;
            }
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (!isset($data['QueryResponse']['Invoice'][0]['SyncToken'])) {
                return false;
            }
            $sync_token = $data['QueryResponse']['Invoice'][0]['SyncToken'];
        }
        // Now delete the invoice
        $delete_url = "$base_url/v3/company/$company_id/invoice?operation=delete";
        $delete_data = array(
            'Id' => $invoice_id,
            'SyncToken' => $sync_token
        );
        $response = wp_remote_post($delete_url, array(
            'headers' => $headers,
            'body' => json_encode($delete_data),
            'timeout' => 30
        ));
        if (is_wp_error($response)) {
            return false;
        }
        $response_code = wp_remote_retrieve_response_code($response);
        return $response_code === 200;
    }
    
    /**
     * Parse company name to extract program, team, and student info
     * Supports: FLL66154-Bennett, FTC12345-Jane Smith, FTC12014 - Nate, etc.
     */
    public function parse_company_name($company_name) {
        $result = array(
            'program' => '',
            'team' => '',
            'student' => ''
        );
        if (empty($company_name)) {
            return $result;
        }
        // Pattern: PROGRAMTEAM-STUDENT (e.g. FLL66154-Bennett, FLL18067-Grant, FLL18067-Kristyn Hauenstein)
        if (preg_match('/^([A-Za-z]+)(\d+)-(.*)$/', $company_name, $matches)) {
            $result['program'] = $matches[1];
            $result['team'] = ltrim($matches[2], '0');
            $result['student'] = trim($matches[3]);
            return $result;
        }
        // Pattern: PROGRAMTEAM - Student (e.g. FTC12014 - Nate)
        if (preg_match('/^([A-Za-z]+)(\d+)\s*-\s*(.+)$/', $company_name, $matches)) {
            $result['program'] = $matches[1];
            $result['team'] = ltrim($matches[2], '0');
            $result['student'] = trim($matches[3]);
            return $result;
        }
        // Pattern: Program - Team ### - Student Name
        if (preg_match('/^(.+?)\s*-\s*Team\s+(\d+)\s*-\s*(.+)$/i', $company_name, $matches)) {
            $result['program'] = trim($matches[1]);
            $result['team'] = trim($matches[2]);
            $result['student'] = trim($matches[3]);
        }
        // Pattern: Program - Team ###
        elseif (preg_match('/^(.+?)\s*-\s*Team\s+(\d+)$/i', $company_name, $matches)) {
            $result['program'] = trim($matches[1]);
            $result['team'] = trim($matches[2]);
        }
        // Pattern: Team ### - Student Name
        elseif (preg_match('/^Team\s+(\d+)\s*-\s*(.+)$/i', $company_name, $matches)) {
            $result['team'] = trim($matches[1]);
            $result['student'] = trim($matches[2]);
        }
        // Pattern: Just Team ###
        elseif (preg_match('/^Team\s+(\d+)$/i', $company_name, $matches)) {
            $result['team'] = trim($matches[1]);
        }
        return $result;
    }
    
    /**
     * Parse student name into first and last name
     */
    public function parse_student_name($student_name) {
        $parts = explode(' ', trim($student_name), 2);
        return array(
            'first' => isset($parts[0]) ? $parts[0] : '',
            'last' => isset($parts[1]) ? $parts[1] : ''
        );
    }
    
    /**
     * AJAX handler for getting customer invoices
     */
    public function ajax_get_customer_invoices() {
        if (!wp_verify_nonce($_POST['security'], 'qbo_get_invoices')) {
            wp_die('Security check failed');
        }
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $customer_id = sanitize_text_field($_POST['customer_id']);
        $cache_key = 'qbo_recurring_billing_invoices_cache_' . $customer_id;
        $cache = get_option($cache_key, array());
        $last_cached = isset($cache['timestamp']) ? date('M j, Y H:i:s', $cache['timestamp']) : 'Never';
        $invoices = $this->fetch_customer_invoices($customer_id);
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Invoice #</th><th>Date</th><th>Due Date</th><th>Amount</th><th>Balance</th><th>Status</th><th>Actions</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($invoices as $invoice) {
            $status = 'Unknown';
            $status_class = '';
            $balance = floatval($invoice['Balance'] ?? 0);
            if ($balance <= 0) {
                $status = 'Paid';
                $status_class = 'status-paid';
            } elseif (isset($invoice['DueDate']) && strtotime($invoice['DueDate']) < time()) {
                $status = 'Overdue';
                $status_class = 'status-overdue';
            } else {
                $status = 'Open';
                $status_class = 'status-open';
            }
            echo '<tr>';
            echo '<td>' . esc_html($invoice['DocNumber'] ?? 'N/A') . '</td>';
            echo '<td>' . esc_html(isset($invoice['TxnDate']) ? date('M j, Y', strtotime($invoice['TxnDate'])) : 'N/A') . '</td>';
            echo '<td>' . esc_html(isset($invoice['DueDate']) ? date('M j, Y', strtotime($invoice['DueDate'])) : 'N/A') . '</td>';
            echo '<td>$' . esc_html(number_format($invoice['TotalAmt'] ?? 0, 2)) . '</td>';
            echo '<td>$' . esc_html(number_format($invoice['Balance'] ?? 0, 2)) . '</td>';
            echo '<td><span class="' . $status_class . '">' . esc_html($status) . '</span></td>';
            echo '<td>';
            echo '<button type="button" class="button button-small view-invoice" data-invoice-id="' . esc_attr($invoice['Id']) . '" data-invoice-number="' . esc_attr($invoice['DocNumber'] ?? 'N/A') . '" title="View Invoice" style="margin-right: 5px;"><span class="dashicons dashicons-visibility"></span></button>';
            echo '<button type="button" class="button button-small delete-invoice" data-invoice-id="' . esc_attr($invoice['Id']) . '" data-invoice-number="' . esc_attr($invoice['DocNumber'] ?? 'N/A') . '" title="Delete Invoice"><span class="dashicons dashicons-trash"></span></button>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        // Cache info and force refresh button
        echo '<div style="margin-top:15px;">';
        echo '<strong>Last cache:</strong> ' . esc_html($last_cached);
        echo ' <button type="button" class="button" id="force-refresh-invoices" data-customer-id="' . esc_attr($customer_id) . '">Force Refresh</button>';
        echo '</div>';
        // JS for force refresh
        echo '<script type="text/javascript">
        jQuery(document).ready(function($){
            $(document).off("click", "#force-refresh-invoices").on("click", "#force-refresh-invoices", function(){
                var btn = $(this);
                btn.prop("disabled", true).text("Refreshing...");
                $.post(ajaxurl, {
                    action: "qbo_clear_invoice_cache",
                    nonce: "' . wp_create_nonce('qbo_clear_cache') . '",
                    customer_id: btn.data("customer-id")
                }, function(resp){
                    if (resp.success) {
                        btn.text("Reloading...");
                        setTimeout(function(){ 
                            btn.prop("disabled", false).text("Force Refresh"); 
                            // Reload invoices
                            $("#modal-content").trigger("refreshInvoices");
                        }, 1000);
                    } else {
                        alert("Error clearing cache: " + (resp.data || "Unknown error"));
                        btn.prop("disabled", false).text("Force Refresh");
                    }
                }).fail(function(){
                    alert("Error clearing cache. Please try again.");
                    btn.prop("disabled", false).text("Force Refresh");
                });
            });
            
            // Handle view invoice button clicks with event delegation
            $(document).off("click", ".view-invoice").on("click", ".view-invoice", function(e){
                e.preventDefault();
                console.log("View invoice button clicked");
                var invoiceId = $(this).data("invoice-id");
                var invoiceNumber = $(this).data("invoice-number");
                console.log("Invoice ID:", invoiceId, "Invoice Number:", invoiceNumber);
                var security = (typeof qboCustomerListVars !== "undefined") ? qboCustomerListVars.invoice_nonce : "";
                console.log("Security nonce:", security);
                console.log("qboCustomerListVars:", typeof qboCustomerListVars !== "undefined" ? qboCustomerListVars : "undefined");
                if (typeof qboViewInvoiceDetails === "function") {
                    qboViewInvoiceDetails(invoiceId, invoiceNumber, security);
                } else {
                    console.error("qboViewInvoiceDetails function not found");
                }
            });
        });
        </script>
        
        <script type="text/javascript">
        // Global function to view invoice details - make it available globally
        window.qboViewInvoiceDetails = function(invoiceId, invoiceNumber, security) {
            console.log("qboViewInvoiceDetails called with:", invoiceId, invoiceNumber, security);
            // Create modal HTML
            var modalHtml = `
                <div id="invoice-detail-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999; display: flex; align-items: center; justify-content: center;">
                    <div style="background: white; border-radius: 8px; padding: 20px; max-width: 600px; max-height: 80vh; overflow-y: auto; position: relative;">
                        <span id="close-invoice-modal" style="position: absolute; top: 10px; right: 15px; font-size: 24px; cursor: pointer; color: #999;">&times;</span>
                        <h2>Invoice Details - #` + invoiceNumber + `</h2>
                        <div id="invoice-details-content">Loading...</div>
                    </div>
                </div>
            `;
            
            // Add modal to page
            jQuery("body").append(modalHtml);
            
            // Close modal functionality
            jQuery("#close-invoice-modal, #invoice-detail-modal").on("click", function(e) {
                if (e.target === this) {
                    jQuery("#invoice-detail-modal").remove();
                }
            });
            
            // Fetch invoice details
            var nonce = (typeof qboCustomerListVars !== "undefined") ? qboCustomerListVars.invoice_nonce : "";
            var ajaxurl = (typeof qboCustomerListVars !== "undefined") ? qboCustomerListVars.ajaxurl : (typeof ajaxurl !== "undefined" ? ajaxurl : "");
            
            if (!nonce || !ajaxurl) {
                jQuery("#invoice-details-content").html("<p>Error: Missing AJAX configuration. Please reload the page.</p>");
                return;
            }
            
            jQuery.post(ajaxurl, {
                action: "qbo_view_invoice",
                nonce: nonce,
                invoice_id: invoiceId
            }, function(response) {
                if (response.success) {
                    var invoice = response.data;
                    var html = `
                        <table class="form-table">
                            <tr><th>Invoice Number:</th><td>` + invoice.doc_number + `</td></tr>
                            <tr><th>Invoice Date:</th><td>` + invoice.txn_date + `</td></tr>
                            <tr><th>Due Date:</th><td>` + invoice.due_date + `</td></tr>
                            <tr><th>Customer:</th><td>` + invoice.customer_ref + `</td></tr>
                            <tr><th>Total Amount:</th><td><strong>` + invoice.total_amt + `</strong></td></tr>
                            <tr><th>Balance Due:</th><td><strong style="color: ` + (invoice.balance !== "$0.00" ? "red" : "green") + `;">` + invoice.balance + `</strong></td></tr>
                        </table>
                    `;
                    
                    if (invoice.line_items.length > 0) {
                        html += `<h3>Line Items</h3>
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th>Description</th>
                                            <th>Quantity</th>
                                            <th>Rate</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>`;
                        
                        invoice.line_items.forEach(function(item) {
                            html += `<tr>
                                        <td>` + item.description + `</td>
                                        <td>` + item.quantity + `</td>
                                        <td>` + item.rate + `</td>
                                        <td>` + item.amount + `</td>
                                    </tr>`;
                        });
                        
                        html += `</tbody></table>`;
                    }
                    
                    jQuery("#invoice-details-content").html(html);
                } else {
                    jQuery("#invoice-details-content").html("<p>Error loading invoice details: " + response.data + "</p>");
                }
            }).fail(function() {
                jQuery("#invoice-details-content").html("<p>Error loading invoice details. Please try again.</p>");
            });
        };
        </script>
        
        <script type="text/javascript">
        // JS event for parent to reload invoices (should be handled in your modal JS)
        jQuery(document).ready(function($){
            $("#modal-content").off("refreshInvoices").on("refreshInvoices", function(){
                var cid = $("#force-refresh-invoices").data("customer-id");
                var sec = "' . wp_create_nonce('qbo_get_invoices') . '";
                $.post(ajaxurl, {action: "qbo_get_customer_invoices", security: sec, customer_id: cid}, function(html){
                    $("#modal-content").html(html);
                });
            });
        });
        </script>';
        wp_die();
    }
    
    /**
     * AJAX handler for deleting invoices
     */
    public function ajax_delete_invoice() {
        if (!wp_verify_nonce($_POST['nonce'], 'qbo_delete_invoice_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        $invoice_id = sanitize_text_field($_POST['invoice_id']);
        $options = get_option($this->option_name);
        if (!isset($options['access_token']) || !isset($options['realm_id'])) {
            wp_send_json_error('Missing QBO credentials');
        }
        $access_token = $options['access_token'];
        $company_id = $options['realm_id'];
        $base_url = 'https://quickbooks.api.intuit.com';
        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        );
        // Fetch invoice by Id using QBO SQL query (legacy logic)
        $query = "SELECT * FROM Invoice WHERE Id = '{$invoice_id}'";
        $api_url = "$base_url/v3/company/$company_id/query?query=" . urlencode($query) . "&minorversion=65";
        $response = wp_remote_get($api_url, array('headers' => $headers, 'timeout' => 30));
        if (is_wp_error($response)) {
            wp_send_json_error('Error fetching invoice');
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!isset($data['QueryResponse']['Invoice'][0]['SyncToken'])) {
            wp_send_json_error('Invoice not found');
        }
        $sync_token = $data['QueryResponse']['Invoice'][0]['SyncToken'];
        // Now delete the invoice using the legacy-compatible method
        if ($this->delete_invoice($invoice_id, $sync_token)) {
            // Clear the invoice cache for the customer associated with this invoice
            if (isset($data['QueryResponse']['Invoice'][0]['CustomerRef']['value'])) {
                $customer_id = $data['QueryResponse']['Invoice'][0]['CustomerRef']['value'];
                $cache_key = 'qbo_recurring_billing_invoices_cache_' . $customer_id;
                delete_option($cache_key);
            }
            wp_send_json_success('Invoice deleted successfully');
        } else {
            wp_send_json_error('Failed to delete invoice');
        }
    }
    
    /**
     * AJAX handler to clear invoice cache for a customer
     */
    public function ajax_clear_invoice_cache() {
        if (!wp_verify_nonce($_POST['nonce'], 'qbo_clear_cache') && !wp_verify_nonce($_POST['nonce'], 'qbo_get_invoices')) {
            wp_send_json_error('Invalid nonce');
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        $customer_id = sanitize_text_field($_POST['customer_id']);
        $cache_key = 'qbo_recurring_billing_invoices_cache_' . $customer_id;
        delete_option($cache_key);
        wp_send_json_success('Cache cleared');
    }

    /**
     * AJAX handler to clear customer list cache
     */
    public function ajax_clear_customer_cache() {
        if (!wp_verify_nonce($_POST['nonce'], 'qbo_get_customers')) {
            wp_send_json_error('Invalid nonce');
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        $cache_key = 'qbo_recurring_billing_customers_cache';
        delete_option($cache_key);
        wp_send_json_success('Customer cache cleared');
    }

    /**
     * AJAX handler for getting customer list (with cache info and force refresh)
     */
    public function ajax_get_customer_list() {
        if (!wp_verify_nonce($_POST['security'], 'qbo_get_customers')) {
            wp_die('Security check failed');
        }
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $cache_key = 'qbo_recurring_billing_customers_cache';
        $cache = get_option($cache_key, array());
        $last_cached = isset($cache['timestamp']) ? date('M j, Y H:i:s', $cache['timestamp']) : 'Never';
        $customers = $this->fetch_customers();
        if (empty($customers)) {
            echo '<p>No customers found.</p>';
        }
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Customer Name</th><th>Company</th><th>Email</th><th>Phone</th><th>Program</th><th>Team</th><th>Student</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($customers as $customer) {
            echo '<tr>';
            echo '<td>' . esc_html($customer['DisplayName'] ?? '') . '</td>';
            echo '<td>' . esc_html($customer['CompanyName'] ?? '') . '</td>';
            echo '<td>' . esc_html($customer['PrimaryEmailAddr']['Address'] ?? '') . '</td>';
            echo '<td>' . esc_html($customer['PrimaryPhone']['FreeFormNumber'] ?? '') . '</td>';
            echo '<td>' . esc_html($customer['parsed_program'] ?? '') . '</td>';
            echo '<td>' . esc_html($customer['parsed_team'] ?? '') . '</td>';
            echo '<td>' . esc_html($customer['parsed_student'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        // Cache info and force refresh button
        echo '<div style="margin-top:15px;">';
        echo '<strong>Last cache:</strong> ' . esc_html($last_cached);
        echo ' <button type="button" class="button" id="force-refresh-customers">Force Refresh</button>';
        echo '</div>';
        // JS for force refresh
        echo '<script type="text/javascript">\njQuery(document).ready(function($){\n    $(document).off("click", "#force-refresh-customers").on("click", "#force-refresh-customers", function(){\n        var btn = $(this);\n        btn.prop("disabled", true).text("Refreshing...");\n        var nonce = (typeof qboCustomerListVars !== "undefined" ? qboCustomerListVars.nonce : "");\n        var ajaxurl_ = (typeof qboCustomerListVars !== "undefined" ? qboCustomerListVars.ajaxurl : (typeof ajaxurl !== "undefined" ? ajaxurl : ""));\n        if (!nonce) { alert("QBO AJAX nonce not found. Please reload the page."); btn.prop("disabled", false).text("Force Refresh"); return; }\n        $.post(ajaxurl_, {\n            action: "qbo_clear_customer_cache",\n            nonce: nonce\n        }, function(resp){\n            btn.text("Reloading...");\n            setTimeout(function(){ btn.prop("disabled", false).text("Force Refresh"); }, 2000);\n            // Reload customers\n            $("#customer-list-content").trigger("refreshCustomers");\n        });\n    });\n});\n</script>';
        // JS event for parent to reload customers (should be handled in your modal or page JS)
        echo '<script type="text/javascript">\njQuery(document).ready(function($){\n    $("#customer-list-content").off("refreshCustomers").on("refreshCustomers", function(){\n        var nonce = (typeof qboCustomerListVars !== "undefined" ? qboCustomerListVars.nonce : "");\n        var ajaxurl_ = (typeof qboCustomerListVars !== "undefined" ? qboCustomerListVars.ajaxurl : (typeof ajaxurl !== "undefined" ? ajaxurl : ""));\n        if (!nonce) { alert("QBO AJAX nonce not found. Please reload the page."); return; }\n        $.post(ajaxurl_, {action: "qbo_get_customer_list", security: nonce}, function(html){\n            $("#customer-list-content").html(html);\n        });\n    });\n});\n</script>';
        wp_die();
    }
    
    /**
     * Render customer list table with cache info and force refresh button (for non-AJAX/server-side)
     */
    public function render_customer_list_table() {
        $cache_key = 'qbo_recurring_billing_customers_cache';
        $cache = get_option($cache_key, array());
        $last_cached = isset($cache['timestamp']) ? date('M j, Y H:i:s', $cache['timestamp']) : 'Never';
        $customers = $this->fetch_customers();
        echo '<div id="customer-list-content">';
        if (empty($customers)) {
            echo '<p>No customers found.</p>';
        }
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Customer Name</th><th>Company</th><th>Email</th><th>Phone</th><th>Program</th><th>Team</th><th>Student</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($customers as $customer) {
            echo '<tr>';
            echo '<td>' . esc_html($customer['DisplayName'] ?? '') . '</td>';
            echo '<td>' . esc_html($customer['CompanyName'] ?? '') . '</td>';
            echo '<td>' . esc_html($customer['PrimaryEmailAddr']['Address'] ?? '') . '</td>';
            echo '<td>' . esc_html($customer['PrimaryPhone']['FreeFormNumber'] ?? '') . '</td>';
            echo '<td>' . esc_html($customer['parsed_program'] ?? '') . '</td>';
            echo '<td>' . esc_html($customer['parsed_team'] ?? '') . '</td>';
            echo '<td>' . esc_html($customer['parsed_student'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        // Cache info and force refresh button
        echo '<div style="margin-top:15px;">';
        echo '<strong>Last cache:</strong> ' . esc_html($last_cached);
        echo ' <button type="button" class="button" id="force-refresh-customers">Force Refresh</button>';
        echo '</div>';
        // JS for force refresh
        echo '<script type="text/javascript">
        jQuery(document).ready(function($){
            $(document).off("click", "#force-refresh-customers").on("click", "#force-refresh-customers", function(){
                var btn = $(this);
                btn.prop("disabled", true).text("Refreshing...");
                var nonce = (typeof qboCustomerListVars !== "undefined" ? qboCustomerListVars.nonce : "");
                var ajaxurl_ = (typeof qboCustomerListVars !== "undefined" ? qboCustomerListVars.ajaxurl : (typeof ajaxurl !== "undefined" ? ajaxurl : ""));
                if (!nonce) { alert("QBO AJAX nonce not found. Please reload the page."); btn.prop("disabled", false).text("Force Refresh"); return; }
                $.post(ajaxurl_, {
                    action: "qbo_clear_customer_cache",
                    nonce: nonce
                }, function(resp){
                    btn.text("Reloading...");
                    setTimeout(function(){ btn.prop("disabled", false).text("Force Refresh"); }, 2000);
                    // Reload customers
                    $("#customer-list-content").trigger("refreshCustomers");
                });
            });
        });
        </script>';
        // JS event for parent to reload customers
        echo '<script type="text/javascript">
        jQuery(document).ready(function($){
            $("#customer-list-content").off("refreshCustomers").on("refreshCustomers", function(){
                var nonce = (typeof qboCustomerListVars !== "undefined" ? qboCustomerListVars.nonce : "");
                var ajaxurl_ = (typeof qboCustomerListVars !== "undefined" ? qboCustomerListVars.ajaxurl : (typeof ajaxurl !== "undefined" ? ajaxurl : ""));
                if (!nonce) { alert("QBO AJAX nonce not found. Please reload the page."); return; }
                $.post(ajaxurl_, {action: "qbo_get_customer_list", security: nonce}, function(html){
                    $("#customer-list-content").html(html);
                });
            });
        });
        </script>';
        echo '</div>';
    }
    
    /**
     * Get authorization URL for QuickBooks OAuth
     */
    public function get_authorization_url() {
        $options = get_option($this->option_name);
        $state = wp_create_nonce('qbo_oauth_state');
        
        $params = array(
            'client_id' => $options['client_id'],
            'scope' => 'com.intuit.quickbooks.accounting',
            'redirect_uri' => $options['redirect_uri'],
            'response_type' => 'code',
            'access_type' => 'offline',
            'state' => $state
        );
        
        return 'https://appcenter.intuit.com/connect/oauth2?' . http_build_query($params);
    }

    /**
     * Enqueue admin scripts and localize qboCustomerListVars nonce for AJAX
     */
    public function enqueue_admin_scripts($hook) {
        // Only enqueue on QBO plugin admin pages
        $qbo_pages = array('toplevel_page_gears-dashboard', 'gears-dashboard_page_qbo-customer-list', 'gears-dashboard_page_qbo-teams', 'gears-dashboard_page_qbo-mentors', 'gears-dashboard_page_qbo-settings');
        if (in_array($hook, $qbo_pages)) {
            wp_enqueue_script('jquery');
            // Localize nonce for customer list AJAX
            wp_register_script('qbo-customer-list-js', false); // dummy handle for inline usage
            wp_enqueue_script('qbo-customer-list-js');
            wp_localize_script('qbo-customer-list-js', 'qboCustomerListVars', array(
                'nonce' => wp_create_nonce('qbo_get_customers'),
                'invoice_nonce' => wp_create_nonce('qbo_get_invoices'),
                'ajaxurl' => admin_url('admin-ajax.php')
            ));
            
            // Add global event delegation for view-invoice buttons
            add_action('admin_footer', array($this, 'add_global_invoice_view_js'));
        }
    }

    /**
     * Schedule automatic token refresh
     */
    public function schedule_token_refresh() {
        if (!wp_next_scheduled('qbo_refresh_token_hook')) {
            wp_schedule_event(time(), 'hourly', 'qbo_refresh_token_hook');
        }
    }

    /**
     * Cron job callback to refresh tokens
     */
    public function cron_refresh_token() {
        $options = get_option($this->option_name);
        
        if (isset($options['refresh_token']) && !empty($options['refresh_token'])) {
            $this->refresh_access_token();
        }
    }

    /**
     * Initialize scheduled events
     */
    public function init_scheduled_events() {
        add_action('qbo_refresh_token_hook', array($this, 'cron_refresh_token'));
        $this->schedule_token_refresh();
    }

    /**
     * Display connection status and token information
     */
    public function display_connection_status() {
        $options = get_option($this->option_name);
        
        echo '<h3>Connection Status</h3>';
        echo '<table class="form-table">';
        
        // Connection status
        echo '<tr>';
        echo '<th scope="row">QuickBooks Connection</th>';
        echo '<td>';
        if (isset($options['access_token']) && !empty($options['access_token'])) {
            echo '<span style="color: green;">✓ Connected</span>';
        } else {
            echo '<span style="color: red;">✗ Not Connected</span>';
        }
        echo '</td>';
        echo '</tr>';
        
        // Token refresh status
        if (isset($options['token_refreshed_at'])) {
            $last_refresh = date('Y-m-d H:i:s', $options['token_refreshed_at']);
            $next_refresh = date('Y-m-d H:i:s', $options['token_refreshed_at'] + 3000); // 50 minutes later
            
            echo '<tr>';
            echo '<th scope="row">Last Token Refresh</th>';
            echo '<td>' . $last_refresh . '</td>';
            echo '</tr>';
            
            echo '<tr>';
            echo '<th scope="row">Next Scheduled Refresh</th>';
            echo '<td>' . $next_refresh . ' (automatic)</td>';
            echo '</tr>';
        }
        
        // Refresh token status
        echo '<tr>';
        echo '<th scope="row">Refresh Token Available</th>';
        echo '<td>';
        if (isset($options['refresh_token']) && !empty($options['refresh_token'])) {
            echo '<span style="color: green;">✓ Available (enables automatic re-authentication)</span>';
        } else {
            echo '<span style="color: orange;">! Not Available (manual re-auth may be needed)</span>';
        }
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        
        if (isset($options['refresh_token']) && !empty($options['refresh_token'])) {
            echo '<p><strong>Your connection should remain active indefinitely!</strong> The plugin automatically refreshes your QuickBooks credentials every 50 minutes.</p>';
        }
    }

    /**
     * Manual token refresh button
     */
    public function manual_token_refresh() {
        if (isset($_POST['manual_refresh']) && wp_verify_nonce($_POST['qbo_manual_refresh_nonce'], 'qbo_manual_refresh')) {
            if ($this->refresh_access_token()) {
                echo '<div class="notice notice-success"><p>Token refreshed successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to refresh token. You may need to re-authenticate.</p></div>';
            }
        }
        
        echo '<form method="post" style="margin-top: 20px;">';
        wp_nonce_field('qbo_manual_refresh', 'qbo_manual_refresh_nonce');
        echo '<input type="submit" name="manual_refresh" class="button" value="Refresh Token Now" />';
        echo '<p class="description">Use this if you\'re experiencing connection issues.</p>';
        echo '</form>';
    }

    /**
     * AJAX handler for viewing invoice details
     */
    public function ajax_view_invoice() {
        if (!wp_verify_nonce($_POST['nonce'], 'qbo_get_invoices')) {
            wp_send_json_error('Invalid nonce');
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $invoice_id = sanitize_text_field($_POST['invoice_id']);
        $options = get_option($this->option_name);
        
        if (!isset($options['access_token']) || !isset($options['realm_id'])) {
            wp_send_json_error('Missing QBO credentials');
        }
        
        $access_token = $options['access_token'];
        $company_id = $options['realm_id'];
        $base_url = 'https://quickbooks.api.intuit.com';
        
        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        );
        
        // Fetch invoice details by Id using QBO SQL query
        $query = "SELECT * FROM Invoice WHERE Id = '{$invoice_id}'";
        $api_url = "$base_url/v3/company/$company_id/query?query=" . urlencode($query) . "&minorversion=65";
        
        $response = wp_remote_get($api_url, array('headers' => $headers, 'timeout' => 30));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Error fetching invoice details');
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!isset($data['QueryResponse']['Invoice'][0])) {
            wp_send_json_error('Invoice not found');
        }
        
        $invoice = $data['QueryResponse']['Invoice'][0];
        
        // Format the invoice data for display
        $formatted_invoice = array(
            'id' => $invoice['Id'],
            'doc_number' => $invoice['DocNumber'] ?? 'N/A',
            'txn_date' => isset($invoice['TxnDate']) ? date('M j, Y', strtotime($invoice['TxnDate'])) : 'N/A',
            'due_date' => isset($invoice['DueDate']) ? date('M j, Y', strtotime($invoice['DueDate'])) : 'N/A',
            'total_amt' => '$' . number_format($invoice['TotalAmt'] ?? 0, 2),
            'balance' => '$' . number_format($invoice['Balance'] ?? 0, 2),
            'customer_ref' => isset($invoice['CustomerRef']['name']) ? $invoice['CustomerRef']['name'] : 'Unknown',
            'line_items' => array()
        );
        
        // Extract line items if available
        if (isset($invoice['Line']) && is_array($invoice['Line'])) {
            foreach ($invoice['Line'] as $line) {
                if (isset($line['SalesItemLineDetail'])) {
                    $formatted_invoice['line_items'][] = array(
                        'description' => $line['Description'] ?? 'No description',
                        'quantity' => $line['SalesItemLineDetail']['Qty'] ?? 1,
                        'rate' => '$' . number_format($line['SalesItemLineDetail']['UnitPrice'] ?? 0, 2),
                        'amount' => '$' . number_format($line['Amount'] ?? 0, 2)
                    );
                }
            }
        }
        
        wp_send_json_success($formatted_invoice);
    }

    /**
     * Add global JavaScript for view-invoice button handling
     */
    public function add_global_invoice_view_js() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Global event delegation for view-invoice buttons
            $(document).off("click", ".view-invoice").on("click", ".view-invoice", function(e){
                e.preventDefault();
                console.log("Global view invoice button clicked");
                var invoiceId = $(this).data("invoice-id");
                var invoiceNumber = $(this).data("invoice-number");
                console.log("Invoice ID:", invoiceId, "Invoice Number:", invoiceNumber);
                var security = (typeof qboCustomerListVars !== "undefined") ? qboCustomerListVars.invoice_nonce : "";
                console.log("Security nonce:", security);
                console.log("qboCustomerListVars:", typeof qboCustomerListVars !== "undefined" ? qboCustomerListVars : "undefined");
                if (typeof qboViewInvoiceDetails === "function") {
                    qboViewInvoiceDetails(invoiceId, invoiceNumber, security);
                } else {
                    console.error("qboViewInvoiceDetails function not found");
                }
            });
        });

        // Global function to view invoice details - make it available globally
        window.qboViewInvoiceDetails = function(invoiceId, invoiceNumber, security) {
            console.log("qboViewInvoiceDetails called with:", invoiceId, invoiceNumber, security);
            // Create modal HTML
            var modalHtml = `
                <div id="invoice-detail-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999; display: flex; align-items: center; justify-content: center;">
                    <div style="background: white; border-radius: 8px; padding: 20px; max-width: 600px; max-height: 80vh; overflow-y: auto; position: relative;">
                        <span id="close-invoice-modal" style="position: absolute; top: 10px; right: 15px; font-size: 24px; cursor: pointer; color: #999;">&times;</span>
                        <h2>Invoice Details - #` + invoiceNumber + `</h2>
                        <div id="invoice-details-content">Loading...</div>
                    </div>
                </div>
            `;
            
            // Add modal to page
            jQuery("body").append(modalHtml);
            
            // Close modal functionality
            jQuery("#close-invoice-modal, #invoice-detail-modal").on("click", function(e) {
                if (e.target === this) {
                    jQuery("#invoice-detail-modal").remove();
                }
            });
            
            // Fetch invoice details
            var nonce = (typeof qboCustomerListVars !== "undefined") ? qboCustomerListVars.invoice_nonce : "";
            var ajaxurl = (typeof qboCustomerListVars !== "undefined") ? qboCustomerListVars.ajaxurl : (typeof ajaxurl !== "undefined" ? ajaxurl : "");
            
            if (!nonce || !ajaxurl) {
                jQuery("#invoice-details-content").html("<p>Error: Missing AJAX configuration. Please reload the page.</p>");
                return;
            }
            
            jQuery.post(ajaxurl, {
                action: "qbo_view_invoice",
                nonce: nonce,
                invoice_id: invoiceId
            }, function(response) {
                if (response.success) {
                    var invoice = response.data;
                    var html = `
                        <table class="form-table">
                            <tr><th>Invoice Number:</th><td>` + invoice.doc_number + `</td></tr>
                            <tr><th>Invoice Date:</th><td>` + invoice.txn_date + `</td></tr>
                            <tr><th>Due Date:</th><td>` + invoice.due_date + `</td></tr>
                            <tr><th>Customer:</th><td>` + invoice.customer_ref + `</td></tr>
                            <tr><th>Total Amount:</th><td><strong>` + invoice.total_amt + `</strong></td></tr>
                            <tr><th>Balance Due:</th><td><strong style="color: ` + (invoice.balance !== "$0.00" ? "red" : "green") + `;">` + invoice.balance + `</strong></td></tr>
                        </table>
                    `;
                    
                    if (invoice.line_items.length > 0) {
                        html += `<h3>Line Items</h3>
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th>Description</th>
                                            <th>Quantity</th>
                                            <th>Rate</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>`;
                        
                        invoice.line_items.forEach(function(item) {
                            html += `<tr>
                                        <td>` + item.description + `</td>
                                        <td>` + item.quantity + `</td>
                                        <td>` + item.rate + `</td>
                                        <td>` + item.amount + `</td>
                                    </tr>`;
                        });
                        
                        html += `</tbody></table>`;
                    }
                    
                    jQuery("#invoice-details-content").html(html);
                } else {
                    jQuery("#invoice-details-content").html("<p>Error loading invoice details: " + response.data + "</p>");
                }
            }).fail(function() {
                jQuery("#invoice-details-content").html("<p>Error loading invoice details. Please try again.</p>");
            });
        };
        </script>
        <?php
    }

    /**
     * Fetch recurring invoices for a specific team
     */
    public function fetch_recurring_invoices_by_team($team_id) {
        // Instantiate QBO_Recurring_Invoices with the current core instance
        $recurring_invoices_class = new QBO_Recurring_Invoices($this);
        $recurring_invoices = $recurring_invoices_class->fetch_recurring_invoices(); // Fetch all recurring invoices

        // Filter invoices by team_id using CustomField in the Invoice data
        $team_invoices = array_filter($recurring_invoices, function($invoice) use ($team_id) {
            if (isset($invoice['Invoice']['CustomField'])) {
                return array_search($team_id, array_column($invoice['Invoice']['CustomField'], 'Value')) !== false;
            }
            return false;
        });

        return $team_invoices;
    }

    /**
     * Fetch recurring invoices for a specific member
     */
    public function fetch_recurring_invoices_by_member($member_id) {
        // Instantiate QBO_Recurring_Invoices with the current core instance
        $recurring_invoices_class = new QBO_Recurring_Invoices($this);
        $recurring_invoices = $recurring_invoices_class->fetch_recurring_invoices(); // Fetch all recurring invoices

        // Filter invoices by member_id using customer_ref field
        $member_invoices = array_filter($recurring_invoices, function($invoice) use ($member_id) {
            // Check if this recurring invoice belongs to the member using customer_ref
            if (isset($invoice['customer_ref']['value'])) {
                return (string)$invoice['customer_ref']['value'] === (string)$member_id;
            }
            // Also check the nested Invoice structure as fallback
            if (isset($invoice['Invoice']['CustomerRef']['value'])) {
                return (string)$invoice['Invoice']['CustomerRef']['value'] === (string)$member_id;
            }
            return false;
        });

        return $member_invoices;
    }
}
