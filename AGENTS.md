# Workspace Rules & AI Agent Instructions

## Session Startup Checklist
At the beginning of **EVERY** chat session or task in this repository, you MUST perform the following actions:
1. **Read `.gemini/memory.md`**: Load current project context, technical state, database schema status, and recent session notes.
2. **Read `.gemini/content.md`**: Load page inventories, form structures, content copy guidelines, and brand voice.
3. **Read `.gemini/theme.md`**: Load design tokens (`--soft-*`), color palettes, typography specs, and component layout rules.
4. **Read `.gemini/skills.md`**: Load Master UI/UX Guidelines, section rhythms, animation rules, and UX checklists.

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
