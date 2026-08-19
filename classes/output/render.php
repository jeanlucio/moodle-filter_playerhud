<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace filter_playerhud\output;

use moodle_url;
use context_block;

/**
 * Render output class for PlayerHUD filter.
 * @package    filter_playerhud
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class render {
    /** @var array Preloaded cache for drops. */
    protected static $dropscache = [];

    /** @var array Preloaded cache for user inventory. */
    protected static $inventorycache = [];

    /** @var array Preloaded cache for item display data (images/emojis). */
    protected static $dropmediacache = [];

    /**
     * Tracks which drop IDs were preloaded, even when the inventory result was empty.
     * This prevents the fallback query from firing for users who simply never collected.
     *
     * @var array Keys are drop IDs that have already been through preload_data().
     */
    protected static $preloadeddropids = [];

    /**
     * Cache for player profiles, keyed by "{instanceid}_{userid}".
     * Moved from static-local to class property so it can be reset between tests.
     *
     * @var array
     */
    protected static $playercache = [];

    /**
     * Cache for player RPG class ID, keyed by "{instanceid}_{userid}".
     *
     * @var array
     */
    protected static $classcache = [];

    /**
     * Preloads drops, inventory and media in bulk to prevent N+1 queries.
     *
     * @param array $dropcodes Array of drop codes found in the text.
     * @param array $tradecodes Array of trade codes found in the text.
     * @param int $instanceid The block instance ID.
     */
    public static function preload_data(array $dropcodes, array $tradecodes, int $instanceid) {
        global $DB, $USER;

        $context = \context_block::instance($instanceid);

        if (!empty($dropcodes)) {
            $uniquecodes = array_unique($dropcodes);
            [$insql, $inparams] = $DB->get_in_or_equal($uniquecodes, SQL_PARAMS_NAMED, 'dc');
            $inparams['bi'] = $instanceid;

            // The drop "value" column only exists on block_playerhud versions that ship the
            // item-quantity engine — this queries block_playerhud's own table directly, so it
            // must not assume the installed version has it. "1 as value" matches that older
            // engine's behaviour: it never granted more than one unit per collection.
            $valuecolumn = $DB->get_manager()->field_exists('block_playerhud_drops', 'value')
                ? 'd.value'
                : '1 as value';

            $sql = "SELECT d.id as dropid, d.maxusage, $valuecolumn, d.respawntime, d.blockinstanceid, d.code,
                           i.id as itemid, i.name as itemname, i.image, i.xp, i.description,
                           i.secret, i.required_class_id
                      FROM {block_playerhud_drops} d
                      JOIN {block_playerhud_items} i ON d.itemid = i.id
                     WHERE d.code $insql
                       AND d.blockinstanceid = :bi
                       AND i.enabled = 1";

            $drops = $DB->get_records_sql($sql, $inparams);

            if ($drops) {
                $dropids = [];
                $fakeitems = [];

                foreach ($drops as $drop) {
                    self::$dropscache[$drop->code] = $drop;
                    $dropids[] = $drop->dropid;
                    $fakeitems[$drop->itemid] = (object)['id' => $drop->itemid, 'image' => $drop->image];

                    // Mark this drop ID as preloaded regardless of inventory result.
                    self::$preloadeddropids[$drop->dropid] = true;
                }

                // Bulk load media.
                self::$dropmediacache = \block_playerhud\utils::get_items_display_data($fakeitems, $context);

                // Bulk load user inventory for all drops found.
                [$didsql, $didparams] = $DB->get_in_or_equal($dropids, SQL_PARAMS_NAMED, 'did');
                $didparams['uid'] = $USER->id;

                $invsql = "SELECT id, dropid, timecreated
                             FROM {block_playerhud_inventory}
                            WHERE userid = :uid AND dropid $didsql
                         ORDER BY timecreated DESC";

                $invs = $DB->get_records_sql($invsql, $didparams);
                if ($invs) {
                    foreach ($invs as $inv) {
                        self::$inventorycache[$inv->dropid][] = $inv;
                    }
                }

                // Also bulk load the new-engine stacking storage (grant entries only, never
                // revoked) — see get_pickup_totals() for why both sources must be summed.
                if ($DB->get_manager()->table_exists('block_playerhud_stack_log')) {
                    $sqsql = "SELECT id, dropid, timecreated
                                FROM {block_playerhud_stack_log}
                               WHERE userid = :uid AND dropid $didsql
                                     AND delta > 0 AND source <> 'revoked'
                            ORDER BY timecreated DESC";
                    $sqs = $DB->get_records_sql($sqsql, $didparams);
                    if ($sqs) {
                        foreach ($sqs as $sq) {
                            self::$inventorycache[$sq->dropid][] = $sq;
                        }
                    }
                }
            }
        }
    }

    /**
     * Counts the collection event count for a drop, across both storage generations: the legacy
     * per-unit inventory rows and the new-engine stack_log grant entries (both 1 row = 1
     * collection event). Deliberately a count of EVENTS, never a sum of quantity granted: a
     * drop's maxusage limits collection events, not units, so a caller comparing a unit total
     * against maxusage would report the limit reached after fewer real collections than
     * configured. Also tracks the most recent collection timestamp.
     *
     * @param array $entries List of {timecreated} rows from the merged cache/fallback.
     * @return array {events: int, last: \stdClass|null}
     */
    private static function get_pickup_totals(array $entries): array {
        $events = 0;
        $last = null;
        $lasttime = 0;

        foreach ($entries as $entry) {
            $events++;
            if ($entry->timecreated > $lasttime) {
                $lasttime = $entry->timecreated;
                $last = $entry;
            }
        }

        return ['events' => $events, 'last' => $last];
    }

    /**
     * Renders the drop collection trigger.
     *
     * @param string $attributesstr Raw attributes string from the shortcode.
     * @param int $blockinstanceid The block instance ID.
     * @return string HTML content.
     */
    public static function render_drop($attributesstr, $blockinstanceid) {
        global $DB, $USER, $CFG, $COURSE, $OUTPUT;

        if (\core_useragent::is_moodle_app()) {
            return '';
        }

        $cachekey = $blockinstanceid . '_' . $USER->id;

        if (!isset(self::$playercache[$cachekey])) {
            self::$playercache[$cachekey] = $DB->get_record('block_playerhud_user', [
                'blockinstanceid' => $blockinstanceid,
                'userid' => $USER->id,
            ]);
        }

        $player = self::$playercache[$cachekey];

        if (!$player || !$player->enable_gamification) {
            return '';
        }

        require_once($CFG->dirroot . '/blocks/playerhud/lib.php');

        // Parse Attributes.
        $attrs = [];

        if (preg_match('/code=([a-zA-Z0-9]+)/i', $attributesstr, $m)) {
            $attrs['code'] = $m[1];
        }
        if (preg_match('/mode=([a-z]+)/i', $attributesstr, $m)) {
            $attrs['mode'] = strtolower($m[1]);
        }
        if (preg_match('/text=["\']?([^"\']*)["\']?/i', $attributesstr, $m)) {
            $attrs['text'] = $m[1];
        }
        if (preg_match('/button_text=["\']?([^"\']*)["\']?/i', $attributesstr, $m)) {
            $attrs['button_text'] = $m[1];
        }
        if (preg_match('/button_emoji=["\']?([^"\']*)["\']?/i', $attributesstr, $m)) {
            $attrs['button_emoji'] = $m[1];
        }

        // Fetch Data from cache (or fallback).
        $data = null;
        if (!empty($attrs['code']) && isset(self::$dropscache[$attrs['code']])) {
            $data = self::$dropscache[$attrs['code']];
        } else if (!empty($attrs['code'])) {
            if (function_exists('block_playerhud_get_drop_details_by_code')) {
                $data = block_playerhud_get_drop_details_by_code($attrs['code'], $blockinstanceid);
            }
        }

        if (!$data || $data->blockinstanceid != $blockinstanceid) {
            return '';
        }

        // Class restriction: only query RPG progress when the item actually has a restriction.
        $userclassid = 0;
        if (!empty($data->required_class_id) && $data->required_class_id !== '0') {
            if (!isset(self::$classcache[$cachekey])) {
                $myclassid = 0;
                if ($DB->get_manager()->table_exists('block_playerhud_rpg_progress')) {
                    $prog = $DB->get_record(
                        'block_playerhud_rpg_progress',
                        ['userid' => $USER->id, 'blockinstanceid' => $blockinstanceid],
                        'classid'
                    );
                    if ($prog) {
                        $myclassid = (int) $prog->classid;
                    }
                }
                self::$classcache[$cachekey] = $myclassid;
            }
            $userclassid = self::$classcache[$cachekey];
        }
        if (!self::is_item_visible_for_class($data->required_class_id, $userclassid)) {
            return '';
        }

        $dropid = $data->dropid;
        $mode = $attrs['mode'] ?? 'card';
        $customtext = $attrs['text'] ?? null;

        // Inventory logic using cache.
        // FIX: Check whether this drop was preloaded rather than whether the cache array
        // is empty. This prevents a fallback query for users who simply never collected yet.
        $inventory = self::$inventorycache[$dropid] ?? [];
        if (!isset(self::$preloadeddropids[$dropid]) && empty($inventory)) {
            $legacyfallback = $DB->get_records('block_playerhud_inventory', [
                'userid' => $USER->id,
                'dropid' => $dropid,
            ], 'timecreated DESC');
            foreach ($legacyfallback as $row) {
                $inventory[] = $row;
            }

            if ($DB->get_manager()->table_exists('block_playerhud_stack_log')) {
                $stackfallback = $DB->get_records_select(
                    'block_playerhud_stack_log',
                    'userid = :uid AND dropid = :dropid AND delta > 0 AND source <> :revoked',
                    ['uid' => $USER->id, 'dropid' => $dropid, 'revoked' => 'revoked'],
                    'timecreated DESC'
                );
                foreach ($stackfallback as $row) {
                    $inventory[] = $row;
                }
            }
        }

        $pickuptotals = self::get_pickup_totals($inventory);
        $events = $pickuptotals['events'];
        $lastcollected = $pickuptotals['last'];

        $isunique = ($data->maxusage == 1);
        // Compared against the collection EVENT count, not the unit total: maxusage limits how
        // many times the drop can be collected, independent of how many units each collection
        // grants (see get_pickup_totals()).
        $limitreached = ($data->maxusage > 0 && $events >= $data->maxusage);

        $readytime = 0;
        $iscooldown = false;

        if ($lastcollected && $data->respawntime > 0) {
            $readytime = $lastcollected->timecreated + $data->respawntime;

            if (time() < $readytime) {
                $iscooldown = true;
            }
        }

        // Secret & Display Logic.
        $timestamp = 0;
        $issecret = ($data->secret == 1 && $events == 0);
        $xpvalue = isset($data->xp) ? $data->xp : 0;

        if ($issecret) {
            $displayname = get_string('mysteryitem', 'filter_playerhud');
            $displaydesc = get_string('mysteryitem_desc', 'filter_playerhud');
            $media = ['is_image' => false, 'content' => '❓', 'url' => ''];
            $xpdisplay = '???';
        } else {
            $context = context_block::instance($blockinstanceid);
            $displayname = format_string($data->itemname);
            // The widget stash path (widget.php) already cleans this same column with
            // format_text() before rendering; this path skipped that step and shipped the
            // raw HTML to the client instead, where the block's JS injects it verbatim.
            // The 'filter' => false option: an item description never needs shortcode expansion, and
            // without it a description containing this filter's own [PLAYERHUD_DROP ...]
            // shortcode would re-enter text_filter::filter() from inside render_drop(),
            // recursing until the request dies on memory_limit — core's filter chain has
            // no reentrancy guard of its own.
            $displaydesc = format_text($data->description ?? '', FORMAT_HTML, [
                'context' => $context,
                'filter' => false,
            ]);

            if ((int)$data->maxusage === 0) {
                $xpdisplay = '0';
            } else {
                $xpdisplay = $xpvalue . ' ' . get_string('currentxp', 'filter_playerhud');
            }

            if ($lastcollected) {
                $timestamp = $lastcollected->timecreated;
            }

            // Fetch media from cache.
            $media = self::$dropmediacache[$data->itemid] ?? null;
            if (!$media) {
                $fakeitem = (object)['id' => $data->itemid, 'image' => $data->image];
                $media = \block_playerhud\utils::get_item_display_data($fakeitem, $context);
            }
        }

        // Format respawn time for template.
        $respawntimestr = '';
        if ($data->respawntime > 0) {
            $respawntimestr = format_time($data->respawntime);
        }

        // Prepare Data for Template.
        $collecturl = new moodle_url('/blocks/playerhud/collect.php', [
            'instanceid' => $blockinstanceid,
            'dropid' => $dropid,
            'courseid' => $COURSE->id,
            'sesskey' => sesskey(),
        ]);
        $safename = s($displayname);
        // The description is always a string by this point: the mystery-item branch uses
        // get_string(), and the real-item branch coerces the nullable description column
        // with ?? '' before format_text() (which itself never returns null).
        $htmldesc = base64_encode($displaydesc);
        $rawimage = $media['is_image'] ? $media['url'] : strip_tags($media['content']);

        // The drop's own configured value-per-collection — a static property of the drop
        // definition, not a sum of what this student has collected from it. Only set on
        // block_playerhud versions that ship the item-quantity engine (block_playerhud_drops.
        // value); ?? 1 covers an older installed version, where lib.php never selects it.
        $value = (int) ($data->value ?? 1);

        // Precomputed once here so the modal (via data attributes) and the card/text-mode
        // badge/title below always show identical wording, whatever the drop's maxusage/value.
        // Guarded: format_drop_progress_count()/format_drop_qty_per_collection() only exist on
        // block_playerhud versions that ship the item-quantity engine. Older versions never
        // grant more than one unit per collection, so the plain "N/M Collected" phrasing already
        // covers that case exactly.
        if (method_exists('\block_playerhud\utils', 'format_drop_progress_count')) {
            $progresstext = \block_playerhud\utils::format_drop_progress_count($events, (int)$data->maxusage);
        } else if ((int)$data->maxusage > 0) {
            $progresstext = $events . '/' . (int)$data->maxusage . ' ' . get_string('collected', 'block_playerhud');
        } else {
            $progresstext = get_string('infinite', 'block_playerhud');
        }

        $qtytext = method_exists('\block_playerhud\utils', 'format_drop_qty_per_collection')
            ? \block_playerhud\utils::format_drop_qty_per_collection($value)
            : '';

        // Same guard: format_compact_number() is also new-engine-only. Falling back to the
        // plain number is a cosmetic difference only (no large-number "1.5k" shortening).
        $valuedisplay = method_exists('\block_playerhud\utils', 'format_compact_number')
            ? \block_playerhud\utils::format_compact_number($value)
            : (string) $value;

        // The infinite badge only needs maxusage, which has meant "unlimited collections" since
        // long before the item-quantity engine — shown on any block_playerhud version. The value
        // badge needs the new engine, so it is separately gated behind method_exists() above.
        $isinfinitedropbadge = ((int)$data->maxusage === 0);
        $showvaluebadge = ($qtytext !== '') && $value > 1;
        // Text mode has no room for two visible badges; surface both facts together via a
        // single title/aria-label instead, whenever there is something worth noting.
        $showprogresstitle = ($showvaluebadge || $isinfinitedropbadge);
        $titletext = ($qtytext !== '') ? ($progresstext . ' · ' . $qtytext) : $progresstext;

        $dataattributes = 'data-name="' . $safename . '" ' .
                          'data-desc-b64="' . $htmldesc . '" ' .
                          'data-image="' . s($rawimage) . '" ' .
                          'data-isimage="' . ($media['is_image'] ? 1 : 0) . '" ' .
                          'data-xp="' . s($xpdisplay) . '" ' .
                          'data-unique="' . ($isunique ? 1 : 0) . '" ' .
                          'data-timestamp="' . $timestamp . '" ' .
                          'data-progress-text="' . s($progresstext) . '" ' .
                          'data-qty-text="' . s($qtytext) . '" ' .
                          'data-maxusage="' . $data->maxusage . '" ' .
                          'data-respawntime-str="' . s($respawntimestr) . '"';

        $btntext = !empty($attrs['button_text']) ? $attrs['button_text'] : get_string('take', 'filter_playerhud');
        $btnemoji = isset($attrs['button_emoji']) ? $attrs['button_emoji'] : '🖐';

        if ($issecret && empty($attrs['button_text'])) {
            $btntext = get_string('mysteryitem', 'filter_playerhud');
            $btnemoji = '🕵️';
        }

        $emojihyml = !empty($btnemoji) ? '<span aria-hidden="true" class="me-1">' . s($btnemoji) . '</span> ' : '';
        $textlabel = ($issecret && empty($customtext)) ? $displayname : ($customtext ?: $displayname);

        $templatedata = [
            'is_card' => ($mode === 'card'),
            'is_text' => ($mode === 'text'),
            'is_image_mode' => ($mode === 'image'),
            'limit_reached' => $limitreached,
            'is_cooldown' => $iscooldown,
            'readytime' => $readytime,
            'is_infinite_drop_badge' => $isinfinitedropbadge,
            'show_value_badge' => $showvaluebadge,
            'show_progress_title' => $showprogresstitle,
            'value_display' => $valuedisplay,
            'qty_text' => $qtytext,
            'title_text' => $titletext,
            'safe_name' => $safename,
            'display_name' => $displayname,
            'label' => $textlabel,
            'is_image_media' => $media['is_image'],
            'media_url' => $media['url'],
            'media_content' => $media['content'],
            'btn_text' => $btntext,
            'emoji_html' => $emojihyml,
            'collect_url' => $collecturl->out(false),
            'data_attributes' => $dataattributes,
        ];

        return $OUTPUT->render_from_template('filter_playerhud/drop', $templatedata);
    }

    /**
     * Resolves a short lookup code to its trade ID and renders it.
     *
     * The code is derived from md5($id . '_' . $timecreated), truncated to 6 hex chars —
     * a convenience identifier for the shortcode syntax, not a security boundary. It is
     * derivable from two low-entropy values and offline-bruteforceable; do not rely on it
     * to gate access. The real access control lives in process_trade.php (sesskey,
     * capability and group checks), which this method never bypasses.
     *
     * @param string $code The 6-character lookup code.
     * @param int $blockinstanceid The block instance ID.
     * @return string HTML content of the trade card.
     */
    public static function render_trade_by_code($code, $blockinstanceid) {
        global $DB;

        if (empty($code)) {
            return '';
        }

        // Static cache to avoid N+1 if there are multiple shortcodes on the same page.
        static $tradescache = [];
        if (!isset($tradescache[$blockinstanceid])) {
            $tradescache[$blockinstanceid] = $DB->get_records(
                'block_playerhud_trades',
                ['blockinstanceid' => $blockinstanceid],
                '',
                'id, timecreated'
            );
        }

        $tradeid = 0;

        if (!empty($tradescache[$blockinstanceid])) {
            foreach ($tradescache[$blockinstanceid] as $t) {
                $expectedcode = strtoupper(substr(md5($t->id . '_' . $t->timecreated), 0, 6));
                if ($expectedcode === strtoupper($code)) {
                    $tradeid = $t->id;
                    break;
                }
            }
        }

        if (!$tradeid) {
            return ''; // Invalid code or deleted trade.
        }

        return self::render_trade($tradeid, $blockinstanceid);
    }

    /**
     * Renders a trade trigger inline widget.
     *
     * @param int $id The trade ID.
     * @param int $blockinstanceid The block instance ID.
     * @return string HTML content of the trade card.
     */
    public static function render_trade($id, $blockinstanceid) {
        global $DB, $USER, $COURSE, $OUTPUT, $PAGE;

        if (\core_useragent::is_moodle_app()) {
            return '';
        }

        $cachekey = $blockinstanceid . '_' . $USER->id;

        if (!isset(self::$playercache[$cachekey])) {
            self::$playercache[$cachekey] = $DB->get_record('block_playerhud_user', [
                'blockinstanceid' => $blockinstanceid,
                'userid' => $USER->id,
            ]);
        }

        $player = self::$playercache[$cachekey];

        if (!$player || !$player->enable_gamification) {
            return '';
        }

        // Fetch Trade.
        $trade = $DB->get_record('block_playerhud_trades', ['id' => $id, 'blockinstanceid' => $blockinstanceid]);
        if (!$trade) {
            return '';
        }

        $context = context_block::instance($blockinstanceid);

        // Fetch Requirements.
        $sqlreqs = "SELECT req.id, req.itemid, req.qty, i.name, i.image
                      FROM {block_playerhud_trade_reqs} req
                      JOIN {block_playerhud_items} i ON req.itemid = i.id
                     WHERE req.tradeid = :tradeid";
        $reqs = $DB->get_records_sql($sqlreqs, ['tradeid' => $id]);

        // Fetch Rewards.
        $sqlrewards = "SELECT rew.id, rew.itemid, rew.qty, i.name, i.image
                         FROM {block_playerhud_trade_rewards} rew
                         JOIN {block_playerhud_items} i ON rew.itemid = i.id
                        WHERE rew.tradeid = :tradeid";
        $rewards = $DB->get_records_sql($sqlrewards, ['tradeid' => $id]);

        // 1. BULK FETCH: Group all necessary items for the current trade.
        $fakeitems = [];
        if ($reqs) {
            foreach ($reqs as $req) {
                $fakeitems[$req->itemid] = (object)['id' => $req->itemid, 'image' => $req->image];
            }
        }
        if ($rewards) {
            foreach ($rewards as $rew) {
                $fakeitems[$rew->itemid] = (object)['id' => $rew->itemid, 'image' => $rew->image];
            }
        }

        $allmedia = \block_playerhud\utils::get_items_display_data($fakeitems, $context);

        // 2. Bulk fetch user inventory to validate if they can afford the trade. Sums both
        // storage generations: the legacy per-unit inventory rows and the new-engine stack
        // balance (block_playerhud_stack) — an item obtained only through the new engine would
        // otherwise never count towards affordability.
        $sqlinv = "SELECT itemid, COUNT(id) as qty FROM {block_playerhud_inventory} WHERE userid = :userid GROUP BY itemid";
        $myinventory = $DB->get_records_sql_menu($sqlinv, ['userid' => $USER->id]);

        if ($DB->get_manager()->table_exists('block_playerhud_stack')) {
            $sqlstack = "SELECT itemid, qty FROM {block_playerhud_stack} WHERE userid = :userid";
            $mystack = $DB->get_records_sql_menu($sqlstack, ['userid' => $USER->id]);
            foreach ($mystack as $stackitemid => $stackqty) {
                $myinventory[$stackitemid] = ($myinventory[$stackitemid] ?? 0) + $stackqty;
            }
        }

        // 3. Format Requirements and Check Affordability simultaneously.
        $reqsdata = [];
        $canafford = true;
        if ($reqs) {
            foreach ($reqs as $req) {
                $media = $allmedia[$req->itemid];
                $myqty = isset($myinventory[$req->itemid]) ? $myinventory[$req->itemid] : 0;
                $hasenough = ($myqty >= $req->qty);

                if (!$hasenough) {
                    $canafford = false;
                }

                $reqsdata[] = [
                    'qty' => $req->qty,
                    'name' => format_string($req->name),
                    'is_image' => $media['is_image'],
                    'url' => $media['url'],
                    'content' => $media['content'],
                    'user_qty' => $myqty,
                    'qty_class' => $hasenough ? 'text-success' : 'text-danger',
                ];
            }
        }

        // 4. Format Rewards using the bulk-loaded media array.
        $rewsdata = [];
        if ($rewards) {
            foreach ($rewards as $rew) {
                $media = $allmedia[$rew->itemid];
                $rewsdata[] = [
                    'qty' => $rew->qty,
                    'name' => format_string($rew->name),
                    'is_image' => $media['is_image'],
                    'url' => $media['url'],
                    'content' => $media['content'],
                ];
            }
        }

        // 5. Get the exact URL where the user is now.
        $returnurlparam = $PAGE->url->out_as_local_url(false);

        // Action URL to process the trade.
        $tradeurl = new moodle_url('/blocks/playerhud/process_trade.php', [
            'courseid' => $COURSE->id,
            'instanceid' => $blockinstanceid,
            'tradeid' => $id,
            'sesskey' => sesskey(),
            'returnurl' => $returnurlparam,
        ]);

        $templatedata = [
            'trade_name' => format_string($trade->name),
            'reqs' => $reqsdata,
            'rewards' => $rewsdata,
            'trade_url' => $tradeurl->out(false),
            'can_afford' => $canafford,
            'str_trade_btn' => get_string('trade_perform', 'block_playerhud'),
            'str_missing_items' => get_string('trade_missing_items', 'block_playerhud'),
            'str_you_pay' => get_string('shop_pay', 'block_playerhud'),
            'str_you_receive' => get_string('shop_receive', 'block_playerhud'),
        ];

        return $OUTPUT->render_from_template('filter_playerhud/trade', $templatedata);
    }

    /**
     * Pre-populates the player cache from an already-fetched record.
     *
     * Call this from text_filter after fetching the player, so render_drop()
     * reuses the same record and avoids a duplicate DB query.
     *
     * @param string $cachekey "{instanceid}_{userid}" key.
     * @param object|false $player The player record (or false when none exists).
     */
    public static function populate_player_cache(string $cachekey, object|false $player): void {
        self::$playercache[$cachekey] = $player;
    }

    /**
     * Resets all static caches.
     *
     * Must be called in PHPUnit tearDown() to prevent cache leaking between test cases,
     * since PHP does not reset static class properties between tests.
     */
    public static function reset_caches(): void {
        self::$dropscache       = [];
        self::$inventorycache   = [];
        self::$dropmediacache   = [];
        self::$preloadeddropids = [];
        self::$playercache      = [];
        self::$classcache       = [];
    }

    /**
     * Returns true if the item is visible for the given class.
     *
     * Mirrors \block_playerhud\utils::is_visible_for_class() but kept local to
     * avoid a hard runtime dependency on the block plugin's autoloaded classes.
     *
     * @param string $requiredclassids Comma-separated class IDs stored on the item, or '0'.
     * @param int $userclassid The current player's class ID (0 = no class assigned).
     * @return bool
     */
    private static function is_item_visible_for_class(string $requiredclassids, int $userclassid): bool {
        if (empty($requiredclassids) || $requiredclassids === '0') {
            return true;
        }
        $allowedarray = explode(',', $requiredclassids);
        return in_array('0', $allowedarray) || in_array((string) $userclassid, $allowedarray);
    }
}
