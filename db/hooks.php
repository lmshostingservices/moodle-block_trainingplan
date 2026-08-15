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
 * Hook callbacks for block_trainingplan.
 *
 * STRANDED-VERSION BANNER: shown to site administrators on every page while the
 * recorded plugin version is higher than the installed files declare (legacy
 * 13-digit numbering fault). Registered at the top of the body rather than on
 * the plugin's own pages because an admin who never opens this plugin still
 * needs to know why Moodle is refusing their updates. On Moodle versions older
 * than 4.3 (no hooks API) this file is ignored and the repair page at
 * /blocks/trainingplan/version_repair.php remains reachable directly.
 *
 * @package   block_trainingplan
 * @copyright 2026 LMS-Labs
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\output\before_standard_top_of_body_html_generation::class,
        'callback' => \block_trainingplan\hook\before_standard_top_of_body_html_generation::class . '::callback',
        'priority' => 500,
    ],
];
