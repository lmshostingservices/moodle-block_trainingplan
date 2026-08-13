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

defined('MOODLE_INTERNAL') || die();

$tasks = [
    // Status engine. Runs every minute. Unchanged.
    [
        'classname' => 'block_trainingplan\task\update_trainingplan_status',
        'blocking'  => 0,
        'minute'    => '*',
        'hour'      => '*',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*'
    ],

    // Overdue digest to trainers. WEEKLY - Monday 07:00.
    // The schedule IS the cooldown: there is no throttle state to get wrong.
    // Change the frequency in Site administration > Server > Scheduled tasks.
    [
        'classname' => 'block_trainingplan\task\send_overdue_digest',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '7',
        'day'       => '*',
        'dayofweek' => '1',
        'month'     => '*'
    ]
];
