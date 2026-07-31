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

namespace block_trainingplan\local;

defined('MOODLE_INTERNAL') || die();

class helper {

    public static function get_user_cohorts(int $userid): array {
        global $DB;
        $sql = "SELECT c.* FROM {cohort} c
                JOIN {cohort_members} cm ON cm.cohortid = c.id
                WHERE cm.userid = :userid";
        return $DB->get_records_sql($sql, ['userid' => $userid]);
    }

    public static function get_cohort_units(int $cohortid): array {
        global $DB;
        return $DB->get_records('block_trainingplan_units', ['cohortid' => $cohortid], 'orderindex ASC');
    }

    public static function get_user_schedule(int $userid, ?int $cohortid = null): array {
        global $DB;
        $params = ['userid' => $userid];
        $where = 'userid = :userid';
        if ($cohortid) {
            $where .= ' AND cohortid = :cohortid';
            $params['cohortid'] = $cohortid;
        }
        return $DB->get_records_select('block_trainingplan_schedule', $where, $params, 'startdate ASC');
    }

    public static function get_approved_marksheet(int $userid, int $courseid): ?\stdClass {
        global $DB;

        $records = $DB->get_records('local_finalmarkingsheet', [
            'userid' => $userid,
            'courseid' => $courseid,
            'status' => 'approved',
        ], 'id DESC', 'id,deemedcompetentdate', 0, 1);

        return $records ? reset($records) : null;
    }

    public static function get_marksheet_state(int $userid, int $courseid): array {
        $record = self::get_approved_marksheet($userid, $courseid);
        if (!$record) {
            return [
                'marksheetstatus' => 'pending',
                'marksheeturl' => '',
            ];
        }

        return [
            'marksheetstatus' => 'approved',
            'marksheeturl' => (new \moodle_url('/local/finalmarkingsheet/view.php', [
                'sheetid' => $record->id,
            ]))->out(false),
        ];
    }

    public static function compute_course_progress(int $userid, int $courseid): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $info = new \completion_info(get_course($courseid));
        if (!$info->is_enabled()) {
            return ['percent' => 0.0, 'completed' => false, 'timecompleted' => null];
        }
        $completion = new \completion_completion(['userid' => $userid, 'course' => $courseid]);
        $completed = $completion->is_complete();
        $timecompleted = $completed ? (int)$completion->timecompleted : null;

        // Use Moodle’s helper for percentage across activities.
        $progress = \core_completion\progress::get_course_progress_percentage($courseid, $userid);
        $percent = is_null($progress) ? 0.0 : round($progress, 2);

        return ['percent' => $percent, 'completed' => $completed, 'timecompleted' => $timecompleted];
    }

    public static function refresh_all_progress_cache(): void {
        global $DB;
        $recs = $DB->get_records('block_trainingplan_schedule', null, '', 'id,userid,courseid,status,progress');
        $now = time();
        foreach ($recs as $rec) {
            $p = self::compute_course_progress($rec->userid, $rec->courseid);
            $rec->progress = $p['percent'];
            if ($p['completed']) {
                $rec->status = 'completed';
                if (empty($rec->signdate)) {
                    // Signature is not tied to completion, so do nothing here.
                }
            }
            $rec->timemodified = $now;
            $DB->update_record('block_trainingplan_schedule', $rec);
        }
    }

    public static function shift_schedule_from_first_unit(int $userid, int $cohortid, int $newfirststart): void {
        global $DB;
        $units = $DB->get_records('block_trainingplan_units', ['cohortid' => $cohortid], 'orderindex ASC');
        if (!$units) { return; }

        $bycourse = [];
        foreach ($units as $u) {
            $bycourse[$u->courseid] = $u;
        }

        $sched = $DB->get_records('block_trainingplan_schedule', ['userid' => $userid, 'cohortid' => $cohortid], 'startdate ASC');
        if (!$sched) { return; }

        $start = $newfirststart;
        foreach ($sched as $row) {
            $u = $bycourse[$row->courseid] ?? null;
            $duration = $u ? (int)$u->duration : 30;
            $end = strtotime('+' . $duration . ' days', $start);

            $row->startdate = $start;
            $row->enddate = $end;
            $row->timemodified = time();
            $DB->update_record('block_trainingplan_schedule', $row);

            $start = $end;
        }
    }

    /**
     * Centralised outcome writer — every change to block_trainingplan_userseq.outcome
     * MUST go through this method so that:
     *   (a) every change is logged in block_trainingplan_outcome_log, and
     *   (b) automatic IP→NYS attempts (regressions) are blocked and loud.
     *
     * @param \stdClass $row        The current userseq row ($row->outcome must be the CURRENT/old value).
     * @param string    $newoutcome The desired new outcome.
     * @param string    $source     One of: cron, observer:assessment_submitted, rebuild, manual_ui, marksheet.
     * @param int       $changedby  Acting user id; 0 for system/cron.
     * @return bool True if the write was performed; false if skipped (no change or blocked).
     */
    public static function set_outcome(
        \stdClass $row,
        string $newoutcome,
        string $source,
        int $changedby
    ): bool {
        global $DB;

        $oldoutcome = $row->outcome ?? null;

        // No-op: outcome is already the target value.
        if ($oldoutcome === $newoutcome) {
            return false;
        }

        // DEFENSIVE GUARD (v1.5.5): Automatic IP→NYS transitions do not exist in
        // any legitimate code path. If one is attempted here with a non-manual
        // source, it is a regression and must be blocked loudly, not applied silently.
        if ($oldoutcome === 'IP' && $newoutcome === 'NYS' && $source !== 'manual_ui') {
            $msg = sprintf(
                'block_trainingplan: BLOCKED automatic IP→NYS attempt. ' .
                'source=%s changedby=%d userid=%d cohortid=%d courseid=%d',
                $source,
                $changedby,
                (int)($row->userid   ?? 0),
                (int)($row->cohortid ?? 0),
                (int)($row->courseid ?? 0)
            );
            mtrace($msg);
            debugging($msg, DEBUG_NORMAL);
            return false; // Do NOT perform the write.
        }

        $now = time();
        $row->outcome      = $newoutcome;
        $row->timemodified = $now;
        $DB->update_record('block_trainingplan_userseq', $row);

        // Audit log row.
        $DB->insert_record('block_trainingplan_outcome_log', (object)[
            'userid'      => (int)($row->userid   ?? 0),
            'courseid'    => (int)($row->courseid ?? 0),
            'cohortid'    => (int)($row->cohortid ?? 0),
            'oldoutcome'  => $oldoutcome,
            'newoutcome'  => $newoutcome,
            'source'      => $source,
            'changedby'   => $changedby,
            'timecreated' => $now,
        ]);

        return true;
    }

    public static function csv_export(array $rows): string {
        $fh = fopen('php://temp', 'w+');
        // UTF-8 BOM so Excel opens the file without garbling special characters.
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, [
            'Student', 'Email', 'Cohort', 'Course',
            'Start Date', 'End Date',
            'Progress %', 'Status', 'Overdue', 'Completed', 'Signature',
        ]);
        foreach ($rows as $r) {
            fputcsv($fh, [
                $r['student'], $r['email'], $r['cohort'], $r['course'],
                $r['startdate'], $r['enddate'],
                $r['progress'], $r['status'],
                $r['overdue']    ? 'Yes' : 'No',
                $r['completed']  ? 'Yes' : 'No',
                $r['signed']     ? 'Yes' : 'No',
            ]);
        }
        rewind($fh);
        return stream_get_contents($fh);
    }
}
