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
 *
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

        // Check if gamification is enabled for the user.
        $player = $DB->get_record('block_playerhud_user', [
            'blockinstanceid' => $blockinstanceid,
            'userid' => $USER->id,
        ]);

        if (!$player || !$player->enable_gamification) {
            return '';
        }

        require_once($CFG->dirroot . '/blocks/playerhud/lib.php');
        require_once($CFG->dirroot . '/blocks/playerhud/classes/utils.php');

        // Parse Attributes.
        $attrs = [];
        if (preg_match('/id=(\d+)/i', $attributesstr, $m)) {
            $attrs['id'] = $m[1];
        }
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
        } else if (!empty($attrs['id'])) {
            $data = block_playerhud_get_drop_details_for_filter((int)$attrs['id']);
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
        $showcount = ($count > 0 && !$isunique);
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
            'show_count' => $showcount,
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
     * Renders a trade trigger (Placeholder).
     *
     * @param int $id The trade ID.
     * @param int $blockinstanceid The block instance ID.
     * @return string Empty string.
     */
    public static function render_trade($id, $blockinstanceid) {
        return '';
    }
}
