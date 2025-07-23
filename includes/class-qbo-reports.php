<?php
/**
 * Reports functionality for QBO GEARS Plugin
 */
class QBO_Reports {
    private $core;

    public function __construct($core) {
        $this->core = $core;
        
        // Add AJAX handlers for reports
        add_action('wp_ajax_qbo_generate_report', array($this, 'ajax_generate_report'));
    }

    /**
     * Render the main reports page
     */
    public function reports_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        echo '<div class="wrap">';
        echo '<h1>Reports</h1>';
        echo '<p>Generate various reports for your GEARS program data.</p>';
        
        echo '<div class="report-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 30px;">';
        
        // Teams Report Card
        echo '<div class="report-card">';
        echo '<h2>Teams Report</h2>';
        echo '<p>Comprehensive report of all teams with detailed information including mentor and student counts.</p>';
        echo '<ul>';
        echo '<li>Team information and program details</li>';
        echo '<li>Hall of Fame status and social media links</li>';
        echo '<li>Mentor and student count aggregates</li>';
        echo '<li>Contact information and creation dates</li>';
        echo '</ul>';
        echo '<button class="button button-primary" onclick="generateReport(\'teams\')">Download Teams Report</button>';
        echo '</div>';
        
        // Customers Report
        echo '<div class="report-card">';
        echo '<h2>Customers Report</h2>';
        echo '<p>QuickBooks customer data with parsed program and team information for billing analysis.</p>';
        echo '<ul>';
        echo '<li>Customer contact information</li>';
        echo '<li>Parsed program and team assignments</li>';
        echo '<li>Billing addresses and payment details</li>';
        echo '<li>Account status and creation dates</li>';
        echo '</ul>';
        echo '<button class="button button-primary" onclick="generateReport(\'customers\')">Download Customers Report</button>';
        echo '</div>';
        
        // Invoices Report
        echo '<div class="report-card">';
        echo '<h2>Recurring Invoices Report</h2>';
        echo '<p>Active and inactive recurring invoices with customer and schedule details.</p>';
        echo '<ul>';
        echo '<li>Invoice amounts and billing schedules</li>';
        echo '<li>Customer information and status</li>';
        echo '<li>Next due dates and frequencies</li>';
        echo '<li>Program and team associations</li>';
        echo '</ul>';
        echo '<button class="button button-primary" onclick="generateReport(\'invoices\')">Download Invoices Report</button>';
        echo '</div>';
        
        // Students/Members Report
        echo '<div class="report-card">';
        echo '<h2>Students Report</h2>';
        echo '<p>Complete student roster with team assignments and academic information.</p>';
        echo '<ul>';
        echo '<li>Student names and grade levels</li>';
        echo '<li>Current team assignments</li>';
        echo '<li>First year participation tracking</li>';
        echo '<li>Customer ID associations</li>';
        echo '</ul>';
        echo '<button class="button button-primary" onclick="generateReport(\'students\')">Download Students Report</button>';
        echo '</div>';
        
        // Alumni Report
        echo '<div class="report-card">';
        echo '<h2>Alumni Report</h2>';
        echo '<p>Former students who have graduated from the GEARS programs with historical data.</p>';
        echo '<ul>';
        echo '<li>Graduate information and contact details</li>';
        echo '<li>Historical team participation</li>';
        echo '<li>Program completion records</li>';
        echo '<li>Graduation years and achievements</li>';
        echo '</ul>';
        echo '<button class="button button-primary" onclick="generateReport(\'alumni\')">Download Alumni Report</button>';
        echo '</div>';
        
        // Mentors Report
        echo '<div class="report-card">';
        echo '<h2>Mentors Report</h2>';
        echo '<p>Mentor directory with specialties, team assignments, and contact information.</p>';
        echo '<ul>';
        echo '<li>Mentor contact information</li>';
        echo '<li>Specialties and expertise areas</li>';
        echo '<li>Current team assignments</li>';
        echo '<li>Bio and background information</li>';
        echo '</ul>';
        echo '<button class="button button-primary" onclick="generateReport(\'mentors\')">Download Mentors Report</button>';
        echo '</div>';
        
