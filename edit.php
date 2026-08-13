<?php
/**
 * block_trainingplan file.
 *
 * @package    block_trainingplan
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

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

require_once(__DIR__ . '/../../config.php');
require_login();
$sysctx = context_system::instance();
require_capability('block/trainingplan:manage', $sysctx);

$userid = required_param('userid', PARAM_INT);
$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

// Page setup
$PAGE->set_context($sysctx);
$PAGE->set_url(new moodle_url('/blocks/trainingplan/edit.php', ['userid' => $userid]));
$PAGE->set_title("Edit Training Plan - " . fullname($user));
$PAGE->requires->js_call_amd('block_trainingplan/edit', 'init', [sesskey(), $userid]);

echo $OUTPUT->header();

// Fetch all cohorts for this user
$cohorts = $DB->get_records_sql("
    SELECT c.id, c.name
    FROM {cohort} c
    JOIN {cohort_members} m ON m.cohortid = c.id
    WHERE m.userid = ?", [$userid]);

echo html_writer::start_div('block_trainingplan-edit');
echo html_writer::tag('h1', "Edit Training Plan for " . fullname($user), ['class' => 'mb-3']);

// Cohort selector
$options = [];
foreach ($cohorts as $c) { $options[$c->id] = $c->name; }
echo html_writer::select($options, 'tp-cohort', key($options), false, ['id' => 'tp-cohort', 'class' => 'form-select mb-3']);
echo html_writer::tag('a',
    'Export Full Training Plan (PDF)',
    [
        'href'  => new moodle_url('/blocks/trainingplan/exportpdf.php', ['userid' => $userid]),
        'class' => 'btn btn-secondary mb-3',
        'target' => '_blank'
    ]
);
echo html_writer::tag('a',
    'Export Selected Training Plan (PDF)',
    [
        'href'  => new moodle_url('/blocks/trainingplan/exportpdf_single.php', [
            'userid'   => $userid,
            'cohortid' => key($options) // default cohort
        ]),
        'class' => 'btn btn-secondary mb-3 ms-2',
        'id'    => 'tp-export-single',
        'target' => '_blank'
    ]
);

// Table container
echo '<table id="tp-edit-table" class="table table-striped table-sm align-middle">';
echo '<thead><tr>
        <th>Order</th>
        <th>Course</th>
        <th>Type</th>
        <th>Start Date</th>
        <th>End Date</th>
        <th>Outcome</th>
        <th>Actions</th>
      </tr></thead><tbody></tbody></table>
      <button id="tp-save-order" class="btn btn-primary mb-3 d-none">Save Order</button>
';

echo html_writer::end_div();

echo $OUTPUT->footer();
