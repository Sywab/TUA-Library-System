<?php
// Path: blocks/library_export/ajax_data_engine.php

define('AJAX_SCRIPT', true);
require_once('../../config.php');

global $DB, $PAGE, $OUTPUT;

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/blocks/library_export/display.php');
require_login();

// 1. Capture parameters
$mode = optional_param('mode', 'range', PARAM_ALPHANUM);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 20;

$categoryids = optional_param_array('categoryids', [], PARAM_INT);
$courseids = optional_param_array('courseids', [], PARAM_INT);

$params = []; 
$base_where_sql = ''; 
$baseurl_params = ['mode' => $mode];

if ($mode === 'range') {
    $raw_start = optional_param('start', 0, PARAM_INT);
    $raw_end = optional_param('end', 0, PARAM_INT);
    if (empty($raw_start) || empty($raw_end)) {
        echo json_encode(['error' => 'Missing start or end date.']); die();
    }
    $baseurl_params['start'] = $raw_start;
    $baseurl_params['end'] = $raw_end;
    $base_where_sql = "(l.timecreated >= :start AND l.timecreated <= :end)";
    $params['start'] = $raw_start;
    $params['end'] = $raw_end + 86399;
} else if ($mode === 'multiple') {
    $raw_dates = optional_param('dates', '', PARAM_SEQUENCE); 
    if (empty($raw_dates)) {
        echo json_encode(['error' => 'Dates were not sent.']); die();
    }
    $baseurl_params['dates'] = $raw_dates;
    $date_array = array_map('intval', explode(',', $raw_dates));
    $or_conditions = [];
    foreach ($date_array as $index => $start) {
        $or_conditions[] = "(l.timecreated >= :start{$index} AND l.timecreated <= :end{$index})";
        $params["start{$index}"] = $start;
        $params["end{$index}"] = $start + 86399;
    }
    $base_where_sql = "(" . implode(' OR ', $or_conditions) . ")";
}

$final_where_sql = $base_where_sql;
if (!empty($categoryids) || !empty($courseids)) {
    $filter_conditions = [];
    if (!empty($categoryids)) {
        list($in_sql_cat, $in_params_cat) = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat');
        $filter_conditions[] = "c.category $in_sql_cat";
        $params = array_merge($params, $in_params_cat);
        foreach ($categoryids as $idx => $cid) $baseurl_params["categoryids[{$idx}]"] = $cid;
    }
    if (!empty($courseids)) {
        list($in_sql_crs, $in_params_crs) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');
        $filter_conditions[] = "l.courseid $in_sql_crs";
        $params = array_merge($params, $in_params_crs);
        foreach ($courseids as $idx => $cid) $baseurl_params["courseids[{$idx}]"] = $cid;
    }
    $final_where_sql .= " AND (" . implode(' OR ', $filter_conditions) . ")";
}

$baseurl = new moodle_url('/blocks/library_export/display.php', $baseurl_params);

// 2. RUN QUERIES

$summary_sql = "SELECT COALESCE(c.category, 0) AS catid, cc.name AS categoryname, COUNT(l.id) AS activity_count
                FROM {logstore_standard_log} l
                JOIN {user} u ON l.userid = u.id
                LEFT JOIN {course} c ON l.courseid = c.id
                LEFT JOIN {course_categories} cc ON cc.id = c.category
                WHERE u.deleted = 0 AND $final_where_sql
                GROUP BY COALESCE(c.category, 0), cc.name
                ORDER BY cc.name ASC";
$summary_records = $DB->get_records_sql($summary_sql, $params);

// --- SMART TIMELINE PADDER ---
$all_months = [];

if ($mode === 'range') {
    // If it's a range, draw every single month between start and end
    $current_time = $raw_start;
    while ($current_time <= $raw_end) {
        $m_label = date('M Y', $current_time);
        if (!in_array($m_label, $all_months)) { $all_months[] = $m_label; }
        $current_time = strtotime('+1 month', $current_time);
    }
    $end_m_label = date('M Y', $raw_end);
    if (!in_array($end_m_label, $all_months)) { $all_months[] = $end_m_label; }
} else if ($mode === 'multiple') {
    // If it's specific dates, ONLY draw the exact months that were clicked
    foreach ($date_array as $ts) {
        $m_label = date('M Y', $ts);
        if (!in_array($m_label, $all_months)) { 
            $all_months[] = $m_label; 
        }
    }
    // Sort them chronologically so they appear in the correct order on the chart
    usort($all_months, function($a, $b) {
        return strtotime($a) - strtotime($b);
    });
}
// ----------------------------