        // Add more report cards here in the future
        
        echo '</div>'; // End report-cards
        echo '</div>'; // End wrap
        
        // Add JavaScript for report generation
        $this->add_reports_javascript();
    }

    /**
     * Add JavaScript for handling report generation
     */
    private function add_reports_javascript() {
        ?>
        <script type="text/javascript">
        function generateReport(reportType) {
            var button = event.target;
            var originalText = button.textContent;
            
            // Show loading state
            button.textContent = 'Generating Report...';
            button.disabled = true;
            
            // Make AJAX request to generate the report
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'qbo_generate_report',
                    report_type: reportType,
                    nonce: '<?php echo wp_create_nonce('qbo_generate_report'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        // Create download link and trigger download
                        var link = document.createElement('a');
                        link.href = response.data.download_url;
                        link.download = response.data.filename;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        
                        alert(response.data.filename + ' generated successfully!');
                    } else {
                        alert('Error generating report: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Error generating report. Please try again.');
                },
                complete: function() {
                    // Restore button state
                    button.textContent = originalText;
                    button.disabled = false;
                }
            });
        }
        </script>
        <?php
    }

    /**
     * AJAX handler for generating reports
     */
    public function ajax_generate_report() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'qbo_generate_report')) {
            wp_send_json_error('Security check failed');
        }

        // Verify user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $report_type = sanitize_text_field($_POST['report_type']);

        try {
            switch ($report_type) {
                case 'teams':
                    $result = $this->generate_teams_excel_report();
                    break;
                case 'customers':
                    $result = $this->generate_customers_excel_report();
                    break;
                case 'invoices':
                    $result = $this->generate_invoices_excel_report();
                    break;
                case 'students':
                    $result = $this->generate_students_excel_report();
                    break;
                case 'alumni':
                    $result = $this->generate_alumni_excel_report();
                    break;
                case 'mentors':
                    $result = $this->generate_mentors_excel_report();
                    break;
                default:
                    wp_send_json_error('Invalid report type');
                    return;
            }
            
            if ($result) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error('Failed to generate report');
            }
        } catch (Exception $e) {
            wp_send_json_error('Error generating report: ' . $e->getMessage());
        }
    }

    /**
     * Generate Teams Excel Report
     */
    private function generate_teams_excel_report() {
        global $wpdb;

        // Get teams data with counts
        $table_teams = $wpdb->prefix . 'gears_teams';
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        $table_students = $wpdb->prefix . 'gears_students';

        $query = "
            SELECT t.*, 
                   COALESCE(m.mentor_count, 0) as mentor_count,
                   COALESCE(s.student_count, 0) as student_count
            FROM $table_teams t
            LEFT JOIN (
                SELECT team_id, COUNT(*) as mentor_count 
                FROM $table_mentors 
                WHERE team_id IS NOT NULL
                GROUP BY team_id
            ) m ON t.id = m.team_id
            LEFT JOIN (
                SELECT team_id, COUNT(*) as student_count 
                FROM $table_students 
                WHERE team_id IS NOT NULL AND (grade != 'Alumni' OR grade IS NULL)
                GROUP BY team_id
            ) s ON t.id = s.team_id
            WHERE (t.archived = 0 OR t.archived IS NULL)
            ORDER BY t.team_name
        ";

        $teams = $wpdb->get_results($query);

        if (empty($teams)) {
            throw new Exception('No teams found to export');
        }

        // Use PHP's built-in functions to create CSV (which Excel can open)
        // This avoids needing external libraries
        return $this->create_teams_csv_report($teams);
    }

    /**
     * Create Teams CSV Report (Excel compatible)
     */
    private function create_teams_csv_report($teams) {
        // Create uploads directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $reports_dir = $upload_dir['basedir'] . '/gears-reports';
        
        if (!file_exists($reports_dir)) {
            wp_mkdir_p($reports_dir);
        }

        // Generate filename with timestamp - use .xls extension for Excel XML format
        $filename = 'teams-report-' . date('Y-m-d-H-i-s') . '.xls';
        $filepath = $reports_dir . '/' . $filename;

        // Create Excel XML content with styling and filtering
        $excel_content = $this->create_excel_xml_content($teams);
        
        // Write Excel content to file
        if (file_put_contents($filepath, $excel_content) === false) {
            throw new Exception('Unable to create report file');
        }

        // Return download information
        $download_url = $upload_dir['baseurl'] . '/gears-reports/' . $filename;
        
        return array(
            'filename' => $filename,
            'download_url' => $download_url,
            'filepath' => $filepath
        );
    }

    /**
     * Create Excel XML content with styling and filtering
     */
    private function create_excel_xml_content($teams) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title>Teams Report</Title>
  <Author>GEARS WordPress Plugin</Author>
  <Created>' . date('Y-m-d\TH:i:s\Z') . '</Created>
 </DocumentProperties>
 <ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel">
  <WindowHeight>12000</WindowHeight>
  <WindowWidth>20000</WindowWidth>
  <WindowTopX>0</WindowTopX>
  <WindowTopY>0</WindowTopY>
  <ProtectStructure>False</ProtectStructure>
  <ProtectWindows>False</ProtectWindows>
 </ExcelWorkbook>
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Bottom"/>
   <Borders/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
   <Interior/>
   <NumberFormat/>
   <Protection/>
  </Style>
  <Style ss:ID="HeaderStyle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Bottom"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#000000"/>
   </Borders>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Bold="1"/>
   <Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="DataStyle">
   <Alignment ss:Vertical="Bottom"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D0D0D0"/>
   </Borders>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Teams Report">
  <Table ss:ExpandedColumnCount="12" ss:ExpandedRowCount="' . (count($teams) + 1) . '" x:FullColumns="1" x:FullRows="1" ss:DefaultRowHeight="15">';

        // Add column widths for better formatting
        $xml .= '
   <Column ss:AutoFitWidth="0" ss:Width="120"/>
   <Column ss:AutoFitWidth="0" ss:Width="80"/>
   <Column ss:AutoFitWidth="0" ss:Width="100"/>
   <Column ss:AutoFitWidth="0" ss:Width="200"/>
   <Column ss:AutoFitWidth="0" ss:Width="80"/>
   <Column ss:AutoFitWidth="0" ss:Width="70"/>
   <Column ss:AutoFitWidth="0" ss:Width="70"/>
   <Column ss:AutoFitWidth="0" ss:Width="120"/>
   <Column ss:AutoFitWidth="0" ss:Width="120"/>
   <Column ss:AutoFitWidth="0" ss:Width="120"/>
   <Column ss:AutoFitWidth="0" ss:Width="120"/>
   <Column ss:AutoFitWidth="0" ss:Width="100"/>';

        // Define headers
        $headers = array(
            'Team Name',
            'Team Number',
            'Program',
            'Description',
            'Hall of Fame',
            'Mentor Count',
            'Student Count',
            'Facebook',
            'Twitter',
            'Instagram',
            'Website',
            'Created Date'
        );

        // Add header row with styling
        $xml .= '
   <Row ss:AutoFitHeight="0" ss:Height="20">';
        
        foreach ($headers as $header) {
            $xml .= '
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>';
        }
        
        $xml .= '
   </Row>';

        // Add data rows
        foreach ($teams as $team) {
            $xml .= '
   <Row>';
            
            // Team Name
            $xml .= '
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($team->team_name) . '</Data></Cell>';
            
            // Team Number
            $xml .= '
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($team->team_number ?: 'N/A') . '</Data></Cell>';
            
            // Program
            $xml .= '
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($this->get_program_display($team->program)) . '</Data></Cell>';
            
            // Description
            $description = $team->description ?: 'N/A';
            // Truncate long descriptions for Excel
            if (strlen($description) > 100) {
                $description = substr($description, 0, 97) . '...';
            }
            $xml .= '
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($description) . '</Data></Cell>';
            
            // Hall of Fame
            $xml .= '
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . (!empty($team->hall_of_fame) && $team->hall_of_fame == 1 ? 'Yes' : 'No') . '</Data></Cell>';
            
            // Mentor Count
            $xml .= '
    <Cell ss:StyleID="DataStyle"><Data ss:Type="Number">' . intval($team->mentor_count) . '</Data></Cell>';
            
            // Student Count
            $xml .= '
    <Cell ss:StyleID="DataStyle"><Data ss:Type="Number">' . intval($team->student_count) . '</Data></Cell>';
            
            // Social Media and Website
            $xml .= '
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($team->facebook ?: 'N/A') . '</Data></Cell>';
            $xml .= '
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($team->twitter ?: 'N/A') . '</Data></Cell>';
            $xml .= '
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($team->instagram ?: 'N/A') . '</Data></Cell>';
            $xml .= '
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($team->website ?: 'N/A') . '</Data></Cell>';
            
            // Created Date
            $xml .= '
    <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . (!empty($team->created_at) ? date('Y-m-d', strtotime($team->created_at)) : 'N/A') . '</Data></Cell>';
            
            $xml .= '
   </Row>';
        }

        // Close table and add worksheet options with AutoFilter
        $xml .= '
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <PageSetup>
    <Header x:Margin="0.3"/>
    <Footer x:Margin="0.3"/>
    <PageMargins x:Bottom="0.75" x:Left="0.7" x:Right="0.7" x:Top="0.75"/>
   </PageSetup>
   <Unsynced/>
   <Print>
    <PrintErrors>Blank</PrintErrors>
    <FitWidth>1</FitWidth>
    <FitHeight>32767</FitHeight>
   </Print>
   <Selected/>
   <FreezePanes/>
   <FrozenNoSplit/>
   <SplitHorizontal>1</SplitHorizontal>
   <TopRowBottomPane>1</TopRowBottomPane>
   <ActivePane>2</ActivePane>
   <Panes>
    <Pane>
     <Number>3</Number>
    </Pane>
    <Pane>
     <Number>2</Number>
     <ActiveRow>1</ActiveRow>
    </Pane>
   </Panes>
   <ProtectObjects>False</ProtectObjects>
   <ProtectScenarios>False</ProtectScenarios>
  </WorksheetOptions>
  <AutoFilter x:Range="R1C1:R' . (count($teams) + 1) . 'C12" xmlns="urn:schemas-microsoft-com:office:excel">
  </AutoFilter>
 </Worksheet>
