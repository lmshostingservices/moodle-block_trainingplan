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

namespace block_trainingplan\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/completion/completion_completion.php');

class update_trainingplan_status extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('cron_update_trainingplan_status', 'block_trainingplan');
    }

    public function execute() {
        global $DB;

        // Explicitly run as the site admin user so that any events or writes
        // attributed by Moodle's logging layer are credited to the system admin
        // account, not to whoever Moodle's global $USER happens to be at cron time.
        \core\cron::setup_user(get_admin());

        $now = time();

        mtrace(str_repeat('-', 50));
        mtrace('TRAINING PLAN CRON STARTED');
        mtrace(str_repeat('-', 50));

        $records = $DB->get_records_sql("\n            SELECT us.*\n              FROM {block_trainingplan_userseq} us\n          ORDER BY us.userid, us.cohortid, us.orderindex\n        ");

        $riskcooldown = 7 * DAYSECS;
        $riskwindow   = 10 * DAYSECS;

        // FIX-TP-SCHEDULE (v1.5.1): Unit selection is schedule/date-driven, not
        // orderindex-driven. The current/accessible unit is the one whose date window
        // contains "now" (startdate <= now <= enddate). This matches the RTO's model:
        // each unit is assigned to a specific calendar month, and the active unit is
        // simply whichever is scheduled for this month.
        //
        // Out-of-order completions (e.g. unit 13 done while 9–12 are untouched) are
        // handled correctly: units 9–12 are past their windows → expired; unit 14
        // in the current window → IP. No orderindex arithmetic needed.
        //
        // REQUIREMENT: Admin must configure non-overlapping windows (one unit per
        // calendar month). If two units share a window both will become IP — that is
        // an admin configuration error, not a plugin bug.
        $lastkey = null;

        foreach ($records as $r) {

            $key = "{$r->userid}-{$r->cohortid}";
            if ($key !== $lastkey) {
                mtrace('');
                mtrace("USER {$r->userid} - COHORT {$r->cohortid}");
                mtrace(str_repeat('-', 40));
                $lastkey = $key;
            }

            mtrace("Course {$r->courseid} | outcome={$r->outcome}");

            // 1) MANUAL OVERRIDE (absolute priority — exempt from latch).
            if ($r->manualoverride) {
                $this->process_manual_override($r, $now);
                continue;
            }

            // Load enrolment + schedule.
            $context = $this->load_course_context($r);
            if (!$context) {
                continue;
            }

            [$course, $completion, $ue, $schedule] = $context;

            // --- "DONE" exits — schedule-driven selection continues below. ---

            // BLOCKER-1-FIX (v1.5.2): Approved marksheet is the ONLY automatic
            // source of outcome=C. Moodle course completion does NOT set C.
            // Guard: never overwrite a staff-set CT/RPL/NA (BLOCKER-4-FIX).
            $approvedmarksheet = $this->get_approved_marksheet($r);
            if ($approvedmarksheet && !in_array($r->outcome, ['CT', 'RPL', 'NA'], true)) {
                mtrace('Approved marksheet found -> completing course');
                $this->sync_enddate_from_marksheet($r, $schedule, $approvedmarksheet, $now);
                $this->mark_completed($r, $ue, $schedule, $now);
                continue;
            }

            if ($r->outcome === 'NA') {
                mtrace('Outcome N/A -> suspended');
                $this->suspend_enrolment($ue, $now);
                $this->sync_schedule($schedule, 'na', $now);
                continue;
            }

            // 2) Fixed completed outcomes — sync Moodle completion and suspend.
            if (in_array($r->outcome, ['C', 'CT', 'RPL'], true)) {
                $this->mark_course_completed($r->userid, $r->courseid, $now);
                $this->suspend_enrolment($ue, $now);
                $this->sync_schedule($schedule, 'completed', $now);
                continue;
            }

            // NOTE: Moodle course completion is intentionally NOT used here as a
            // source of outcome=C. The only automatic C path is the approved
            // marksheet above. Moodle completion may be used elsewhere for
            // progress % (helper::compute_course_progress) — that is fine.

            // 4) AT-RISK WARNING.
            $this->handle_at_risk($r, $ue, $schedule, $now, $riskwindow, $riskcooldown);

            // 5) NOT STARTED YET.
            if ($now < $r->startdate) {
                mtrace('Start date not reached');
                $this->suspend_enrolment($ue, $now);
                $this->sync_schedule($schedule, 'pending', $now);
                continue;
            }

            // 6) EXPIRED.
            if ($now > $r->enddate) {
                mtrace('End date passed -> expired');
                $this->suspend_enrolment($ue, $now);
                $this->sync_schedule($schedule, 'expired', $now);
                continue;
            }

            // 7) ACTIVE WINDOW.
            mtrace('Course active');
            $this->activate_enrolment($ue, $now);

            if ($r->outcome === 'NYS') {
                mtrace('Outcome set to IP (entered active window)');
                \block_trainingplan\local\helper::set_outcome($r, 'IP', 'cron', 0);
            }

            $this->sync_schedule($schedule, 'active', $now);
        }

        mtrace(str_repeat('-', 50));
        mtrace('TRAINING PLAN CRON FINISHED');
        mtrace(str_repeat('-', 50));
    }

    private function load_course_context($r): ?array {
        global $DB;

        $course = get_course($r->courseid);
        $completion = new \completion_info($course);

        $instances = enrol_get_instances($course->id, true);
        $cohortinstance = null;

        foreach ($instances as $instance) {
            if ($instance->enrol === 'cohort' && $instance->customint1 == $r->cohortid) {
                $cohortinstance = $instance;
                break;
            }
        }

        if (!$cohortinstance) {
            mtrace('No cohort enrol instance');
            return null;
        }

        $ue = $DB->get_record('user_enrolments', [
            'enrolid' => $cohortinstance->id,
            'userid'  => $r->userid
        ]);

        if (!$ue) {
            mtrace('User not enrolled via cohort');
            return null;
        }

        $schedule = $DB->get_record('block_trainingplan_schedule', [
            'userid'   => $r->userid,
            'cohortid' => $r->cohortid,
            'courseid' => $r->courseid
        ]);

        if (!$schedule) {
            mtrace('Missing schedule record');
            return null;
        }

        return [$course, $completion, $ue, $schedule];
    }

    private function process_manual_override($r, int $now): void {
        global $DB;

        mtrace('Manual override enabled');

        // FIX-TP-RESTAMP (v1.5.0): Capture original values so we can guard the
        // update_record below. Previously it ran unconditionally on every cron pass,
        // stamping $now on every manualoverride=1 row every run and producing
        // 2,000-row timestamp-burst patterns in monitoring.
        $originaloutcome = $r->outcome;
        $originalenddate = (int)$r->enddate;

        $approvedmarksheet = $this->get_approved_marksheet($r);
        if ($approvedmarksheet && $r->outcome !== 'C') {
            mtrace('Approved marksheet found during manual override -> outcome set to C');
            $r->outcome = 'C';
        }

        // For C/CT/RPL, mark completion regardless of enrol link availability.
        if (in_array($r->outcome, ['C', 'CT', 'RPL'], true)) {
            $this->mark_course_completed($r->userid, $r->courseid, $now);
        }

        $ue = $DB->get_record_sql(
            "SELECT ue.*
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = ?
                AND e.courseid = ?
                AND e.enrol = 'cohort'
                AND e.customint1 = ?",
            [$r->userid, $r->courseid, $r->cohortid]
        );

        if ($ue) {
            if (in_array($r->outcome, ['C', 'CT', 'RPL', 'NYS', 'NA'], true)) {
                $this->suspend_enrolment($ue, $now);
                mtrace("Suspended due to manual outcome {$r->outcome}");
            } else {
                $this->activate_enrolment($ue, $now);
                mtrace("Activated due to manual outcome {$r->outcome}");
            }
        } else {
            mtrace("No cohort enrolment row found for manual override (user {$r->userid}, course {$r->courseid}, cohort {$r->cohortid})");
            if (in_array($r->outcome, ['C', 'CT', 'RPL', 'NYS', 'NA'], true)) {
                $this->suspend_all_course_enrolments($r->userid, $r->courseid, $now);
            }
        }

        $schedule = $DB->get_record('block_trainingplan_schedule', [
            'userid'   => $r->userid,
            'cohortid' => $r->cohortid,
            'courseid' => $r->courseid
        ]);

        if ($schedule) {
            if ($approvedmarksheet) {
                $this->sync_enddate_from_marksheet($r, $schedule, $approvedmarksheet, $now);
            }

            // BUG FIX v1.4.9: Never write raw outcome strings (e.g. 'IP', 'NYS') into
            // schedule.status — those are not valid status values. The old ternary fell
            // through to ": $r->outcome" for any non-conclusive, non-NA outcome, producing
            // 1,014 rows with status='IP' and 182 with status='NYS' in production.
            if ($r->outcome === 'NA') {
                $schedule->status = 'na';
            } elseif (in_array($r->outcome, ['C', 'CT', 'RPL'], true)) {
                $schedule->status = 'completed';
            } elseif ($r->outcome === 'IP') {
                $schedule->status = 'active';
            } else {
                $schedule->status = 'pending'; // NYS or any unknown transient state
            }
            $schedule->timemodified = $now;
            $DB->update_record('block_trainingplan_schedule', $schedule);
        }

        // Persist outcome change via helper (audit-logged + IP→NYS-guarded).
        // Enddate changes are already written by sync_enddate_from_marksheet();
        // only the outcome needs to be handled here (FIX-TP-RESTAMP v1.5.0,
        // AUDIT-TRAIL v1.5.5).
        if ($r->outcome !== $originaloutcome) {
            $newout = $r->outcome;
            $r->outcome = $originaloutcome; // restore old so set_outcome can read it
            \block_trainingplan\local\helper::set_outcome($r, $newout, 'marksheet', 0);
        }
    }

    private function get_approved_marksheet($r): ?\stdClass {
        return \block_trainingplan\local\helper::get_approved_marksheet(
            (int)$r->userid,
            (int)$r->courseid
        );
    }

    private function reset_in_progress_outcome($r, int $now, string $reason): void {
        // No-op. Automatic outcome resets are removed to respect admin-set values.
        // Outcomes are changed only by: (a) admin via the UI, or (b) an approved
        // marking sheet triggering the NYS/IP → C transition.
        // Moodle course completion alone does NOT change outcome — marksheet is the
        // single source of C (BLOCKER-1-FIX v1.5.2).
    }

    private function start_course_early($r, $schedule, int $now): void {
        global $DB;

        $r->startdate = $now;
        $r->timemodified = $now;
        $DB->update_record('block_trainingplan_userseq', $r);

        $schedule->startdate = $now;
        $schedule->timemodified = $now;
        $DB->update_record('block_trainingplan_schedule', $schedule);
    }

    private function sync_enddate_from_marksheet($r, $schedule, $marksheet, int $now): void {
        global $DB;

        $enddate = $this->normalise_marksheet_date($marksheet->deemedcompetentdate ?? null);
        if (!$enddate) {
            mtrace('Approved marksheet has no deemed competent date -> end date unchanged');
            return;
        }

        if ((int)$r->enddate !== $enddate) {
            mtrace('End date updated from approved marksheet deemed competent date');
            $r->enddate = $enddate;
            $r->timemodified = $now;
            $DB->update_record('block_trainingplan_userseq', $r);
        }

        if ($schedule && (int)$schedule->enddate !== $enddate) {
            $schedule->enddate = $enddate;
            $schedule->timemodified = $now;
            $DB->update_record('block_trainingplan_schedule', $schedule);
        }
    }

    private function normalise_marksheet_date($datevalue): int {
        if (empty($datevalue)) {
            return 0;
        }

        if (is_numeric($datevalue)) {
            return (int)$datevalue;
        }

        $timestamp = strtotime((string)$datevalue);
        return $timestamp ? (int)$timestamp : 0;
    }

    private function mark_completed($r, $ue, $schedule, int $now): void {
        global $DB;

        mtrace('Course completed');

        if ($r->outcome !== 'C') {
            \block_trainingplan\local\helper::set_outcome($r, 'C', 'marksheet', 0);
        }

        $this->mark_course_completed($r->userid, $r->courseid, $now);
        $this->suspend_enrolment($ue, $now);
        $this->sync_schedule($schedule, 'completed', $now);
    }

    private function mark_course_completed(int $userid, int $courseid, int $now): void {
        $sendemail = (bool)get_config('block_trainingplan', 'sendcompletionemails');

        try {
            $completion = new \completion_completion([
                'userid' => $userid,
                'course' => $courseid,
            ]);

            if (empty($completion->timecompleted)) {
                if ($sendemail) {
                    $completion->mark_complete($now);
                } else {
                    $completion->mark_complete($now, false);
                }
            }
        } catch (\Throwable $e) {
            mtrace("Unable to mark course completion for user {$userid}, course {$courseid}: {$e->getMessage()}");
        }
    }

    private function handle_at_risk($r, $ue, $schedule, int $now, int $window, int $cooldown): void {
        if (!$schedule->signdate || $ue->status != ENROL_USER_ACTIVE) {
            return;
        }

        if (($r->enddate - $now) > $window) {
            return;
        }

        $last = (int)($schedule->lastrisknotif ?? 0);
        if ($last > ($now - $cooldown)) {
            return;
        }

        mtrace('At-risk -> sending notification');
        $sent = $this->send_risk_notification($r->userid, $r->courseid, $r->enddate, $r->cohortid);

        if (!$sent) {
            // The send was suppressed or message_send() failed silently.
            // Do NOT consume the cooldown stamp — the learner would otherwise be
            // locked out for 7 days from a notification they never received.
            mtrace('At-risk notification was NOT sent - cooldown not consumed.');
            return;
        }

        $schedule->lastrisknotif = $now;
        $schedule->timemodified = $now;
        global $DB;
        $DB->update_record('block_trainingplan_schedule', $schedule);
    }

    private function activate_enrolment($ue, int $now): void {
        // Enrolment status is NOT manipulated by this plugin (removed in v1.4.7,
        // confirmed correct in v1.4.9 forensic analysis).
        //
        // Why: enrol_cohort_sync owns these enrolments. It unconditionally reactivates
        // every suspended cohort-member regardless of which API is used to suspend them,
        // creating a flip-loop of thousands of enrolment events per hour that grew
        // logstore_standard_log to 1.3 GB (confirmed production incident).
        //
        // Access gating is handled by: (1) canaccess flag in student_view.php (only
        // IP units get a clickable link), and (2) the observer.php selective-delete fix
        // (v1.4.9) which prevents churned rows from losing their conclusive outcomes.
        if ($ue->status != ENROL_USER_ACTIVE) {
            mtrace("[INFO] Enrolment id={$ue->id} is suspended — gating via block link only (enrolment manipulation disabled, see code comment).");
        }
    }

    private function suspend_enrolment($ue, int $now): void {
        // No-op — see activate_enrolment() for full explanation.
        if ($ue->status != ENROL_USER_SUSPENDED) {
            mtrace("[INFO] Would suspend enrolment id={$ue->id} — enrolment gating disabled (see code comment).");
        }
    }

    private function suspend_all_course_enrolments(int $userid, int $courseid, int $now): void {
        // No-op — see activate_enrolment() for full explanation.
        mtrace("[INFO] Would suspend all enrolments for user {$userid}, course {$courseid} — enrolment gating disabled.");
    }

    private function sync_schedule($schedule, string $status, int $now): void {
        global $DB;
        $schedule->status = $status;
        $schedule->timemodified = $now;
        $DB->update_record('block_trainingplan_schedule', $schedule);
    }

    private function send_risk_notification(int $userid, int $courseid, int $enddate, int $cohortid): bool {
        global $DB;

        $user   = $DB->get_record('user',   ['id' => $userid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        $message = "You are at risk of not completing your course: {$course->fullname}.\n"
            . "Please complete it by " . userdate($enddate) . " to avoid suspension.";

        $event = new \core\message\message();
        $event->component = 'block_trainingplan';
        $event->name = 'risknotification';
        $event->userfrom = \core_user::get_noreply_user();
        $event->userto = $user;
        $event->subject = 'Training Plan Alert: At Risk';
        $event->fullmessage = $message;
        $event->fullmessageformat = FORMAT_PLAIN;
        $event->fullmessagehtml = nl2br($message);
        $event->smallmessage = $message;
        $event->notification = 1;

        // All sends go through the gatekeeper (kill switch + test allowlist).
        // Return the bool so handle_at_risk() can decide whether to stamp the cooldown.
        return \block_trainingplan\local\notifier::send($event, 'at-risk');
    }
}
