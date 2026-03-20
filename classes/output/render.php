<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace filter_playerhud\output;

use moodle_url;
use context_block;

/**
 * Render output class for PlayerHUD filter.
 * @package    filter_playerhud
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class render {
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

        static $playercache = [];
        $cachekey = $blockinstanceid . '_' . $USER->id;

        if (!isset($playercache[$cachekey])) {
            $playercache[$cachekey] = $DB->get_record('block_playerhud_user', [
                'blockinstanceid' => $blockinstanceid,
                'userid' => $USER->id,
            ]);
        }

        $player = $playercache[$cachekey];

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

        // Fetch Data.
        $data = null;
        if (!empty($attrs['code'])) {
            if (function_exists('block_playerhud_get_drop_details_by_code')) {
                $data = block_playerhud_get_drop_details_by_code($attrs['code'], $blockinstanceid);
            }
        }

        if (!$data || $data->blockinstanceid != $blockinstanceid) {
            return '';
        }

        $dropid = $data->dropid;
        $mode = $attrs['mode'] ?? 'card';
        $customtext = $attrs['text'] ?? null;

        // Game Logic (Inventory & Cooldown).
        $inventory = $DB->get_records('block_playerhud_inventory', [
            'userid' => $USER->id,
            'dropid' => $dropid,
        ], 'timecreated DESC');

        $count = count($inventory);
        $lastcollected = $inventory ? reset($inventory) : null;

        $isunique = ($data->maxusage == 1);
        $limitreached = ($data->maxusage > 0 && $count >= $data->maxusage);

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
        $issecret = ($data->secret == 1 && $count == 0);
        $xpvalue = isset($data->xp) ? $data->xp : 0;

        if ($issecret) {
            $displayname = get_string('mysteryitem', 'filter_playerhud');
            $displaydesc = get_string('mysteryitem_desc', 'filter_playerhud');
            $media = ['is_image' => false, 'content' => '❓', 'url' => ''];
            $xpdisplay = '???';
        } else {
            $displayname = format_string($data->itemname);
            $displaydesc = $data->description;
            $xpdisplay = $xpvalue . ' ' . get_string('currentxp', 'filter_playerhud');

            if ($lastcollected) {
                $timestamp = $lastcollected->timecreated;
            }

            $context = context_block::instance($blockinstanceid);
            $fakeitem = (object)['id' => $data->itemid, 'image' => $data->image];
            $media = \block_playerhud\utils::get_item_display_data($fakeitem, $context);
        }

        // Prepare Data for Template.
        $collecturl = new moodle_url('/blocks/playerhud/collect.php', [
            'instanceid' => $blockinstanceid,
            'dropid' => $dropid,
            'courseid' => $COURSE->id,
            'sesskey' => sesskey(),
        ]);

        $safename = s($displayname);
        $htmldesc = base64_encode($displaydesc);
        $rawimage = $media['is_image'] ? $media['url'] : strip_tags($media['content']);

        $dataattributes = 'data-name="' . $safename . '" ' .
                          'data-desc-b64="' . $htmldesc . '" ' .
                          'data-image="' . s($rawimage) . '" ' .
                          'data-isimage="' . ($media['is_image'] ? 1 : 0) . '" ' .
                          'data-xp="' . s($xpdisplay) . '" ' .
                          'data-unique="' . ($isunique ? 1 : 0) . '" ' .
                          'data-timestamp="' . $timestamp . '"';

        $btntext = !empty($attrs['button_text']) ? $attrs['button_text'] : get_string('take', 'filter_playerhud');
        $btnemoji = isset($attrs['button_emoji']) ? $attrs['button_emoji'] : '🖐';

        if ($issecret && empty($attrs['button_text'])) {
            $btntext = get_string('mysteryitem', 'filter_playerhud');
            $btnemoji = '🕵️';
        }

        $emojihyml = !empty($btnemoji) ? '<span aria-hidden="true" class="me-1">' . s($btnemoji) . '</span> ' : '';
        $textlabel = ($issecret) ? $displayname : ($customtext ?: $displayname);

        $templatedata = [
            'is_card' => ($mode === 'card'),
            'is_text' => ($mode === 'text'),
            'is_image_mode' => ($mode === 'image'),
            'limit_reached' => $limitreached,
            'is_cooldown' => $iscooldown,
            'readytime' => $readytime,
            'count' => $count,
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
     * Resolves a secure trade code to its ID and renders it.
     * Prevents ID Enumeration by requiring the unguessable hash.
     *
     * @param string $code The 6-character secure code.
     * @param int $blockinstanceid The block instance ID.
     * @return string HTML content of the trade card.
     */
    public static function render_trade_by_code($code, $blockinstanceid) {
        global $DB;

        if (empty($code)) {
            return '';
        }

        // Cache estático para evitar N+1 caso haja múltiplos shortcodes na mesma página.
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
            return ''; // Código inválido ou troca deletada.
        }

        // Se achou, chama o renderizador original que já estava pronto!
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
        global $DB, $USER, $COURSE, $OUTPUT;

        if (\core_useragent::is_moodle_app()) {
            return '';
        }

        // Static cache to avoid N+1 in filter (Moodle.org Standard).
        static $playercache = [];
        $cachekey = $blockinstanceid . '_' . $USER->id;

        if (!isset($playercache[$cachekey])) {
            $playercache[$cachekey] = $DB->get_record('block_playerhud_user', [
                'blockinstanceid' => $blockinstanceid,
                'userid' => $USER->id,
            ]);
        }

        $player = $playercache[$cachekey];

        if (!$player || !$player->enable_gamification) {
            return '';
        }

        // Fetch Trade.
        $trade = $DB->get_record('block_playerhud_trades', ['id' => $id, 'blockinstanceid' => $blockinstanceid]);

        if (!$trade) {
            return '';
        }

        $context = context_block::instance($blockinstanceid);

        // Helper function to format items with images/emojis.
        $formatitems = function ($records) use ($context) {
            $formatted = [];
            foreach ($records as $r) {
                $fakeitem = (object)['id' => $r->itemid, 'image' => $r->image];
                $media = \block_playerhud\utils::get_item_display_data($fakeitem, $context);

                $formatted[] = [
                    'qty' => $r->qty,
                    'name' => format_string($r->name),
                    'is_image' => $media['is_image'],
                    'url' => $media['url'],
                    'content' => $media['content'],
                ];
            }
            return $formatted;
        };

        // Fetch Requirements (Student Pays). Garante 'req.id' como primeira coluna (Primary Key).
        $sqlreqs = "SELECT req.id, req.itemid, req.qty, i.name, i.image
                      FROM {block_playerhud_trade_reqs} req
                      JOIN {block_playerhud_items} i ON req.itemid = i.id
                     WHERE req.tradeid = :tradeid";
        $reqs = $DB->get_records_sql($sqlreqs, ['tradeid' => $id]);

        // Fetch Rewards (Student Receives). Garante 'rew.id' como primeira coluna (Primary Key).
        $sqlrewards = "SELECT rew.id, rew.itemid, rew.qty, i.name, i.image
                         FROM {block_playerhud_trade_rewards} rew
                         JOIN {block_playerhud_items} i ON rew.itemid = i.id
                        WHERE rew.tradeid = :tradeid";
        $rewards = $DB->get_records_sql($sqlrewards, ['tradeid' => $id]);

        // 1. Bulk Fetch do Inventário do Usuário (Zero N+1)
        $sqlinv = "SELECT itemid, COUNT(id) as qty FROM {block_playerhud_inventory} WHERE userid = :userid GROUP BY itemid";
        $myinventory = $DB->get_records_sql_menu($sqlinv, ['userid' => $USER->id]);

        // 2. Valida se o aluno tem como pagar
        $can_afford = true;
        foreach ($reqs as $req) {
            $myqty = isset($myinventory[$req->itemid]) ? $myinventory[$req->itemid] : 0;
            if ($myqty < $req->qty) {
                $can_afford = false;
                break;
            }
        }

        // 3. Pega a URL exata onde o aluno está agora
        global $PAGE;
        $returnurlparam = $PAGE->url->out_as_local_url(false);

        // Action URL to process the trade.
        $tradeurl = new moodle_url('/blocks/playerhud/process_trade.php', [
            'courseid' => $COURSE->id,
            'instanceid' => $blockinstanceid,
            'tradeid' => $id,
            'sesskey' => sesskey(),
            'returnurl' => $returnurlparam // <--- Injetamos a URL de retorno aqui!
        ]);

        $templatedata = [
            'trade_name' => format_string($trade->name),
            'reqs' => $formatitems($reqs),
            'rewards' => $formatitems($rewards),
            'trade_url' => $tradeurl->out(false),
            'can_afford' => $can_afford, // <--- Enviamos a validação pro layout
            'str_trade_btn' => get_string('trade_perform', 'block_playerhud'),
            'str_missing_items' => get_string('trade_missing_items', 'block_playerhud'), // <--- Nova string
            'str_you_pay' => get_string('shop_pay', 'block_playerhud'),
            'str_you_receive' => get_string('shop_receive', 'block_playerhud'),
        ];

        return $OUTPUT->render_from_template('filter_playerhud/trade', $templatedata);
    }
}
