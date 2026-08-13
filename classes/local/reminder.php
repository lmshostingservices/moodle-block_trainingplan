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

namespace block_trainingplan\local;

defined('MOODLE_INTERNAL') || die();

class reminder {
    public static function send_bulk(array $userids, string $message, ?string $subject = null): void {
        global $DB;

        $subject = $subject ?: get_string('remindersubject', 'block_trainingplan');

        foreach ($userids as $userid) {
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
            $eventdata = new \core\message\message();
            $eventdata->component         = 'block_trainingplan';
            $eventdata->name              = 'reminder';
            $eventdata->userfrom          = \core_user::get_support_user();
            $eventdata->userto            = $user;
            $eventdata->subject           = $subject;
            $eventdata->fullmessage       = $message;
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml   = format_text($message, FORMAT_PLAIN);
            $eventdata->smallmessage      = '';
            $eventdata->notification      = 1;

            // All sends go through the gatekeeper (kill switch + test allowlist).
            notifier::send($eventdata, 'manual bulk reminder');

            // NOTE: SMS is NOT implemented. This plugin has never sent an SMS.
            // If an SMS gateway is added, it must also be routed through
            // \block_trainingplan\local\notifier so the kill switch applies.
        }
    }
}
