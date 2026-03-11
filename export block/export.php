<?php
// Path: D:\xampp\htdocs\moodle\blocks\tua_data\export.php

require_once('../../config.php');
require_once($CFG->libdir . '/excellib.class.php'); // Essential for Excel generation

global $DB, $OUTPUT, $PAGE, $USER;

// 1. Moodle Page Setup
$url = new moodle_url('/blocks/tua_data/export.php');
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
require_login();

// --- DOWNLOAD LOGIC ---
$download = optional_param('download', 0, PARAM_INT);

if ($download) {
    // Get Date Range
    $startdate = optional_param('startdate', '', PARAM_RAW);
    $enddate   = optional_param('enddate', '', PARAM_RAW);
    
    $start_ts = strtotime($startdate . " 00:00:00");
    $end_ts   = strtotime($enddate . " 23:59:59");

    // Initialize Workbook
    $filename = "TUA_Activity_Report_" . date('Ymd') . ".xlsx";
    $workbook = new MoodleExcelWorkbook($filename);
    
    // Create Header Style
    $format_header = $workbook->add_format();
    $format_header->set_bold();
    $format_header->set_bg_color('silver');
    $format_header->set_border(1);

    // 2. Fetch Detailed Data
    $sql = "SELECT l.id AS logid, 
                CONCAT(u.firstname, ' ', u.lastname) AS fullname, 
                u.email,
                cc.name AS categoryname,
                c.fullname AS coursename, 
                l.eventname,
                l.timecreated
            FROM {logstore_standard_log} l
            JOIN {user} u ON l.userid = u.id
            JOIN {course} c ON l.courseid = c.id
            JOIN {course_categories} cc ON cc.id = c.category
            WHERE c.id <> 1 
            AND u.deleted = 0 
            AND l.timecreated >= ? 
            AND l.timecreated <= ?
            ORDER BY cc.name ASC, l.timecreated DESC";
    
    $logs = $DB->get_records_sql($sql, array($start_ts, $end_ts));

    // 3. Grouping Data & Counting
    $data_by_college = [];
    $college_counts = [];
    
    if ($logs) {
        foreach ($logs as $log) {
            $college = $log->categoryname ?: 'Uncategorized';
            $data_by_college[$college][] = $log;
            $college_counts[$college] = ($college_counts[$college] ?? 0) + 1;
        }
    }

    // 4. Create SUMMARY SHEET
    $summary = $workbook->add_worksheet('Summary');
    $summary->write(0, 0, 'College/Category Name', $format_header);
    $summary->write(0, 1, 'Total Activity Count', $format_header);
    
    $s_row = 1;
    foreach ($college_counts as $name => $count) {
        $summary->write($s_row, 0, $name);
        $summary->write($s_row, 1, $count);
        $s_row++;
    }

    // 5. Create INDIVIDUAL COLLEGE SHEETS
    foreach ($data_by_college as $college_name => $entries) {
        // Excel limit: sheet names must be <= 31 chars
        $tab_name = substr($college_name, 0, 31);
        $sheet = $workbook->add_worksheet($tab_name);
        
        // Write Headers
        $headers = array('Log ID', 'Full Name', 'Email', 'Course Name', 'Action', 'Date/Time');
        foreach ($headers as $col_idx => $title) {
            $sheet->write(0, $col_idx, $title, $format_header);
        }

        // Write Rows
        $row_idx = 1;
        foreach ($entries as $entry) {
            $sheet->write($row_idx, 0, $entry->logid);
            $sheet->write($row_idx, 1, $entry->fullname);
            $sheet->write($row_idx, 2, $entry->email);
            $sheet->write($row_idx, 3, $entry->coursename);
            $sheet->write($row_idx, 4, str_replace('\\', ' ', $entry->eventname));
            $sheet->write($row_idx, 5, userdate($entry->timecreated));
            $row_idx++;
        }
    }

    // Close and Send to Browser
    $workbook->close();
    exit;
}

// --- HTML DISPLAY PAGE ---
$PAGE->set_title('TUA Export Tool');
$PAGE->set_heading('Academic Activity Reporter');

echo $OUTPUT->header();


echo '
<div class="card p-4 shadow-sm" style="max-width: 600px; margin: 40px auto; border-radius: 15px; border-top: 6px solid #1a5a30;">
    <div class="text-center mb-4">
        <h3 style="color: #1a5a30;">Excel Data Export</h3>
        <p class="text-muted">Generate a multi-sheet Excel report filtered by date.</p>
    </div>

    <form action="export.php" method="get">
        <input type="hidden" name="download" value="1">
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="small font-weight-bold">From:</label>
                <input type="date" name="startdate" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="small font-weight-bold">To:</label>
                <input type="date" name="enddate" class="form-control" required>
            </div>
        </div>

        <div class="alert alert-success border-0" style="background-color: #f0f9f1; color: #1a5a30;">
            <strong>Excel Features:</strong>
            <ul class="mb-0 mt-2 small">
                <li><b>Summary Tab:</b> Auto-calculates totals per college.</li>
                <li><b>Dynamic Tabs:</b> Each college gets its own separate sheet.</li>
                <li><b>Name Sync:</b> First and Last names are combined automatically.</li>
            </ul>
        </div>

        <button type="submit" class="btn btn-success w-100 py-3 font-weight-bold shadow-sm" style="background-color: #1a5a30; border: none;">
            <i class="fa fa-file-excel-o mr-2"></i> DOWNLOAD EXCEL (.XLSX)
        </button>
        
        <div class="text-center mt-4">
            <a href="view.php" class="text-secondary small">← Return to Analytics Dashboard</a>
        </div>
    </form>
</div>';

echo $OUTPUT->footer();