<?php
/**
 * QBO Settings Class
 * 
 * Handles all settings page functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class QBO_Settings {
    
    private $core;
    private $option_name;
    
    public function __construct($core) {
        $this->core = $core;
        $this->option_name = $core->get_option_name();
        
        add_action('admin_init', array($this, 'settings_init'));
    }
    
    /**
     * Initialize settings
     */
    public function settings_init() {
        register_setting('qbo_recurring_billing', $this->option_name);
        
        add_settings_section(
            'qbo_recurring_billing_section',
            'QuickBooks Online API Settings',
            array($this, 'settings_section_callback'),
            'qbo_recurring_billing'
        );
        
        add_settings_field(
            'client_id',
            'Client ID',
            array($this, 'client_id_render'),
            'qbo_recurring_billing',
            'qbo_recurring_billing_section'
        );
        
        add_settings_field(
            'client_secret',
            'Client Secret',
            array($this, 'client_secret_render'),
            'qbo_recurring_billing',
            'qbo_recurring_billing_section'
        );
        
        add_settings_field(
            'redirect_uri',
            'Redirect URI',
            array($this, 'redirect_uri_render'),
            'qbo_recurring_billing',
            'qbo_recurring_billing_section'
        );
        
        add_settings_field(
            'realm_id',
            'Realm ID (Company ID)',
            array($this, 'realm_id_render'),
            'qbo_recurring_billing',
            'qbo_recurring_billing_section'
        );
        
        add_settings_field(
            'access_token',
            'Access Token',
            array($this, 'access_token_render'),
            'qbo_recurring_billing',
            'qbo_recurring_billing_section'
        );
        
        add_settings_field(
            'refresh_token',
            'Refresh Token',
            array($this, 'refresh_token_render'),
            'qbo_recurring_billing',
            'qbo_recurring_billing_section'
        );
    }
    
    /**
     * Settings section callback
     */
    public function settings_section_callback() {
        echo '<div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa; margin-bottom: 20px;">';
        echo '<h3>Setup Instructions</h3>';
        echo '<ol>';
        echo '<li><strong>Create a QuickBooks App:</strong> Go to <a href="https://developer.intuit.com/app/developer/myapps" target="_blank">Intuit Developer Dashboard</a> and create a new app.</li>';
        echo '<li><strong>Get Client ID & Secret:</strong> From your app dashboard, copy the Client ID and Client Secret.</li>';
        echo '<li><strong>Set Redirect URI:</strong> In your app settings, add this exact URL as a redirect URI: <code>' . admin_url('admin.php?page=qbo-settings') . '</code></li>';
        echo '<li><strong>Authorize:</strong> After saving settings, click "Authorize with QuickBooks" to get your tokens.</li>';
        echo '<li><strong>Get Realm ID:</strong> The Company ID (Realm ID) will be automatically saved during authorization.</li>';
        echo '</ol>';
        echo '<div style="background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin-top: 15px;">';
        echo '<strong>Important:</strong> Make sure to connect to a <em>live</em> QuickBooks company, not a sandbox account. ';
        echo 'If you get "ApplicationAuthorizationFailed" errors, you may need to re-authorize or check your app permissions.';
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Render client ID field
     */
    public function client_id_render() {
        $options = get_option($this->option_name);
        echo '<input type="text" name="' . $this->option_name . '[client_id]" value="' . (isset($options['client_id']) ? esc_attr($options['client_id']) : '') . '" style="width: 400px;" />';
        echo '<p class="description">Your app\'s Client ID from the Intuit Developer Dashboard.</p>';
    }
    
    /**
     * Render client secret field
     */
    public function client_secret_render() {
        $options = get_option($this->option_name);
        echo '<input type="password" name="' . $this->option_name . '[client_secret]" value="' . (isset($options['client_secret']) ? esc_attr($options['client_secret']) : '') . '" style="width: 400px;" />';
        echo '<p class="description">Your app\'s Client Secret from the Intuit Developer Dashboard.</p>';
    }
    
    /**
     * Render redirect URI field
     */
    public function redirect_uri_render() {
        $options = get_option($this->option_name);
        $default_uri = admin_url('admin.php?page=qbo-settings');
        echo '<input type="url" name="' . $this->option_name . '[redirect_uri]" value="' . (isset($options['redirect_uri']) ? esc_attr($options['redirect_uri']) : esc_attr($default_uri)) . '" style="width: 400px;" />';
        echo '<p class="description">The redirect URI configured in your QuickBooks app. Default: <code>' . esc_html($default_uri) . '</code></p>';
    }
    
    /**
     * Render realm ID field
     */
    public function realm_id_render() {
        $options = get_option($this->option_name);
        echo '<input type="text" name="' . $this->option_name . '[realm_id]" value="' . (isset($options['realm_id']) ? esc_attr($options['realm_id']) : '') . '" style="width: 400px;" />';
        echo '<p class="description">Your QuickBooks Company ID (automatically filled during OAuth authorization).</p>';
    }
    
    /**
     * Render access token field
     */
    public function access_token_render() {
        $options = get_option($this->option_name);
        echo '<input type="password" name="' . $this->option_name . '[access_token]" value="' . (isset($options['access_token']) ? esc_attr($options['access_token']) : '') . '" style="width: 400px;" />';
        echo '<p class="description">OAuth access token (automatically filled during authorization).</p>';
    }
    
    /**
     * Render refresh token field
     */
    public function refresh_token_render() {
        $options = get_option($this->option_name);
        echo '<input type="password" name="' . $this->option_name . '[refresh_token]" value="' . (isset($options['refresh_token']) ? esc_attr($options['refresh_token']) : '') . '" style="width: 400px;" />';
        echo '<p class="description">OAuth refresh token (automatically filled during authorization).</p>';
    }
    
    /**
     * Render settings page
     */
    public function settings_page() {
        if (isset($_GET['oauth']) && $_GET['oauth'] === 'success') {
            echo '<div class="notice notice-success"><p>OAuth authorization successful! Your tokens have been saved.</p></div>';
        } elseif (isset($_GET['oauth']) && $_GET['oauth'] === 'error') {
            echo '<div class="notice notice-error"><p>OAuth authorization failed. Please try again.</p></div>';
        }
        
        // Display connection status
        $this->core->display_connection_status();
        
        echo "<form action='options.php' method='post'>";
        settings_fields('qbo_recurring_billing');
        do_settings_sections('qbo_recurring_billing');
        submit_button();
        echo "</form>";
        
        $options = get_option($this->option_name);
        if (isset($options['client_id']) && isset($options['client_secret']) && isset($options['redirect_uri'])) {
            $auth_url = $this->core->get_authorization_url();
            echo '<a href="' . esc_url($auth_url) . '" class="button button-primary" style="margin-top:20px;">Authorize with QuickBooks</a>';
        }
        
        // Manual token refresh
        $this->core->manual_token_refresh();
    }
}
