<?php
namespace filter_playerhud\output;

defined('MOODLE_INTERNAL') || die();

class render {

    /**
     * Renderiza o botão de Drop (Shortcode).
     * Suporta: [PLAYERHUD_DROP code=... mode=... text=... button_text=... button_emoji=...]
     */
public static function render_drop($attributes_str, $blockinstanceid) {
        global $DB, $USER, $CFG, $COURSE;
        require_once($CFG->dirroot . '/blocks/playerhud/lib.php');

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
                $data = block_playerhud_get_drop_details_by_code($attrs['code']);
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

        // 3. Game Logic (Check Inventory)
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

        // --- LÓGICA DE SEGREDO (NOVO) ---
        // Se o item é secreto E o aluno nunca pegou (count == 0), mascaramos tudo.
        $is_secret_masked = ($data->secret == 1 && $count == 0);

        if ($is_secret_masked) {
            $display_name = get_string('secret_name', 'block_playerhud');
            $display_desc = get_string('secret_desc', 'block_playerhud');
            // Força a imagem de interrogação
            $media = [
                'is_image' => false,
                'content' => '<span aria-hidden="true">❓</span>',
                'url' => ''
            ];
        } else {
            $display_name = format_string($data->itemname);
            $display_desc = $data->description;
            
            // Carrega imagem real
            $context = \context_block::instance($blockinstanceid);
            $fakeitem = (object)['id' => $data->itemid, 'image' => $data->image];
            $media = \block_playerhud\utils::get_item_display_data($fakeitem, $context);
        }

        // 4. Assets & Strings
        $collecturl = new \moodle_url('/blocks/playerhud/collect.php', [
            'instanceid' => $blockinstanceid,
            'dropid' => $dropid,
            'courseid' => $COURSE->id,
            'sesskey' => sesskey()
        ]);
        
        // Dados comuns para o Modal (Codificação segura)
        $safeName = s($display_name);
        $htmlDesc = base64_encode($display_desc);
        $rawImageForJs = $media['is_image'] ? $media['url'] : $media['content'];
        
        // Remove tags HTML se for conteúdo emoji para o atributo data-image
        if (!$media['is_image']) $rawImageForJs = strip_tags($media['content']);

        $commonDataAttrs = ' data-name="' . $safeName . '" 
                             data-desc-b64="' . $htmlDesc . '" 
                             data-image="' . s($rawImageForJs) . '" 
                             data-isimage="' . ($media['is_image'] ? 1 : 0) . '"';

        // --- MODO TEXTO ---
        if ($mode == 'text') {
            // Se for secreto, ignora o customtext e força "???"
            $label = ($is_secret_masked) ? $display_name : ($customtext ?: $display_name);
            
            if ($limitreached) {
                return '<span class="ph-item-details-trigger text-success fw-bold" style="cursor:help;" tabindex="0" role="button" ' . $commonDataAttrs . '>
                            <i class="fa fa-check"></i> ' . $label . '
                        </span>';
            }
            
            if ($iscooldown) {
                return '<span class="ph-item-details-trigger text-muted" style="cursor:wait;" tabindex="0" role="button" ' . $commonDataAttrs . '>
                            ⏳ ' . $label . ' <small class="ph-timer" data-deadline="' . $readytime . '">...</small>
                        </span>';
            }

            return '<a href="' . $collecturl->out() . '" class="ph-action-collect fw-bold" data-mode="text" ' . $commonDataAttrs . '>' . $label . '</a>';
        }

        // --- MODO IMAGEM ---
        if ($mode == 'image') {
            $imgHtml = $media['is_image'] 
                ? '<img src="' . $media['url'] . '" style="width:50px; height:50px; object-fit:contain;" alt="' . $safeName . '">'
                : '<span style="font-size:40px;" aria-hidden="true">' . $media['content'] . '</span>';
            
            $wrapperStart = '<div style="display:inline-block; position:relative; text-align:center;" ' . $commonDataAttrs . ' ';

            if ($limitreached) {
                return $wrapperStart . 'class="ph-item-details-trigger" tabindex="0" role="button" title="' . get_string('collected', 'block_playerhud') . '">
                            <div style="opacity:0.5; filter:grayscale(100%);">' . $imgHtml . '</div>
                            <span class="badge bg-success rounded-circle" style="position:absolute; bottom:-5px; right:-5px; font-size:0.6rem;"><i class="fa fa-check"></i></span>
                        </div>';
            }
            
            if ($iscooldown) {
                return $wrapperStart . 'class="ph-item-details-trigger" tabindex="0" role="button">
                            <div style="opacity:0.5;">' . $imgHtml . '</div>
                            <div class="ph-timer badge bg-light text-dark border shadow-sm" style="position:absolute; bottom:-10px; left:50%; transform:translateX(-50%); font-size:0.6rem;" data-deadline="' . $readytime . '">...</div>
                        </div>';
            }

            return '<a href="' . $collecturl->out() . '" class="ph-action-collect ph-hover-scale" style="display:inline-block; filter:drop-shadow(0 4px 2px rgba(0,0,0,0.1)); transition:transform 0.2s;" data-mode="image" ' . $commonDataAttrs . '>' . $imgHtml . '</a>';
        }

        // --- MODO CARD (Padrão) ---
        $strtake = !empty($attrs['button_text']) ? $attrs['button_text'] : get_string('take', 'block_playerhud');
        $emojiChar = isset($attrs['button_emoji']) ? $attrs['button_emoji'] : '🖐';
        
        // Se for secreto, o botão mostra "?" se não houver texto customizado
        if ($is_secret_masked && empty($attrs['button_text'])) {
            $strtake = get_string('secret_name', 'block_playerhud');
            $emojiChar = '🕵️';
        }

        $emojiHtml = !empty($emojiChar) ? '<span aria-hidden="true" class="me-1">' . s($emojiChar) . '</span> ' : '';

        // Badge de contagem
        $badgeHtml = ($count > 0) 
            ? '<span class="badge bg-info text-dark rounded-pill position-absolute ph-badge-count" style="top:5px; right:5px; font-size:0.7rem;">x' . $count . '</span>' 
            : '<span class="badge bg-info text-dark rounded-pill position-absolute ph-badge-count" style="display:none; top:5px; right:5px; font-size:0.7rem;">x0</span>';

        $statusClass = $limitreached ? 'ph-owned' : ($iscooldown ? '' : 'ph-item-trigger');
        $btnHtml = '';

        if ($limitreached) {
            $btnHtml = '<button disabled class="btn btn-light btn-sm w-100 text-success border-success">✔ ' . get_string('collected', 'block_playerhud') . '</button>';
        } else if ($iscooldown) {
            $btnHtml = '<button disabled class="btn btn-light btn-sm w-100 text-muted">⏳ <span class="ph-timer" data-deadline="' . $readytime . '">...</span></button>';
        } else {
            $btnHtml = '<a href="' . $collecturl->out() . '" class="btn btn-primary btn-sm w-100 ph-action-collect shadow-sm mt-2" data-mode="card">' . $emojiHtml . $strtake . '</a>';
        }

        $iconHtml = $media['is_image'] 
            ? '<img src="' . $media['url'] . '" style="max-width:100%; max-height:100%; object-fit:contain;" alt="">'
            : '<div style="font-size:2.5em; line-height:1;" aria-hidden="true">' . $media['content'] . '</div>';

        return '
        <div class="playerhud-item-card ph-card-compact card p-3 ' . $statusClass . '" 
             style="width: 160px; display:inline-block; vertical-align:top; margin:5px; position: relative;"
             ' . $commonDataAttrs . '>
            
            ' . $badgeHtml . '
            
            <div class="ph-item-details-trigger d-flex flex-column align-items-center justify-content-center mb-2" 
                 style="cursor: pointer; min-height: 85px;"
                 tabindex="0" 
                 role="button" 
                 aria-label="' . get_string('details', 'block_playerhud') . ': ' . $safeName . '">
                 
                 <div class="text-center mb-1" style="height: 60px; display: flex; align-items: center; justify-content: center; width: 100%;">
                     ' . $iconHtml . '
                 </div>

                 <strong class="text-center d-block text-truncate w-100" style="font-size: 0.9rem;" aria-hidden="true">' . $display_name . '</strong>
            </div>
            
            ' . $btnHtml . '
        </div>';
    }
}
