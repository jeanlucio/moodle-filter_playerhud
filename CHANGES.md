# Changes

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
