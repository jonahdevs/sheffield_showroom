---
paths:
  - '**/*.php'
---

# General

## PHP comments use #, which requires the pint.json override
Inline PHP comments use `#`, not `//`. Section banners are `# =====` for a main section and `# -----` for a description area within one, 73 characters wide. Vue/TS use the same shapes with `//` and `<!-- -->`, since `#` is a syntax error there.

This survives formatting only because `pint.json` sets `"single_line_comment_style": false`. The bare laravel preset configures that fixer as `comment_types: ['hash']` and rewrites every `#` to `//`. Removing that rule means one `vendor/bin/pint` run silently reformats the whole codebase back, and the fixer converts one way only - Pint will not undo it for you.

Comment sparingly. A comment earns its place when it prevents a real mistake: locking and transaction ordering, "do not do the obvious thing because X", security boundaries, and non-obvious invariants. Do not explain ordinary decisions or restate the code. Never strip PHPDoc carrying type information (`@param array<int, int>`, `@return`, `@property`, `@var`, `@use HasFactory<...>`) - Larastan reads it.
