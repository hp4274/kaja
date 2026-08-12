# Lead → Intake → Client Spine — Design

**Date:** 2026-08-12
**Status:** Approved for planning
**Target tree:** `C:\xampp\htdocs\Kaja` (authoritative; `d:\Work\kaja` is diverged and out of scope)

## Context

The admin dashboard manages a solo therapy practice. A public contact form creates
leads; an intake questionnaire collects clinical detail; accepted people become
clients with sessions, notes and fees.

This document covers the first of six pieces carved out of a larger 27-section UX
brief. It designs the lifecycle spine only:

    Lead → Accept → Intake sent → Intake completed → Convert → Client

The remaining five pieces (client profile, dashboard home, sessions calendar,
bulk actions and holidays, cross-cutting polish) each get their own design.

### Decisions taken during brainstorming

| Question | Decision | Consequence |
|---|---|---|
| Does intake come before or after conversion? | **Intake gates conversion** | `clients` holds only vetted people with real intake data |
| Solo practice or team? | **Solo** | No assignment, staff filters, author bylines, or "changed by" |
| Volume? | **Under 10 leads/week, under 30 active clients** | No pagination, no filter bar, no bulk lead actions |
| Stage: stored or derived? | **Derived** | One source of truth; expiry correct without a reconciliation job |

The brief contained a contradiction on the first question. Its Product Context
said intake is "completed after a lead is converted into a client", while section
24 placed intake before conversion. The existing `convert` action implemented a
third variant: it created the client row immediately, seeding `first_name` and
`last_name` by splitting the lead's name string, then issued the intake token.
Intake-gates-conversion was chosen, so `convert` is rebuilt.

## Goals

1. The therapist can always see which leads need action from them, distinct from
   those waiting on the client.
2. Every lifecycle transition is one click from where the therapist already is.
3. Lead history survives conversion and deletion.
4. A client record is never created from guessed data.

## Non-goals

- Client profile, sessions, notes, fees (later pieces).
- Any multi-user concept: assignment, roles, per-user attribution.
- Address, contact preferences, service requested, additional information. The
  contact form does not collect these; rendering permanent blanks is worse than
  omitting them. Adding them means changing the public form first.

## Data model

Two nullable columns. No new tables, no new enum values.

```sql
ALTER TABLE `leads`
  ADD COLUMN `first_viewed_at` DATETIME NULL AFTER `status`,
  ADD COLUMN `deleted_at`      DATETIME NULL AFTER `first_viewed_at`;
```

`first_viewed_at` is stamped once, when the lead detail page is first opened. It
supplies the "Lead viewed" timeline event and derives the Reviewing stage. The
brief listed Reviewing as a status, which would normally mean a "Mark as
reviewing" button; opening the lead *is* the review, so no button exists.

`deleted_at` makes Delete a soft delete. Hard deletion would destroy the lead's
timeline and orphan its `intake_links` row. Soft-deleted leads are excluded from
every list and every identity lookup.

Existing tables used as-is: `leads`, `intake_links` (token, status, `opened_at`,
`submitted_at`, `expires_at`, `patient_intake_id`), `patient-intake`, `clients`,
`activity_log`.

### Stage derivation

New file `includes/lead-stage.php`, following the plain-function style of
`includes/intake-token.php`.

```php
function leadStage(array $lead, ?array $link, $now = null): array
```

Pure: no database access, no clock read beyond the injected `$now`. Returns
`['key', 'label', 'tone', 'next']` where `next` names the single most useful
action, or `null` when none is pending.

`$link` is **the most recently created `intake_links` row for the lead**, or
`null` if none exists. Resend leaves older expired rows behind, so callers must
order by `created_at DESC LIMIT 1`. Older rows are history, read only by the
timeline.

Precedence, first match wins:

| # | Condition | Stage | `next` |
|---|---|---|---|
| 1 | `deleted_at` set | *excluded from all lists* | — |
| 2 | `status` is `declined` or `spam` | Rejected | — |
| 3 | `status` is `converted` | Converted | — |
| 4 | link status `submitted` | Intake Completed | `review_and_convert` |
| 5 | link status `opened` | Intake In Progress | — |
| 6 | link status `sent`, `expires_at` in future | Intake Sent | — |
| 7 | link status `sent`, `expires_at` past | Intake Expired | `resend_intake` |
| 8 | `status` is `accepted`, no link row at all | Accepted | `send_intake` |
| 9 | `first_viewed_at` set | Reviewing | `accept_or_reject` |
| 10 | otherwise | New | `review` |

Rejected and Converted outrank intake state deliberately: a lead rejected after
its intake was sent reads Rejected, not Intake Sent.

Row 7 is the reason stage is derived rather than stored. Expiry is the passage of
time, not an event — no transition fires when a token lapses. A stored column
would keep reporting Intake Sent until a scheduled job corrected it. The derived
value is correct the moment it is read.

Because the list, the detail page, and later the dashboard all read `next` from
this one function, they cannot disagree about what to do next.

## Screens

### Leads list

The brief asked for separate Status and Intake status columns. `leadStage()`
merges them into one badge; they were never independent facts, only one position
in a pipeline.

Tabs replace the filter bar, framed by who is blocked:

```
[ Needs Action 4 ]  [ Waiting on Client 2 ]  [ All ]  [ Archive ]      [ search… ]
```

- **Needs Action** (default) — New, Reviewing, Accepted, Intake Completed,
  Intake Expired. Every stage whose `next` is non-null.
- **Waiting on Client** — Intake Sent, Intake In Progress. Real work in flight,
  but not the therapist's.
