<?php
namespace filter_playerhud\output;

use moodle_url;
use renderable;
use templatable;
use renderer_base;
use context_block;

defined('MOODLE_INTERNAL') || die();

class render {

    public static function render_drop($attributes_str, $blockinstanceid) {
        global $DB, $USER, $CFG, $COURSE, $OUTPUT;
        
        require_once($CFG->dirroot . '/blocks/playerhud/lib.php');
        require_once($CFG->dirroot . '/blocks/playerhud/classes/utils.php');

        // 1. Parse Attributes
        $attrs = [];
        if (preg_match('/id=(\d+)/i', $attributes_str, $m)) $attrs['id'] = $m[1];
        if (preg_match('/code=([a-zA-Z0-9]+)/i', $attributes_str, $m)) $attrs['code'] = $m[1];
        if (preg_match('/mode=([a-z]+)/i', $attributes_str, $m)) $attrs['mode'] = strtolower($m[1]);
        if (preg_match('/text=["\']?([^"\']*)["\']?/i', $attributes_str, $m)) $attrs['text'] = $m[1];
        if (preg_match('/button_text=["\']?([^"\']*)["\']?/i', $attributes_str, $m)) $attrs['button_text'] = $m[1];
        if (preg_match('/button_emoji=["\']?([^"\']*)["\']?/i', $attributes_str, $m)) $attrs['button_emoji'] = $m[1];

        // 2. Fetch Data
        $data = null;
        if (!empty($attrs['code'])) {
            if (function_exists('block_playerhud_get_drop_details_by_code')) {
                $data = block_playerhud_get_drop_details_by_code($attrs['code'], $blockinstanceid);
            }
        } elseif (!empty($attrs['id'])) {
            $data = block_playerhud_get_drop_details_for_filter((int)$attrs['id']);
        }

        if (!$data || $data->blockinstanceid != $blockinstanceid) {
            return '';
        }

        $dropid = $data->dropid;
        $mode = $attrs['mode'] ?? 'card';
        $customtext = $attrs['text'] ?? null;

        // 3. Game Logic
        $inventory = $DB->get_records('block_playerhud_inventory', [
            'userid' => $USER->id, 
            'dropid' => $dropid
        ], 'timecreated DESC');
        
        $count = count($inventory);
        $lastcollected = $inventory ? reset($inventory) : null;
        
        // NOVO: Lógica inteligente para esconder contador se item for único
        // Se maxusage for 1, nunca mostramos "x1". Se for > 1 ou 0 (infinito), mostramos se count > 0.
        $is_unique = ($data->maxusage == 1);
        $show_count = ($count > 0 && !$is_unique);

        $limitreached = ($data->maxusage > 0 && $count >= $data->maxusage);
        
        $readytime = 0;
        $iscooldown = false;
        if ($lastcollected && $data->respawntime > 0) {
            $readytime = $lastcollected->timecreated + $data->respawntime;
            if (time() < $readytime) {
                $iscooldown = true;
            }
        }

        // 4. Secret & Display Logic
        $timestamp_val = 0;
        $is_secret_masked = ($data->secret == 1 && $count == 0);
        $xp_val = isset($data->xp) ? $data->xp : 0; // Pega o XP do objeto de dados

        if ($is_secret_masked) {
            $display_name = get_string('mysteryitem', 'filter_playerhud');
            $display_desc = get_string('mysteryitem_desc', 'filter_playerhud');
            $media = ['is_image' => false, 'content' => '❓', 'url' => ''];
            $xp_display = '???';
            $date_display = ''; // Sem data se é segredo/não coletado
        } else {
            $display_name = format_string($data->itemname);
            $display_desc = $data->description;
            $xp_display = $xp_val . ' ' . get_string('currentxp', 'filter_playerhud');

            // CORREÇÃO: Data formatada para o modal
            $date_display = '';
            if ($lastcollected) {
                $timestamp_val = $lastcollected->timecreated;
                $date_display = userdate($lastcollected->timecreated, get_string('strftimedatefullshort', 'langconfig'));
            }
            
            $context = context_block::instance($blockinstanceid);
            $fakeitem = (object)['id' => $data->itemid, 'image' => $data->image];
            $media = \block_playerhud\utils::get_item_display_data($fakeitem, $context);
        }

        // 5. Prepare Data for Template
        $collecturl = new moodle_url('/blocks/playerhud/collect.php', [
            'instanceid' => $blockinstanceid,
            'dropid' => $dropid,
            'courseid' => $COURSE->id,
            'sesskey' => sesskey()
        ]);

        $safeName = s($display_name);
        $htmlDesc = base64_encode($display_desc);
        $rawImageForJs = $media['is_image'] ? $media['url'] : strip_tags($media['content']);

        // NOVO: Adicionado data-xp
        $dataAttributes = 'data-name="' . $safeName . '" ' .
                          'data-desc-b64="' . $htmlDesc . '" ' .
                          'data-image="' . s($rawImageForJs) . '" ' .
                          'data-isimage="' . ($media['is_image'] ? 1 : 0) . '" ' .
                          'data-xp="' . s($xp_display) . '" ' .
                          'data-unique="' . ($is_unique ? 1 : 0) . '"'.
                          'data-timestamp="' . $timestamp_val . '"';

        $btnText = !empty($attrs['button_text']) ? $attrs['button_text'] : get_string('take', 'filter_playerhud');
        $btnEmoji = isset($attrs['button_emoji']) ? $attrs['button_emoji'] : '🖐';
        
        if ($is_secret_masked && empty($attrs['button_text'])) {
            $btnText = get_string('mysteryitem', 'filter_playerhud');
            $btnEmoji = '🕵️';
        }

        $emojiHtml = !empty($btnEmoji) ? '<span aria-hidden="true" class="me-1">' . s($btnEmoji) . '</span> ' : '';
        $textLabel = ($is_secret_masked) ? $display_name : ($customtext ?: $display_name);

        $templateData = [
            'is_card' => ($mode === 'card'),
            'is_text' => ($mode === 'text'),
            'is_image_mode' => ($mode === 'image'),
            'limit_reached' => $limitreached,
            'is_cooldown' => $iscooldown,
            'readytime' => $readytime,
            'count' => $count,
            'show_count' => $show_count, // Usaremos isso no mustache
            'safe_name' => $safeName,
            'display_name' => $display_name,
            'label' => $textLabel,
            'is_image_media' => $media['is_image'],
            'media_url' => $media['url'],
            'media_content' => $media['content'],
            'btn_text' => $btnText,
            'emoji_html' => $emojiHtml, 
            'collect_url' => $collecturl->out(false),
            'data_attributes' => $dataAttributes
        ];

        return $OUTPUT->render_from_template('filter_playerhud/drop', $templateData);
    }

    public static function render_trade($id, $blockinstanceid) { return ''; }
}
