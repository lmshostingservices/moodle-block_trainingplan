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

namespace block_trainingplan;

defined('MOODLE_INTERNAL') || die();

class observer {

    public static function cohort_member_added(\core\event\cohort_member_added $event): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $userid   = $event->relateduserid;
        $cohortid = $event->objectid;

        // Fetch all units (courses) under this cohort.
        $units = $DB->get_records('block_trainingplan_units', ['cohortid' => $cohortid], 'orderindex ASC');
        if (!$units) {
            return;
        }

        // M2-FIX (v1.5.1): Use existing schedule dates when a row already exists,
        // so a cohort-sync remove/re-add does not silently reset the student's
        // future schedule to "today + sequential", wiping any staff-adjusted dates.
        // The sequential $start pointer is only used for genuinely new units.
        $start = time();
        foreach ($units as $unit) {
            $existingschedule = $DB->get_record('block_trainingplan_schedule', [
                'userid' => $userid, 'cohortid' => $cohortid, 'courseid' => $unit->courseid
            ]);

            if ($existingschedule) {
                // Preserve existing dates — never overwrite staff-set or original timing.
                $unitstart = (int)$existingschedule->startdate;
                $unitend   = (int)$existingschedule->enddate;
                $start     = $unitend; // keep sequential pointer consistent for new units after this
            } else {
                $unitstart = $start;
                $unitend   = strtotime("+{$unit->duration} days", $start);
                $DB->insert_record('block_trainingplan_schedule', (object)[
                    'userid'       => $userid,
                    'cohortid'     => $cohortid,
                    'courseid'     => $unit->courseid,
                    'startdate'    => $unitstart,
                    'enddate'      => $unitend,
                    'progress'     => 0.0,
                    'status'       => 'pending',
                    'timemodified' => time()
                ]);
                $start = $unitend;
            }

            self::ensure_userseq_row($userid, $cohortid, (int)$unit->courseid, (int)$unit->orderindex, $unitstart, $unitend);
        }
    }

    public static function cohort_member_removed(\core\event\cohort_member_removed $event): void {
        global $DB;

        $userid   = $event->relateduserid;
        $cohortid = $event->objectid;

        if (!$userid || !$cohortid) {
            return;
        }

        // BUG FIX v1.4.9: Selective delete — NEVER hard-delete rows that carry
        // meaningful progress (conclusive outcomes C/CT/RPL/NA, or in-progress IP).
        //
        // Root cause of outcome-switching bug: enrol_cohort_sync occasionally
        // removes then re-adds cohort members (e.g. during a sync or admin
        // action). The old hard-delete wiped the entire userseq for the user,
        // including rows where staff had set CT/RPL/NA. On re-add, those rows
        // were recreated as NYS, and the cron then promoted in-window NYS → IP.
        // The result appeared random because it depended on which cascaded dates
        // happened to land in the current active window.
        //
        // Fix: only delete rows whose outcome is NYS (truly not started — no
        // meaningful data to preserve). All other rows are left intact so that
        // a transient cohort-sync remove/re-add cannot wipe a staff decision.
        $conclusive = ['C', 'CT', 'RPL', 'NA', 'IP'];

        $userseqrows = $DB->get_records('block_trainingplan_userseq', [
            'userid'   => $userid,
            'cohortid' => $cohortid,
        ]);

        foreach ($userseqrows as $row) {
            if (in_array($row->outcome, $conclusive, true)) {
                // Keep: outcome carries meaningful staff data or student progress.
                continue;
            }
            // NYS (or empty) — delete the sequencing row only.
            // M2-FIX (v1.5.1): Do NOT delete the schedule row. Keeping it means
            // a re-add via cohort_member_added can restore the original dates,
            // preventing a transient sync remove/re-add from silently rescheduling
            // future units to "today + sequential".
            $DB->delete_records('block_trainingplan_userseq', ['id' => $row->id]);
        }
    }


    public static function cohort_created(\core\event\cohort_created $event): void {
        $cohortid = $event->objectid;
        debugging("Trainingplan: New cohort created (ID $cohortid)", DEBUG_DEVELOPER);
        // Nothing to insert yet; we only create units when it's linked via cohort sync enrolment.
    }

    /**
     * When an enrol instance is created.
     * If it's a cohort sync, auto-create block_trainingplan_units row.
     */
    public static function enrol_instance_created(\core\event\enrol_instance_created $event): void {
        global $DB;
        $data = $event->get_record_snapshot('enrol', $event->objectid);
        if ($data->enrol !== 'cohort' || empty($data->customint1)) {
            return; // not cohort sync
        }

        self::insert_unit_record((int)$data->customint1, (int)$data->courseid);
        self::backfill_schedule_for_cohort_course((int)$data->customint1, (int)$data->courseid);
    }

    /**
     * When a new course is created, look for cohort sync enrolments
     * and auto-populate block_trainingplan_units.
     */
    public static function course_created(\core\event\course_created $event): void {
        global $DB;
        $courseid = (int)$event->objectid;

        $enrols = $DB->get_records('enrol', [
            'courseid' => $courseid,
            'enrol'    => 'cohort'
        ]);

        foreach ($enrols as $e) {
            if (!empty($e->customint1)) {
                self::insert_unit_record((int)$e->customint1, $courseid);
            }
        }
    }

    /**
     * When a cohort-sync enrolment instance is updated, ensure mapping exists.
     */
    public static function enrol_instance_updated(\core\event\enrol_instance_updated $event): void {
        $data = $event->get_record_snapshot('enrol', $event->objectid);
        if ($data->enrol !== 'cohort' || empty($data->customint1)) {
            return;
        }

        self::insert_unit_record((int)$data->customint1, (int)$data->courseid);
        self::backfill_schedule_for_cohort_course((int)$data->customint1, (int)$data->courseid);
    }

    /**
     * Generic helper that ensures a cohort-course unit row exists.
     * Used by multiple observers.
     */
    private static function insert_unit_record(int $cohortid, int $courseid): void {
        global $DB;

        // Already exists?
        if ($DB->record_exists('block_trainingplan_units', ['cohortid' => $cohortid, 'courseid' => $courseid])) {
            return;
        }

        // Defaults (from settings if available)
        $fee      = (float)get_config('block_trainingplan', 'defaultfee') ?: 0.00;
        $duration = (int)get_config('block_trainingplan', 'defaultduration') ?: 30;

        // Determine order index (append to end)
        $maxorder = $DB->get_field_sql(
            'SELECT MAX(orderindex) FROM {block_trainingplan_units} WHERE cohortid = ?',
            [$cohortid]
        );

        $record = (object)[
            'cohortid'     => $cohortid,
            'courseid'     => $courseid,
            'type'         => 'core',
            'fee'          => $fee,
            'duration'     => $duration,
            'orderindex'   => ((int)$maxorder) + 1,
            'timecreated'  => time(),
            'timemodified' => time(),
        ];

        $DB->insert_record('block_trainingplan_units', $record);
        debugging("TrainingPlan: Auto-added mapping cohort {$cohortid} → course {$courseid}", DEBUG_DEVELOPER);
    }

    /**
     * When a new course is cohort-synced, ensure schedule rows exist
     * for all current cohort members.
     */
    private static function backfill_schedule_for_cohort_course(int $cohortid, int $courseid): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $unit = $DB->get_record('block_trainingplan_units', [
            'cohortid' => $cohortid,
            'courseid' => $courseid
        ]);
        if (!$unit) {
            return;
        }

        $members = $DB->get_records('cohort_members', ['cohortid' => $cohortid], '', 'userid');
        if (!$members) {
            return;
        }

        $inserted = 0;
        foreach ($members as $m) {
            $userid = (int)$m->userid;

            $exists = $DB->record_exists('block_trainingplan_schedule', [
                'userid' => $userid,
                'cohortid' => $cohortid,
                'courseid' => $courseid
            ]);
            if ($exists) {
                continue;
            }

            $lastend = $DB->get_field_sql(
                'SELECT MAX(enddate) FROM {block_trainingplan_userseq} WHERE userid = ? AND cohortid = ?',
                [$userid, $cohortid]
            );

            $start = $lastend ? (int)$lastend : time();
            $end = strtotime("+{$unit->duration} days", $start);

            $DB->insert_record('block_trainingplan_schedule', (object)[
                'userid' => $userid,
                'cohortid' => $cohortid,
                'courseid' => $courseid,
                'startdate' => $start,
                'enddate' => $end,
                'progress' => 0.0,
                'status' => 'pending',
                'timemodified' => time()
            ]);
            $inserted++;

            self::ensure_userseq_row($userid, $cohortid, $courseid, (int)$unit->orderindex, $start, $end);
        }

    }

    /**
     * Ensure block_trainingplan_userseq row exists for a user/cohort/course.
     */
    private static function ensure_userseq_row(
        int $userid,
        int $cohortid,
        int $courseid,
        int $orderindex,
        int $startdate,
        int $enddate
    ): void {
        global $DB;

        $exists = $DB->record_exists('block_trainingplan_userseq', [
            'userid' => $userid,
            'cohortid' => $cohortid,
            'courseid' => $courseid
        ]);
        if ($exists) {
            return;
        }

        $DB->insert_record('block_trainingplan_userseq', (object)[
            'userid' => $userid,
            'cohortid' => $cohortid,
            'courseid' => $courseid,
            'orderindex' => $orderindex,
            'startdate' => $startdate,
            'enddate' => $enddate,
            'outcome' => 'NYS',
            'manualoverride' => 0,
            'timemodified' => time()
        ]);
    }

    public static function user_enrolled(\core\event\user_enrolled $event): void {
        global $DB;

        $userid   = $event->relateduserid;
        $courseid = $event->courseid;


        // Find cohort-sync enrol instance.
        // Note: user_enrolled->objectid is user_enrolments.id, not enrol.id.
        $enrolid = $event->other['enrolid'] ?? null;
        if (!$enrolid) {
            $ue = $DB->get_record('user_enrolments', ['id' => $event->objectid]);
            if (!$ue) {
                return;
            }
            $enrolid = $ue->enrolid;
        }

        $instance = $DB->get_record('enrol', ['id' => $enrolid]);
        if (!$instance || $instance->enrol !== 'cohort' || empty($instance->customint1)) {
            $type = $instance ? $instance->enrol : 'missing';
            $cohort = $instance->customint1 ?? 'none';
            return; // Not a cohort-sync enrolment
        }

        $cohortid = $instance->customint1;


        // Fetch units for this cohort
        $units = $DB->get_records('block_trainingplan_units', ['cohortid' => $cohortid], 'orderindex ASC');
        if (!$units) {
            return;
        }

        $start = time();

        foreach ($units as $unit) {
            $end = strtotime("+{$unit->duration} days", $start);

            // avoid duplicates
            $exists = $DB->record_exists('block_trainingplan_schedule', [
                'userid' => $userid,
                'cohortid' => $cohortid,
                'courseid' => $unit->courseid
            ]);

            if (!$exists) {
                $DB->insert_record('block_trainingplan_schedule', (object)[
                    'userid'       => $userid,
                    'cohortid'     => $cohortid,
                    'courseid'     => $unit->courseid,
                    'startdate'    => $start,
                    'enddate'      => $end,
                    'progress'     => 0.0,
                    'status'       => 'pending',
                    'timemodified' => time()
                ]);
            }

            // H2-FIX (v1.5.1): Also create the userseq row. Previously this
            // handler only created schedule rows, leaving userseq empty for
            // students enrolled via user_enrolled (without cohort_member_added).
            // Result: orphaned schedule rows and a blank training plan block.
            self::ensure_userseq_row($userid, $cohortid, (int)$unit->courseid, (int)$unit->orderindex, $start, $end);

            $start = $end;
        }
    }

    /**
     * BLOCKER-3-FIX (v1.5.2): Open the next sequential unit when a student
     * submits all assign activities in their current (IP) unit's course.
     *
     * Rules (verbatim): "NYS → IP … when the student submits the assessment(s)
     * of the preceding unit. On opening: outcome becomes IP, startdate is set
     * to today … A unit does not need to be C for the next one to open —
     * submitting the prior unit is enough."
     *
     * Produces two concurrent IP units (current submitted-awaiting-marking +
     * next just-opened) — that is correct and expected per the rules.
     */
    public static function assessment_submitted(\mod_assign\event\assessable_submitted $event): void {
        global $DB;

        $userid   = (int)($event->relateduserid ?? $event->userid);
        $courseid = (int)$event->courseid;

        if (!$userid || !$courseid) {
            return;
        }

        $now = time();

        // GAP-1-FIX (v1.5.4): A student may submit into a unit that is still NYS.
        // Since enrolment suspension is disabled (v1.4.7, to stop the cohort-sync
        // flip-loop), every cohort course is reachable, so students can work ahead
        // of — or behind — the calendar window. Promotion to IP was previously only
        // possible inside a unit's date window (cron) or when the *previous* IP unit
        // was submitted (successor logic below); a unit whose window had passed, or
        // that was never opened, stayed NYS forever even after the student submitted
        // real work. Submitting into a unit means it is in progress, so promote the
        // submitted unit's own NYS row(s) to IP here. Respect manualoverride (a
        // staff lock/hold must not be reopened). Any single submission is enough to
        // mark the unit started; opening the NEXT unit still requires ALL of this
        // unit's assign activities to be submitted (unchanged, below).
        $submittedrows = $DB->get_records_select(
            'block_trainingplan_userseq',
            "userid = ? AND courseid = ? AND outcome = 'NYS' AND manualoverride = 0",
            [$userid, $courseid]
        );
        foreach ($submittedrows as $row) {
            if (empty($row->startdate)) {
                $row->startdate = $now;
            }
            // AUDIT-TRAIL v1.5.5: set_outcome() writes the row (including any
            // startdate change above) and logs the NYS→IP transition.
            \block_trainingplan\local\helper::set_outcome($row, 'IP', 'observer:assessment_submitted', $userid);

            $schedule = $DB->get_record('block_trainingplan_schedule', [
                'userid'   => $userid,
                'cohortid' => (int)$row->cohortid,
                'courseid' => $courseid,
            ]);
            if ($schedule) {
                if (empty($schedule->startdate)) {
                    $schedule->startdate = $now;
                }
                $schedule->status       = 'active';
                $schedule->timemodified = $now;
                $DB->update_record('block_trainingplan_schedule', $schedule);
            }

            debugging(
                "TrainingPlan: Promoted submitted unit (course {$courseid}) to IP " .
                "for user {$userid} on submission (was NYS).",
                DEBUG_DEVELOPER
            );
        }

        // Find all IP userseq rows for this user in this course (now includes any
        // just promoted above). (Could span multiple cohorts if the student is in
        // more than one.)
        $currentrows = $DB->get_records_select(
            'block_trainingplan_userseq',
            "userid = ? AND courseid = ? AND outcome = 'IP' AND manualoverride = 0",
            [$userid, $courseid]
        );

        if (!$currentrows) {
            return;
        }

        foreach ($currentrows as $current) {
            // Require ALL assign activities in the course to be submitted.
            if (!self::all_assignments_submitted($userid, $courseid)) {
                continue;
            }

            // Find the IMMEDIATE successor by orderindex (any outcome), then open it
            // only if it is a fresh, unlocked NYS unit.
            // v1.5.3 FIX: the previous query filtered outcome='NYS' which leapfrogged
            // an already-open (IP) concurrent unit and also ignored manualoverride on
            // the target — opening a unit two ahead, or opening a staff-locked hold.
            $nexts = $DB->get_records_sql("
                SELECT *
                  FROM {block_trainingplan_userseq}
                 WHERE userid = ? AND cohortid = ? AND orderindex > ?
              ORDER BY orderindex ASC
            ", [$userid, (int)$current->cohortid, (int)$current->orderindex], 0, 1);

            $next = $nexts ? reset($nexts) : null;
            if (!$next || $next->outcome !== 'NYS' || $next->manualoverride) {
                continue;
            }

            // Open the next unit: outcome → IP, startdate → today.
            $now = time();
            $next->startdate = $now;
            // AUDIT-TRAIL v1.5.5: set_outcome() writes the row (including
            // the startdate update) and logs the NYS→IP transition.
            \block_trainingplan\local\helper::set_outcome($next, 'IP', 'observer:assessment_submitted', $userid);

            // Sync the schedule row.
            $schedule = $DB->get_record('block_trainingplan_schedule', [
                'userid'   => $userid,
                'cohortid' => (int)$current->cohortid,
                'courseid' => (int)$next->courseid,
            ]);
            if ($schedule) {
                $schedule->startdate    = $now;
                $schedule->status       = 'active';
                $schedule->timemodified = $now;
                $DB->update_record('block_trainingplan_schedule', $schedule);
            }

            debugging(
                "TrainingPlan: Opened next unit (course {$next->courseid}) for " .
                "user {$userid} on submission of course {$courseid}",
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Returns true when every assign activity in the course has a submitted
     * (latest) submission for this user. Units with no assign activities
     * return true (nothing to require).
     */
    private static function all_assignments_submitted(int $userid, int $courseid): bool {
        global $DB;

        $assigns = $DB->get_records_sql("
            SELECT cm.id, cm.instance
              FROM {course_modules} cm
              JOIN {modules} m ON m.id = cm.module
             WHERE cm.course = ?
               AND m.name = 'assign'
               AND cm.deletioninprogress = 0
        ", [$courseid]);

        if (!$assigns) {
            return true; // no assign activities — treat as submitted
        }

        foreach ($assigns as $cm) {
            $submitted = $DB->record_exists_select(
                'assign_submission',
                "assignment = ? AND userid = ? AND status = 'submitted' AND latest = 1",
                [(int)$cm->instance, $userid]
            );
            if (!$submitted) {
                return false;
            }
        }

        return true;
    }

    public static function enrol_instance_deleted(
    \core\event\enrol_instance_deleted $event
    ): void {
        global $DB;

        $instance = $event->get_record_snapshot('enrol', $event->objectid);
        if (!$instance || $instance->enrol !== 'cohort' || empty($instance->customint1)) {
            return;
        }

        $cohortid = (int)$instance->customint1;
        $courseid = (int)$instance->courseid;


        // 1. Remove unit mapping
        $DB->delete_records('block_trainingplan_units', [
            'cohortid' => $cohortid,
            'courseid' => $courseid
        ]);

        // 2 & 3. Remove sequencing and schedules — but preserve rows where a
        // student has a conclusive or in-progress outcome (C/CT/RPL/IP/NA).
        // H1-FIX (v1.5.1): The previous hard-delete wiped ALL rows for the
        // cohort/course when an enrol instance was removed or recreated. This
        // silently erased student history and is the likely cause of "trimmed"
        // students seen in production. Now: only NYS rows are removed; any row
        // carrying meaningful progress is preserved.
        $conclusive = ['C', 'CT', 'RPL', 'NA', 'IP'];
        $userseqrows = $DB->get_records('block_trainingplan_userseq', [
            'cohortid' => $cohortid,
            'courseid' => $courseid,
        ]);
        foreach ($userseqrows as $row) {
            if (in_array($row->outcome, $conclusive, true)) {
                continue; // preserve meaningful progress
            }
            $DB->delete_records('block_trainingplan_userseq', ['id' => $row->id]);
            $DB->delete_records('block_trainingplan_schedule', [
                'userid'   => (int)$row->userid,
                'cohortid' => $cohortid,
                'courseid' => $courseid,
            ]);
        }
    }


}
