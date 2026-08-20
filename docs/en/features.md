# ✨ Features

* 📍 **Item Drops:** insert collectible item drops directly into course content — pages, labels,
  books, forums, and any other HTML-supported activity.
* 🔢 **Per-Collection Quantity Display:** a drop's card shows the actual number of units it
  grants per collection (e.g. `x2`), read through the block's item-quantity engine when
  present and falling back to single-unit display on an older installed block version.
* 🏪 **Trade Widgets:** embed a PlayerHUD NPC shop trade card inline, resolved by a short
  lookup code rather than a raw database ID.
* 🧩 **Shortcode-Based Integration:** three shortcodes (`[PLAYERHUD_WIDGET]`,
  `[PLAYERHUD_DROP ...]`, `[PLAYERHUD_TRADE ...]`) — see [Usage](#usage) for the full syntax.
* 🎮 **Compact HUD Widget:** the same player HUD shown in the block (avatar, XP, level, recent
  items, ranking, karma bar) embeddable anywhere a shortcode is accepted.
* ⚡ **Real-Time Interaction:** AJAX-based collection via Moodle's `core/ajax`, with no page
  redirect.
* 🎒 **Seamless Inventory Integration:** collected items post directly into the PlayerHUD
  inventory system, respecting the same cooldown, limit, and secret-item rules as the block.
* 🚀 **Zero N+1 Rendering:** every drop/trade code on a page is bulk-loaded in a single pass
  before rendering, regardless of how many shortcodes appear in the same content.
* 🔐 **Server-Side Validation:** recharge time (cooldown), collection limits, gamification
  opt-out, and the `block/playerhud:view` capability are all enforced at render time — a
  shortcode never leaks item names, XP, or trade contents to a user who shouldn't see them.
* 📱 **Mobile-Compatible Rendering:** shortcodes render a lightweight fallback (or nothing, for
  the widget) inside the Moodle app, where the AJAX collection flow does not apply.
