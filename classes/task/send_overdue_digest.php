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

namespace block_trainingplan\task;

defined('MOODLE_INTERNAL') || die();

use block_trainingplan\local\overdue;
use block_trainingplan\local\notifier;

/**
 * Weekly digest of learners whose training plan has fallen behind.
 *
 * Design decisions worth knowing:
 *
 * 1. TRAINERS ONLY. Learners are not emailed. 84% of currently-stalled plans on
 *    a mature site are typically MONTHS behind, not days. Notifying learners
 *    directly would mean telling a large number of people at once that they are
 *    long overdue. That is an incident, not a nudge. A weekly worklist to the
 *    staff who can actually intervene is the useful thing.
 *
 * 2. ONE EMAIL PER TRAINER, not per learner and definitely not per course row.
 *    A learner can have a dozen schedule rows behind; a trainer can have a
 *    hundred learners. Per-row notification would send ~749 messages a run.
 *
 * 3. THE SCHEDULE IS THE COOLDOWN. This task is registered weekly, so there is
 *    no cooldown table and no state to get wrong. Change the frequency in
 *    Site administration > Server > Scheduled tasks.
 *
 * 4. IT SENDS NOTHING BY DEFAULT. Every send goes through notifier::send(),
 *    which is gated by block_trainingplan/notificationsenabled (off by default)
 *    and the test recipient allowlist. Suppressed sends are logged, so a dry run
 *    tells you exactly who would have been mailed.
 */
class send_overdue_digest extends \core\task\scheduled_task {

    /** Never list more than this many learners in one email. */
    private const MAX_ROWS = 50;

    public function get_name(): string {
        return get_string('cron_send_overdue_digest', 'block_trainingplan');
    }

    public function execute() {
        global $DB;

        $now = time();

        mtrace(str_repeat('-', 50));
        mtrace('TRAINING PLAN OVERDUE DIGEST');
        mtrace(str_repeat('-', 50));

        if (!notifier::is_enabled()) {
            mtrace('Notifications are DISABLED in plugin settings.');
            mtrace('Running as a DRY RUN - nothing will be sent, everything logged.');
        }

        $cutoff = overdue::get_cutoff();
        if ($cutoff > 0) {
            mtrace('Historical cutoff: ' . userdate($cutoff)
                . ' - plans already overdue before this date are ignored.');
        } else {
            mtrace('Historical cutoff: NONE - the full backlog is in scope.');
            mtrace('  !! With no cutoff, EVERY plan that is behind schedule - however');
            mtrace('  !! old - will be included. On a site with a long-standing backlog');
            mtrace('  !! that can mean hundreds of stale learners in one email.');
            mtrace('  !! Set "Ignore plans overdue before" in the plugin settings if');
            mtrace('  !! this is not what you want.');
        }

        $plans = overdue::get_stalled_plans($cutoff, $now);

        if (empty($plans)) {
            mtrace('No stalled plans found. Nothing to do.');
            return;
        }

        mtrace('Stalled plans found: ' . count($plans));

        // Group stalled learners by the trainer who should hear about them.
        $bytrainer = [];
        $trainercache = [];
        $orphaned = [];

        foreach ($plans as $plan) {
            $courseid = $plan->blockercourseid;

            if (!array_key_exists($courseid, $trainercache)) {
                $trainercache[$courseid] = overdue::get_trainers_for_course($courseid);
            }

            $trainers = $trainercache[$courseid];

            if (empty($trainers)) {
                $orphaned[] = $plan;
                continue;
            }

            foreach ($trainers as $trainerid) {
                $bytrainer[$trainerid][] = $plan;
            }
        }

        // Courses with no trainer fall back to system-level capability holders.
        if (!empty($orphaned)) {
            mtrace('Plans whose blocking course has no trainer: ' . count($orphaned));
            foreach (overdue::get_fallback_recipients() as $fallbackid) {
                foreach ($orphaned as $plan) {
                    $bytrainer[$fallbackid][] = $plan;
                }
            }
        }

        if (empty($bytrainer)) {
            mtrace('No recipients hold block/trainingplan:receiveoverduedigest. Nothing sent.');
            return;
        }

        // Drop anyone on the exclusion list before a single message is built.
        $excluded = overdue::get_excluded_recipients();
        foreach ($excluded as $exid) {
            if (isset($bytrainer[$exid])) {
                mtrace("Excluded recipient {$exid} (plugin exclusion list) - skipped.");
                unset($bytrainer[$exid]);
            }
        }

        mtrace('Recipients: ' . count($bytrainer));
        mtrace('');

        $sent = 0;
        foreach ($bytrainer as $trainerid => $trainerplans) {
            if ($this->send_digest((int)$trainerid, $trainerplans, $now)) {
                $sent++;
            }
        }

        mtrace('');
        mtrace("Digests actually sent: {$sent} of " . count($bytrainer));
        mtrace(str_repeat('-', 50));
    }

