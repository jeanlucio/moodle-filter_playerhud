# 📖 Usage

1. Ensure the PlayerHUD Block is added and configured in the course.
2. Enable the **PlayerHUD Filter** (see [Installation](#installation)).
3. Insert one of the shortcodes below inside any content area that runs Moodle filters — pages,
   labels, book chapters, forum posts, etc.
4. Item drops and trade widgets render dynamically within the course; students collect/trade
   according to the rules defined in the PlayerHUD Block's Management Panel.

Shortcodes are stripped out (rendered as empty) for guests, on the site front page, for a user
whose `block/playerhud:view` capability is prohibited, and for a student who has paused their
own gamification — in every one of those cases nothing about the underlying item or trade is
ever sent to the browser.

## Shortcode Reference

### `[PLAYERHUD_WIDGET]`

Renders the compact PlayerHUD widget: avatar, XP/level, recent items stash, ranking badge, and
(when RPG mode is enabled) the karma bar and class portrait. Takes no attributes.

```
[PLAYERHUD_WIDGET]
```

### `[PLAYERHUD_DROP code=... mode=... text=... button_text=... button_emoji=...]`

Renders a collectible item drop trigger.

| Attribute | Required | Values | Default | Description |
|-----------|:--------:|--------|---------|-------------|
| `code` | Yes | Alphanumeric | — | The drop's unique collection code, generated when the drop is created in the Management Panel. |
| `mode` | No | `card`, `text`, `image` | `card` | Visual presentation: a self-contained card with icon and button, an inline text link, or an icon-only clickable image. |
| `text` | No | Any string | The item's name | Custom label shown next to the trigger (ignored for secret items until collected). |
| `button_text` | No | Any string | *"Take"* | Overrides the collect button's label (`card`/`text` modes). |
| `button_emoji` | No | Any emoji | 🖐 | Overrides the collect button's leading emoji (`card` mode). |

```
[PLAYERHUD_DROP code=XPTO123]
[PLAYERHUD_DROP code=XPTO123 mode=text text="Grab the sword"]
[PLAYERHUD_DROP code=XPTO123 mode=image]
[PLAYERHUD_DROP code=XPTO123 button_text="Collect!" button_emoji="⚔️"]
```

A **secret item** (marked as such in the Management Panel) always renders as a generic mystery
placeholder — name, description, and XP hidden — until the student actually collects it, even
if a custom `text` attribute is supplied.

An item can optionally be restricted to specific RPG classes; a student outside the allowed
classes never sees the shortcode's output at all (not even a placeholder).

### `[PLAYERHUD_TRADE code=...]`

Renders an inline NPC shop trade card, with live affordability checking against the current
user's inventory.

| Attribute | Required | Values | Description |
|-----------|:--------:|--------|-------------|
| `code` | Yes | 6-character code shown in the trade's Management Panel entry | Identifies the trade to render. |

```
[PLAYERHUD_TRADE code=A1B2C3]
```

The trade `code` is a short lookup convenience, not a security boundary — access to the trade
itself is always re-validated server-side (sesskey, capability, and group checks) when the
student actually performs it.

## Notes

* Multiple shortcodes on the same page are all resolved in a single bulk-loading pass — adding
  more drops to a page does not add proportionally more database queries.
* Inside the Moodle mobile app, `[PLAYERHUD_DROP ...]` and `[PLAYERHUD_TRADE ...]` render
  nothing (the AJAX collection flow is web-only); students are directed to the block's own
  Backpack view instead.
