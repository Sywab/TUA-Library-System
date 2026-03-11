<?php
require_once('../../config.php');
require_login();

// xray debug
global $CFG, $DB;
$CFG->debug = E_ALL;
$CFG->debugdisplay = 1;

try {
    // tangal white load
    if (ob_get_level()) {
        ob_end_clean();
    }
    //range or select dates script
    $mode = optional_param('mode', 'range', PARAM_ALPHANUM);
    

    $params = ['eventname' => '\\core\\event\\dashboard_viewed']; 
    $where_sql = '';

    if ($mode === 'range') {
        $startdate = optional_param('start', 0, PARAM_INT);
        $enddate = optional_param('end', 0, PARAM_INT);
        
        if (empty($startdate) || empty($enddate)) {
            die('<h2>err: Missing start or end date in the URL.</h2>');
        }
        
        $enddate = $enddate + 86399; 
        $where_sql = "(l.timecreated >= :start AND l.timecreated <= :end)";
        $params['start'] = $startdate;
        $params['end'] = $enddate;

    } else if ($mode === 'multiple') {
        $dates = optional_param('dates', '', PARAM_SEQUENCE); 
        if (empty($dates)) {
            die('<h2>err: dates was not sent to the database.</h2>');
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
        die('<h2>Error: invalid mode</h2>');
    }

    // query for sql to find data
    $sql = "SELECT l.id, u.username, u.firstname, u.lastname, l.timecreated, l.ip
            FROM {logstore_standard_log} l
            JOIN {user} u ON l.userid = u.id
            WHERE $where_sql
            AND l.eventname = :eventname
            ORDER BY l.timecreated DESC";

    $logs = $DB->get_records_sql($sql, $params);

    // download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="library_access_logs.csv"');

    $output = fopen('php://output', 'w');
    
    // Byte order markers - excel to read dates and names correctly, and fix for special char
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['Log ID', 'Username', 'First Name', 'Last Name', 'Date', 'Time', 'IP Address']);

    if ($logs) {
        foreach ($logs as $log) {
            fputcsv($output, [
                $log->id,
                $log->username,
                $log->firstname,
                $log->lastname,
                date('Y-m-d', $log->timecreated), // time of when exported
                date('H:i:s', $log->timecreated),
                $log->ip
            ]);
        }
    } else {
        fputcsv($output, ['No logs found for the selected dates.']);
    }

    fclose($output);
    die();

} catch (Exception $e) {
    // if go bad
    echo "<div style='font-family: sans-serif; padding: 20px;'>";
    echo "<h2 style='color: red;'>Database Error Encountered</h2>";
    echo "<strong>Error Message:</strong> <p>" . $e->getMessage() . "</p>";
    echo "</div>";
    die();
}