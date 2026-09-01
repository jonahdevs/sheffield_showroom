---
paths:
  - 'routes/**'
---

# Routes

## `permission:a|b` means "any of these", never "both"
Spatie's `permission:` middleware reads a pipe as OR. A route needing two capabilities must list two middleware — `->middleware(['permission:products.create', 'permission:products.update'])` — not one piped string. Written as a pipe, `products.sync` let somebody holding only one of the two past a gate the controller then closed.

Where the conjunction cannot be expressed on the route (`customers.import` needs create and update), name only the entry permission here and let the policy hold the rest — `CustomerPolicy` does.

Also in this file: register a literal segment before the `{wildcard}` that would otherwise swallow it. `rewards/overview`, `rewards/redeem`, `rewards/winners` and `rewards/catalogue` all sit above the `{campaign}` group for that reason.
