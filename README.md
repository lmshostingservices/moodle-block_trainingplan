<p align="center">
  <a href="https://lmshostingservices.com">
    <img src="https://raw.githubusercontent.com/lmshostingservices/lms-labs/main/attached_assets/lms-hosting-logo.png" alt="LMS Hosting Services" height="60">
  </a>
</p>

> **LMS Labs** is the Moodle plugin division of [LMS Hosting Services](https://lmshostingservices.com) — Australia's Moodle™ Certified Partner.

---

# Training Plan block

Sequential training-plan management for Moodle, with a weekly overdue-digest
notification to trainers.

## Requirements

- Moodle 4.4 or later
- MySQL / MariaDB / PostgreSQL

## Installation

1. Copy this directory to `blocks/trainingplan` in your Moodle install
   (or upload the ZIP via Site administration > Plugins > Install plugins).
2. Visit Site administration > Notifications and complete the upgrade.
3. Review the settings at
   Site administration > Plugins > Blocks > Training Plan.

The upgrade adds one column and sets a notification cutoff to the install date,
so any pre-existing backlog of overdue plans is not emailed out on first run.

## The overdue digest

Once a week, each trainer receives one email listing the learners on their
courses whose plan has fallen behind, shown against the course each learner is
stuck on. Learners are not emailed; trainers are.

Notifications are controlled by a master switch (on by default) and can be
tested against a restricted recipient list before going live. See the full
documentation for details.

## Settings

| Setting | Purpose |
|---------|---------|
| Enable notifications | Master on/off switch for all plugin messages |
| Test recipients | Restrict sending to named user IDs while testing |
| Ignore plans overdue before | Exclude a pre-existing backlog (set to install date) |
| Never send digests to | User IDs that never receive a digest |

## Capabilities

- `block/trainingplan:manage` — manage plans, use the admin view
- `block/trainingplan:view` — view own plan
- `block/trainingplan:receiveoverduedigest` — receive the weekly digest
  (course context; granted by default to teachers, editing teachers, managers)

## Extending

All outbound messages pass through
`\block_trainingplan\local\notifier::send()`. Any new channel must be routed
through it so the master switch and test-recipient controls continue to apply.

## Pricing

**$50 USD** — one-time purchase per site · lifetime updates · no subscription.

Download at [lms-labs.com/plugins](https://lms-labs.com/plugins).


## ⭐ Why this plugin is unlike anything else available

**Sequential training schedule with overdue digest emails to managers**

- Moodle completion shows who has finished. Training Plan shows who should have finished and hasn't — sorted by days overdue. Managers receive a weekly digest listing every overdue learner with the specific course they're behind on and how many days past the scheduled date.
- Course order and schedule dates are site-configurable. The same block shows each learner their personalised next step based on their cohort membership and completion state — one block, many pathways.
- Integrates with Moodle's existing cohort system for pathway assignment. No separate enrolment or HR system needed to define who is in which training program.

## Support

- **Portal:** [lms-labs.com](https://lms-labs.com)
- **Email:** support@lmshostingservices.com
- **Website:** [lmshostingservices.com](https://lmshostingservices.com)

LMS Labs is the plugin division of LMS Hosting Services, Australia's Moodle™ Certified Partner.

## Licence

GNU GPL v3 or later. See LICENSE.
