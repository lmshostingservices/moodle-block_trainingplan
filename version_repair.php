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

/**
 * One-click repair for a stranded plugin version.
 *
 * A site that installed a legacy 13-digit build of this plugin has that value
 * recorded in config_plugins — numerically ~1000x larger than any valid
 * 10-digit version. Moodle then refuses every future update with "A higher
 * version of this plugin is already installed", and the only remedies until
 * now were an SSH session and a hand-written UPDATE statement.
 *
 * This page does the same thing safely from the browser: it re-reads the version
 * the installed files declare, sets the recorded version to one below it, and
 * purges caches so Moodle notices. Setting it one BELOW rather than equal is
 * deliberate — it means the normal upgrade still runs afterwards and applies
 * anything the site missed, instead of merely asserting that it is up to date.
 *
 * No student or enrolment data is read or written. The only change is a single
 * row of config_plugins, recorded in the standard config change log.
 *
 * @package    block_trainingplan
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

require_login();

$context = context_system::instance();
// Changing a recorded plugin version is a site-administration act, so it needs the
// same capability Moodle requires to run an upgrade — not merely plugin management.
require_capability('moodle/site:config', $context);
require_sesskey();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/trainingplan/version_repair.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('versionrepair_title', 'block_trainingplan'));
$PAGE->set_heading(get_string('versionrepair_title', 'block_trainingplan'));

$state = block_trainingplan_version_is_stranded();

if ($state === false) {
    redirect(
        new moodle_url('/admin/index.php'),
        get_string('versionrepair_notneeded', 'block_trainingplan'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$result = block_trainingplan_repair_stranded_version();

echo $OUTPUT->header();

if (!empty($result['ok'])) {
    echo $OUTPUT->notification($result['message'], \core\output\notification::NOTIFY_SUCCESS);
    echo html_writer::tag('p', get_string('versionrepair_next', 'block_trainingplan'));
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/admin/index.php'),
            get_string('versionrepair_gotonotifications', 'block_trainingplan'),
            ['class' => 'btn btn-primary']
        ),
        '',
        ['style' => 'margin-top:12px;']
    );
} else {
    echo $OUTPUT->notification($result['message'], \core\output\notification::NOTIFY_WARNING);
}

echo $OUTPUT->footer();
