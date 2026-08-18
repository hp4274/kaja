# Workspace Rules & AI Agent Instructions

## Session Startup Checklist
At the beginning of **EVERY** chat session or task in this repository, you MUST perform the following actions:
1. **Read `.gemini/memory.md`**: Load current project context, technical state, database schema status, and recent session notes.
2. **Read `.gemini/content.md`**: Load page inventories, form structures, content copy guidelines, and brand voice.
3. **Read `.gemini/theme.md`**: Load design tokens (`--soft-*`), color palettes, typography specs, and component layout rules.
4. **Read `.gemini/skills.md`**: Load Master UI/UX Guidelines, section rhythms, animation rules, and UX checklists.

---

## Memory-First Research Rule
When working on ANY task in this repository, you MUST follow this lookup priority before reading source files:

1. **Check `.gemini/memory.md` FIRST** — Contains project architecture, database schema, session history, and current state. This file answers most "what exists?" and "how does it work?" questions without opening source code.
2. **Check `.gemini/context.md` NEXT** — Contains directory inventory, file roles, database table definitions, and tech stack details. Consult this when you need to know which files exist and what they do.
3. **Check `.gemini/ui.md` NEXT** — Contains detailed UI/component specifications for both public pages and admin pages. Consult this when you need design tokens, layout rules, or component structure.
4. **Only THEN open source files** — If memory, context, and UI docs don't contain the specific information you need (e.g., exact line-level code, a specific CSS selector value, or runtime logic), then read the actual source file.

> **Rationale**: The `.gemini/` memory files are curated, compact references that give you full context in a fraction of the tokens. Reading source files first wastes context window and risks missing established patterns. Always start from memory.

---

## Session Completion Checklist
At the end of **EVERY** chat session or major feature implementation in this repository, you MUST perform the following actions:
1. **Update `.gemini/memory.md`**:
   - Add a new session log entry with date, summary of changes made, and files modified.
   - Update the current project status and active tasks list.
2. **Update `.gemini/content.md`** (if applicable):
   - Add or update page inventories or form field definitions if any HTML/PHP/JS content changed.
3. **Update `.gemini/theme.md` & `.gemini/skills.md`** (if applicable):
   - Record any new CSS variables, theme classes, or UI/UX animation patterns created during the session.


---

## Scheduled jobs

`cron/intake-reminders.php` — hourly. Sends one reminder per lead whose intake
link has gone unsubmitted past the `admin_reminder_hours` setting, then sets
`leads.reminder_sent` so it never fires again for that lead. The flag is set
whether or not the mail went out: a mail server down for an hour must not turn
into a lead being chased every hour once it comes back.

Requires `site_base_url` to be set in Settings. A CLI process has no
`HTTP_HOST` to derive a link from, so leaving it blank produces broken URLs.

Windows Task Scheduler:

    schtasks /create /tn "Kaja intake reminders" /sc hourly ^
      /tr "C:\xampp\php\php.exe C:\xampp\htdocs\Kaja\cron\intake-reminders.php"

## Tests

`php tests/run.php` runs everything; `php tests/run.php lead_repo` runs one
file. Tests build a throwaway `kaja_db_test` from `schema.sql` — they never
touch `kaja_db`.
