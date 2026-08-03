# Changes

## [v1.6.4] — 2026-08-03

- Update: renamed an internal test fixture and softened wording in the security
  regression test suite; no functional change, same assertions and code paths covered.

## [v1.6.3] — 2026-08-03

- Update: the PlayerGames Ecosystem badge in the README and documentation now links to
  the ecosystem site (`jeanlucio.github.io/playergames`) instead of the old Plugins
  Directory contributor listing.

## [v1.6.2] — 2026-08-03

- Fix: `docs/` and `.github/` are now excluded from the release archive via
  `.gitattributes` (`export-ignore`), so the Plugin Directory zip no longer ships the
  GitHub Pages source and CI workflow files.

## [v1.6.1] — 2026-08-03

- Fix: item descriptions are now sanitised with `format_text()` before reaching the
  client, closing a stored XSS where a teacher-authored `<img onerror=...>` in a drop
  or trade description could execute in the session of any student opening its modal.
- Fix: the filter now enforces `block/playerhud:view` before rendering the HUD, drops
  and trades from shortcodes, matching the capability check already applied to the
  block's own actions (collecting, trading).
- Fix: descriptions cleaned with `format_text()` no longer re-expand shortcodes, which
  previously caused unbounded recursion (and a fatal request) when a description
  contained the same `[PLAYERHUD_DROP ...]` shortcode as its own item.
- Fix: `render_drop()` no longer crashes on a `null` item description.
- Fix: the block's `configdata` is now deserialised with `unserialize_object()` instead
  of a bare `unserialize()`.
- Add: PHPUnit and Behat coverage for the fixes above (22 PHPUnit cases, 6 Behat
  scenarios in total).
- Update: full documentation (features, shortcode syntax, security details, test
  breakdown) moved to a GitHub Pages site; README is now a short entry point linking
  to it.

## [v1.6.0] — 2026-06-24

- Add: collecting an item through the in-text widget now triggers the milestone
  celebration popups (level-up, the first PlayerCoin and beating the game) with the
  Huddy mascot, matching the block. Mascot art is served as WebP.

## [v1.5.0] — 2026-06-04

- Add: filter widget avatar now displays the equipped item image (or emoji) instead
  of the Moodle user profile picture.
- Add: +N overflow badge in the widget stash now opens a popover listing the hidden items.

## [v1.4.1] — 2026-05-19

- Fix: `aria-label` misuse warnings in widget.mustache — added `role="img"` to
  the inline karma `<span>` (label differs from visible text); removed redundant
  `aria-label` from the karma `<div>` in the modal body (visible text is identical).

## [v1.4.0] — 2026-05-15

- Add: declare supported Moodle versions [405, 502] in version.php.

## [v1.3.5] — 2026-05-13

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

## [v1.3.4] — 2026-05-06

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

## [v1.3.3] — 2026-05-05

- Fix: custom text in secret item drops is now shown correctly instead of "Mystery Item"

## [v1.3.2] — 2026-04-28

- Add dynamic CI and release badges to README
- Show player group widget in filter widget (soft dependency on mod_playergroup)

## [v1.3.1] — 2026-04-23

- Initial stable release
