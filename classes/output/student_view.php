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

namespace block_trainingplan\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use templatable;
use renderer_base;
use stdClass;

class student_view implements renderable, templatable {
    public function __construct(private int $userid) {}

    public function export_for_template(renderer_base $output): stdClass {
        global $DB;

        // Get cohorts where user has a training plan sequence.
        $cohorts = $DB->get_records_sql("
            SELECT DISTINCT c.id, c.name
            FROM {block_trainingplan_userseq} us
            JOIN {cohort} c ON c.id = us.cohortid
            WHERE us.userid = ?
            ORDER BY c.name", [$this->userid]);

        $plans = [];

        foreach ($cohorts as $cohort) {

            // Fetch ordered user sequence (THIS defines the real plan)
            $seqs = $DB->get_records('block_trainingplan_userseq', [
                'userid'   => $this->userid,
                'cohortid' => $cohort->id
            ], 'orderindex ASC');

            if (!$seqs) {
                continue;
            }

            $units = [];
            $signed = false;
            $signdate = null;
            $signatureimg = null;

            foreach ($seqs as $seq) {
                // N/A units are outside the plan, but completed/credit outcomes still
                // belong on the student-facing plan as read-only rows.
                if ($seq->outcome === 'NA') {
                    continue;
                }

                // Unit metadata
                $unit = $DB->get_record('block_trainingplan_units', [
                    'cohortid' => $cohort->id,
                    'courseid' => $seq->courseid
                ]);

                if (!$unit) {
                    continue;
                }

                $course = get_course($seq->courseid);

                // Schedule record (progress, status, signature only)
                $schedule = $DB->get_record('block_trainingplan_schedule', [
                    'userid'   => $this->userid,
                    'cohortid' => $cohort->id,
                    'courseid' => $seq->courseid
                ]);

                $startdate = (int)$seq->startdate;
                $enddate   = (int)$seq->enddate;

                $readonlyoutcomes = ['C', 'CT', 'RPL'];
                $isreadonly = in_array($seq->outcome, $readonlyoutcomes, true);
                // Only IP (In Progress) units should have a live link.
                // NYS = not their turn yet; C/CT/RPL = already credited/complete.
                // Any other outcome must also be non-clickable to prevent students
                // accessing units they are not required to complete.
                // BLOCKER-2-FIX (v1.5.2): Access is defined by outcome only.
                // Every IP unit is accessible — including a unit submitted and
                // awaiting marking even after its calendar month has passed.
                // The enddate guard from v1.5.1 over-reached and locked students
                // out of work they had already submitted. Per the rules:
                // "The student can access every IP unit" — no date caveat.
                $canaccess = ($seq->outcome === 'IP');

                // Duration from userseq dates
                $duration_days = ($startdate && $enddate)
                    ? max(1, round(($enddate - $startdate) / DAYSECS))
                    : 0;

                // The cached progress field is populated only by
                // refresh_all_progress_cache(), which is never called, so it is
                // always 0 and the bars render empty. Derive the bar from the
                // live `status` field instead (prefer a real cached value if one
                // ever exists).
                $cachedprogress = $schedule ? (float)$schedule->progress : 0.0;
                if ($cachedprogress > 0) {
                    $progress = round($cachedprogress, 1);
                } else {
                    $schedstatus = $schedule ? (string)$schedule->status : '';
                    switch ($schedstatus) {
                        case 'completed':
                        case 'CT':
                        case 'RPL':
                            $progress = 100.0;
                            break;
                        case 'active':
                            $progress = 50.0;
                            break;
                        default: // pending, expired, na, empty.
                            $progress = 0.0;
                            break;
                    }
                }

                $units[] = [
                    'unitname' => format_string($course->fullname),
                    'uniturl' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
                    'type' => $unit->type,
                    'startdate' => userdate($startdate),
                    'duration' => $duration_days,
                    'duedate' => $enddate ? userdate($enddate) : '',
                    'progress' => $progress,
                    'progresswidth' => (int)round($progress),
                    'completed' => ($isreadonly || ($schedule && $schedule->status === 'completed')),
                    'completiondate' => ($isreadonly || ($schedule && $schedule->status === 'completed'))
                        ? userdate($enddate)
                        : '',
                    'statuslabel' => $seq->outcome,
                    'canaccess' => $canaccess
                ];

                // One signature per plan
                if (!$signed && $schedule && !empty($schedule->signdate)) {
                    $signed = true;
                    $signdate = $schedule->signdate;
                    $signatureimg = !empty($schedule->signature) ? $schedule->signature : null;
                }
            }

            if (!$units) {
                continue;
            }

            $plans[] = [
                'cohortname' => format_string($cohort->name),
                'cohortid' => $cohort->id,
                'signed' => $signed,
                'signdate' => $signdate ? userdate($signdate) : null,
                'signature' => $signatureimg,
                'showsignbutton' => !$signed,
                'units' => $units
            ];
        }

        return (object)[
            'plans' => $plans,
            'hasplans' => !empty($plans),
            'sesskey' => sesskey(),
            'ajaxsignurl' => (new \moodle_url('/blocks/trainingplan/ajax.php', [
                'action' => 'signplan'
            ]))->out(false)
        ];
    }

}
