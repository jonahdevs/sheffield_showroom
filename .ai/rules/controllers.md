---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## A partial select of products must include image_path
`Product::imageUrl()` builds the thumbnail from `image_path`, so any query that narrows the columns has to ask for it. The `:id,name` shorthand - in `->get(['id','name'])`, `->with('products:id,name')` or a relation string - silently returns every row with a blank image and no error anywhere.

Already load-bearing in three places: `DashboardController::topProducts`, `VisitController::formOptions` and `RewardController::index`. Add it to any new one.
