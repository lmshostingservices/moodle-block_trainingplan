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

require_once('../../config.php');

// 1. Load the essential Moodle block libraries
require_once($CFG->libdir . '/blocklib.php');
require_once($CFG->dirroot . '/blocks/trainingplan/block_trainingplan.php');

// 2. Standard Moodle page setup
require_login();
$context = context_system::instance();

// CRITICAL: Set these BEFORE calling anything else
$PAGE->set_url(new moodle_url('/blocks/trainingplan/view.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'block_trainingplan'));
$PAGE->set_heading(get_string('pluginname', 'block_trainingplan'));
$PAGE->set_pagelayout('standard');

// 3. Initialize the block AFTER the page state is ready
$mytrainingplan = new block_trainingplan();
$mytrainingplan->init();

// 4. Start Output
echo $OUTPUT->header();

// 5. Get and display the content
$content = $mytrainingplan->get_content();
if ($content && !empty($content->text)) {
    echo $content->text;
} else {
    echo "No training plan content available.";
}

echo $OUTPUT->footer();