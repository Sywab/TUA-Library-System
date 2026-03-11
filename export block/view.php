<?php
// Path: D:\xampp\htdocs\moodle\blocks\tua_data\view.php

require_once('../../config.php');
global $DB, $OUTPUT, $PAGE;

$url = new moodle_url('/blocks/tua_data/view.php');
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_title('TUA Logs');
require_login();

echo $OUTPUT->header();
echo "<h3>Last 50 Activity Entries</h3>";
// --- SQL LOGIC ---
$sql = "SELECT l.id, u.firstname, u.lastname, l.eventname, l.timecreated 
        FROM {logstore_standard_log} l
        JOIN {user} u ON l.userid = u.id
        ORDER BY l.timecreated DESC LIMIT 50";

$logs = $DB->get_records_sql($sql);

if ($logs) {
    echo '<table class="table table-striped"><thead><tr><th>User</th><th>Event</th><th>Time</th></tr></thead><tbody>';
    foreach ($logs as $log) {
        echo "<tr><td>$log->firstname $log->lastname</td><td>".str_replace('\\', ' ', $log->eventname)."</td><td>".userdate($log->timecreated)."</td></tr>";
    }
    echo "</tbody></table>"; 

echo $OUTPUT->continue_button(new moodle_url('/'));
echo $OUTPUT->footer();}