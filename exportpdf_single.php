<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

require_once('../../config.php');
require_login();

$sysctx = context_system::instance();
require_capability('block/trainingplan:manage', $sysctx);

global $DB, $CFG;

function block_trainingplan_export_single_date($timestamp): string {
    return !empty($timestamp) ? userdate($timestamp) : '';
}

function block_trainingplan_export_single_outcome(string $outcome): string {
    return $outcome === 'NA' ? 'N/A' : $outcome;
}

function block_trainingplan_export_single_outcome_legend(): string {
    return '
        <table border="1" cellpadding="5" cellspacing="0" style="width:100%;">
            <tr>
                <td>
                    <b>' . get_string('outcomelegend', 'block_trainingplan') . ':</b><br>
                    ' . get_string('outcome_c', 'block_trainingplan') . '<br>
                    ' . get_string('outcome_ct', 'block_trainingplan') . '<br>
                    ' . get_string('outcome_rpl', 'block_trainingplan') . '<br>
                    ' . get_string('outcome_ip', 'block_trainingplan') . '<br>
                    ' . get_string('outcome_nys', 'block_trainingplan') . '
                </td>
            </tr>
        </table>
        <br>
    ';
}

$userid   = required_param('userid', PARAM_INT);
$cohortid = required_param('cohortid', PARAM_INT);

$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
$cohort = $DB->get_record('cohort', ['id' => $cohortid], '*', MUST_EXIST);

require_once($CFG->libdir . '/pdflib.php');

$pdf = new pdf();
$pdf->SetCreator('Moodle');
$pdf->SetAuthor('Training Plan System');
$pdf->SetTitle('Training Plan - ' . fullname($user));
$pdf->AddPage();

$html = '<h3>Cohort: '.format_string($cohort->name).'</h3>';
$html .= block_trainingplan_export_single_outcome_legend();

$html .= '
<table border="1" cellpadding="4" cellspacing="0" style="table-layout: fixed; width:100%;">
<thead>
<tr style="background-color:#efefef; font-weight:bold;">
    <th width="8%">#</th>
    <th width="32%">Course</th>
    <th width="15%">Start Date</th>
    <th width="15%">End Date</th>
    <th width="10%">Outcome</th>
    <th width="10%">Manual</th>
    <th width="10%">Progress</th>
</tr>
</thead>
<tbody>
';

$seq = $DB->get_records('block_trainingplan_userseq', [
    'userid'   => $userid,
    'cohortid' => $cohortid
], 'orderindex ASC');

foreach ($seq as $r) {
    $course = $DB->get_record('course', ['id' => $r->courseid], 'fullname');

    $schedule = $DB->get_record('block_trainingplan_schedule', [
        'userid'   => $userid,
        'cohortid' => $cohortid,
        'courseid' => $r->courseid
    ]);

    $progressval = $schedule ? number_format($schedule->progress, 1).'%' : '--';

    $html .= '
        <tr>
            <td width="8%">' . $r->orderindex . '</td>
            <td width="32%">' . format_string($course->fullname) . '</td>
            <td width="15%">' . block_trainingplan_export_single_date($r->startdate) . '</td>
            <td width="15%">' . block_trainingplan_export_single_date($r->enddate) . '</td>
            <td width="10%">' . block_trainingplan_export_single_outcome($r->outcome) . '</td>
            <td width="10%">' . ($r->manualoverride ? 'Yes' : 'No') . '</td>
            <td width="10%">' . $progressval . '</td>
        </tr>
    ';

}

$html .= '</tbody></table>';

$pdf->writeHTML($html);
$pdf->Output('trainingplan_'.$userid.'_cohort_'.$cohortid.'.pdf', 'I');
exit;