$chart_sql = "SELECT l.id, l.timecreated, COALESCE(uc.cohortname, 'None') AS cohort
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
              WHERE u.deleted = 0 AND $final_where_sql";

$chart_rs = $DB->get_recordset_sql($chart_sql, $params);
$chart_grouped = [];

if ($chart_rs->valid()) {
    foreach ($chart_rs as $row) {
        $month_label = date('M Y', $row->timecreated); 
        $month_sort = date('Y-m', $row->timecreated);  
        $cohort = $row->cohort;

        $key = $month_sort . '_' . $cohort;
        if (!isset($chart_grouped[$key])) {
            $chart_grouped[$key] = [
                'month_label' => $month_label,
                'month_sort' => $month_sort,
                'cohort' => $cohort,
                'count' => 0
            ];
        }
        $chart_grouped[$key]['count']++;
    }
}
$chart_rs->close();

usort($chart_grouped, function($a, $b) {
    return strcmp($a['month_sort'], $b['month_sort']);
});

$total_sql = "SELECT COUNT(l.id) 
              FROM {logstore_standard_log} l 
              JOIN {user} u ON l.userid = u.id 
              LEFT JOIN {course} c ON l.courseid = c.id
              WHERE u.deleted = 0 AND $final_where_sql";
$totalcount = $DB->count_records_sql($total_sql, $params);

$details_sql = "SELECT l.id AS logid, u.username, CONCAT(u.firstname, ' ', u.lastname) AS fullname, u.email, cc.name AS categoryname, c.fullname AS coursename, l.timecreated, l.ip, COALESCE(uc.cohortname, 'None') AS cohort
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
                WHERE u.deleted = 0 AND $final_where_sql
                ORDER BY cc.name ASC, l.timecreated DESC";
$logs = $DB->get_records_sql($details_sql, $params, $page * $perpage, $perpage);


// 3. FORMAT DATA FOR JAVASCRIPT
$response = [
    'summary' => [],
    'chart' => ['labels' => [], 'datasets' => []],
    'logs' => [],
    'totalcount' => $totalcount,
    'pagination' => $OUTPUT->paging_bar($totalcount, $page, $perpage, $baseurl)
];

if ($summary_records) {
    foreach ($summary_records as $rec) {
        $response['summary'][] = ['name' => $rec->categoryname ?: 'System & Dashboard', 'count' => $rec->activity_count];
    }
}

if ($logs) {
    foreach ($logs as $log) {
        $response['logs'][] = [
            'logid' => $log->logid,
            'username' => $log->username,
            'fullname' => $log->fullname,
            'cohort' => $log->cohort,
            'course' => $log->coursename ?: 'N/A',
            'date' => date('Y-m-d', $log->timecreated),
            'time' => date('H:i:s', $log->timecreated),
            'ip' => $log->ip
        ];
    }
}

// Package Chart using the Padded Timeline
if (!empty($chart_grouped)) {
    $months = $all_months; // X-axis dynamically adapts based on mode!
    $cohorts = [];
    foreach ($chart_grouped as $row) {
        if (!in_array($row['cohort'], $cohorts)) $cohorts[] = $row['cohort'];
    }

    $colors = ['#1a5a30', '#20c997', '#0dcaf0', '#ffc107', '#fd7e14', '#dc3545'];
    $datasets = [];

    foreach ($cohorts as $idx => $cohort_name) {
        $data_points = [];
        foreach ($months as $month) {
            $found = 0;
            foreach ($chart_grouped as $row) {
                if ($row['month_label'] === $month && $row['cohort'] === $cohort_name) {
                    $found = $row['count'];
                    break;
                }
            }
            $data_points[] = $found;
        }
        $datasets[] = ['label' => $cohort_name, 'data' => $data_points, 'backgroundColor' => $colors[$idx % count($colors)], 'borderWidth' => 1];
    }
    $response['chart']['labels'] = $months;
    $response['chart']['datasets'] = $datasets;
}

header('Content-Type: application/json');
echo json_encode($response);
die();