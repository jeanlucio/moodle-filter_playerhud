<?php
namespace filter_playerhud\output;

use moodle_url;
use renderable;
use templatable;
use renderer_base;

defined('MOODLE_INTERNAL') || die();

class render {

    /**
     * Renderiza o botão de Drop (Shortcode) usando Templates Mustache.
     * Suporta: [PLAYERHUD_DROP code=... mode=... text=... button_text=... button_emoji=...]
     */
    public static function render_drop($attributes_str, $blockinstanceid) {
        global $DB, $USER, $CFG, $COURSE, $OUTPUT;
        
        // Inclui bibliotecas necessárias
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

        // Validação de segurança: Drop deve existir e pertencer a este bloco
        if (!$data || $data->blockinstanceid != $blockinstanceid) {
            return '';
        }

        $dropid = $data->dropid;
        $mode = $attrs['mode'] ?? 'card';
        $customtext = $attrs['text'] ?? null;

        // 3. Game Logic (Inventory & Cooldown)
        $inventory = $DB->get_records('block_playerhud_inventory', [
            'userid' => $USER->id, 
            'dropid' => $dropid
        ], 'timecreated DESC');
        
        $count = count($inventory);
        $lastcollected = $inventory ? reset($inventory) : null;
        $limitreached = ($data->maxusage > 0 && $count >= $data->maxusage);
        
        $readytime = 0;
        $iscooldown = false;
        if ($lastcollected && $data->respawntime > 0) {
            $readytime = $lastcollected->timecreated + $data->respawntime;
            if (time() < $readytime) {
                $iscooldown = true;
            }
        }

        // 4. Secret Item Logic
        $is_secret_masked = ($data->secret == 1 && $count == 0);

        if ($is_secret_masked) {
            $display_name = get_string('secret_name', 'block_playerhud');
            $display_desc = get_string('secret_desc', 'block_playerhud');
            $media = [
                'is_image' => false,
                'content' => '❓',
                'url' => ''
            ];
        } else {
            $display_name = format_string($data->itemname);
            $display_desc = $data->description;
            
            // Fetch real media
            $context = \context_block::instance($blockinstanceid);
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

        // Prepara atributos de dados comuns para o JS (View Modal e AJAX Collect)
        $safeName = s($display_name);
        $htmlDesc = base64_encode($display_desc);
        $rawImageForJs = $media['is_image'] ? $media['url'] : strip_tags($media['content']);

        $dataAttributes = 'data-name="' . $safeName . '" ' .
                          'data-desc-b64="' . $htmlDesc . '" ' .
                          'data-image="' . s($rawImageForJs) . '" ' .
                          'data-isimage="' . ($media['is_image'] ? 1 : 0) . '"';

        // Configuração de Botão (Card Mode)
        $btnText = !empty($attrs['button_text']) ? $attrs['button_text'] : get_string('take', 'block_playerhud');
        $btnEmoji = isset($attrs['button_emoji']) ? $attrs['button_emoji'] : '🖐';
        
        if ($is_secret_masked && empty($attrs['button_text'])) {
            $btnText = get_string('secret_name', 'block_playerhud');
            $btnEmoji = '🕵️';
        }
        $emojiHtml = !empty($btnEmoji) ? '<span aria-hidden="true" class="me-1">' . s($btnEmoji) . '</span> ' : '';

        // Label para Text Mode
        $textLabel = ($is_secret_masked) ? $display_name : ($customtext ?: $display_name);

        $templateData = [
            // Flags de Modo
            'is_card' => ($mode === 'card'),
            'is_text' => ($mode === 'text'),
            'is_image_mode' => ($mode === 'image'), // Renomeado para evitar conflito com is_image da media

            // Estado
            'limit_reached' => $limitreached,
            'is_cooldown' => $iscooldown,
            'readytime' => $readytime,
            'count' => $count,

            // Dados Visuais
            'safe_name' => $safeName,
            'display_name' => $display_name,
            'label' => $textLabel, // Usado no modo texto
            'is_image_media' => $media['is_image'],
            'media_url' => $media['url'],
            'media_content' => $media['content'],
            
            // Botão Card
            'btn_text' => $btnText,
            'emoji_html' => $emojiHtml,

            // Técnico
            'collect_url' => $collecturl->out(false),
            'data_attributes' => $dataAttributes
        ];

        return $OUTPUT->render_from_template('filter_playerhud/drop', $templateData);
    }

    /**
     * Renderiza Trades (Ainda não migrado, mas mantemos o placeholder se necessário)
     */
    public static function render_trade($id, $blockinstanceid) {
        // Implementar no futuro (Etapa 6/7)
        return '';
    }
}
