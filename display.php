<?php
// Path: blocks/library_export/display.php

require_once('../../config.php');

global $DB, $PAGE, $OUTPUT, $USER, $CFG;

// Moodle Page Setup
$url = new moodle_url('/blocks/library_export/display.php');
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Display Log List');
$PAGE->set_heading('Library Access Logs');
require_login();

// Capture parameters
$mode = optional_param('mode', 'range', PARAM_ALPHANUM);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 20;

// NEW: Capture both categories AND specific courses
$categoryids = optional_param_array('categoryids', [], PARAM_INT);
$courseids = optional_param_array('courseids', [], PARAM_INT);

$params = []; 
$base_where_sql = ''; 
$baseurl_params = ['mode' => $mode];

// 1. Process Dates
$raw_start = 0;
$raw_end = 0;
$raw_dates = '';

if ($mode === 'range') {
    $raw_start = optional_param('start', 0, PARAM_INT);
    $raw_end = optional_param('end', 0, PARAM_INT);
    
    if (empty($raw_start) || empty($raw_end)) {
        echo $OUTPUT->header();
        echo html_writer::tag('div', 'Error: Missing start or end date.', ['class' => 'alert alert-danger']);
        echo $OUTPUT->footer();
        die();
    }
    
    $baseurl_params['start'] = $raw_start;
    $baseurl_params['end'] = $raw_end;

    $enddate_adjusted = $raw_end + 86399;
    $base_where_sql = "(l.timecreated >= :start AND l.timecreated <= :end)";
    $params['start'] = $raw_start;
    $params['end'] = $enddate_adjusted;

} else if ($mode === 'multiple') {
    $raw_dates = optional_param('dates', '', PARAM_SEQUENCE); 
    if (empty($raw_dates)) {
        echo $OUTPUT->header();
        echo html_writer::tag('div', 'Error: Dates were not sent.', ['class' => 'alert alert-danger']);
        echo $OUTPUT->footer();
        die();
    }
    
    $baseurl_params['dates'] = $raw_dates;
    $date_array = explode(',', $raw_dates);
    $or_conditions = [];
    
    foreach ($date_array as $index => $ts) {
        $start = (int)$ts;
        $end = $start + 86399;
        $or_conditions[] = "(l.timecreated >= :start{$index} AND l.timecreated <= :end{$index})";
        $params["start{$index}"] = $start;
        $params["end{$index}"] = $end;
    }
    $base_where_sql = "(" . implode(' OR ', $or_conditions) . ")";
} else {
    die('Invalid mode');
}

// 2. Fetch active courses WITH their Categories
$course_list_sql = "SELECT DISTINCT c.id, c.fullname, COALESCE(cc.id, 0) AS catid, COALESCE(cc.name, 'System & Dashboard') AS catname
                    FROM {logstore_standard_log} l
                    JOIN {course} c ON l.courseid = c.id
                    LEFT JOIN {course_categories} cc ON cc.id = c.category
                    JOIN {user} u ON l.userid = u.id
                    WHERE l.courseid <> 0 AND u.deleted = 0 AND $base_where_sql
                    ORDER BY catname ASC, c.fullname ASC";
$available_courses = $DB->get_records_sql($course_list_sql, $params);

$grouped_data = [];
if ($available_courses) {
    foreach ($available_courses as $c) {
        $catid = $c->catid;
        if (!isset($grouped_data[$catid])) {
            $grouped_data[$catid] = ['name' => $c->catname, 'courses' => []];
        }
        $grouped_data[$catid]['courses'][] = $c;
    }
}