- **All** — everything not deleted.
- **Archive** — Converted and Rejected.

Search is a single box matching name, email, or phone. No pagination.

Columns: Lead (name over email), Submitted (relative time), Stage (badge),
primary action button, overflow menu. Destructive actions live only in the menu.

### Lead detail

`index.php?page=lead&id=` — a real page, deep-linkable and bookmarkable. Stamps
`first_viewed_at` on first open.

Sections:

- **Header** — name, stage badge, primary action.
- **Next step banner** — e.g. "Accepted 3 days ago — intake form not sent yet."
  with the action button. Hidden when `next` is null.
- **Contact** — email, country code, phone, submitted date.
- **Inquiry** — message, preference, requested date and time, source page.
- **Intake** — stage, sent/opened/submitted timestamps, expiry, resend action.
  Shows "Not sent" before a link exists.
- **Timeline** — reverse chronological, from `activity_log`.

## Transitions

Each writes exactly one `activity_log` row.

| Action | Allowed from | Guard |
|---|---|---|
| Accept | New, Reviewing | — |
| Reject | any stage except Converted and Rejected | Confirm, optional reason stored in log description |
| Delete | any stage | Confirm; sets `deleted_at` |
| Send Intake | Accepted | Blocked if an unexpired link exists |
| Resend Intake | Intake Sent, Intake Expired | Expires the old link, issues a new one |
| Convert | **Intake Completed only** | Hard-blocked from every other stage |

The Convert guard is where intake-gates-conversion is enforced.

### Convert, rebuilt

Current behaviour matches on `email AND phone` against `patient-intake`, then
creates the client with names split off the lead string.

New behaviour: reach the intake row through `intake_links.patient_intake_id` — a
foreign key established when the client submitted the form. Every column on
`clients` is seeded from genuinely submitted values. `explode(' ', $name, 2)`
is removed, so a lead named "Mary Anne Smith" no longer becomes first name
"Mary", last name "Anne Smith".

Within one transaction: insert `clients`, set `leads.status = 'converted'`, set
`leads.client_id`, set `intake_links.client_id`, log the event. On success the
UI confirms and offers "View Client Profile".

## Defect fixes

These are existing defects, fixed as part of this work.

**Lead identity.** `WHERE email = :email AND phone = :phone` fails to match when
a phone number is typed with different spacing or omitted, and the surrounding
code then creates a second lead. Replaced with:

```sql
WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) AND deleted_at IS NULL
```

Phone becomes corroborating data displayed to the therapist, not a join key.
Email is the only field the contact form validates, so it is the only field
trustworthy enough to establish identity. At this volume no generated column or
index is warranted.

**Lead manufacturing.** `update_intake_status` creates leads from intake
submissions, naming them "Valued Client" when no match is found. Under
intake-gates-conversion every intake submission arrives bearing a token, and that
token carries `lead_id`. Resolution becomes `intake_links.token → lead_id`. An
unresolvable token is an error to surface, not a cue to invent a record. The
lead-creation branch is deleted.

**Timeline gaps.** `activity_log` records only status changes. All seven events
from the brief are logged at the point each transition happens, including the two
that fire from the public side — `markIntakeLinkOpened()` and intake submission —
which is what makes "Intake form opened" observable without polling.

`activity_log` gains no actor column; the practice is solo.

## Error handling

**Mail failure on Send Intake.** `intake-token.php` already commits the token
before attempting mail, so an SMTP failure cannot void a valid link. The UI must
complete that thought rather than failing silently:

> ⚠ Intake link created, but the email didn't send.
> `[ Copy link ]` `[ Retry email ]`

Copy-link is the more important of the two — the link can be pasted into
WhatsApp and the work continues. Silent failure is the worst outcome: the
therapist would believe the form was sent and wait.

**Double submit.** Send Intake re-checks for an unexpired link inside the
transaction, so a double-click cannot mint two tokens.

**Expired link opened by a client.** The client sees a plain "this link has
expired, please contact us" page. The lead surfaces as Intake Expired under Needs
Action, so the therapist learns it from their own dashboard rather than from a
confused client.

**Convert failure.** The whole conversion runs in one transaction; a failure
rolls back completely, leaving the lead at Intake Completed and safe to retry.

## Testing

`leadStage()` is pure, making it the highest-value test target — every screen
reads stage from it, so proving it correct proves they agree.

1. **Stage table test** — all ten precedence rows, plus boundaries: `expires_at`
   exactly equal to `$now`; submitted and also expired; rejected after intake
   sent; converted with no link.
2. **Guard tests** — Convert rejected from each of the eight stages other than
   Intake Completed; Send Intake rejected when an unexpired link exists.
3. **Identity test** — `Priya@x.com `, `priya@x.com`, and `PRIYA@X.COM` resolve
   to one lead; a soft-deleted lead never matches.
4. **Lifecycle integration** — over HTTP: new lead → accept → send intake → open
   link → submit intake → convert, asserting database state after each step.

Integration tests run against a throwaway database, never `kaja_db`.

## Migration and rollout

Two nullable columns; safe, reversible, no backfill. Existing leads read as New
until first opened, which is accurate — they have not been viewed in this UI.

The migration is added to the existing `migrate.php`, matching its guarded,
idempotent style so re-running is safe.

## Open items for later pieces

- `clients.status` already carries `pending`; the client profile piece decides
  whether a freshly converted client starts `pending` or `active`.
- `schema.sql` does not describe the post-`migrate.php` schema. Reconciling them
  is worth doing, but belongs with the tree-divergence cleanup, not here.
