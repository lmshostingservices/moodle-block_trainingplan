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

$string['pluginname'] = 'Training Plan';
$string['blockname'] = 'Training Plan';

$string['signaccept'] = 'Sign & Accept Training Plan';
$string['signdesc'] = 'By clicking Confirm, you acknowledge and accept your Training Plan schedule.';
$string['signedon'] = 'Training plan signed on';
$string['notyet'] = 'Not yet started';
$string['active'] = 'Active';

$string['unitname'] = 'Unit name';
$string['type'] = 'Type';
$string['core'] = 'Core';
$string['elective'] = 'Elective';
$string['startdate'] = 'Start date';
$string['durationdays'] = 'Duration (days)';
$string['fee'] = 'Fee';
$string['duedate'] = 'Due date';
$string['progress'] = 'Progress';
$string['completed'] = 'Completed';
$string['completiondate'] = 'Completion date';
$string['status'] = 'Status';
$string['outcomelegend'] = 'Outcome legend';
$string['outcome_c'] = 'C = Competency Achieved';
$string['outcome_ct'] = 'CT = Credit Transfer';
$string['outcome_rpl'] = 'RPL = Recognition of Prior Learning';
$string['outcome_ip'] = 'IP = In Progress';
$string['outcome_nys'] = 'NYS = Not Yet Started';
$string['yes'] = 'Yes';
$string['no'] = 'No';
$string['noplans'] = 'No training plans found.';

$string['cohort'] = 'Cohort';
$string['allcohorts'] = 'All cohorts';
$string['exportcsv'] = 'Export CSV';
$string['signature'] = 'Signature';
$string['marksheet'] = 'marksheet';
$string['signed'] = 'Signed';
$string['pending'] = 'Pending';
$string['notapplicable'] = 'Not applicable';

$string['admininlinetip'] = 'Tip: Use filters, then export CSV or send reminders via the actions menu.';
$string['remindersubject'] = 'Training Plan Reminder';

$string['task_activate_units'] = 'Activate scheduled training plan units';
$string['course'] = 'Course';
$string['student'] = 'Student';
$string['enddate'] = 'End date';

$string['defaultfee'] = 'Default fee';
$string['defaultfee_desc'] = 'Fee assigned automatically when new cohort sync enrolment is detected.';
$string['defaultduration'] = 'Default duration (days)';
$string['defaultduration_desc'] = 'Duration automatically assigned to new training plan units.';
$string['sendcompletionemails'] = 'Send course completion emails';
$string['sendcompletionemails_desc'] = 'When enabled, training plan completions marked by the scheduled task may send Moodle course completion emails. When disabled, the task suppresses those emails.';
$string['searchuser'] = 'Search user';


$string['trainingplan:view'] = 'View the training plan block';
$string['trainingplan:manage'] = 'Manage training plan settings';
$string['trainingplan:addinstance'] = 'Add a new Training Plan block';
$string['trainingplan:myaddinstance'] = 'Add a new Training Plan block to the Dashboard';

$string['trainingplan'] = 'Training Plan';
$string['trainingplansigned'] = 'Signed Training Plans';
$string['notsigned'] = 'Not yet signed';

// ---------------------------------------------------------------------------
// Notification safety controls.
// ---------------------------------------------------------------------------

$string['notificationheading'] = 'Notification safety controls';
$string['notificationheading_desc'] = 'These settings control whether this plugin is allowed to send any message at all. They fail closed: if notifications are disabled, nothing is sent, and every suppressed send is written to the cron log so you can see who would have been notified.';

$string['notificationsenabled'] = 'Enable notifications';
$string['notificationsenabled_desc'] = 'Master switch. Untick this to immediately stop the plugin sending any notification, email or message to anyone - suppressed sends are still written to the cron log, so you can see what would have gone out. This is the emergency stop.';

$string['testrecipients'] = 'Test recipients (user IDs)';
$string['testrecipients_desc'] = 'Comma-separated list of Moodle user IDs, for example <code>2, 145</code>. If this field is not empty, ONLY these users can receive a message; every other recipient is skipped and logged. Use this to test safely on a live site. Clear this field to send to all intended recipients.';

// Message provider names (shown in Preferences > Notification preferences).
$string['messageprovider:risknotification'] = 'Training plan at-risk alerts';
$string['messageprovider:reminder'] = 'Training plan reminders';

// Overdue digest.
$string['cron_send_overdue_digest'] = 'Send overdue training plan digest to trainers';
$string['messageprovider:overduedigest'] = 'Training plan overdue digest (trainers)';
$string['trainingplan:receiveoverduedigest'] = 'Receive the overdue training plan digest';

$string['overduecutoff'] = 'Ignore plans overdue before (date)';
$string['overduecutoff_desc'] = 'Plans whose blocking course fell due BEFORE this date are treated as pre-existing backlog and are never included in the digest. Format <code>YYYY-MM-DD</code>. This is set to the install date automatically, so any backlog that already existed when you installed the plugin is not chased. Anything that falls behind AFTER this date is chased, and stays in the digest until it is resolved. Clear this field to include the full backlog - on a mature site that can mean a large number of emails at once.';

$string['digestsubject'] = 'Training plans behind schedule: {$a} learner(s)';
$string['digestintro'] = '{$a} learner(s) on your courses have a training plan that has fallen behind schedule. Each is listed against the course they are currently stuck on.';
$string['digestmore'] = '...and {$a} more not shown. See the Training Plan admin view for the full list.';
$string['digestcol_learner'] = 'Learner';
$string['digestcol_blocker'] = 'Stuck on';
$string['digestcol_due'] = 'Was due';
$string['digestcol_days'] = 'Days behind';
$string['digestcol_behind'] = 'Courses behind';

// Pre-existing: used by update_trainingplan_status::get_name() but never defined,
// so the task showed as [[cron_update_trainingplan_status]] in Scheduled tasks.
$string['cron_update_trainingplan_status'] = 'Update training plan status';

$string['excludedrecipients'] = 'Never send digests to (user IDs)';
$string['excludedrecipients_desc'] = 'Comma-separated user IDs that will never receive the overdue digest, regardless of what role they hold. Useful for test accounts that have been given a teacher role, or for staff who should not be chased. Leave empty to send to every eligible trainer.';

$string['privacy:metadata'] = 'The block_trainingplan plugin does not store any personal data.';
$string['pdf_hide_na'] = 'Exclude N/A units from PDF export';
$string['pdf_hide_na_desc'] = 'When enabled, units with an N/A outcome are omitted from the exported training plan PDF. Off by default — omitting units from a signed compliance document should be a deliberate, approved choice.';
