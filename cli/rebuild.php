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

// CLI rebuild script to populate block_trainingplan_schedule for all existing cohort members.
define('CLI_SCRIPT', true);

// Load Moodle framework.
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/lib/classes/event/cohort_member_added.php');

global $DB;

// Loop through all cohorts and members.
$cohorts = $DB->get_records('cohort');
foreach ($cohorts as $c) {
    echo "Processing cohort {$c->id} ({$c->name})\n";
    $members = $DB->get_records('cohort_members', ['cohortid' => $c->id]);
    foreach ($members as $m) {
        echo "  -> User {$m->userid}\n";

        // Create a proper event object so we can reuse the observer logic.
        $event = \core\event\cohort_member_added::create([
            'objectid' => $c->id,
            'context' => \context_system::instance(),
            'relateduserid' => $m->userid,
        ]);

        try {
            \block_trainingplan\observer::cohort_member_added($event);
            echo "     ✓ Schedule built\n";
        } catch (Exception $e) {
            echo "     ⚠️  Error: " . $e->getMessage() . "\n";
        }
    }
}

echo "✅ Done.\n";
