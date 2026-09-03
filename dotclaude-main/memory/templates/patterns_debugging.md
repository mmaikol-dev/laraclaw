---
name: patterns_debugging
description: Cross-project debugging playbook -- known issues and fix patterns
type: project
---

## Known Patterns

{Add entries as you encounter and fix bugs. Format:}

### Category Name
- **Symptom:** {What you observe}
- **Root cause:** {Why it happens}
- **Fix:** {What to do}
- **Prevention:** {How to avoid in future}

## Example Entry

### Database
- **Symptom:** RLS policy returns empty results even for authenticated users
- **Root cause:** Using `auth.uid()` directly instead of `(SELECT auth.uid())`
- **Fix:** Wrap in subquery: `(SELECT auth.uid())`
- **Prevention:** Always use the subquery form in RLS policies

**How to apply:** Before debugging any issue, scan this file for matching symptoms. After fixing a non-trivial bug, add the pattern here.
