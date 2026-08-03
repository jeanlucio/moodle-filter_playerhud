# 🧪 Automated Tests

The filter ships with unit/integration (PHPUnit) and browser acceptance (Behat) tests, executed
on every CI push against the full matrix (Moodle 4.5 → 5.2, PostgreSQL & MariaDB).

### PHPUnit — Unit & Integration Tests

| Test file | Cases | What is covered |
|-----------|------:|----------------|
| `filter_test.php` | 22 | Shortcode parsing into the collect button; zero N+1 queries across 5 simultaneous drop shortcodes on one page; drop visibility when gamification is paused; the privacy null provider's reason string; shortcodes stripped for guests/site front page and for a course without a block instance; the assets modals fragment renders real HTML; a secret item renders as a mystery placeholder until collected; a null item description does not crash `base64_encode()`; limit-reached and cooldown drop states render disabled with no collect action; a valid trade code resolves and renders the trade card, an unknown code renders nothing (guards against ID enumeration); the widget shows an opt-in button when paused and the full HUD when active; the Moodle app redirects to the block's Backpack view instead of rendering AJAX triggers; an item description containing an XSS payload is sanitised with `format_text()` before being base64-encoded into `data-desc-b64`; a crafted `configdata` payload is deserialised with `unserialize_object()`, so an arbitrary class's `__wakeup()` never fires; the drop and trade Mustache templates escape non-image icon/emoji content (double-mustache); a user with `block/playerhud:view` explicitly prohibited never sees rendered shortcodes; a description containing the filter's own `[PLAYERHUD_DROP ...]` shortcode is not re-expanded, proving the reentrancy guard without triggering the real unbounded recursion it prevents |
| **Total** | **22** | |

```bash
vendor/bin/phpunit --testsuite filter_playerhud
```

**Line coverage by class** (PHPUnit + Xdebug):

| Class | Line coverage |
|-------|:-------------:|
| `output\assets` | 100% |
| `privacy\provider` | 100% |
| `output\render` | 85% |
| `text_filter` | 83% |
| `output\widget` | 73% |
| **Overall** | **80%** |

The lowest figure, `output\widget` (73%), reflects branches the current fixture never exercises
rather than untested logic: the minimal test player has no RPG class, no karma progress, and no
`mod_playergroup` group, so the portrait/karma-bar/class-description and group-badge branches of
`export_for_template()` are not reached. `output\render`'s uncovered lines are mostly the
RPG-class-restriction check (`is_item_visible_for_class()`'s branch inside `render_drop()`) and
the full affordability path of `render_trade()` with a real, non-empty inventory.

### Behat — Acceptance Tests

| Feature file | Scenarios | What is covered |
|--------------|----------:|----------------|
| `filter_playerhud_modals.feature` | 6 | Collecting a drop via the shortcode's AJAX action does not redirect the page; opening item details from the widget stash after collecting; the description modal does not render raw HTML tags as visible text; an XSS payload in an item description does not leak into the modal's actual HTML (`onerror`, `<script`) — the HTML-level regression for the finding fixed in `render_drop()`; the modal never shows raw `[[...]]` string placeholders; clicking the item trigger multiple times never duplicates the modal in the DOM |
| **Total** | **6** | |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@filter_playerhud --profile=chrome
```