// 3. SMART FILTER LOGIC (Categories AND Courses)
$final_where_sql = $base_where_sql;
if (!empty($categoryids) || !empty($courseids)) {
    $filter_conditions = [];
    $filter_params = [];
    
    if (!empty($categoryids)) {
        list($in_sql_cat, $in_params_cat) = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat');
        $filter_conditions[] = "c.category $in_sql_cat";
        $filter_params = array_merge($filter_params, $in_params_cat);
        foreach ($categoryids as $idx => $cid) {
            $baseurl_params["categoryids[{$idx}]"] = $cid;
        }
    }
    
    if (!empty($courseids)) {
        list($in_sql_crs, $in_params_crs) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');
        $filter_conditions[] = "l.courseid $in_sql_crs";
        $filter_params = array_merge($filter_params, $in_params_crs);
        foreach ($courseids as $idx => $cid) {
            $baseurl_params["courseids[{$idx}]"] = $cid;
        }
    }
    
    $final_where_sql .= " AND (" . implode(' OR ', $filter_conditions) . ")";
    $params = array_merge($params, $filter_params);
}

$baseurl = new moodle_url('/blocks/library_export/display.php', $baseurl_params);

// 4. Queries (Using final_where_sql)
$summary_sql = "SELECT COALESCE(c.category, 0) AS catid, cc.name AS categoryname, COUNT(l.id) AS activity_count
                FROM {logstore_standard_log} l
                JOIN {user} u ON l.userid = u.id
                LEFT JOIN {course} c ON l.courseid = c.id
                LEFT JOIN {course_categories} cc ON cc.id = c.category
                WHERE u.deleted = 0 AND $final_where_sql
                GROUP BY COALESCE(c.category, 0), cc.name
                ORDER BY cc.name ASC";
$summary_records = $DB->get_records_sql($summary_sql, $params);

$total_sql = "SELECT COUNT(l.id) 
              FROM {logstore_standard_log} l 
              JOIN {user} u ON l.userid = u.id 
              LEFT JOIN {course} c ON l.courseid = c.id
              WHERE u.deleted = 0 AND $final_where_sql";
$totalcount = $DB->count_records_sql($total_sql, $params);

$details_sql = "SELECT l.id AS logid, u.username, CONCAT(u.firstname, ' ', u.lastname) AS fullname, u.email, cc.name AS categoryname, c.fullname AS coursename, l.eventname, l.timecreated, l.ip,
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
                WHERE u.deleted = 0 AND $final_where_sql
                ORDER BY cc.name ASC, l.timecreated DESC";
$logs = $DB->get_records_sql($details_sql, $params, $page * $perpage, $perpage);

// --- OUTPUT TO SCREEN ---
echo $OUTPUT->header();
echo '<div class="container mt-4">';

// --- CATEGORY & COURSE FILTER PANEL ---
echo '<div class="card shadow-sm mb-4">';
echo '<div class="card-body bg-light">';
echo '<form method="get" action="display.php" id="filterForm">';
echo '<input type="hidden" name="mode" value="' . s($mode) . '">';
if ($mode === 'range') {
    echo '<input type="hidden" name="start" value="' . $raw_start . '">';
    echo '<input type="hidden" name="end" value="' . $raw_end . '">';
} else {
    echo '<input type="hidden" name="dates" value="' . s($raw_dates) . '">';
}

echo '<div class="row">';
echo '<div class="col-md-8">';
echo '<label class="font-weight-bold">Select Categories (Leave empty to show all):</label>';

// 1. MAIN CATEGORY BOX
echo '<div style="max-height: 150px; overflow-y: auto; border: 1px solid #ced4da; padding: 10px; border-radius: 5px; background: #fff;">';
if (!empty($grouped_data)) {
    foreach ($grouped_data as $catid => $group) {
        $has_courses = count($group['courses']) > 0;
        $cat_explicitly_checked = in_array($catid, $categoryids);
        
        $all_courses_checked = true;
        foreach ($group['courses'] as $c) {
            if (!in_array($c->id, $courseids)) {
                $all_courses_checked = false; break;
            }
        }
        
        $cat_checked = ($cat_explicitly_checked || ($all_courses_checked && $has_courses && !empty($courseids))) ? 'checked' : '';

        echo '<div class="form-check">';
        // NEW: Added name="categoryids[]" and value to send Category ID
        echo '<input class="form-check-input cat-cb" type="checkbox" name="categoryids[]" value="'.$catid.'" data-catid="'.$catid.'" id="cat_'.$catid.'" '.$cat_checked.'>';
        echo '<label class="form-check-label font-weight-bold" style="cursor:pointer;" for="cat_'.$catid.'">'.$group['name'].'</label>';
        echo '</div>';
    }
} else {
    echo '<div class="text-muted small">No data found for the selected dates.</div>';
}
echo '</div>'; 

