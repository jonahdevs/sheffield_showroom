---
paths:
  - 'app/Data/**'
---

# App Data

## Option data objects share a positional prefix with OptionData
`ProductOptionData` and `CustomerOptionData` must keep `value, label, hint, image_url` as their first four properties, in that order. The shared comboboxes (`OptionCombobox`, `OptionMultiCombobox`, `OptionOrNewCombobox`) accept all three shapes interchangeably on that prefix alone.

Reordering or inserting a property ahead of them breaks the pickers silently - nothing in PHP or TypeScript enforces the order. Add new fields after the prefix.
