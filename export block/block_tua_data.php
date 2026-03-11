<?php
// Path: D:\xampp\htdocs\moodle\blocks\tua_data\block_tua_data.php

class block_tua_data extends block_base {
    public function init() {
        $this->title = 'TUA Data Center';
    }

    public function get_content() {
        global $OUTPUT;
        if ($this->content !== null) { return $this->content; }

        $this->content = new stdClass();
        
        // Button 1: View Recent Logs
        $viewurl = new moodle_url('/blocks/tua_data/view.php');
        $html = html_writer::div($OUTPUT->single_button($viewurl, 'View Recent Logs', 'get'));
        $html .= "<br>";
        // Button 2: Export Page
        $exporturl = new moodle_url('/blocks/tua_data/export.php');
        $html .= html_writer::div($OUTPUT->single_button($exporturl, 'Export Files', 'get'));

        $this->content->text = $html;
        return $this->content;
    }
}