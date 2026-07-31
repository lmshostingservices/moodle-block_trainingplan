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

namespace block_trainingplan\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use templatable;
use renderer_base;
use stdClass;

class admin_view implements renderable, templatable {

    public function export_for_template(renderer_base $output): stdClass {
        global $DB, $PAGE;

        $cohorts = $DB->get_records_menu('cohort', null, 'name ASC', 'id,name');

        $selected = optional_param('cohortid', 0, PARAM_INT);
        $params = [];
        $where = '';
        if ($selected) {
            $where = 'WHERE s.cohortid = :cid';
            $params['cid'] = $selected;
        }

        $sql = "SELECT s.*, u.firstname, u.lastname, u.email,
                    c.name AS cohortname, cr.id AS courseid, cr.fullname AS coursename
                FROM {block_trainingplan_schedule} s
                JOIN {user} u ON u.id = s.userid
                JOIN {cohort} c ON c.id = s.cohortid
                JOIN {course} cr ON cr.id = s.courseid
                $where
                ORDER BY c.id, u.lastname, s.startdate";

        $records = $DB->get_records_sql($sql, $params);

        // Group by user + cohort.
        $grouped = [];
        foreach ($records as $r) {
            $key = "{$r->userid}-{$r->cohortid}";
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'userid' => $r->userid,
                    'student' => fullname($r),
                    'email' => $r->email,
                    'cohort' => $r->cohortname,
                    'cohortid' => $r->cohortid,
                    'editurl' => (new \moodle_url('/blocks/trainingplan/edit.php', ['userid' => $r->userid]))->out(false),
                    'courses' => []
                ];
            }
            $marksheet = \block_trainingplan\local\helper::get_marksheet_state(
                (int)$r->userid,
                (int)$r->courseid
            );
            $grouped[$key]['courses'][] = [
                'courseid' => $r->courseid,
                'coursename' => $r->coursename,
                'startdate' => !empty($r->startdate) ? userdate($r->startdate) : '',
                'enddate' => !empty($r->enddate) ? userdate($r->enddate) : '',
                'progress' => round($r->progress, 1),
                'status' => $r->status === 'na' ? 'N/A' : $r->status,
                'signdate' => $r->signdate ? userdate($r->signdate, '%d %b %Y') : '',
                'signature' => $r->signature ?? '',
            ] + $marksheet;
        }

        $rows = array_values($grouped);

        // Register JS.
        $PAGE->requires->js_call_amd('block_trainingplan/admin', 'init', [
            (new \moodle_url('/blocks/trainingplan/ajax.php'))->out(false),
            sesskey()
        ]);

        return (object)[
            'rows' => $rows,
            'cohorts' => array_map(fn($id,$name)=>['id'=>$id,'name'=>$name], array_keys($cohorts), $cohorts),
            'selected' => $selected,
            'hasrows' => !empty($rows)
        ];
    }

}
