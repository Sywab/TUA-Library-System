<?php
// Path: D:\xampp\htdocs\moodle\blocks\library_export\ajax_export.php

require_once('../../config.php');
require_once($CFG->libdir . '/excellib.class.php'); // Essential for Excel generation

global $DB, $PAGE, $USER, $CFG;

// Xray debug
// $CFG->debug = E_ALL;
// $CFG->debugdisplay = 1;

// Moodle Page Setup
$url = new moodle_url('/blocks/library_export/ajax_export.php');
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
require_login();

try {
    // Prevent corrupted files by clearing any unexpected output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }

    $mode = optional_param('mode', 'range', PARAM_ALPHANUM);
    
    // FIXED: Removed the dashboard_viewed filter so courses actually load!
    $params = []; 
    $where_sql = '';

    if ($mode === 'range') {
        $startdate = optional_param('start', 0, PARAM_INT);
        $enddate = optional_param('end', 0, PARAM_INT);
        
        if (empty($startdate) || empty($enddate)) {
            die('<h2>Error: Missing start or end date from the block.</h2>');
        }
        
        $enddate = $enddate + 86399; 
        $where_sql = "(l.timecreated >= :start AND l.timecreated <= :end)";
        $params['start'] = $startdate;
        $params['end'] = $enddate;

    } else if ($mode === 'multiple') {
        $dates = optional_param('dates', '', PARAM_SEQUENCE); 
        if (empty($dates)) {
            die('<h2>Error: Dates were not sent to the database.</h2>');
        }

        $date_array = explode(',', $dates);
        $or_conditions = [];
        
        foreach ($date_array as $index => $ts) {
            $start = (int)$ts;
            $end = $start + 86399;
            $or_conditions[] = "(l.timecreated >= :start{$index} AND l.timecreated <= :end{$index})";
            $params["start{$index}"] = $start;
            $params["end{$index}"] = $end;
        }
        
        $where_sql = "(" . implode(' OR ', $or_conditions) . ")";
    } else {
        die('<h2>Error: Invalid mode</h2>');
    }

    // Initialize Workbook
    $filename = "Library_Access_Logs_" . date('Ymd') . ".xlsx";
    $workbook = new MoodleExcelWorkbook($filename);
    
    // Create Header Style
    $format_header = $workbook->add_format();
    $format_header->set_bold();
    $format_header->set_bg_color('silver');
    $format_header->set_border(1);

    // Fetch Detailed Data
    $sql = "SELECT l.id AS logid, 
                   u.username,
                   CONCAT(u.firstname, ' ', u.lastname) AS fullname, 
                   u.email,
                   cc.name AS categoryname,
                   c.fullname AS coursename, 
                   l.eventname,
                   l.timecreated,
                   l.ip,
                   uc.cohortname
            FROM {logstore_standard_log} l
            JOIN {user} u ON l.userid = u.id
            LEFT JOIN {course} c ON l.courseid = c.id
            LEFT JOIN {course_categories} cc ON cc.id = c.category
            LEFT JOIN (
                SELECT cm.userid, MAX(ch.name) AS cohortname
                FROM {cohort_members} cm
                JOIN {cohort} ch ON cm.cohortid = ch.id
                GROUP BY cm.userid
            ) uc ON uc.userid = u.id
            WHERE u.deleted = 0 
            AND $where_sql
            ORDER BY cc.name ASC, l.timecreated DESC";
    
    $logs = $DB->get_records_sql($sql, $params);

    // Grouping Data & Counting
    $data_by_college = [];
    $college_counts = [];
    
    if ($logs) {
        foreach ($logs as $log) {
            $college = $log->categoryname ?: 'System & Dashboard';
            $data_by_college[$college][] = $log;
            $college_counts[$college] = ($college_counts[$college] ?? 0) + 1;
        }
    } else {
        $sheet = $workbook->add_worksheet('No Data');
        $sheet->write(0, 0, 'No logs found for the selected dates.', $format_header);
        $workbook->close();
        exit;
    }

    // Create SUMMARY SHEET
    $summary = $workbook->add_worksheet('Summary');
    $summary->write(0, 0, 'Category Name', $format_header);
    $summary->write(0, 1, 'Total Activity Count', $format_header);
    
    $s_row = 1;
    foreach ($college_counts as $name => $count) {
        $summary->write($s_row, 0, $name);
        $summary->write($s_row, 1, $count);
        $s_row++;
    }

   // Create INDIVIDUAL COLLEGE SHEETS
    foreach ($data_by_college as $college_name => $entries) {
        $tab_name = substr($college_name, 0, 31);
        $sheet = $workbook->add_worksheet($tab_name);
        
        // Added 'Role/Cohort' to the array
        $headers = array('Log ID', 'Username', 'Full Name', 'Email', 'Role/Cohort', 'Course Name', 'Action', 'Date', 'Time', 'IP Address');
        foreach ($headers as $col_idx => $title) {
            $sheet->write(0, $col_idx, $title, $format_header);
        }

        $row_idx = 1;
        foreach ($entries as $entry) {
            $sheet->write($row_idx, 0, $entry->logid);
            $sheet->write($row_idx, 1, $entry->username);
            $sheet->write($row_idx, 2, $entry->fullname);
            $sheet->write($row_idx, 3, $entry->email);
            
            // Insert cohortname at index 4, and shift all subsequent indexes down by 1
            $sheet->write($row_idx, 4, $entry->cohortname ?: 'None');
            $sheet->write($row_idx, 5, $entry->coursename ?: 'N/A');
            $sheet->write($row_idx, 6, str_replace('\\', ' ', $entry->eventname));
            $sheet->write($row_idx, 7, date('Y-m-d', $entry->timecreated));
            $sheet->write($row_idx, 8, date('H:i:s', $entry->timecreated));
            $sheet->write($row_idx, 9, $entry->ip);
            $row_idx++;
        }
    }

    // Close and Send to Browser automatically
    $workbook->close();
    exit;

} catch (Exception $e) {
    echo "<div style='font-family: sans-serif; padding: 20px;'>";
    echo "<h2 style='color: red;'>Database Error Encountered</h2>";
    echo "<strong>Error Message:</strong> <p>" . $e->getMessage() . "</p>";
    echo "</div>";
    die();
}
