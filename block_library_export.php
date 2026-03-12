<?php
class block_library_export extends block_base {
    
    public function init() {
        $this->title = get_string('pluginname', 'block_library_export');
    }
    //makes sure it only loads once even if called on repeat by moodle. (save power)
    public function get_content() {
        if ($this->content !== null) return $this->content;

        global $CFG;
        $this->content = new stdClass();

        // injects flatpickr ui (para d na kelangan manually gawan ng calendar)
        $html = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">';
        $html .= '<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>';

        $html .= '<div class="p-2">';
        
        // switch between range and selective dates (swipe or point and click)
        $html .= '<div class="mb-3" style="text-align: center;">';
        $html .= '<strong>Selection Mode:</strong><br>';
        $html .= '<input type="radio" id="mode-range" name="cal_mode" value="range" checked> <label for="mode-range" class="mr-3">Date Range</label> ';
        $html .= '<input type="radio" id="mode-multiple" name="cal_mode" value="multiple"> <label for="mode-multiple">Specific Dates</label>';
        $html .= '</div>';
        
        // container for calendar
        $html .= '<div class="form-group mb-3 d-flex justify-content-center">';
        $html .= '<input type="text" id="lib-date-range" class="form-control" style="display:none;">';
        $html .= '</div>';
        
        $html .= '<button id="btn-export-csv" class="btn btn-success btn-block mt-2">Export to CSV</button>';
        $html .= '</div>';

        $this->content->text = $html;

        // script for toggle
        $this->content->text .= "
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            let fp;
            const inputField = document.getElementById('lib-date-range');

            // generates calandar when switching from range or single
            function initCalendar(modeStr) {
                if (fp) { fp.destroy(); } // wipes calendar when switchin
                fp = flatpickr(inputField, {
                    mode: modeStr,
                    inline: true,
                    dateFormat: 'Y-m-d'
                });
            }

            // makes default start be range
            initCalendar('range');

            // watches for the toggle when clicking between buttons
            document.getElementById('mode-range').addEventListener('change', function() {
                if(this.checked) initCalendar('range');
            });
            document.getElementById('mode-multiple').addEventListener('change', function() {
                if(this.checked) initCalendar('multiple');
            });

            // export butt
            const btn = document.getElementById('btn-export-csv');
            if (btn) {
                btn.onclick = function(e) {
                    e.preventDefault();
                    const selectedDates = fp.selectedDates;
                    const mode = document.querySelector('input[name=\"cal_mode\"]:checked').value;

                    if (selectedDates.length === 0) {
                        alert('Please select at least one date.');
                        return;
                    }

                    let url = '{$CFG->wwwroot}/blocks/library_export/ajax_export.php?mode=' + mode;

                    if (mode === 'range') {
                        if (selectedDates.length !== 2) {
                            alert('For Range mode, please select a start and end date.');
                            return;
                        }
                        const startTs = Math.floor(selectedDates[0].getTime() / 1000);
                        const endTs = Math.floor(selectedDates[1].getTime() / 1000);
                        url += '&start=' + startTs + '&end=' + endTs;
                    } else {
                        // Multiple Mode: Combine all clicked dates into a comma-separated list
                        const timestamps = selectedDates.map(d => Math.floor(d.getTime() / 1000));
                        url += '&dates=' + timestamps.join(',');
                    }

                    window.location.href = url;
                };
            }
        });
        </script>";

        return $this->content;
    }
}