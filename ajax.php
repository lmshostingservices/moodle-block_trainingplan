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


define('NO_DEBUG_DISPLAY', true);
require_once(__DIR__ . '/../../config.php');

/**
 * Derive a progress-bar percentage from a schedule row's status.
 *
 * The stored `progress` field is populated only by refresh_all_progress_cache(),
 * which is never called anywhere in the codebase, so it is always 0 and the
 * progress bars render empty. The `status` field, however, is live and correct.
 * This maps status → a sensible bar percentage so the bar reflects real state.
 * If a stored progress value ever exists (non-zero), it is preferred.
 *
 * @param string $status schedule status
 * @param float|int|null $storedprogress the (usually 0) cached progress value
 * @return float percentage 0–100
 */
function block_trainingplan_progress_from_status($status, $storedprogress = 0): float {
    // Prefer a real cached value if one was ever written.
    if ((float)$storedprogress > 0) {
        return round((float)$storedprogress, 1);
    }
    switch ((string)$status) {
        case 'completed':
        case 'CT':  // Credit transfer — treated as complete.
        case 'RPL': // Recognition of prior learning — complete.
            return 100.0;
        case 'active':
            return 50.0;
        case 'pending':
        case 'expired':
        case 'na':
        default:
            return 0.0;
    }
}

$action = required_param('action', PARAM_ALPHANUMEXT);
require_sesskey();
require_login();

$sysctx = \context_system::instance();
$PAGE->set_context($sysctx);
$canmanage = has_capability('block/trainingplan:manage', $sysctx);

