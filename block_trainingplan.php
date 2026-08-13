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

// This file is part of Moodle - http://moodle.org/.
//
// Block: Training Plan.

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/blocks/moodleblock.class.php'); 

class block_trainingplan extends block_base {
    public function init(): void {
        $this->title = get_string('pluginname', 'block_trainingplan');
    }

    public function applicable_formats(): array {
        // Dashboard and course pages (course page shows plan for cohorts linked to that user too).
        return ['my' => true, 'course-view' => true, 'site' => true];
    }

    public function instance_allow_config(): bool {
        return false;
    }

    public function has_config(): bool {
        return true;
    }

    public function get_content() {
        global $USER, $PAGE;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $renderer = $PAGE->get_renderer('block_trainingplan');

        $canmanage = has_capability('block/trainingplan:manage', \context_system::instance());

        if ($canmanage) {
            $view = new \block_trainingplan\output\admin_view();
           //
            $this->content->text = $renderer->render($view);
        } else {
            require_capability('block/trainingplan:view', \context_system::instance());
            $view = new \block_trainingplan\output\student_view($USER->id);
            $this->content->text = $renderer->render($view);
        }

        $this->content->footer = '';
        return $this->content;
    }
}
