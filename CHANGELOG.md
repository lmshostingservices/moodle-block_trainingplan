# Changelog

All notable changes to block_trainingplan.

## 1.5.4 — 2026-07-21

### Fixed

- Submitted work now moves a unit to IP. Because enrolment suspension is disabled
  (v1.4.7), students can work ahead of / behind their calendar window. A unit whose
  window had passed, or that was never opened, stayed NYS even after the student
  submitted. The assessment_submitted observer now promotes the submitted unit's own
  NYS row to IP (respecting manualoverride). Opening the next unit is unchanged.

## 1.4.3 — 2026-07-13

### Fixed

- Removed real user IDs that had been left in the "Test recipients" setting
  description as an example. Help text now uses generic placeholders.

## 1.4.2 — 2026-07-13

### Fixed (important)

- **The historical cutoff was never set on a fresh install.** Moodle runs
  `db/upgrade.php` only when upgrading an existing plugin, never on a first
  install. The cutoff was written there and nowhere else, so a fresh install
  ended up with an empty cutoff — meaning *no* cutoff, and the first weekly
  digest would have included a site's entire pre-existing backlog of overdue
  plans. On a site with a long-standing backlog that is hundreds of stale
  learners landing on a handful of trainers in one email.

  A `db/install.php` now sets the cutoff on fresh installs, and an upgrade step
  repairs any site already left in this state.

### Added

- Runtime warning: if the digest task ever runs with no cutoff configured, it
  now says so loudly in the cron log before doing anything.
- A `cutoffinitialised` flag, so an administrator who *deliberately* clears the
  cutoff (meaning "chase the whole backlog") is never silently overridden.

### Verified

Installed and run on Moodle 5.0.1. Task registers, cutoff resolves in the server
timezone, query executes, no debugging output.

## 1.4.1 — 2026-07-13

### Security

- Restored the capability check on the `save_user_plan` AJAX endpoint. It had
  been commented out. Because the endpoint takes `userid` as a parameter rather
  than using the logged-in user, any authenticated user with a valid sesskey
  could modify another user's training plan.

### Changed

- Removed development debug logging: 32 `error_log()` statements across the
  event observers, AJAX handlers and profile callback — one of which fired on
  every profile page view of every user — and `console.log` output from the
  admin and edit JavaScript (both source and built bundles).
- Added GNU GPL v3 licence headers to all PHP files.
- Added README, CHANGELOG and LICENSE for distribution.

## 1.4.0 — 2026-07-13

### Added

- **Weekly overdue digest to trainers.** One email per trainer listing the
  learners on their courses whose plan has fallen behind, each shown against the
  course they are actually stuck on. Learners are not emailed.
- Master notification switch (`Enable notifications`), on by default. Untick to
  stop every message the plugin sends, immediately.
- Test recipient allowlist — restrict sending to named user IDs while testing on
  a live site.
- Historical cutoff — excludes a pre-existing backlog. Set to the install date.
- Recipient exclusion list — user IDs that never receive a digest.
- Capability `block/trainingplan:receiveoverduedigest` (course context; granted
  by default to teachers, editing teachers and managers).

### Fixed

- **Added the `lastrisknotif` column.** It was read and written by the at-risk
  notification cooldown but had never been defined, and there was no upgrade
  path. Moodle's `update_record()` silently discards writes to unknown columns,
  so the cooldown never persisted. With the status task running every minute, an
  eligible plan could have been notified once per minute, indefinitely.
- **Registered the `reminder` message provider.** `reminder::send_bulk()` was
  sending messages under a provider name that was never registered, so the admin
  "Send reminders" action could not deliver.
- Declared all message processors (`email`, `popup`, `sms`, `airnotifier`)
  explicitly on every provider. Moodle 4.5+ ships a core SMS subsystem; an
  undeclared processor falls back to a Moodle default that is not clearly
  specified and has changed across releases. SMS and push are now permitted but
  off by default, so nobody receives an unexpected text message.
- Added the missing `cron_update_trainingplan_status` language string. The
  scheduled task had been displaying as `[[cron_update_trainingplan_status]]`.

### Unchanged

No change to the status engine. Existing `pending` / `expired` / `active` rows
behave exactly as before, and no row changes on upgrade.

## 1.0.0

Initial release.