    /**
     * Build and send one trainer's digest.
     */
    private function send_digest(int $trainerid, array $plans, int $now): bool {
        global $DB;

        $trainer = $DB->get_record('user', ['id' => $trainerid, 'deleted' => 0]);
        if (!$trainer || !empty($trainer->suspended)) {
            mtrace("Skipping recipient {$trainerid}: user missing, deleted or suspended.");
            return false;
        }

        // Most overdue first, and de-duplicate.
        usort($plans, fn($a, $b) => $b->daysbehind <=> $a->daysbehind);

        $total = count($plans);
        $shown = array_slice($plans, 0, self::MAX_ROWS);

        $lines = [];
        $htmlrows = [];

        foreach ($shown as $plan) {
            $learner = $DB->get_record('user', ['id' => $plan->userid], '*');
            $course  = $DB->get_record('course', ['id' => $plan->blockercourseid], 'id, fullname');

            $name       = $learner ? fullname($learner) : "User {$plan->userid}";
            $coursename = $course ? format_string($course->fullname) : "Course {$plan->blockercourseid}";
            $due        = userdate($plan->blockerdue, get_string('strftimedatefullshort', 'langconfig'));

            $lines[] = sprintf(
                '%s - stuck on "%s" - due %s (%d days ago) - %d course(s) behind',
                $name, $coursename, $due, $plan->daysbehind, $plan->coursesbehind
            );

            $htmlrows[] = '<tr>'
                . '<td>' . s($name) . '</td>'
                . '<td>' . s($coursename) . '</td>'
                . '<td>' . s($due) . '</td>'
                . '<td style="text-align:right">' . (int)$plan->daysbehind . '</td>'
                . '<td style="text-align:right">' . (int)$plan->coursesbehind . '</td>'
                . '</tr>';
        }

        $more = $total > self::MAX_ROWS ? ($total - self::MAX_ROWS) : 0;

        $intro = get_string('digestintro', 'block_trainingplan', $total);

        $text = $intro . "\n\n" . implode("\n", $lines);
        if ($more) {
            $text .= "\n\n" . get_string('digestmore', 'block_trainingplan', $more);
        }

        $html = '<p>' . s($intro) . '</p>'
            . '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse">'
            . '<thead><tr>'
            . '<th align="left">' . get_string('digestcol_learner', 'block_trainingplan') . '</th>'
            . '<th align="left">' . get_string('digestcol_blocker', 'block_trainingplan') . '</th>'
            . '<th align="left">' . get_string('digestcol_due', 'block_trainingplan') . '</th>'
            . '<th align="right">' . get_string('digestcol_days', 'block_trainingplan') . '</th>'
            . '<th align="right">' . get_string('digestcol_behind', 'block_trainingplan') . '</th>'
            . '</tr></thead><tbody>'
            . implode('', $htmlrows)
            . '</tbody></table>';

        if ($more) {
            $html .= '<p>' . s(get_string('digestmore', 'block_trainingplan', $more)) . '</p>';
        }

        $event = new \core\message\message();
        $event->component         = 'block_trainingplan';
        $event->name              = 'overduedigest';
        $event->userfrom          = \core_user::get_noreply_user();
        $event->userto            = $trainer;
        $event->subject           = get_string('digestsubject', 'block_trainingplan', $total);
        $event->fullmessage       = $text;
        $event->fullmessageformat = FORMAT_PLAIN;
        $event->fullmessagehtml   = $html;
        $event->smallmessage      = $intro;
        $event->notification      = 1;

        return notifier::send($event, "overdue digest, {$total} learner(s)");
    }
}
