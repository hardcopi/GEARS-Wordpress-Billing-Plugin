<?php
/**
 * QBO Customers Class
 * 
 * Handles customer list functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class QBO_Customers {
    
    private $core;
    private $option_name;
    
    public function __construct($core) {
        $this->core = $core;
        $this->option_name = $core->get_option_name();
    }
    
    /**
     * Render customer list page
     */
    public function render_page() {
        echo '<div class="wrap">';
        echo '<h1>Customer List</h1>';
        
        $options = get_option($this->core->get_option_name());
        // Debug output for troubleshooting
        if (current_user_can('manage_options')) {
            // echo '<pre style="background:#fffbe6;border:1px solid #e2c089;padding:10px;margin:10px 0;max-width:800px;overflow:auto;"><strong>QBO Options Debug:</strong>\n';
            // print_r($options);
            // echo '</pre>';
        }
        if (!isset($options['access_token']) || !isset($options['realm_id'])) {
            echo '<div class="notice notice-error"><p>Please configure your QuickBooks settings first.</p></div>';
            return;
        }
        
        // Get search and pagination parameters
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'FirstName';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'asc';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        
        // Fetch customers from QBO
        $customers = $this->core->fetch_customers();
        
        if ($customers === false) {
            echo '<div class="notice notice-error"><p>Failed to fetch customers. Please check your API credentials.</p></div>';
            return;
        }
        
        if (empty($customers)) {
            echo '<div class="notice notice-warning"><p>No customers found.</p></div>';
            return;
        }
        
        // Parse company name data for each customer
        foreach ($customers as &$customer) {
            $parsed = $this->core->parse_company_name($customer['CompanyName'] ?? '');
            $customer['Program'] = $parsed['program'];
            $customer['Team'] = $parsed['team'];
            $customer['Student'] = $parsed['student'];
            
            // Get first and last name from QuickBooks customer fields
            $customer['FirstName'] = $customer['GivenName'] ?? '';
            $customer['LastName'] = $customer['FamilyName'] ?? '';
            
            // If no first/last name in QBO fields and we have a parsed student name, use that
            if (empty($customer['FirstName']) && empty($customer['LastName']) && !empty($parsed['student'])) {
                $name_parts = $this->core->parse_student_name($parsed['student']);
                $customer['FirstName'] = $name_parts['first'];
                $customer['LastName'] = $name_parts['last'];
            }
            
            // If no program and team were parsed, use company name as contact name
            if (empty($parsed['program']) && empty($parsed['team'])) {
                $customer['ContactName'] = $customer['CompanyName'] ?? '';
            } else {
                // If program and team were parsed, use the QBO customer name for contact name
                $customer['ContactName'] = $customer['Name'] ?? '';
            }
        }
        unset($customer); // Break reference
        
        // Apply filters and sorting
        $customers = $this->filter_and_sort_customers($customers, $search, $orderby, $order);
        
        // Calculate pagination
        $total_customers = count($customers);
        $total_pages = ceil($total_customers / $per_page);
        $offset = ($paged - 1) * $per_page;
        $customers_page = array_slice($customers, $offset, $per_page);
        
        // Render the page
        $this->render_search($search, $orderby, $order);
        $this->render_pagination($total_customers, $total_pages, $paged, $search, $orderby, $order, 'top');
        $this->render_customer_table($customers_page, $orderby, $order, $search);
        $this->render_pagination($total_customers, $total_pages, $paged, $search, $orderby, $order, 'bottom');
        // Add cache info and force refresh button below the table
        $cache_key = 'qbo_recurring_billing_customers_cache';
        $cache = get_option($this->core->get_option_name(), array());
        $last_cached = 'Never';
        if (isset($cache_key)) {
            $cache_data = get_option($cache_key, array());
            if (isset($cache_data['timestamp'])) {
                $last_cached = date('M j, Y H:i:s', $cache_data['timestamp']);
            }
        }
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
                    // Reload page
                    window.location.reload();
                });
            });
        });
        </script>';
        
        echo '</div>';
    }
    
    /**
     * Filter and sort customers
     */
    private function filter_and_sort_customers($customers, $search, $orderby, $order) {
        // Filter customers based on search only
        if (!empty($search)) {
            $customers = array_filter($customers, function($customer) use ($search) {
                $search_lower = strtolower($search);
                $fields = array(
                    strtolower($customer['ContactName'] ?? ''),
                    strtolower($customer['PrimaryEmailAddr']['Address'] ?? ''),
                    strtolower($customer['PrimaryPhone']['FreeFormNumber'] ?? ''),
                    strtolower($customer['CompanyName'] ?? ''),
                    strtolower($customer['Program'] ?? ''),
                    strtolower($customer['Team'] ?? ''),
                    strtolower($customer['Student'] ?? ''),
                    strtolower($customer['FirstName'] ?? ''),
                    strtolower($customer['LastName'] ?? '')
                );
                
                foreach ($fields as $field) {
                    if (strpos($field, $search_lower) !== false) {
                        return true;
                    }
                }
                return false;
            });
        }
        
        // Sort customers
        usort($customers, function($a, $b) use ($orderby, $order) {
            $val_a = '';
            $val_b = '';
            
            switch ($orderby) {
                case 'Id':
                    $val_a = $a['Id'] ?? '';
                    $val_b = $b['Id'] ?? '';
                    break;
                case 'Name':
                    $val_a = $a['ContactName'] ?? '';
                    $val_b = $b['ContactName'] ?? '';
                    break;
                case 'Email':
                    $val_a = $a['PrimaryEmailAddr']['Address'] ?? '';
                    $val_b = $b['PrimaryEmailAddr']['Address'] ?? '';
                    break;
                case 'Phone':
                    $val_a = $a['PrimaryPhone']['FreeFormNumber'] ?? '';
                    $val_b = $b['PrimaryPhone']['FreeFormNumber'] ?? '';
                    break;
                case 'Balance':
                    $val_a = floatval($a['Balance'] ?? 0);
                    $val_b = floatval($b['Balance'] ?? 0);
                    return ($order === 'asc') ? ($val_a - $val_b) : ($val_b - $val_a);
                case 'Program':
                    $val_a = $a['Program'] ?? '';
                    $val_b = $b['Program'] ?? '';
                    break;
                case 'Team':
                    $val_a = $a['Team'] ?? '';
                    $val_b = $b['Team'] ?? '';
                    break;
                case 'Student':
                    $val_a = $a['Student'] ?? '';
                    $val_b = $b['Student'] ?? '';
                    break;
                case 'FirstName':
                    $val_a = $a['FirstName'] ?? '';
                    $val_b = $b['FirstName'] ?? '';
                    break;
                case 'LastName':
                    $val_a = $a['LastName'] ?? '';
                    $val_b = $b['LastName'] ?? '';
                    break;
                case 'Company':
                    $val_a = $a['CompanyName'] ?? '';
                    $val_b = $b['CompanyName'] ?? '';
                    break;
            }
            
            $result = strcasecmp($val_a, $val_b);
            return ($order === 'asc') ? $result : -$result;
        });
        
        return $customers;
    }
    
    /**
     * Render search section
     */
    private function render_search($search, $orderby, $order) {
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        // Empty left side - could add bulk actions here in future
        echo '</div>';
        
        // Search form on the right
        echo '<div class="alignright">';
        echo '<form method="get" style="display: inline-block;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($_GET['page']) . '" />';
        if (!empty($orderby)) echo '<input type="hidden" name="orderby" value="' . esc_attr($orderby) . '" />';
        if (!empty($order)) echo '<input type="hidden" name="order" value="' . esc_attr($order) . '" />';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Search customers..." style="margin-right: 5px;" />';
        echo '<input type="submit" class="button" value="Search" />';
        if (!empty($search)) {
            $clear_url = admin_url('admin.php?page=' . $_GET['page']);
            if (!empty($orderby)) $clear_url .= '&orderby=' . urlencode($orderby);
            if (!empty($order)) $clear_url .= '&order=' . urlencode($order);
            echo ' <a href="' . $clear_url . '" class="button">Clear Search</a>';
        }
        echo '</form>';
        echo '</div>';
    }
    
    /**
     * Render pagination
     */
    private function render_pagination($total_customers, $total_pages, $paged, $search, $orderby, $order, $position) {
        if ($total_customers > 0) {
            $per_page = 20;
            $offset = ($paged - 1) * $per_page;
            $start = $offset + 1;
            $end = min($offset + $per_page, $total_customers);
            
            echo '<div class="tablenav-pages">';
            echo '<span class="displaying-num">' . sprintf('%d items', $total_customers) . '</span>';
            
            if ($total_pages > 1) {
                $base_url = admin_url('admin.php?page=' . $_GET['page']);
                if (!empty($search)) $base_url .= '&s=' . urlencode($search);
                if (!empty($orderby)) $base_url .= '&orderby=' . urlencode($orderby);
                if (!empty($order)) $base_url .= '&order=' . urlencode($order);
                
                echo '<span class="pagination-links">';
                
                // Previous page
                if ($paged > 1) {
                    echo '<a class="prev-page button" href="' . $base_url . '&paged=' . ($paged - 1) . '">&lsaquo;</a>';
                } else {
                    echo '<span class="prev-page button disabled">&lsaquo;</span>';
                }
                
                // Page numbers
                echo '<span class="paging-input">';
                echo '<span class="tablenav-paging-text">' . $paged . ' of ' . $total_pages . '</span>';
                echo '</span>';
                
                // Next page
                if ($paged < $total_pages) {
                    echo '<a class="next-page button" href="' . $base_url . '&paged=' . ($paged + 1) . '">&rsaquo;</a>';
                } else {
                    echo '<span class="next-page button disabled">&rsaquo;</span>';
                }
                
                echo '</span>';
            }
            echo '</div>';
        }
        
        if ($position === 'top') {
            echo '</div>'; // Close tablenav top
        } elseif ($position === 'bottom') {
            echo '<div class="tablenav bottom">';
            echo '<div class="tablenav-pages">';
            echo '<span class="displaying-num">' . sprintf('%d items', $total_customers) . '</span>';
            echo '</div>';
            echo '</div>';
        }
    }
    
    /**
     * Render customer table
     */
    private function render_customer_table($customers_page, $orderby, $order, $search) {
        // Helper function for sortable column headers
        $sort_url = function($column) use ($search, $orderby, $order) {
            $base_url = admin_url('admin.php?page=' . $_GET['page']);
            $params = array();
            
            if (!empty($search)) $params['s'] = $search;
            
            $new_order = 'asc';
            if ($orderby === $column && $order === 'asc') {
                $new_order = 'desc';
            }
            
            $params['orderby'] = $column;
            $params['order'] = $new_order;
            
            return $base_url . '&' . http_build_query($params);
        };
        
        // Get sort indicator
        $sort_indicator = function($column) use ($orderby, $order) {
            if ($orderby === $column) {
                return $order === 'asc' ? ' ↑' : ' ↓';
            }
            return '';
        };
        
        // Display customers table
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th><a href="' . $sort_url('Id') . '">ID' . $sort_indicator('Id') . '</a></th>';
        echo '<th><a href="' . $sort_url('FirstName') . '">First Name' . $sort_indicator('FirstName') . '</a></th>';
        echo '<th><a href="' . $sort_url('LastName') . '">Last Name' . $sort_indicator('LastName') . '</a></th>';
        echo '<th><a href="' . $sort_url('Name') . '">Company Name' . $sort_indicator('Name') . '</a></th>';
        echo '<th><a href="' . $sort_url('Balance') . '">Balance' . $sort_indicator('Balance') . '</a></th>';
        echo '<th>Actions</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        if (empty($customers_page)) {
            echo '<tr><td colspan="6" style="text-align: center; padding: 20px;">No customers found matching your search.</td></tr>';
        } else {
            foreach ($customers_page as $customer) {
                echo '<tr>';
                echo '<td>' . esc_html($customer['Id']) . '</td>';
                echo '<td>' . esc_html($customer['FirstName'] ?? 'N/A') . '</td>';
                echo '<td>' . esc_html($customer['LastName'] ?? 'N/A') . '</td>';
                echo '<td>' . esc_html($customer['ContactName']) . '</td>';
                echo '<td>' . esc_html(isset($customer['Balance']) ? '$' . number_format($customer['Balance'], 2) : '$0.00') . '</td>';
                echo '<td><a href="' . esc_url(admin_url('admin.php?page=qbo-view-invoices&customer_id=' . urlencode($customer['Id']))) . '" class="button button-small" title="View Customer Details">View Details</a></td>';
                echo '</tr>';
            }
        }
        
        echo '</tbody></table>';
        // Cache info and force refresh button (always show under table)
        $cache_key = 'qbo_recurring_billing_customers_cache';
        $cache = get_option($cache_key, array());
        $last_cached = isset($cache['timestamp']) ? date('M j, Y H:i:s', $cache['timestamp']) : 'Never';
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
                    window.location.reload();
                });
            });
        });
        </script>';
    }
    
    /**
     * Customer list page (stub)
     */
    public function customer_list_page() {
        // --- Begin migrated code from old plugin ---
        echo '<div class="wrap">';
        echo '<h1>Customer List</h1>';
        $options = get_option($this->option_name);
        // Debug output for troubleshooting
        if (current_user_can('manage_options')) {
            // echo '<pre style="background:#fffbe6;border:1px solid #e2c089;padding:10px;margin:10px 0;max-width:800px;overflow:auto;"><strong>QBO Options Debug:</strong>\n';
            // print_r($options);
            // echo '</pre>';
        }
        if (!isset($options['access_token']) || !isset($options['realm_id'])) {
            echo '<div class="notice notice-error"><p>Please configure your QuickBooks settings first.</p></div>';
            return;
        }
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'FirstName';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'asc';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $customers = $this->core->fetch_customers();
        if ($customers === false) {
            echo '<div class="notice notice-error"><p>Failed to fetch customers. Please check your API credentials.</p></div>';
            return;
        }
        if (empty($customers)) {
            echo '<div class="notice notice-warning"><p>No customers found.</p></div>';
            return;
        }
        // Parse company name data for each customer
        foreach ($customers as &$customer) {
            $parsed = $this->core->parse_company_name($customer['CompanyName'] ?? '');
            $customer['Program'] = $parsed['program'];
            $customer['Team'] = $parsed['team'];
            $customer['Student'] = $parsed['student'];
            
            // Get first and last name from QuickBooks customer fields
            $customer['FirstName'] = $customer['GivenName'] ?? '';
            $customer['LastName'] = $customer['FamilyName'] ?? '';
            
            // If no first/last name in QBO fields and we have a parsed student name, use that
            if (empty($customer['FirstName']) && empty($customer['LastName']) && !empty($parsed['student'])) {
                $name_parts = $this->core->parse_student_name($parsed['student']);
                $customer['FirstName'] = $name_parts['first'];
                $customer['LastName'] = $name_parts['last'];
            }
            
            // If no program and team were parsed, use company name as contact name
            if (empty($parsed['program']) && empty($parsed['team'])) {
                $customer['ContactName'] = $customer['CompanyName'] ?? '';
            } else {
                // If program and team were parsed, use the QBO customer name for contact name
                $customer['ContactName'] = $customer['Name'] ?? '';
            }
        }
        unset($customer); // Break reference
        
        // Apply filters and sorting
        $customers = $this->filter_and_sort_customers($customers, $search, $orderby, $order);
        
        // Calculate pagination
        $total_customers = count($customers);
        $total_pages = ceil($total_customers / $per_page);
        $offset = ($paged - 1) * $per_page;
        $customers_page = array_slice($customers, $offset, $per_page);
        
        // Render the page
        $this->render_search($search, $orderby, $order);
        $this->render_pagination($total_customers, $total_pages, $paged, $search, $orderby, $order, 'top');
        $this->render_customer_table($customers_page, $orderby, $order, $search);
        $this->render_pagination($total_customers, $total_pages, $paged, $search, $orderby, $order, 'bottom');
        
        echo '</div>';
        // --- End migrated code ---
    }
}
