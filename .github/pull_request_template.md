## What

Brief summary of what this PR does.

## Why

Link to related issue(s) or explain the motivation.

Fixes #
Closes #
Relates to #

## How

Key changes and approach:

-
-
-

## Screenshots / Demo

<!-- If this affects UI, include before/after screenshots or a GIF -->
<!-- For accessibility changes, note keyboard flow or screen reader behavior -->

## Testing

How was this tested?

- [ ] Manual testing on local environment
- [ ] Unit tests added/updated
- [ ] E2E tests added/updated (if applicable)
- [ ] Tested with keyboard navigation
- [ ] Tested with screen reader (if UI change)

## Pre-merge Checklist

**Code Quality**
- [ ] Code follows WordPress Coding Standards (PHPCS/WPCS)
- [ ] JavaScript/CSS follows project linting rules
- [ ] No debug code left (`var_dump`, `console.log`, etc.)

**Security & Performance**
- [ ] Input is validated and sanitized
- [ ] Output is escaped appropriately
- [ ] Nonces and capability checks are in place for actions
- [ ] No expensive queries in loops or templates
- [ ] Enqueued assets are scoped appropriately

**Accessibility & i18n**
- [ ] All strings are translatable with correct text domain (`postsecret`)
- [ ] Keyboard navigation works correctly
- [ ] Focus states are visible
- [ ] ARIA roles/labels added where needed
- [ ] Meets WCAG 2.2 AA standards (if UI change)

**Documentation**
- [ ] README updated (if needed)
- [ ] Inline comments added for complex logic
- [ ] CONTRIBUTING.md updated (if workflow changed)
- [ ] Migration notes included (if schema/data changes)

**CI & Tests**
- [ ] All CI checks pass
- [ ] No new warnings or errors introduced

## Risk Assessment

<!-- Low / Medium / High -->
**Risk level**:

<!-- Explain any risks, breaking changes, or deployment considerations -->
<!-- Example: "Medium - requires running migrations after deploy" -->

## Post-merge Actions

<!-- Any manual steps needed after merge? -->
<!-- Example: "Run `wp postsecret migrate` on production" -->

- [ ] None
- [ ] Requires migration:
- [ ] Requires settings update:
- [ ] Other:

---

<!-- Thank you for contributing! -->
