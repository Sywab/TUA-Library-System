<?php
class block_library_export extends block_base {
    
    public function init() {
        $this->title = get_string('pluginname', 'block_library_export');
    }

    // makes sure it only loads once even if called on repeat by moodle. (save power)
    public function get_content() {
        if ($this->content !== null) return $this->content;

        global $CFG;
        $this->content = new stdClass();

        // injects flatpickr ui
        $html = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">';
        $html .= '<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>';

        $html .= '<div class="p-2">';
        
        // switch between range and selective dates
        $html .= '<div class="mb-3" style="text-align: center;">';
        $html .= '<strong>Selection Mode:</strong><br>';
        $html .= '<input type="radio" id="mode-range" name="cal_mode" value="range" checked> <label for="mode-range" class="mr-3">Date Range</label> ';
        $html .= '<input type="radio" id="mode-multiple" name="cal_mode" value="multiple"> <label for="mode-multiple">Specific Dates</label>';
        $html .= '</div>';
        
        // container for calendar
        $html .= '<div class="form-group mb-3 d-flex justify-content-center">';
        $html .= '<input type="text" id="lib-date-range" class="form-control" style="display:none;">';
        $html .= '</div>';
        
        // --- NEW BUTTON LAYOUT ---
        $html .= '<div class="d-flex justify-content-between mt-2">';
        $html .= '<button id="btn-display-list" class="btn btn-primary w-100 mr-1" style="margin-right: 4px;">Display List</button>';
        $html .= '<button id="btn-export-excel" class="btn btn-success w-100 ml-1" style="margin-left: 4px;">Export Excel</button>';
        $html .= '</div>';
        $html .= '</div>';

        $this->content->text = $html;

        // script for toggle and button clicks
        $this->content->text .= "
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            let fp;
            const inputField = document.getElementById('lib-date-range');

            // generates calendar when switching from range or single
            function initCalendar(modeStr) {
                if (fp) { fp.destroy(); } // wipes calendar when switching
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

            // --- REUSABLE REDIRECT FUNCTION ---
            function processAction(e, targetFile) {
                e.preventDefault();
                const selectedDates = fp.selectedDates;
                const mode = document.querySelector('input[name=\"cal_mode\"]:checked').value;

                if (selectedDates.length === 0) {
                    alert('Please select at least one date.');
                    return;
                }

                // Make sure this points to the right files we created (display.php and export.php)
                let url = '{$CFG->wwwroot}/blocks/library_export/' + targetFile + '?mode=' + mode;

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
            }

            // Bind Display Button
            const btnDisplay = document.getElementById('btn-display-list');
            if (btnDisplay) {
                btnDisplay.onclick = function(e) {
                    processAction(e, 'display.php');
                };
            }

            // Bind Export Button
            const btnExport = document.getElementById('btn-export-excel');
            if (btnExport) {
                btnExport.onclick = function(e) {
                    // Note: I changed this from ajax_export.php to export.php based on our setup
                    processAction(e, 'export.php'); 
                };
            }
        });
        </script>";

        return $this->content;
    }
}