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

function block_trainingplan_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    global $DB;

    $plans = $DB->get_records_sql("
        SELECT DISTINCT c.id, c.name, s.signdate
          FROM {block_trainingplan_schedule} s
          JOIN {cohort} c ON c.id = s.cohortid
         WHERE s.userid = ?
      ORDER BY c.name", [$user->id]);

    if (!$plans) return;

    $html = html_writer::start_div('trainingplan-profile');
    $html .= html_writer::tag('h4', get_string('trainingplansigned', 'block_trainingplan'));
    $html .= html_writer::start_tag('ul');

    foreach ($plans as $p) {
        $signed = $p->signdate ? userdate($p->signdate) : get_string('notsigned', 'block_trainingplan');
        $color = $p->signdate ? 'text-success' : 'text-danger';
        $html .= html_writer::tag('li', format_string($p->name) . " — <span class='$color'>$signed</span>");
    }

    $html .= html_writer::end_tag('ul');
    $html .= html_writer::end_div();

    $category = new \core_user\output\myprofile\category(
        'trainingplan',
        get_string('trainingplan', 'block_trainingplan')
    );
    $node = new \core_user\output\myprofile\node(
        'trainingplan',
        'trainingplan_signed',
        get_string('trainingplansigned', 'block_trainingplan'),
        null,
        $html
    );

    $category->add_node($node);
    $tree->add_category($category);
}
