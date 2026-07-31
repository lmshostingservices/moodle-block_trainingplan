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

namespace block_trainingplan\output;

defined('MOODLE_INTERNAL') || die();

class renderer extends \plugin_renderer_base {

    public function render_student_view(student_view $view): string {
        $data = $view->export_for_template($this);
        return $this->render_from_template('block_trainingplan/student', $data);
    }

    public function render_admin_view(admin_view $view): string {
        $data = $view->export_for_template($this);
        return $this->render_from_template('block_trainingplan/admin', $data);
    }

    // Auto map render() to the correct method.
    public function render($renderable): string {
        if ($renderable instanceof student_view) {
            return $this->render_student_view($renderable);
        } else if ($renderable instanceof admin_view) {
            return $this->render_admin_view($renderable);
        }
        return parent::render($renderable);
    }
}
