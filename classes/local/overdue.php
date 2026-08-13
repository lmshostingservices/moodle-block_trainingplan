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

/**
 * Works out which training plans are behind schedule.
 *
 * IMPORTANT - what "behind" means here.
 *
 * The cron task deliberately blocks a course whose predecessor is not complete
 * (step 5 of update_trainingplan_status). Those courses keep status 'pending'
 * and never reach the 'expired' branch, even though their enddate has passed.
 * That is by design: a learner cannot miss a deadline for a course they were
 * never allowed to open.
 *
 * So we do NOT rely on status = 'expired'. We derive "behind" directly:
 *
 *   a schedule row is behind if  enddate has passed  AND  it is not finished
 *
 * and we then roll those rows up to ONE record per learner+cohort. The row with
 * the earliest passed enddate is the BLOCKER - the course the learner is
 * actually stuck on, and the only one a trainer can usefully act on.
 *
 * This class does not send anything and does not write to the database.
 * @package    block_trainingplan
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class overdue {
    /**
     * Statuses that mean the course is finished or not applicable.
     *
     * 'completed' and 'na' are what the cron task writes.
     *
     * 'CT' (credit transfer), 'C' (competent) and 'RPL' (recognition of prior
     * learning) are OUTCOME codes, not schedule statuses - update_trainingplan_status
     * maps all three to 'completed' before writing. They should never appear in
     * this column. They are listed here defensively because sites have been seen
     * with raw outcome codes written straight into the status column by an import
     * or by admin UI, bypassing cron. Without this, those learners would be chased
     * for a course that has already been credited to them.
     */
    private const DONE_STATUSES = ['completed', 'na', 'CT', 'C', 'RPL'];

    /**
     * Get every plan that is behind schedule, one record per learner+cohort.
     *
     * @param int $cutoff  Ignore plans whose blocking course fell due BEFORE this
     *                     timestamp. Use this to exclude a historical backlog that
     *                     pre-dates go-live. 0 = include everything.
     * @param int $now     Timestamp to evaluate against (for testing).
     * @return array  Records with: userid, cohortid, blockercourseid, blockerdue,
     *                daysbehind, coursesbehind.
     */
    public static function get_stalled_plans(int $cutoff = 0, int $now = 0): array {
        global $DB;

        $now = $now ?: time();

        [$insql, $params] = $DB->get_in_or_equal(self::DONE_STATUSES, SQL_PARAMS_NAMED, 'st', false);
        $params['now'] = $now;

        // Deleted and suspended learners are excluded: a trainer cannot act on
        // them, and chasing someone who has left is worse than not chasing at all.
        // JOIN {course} is a guard, not decoration: a schedule row can outlive the
        // course it points at.
        //
        // The EXISTS on {user_enrolments} is a second guard, and it is load-bearing.
        // Live data contains 14 behind rows whose learner is not enrolled in that
        // course by ANY method - the schedule row outlived the enrolment. Without
        // this, a trainer is told to chase someone who is not in their course.
        //
        // Note carefully: enrolment must EXIST, but is NOT required to be ACTIVE.
        // update_trainingplan_status SUSPENDS the enrolment when it blocks (step 5)
        // or expires (step 7) a course, so a genuinely stalled learner will usually
        // have a suspended enrolment. Filtering on ue.status = 0 here would exclude
        // precisely the people this digest exists to report.
        $sql = "SELECT s.id, s.userid, s.cohortid, s.courseid, s.enddate, s.status
                  FROM {block_trainingplan_schedule} s
                  JOIN {user}   u ON u.id = s.userid
                  JOIN {course} c ON c.id = s.courseid
                 WHERE s.enddate > 0
                   AND s.enddate < :now
                   AND s.status {$insql}
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND EXISTS (
                         SELECT 1
                           FROM {user_enrolments} ue
                           JOIN {enrol} e ON e.id = ue.enrolid
                          WHERE e.courseid = s.courseid
                            AND ue.userid  = s.userid
                       )
              ORDER BY s.userid, s.cohortid, s.enddate";

        $rows = $DB->get_records_sql($sql, $params);

        // Roll up to one record per learner + cohort.
        $plans = [];

        foreach ($rows as $row) {
            $key = $row->userid . '-' . $row->cohortid;

            if (!isset($plans[$key])) {
                $plans[$key] = (object)[
                    'userid'          => (int)$row->userid,
                    'cohortid'        => (int)$row->cohortid,
                    'blockercourseid' => (int)$row->courseid,
                    'blockerdue'      => (int)$row->enddate,
                    'coursesbehind'   => 0,
                ];
            }

            // Earliest passed enddate wins - that is the blocking course.
            if ((int)$row->enddate < $plans[$key]->blockerdue) {
                $plans[$key]->blockerdue      = (int)$row->enddate;
                $plans[$key]->blockercourseid = (int)$row->courseid;
            }

            $plans[$key]->coursesbehind++;
        }

        // Exclude the historical backlog: anything whose blocker fell due before
        // the cutoff. Unlike a rolling window, this does NOT let a plan age out -
        // once a plan is in scope it stays in scope until it is resolved.
        $result = [];

        foreach ($plans as $plan) {
            if ($cutoff > 0 && $plan->blockerdue < $cutoff) {
                continue;
            }
            $plan->daysbehind = (int)floor(($now - $plan->blockerdue) / DAYSECS);
            $result[] = $plan;
        }

        // Most overdue first.
        usort($result, fn($a, $b) => $b->daysbehind <=> $a->daysbehind);

        return $result;
    }

    /**
     * The configured historical cutoff, as a timestamp.
     *
     * Plans whose blocking course fell due before this are treated as pre-existing
     * backlog and never notified. Empty setting = no cutoff = chase everything.
     *
     * TIMEZONE. This deliberately does NOT use strtotime(), which resolves a bare
     * date against PHP's ambient default timezone. That happens to be correct on a
     * standard Moodle (setup.php sets PHP's default from $CFG->timezone), but it is
     * correct by luck rather than by construction, and it breaks quietly if anything
     * touches the default timezone - a CLI wrapper, a cron shim, a stray
     * date_default_timezone_set() in a plugin.
     *
     * The cutoff is the single control preventing an entire historical backlog from
     * being mailed out at once, so it resolves the date explicitly against Moodle's
     * own server timezone. On a site well east or west of UTC, an off-by-one here
     * silently shifts the boundary by a whole day.
     *
     * @return int  Timestamp, or 0 for no cutoff.
     */
    public static function get_cutoff(): int {
        $raw = trim((string)get_config('block_trainingplan', 'overduecutoff'));

        if ($raw === '') {
            return 0;
        }

        // A raw timestamp is accepted and needs no interpretation.
        if (ctype_digit($raw)) {
            return (int)$raw;
        }

        // Otherwise resolve YYYY-MM-DD as local midnight in Moodle's server timezone.
        $tz = \core_date::get_server_timezone_object();
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $raw . ' 00:00:00', $tz);

        if (!$dt) {
            debugging(
                "block_trainingplan: could not parse overduecutoff '{$raw}'. "
                . "Expected YYYY-MM-DD. Treating as NO cutoff, which means the full "
                . "historical backlog is in scope.",
                DEBUG_DEVELOPER
            );
            return 0;
        }

        return (int)$dt->getTimestamp();
    }

    /**
     * Which users should be told about a stalled learner on a given course?
     *
     * Anyone holding block/trainingplan:receiveoverduedigest in that course's
     * context - normally its teachers. If a course has nobody, the caller
     * should fall back to system-level holders of the capability.
     *
     * @return int[]  User ids.
     */
    public static function get_trainers_for_course(int $courseid): array {
        try {
            $context = \context_course::instance($courseid);
        } catch (\Throwable $e) {
            return [];
        }

        // Deliberately the 3-argument form. The optional parameters of this core
        // function have shifted between Moodle releases, and passing positional
        // values into slots whose meaning has changed is a good way to silently
        // select the wrong set of people.
        $users = get_users_by_capability(
            $context,
            'block/trainingplan:receiveoverduedigest',
            'u.id'
        );

        return $users ? array_map('intval', array_keys($users)) : [];
    }

    /**
     * User ids that must never receive a digest, whatever their role.
     *
     * Live data shows several accounts named "... Student" holding editingteacher
     * and teacher roles - almost certainly test accounts that were given a trainer
     * role and never cleaned up. They would receive trainer digests about real
     * learners. Rather than silently work around a role-assignment problem, this
     * gives an admin an explicit, visible switch.
     *
     * @return int[]
     */
    public static function get_excluded_recipients(): array {
        $raw = trim((string)get_config('block_trainingplan', 'excludedrecipients'));

        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $ids = array_filter(array_map('intval', $parts));

        return array_values(array_unique($ids));
    }

    /**
     * Fallback recipients: system-level holders of the capability.
     *
     * Used only when a blocking course has nobody assigned. If a site has courses
     * with no teacher, those learners land here rather than being dropped silently.
     *
     * @return int[]  User ids.
     */
    public static function get_fallback_recipients(): array {
        $users = get_users_by_capability(
            \context_system::instance(),
            'block/trainingplan:receiveoverduedigest',
            'u.id'
        );

        return $users ? array_map('intval', array_keys($users)) : [];
    }
}
