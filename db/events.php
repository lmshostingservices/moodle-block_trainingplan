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

$observers = [
    [
        'eventname' => '\core\event\cohort_member_added',
        'callback' => '\block_trainingplan\observer::cohort_member_added'
    ],
    [
        'eventname' => '\core\event\cohort_member_removed',
        'callback' => '\block_trainingplan\observer::cohort_member_removed'
    ],
    [
        'eventname' => '\core\event\cohort_created',
        'callback'  => '\block_trainingplan\observer::cohort_created',
    ],
    [
        'eventname' => '\core\event\enrol_instance_created',
        'callback'  => '\block_trainingplan\observer::enrol_instance_created',
    ],
    [
        'eventname' => '\core\event\course_created',
        'callback'  => '\block_trainingplan\observer::course_created',
    ],
    [
        'eventname' => '\core\event\enrol_instance_updated',
        'callback'  => '\block_trainingplan\observer::enrol_instance_updated',
    ],
    [
        'eventname' => '\core\event\enrol_instance_deleted',
        'callback'  => '\block_trainingplan\observer::enrol_instance_deleted',
    ],
    [
        'eventname'   => '\core\event\user_enrolled',
        'callback'    => '\block_trainingplan\observer::user_enrolled'
    ],
    // BLOCKER-3-FIX (v1.5.2): Open the next unit when a student submits their
    // assignment(s). A student must not be held to the calendar while waiting
    // for marking — submitting the prior unit is enough to unlock the next one.
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback'  => '\block_trainingplan\observer::assessment_submitted',
    ],
];