</Workbook>';

        return $xml;
    }

    /**
     * Get program display name
     */
    private function get_program_display($program) {
        if (empty($program)) return 'N/A';
        
        switch (strtoupper($program)) {
            case 'FLL':
                return 'FIRST LEGO League (FLL)';
            case 'FTC':
                return 'FIRST Tech Challenge (FTC)';
            case 'FRC':
                return 'FIRST Robotics Competition (FRC)';
            case 'VEXIQ':
                return 'VEX IQ Challenge';
            case 'VRC':
                return 'VEX Robotics Competition';
            case 'VAIC':
                return 'VEX AI Competition';
            default:
                return htmlspecialchars($program);
        }
    }

    /**
     * Generate Customers Excel Report
     */
    private function generate_customers_excel_report() {
        // Create uploads directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $reports_dir = $upload_dir['basedir'] . '/gears-reports';
        
        if (!file_exists($reports_dir)) {
            wp_mkdir_p($reports_dir);
        }
        
        // Get customers data from QuickBooks
        $customers = $this->core->fetch_customers();
        
        if (empty($customers)) {
            return false;
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
        }
        
        // Generate Excel content
        $excel_content = $this->create_customers_excel_xml_content($customers);
        
        // Generate filename
        $filename = 'customers-report-' . date('Y-m-d-H-i-s') . '.xls';
        $filepath = $reports_dir . '/' . $filename;
        
        // Write file
        if (file_put_contents($filepath, $excel_content) === false) {
            return false;
        }
        
        return array(
            'filename' => $filename,
            'download_url' => $upload_dir['baseurl'] . '/gears-reports/' . $filename
        );
    }

    /**
     * Generate Invoices Excel Report
     */
    private function generate_invoices_excel_report() {
        // Create uploads directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $reports_dir = $upload_dir['basedir'] . '/gears-reports';
        
        if (!file_exists($reports_dir)) {
            wp_mkdir_p($reports_dir);
        }
        
        // Get recurring invoices data
        $recurring_invoices_class = new QBO_Recurring_Invoices($this->core);
        $invoices = $recurring_invoices_class->fetch_recurring_invoices();
        
        if (empty($invoices)) {
            return false;
        }
        
        // Generate Excel content
        $excel_content = $this->create_invoices_excel_xml_content($invoices);
        
        // Generate filename
        $filename = 'invoices-report-' . date('Y-m-d-H-i-s') . '.xls';
        $filepath = $reports_dir . '/' . $filename;
        
        // Write file
        if (file_put_contents($filepath, $excel_content) === false) {
            return false;
        }
        
        return array(
            'filename' => $filename,
            'download_url' => $upload_dir['baseurl'] . '/gears-reports/' . $filename
        );
    }

    /**
     * Generate Students Excel Report
     */
    private function generate_students_excel_report() {
        global $wpdb;
        
        // Create uploads directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $reports_dir = $upload_dir['basedir'] . '/gears-reports';
        
        if (!file_exists($reports_dir)) {
            wp_mkdir_p($reports_dir);
        }
        
        // Get students data
        $table_students = $wpdb->prefix . 'gears_students';
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        $students = $wpdb->get_results("
            SELECT s.*, t.team_name, t.team_number, t.program
            FROM {$table_students} s
            LEFT JOIN {$table_teams} t ON s.team_id = t.id
            WHERE s.grade IS NOT NULL AND s.grade != ''
            ORDER BY s.last_name, s.first_name
        ");
        
        if (empty($students)) {
            return false;
        }
        
        // Generate Excel content
        $excel_content = $this->create_students_excel_xml_content($students);
        
        // Generate filename
        $filename = 'students-report-' . date('Y-m-d-H-i-s') . '.xls';
        $filepath = $reports_dir . '/' . $filename;
        
        // Write file
        if (file_put_contents($filepath, $excel_content) === false) {
            return false;
        }
        
        return array(
            'filename' => $filename,
            'download_url' => $upload_dir['baseurl'] . '/gears-reports/' . $filename
        );
    }

    /**
     * Generate Alumni Excel Report
     */
    private function generate_alumni_excel_report() {
        global $wpdb;
        
        // Create uploads directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $reports_dir = $upload_dir['basedir'] . '/gears-reports';
        
        if (!file_exists($reports_dir)) {
            wp_mkdir_p($reports_dir);
        }
        
        // Get alumni data (students with no grade or empty grade, indicating they've graduated)
        $table_students = $wpdb->prefix . 'gears_students';
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        $alumni = $wpdb->get_results("
            SELECT s.*, t.team_name, t.team_number, t.program
            FROM {$table_students} s
            LEFT JOIN {$table_teams} t ON s.team_id = t.id
            WHERE s.grade IS NULL OR s.grade = ''
            ORDER BY s.last_name, s.first_name
        ");
        
        if (empty($alumni)) {
            return false;
        }
        
        // Generate Excel content
        $excel_content = $this->create_alumni_excel_xml_content($alumni);
        
        // Generate filename
        $filename = 'alumni-report-' . date('Y-m-d-H-i-s') . '.xls';
        $filepath = $reports_dir . '/' . $filename;
        
        // Write file
        if (file_put_contents($filepath, $excel_content) === false) {
            return false;
        }
        
        return array(
            'filename' => $filename,
            'download_url' => $upload_dir['baseurl'] . '/gears-reports/' . $filename
        );
    }

    /**
     * Generate Mentors Excel Report
     */
    private function generate_mentors_excel_report() {
        global $wpdb;
        
        // Create uploads directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $reports_dir = $upload_dir['basedir'] . '/gears-reports';
        
        if (!file_exists($reports_dir)) {
            wp_mkdir_p($reports_dir);
        }
        
        // Get mentors data
        $table_mentors = $wpdb->prefix . 'gears_mentors';
        $table_teams = $wpdb->prefix . 'gears_teams';
        
        $mentors = $wpdb->get_results("
            SELECT m.*, t.team_name, t.team_number, t.program
            FROM {$table_mentors} m
            LEFT JOIN {$table_teams} t ON m.team_id = t.id
            ORDER BY m.mentor_name
        ");
        
        if (empty($mentors)) {
            return false;
        }
        
        // Generate Excel content
        $excel_content = $this->create_mentors_excel_xml_content($mentors);
        
        // Generate filename
        $filename = 'mentors-report-' . date('Y-m-d-H-i-s') . '.xls';
        $filepath = $reports_dir . '/' . $filename;
        
        // Write file
        if (file_put_contents($filepath, $excel_content) === false) {
            return false;
        }
        
        return array(
            'filename' => $filename,
            'download_url' => $upload_dir['baseurl'] . '/gears-reports/' . $filename
        );
    }

    /**
     * Create Customers Excel XML content with styling and filtering
     */
    private function create_customers_excel_xml_content($customers) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title>Customers Report</Title>
  <Author>GEARS WordPress Plugin</Author>
  <Created>' . date('Y-m-d\TH:i:s\Z') . '</Created>
 </DocumentProperties>
 <ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel">
  <WindowHeight>12000</WindowHeight>
  <WindowWidth>20000</WindowWidth>
 </ExcelWorkbook>
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
  </Style>
  <Style ss:ID="HeaderStyle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Bottom"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#000000"/>
   </Borders>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Bold="1"/>
   <Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="DataStyle">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D0D0D0"/>
   </Borders>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Customers Report">
  <Table ss:ExpandedColumnCount="8" ss:ExpandedRowCount="' . (count($customers) + 1) . '">';

        // Define headers
        $headers = array(
            'Customer Name',
            'First Name',
            'Last Name',
            'Company Name',
            'Program',
            'Team',
            'Student',
            'Email'
        );

        // Add header row
        $xml .= '<Row>';
        foreach ($headers as $header) {
            $xml .= '<Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>';
        }
        $xml .= '</Row>';

        // Add data rows
        foreach ($customers as $customer) {
            $xml .= '<Row>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($customer['Name'] ?? 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($customer['FirstName']) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($customer['LastName']) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($customer['CompanyName'] ?? 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($customer['Program'] ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($customer['Team'] ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($customer['Student'] ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($customer['PrimaryEmailAddr']['Address'] ?? 'N/A') . '</Data></Cell>';
            $xml .= '</Row>';
        }

        $xml .= '
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <Selected/>
   <FreezePanes/>
   <FrozenNoSplit/>
   <SplitHorizontal>1</SplitHorizontal>
   <TopRowBottomPane>1</TopRowBottomPane>
   <ActivePane>2</ActivePane>
  </WorksheetOptions>
  <AutoFilter x:Range="R1C1:R' . (count($customers) + 1) . 'C8" xmlns="urn:schemas-microsoft-com:office:excel">
  </AutoFilter>
 </Worksheet>
</Workbook>';

        return $xml;
    }

    /**
     * Create Invoices Excel XML content with styling and filtering
     */
    private function create_invoices_excel_xml_content($invoices) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title>Recurring Invoices Report</Title>
  <Author>GEARS WordPress Plugin</Author>
  <Created>' . date('Y-m-d\TH:i:s\Z') . '</Created>
 </DocumentProperties>
 <ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel">
  <WindowHeight>12000</WindowHeight>
  <WindowWidth>20000</WindowWidth>
 </ExcelWorkbook>
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
  </Style>
  <Style ss:ID="HeaderStyle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Bottom"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#000000"/>
   </Borders>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Bold="1"/>
   <Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="DataStyle">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D0D0D0"/>
   </Borders>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Invoices Report">
  <Table ss:ExpandedColumnCount="7" ss:ExpandedRowCount="' . (count($invoices) + 1) . '">';

        // Define headers
        $headers = array(
            'Invoice Name',
            'Customer',
            'Amount',
            'Status',
            'Interval',
            'Next Due Date',
            'Created Date'
        );

        // Add header row
        $xml .= '<Row>';
        foreach ($headers as $header) {
            $xml .= '<Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>';
        }
        $xml .= '</Row>';

        // Add data rows
        foreach ($invoices as $invoice) {
            $invoice_data = $invoice['Invoice'] ?? array();
            $recurring_info = $invoice_data['RecurringInfo'] ?? array();
            
            $xml .= '<Row>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($recurring_info['Name'] ?? 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($invoice_data['CustomerRef']['name'] ?? 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="Number">' . floatval($invoice_data['TotalAmt'] ?? 0) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($recurring_info['status'] ?? 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($recurring_info['IntervalType'] ?? 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($recurring_info['NextDate'] ?? 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($invoice_data['MetaData']['CreateTime'] ?? 'N/A') . '</Data></Cell>';
            $xml .= '</Row>';
        }

        $xml .= '
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <Selected/>
   <FreezePanes/>
   <FrozenNoSplit/>
   <SplitHorizontal>1</SplitHorizontal>
   <TopRowBottomPane>1</TopRowBottomPane>
   <ActivePane>2</ActivePane>
  </WorksheetOptions>
  <AutoFilter x:Range="R1C1:R' . (count($invoices) + 1) . 'C7" xmlns="urn:schemas-microsoft-com:office:excel">
  </AutoFilter>
 </Worksheet>
</Workbook>';

        return $xml;
    }

    /**
     * Create Students Excel XML content with styling and filtering
     */
    private function create_students_excel_xml_content($students) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title>Students Report</Title>
  <Author>GEARS WordPress Plugin</Author>
  <Created>' . date('Y-m-d\TH:i:s\Z') . '</Created>
 </DocumentProperties>
 <ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel">
  <WindowHeight>12000</WindowHeight>
  <WindowWidth>20000</WindowWidth>
 </ExcelWorkbook>
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
  </Style>
  <Style ss:ID="HeaderStyle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Bottom"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#000000"/>
   </Borders>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Bold="1"/>
   <Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="DataStyle">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D0D0D0"/>
   </Borders>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Students Report">
  <Table ss:ExpandedColumnCount="8" ss:ExpandedRowCount="' . (count($students) + 1) . '">';

        // Define headers
        $headers = array(
            'First Name',
            'Last Name',
            'Grade',
            'Team Name',
            'Team Number',
            'Program',
            'Customer ID',
            'First Year'
        );

        // Add header row
        $xml .= '<Row>';
        foreach ($headers as $header) {
            $xml .= '<Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>';
        }
        $xml .= '</Row>';

        // Add data rows
        foreach ($students as $student) {
            $xml .= '<Row>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($student->first_name) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($student->last_name) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($student->grade ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($student->team_name ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($student->team_number ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($this->get_program_display($student->program)) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($student->customer_id ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($student->first_year_first ?: 'N/A') . '</Data></Cell>';
            $xml .= '</Row>';
        }

        $xml .= '
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <Selected/>
   <FreezePanes/>
   <FrozenNoSplit/>
   <SplitHorizontal>1</SplitHorizontal>
   <TopRowBottomPane>1</TopRowBottomPane>
   <ActivePane>2</ActivePane>
  </WorksheetOptions>
  <AutoFilter x:Range="R1C1:R' . (count($students) + 1) . 'C8" xmlns="urn:schemas-microsoft-com:office:excel">
  </AutoFilter>
 </Worksheet>
</Workbook>';

        return $xml;
    }

    /**
     * Create Alumni Excel XML content with styling and filtering
     */
    private function create_alumni_excel_xml_content($alumni) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title>Alumni Report</Title>
  <Author>GEARS WordPress Plugin</Author>
  <Created>' . date('Y-m-d\TH:i:s\Z') . '</Created>
 </DocumentProperties>
 <ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel">
  <WindowHeight>12000</WindowHeight>
  <WindowWidth>20000</WindowWidth>
 </ExcelWorkbook>
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
  </Style>
  <Style ss:ID="HeaderStyle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Bottom"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#000000"/>
   </Borders>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Bold="1"/>
   <Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="DataStyle">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D0D0D0"/>
   </Borders>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Alumni Report">
  <Table ss:ExpandedColumnCount="7" ss:ExpandedRowCount="' . (count($alumni) + 1) . '">';

        // Define headers
        $headers = array(
            'First Name',
            'Last Name',
            'Last Team Name',
            'Last Team Number',
            'Last Program',
            'Customer ID',
            'First Year'
        );

        // Add header row
        $xml .= '<Row>';
        foreach ($headers as $header) {
            $xml .= '<Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>';
        }
        $xml .= '</Row>';

        // Add data rows
        foreach ($alumni as $alumnus) {
            $xml .= '<Row>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($alumnus->first_name) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($alumnus->last_name) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($alumnus->team_name ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($alumnus->team_number ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($this->get_program_display($alumnus->program)) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($alumnus->customer_id ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($alumnus->first_year_first ?: 'N/A') . '</Data></Cell>';
            $xml .= '</Row>';
        }

        $xml .= '
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <Selected/>
   <FreezePanes/>
   <FrozenNoSplit/>
   <SplitHorizontal>1</SplitHorizontal>
   <TopRowBottomPane>1</TopRowBottomPane>
   <ActivePane>2</ActivePane>
  </WorksheetOptions>
  <AutoFilter x:Range="R1C1:R' . (count($alumni) + 1) . 'C7" xmlns="urn:schemas-microsoft-com:office:excel">
  </AutoFilter>
 </Worksheet>
</Workbook>';

        return $xml;
    }

    /**
     * Create Mentors Excel XML content with styling and filtering
     */
    private function create_mentors_excel_xml_content($mentors) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title>Mentors Report</Title>
  <Author>GEARS WordPress Plugin</Author>
  <Created>' . date('Y-m-d\TH:i:s\Z') . '</Created>
 </DocumentProperties>
 <ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel">
  <WindowHeight>12000</WindowHeight>
  <WindowWidth>20000</WindowWidth>
 </ExcelWorkbook>
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
  </Style>
  <Style ss:ID="HeaderStyle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Bottom"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#000000"/>
   </Borders>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Bold="1"/>
   <Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="DataStyle">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D0D0D0"/>
   </Borders>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Mentors Report">
  <Table ss:ExpandedColumnCount="8" ss:ExpandedRowCount="' . (count($mentors) + 1) . '">';

        // Define headers
        $headers = array(
            'Mentor Name',
            'Email',
            'Phone',
            'Team Name',
            'Team Number',
            'Program',
            'Specialties',
            'Bio'
        );

        // Add header row
        $xml .= '<Row>';
        foreach ($headers as $header) {
            $xml .= '<Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>';
        }
        $xml .= '</Row>';

        // Add data rows
        foreach ($mentors as $mentor) {
            // Truncate long bio for Excel
            $bio = $mentor->bio ?: 'N/A';
            if (strlen($bio) > 100) {
                $bio = substr($bio, 0, 97) . '...';
            }
            
            $xml .= '<Row>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($mentor->mentor_name) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($mentor->email ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($mentor->phone ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($mentor->team_name ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($mentor->team_number ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($this->get_program_display($mentor->program)) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($mentor->specialties ?: 'N/A') . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($bio) . '</Data></Cell>';
            $xml .= '</Row>';
        }

        $xml .= '
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <Selected/>
   <FreezePanes/>
   <FrozenNoSplit/>
   <SplitHorizontal>1</SplitHorizontal>
   <TopRowBottomPane>1</TopRowBottomPane>
   <ActivePane>2</ActivePane>
  </WorksheetOptions>
  <AutoFilter x:Range="R1C1:R' . (count($mentors) + 1) . 'C8" xmlns="urn:schemas-microsoft-com:office:excel">
  </AutoFilter>
 </Worksheet>
</Workbook>';

        return $xml;
    }
}