// ADVANCED TOGGLE BUTTON
echo '<div class="mt-2">';
echo '<button type="button" class="btn btn-sm btn-outline-info font-weight-bold" id="btnAdvancedToggle">Advanced: Show Specific Courses <i class="fa fa-caret-down ml-1"></i></button>';
echo '</div>';

// 2. ADVANCED COURSES BOX (Hidden by default)
echo '<div id="advancedBox" style="display:none; margin-top: 10px; max-height: 250px; overflow-y: auto; border: 1px solid #ced4da; padding: 15px; border-radius: 5px; background: #e9ecef;">';
if (!empty($grouped_data)) {
    foreach ($grouped_data as $catid => $group) {
        $cat_explicitly_checked = in_array($catid, $categoryids);
        echo '<div class="mb-3">';
        echo '<div class="text-muted small font-weight-bold text-uppercase border-bottom border-secondary mb-2 pb-1">'.$group['name'].'</div>';
        foreach ($group['courses'] as $c) {
            // UI memory trick: mark as checked if its parent category was explicitly checked
            $checked = (in_array($c->id, $courseids) || $cat_explicitly_checked) ? 'checked' : '';
            echo '<div class="form-check ml-3">';
            echo '<input class="form-check-input course-cb cat-child-'.$catid.'" type="checkbox" name="courseids[]" value="'.$c->id.'" id="course_'.$c->id.'" '.$checked.'>';
            echo '<label class="form-check-label" style="cursor:pointer;" for="course_'.$c->id.'">'.$c->fullname.'</label>';
            echo '</div>';
        }
        echo '</div>';
    }
}
echo '</div>'; 

echo '</div>'; // End col-md-8

// Submit Buttons
echo '<div class="col-md-4 d-flex flex-column justify-content-center">';
echo '<button type="submit" class="btn btn-primary mb-2 w-100 font-weight-bold">Apply Filter</button>';
echo '<button type="submit" formaction="ajax_export.php" class="btn btn-success w-100 font-weight-bold"><i class="fa fa-file-excel-o"></i> Export to Excel</button>';
echo '</div>';

echo '</div></form></div></div>';

