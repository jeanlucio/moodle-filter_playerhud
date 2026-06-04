# Changes

## v1.5.0 (2026060400)

- Add: filter widget avatar now displays the equipped item image (or emoji) instead
  of the Moodle user profile picture.
- Add: +N overflow badge in the widget stash now opens a popover listing the hidden items.

## v1.4.1 (2026051901)

- Fix: `aria-label` misuse warnings in widget.mustache — added `role="img"` to
  the inline karma `<span>` (label differs from visible text); removed redundant
  `aria-label` from the karma `<div>` in the modal body (visible text is identical).

## v1.4.0 (2026051501)

- Add: declare supported Moodle versions [405, 502] in version.php.

## v1.3.5 (2026051301)

- Add: widget stash limited to 5 unique items; +N overflow badge appears when
  inventory exceeds the limit, consistent with the block sidebar.
- Add: Behat acceptance test suite covering collect redirect, modal from widget
  stash, HTML tag leakage, string placeholders and DOM duplication.
- Fix: char modal now uses `theme_boost/bootstrap/modal` with `getInstance`
  fallback chain for BS4/BS5 cross-version compatibility; modal hoisted to body
  before `show()`; description rendered with triple braces to preserve HTML.
- Fix: Bootstrap 4/5 compatibility — calendar icon changed to `fa-calendar`;
  gap fallback for badge row; `ph-help-trigger` spacing via CSS.
- Fix: `sr-only` removed from templates; `.visually-hidden` CSS fallback added
  for Moodle 4.5 (Bootstrap 4 does not define this utility).
- Update: plugin icon.

## v1.3.4 (2026050601)

- Fix: item modal no longer fails to open in forum posts — modal HTML is now
  appended to `$text` so it is present in the DOM when `filter_collect.js`
  runs, regardless of rendering context (forum nested v2, etc.).
- Fix: `js_call_amd` argument exceeded Moodle 4.5's 1024-character limit;
  strings are now registered via `strings_for_js` and read in JS via
  `M.util.get_string()`, and the modal is no longer passed as an AMD argument.
- Fix: filter DB query count reduced from 7 to 5 — player record is shared
  from `text_filter` to `render` via `populate_player_cache()` (eliminates a
  duplicate query), and the RPG class lookup is skipped for items with no
  class restriction (`required_class_id = '0'`).

## v1.3.3 (2026050500)

- Fix: custom text in secret item drops is now shown correctly instead of "Mystery Item"

## v1.3.2 (2026042800)

- Add dynamic CI and release badges to README
- Show player group widget in filter widget (soft dependency on mod_playergroup)

## v1.3.1 (2026042300)

- Initial stable release
