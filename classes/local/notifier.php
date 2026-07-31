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

namespace block_trainingplan\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Central gatekeeper for every outbound message sent by block_trainingplan.
 *
 * ALL calls to message_send() in this plugin must go through notifier::send().
 * Do not call message_send() directly anywhere else.
 *
 * Two independent safety controls, both fail-closed:
 *
 *   1. block_trainingplan/notificationsenabled
 *      Master kill switch. Default OFF. While off, nothing is sent at all.
 *
 *   2. block_trainingplan/testrecipients
 *      Comma-separated user ids. If non-empty, ONLY those users can receive a
 *      message; every other recipient is logged and skipped. This lets you test
 *      on a live site with zero blast radius.
 *
 * Suppressed sends are always logged, so the cron output tells you exactly who
 * WOULD have been notified without anything leaving the building.
 */
class notifier {

    /**
     * Is the master kill switch on?
     */
    public static function is_enabled(): bool {
        return (bool)get_config('block_trainingplan', 'notificationsenabled');
    }

    /**
     * Parse the test recipient allowlist into user ids.
     *
     * Empty array means "no allowlist configured" (i.e. send to everyone,
     * assuming the master switch is on).
     *
     * @return int[]
     */
    public static function get_test_recipients(): array {
        $raw = (string)get_config('block_trainingplan', 'testrecipients');

        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        $ids = array_filter(array_map('intval', $parts));

        return array_values(array_unique($ids));
    }

    /**
     * Send a message, subject to the safety controls above.
     *
     * @param \core\message\message $event   Fully populated message object.
     * @param string                $reason  Short label for the log line.
     * @return bool  True if the message was actually handed to message_send().
     */
    public static function send(\core\message\message $event, string $reason = ''): bool {

        $userid = 0;
        if (!empty($event->userto)) {
            $userid = is_object($event->userto) ? (int)$event->userto->id : (int)$event->userto;
        }

        $name = $event->name ?? 'unknown';
        $label = "[trainingplan] {$name} -> user {$userid}" . ($reason !== '' ? " ({$reason})" : '');

        // Control 1: master kill switch.
        if (!self::is_enabled()) {
            self::log("{$label}: SUPPRESSED - notifications disabled in plugin settings");
            return false;
        }

        // Control 2: test recipient allowlist.
        $allow = self::get_test_recipients();
        if (!empty($allow) && !in_array($userid, $allow, true)) {
            self::log("{$label}: SUPPRESSED - not in test recipient allowlist");
            return false;
        }

        $mode = !empty($allow) ? 'SENDING (test recipient)' : 'SENDING';
        self::log("{$label}: {$mode}");

        // message_send() returns the message id (int) on success, false on failure.
        // Propagate the real outcome so callers can decide whether the cooldown stamp
        // should be consumed. A silent false from message_send() (e.g. provider
        // disabled in Moodle's notification preferences) previously looked like a
        // success to the caller — burning the cooldown for a message never delivered.
        $msgid = message_send($event);

        if ($msgid === false) {
            self::log("{$label}: message_send() returned false — message was suppressed by Moodle "
                . "(check Site admin → General → Messaging → Notification settings for disabled "
                . "block_trainingplan providers)");
            return false;
        }

        return true;
    }

    /**
     * Log safely.
     *
     * mtrace() echoes directly to output, which would corrupt the JSON returned
     * by ajax.php. Only use it when we are genuinely in a CLI/cron context.
     */
    private static function log(string $message): void {
        if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
            mtrace($message);
        } else {
            // Outside cron (e.g. the admin "send reminders" action), mtrace would
            // corrupt the JSON response. Route to Moodle's debugging channel, which
            // only surfaces when developer debugging is on - never to the raw PHP
            // error log in production.
            debugging($message, DEBUG_DEVELOPER);
        }
    }
}