// JavaScript for Smart Payload Management
echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Toggle Advanced Box Visibility
    const btnAdv = document.getElementById('btnAdvancedToggle');
    const advBox = document.getElementById('advancedBox');
    if(btnAdv && advBox) {
        btnAdv.addEventListener('click', function() {
            if(advBox.style.display === 'none') {
                advBox.style.display = 'block';
                btnAdv.innerHTML = 'Hide Specific Courses <i class=\"fa fa-caret-up ml-1\"></i>';
            } else {
                advBox.style.display = 'none';
                btnAdv.innerHTML = 'Advanced: Show Specific Courses <i class=\"fa fa-caret-down ml-1\"></i>';
            }
        });
    }

    // 2. Sync Logic
    const catCbs = document.querySelectorAll('.cat-cb');
    catCbs.forEach(function(catCb) {
        catCb.addEventListener('change', function() {
            const catId = this.getAttribute('data-catid');
            document.querySelectorAll('.cat-child-' + catId).forEach(cb => cb.checked = catCb.checked);
        });
    });

    const courseCbs = document.querySelectorAll('.course-cb');
    courseCbs.forEach(function(courseCb) {
        courseCb.addEventListener('change', function() {
            let catClass = Array.from(this.classList).find(c => c.startsWith('cat-child-'));
            if(catClass) {
                let catId = catClass.replace('cat-child-', '');
                let allChecked = true;
                document.querySelectorAll('.' + catClass).forEach(sib => { if(!sib.checked) allChecked = false; });
                let parentCatCb = document.querySelector('.cat-cb[data-catid=\"' + catId + '\"]');
                if(parentCatCb) parentCatCb.checked = allChecked;
            }
        });
    });

    // 3. SMART PAYLOAD HANDLER
    const filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.addEventListener('submit', function() {
            // Enable everything first
            courseCbs.forEach(cb => cb.disabled = false);
            catCbs.forEach(cb => cb.disabled = false);

            let allCatsChecked = true;
            
            catCbs.forEach(function(catCb) {
                if(catCb.checked) {
                    // SMART PAYLOAD: If a category is checked, disable all its individual courses.
                    // The server will only see the 1 Category ID instead of hundreds of Course IDs.
                    const catId = catCb.getAttribute('data-catid');
                    document.querySelectorAll('.cat-child-' + catId).forEach(cb => cb.disabled = true);
                } else {
                    allCatsChecked = false;
                }
            });

            // If ALL categories are checked, disable EVERYTHING (acts as 'no filter' to save space)
            if (allCatsChecked && catCbs.length > 0) {
                catCbs.forEach(cb => cb.disabled = true);
                courseCbs.forEach(cb => cb.disabled = true);
            }
        });
    }
});
</script>";

// --- SUMMARY TABLE ---
echo '<h3 class="mb-3">Summary</h3>';
echo '<table class="table table-bordered table-striped shadow-sm">';
echo '<thead class="thead-light"><tr><th>Category Name</th><th>Total Activity Count</th></tr></thead>';
echo '<tbody>';
if ($summary_records) {
    foreach ($summary_records as $record) {
        $catname = $record->categoryname ?: 'System & Dashboard';
        echo "<tr><td>{$catname}</td><td>{$record->activity_count}</td></tr>";
    }
} else {
    echo '<tr><td colspan="2" class="text-center">No data found.</td></tr>';
}
echo '</tbody></table>';

echo '<hr class="my-5">';

// --- DETAILED LOGS TABLE ---
echo '<h3 class="mb-3">Detailed Logs (' . $totalcount . ' total records)</h3>';
echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $baseurl);

echo '<div class="table-responsive">';
echo '<table class="table table-bordered table-striped table-hover table-sm shadow-sm">';
echo '<thead class="thead-light"><tr><th>Log ID</th><th>Username</th><th>Full Name</th><th>Role/Cohort</th><th>Course Name</th><th>Date</th><th>Time</th><th>IP Address</th></tr></thead>';
echo '<tbody>';

if ($logs) {
    foreach ($logs as $log) {
        $date = date('Y-m-d', $log->timecreated);
        $time = date('H:i:s', $log->timecreated);
        $course = $log->coursename ?: 'N/A';
        $cohort = $log->cohortname ?: 'None'; 
        
        echo "<tr>
                <td>{$log->logid}</td>
                <td>{$log->username}</td>
                <td>{$log->fullname}</td>
                <td>{$cohort}</td>
                <td>{$course}</td>
                <td>{$date}</td>
                <td>{$time}</td>
                <td>{$log->ip}</td>
              </tr>";
    }
} else {
    echo '<tr><td colspan="8" class="text-center">No detailed logs found.</td></tr>';
}

echo '</tbody></table></div>';

// Bottom Pagination Bar
echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $baseurl);

// Back button and closing tags
echo '<div class="mt-4"><a href="' . $CFG->wwwroot . '/my" class="btn btn-secondary">← Back to Dashboard</a></div>';
echo '</div>'; // End container
echo $OUTPUT->footer();
