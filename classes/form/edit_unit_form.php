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

namespace block_trainingplan\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class edit_unit_form extends \moodleform {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'cohortid');
        $mform->setType('cohortid', PARAM_INT);

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('text', 'duration', get_string('durationdays', 'block_trainingplan'));
        $mform->setType('duration', PARAM_INT);
        $mform->setDefault('duration', 30);

        $mform->addElement('text', 'fee', get_string('fee', 'block_trainingplan'));
        $mform->setType('fee', PARAM_RAW);

        $mform->addElement('select', 'type', get_string('type', 'block_trainingplan'), [
            'core' => get_string('core', 'block_trainingplan'),
            'elective' => get_string('elective', 'block_trainingplan'),
        ]);

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
