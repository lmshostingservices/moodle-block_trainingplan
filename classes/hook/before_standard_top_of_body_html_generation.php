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

namespace block_trainingplan\hook;

defined('MOODLE_INTERNAL') || die();

/**
 * Shows site administrators a repair banner while the recorded plugin version is
 * stranded above the version the installed files declare.
 *
 * Why this hook: a higher stored version does NOT put Moodle into upgrade mode
 * (moodle_needs_upgrading() only reacts to a LOWER one), so the site runs normally
 * and the fault is completely silent — right up until someone tries to install an
 * update and is told "A higher version of this plugin is already installed".
 * Surfacing it on every admin page is what turns a mystery into a one-click fix.
 *
 * @package   block_trainingplan
 * @copyright 2026 LMS-Labs
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class before_standard_top_of_body_html_generation {
    /**
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook
     */
    public static function callback(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        global $CFG;

        // Never on CLI, AJAX or web service requests — there is nobody to read it and
        // injecting HTML into a JSON response would corrupt it.
        if ((defined('CLI_SCRIPT') && CLI_SCRIPT)
            || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)
            || (defined('WS_SERVER') && WS_SERVER)) {
            return;
        }
        if (!isloggedin() || isguestuser()) {
            return;
        }

        require_once($CFG->dirroot . '/blocks/trainingplan/lib.php');

        // The banner function checks is_siteadmin() itself and returns '' for everyone
        // else, and short-circuits on a healthy site, so this costs one cached lookup.
        $html = block_trainingplan_version_banner();
        if ($html !== '') {
            $hook->add_html($html);
        }
    }
}
