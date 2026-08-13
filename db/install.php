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
 * Post-installation steps for block_trainingplan.
 *
 * WHY THIS FILE EXISTS.
 *
 * Moodle runs db/upgrade.php ONLY when upgrading an existing install. On a FRESH
 * install it runs install.xml and then this file - upgrade.php is never called.
 *
 * The historical cutoff (block_trainingplan/overduecutoff) is what stops the very
 * first digest mailing out a site's entire pre-existing backlog of overdue plans.
 * It was being set in upgrade.php only, which meant a fresh install ended up with
 * an EMPTY cutoff - i.e. no protection at all. On a site with a long-standing
 * backlog, the first Monday would have dumped hundreds of stale learners onto a
 * handful of trainers.
 *
 * Setting it here as well means both paths - fresh install and upgrade - are safe.
 *
 * @return bool
 * @package    block_trainingplan
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
function xmldb_block_trainingplan_install() {

    // Guard so an admin who deliberately clears the cutoff later is never
    // second-guessed: once initialised, we never set it again.
    if (get_config('block_trainingplan', 'cutoffinitialised')) {
        return true;
    }

    // Resolve "today" in MOODLE'S server timezone, not PHP's ambient default. On a
    // site well east or west of UTC a naive date() can land on the wrong calendar
    // day and shift the boundary by 24 hours.
    $today = (new \DateTime('now', \core_date::get_server_timezone_object()))
        ->format('Y-m-d');

    set_config('overduecutoff', $today, 'block_trainingplan');
    set_config('cutoffinitialised', 1, 'block_trainingplan');

    return true;
}
