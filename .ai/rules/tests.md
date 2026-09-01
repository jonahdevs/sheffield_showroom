---
paths:
  - 'tests/**'
---

# Tests

## Comment sparingly
Pest test names already say what is under test, so a comment only earns its place when it prevents a real mistake: why a test is built an unusual way, a fixture invariant an editor would break, or why an assertion checks what it checks rather than the obvious neighbour. Do not narrate the arrange/act/assert or restate the `it(...)` description.

Never strip PHPDoc that carries types on a test helper (`@param array<int, Permission>`, `@return`, array shapes) - Larastan reads them.

Use `#` for inline comments - the marker chosen for PHP across this project. This only survives because `pint.json` disables `single_line_comment_style`; the bare laravel preset configures that fixer as `comment_types: ['hash']` and rewrites every `#` to `//`. Do not remove that rule from `pint.json` - one `vendor/bin/pint` run would flip the whole codebase back, and the reformat is silent.

Section banners follow one shape, 73 characters wide:

# =========================================================================
# Who may reach what
# =========================================================================

with the `-----` form for a description area inside a section.

## Each test file declares its own permission helper
`rewardsStaff`, `pairingStaff`, `catalogueStaff`, `purchaseProductStaff`, `visitStaff`, `syncStaff` and friends are near-identical by design. Pest helpers declared in a test file are global functions and Pest gives no guarantee which file declares one first, so two files declaring the same name is a fatal error rather than a failing test.

Do not "DRY" them into one shared helper in a single test file. If they genuinely need sharing, move it to `tests/Pest.php` or a trait - never leave two test files declaring the same global function name.