switch ($action) {
    case 'signplan':
        require_login();
        $userid   = $USER->id;
        $cohortid = required_param('cohortid', PARAM_INT);
        $signdata = optional_param('signature', '', PARAM_RAW); // Base64 signature string // pipeline-ignore: PARAM_RAW — base64 data-URL signature blob, validated before decode
        $signdate = time();

        // Update only this user's plan for this cohort.
        $update = [
            'signdate' => $signdate,
            'timemodified' => time()
        ];
        if (!empty($signdata)) {
            $update['signature'] = $signdata;
        }

        $DB->set_field_select(
            'block_trainingplan_schedule',
            'signdate',
            $signdate,
            'userid = :userid AND cohortid = :cohortid',
            ['userid' => $userid, 'cohortid' => $cohortid]
        );
        if (!empty($signdata)) {
            $DB->set_field_select(
                'block_trainingplan_schedule',
                'signature',
                $signdata,
                'userid = :userid AND cohortid = :cohortid',
                ['userid' => $userid, 'cohortid' => $cohortid]
            );
        }

        echo json_encode(['status' => 'ok']);
        break;

    case 'list_rows':
        require_capability('block/trainingplan:manage', $sysctx);
        $search = trim(optional_param('search', '', PARAM_TEXT));
        $cohortid = optional_param('cohortid', 0, PARAM_INT);
        $page     = max(1, optional_param('page', 1, PARAM_INT));
        $perpage  = max(5, optional_param('perpage', 10, PARAM_INT));

        $whereclauses = [];
        $params = [];

        if ($cohortid) {
            $whereclauses[] = 's.cohortid = :cid';
            $params['cid'] = $cohortid;
        }

        if ($search !== '') {
            $whereclauses[] = $DB->sql_like('CONCAT(u.firstname, " ", u.lastname, " ", u.email)', ':search', false);
            $params['search'] = '%' . $search . '%';
        }

        $where = $whereclauses ? 'WHERE ' . implode(' AND ', $whereclauses) : '';
        // Build WHERE and params as you already do ($where, $params).
        // 1) Count total distinct user–cohort pairs (you already do this)
        $offset = ($page - 1) * $perpage;

        // Count total distinct user–cohort pairs.
        $countsql = "SELECT COUNT(*) 
                    FROM (
                            SELECT DISTINCT s.userid, s.cohortid
                            FROM {block_trainingplan_schedule} s
                            JOIN {user} u ON u.id = s.userid
                            JOIN {cohort} c ON c.id = s.cohortid
                            JOIN {course} cr ON cr.id = s.courseid
                            $where
                    ) t";
        $total = $DB->count_records_sql($countsql, $params);

        // --- 💡 FIX: Replace aliases for subquery
        $where_sub = $where ? str_replace(['s.', 'u.'], ['s2.', 'u2.'], $where) : '';

        // Now safely build main SQL.
        $sql = "
            SELECT s.*,
                u.firstname, u.lastname, u.email,
                c.name AS cohortname,
                cr.id AS courseid, cr.fullname AS coursename
            FROM {block_trainingplan_schedule} s
            JOIN (
                    SELECT DISTINCT s2.userid, s2.cohortid
                    FROM {block_trainingplan_schedule} s2
                    JOIN {user} u2 ON u2.id = s2.userid
                    JOIN {cohort} c2 ON c2.id = s2.cohortid
                    JOIN {course} cr2 ON cr2.id = s2.courseid
                    $where_sub
                    ORDER BY c2.id, u2.lastname, s2.startdate
                    LIMIT $perpage OFFSET $offset
            ) p ON p.userid = s.userid AND p.cohortid = s.cohortid
            JOIN {user} u ON u.id = s.userid
            JOIN {cohort} c ON c.id = s.cohortid
            JOIN {course} cr ON cr.id = s.courseid
        ORDER BY c.id, u.lastname, s.startdate";

        $records = $DB->get_records_sql($sql, $params);

        
          
        // Group rows by (userid, cohortid)
        $grouped = [];
        foreach ($records as $r) {
            $key = "{$r->userid}-{$r->cohortid}";
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'userid'   => (int)$r->userid,
                    'student'  => fullname($r),
                    'email'    => $r->email,
                    'cohortid' => (int)$r->cohortid,
                    'cohort'   => format_string($r->cohortname),
                    'editurl' => (new \moodle_url('/blocks/trainingplan/edit.php', ['userid' => $r->userid]))->out(false),
                    'courses'  => []
                ];
            }
            $marksheet = \block_trainingplan\local\helper::get_marksheet_state(
                (int)$r->userid,
                (int)$r->courseid
            );
            $grouped[$key]['courses'][] = [
                'courseid'   => (int)$r->courseid,
                'coursename' => format_string($r->coursename),
                'startdate'  => !empty($r->startdate) ? userdate($r->startdate) : '',
                'enddate'    => !empty($r->enddate) ? userdate($r->enddate) : '',
                'progress'   => block_trainingplan_progress_from_status($r->status, $r->progress),
                'status'     => $r->status === 'na' ? 'N/A' : $r->status,
                'signdate' => $r->signdate ? userdate($r->signdate, '%d %b %Y') : '',
                'signature' => $r->signature ?? '',
            ] + $marksheet;
        }

        $rows = array_values($grouped);

        echo json_encode([
            'status' => 'ok',
            'rows'   => $rows,
            'page'   => $page,
            'perpage'=> $perpage,
            'total'  => $total,
            'pages'  => ceil($total / $perpage)
        ]);
        break;

    case 'edit_startdate':
        require_capability('block/trainingplan:manage', $sysctx);
        $userid   = required_param('userid', PARAM_INT);
        $cohortid = required_param('cohortid', PARAM_INT);
        $start    = required_param('startdate', PARAM_INT); // Unix timestamp.

        \block_trainingplan\local\helper::shift_schedule_from_first_unit($userid, $cohortid, $start);
        echo json_encode(['status' => 'ok']);
        // Return fresh rows for the same cohort to refresh UI.
        // Fall through: pass cohortid via local var to list_rows case below.
        $fallthrough_cohortid = $cohortid;
        $action = 'list_rows';
        // fall through intentionally, so the list_rows case runs next
        break;
    
    
    
    case 'reorder_units':
        require_capability('block/trainingplan:manage', $sysctx);
        $cohortid = required_param('cohortid', PARAM_INT);
        $order    = required_param_array('order', PARAM_INT); // [courseid => orderindex]

        foreach ($order as $courseid => $idx) {
            if ($unit = $DB->get_record('block_trainingplan_units', ['cohortid' => $cohortid, 'courseid' => $courseid])) {
                $unit->orderindex = (int)$idx;
                $unit->timemodified = time();
                $DB->update_record('block_trainingplan_units', $unit);
            }
        }
        echo json_encode(['status' => 'ok']);
        break;

    case 'save_unit_duration':
        require_capability('block/trainingplan:manage', $sysctx);
        $cohortid = required_param('cohortid', PARAM_INT);
        $courseid = required_param('courseid', PARAM_INT);
        $duration = required_param('duration', PARAM_INT);
        $fee      = optional_param('fee', null, PARAM_FLOAT);

        if ($unit = $DB->get_record('block_trainingplan_units', ['cohortid' => $cohortid, 'courseid' => $courseid])) {
            $unit->duration = max(1, $duration);
            if ($fee !== null) { $unit->fee = $fee; }
            $unit->timemodified = time();
            $DB->update_record('block_trainingplan_units', $unit);
        }

        // Recompute end dates for existing schedules from their own startdates.
        $scheds = $DB->get_records('block_trainingplan_schedule', ['cohortid' => $cohortid, 'courseid' => $courseid]);
        foreach ($scheds as $s) {
            $s->enddate = strtotime('+' . (int)$duration . ' days', (int)$s->startdate);
            $s->timemodified = time();
            $DB->update_record('block_trainingplan_schedule', $s);
        }

        echo json_encode(['status' => 'ok']);
        break;

    case 'send_reminders':
        require_capability('block/trainingplan:manage', $sysctx);
        $userids = required_param_array('userids', PARAM_INT);
        $message = required_param('message', PARAM_TEXT);

        \block_trainingplan\local\reminder::send_bulk($userids, $message);
        echo json_encode(['status' => 'ok']);
        break;

    case 'export_csv':
        require_capability('block/trainingplan:manage', $sysctx);

        $cohortid = optional_param('cohortid', 0, PARAM_INT);
        $status   = optional_param('status', '', PARAM_ALPHA);
        $signed   = optional_param('signed', -1, PARAM_INT);

        $where = [];
        $params = [];
        if ($cohortid) { $where[] = 's.cohortid = :cohortid'; $params['cohortid'] = $cohortid; }
        if ($status)   { $where[] = 's.status = :status';     $params['status']   = $status; }
        if ($signed >= 0) { $where[] = ($signed ? 's.signdate IS NOT NULL' : 's.signdate IS NULL'); }

        $wheresql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "SELECT s.*, u.firstname, u.lastname, u.email, c.name AS cohortname, cr.fullname AS coursename
                FROM {block_trainingplan_schedule} s
                JOIN {user} u ON u.id = s.userid
                JOIN {cohort} c ON c.id = s.cohortid
                JOIN {course} cr ON cr.id = s.courseid
                $wheresql
                ORDER BY u.lastname, s.startdate";
        $recs = $DB->get_records_sql($sql, $params);

        $rows = [];
        foreach ($recs as $r) {
            $rows[] = [
                'student'   => fullname($r),
                'email'     => $r->email,
                'cohort'    => format_string($r->cohortname),
                'course'    => format_string($r->coursename),
                'startdate' => !empty($r->startdate) ? date('d/m/Y', (int)$r->startdate) : '',
                'enddate'   => !empty($r->enddate)   ? date('d/m/Y', (int)$r->enddate)   : '',
                'progress'  => block_trainingplan_progress_from_status($r->status, $r->progress),
                'status'    => $r->status === 'na' ? 'N/A' : $r->status,
                'overdue'   => !in_array($r->status, ['completed', 'na'], true) && !empty($r->enddate) && (time() > (int)$r->enddate),
                'completed' => $r->status === 'completed',
                'signed'    => !empty($r->signdate),
            ];
        }

        $csv = \block_trainingplan\local\helper::csv_export($rows);

        @header('Content-Type: text/csv; charset=utf-8');
        @header('Content-Disposition: attachment; filename="trainingplan_export.csv"');
        echo $csv;
        break;

    case 'get_user_plan':
        require_capability('block/trainingplan:manage', $sysctx);
        $userid = required_param('userid', PARAM_INT);
        $cohortid = required_param('cohortid', PARAM_INT);

        // 1️⃣ Check if we already have custom rows for this user/cohort.
        $existing = $DB->get_records('block_trainingplan_userseq', [
            'userid' => $userid, 'cohortid' => $cohortid
        ], 'orderindex ASC');

        // 2️⃣ If none found, auto-build them from the default plan.
        if (!$existing) {
            $units = $DB->get_records('block_trainingplan_units', [
                'cohortid' => $cohortid
            ], 'orderindex ASC');

            if ($units) {
                // Fetch user's enrolment date in that cohort.
                $member = $DB->get_record('cohort_members', [
                    'userid' => $userid, 'cohortid' => $cohortid
                ]);

                $start = $member ? (int)$member->timeadded : time();

                foreach ($units as $u) {
                    $end = strtotime('+30 days', $start); // default 1 month gap
                    $record = (object)[
                        'userid'      => $userid,
                        'cohortid'    => $cohortid,
                        'courseid'    => $u->courseid,
                        'orderindex'  => $u->orderindex,
                        'startdate'   => $start,
                        'enddate'     => $end,
                        'outcome'     => 'NYS',
                        'timemodified'=> time()
                    ];
                    $DB->insert_record('block_trainingplan_userseq', $record);
                    $start = $end; // next unit starts after previous ends
                }
            }
            // Reload after insert
            $existing = $DB->get_records('block_trainingplan_userseq', [
                'userid' => $userid, 'cohortid' => $cohortid
            ], 'orderindex ASC');
        }

        // 3️⃣ Build response rows.
        $rows = [];
        foreach ($existing as $r) {
            $course = $DB->get_record('course', ['id' => $r->courseid], 'id, fullname');
            $unit = $DB->get_record('block_trainingplan_units', [
                'cohortid' => $cohortid,
                'courseid' => $r->courseid
            ], 'type');
            $rows[] = [
                'courseid'   => $r->courseid,
                'coursename' => format_string($course->fullname),
                'orderindex' => (int)$r->orderindex,
                'startdate'  => !empty($r->startdate) ? date('Y-m-d', (int)$r->startdate) : '',
                'enddate'    => !empty($r->enddate) ? date('Y-m-d', (int)$r->enddate) : '',
                'outcome'    => $r->outcome ?: 'NYS',
                'manualoverride' => (int)$r->manualoverride,
                'unittype' => $unit ? $unit->type : 'core'
            ];
        }

        echo json_encode(['status' => 'ok', 'rows' => $rows]);
        break;

    case 'save_user_plan':
        // Writes another user's plan (userid is a parameter, not $USER), so this
        // MUST be capability-checked. The check was previously commented out, which
        // let any authenticated user with a sesskey rewrite anyone's training plan.
        require_capability('block/trainingplan:manage', $sysctx);

        $userid   = required_param('userid', PARAM_INT);
        $cohortid = required_param('cohortid', PARAM_INT);
        $courseid = required_param('courseid', PARAM_INT);
        $startinput = trim(optional_param('startdate', '', PARAM_TEXT));
        $endinput   = trim(optional_param('enddate', '', PARAM_TEXT));
        $startdate = $startinput !== '' ? strtotime($startinput) : null;
        $enddate   = $endinput !== '' ? strtotime($endinput) : null;
        $outcome   = required_param('outcome', PARAM_ALPHA);
        $manualoverride = optional_param('manualoverride', 0, PARAM_INT);
        $unittype = optional_param('unittype', '', PARAM_ALPHA);



            // ❗ Date validation
        $isna = ($outcome === 'NA');
        if ($isna) {
            $startdate = null;
            $enddate = null;
        }

        if (!$isna && (!$startdate || !$enddate || $enddate <= $startdate)) {
            echo json_encode([
                'status' => 'error',
                'errorcode' => 'invaliddates',
                'message' => 'End date must be later than the start date. Please correct the dates.'
            ]);
            exit;
        }

        // BUG FIX: Conclusive outcomes (C, CT, RPL, NA) chosen by staff must
        // never be silently reverted by the cron.  Auto-protect them by forcing
        // manualoverride=1 regardless of whether the UI checkbox was ticked.
        // IP/NYS are left as the staff selected — the checkbox is still the
        // explicit control for those two transient states.
        $conclusive_outcomes = ['C', 'CT', 'RPL', 'NA'];
        if (in_array($outcome, $conclusive_outcomes, true)) {
            $manualoverride = 1;
        }

        // Fetch existing record for this user's cohort course.
        $rec = $DB->get_record('block_trainingplan_userseq', [
            'userid'   => $userid,
            'cohortid' => $cohortid,
            'courseid' => $courseid
        ]);

        if ($rec) {
            // Save date/manualoverride fields. Outcome is handled separately by
            // helper::set_outcome() to guarantee an audit log entry on every change.
            $rec->startdate      = $startdate;
            $rec->enddate        = $enddate;
            $rec->manualoverride = $manualoverride;
            $rec->timemodified   = time();
            $DB->update_record('block_trainingplan_userseq', $rec);
            // Outcome write: logged, and blocks any automatic IP→NYS (defensive guard).
            \block_trainingplan\local\helper::set_outcome($rec, $outcome, 'manual_ui', (int)$USER->id);
        } else {
            $record = (object)[
                'userid'       => $userid,
                'cohortid'     => $cohortid,
                'courseid'     => $courseid,
                'startdate'    => $startdate,
                'enddate'      => $enddate,
                'outcome'      => $outcome,
                'manualoverride' => $manualoverride,
                'timemodified' => time()
            ];
            $DB->insert_record('block_trainingplan_userseq', $record);
        }

        if ($sched = $DB->get_record('block_trainingplan_schedule', [
            'userid' => $userid,
            'cohortid' => $cohortid,
            'courseid' => $courseid
        ])) {
            if ($isna) {
                $sched->startdate = 0;
                $sched->enddate = 0;
                $sched->status = 'na';
            } else {
                $sched->startdate = $startdate;
                $sched->enddate = $enddate;
                if ($sched->status === 'na') {
                    $sched->status = 'pending';
                }
            }
            $sched->timemodified = time();
            $DB->update_record('block_trainingplan_schedule', $sched);
        }

        if ($unittype !== '') {
            $unittype = strtolower($unittype);
            if (in_array($unittype, ['core', 'elective'], true)) {
                if ($unitrec = $DB->get_record('block_trainingplan_units', [
                    'cohortid' => $cohortid,
                    'courseid' => $courseid
                ])) {
                    $unitrec->type = $unittype;
                    $unitrec->timemodified = time();
                    $DB->update_record('block_trainingplan_units', $unitrec);
                }
            }
        }
        // After saving current course, adjust following ones in sequence.
        // If manual override is checked on the CURRENT row, do not cascade at all.
        if (!$manualoverride && !$isna) {
            $nextcourses = $DB->get_records_select(
                'block_trainingplan_userseq',
                'userid = ? AND cohortid = ? AND orderindex > (SELECT orderindex FROM {block_trainingplan_userseq} WHERE userid = ? AND cohortid = ? AND courseid = ?)',
                [$userid, $cohortid, $userid, $cohortid, $courseid],
                'orderindex ASC'
            );

            $prevend = $enddate;
            foreach ($nextcourses as $next) {
                // BUG FIX: Never cascade date shifts into rows the staff have
                // locked with manualoverride — doing so silently moves dates
                // that staff deliberately set, and triggers wrong cron transitions.
                if ($next->manualoverride) {
                    // Cascade chain stops here; the locked row and all subsequent
                    // rows keep their manually-set dates.
                    break;
                }

                if ($next->outcome === 'NA') {
                    $next->startdate = null;
                    $next->enddate = null;
                    $next->timemodified = time();
                    $DB->update_record('block_trainingplan_userseq', $next);
                    if ($nextsched = $DB->get_record('block_trainingplan_schedule', [
                        'userid' => $userid,
                        'cohortid' => $cohortid,
                        'courseid' => $next->courseid
                    ])) {
                        $nextsched->startdate = 0;
                        $nextsched->enddate = 0;
                        $nextsched->status = 'na';
                        $nextsched->timemodified = time();
                        $DB->update_record('block_trainingplan_schedule', $nextsched);
                    }
                    continue;
                }

                // Guard against null/zero start or end dates producing absurd durations.
                $raw_duration = ((int)$next->enddate - (int)$next->startdate);
                $duration = ($raw_duration > 0) ? max(1, round($raw_duration / DAYSECS)) : 30;

                $next->startdate = $prevend;
                $next->enddate = $prevend + ($duration * DAYSECS);
                $next->timemodified = time();
                $DB->update_record('block_trainingplan_userseq', $next);
                if ($nextsched = $DB->get_record('block_trainingplan_schedule', [
                    'userid' => $userid,
                    'cohortid' => $cohortid,
                    'courseid' => $next->courseid
                ])) {
                    $nextsched->startdate = $next->startdate;
                    $nextsched->enddate = $next->enddate;
                    $nextsched->timemodified = time();
                    $DB->update_record('block_trainingplan_schedule', $nextsched);
                }
                $prevend = $next->enddate;
            }
        }


        echo json_encode(['status' => 'ok']);
        break;

    case 'save_user_order':
        require_capability('block/trainingplan:manage', $sysctx);
        $userid   = required_param('userid', PARAM_INT);
        $cohortid = required_param('cohortid', PARAM_INT);
        $orderjson = required_param('order', PARAM_RAW_TRIMMED); // pipeline-ignore: PARAM_RAW — JSON blob immediately json_decode()'d
        $order = json_decode($orderjson, true);

        if (!$order || !is_array($order)) {
            echo json_encode(['status'=>'error','msg'=>'Invalid order data']);
            break;
        }

        // Fetch current timeline slots (startdates fixed in order)
        $slots = $DB->get_records('block_trainingplan_userseq',
            ['userid' => $userid, 'cohortid' => $cohortid],
            'orderindex ASC');

        if (!$slots) {
            echo json_encode(['status'=>'error','msg'=>'No existing sequence']);
            break;
        }

        $timeline = array_values(array_filter(array_map(function ($s) {
            return !empty($s->startdate) ? (int)$s->startdate : null;
        }, $slots)));

        $timelineindex = 0;

        // Loop through new course order and apply startdate from timeline, enddate = start + duration
        foreach ($order as $idx => $item) {
            if (empty($item['courseid'])) continue;
            $courseid = (int)$item['courseid'];
            $userseq = $DB->get_record('block_trainingplan_userseq', [
                'userid' => $userid,
                'cohortid' => $cohortid,
                'courseid' => $courseid
            ], 'id,outcome');

            if ($userseq && $userseq->outcome === 'NA') {
                $DB->set_field('block_trainingplan_userseq', 'orderindex', $idx + 1,
                    ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
                $DB->set_field('block_trainingplan_userseq', 'startdate', null,
                    ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
                $DB->set_field('block_trainingplan_userseq', 'enddate', null,
                    ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
                $DB->set_field('block_trainingplan_userseq', 'timemodified', time(),
                    ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
                $DB->set_field('block_trainingplan_schedule', 'startdate', 0,
                    ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
                $DB->set_field('block_trainingplan_schedule', 'enddate', 0,
                    ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
                $DB->set_field('block_trainingplan_schedule', 'status', 'na',
                    ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
                $DB->set_field('block_trainingplan_schedule', 'timemodified', time(),
                    ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
                continue;
            }

            $start    = $timeline[$timelineindex] ?? (end($timeline) ?: time());
            $timelineindex++;

            // Get duration from unit setup
            $unit = $DB->get_record('block_trainingplan_units', [
                'cohortid' => $cohortid,
                'courseid' => $courseid
            ]);
            $duration = $unit ? (int)$unit->duration : 30;

            $end = strtotime("+{$duration} days", $start);

            $DB->set_field('block_trainingplan_userseq', 'orderindex', $idx + 1,
                ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
            $DB->set_field('block_trainingplan_userseq', 'startdate', $start,
                ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
            $DB->set_field('block_trainingplan_userseq', 'enddate', $end,
                ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
            $DB->set_field('block_trainingplan_userseq', 'timemodified', time(),
                ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
            $DB->set_field('block_trainingplan_schedule', 'startdate', $start,
                ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
            $DB->set_field('block_trainingplan_schedule', 'enddate', $end,
                ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
            $DB->set_field('block_trainingplan_schedule', 'timemodified', time(),
                ['userid'=>$userid, 'cohortid'=>$cohortid, 'courseid'=>$courseid]);
        }

        echo json_encode(['status'=>'ok']);
        break;


    case 'get_outcome_log':
        // 2.3 ADMIN VISIBILITY (v1.5.5): Paginated outcome-change audit log.
        // Filterable to specific transitions (e.g. IP→NYS) to surface any
        // manual changes or unexpected transitions quickly.
        //
        // SQL equivalent for direct DB use (useful for forensic queries):
        //
        //   SELECT ol.timecreated, u.firstname, u.lastname, u.email,
        //          cr.fullname AS course, c.name AS cohort,
        //          ol.oldoutcome, ol.newoutcome, ol.source,
        //          COALESCE(CONCAT(cu.firstname,' ',cu.lastname), '(system)') AS changedby_name
        //     FROM mdl_block_trainingplan_outcome_log ol
        //     JOIN mdl_user   u  ON u.id  = ol.userid
        //     JOIN mdl_course cr ON cr.id = ol.courseid
        //     JOIN mdl_cohort c  ON c.id  = ol.cohortid
        //     LEFT JOIN mdl_user cu ON cu.id = ol.changedby
        //    WHERE ol.newoutcome = 'NYS' AND ol.oldoutcome = 'IP'    -- remove to see all
        //      AND ol.timecreated >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))
        //    ORDER BY ol.timecreated DESC;
        require_capability('block/trainingplan:manage', $sysctx);
        $page    = max(1, optional_param('page', 1, PARAM_INT));
        $perpage = max(5, min(200, optional_param('perpage', 50, PARAM_INT)));
        $offset  = ($page - 1) * $perpage;

        // Optional filters.
        $filterold = optional_param('oldoutcome', '', PARAM_ALPHA);
        $filternew = optional_param('newoutcome', '', PARAM_ALPHA);
        $days      = optional_param('days', 0, PARAM_INT); // 0 = all time

        $where  = [];
        $params = [];
        if ($filterold !== '') {
            $where[]               = 'ol.oldoutcome = :oldoutcome';
            $params['oldoutcome']  = $filterold;
        }
        if ($filternew !== '') {
            $where[]               = 'ol.newoutcome = :newoutcome';
            $params['newoutcome']  = $filternew;
        }
        if ($days > 0) {
            $where[]          = 'ol.timecreated >= :since';
            $params['since']  = time() - ($days * DAYSECS);
        }
        $wheresql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countsql = "SELECT COUNT(*) FROM {block_trainingplan_outcome_log} ol $wheresql";
        $total    = $DB->count_records_sql($countsql, $params);

        $sql = "SELECT ol.*,
                       u.firstname, u.lastname, u.email,
                       cr.fullname  AS coursename,
                       c.name       AS cohortname,
                       cu.firstname AS cbyfirst,
                       cu.lastname  AS cbylast
                  FROM {block_trainingplan_outcome_log} ol
                  JOIN {user}   u  ON u.id  = ol.userid
                  JOIN {course} cr ON cr.id = ol.courseid
                  JOIN {cohort} c  ON c.id  = ol.cohortid
                  LEFT JOIN {user} cu ON cu.id = ol.changedby AND ol.changedby > 0
                $wheresql
              ORDER BY ol.timecreated DESC";

        $recs = $DB->get_records_sql($sql, $params, $offset, $perpage);

        $rows = [];
        foreach ($recs as $r) {
            $rows[] = [
                'timecreated'  => userdate($r->timecreated, '%d %b %Y %H:%M'),
                'student'      => fullname($r),
                'email'        => $r->email,
                'cohort'       => format_string($r->cohortname),
                'course'       => format_string($r->coursename),
                'oldoutcome'   => $r->oldoutcome ?? '',
                'newoutcome'   => $r->newoutcome,
                'source'       => $r->source,
                'changedby'    => $r->changedby > 0
                                  ? trim(($r->cbyfirst ?? '') . ' ' . ($r->cbylast ?? ''))
                                  : '(system)',
            ];
        }
        echo json_encode([
            'status'  => 'ok',
            'rows'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'perpage' => $perpage,
            'pages'   => $total > 0 ? (int)ceil($total / $perpage) : 1,
        ]);
        break;

    default:
    throw new moodle_exception('invalidaction');
}
