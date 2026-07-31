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

/**
 * Message providers for block_trainingplan.
 *
 * EVERY enabled processor on the target site is declared explicitly, on purpose.
 *
 * Moodle 4.5/5.x ship a core SMS subsystem, so a site may well have the sms and
 * airnotifier (push) processors enabled. If a processor is simply omitted from a
 * provider's 'defaults' array, its behaviour falls back to Moodle's internal
 * default - which is not clearly specified and has changed across releases.
 *
 * Rather than depend on that, each processor is named:
 *
 *   MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED  -> on by default
 *   MESSAGE_PERMITTED                            -> allowed, but OFF by default;
 *                                                   the user may opt in
 *
 * So: email and popup are on. SMS and push are available but off. Nobody gets a
 * surprise text message the first time this runs, and an admin can still enable
 * SMS deliberately from Site administration > General > Messaging.
 */
$messageproviders = [

    // Sent by the cron task when a student is close to their end date.
    'risknotification' => [
        'defaults' => [
            'popup'       => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email'       => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'sms'         => MESSAGE_PERMITTED,
            'airnotifier' => MESSAGE_PERMITTED,
        ],
        'capability' => 'moodle/site:sendmessage'
    ],

    // Sent by \block_trainingplan\local\reminder::send_bulk(), triggered from the
    // admin view (ajax.php 'send_reminders').
    //
    // This provider was previously MISSING while reminder.php was already sending
    // with name = 'reminder', so the admin "send reminders" action could not
    // deliver. Registering it here makes that action work again.
    'reminder' => [
        'defaults' => [
            'popup'       => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email'       => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'sms'         => MESSAGE_PERMITTED,
            'airnotifier' => MESSAGE_PERMITTED,
        ],
        'capability' => 'moodle/site:sendmessage'
    ],

    // Weekly digest to trainers listing learners whose plan has fallen behind.
    // Sent by \block_trainingplan\task\send_overdue_digest.
    //
    // No 'capability' key, deliberately. Moodle evaluates a provider capability in
    // SYSTEM context when building the notification-preferences UI, but
    // block/trainingplan:receiveoverduedigest is CONTEXT_COURSE - a teacher does
    // not hold it site-wide, so naming it here would hide the provider from the
    // very people meant to receive it. Who actually receives the digest is decided
    // in send_overdue_digest, by checking that capability on the blocking COURSE.
    //
    // SMS is off by default here for an additional reason: this message is a table
    // of up to 50 learners. It is not something anyone wants as a text.
    'overduedigest' => [
        'defaults' => [
            'popup'       => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email'       => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'sms'         => MESSAGE_PERMITTED,
            'airnotifier' => MESSAGE_PERMITTED,
        ],
    ],
];
