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

function block_trainingplan_export_date($timestamp): string {
    return !empty($timestamp) ? userdate($timestamp) : '';
}

function block_trainingplan_export_outcome(string $outcome): string {
    return $outcome === 'NA' ? 'N/A' : $outcome;
}

function block_trainingplan_export_outcome_legend(): string {
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

// Params
$userid = required_param('userid', PARAM_INT);
$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

// Include TCPDF
require_once($CFG->libdir . '/pdflib.php');

$pdf = new pdf();
$pdf->SetCreator('Moodle');
$pdf->SetAuthor('Training Plan System');
$pdf->SetTitle('Training Plan - ' . fullname($user));
$pdf->SetMargins(15, 20, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(20);
$pdf->AddPage();

// // --- Branding ---
// $logo = $CFG->dirroot . '/blocks/trainingplan/pix/logo.png';
// $logohtml = file_exists($logo)
//     ? '<img src="'.$logo.'" width="120">'
//     : '<h2>Training Plan</h2>';

// --- Header Section ---
$html = '
<table border="1" cellpadding="4" cellspacing="0" style="table-layout: fixed; width:100%;">
<tr>
    <td width="40%">'.$logohtml.'</td>
    <td width="60%" style="text-align:right;">
        <h2>Individual Training Plan</h2>
    </td>
</tr>
</table>

<hr>

<h3>Student Details</h3>
<table cellpadding="3" cellspacing="0" border="0">
<tr><td><b>Name:</b></td><td>'. fullname($user) .'</td></tr>
<tr><td><b>Email:</b></td><td>'. $user->email .'</td></tr>
<tr><td><b>User ID:</b></td><td>'. $userid .'</td></tr>
<tr><td><b>Generated on:</b></td><td>'. userdate(time()) .'</td></tr>
</table>

<br><br>
';

$html .= block_trainingplan_export_outcome_legend();


// Fetch cohorts for this user
$cohorts = $DB->get_records_sql("
    SELECT c.id, c.name
      FROM {cohort} c
      JOIN {cohort_members} m ON m.cohortid = c.id
     WHERE m.userid = ?
 ORDER BY c.name ASC", [$userid]);

if (!$cohorts) {
    $html .= "<p>No training plans found.</p>";
    $pdf->writeHTML($html);
    $pdf->Output('trainingplan_'.$userid.'.pdf', 'I');
    exit;
}


// Process each cohort
foreach ($cohorts as $cohort) {

    $html .= '
        <h3 style="margin-top:30px;">Cohort: '. format_string($cohort->name) .'</h3>
        <table border="1" cellpadding="4" cellspacing="0">
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
        'userid' => $userid,
        'cohortid' => $cohort->id
    ], 'orderindex ASC');

    foreach ($seq as $r) {
        $course = $DB->get_record('course', ['id' => $r->courseid], 'fullname');

        // Fetch schedule for signature + status
        $schedule = $DB->get_record('block_trainingplan_schedule', [
            'userid' => $userid,
            'cohortid' => $cohort->id,
            'courseid' => $r->courseid
        ]);

        $progressval = $schedule ? number_format($schedule->progress, 1).'%' : '--';
        
        $html .= '
            <tr>
                <td width="8%">' . $r->orderindex . '</td>
                <td width="32%">' . format_string($course->fullname) . '</td>
                <td width="15%">' . block_trainingplan_export_date($r->startdate) . '</td>
                <td width="15%">' . block_trainingplan_export_date($r->enddate) . '</td>
                <td width="10%">' . block_trainingplan_export_outcome($r->outcome) . '</td>
                <td width="10%">' . ($r->manualoverride ? 'Yes' : 'No') . '</td>
                <td width="10%">' . $progressval . '</td>
            </tr>
        ';

    }

    $html .= '</tbody></table>';

    // --- Add Signature Section if ANY course is signed ---
    $signature = null;
    $signdate = null;

    foreach ($seq as $r) {
        $s = $DB->get_record('block_trainingplan_schedule', [
            'userid' => $userid,
            'cohortid' => $cohort->id,
            'courseid' => $r->courseid
        ]);

        if ($s && !empty($s->signature)) {
            $signature = $s->signature;
            $signdate = $s->signdate;
            break; // one signature per plan
        }
    }

    if ($signature) {

        // Convert base64 → image file
        $tmpfile = $CFG->tempdir . '/tp_signature_' . $userid . '.png';
        file_put_contents($tmpfile, base64_decode($signature));

        $html .= "
            <br><br>
            <h4>Student Signature</h4>
            <img src=\"$tmpfile\" width=\"200\"><br>
            <b>Signed on:</b> " . userdate($signdate) . "
            <br><br>
        ";
    }
}


// Footer
$pdf->setPrintFooter(true);
$pdf->setFooterData(['line' => 1], ['Generated by Training Plan System']);

// Output PDF
$pdf->writeHTML($html);
$pdf->Output('trainingplan_'.$userid.'.pdf', 'I');
exit;
