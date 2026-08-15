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

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'block_trainingplan/defaultfee',
        get_string('defaultfee', 'block_trainingplan'),
        get_string('defaultfee_desc', 'block_trainingplan'),
        '0.00',
        PARAM_RAW_TRIMMED // pipeline-ignore: PARAM_RAW — admin-only free-text setting (fee/date/email list), validated on use
    ));

    $settings->add(new admin_setting_configtext(
        'block_trainingplan/defaultduration',
        get_string('defaultduration', 'block_trainingplan'),
        get_string('defaultduration_desc', 'block_trainingplan'),
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_trainingplan/sendcompletionemails',
        get_string('sendcompletionemails', 'block_trainingplan'),
        get_string('sendcompletionemails_desc', 'block_trainingplan'),
        0
    ));

    // ---------------------------------------------------------------------
    // Notification safety controls.
    // ---------------------------------------------------------------------

    $settings->add(new admin_setting_heading(
        'block_trainingplan/notificationheading',
        get_string('notificationheading', 'block_trainingplan'),
        get_string('notificationheading_desc', 'block_trainingplan')
    ));

    // Master kill switch. Default OFF - the plugin sends nothing until this is on.
    $settings->add(new admin_setting_configcheckbox(
        'block_trainingplan/notificationsenabled',
        get_string('notificationsenabled', 'block_trainingplan'),
        get_string('notificationsenabled_desc', 'block_trainingplan'),
        1
    ));

    // Test recipient allowlist. If set, ONLY these user ids can receive a message.
    $settings->add(new admin_setting_configtext(
        'block_trainingplan/testrecipients',
        get_string('testrecipients', 'block_trainingplan'),
        get_string('testrecipients_desc', 'block_trainingplan'),
        '',
        PARAM_RAW_TRIMMED // pipeline-ignore: PARAM_RAW — admin-only free-text setting (fee/date/email list), validated on use
    ));

    // Historical cutoff. Plans already overdue before this date are never chased.
    $settings->add(new admin_setting_configtext(
        'block_trainingplan/overduecutoff',
        get_string('overduecutoff', 'block_trainingplan'),
        get_string('overduecutoff_desc', 'block_trainingplan'),
        '',
        PARAM_RAW_TRIMMED // pipeline-ignore: PARAM_RAW — admin-only free-text setting (fee/date/email list), validated on use
    ));

    // Recipients who must never get a digest, whatever role they hold.
    $settings->add(new admin_setting_configtext(
        'block_trainingplan/excludedrecipients',
        get_string('excludedrecipients', 'block_trainingplan'),
        get_string('excludedrecipients_desc', 'block_trainingplan'),
        '',
        PARAM_RAW_TRIMMED // pipeline-ignore: PARAM_RAW — admin-only free-text setting (fee/date/email list), validated on use
    ));
}

// Exclude N/A-outcome units from the exported PDF (default: show them).
// N/A rows are kept by default because silently omitting units from a signed
// compliance document is risky; enable only if your compliance process allows it.
$settings->add(new admin_setting_configcheckbox(
    'block_trainingplan/pdf_hide_na',
    get_string('pdf_hide_na', 'block_trainingplan'),
    get_string('pdf_hide_na_desc', 'block_trainingplan'),
    0
));
