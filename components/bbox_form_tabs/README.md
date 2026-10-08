# Bounding-box form tabs

The `bbox_form_tabs` component presents the bounding-box filter's map and
coordinate inputs in accessible tabs. Its map viewport has a 300px minimum
width on larger layouts and is capped to the viewport width on small screens.

The spatial predicate select is excluded from Better Exposed Filters'
unconditional autosubmit. A METSIS behavior submits an operator change only
when all four coordinate inputs contain numeric values; map drawing retains
its separate submit behavior.
