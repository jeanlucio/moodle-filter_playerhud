# 🔐 Security & Compliance

* **Capability-based access control:** every shortcode re-checks `block/playerhud:view` at
  render time — a user who is denied that capability sees the shortcode stripped out entirely,
  the same as a guest or a paused player, even though the deterministic checks (login, course,
  gamification status) all pass.
* **Server-side enforcement:** recharge time (cooldown) and collection limits are always
  validated server-side; the shortcode's visual state (ready/cooldown/collected) is a display
  concern only, never the actual gate.
* **`require_sesskey()` protection:** the collect and trade-processing endpoints the shortcodes
  link to (`collect.php`, `process_trade.php`, both owned by the PlayerHUD Block) require a
  valid session key on every request.
* **Safe deserialization:** the block's stored `configdata` is read with `unserialize_object()`,
  restricting the payload to `stdClass` — a crafted configuration can never trigger arbitrary
  object instantiation or a POP-gadget chain the way a bare `unserialize()` could.
* **XSS-hardened rendering:** item and RPG-class descriptions are always sanitised with
  `format_text()` before being handed to the client, and every icon/emoji fallback in the
  Mustache templates uses double-mustache (escaped) output — a description was previously
  shipped raw in one rendering path; this has been fixed and is covered by a dedicated
  regression test.
* **Shortcode reentrancy guard:** an item or RPG-class description containing the filter's own
  `[PLAYERHUD_DROP ...]`/`[PLAYERHUD_WIDGET]` shortcode is rendered with `format_text(...,
  ['filter' => false])`, preventing it from re-entering `text_filter::filter()` and recursing
  until the request exhausts its memory limit — Moodle's own filter chain has no reentrancy
  guard of its own.
* **Zero N+1 by construction:** bulk pre-loading (not per-shortcode queries) means a page with
  many drops cannot be turned into a denial-of-service vector through query amplification.
* **Moodle External API compliant:** the collect flow it links to is exposed as a proper
  external function with its own parameter/return validation and capability gate.
* **Privacy-aware:** see [Privacy Provider](#privacy-provider) below — this plugin stores no
  data of its own.
* **Mobile-compatible:** shortcodes degrade safely inside the Moodle app instead of attempting
  an AJAX flow the app cannot support.

## Privacy Provider

The PlayerHUD Filter implements Moodle's `null_provider` — it only **displays** data owned and
stored by the PlayerHUD Block (items, drops, trades, inventory); it never persists any personal
data of its own. See the block's own documentation for its full GDPR export/delete coverage.
