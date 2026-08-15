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

/**
 * Detect a stranded recorded version for block_trainingplan.
 *
 * A site that installed a legacy 13-digit build has a version recorded in
 * config_plugins that is numerically ~1000x larger than any valid 10-digit
 * version. Moodle then refuses every future update with "A higher version of
 * this plugin is already installed". Detection compares the stored value with
 * what the installed files actually declare.
 *
 * @return array|false ['stored' => string, 'declared' => int, 'target' => int] or false when healthy.
 */
function block_trainingplan_version_is_stranded() {
    global $CFG;

    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = false;

    $stored = get_config('block_trainingplan', 'version');
    if ($stored === false || $stored === null || $stored === '') {
        return $cached;   // Not installed yet — nothing to repair.
    }

    // Read what version.php on disk actually declares, without disturbing globals.
    $plugin = new stdClass();
    $versionfile = $CFG->dirroot . '/blocks/trainingplan/version.php';
    if (!is_readable($versionfile)) {
        return $cached;
    }
    include($versionfile);
    if (empty($plugin->version)) {
        return $cached;
    }

    $declared = (int) $plugin->version;

    // Stranded when the stored value exceeds what the files declare. Comparing as
    // strings first guards against float rounding on a 32-bit PHP build, where a
    // 13-digit integer overflows and comparisons stop being exact.
    $isstranded = (strlen((string) $stored) > strlen((string) $declared))
        || ((string) $stored !== (string) $declared && (float) $stored > (float) $declared);

    if (!$isstranded) {
        return $cached;
    }

    // Target one below the declared version, so the normal Moodle upgrade still
    // runs afterwards and applies anything the site missed — rather than simply
    // asserting "we are up to date" and skipping schema reconciliation.
    $cached = [
        'stored'   => (string) $stored,
        'declared' => $declared,
        'target'   => $declared - 1,
    ];
    return $cached;
}

/**
 * Repair a stranded version. Caller MUST have already checked sesskey and capability.
 *
 * @return array ['ok' => bool, 'from' => string, 'to' => int, 'message' => string]
 */
function block_trainingplan_repair_stranded_version() {
    $state = block_trainingplan_version_is_stranded();
    if ($state === false) {
        return ['ok' => false, 'from' => '', 'to' => 0,
                'message' => get_string('versionrepair_notneeded', 'block_trainingplan')];
    }

    // add_to_config_log() gives an auditable record in mdl_config_log of exactly
    // what changed, by whom, and when — the same trail admin settings changes get.
    add_to_config_log('version', $state['stored'], (string) $state['target'], 'block_trainingplan');
    set_config('version', $state['target'], 'block_trainingplan');

    // The plugin manager caches version information hard; without this the admin
    // would click the button, see no change, and reasonably conclude it failed.
    purge_all_caches();

    return ['ok' => true, 'from' => $state['stored'], 'to' => $state['target'],
            'message' => get_string('versionrepair_done', 'block_trainingplan',
                (object) ['from' => $state['stored'], 'to' => $state['target']])];
}

/**
 * The red banner shown to site administrators while the version is stranded.
 *
 * @return string HTML, or '' when there is nothing to say.
 */
function block_trainingplan_version_banner() {
    if (!is_siteadmin()) {
        return '';   // Only an administrator can act on this, so only they see it.
    }
    $state = block_trainingplan_version_is_stranded();
    if ($state === false) {
        return '';
    }

    $url = new moodle_url('/blocks/trainingplan/version_repair.php', ['sesskey' => sesskey()]);

    $html  = '<div style="margin:12px;padding:14px 16px;border:2px solid #dc2626;border-radius:8px;'
           . 'background:#fef2f2;color:#7f1d1d;font-size:14px;line-height:1.6;">';
    $html .= '<strong style="font-size:15px;">Training Plan cannot be updated on this site</strong><br>';
    $html .= 'Moodle has recorded version <code>' . s($state['stored']) . '</code> for this plugin, which is '
           . 'higher than the <code>' . $state['declared'] . '</code> the installed files declare. That is a '
           . 'legacy numbering fault, not a real newer version — Moodle will refuse every future update with '
           . '<em>"A higher version of this plugin is already installed"</em>.';
    $html .= '<div style="margin-top:10px;">';
    $html .= '<a href="' . s($url->out(false)) . '" style="display:inline-block;padding:8px 16px;'
           . 'background:#dc2626;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">'
           . 'Fix this now</a>';
    $html .= '<span style="margin-left:12px;font-size:13px;">Sets the recorded version to <code>'
           . $state['target'] . '</code> so the normal upgrade can run. No student data is touched.</span>';
    $html .= '</div></div>';

    return $html;
}
