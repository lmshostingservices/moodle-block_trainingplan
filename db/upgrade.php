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

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for block_trainingplan.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_block_trainingplan_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // 2026071300 - Add the lastrisknotif column.
    //
    // The at-risk notification cooldown in update_trainingplan_status::handle_at_risk()
    // reads and writes $schedule->lastrisknotif, but the column was never defined in
    // install.xml and there was no upgrade path. Moodle's update_record() silently
    // discards properties that are not real columns, so the cooldown never persisted
    // and the 7-day throttle never applied. Because the cron task runs every minute,
    // any eligible schedule row would have been notified once per minute indefinitely.
    //
    // Adding the column makes the EXISTING cooldown logic work as originally intended.
    // It cannot cause any notification that was not already possible; it can only
    // suppress repeats.
    if ($oldversion < 2026071300) {

        $table = new xmldb_table('block_trainingplan_schedule');
        $field = new xmldb_field(
            'lastrisknotif',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'signature'
        );

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_block_savepoint(true, 2026071300, 'trainingplan');
    }

    // 2026071304 - Set the historical cutoff to the install date.
    //
    // Any site that has been running training plans for a while will already have a
    // backlog of plans that are behind schedule - often heavily so, and often
    // including cohorts that were bulk-generated and then abandoned. If the digest
    // went out with no cutoff, the very first email would hand trainers a list of
    // learners who have been overdue for months. That is not a worklist, it is a
    // data dump, and it buries the learners who have slipped recently and can still
    // be helped.
    //
    // So the cutoff defaults to NOW: the pre-existing backlog is never chased, and
    // anything that falls behind from today onward is chased until it is resolved.
    //
    // An admin can clear the setting to include the full backlog deliberately.
    if ($oldversion < 2026071304) {

        // The same guard used in db/install.php: once the cutoff has been
        // initialised we never touch it again, so an admin who deliberately clears
        // it (meaning "chase the whole backlog") is not silently overridden.
        if (!get_config('block_trainingplan', 'cutoffinitialised')) {
            // Resolve "today" in MOODLE'S server timezone, not PHP's ambient default.
            // On a site well east or west of UTC a naive date() can land on the wrong
            // calendar day and shift the cutoff by 24 hours - which on a boundary day
            // is the difference between a quiet first digest and mailing out a backlog.
            $today = (new \DateTime('now', \core_date::get_server_timezone_object()))
                ->format('Y-m-d');

            set_config('overduecutoff', $today, 'block_trainingplan');
            set_config('cutoffinitialised', 1, 'block_trainingplan');

            mtrace("block_trainingplan: historical cutoff set to {$today} "
                . "(server timezone). Plans already overdue before this date will "
                . "not be included in the digest.");
        }

        upgrade_block_savepoint(true, 2026071304, 'trainingplan');
    }

    // 2026071311 - Repair installs that ended up with NO cutoff.
    //
    // Before db/install.php existed, a FRESH install never ran this file at all, so
    // the cutoff was never written and the site was left with no backlog protection.
    // Those installs have already passed the 2026071304 savepoint, so the step above
    // will not fire for them again.
    //
    // This step repairs them: if the cutoff was never initialised, set it now. An
    // admin who has deliberately cleared it will have 'cutoffinitialised' set, so
    // their choice is preserved and this does nothing.
    if ($oldversion < 2026071311) {

        if (!get_config('block_trainingplan', 'cutoffinitialised')) {
            $today = (new \DateTime('now', \core_date::get_server_timezone_object()))
                ->format('Y-m-d');

            set_config('overduecutoff', $today, 'block_trainingplan');
            set_config('cutoffinitialised', 1, 'block_trainingplan');

            mtrace("block_trainingplan: no historical cutoff was set on this site "
                . "(fresh installs did not previously set one). Cutoff repaired to "
                . "{$today}. Plans already overdue before this date will not be "
                . "included in the digest.");
        }

        upgrade_block_savepoint(true, 2026071311, 'trainingplan');
    }

    // 2026071314 - Fix cooldown consumed even when send fails (Bug 1).
    //
    // send_risk_notification() was void — it discarded the bool returned by
    // notifier::send(). handle_at_risk() therefore always stamped lastrisknotif
    // regardless of whether the message was actually delivered. A learner whose
    // notification was suppressed (kill switch, test-recipient allowlist, or
    // message_send() returning false due to disabled Moodle preferences) was
    // locked out for 7 days from a notification they never received.
    //
    // This release makes send_risk_notification() return bool and propagates it
    // through handle_at_risk() — the cooldown stamp is only written on a
    // successful send.
    //
    // notifier::send() now also captures message_send()'s return value and
    // returns false if Moodle suppressed the message silently (e.g. provider
    // disabled in Site admin → General → Messaging → Notification settings).
    // Previously notifier::send() returned true unconditionally after calling
    // message_send() — the Bug 2 suppression therefore looked like a success,
    // burned the cooldown, and left no trace in the log.
    //
    // No DB schema changes. opcache_invalidate() used so all PHP-FPM workers
    // reload fresh bytecode immediately.
    if ($oldversion < 2026071314) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'classes/task/update_trainingplan_status.php',
                'classes/local/notifier.php',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_block_savepoint(true, 2026071314, 'trainingplan');
    }

    if ($oldversion < 2026071400) {
        // v1.4.5 — Three cooperating outcome-change bugs fixed:
        //  1. Backend auto-sets manualoverride=1 for conclusive outcomes (C, CT, RPL, NA)
        //     so the cron can never silently revert a staff decision.
        //  2. Date cascade in save_user_plan now stops at manualoverride rows instead of
        //     overwriting dates staff deliberately set.
        //  3. Duration calculation now guards against null/zero dates producing absurd durations.
        //  No schema changes — pure logic fix.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'ajax.php',
                'amd/build/edit.min.js',
                'classes/task/update_trainingplan_status.php',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_block_savepoint(true, 2026071400, 'trainingplan');
    }

    if ($oldversion < 2026071401) {
        // v1.4.6 — CSV export: add Start Date / End Date columns and UTF-8 BOM;
        //          Admin page: add manual Send Reminder UI.
        // No schema changes needed.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'ajax.php',
                'classes/local/helper.php',
                'templates/admin.mustache',
                'amd/build/admin.min.js',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_block_savepoint(true, 2026071401, 'trainingplan');
    }

    if ($oldversion < 2026071402) {
        // v1.4.7 — CRITICAL FIX: Remove direct user_enrolments DB writes.
        //
        // update_trainingplan_status cron was calling $DB->update_record('user_enrolments', ...)
        // directly, bypassing the Moodle enrolment API. enrol_cohort_sync (hourly) unconditionally
        // reactivates every suspended cohort-member enrolment, creating an infinite loop of
        // ~4,300 enrolment-flip events/hour that grew logstore_standard_log to 1.3 GB.
        //
        // Fix: activate_enrolment(), suspend_enrolment(), and suspend_all_course_enrolments()
        // are now no-ops. The cron still tracks outcomes and dates; gating via enrolment status
        // is removed entirely. Use enrol_prereq2 (already installed) to configure per-course-pair
        // access gating if hard sequential gating is required.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'classes/task/update_trainingplan_status.php',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_block_savepoint(true, 2026071402, 'trainingplan');
    }

    if ($oldversion < 2026071500) {
        // v1.4.7 final: cron task now calls \core\cron::setup_user(get_admin())
        // at the top of execute() so events are attributed to the site admin
        // account, not whoever happens to be $USER at cron time.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'classes/task/update_trainingplan_status.php',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_block_savepoint(true, 2026071500, 'trainingplan');
    }

    if ($oldversion < 2026071501) {
        // v1.4.8: Fix canaccess logic in student_view — NYS/C/CT/RPL units no
        // longer render a live link. Only IP units are clickable.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'classes/output/student_view.php',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_block_savepoint(true, 2026071501, 'trainingplan');
    }

    if ($oldversion < 2026071602) {
        // v1.4.9: Re-enable proper Moodle enrolment gating via enrol_get_plugin() API.
        // Remove automatic NYS -> IP outcome promotion to stop date-cascaded units
        // from silently becoming accessible. Outcomes are now admin-driven only.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'classes/task/update_trainingplan_status.php',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_block_savepoint(true, 2026071602, 'trainingplan');
    }

    if ($oldversion < 2026071603) {
        // FIX-TP-LATCH (v1.5.0): PHP-only fix. No DB schema changes.
        // Replaced the broken $previouscompleted / $isfirst gate with a
        // $currentassigned latch: only the first not-done unit per user+cohort
        // may enter the active window. Any subsequent not-done unit is immediately
        // suspended/pending, regardless of its date window.
        // Also guarded process_manual_override() update_record so it only fires
        // when outcome or enddate actually changed (Bug 2 — re-stamp noise fix).
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'classes/task/update_trainingplan_status.php',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_block_savepoint(true, 2026071603, 'trainingplan');
    }

    if ($oldversion < 2026071604) {
        // v1.5.1 changes — no DB schema changes for C1/H1/H2/M2/M3 (PHP-only).
        // M1-FIX: Add unique constraint on userseq(userid, cohortid, courseid)
        // so any future logic slip fails loudly instead of silently creating duplicate rows.
        // Step 1: Deduplicate — keep the row with the highest id per triplet.
        $dups = $DB->get_records_sql("
            SELECT userid, cohortid, courseid, MAX(id) AS keepid
              FROM {block_trainingplan_userseq}
          GROUP BY userid, cohortid, courseid
            HAVING COUNT(*) > 1
        ");
        foreach ($dups as $dup) {
            $DB->delete_records_select(
                'block_trainingplan_userseq',
                'userid = ? AND cohortid = ? AND courseid = ? AND id <> ?',
                [(int)$dup->userid, (int)$dup->cohortid, (int)$dup->courseid, (int)$dup->keepid]
            );
        }

        // Step 2: Add unique index (if not already present).
        $table = new xmldb_table('block_trainingplan_userseq');
        $index = new xmldb_index('user_cohort_course_uniq', XMLDB_INDEX_UNIQUE, ['userid', 'cohortid', 'courseid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Opcache flush.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'classes/task/update_trainingplan_status.php',
                'classes/observer.php',
                'classes/output/student_view.php',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_block_savepoint(true, 2026071604, 'trainingplan');
    }

    if ($oldversion < 2026071605) {
        // v1.5.2 — PHP-only fixes. No DB schema changes.
        // BLOCKER-1: Removed Moodle course completion as a source of outcome=C.
        //            Marksheet is now the only automatic C path.
        // BLOCKER-2: canaccess reverted to outcome=IP only (no date guard).
        // BLOCKER-3: assessable_submitted observer opens next NYS unit on submission.
        // BLOCKER-4: Marksheet block now guards CT/RPL/NA — never overwrites them.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'classes/task/update_trainingplan_status.php',
                'classes/observer.php',
                'classes/output/student_view.php',
                'db/events.php',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_block_savepoint(true, 2026071605, 'trainingplan');
    }

    // v1.5.3 — REVERT-STUDENT-VIEW: student_view.php reverted to original NA-skip-only behaviour.
    // The full plan (NYS, IP, C, CT, RPL) is shown; NA units remain hidden. No DB schema change.
    if ($oldversion < 2026071800) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['classes/output/student_view.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_block_savepoint(true, 2026071800, 'trainingplan');
    }

    // v1.5.5 (2026072101) — Outcome-change audit trail + defensive IP→NYS guard.
    //
    // PRIMARY DELIVERABLE: Client could not tell what changed an outcome or when,
    // forcing manual checking after reports of IP→NYS changes.
    //
    // Changes:
    //  1. New table block_trainingplan_outcome_log — one row per outcome change,
    //     with student, course, cohort, old→new outcome, source (cron /
    //     observer:assessment_submitted / marksheet / manual_ui), acting user,
    //     and timestamp.
    //
    //  2. helper::set_outcome($row, $newoutcome, $source, $changedby) — every
    //     outcome write in the plugin now goes through this method. It reads the
    //     current outcome, writes the new one to block_trainingplan_userseq, and
    //     inserts a matching log row. Returns early (no write, no log) if
    //     unchanged.
    //
    //  3. Defensive guard inside set_outcome(): if oldoutcome=IP, newoutcome=NYS,
    //     and source != 'manual_ui', the write is BLOCKED. An mtrace() + debugging()
    //     warning fires with full context. No legitimate automatic IP→NYS exists;
    //     any such attempt is a regression and must be caught loudly, not applied.
    //     Manual (manual_ui) IP→NYS is still permitted (staff intent) but logged.
    //
    //  4. Admin visibility: ajax action 'get_outcome_log' returns paginated, filterable
    //     outcome change records (filterable to IP→NYS in the last N days).
    //     See ajax.php case 'get_outcome_log' for a raw SQL equivalent usable
    //     directly in the DB for forensic queries.
    //
    //  5. Section 1 audit (IP→NYS concern) still holds — confirmed by code review:
    //     - cron active window: NYS→IP only (set_outcome source='cron')
    //     - cron marksheet path: →C only (set_outcome source='marksheet')
    //     - cron process_manual_override: →C only (set_outcome source='marksheet')
    //     - observer assessment_submitted: NYS→IP only (set_outcome source='observer:…')
    //     - ajax save_user_plan: any outcome allowed for staff (source='manual_ui')
    //     - reset_in_progress_outcome(): still explicit no-op (line 299+)
    //     - ensure_userseq_row(): INSERT only when row missing — no outcome overwrites
    //     - cohort_member_removed / enrol_instance_deleted: selective delete only
    //
    if ($oldversion < 2026072101) {

        // Create block_trainingplan_outcome_log.
        $table = new xmldb_table('block_trainingplan_outcome_log');
        $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('courseid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('cohortid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('oldoutcome',  XMLDB_TYPE_CHAR,    '10', null, null);   // nullable
        $table->add_field('newoutcome',  XMLDB_TYPE_CHAR,    '10', null, XMLDB_NOTNULL);
        $table->add_field('source',      XMLDB_TYPE_CHAR,    '40', null, XMLDB_NOTNULL);
        $table->add_field('changedby',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('user_course_idx',  XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
        $table->add_index('outcome_time_idx', XMLDB_INDEX_NOTUNIQUE, ['newoutcome', 'timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Opcache flush so PHP-FPM workers reload the updated helper + cron + observer.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'classes/local/helper.php',
                'classes/task/update_trainingplan_status.php',
                'classes/observer.php',
                'ajax.php',
                'version.php',
                'db/upgrade.php',
                'db/install.xml',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }

        upgrade_block_savepoint(true, 2026072101, 'trainingplan');
    }

    return true;
}
