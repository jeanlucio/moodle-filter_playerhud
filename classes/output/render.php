<?php
namespace filter_playerhud\output;

defined('MOODLE_INTERNAL') || die();

class render {

    /**
     * Renderiza o botão de Drop (Shortcode).
     * Suporta: [PLAYERHUD_DROP code=... mode=... text=...]
     */
    public static function render_drop($attributes_str, $blockinstanceid) {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/blocks/playerhud/lib.php');

        // 1. Parse Attributes (Extrai ID, CODE, MODE e TEXT)
        $attrs = [];
        
        // Captura ID numérico (Legado)
        if (preg_match('/id=(\d+)/i', $attributes_str, $m)) $attrs['id'] = $m[1];
        
        // Captura Código Hash (Novo padrão seguro)
        if (preg_match('/code=([a-zA-Z0-9]+)/i', $attributes_str, $m)) $attrs['code'] = $m[1];
        
        // Captura Modo (card, text, image)
        if (preg_match('/mode=([a-z]+)/i', $attributes_str, $m)) $attrs['mode'] = strtolower($m[1]);
        
        // Captura Texto Personalizado (suporta aspas ou não)
        if (preg_match('/text=["\']?([^"\']*)["\']?/i', $attributes_str, $m)) $attrs['text'] = $m[1];

        // 2. Fetch Data (Prioridade para CODE, fallback para ID)
        $data = null;
        
        if (!empty($attrs['code'])) {
            if (function_exists('block_playerhud_get_drop_details_by_code')) {
                $data = block_playerhud_get_drop_details_by_code($attrs['code']);
            }
        } elseif (!empty($attrs['id'])) {
            $data = block_playerhud_get_drop_details_for_filter((int)$attrs['id']);
        }

        // Validação: Se não achou ou pertence a outro bloco, não renderiza nada.
        if (!$data || $data->blockinstanceid != $blockinstanceid) return '';

        $dropid = $data->dropid;
        $mode = $attrs['mode'] ?? 'card';
        $customtext = $attrs['text'] ?? null;

        // 3. Lógica do Jogo (Verificar Inventário e Cooldown)
        $inventory = $DB->get_records('block_playerhud_inventory', ['userid' => $USER->id, 'dropid' => $dropid], 'timecreated DESC');
        $count = count($inventory);
        $lastcollected = $inventory ? reset($inventory) : null;

        $limitreached = ($data->maxusage > 0 && $count >= $data->maxusage);
        
        $readytime = 0;
        $iscooldown = false;
        if ($lastcollected && $data->respawntime > 0) {
            $readytime = $lastcollected->timecreated + $data->respawntime;
            if (time() < $readytime) $iscooldown = true;
        }

        // 4. Preparação da URL e Assets
        global $COURSE;
        $collecturl = new \moodle_url('/blocks/playerhud/collect.php', [
            'instanceid' => $blockinstanceid,
            'dropid' => $dropid,
            'courseid' => $COURSE->id,
            'sesskey' => sesskey()
        ]);
        
        $context = \context_block::instance($blockinstanceid);
        
        // Helper para pegar imagem/emoji
        $fakeitem = (object)['id' => $data->itemid, 'image' => $data->image];
        $media = \block_playerhud\utils::get_item_display_data($fakeitem, $context);
        
        // Rótulo: Usa texto personalizado se houver, senão usa nome do item
        $label = $customtext ?: format_string($data->itemname);

        // Strings comuns (usando as do filtro ou bloco conforme disponibilidade)
        $strcollected = get_string('collected', 'block_playerhud');
        $strtake = get_string('take', 'block_playerhud');

        // --- RENDERIZAÇÃO POR MODO ---
        
        // A. MODO TEXTO (Link simples)
        if ($mode == 'text') {
            if ($limitreached) {
                return '<span class="text-success cursor-default" title="' . $strcollected . '">✅ ' . $label . '</span>';
            }
            if ($iscooldown) {
                return '<span class="text-muted cursor-wait">⏳ ' . $label . '...</span>';
            }
            // Link clicável
            return '<a href="' . $collecturl->out() . '" class="ph-action-collect fw-bold" data-mode="text">' . $label . '</a>';
        }

        // B. MODO IMAGEM (Ícone flutuante)
        if ($mode == 'image') {
            $imgHtml = $media['is_image'] 
                ? '<img src="' . $media['url'] . '" style="width:50px; height:50px; object-fit:contain;" alt="' . s($label) . '">'
                : '<span style="font-size:40px;" aria-hidden="true">' . $media['content'] . '</span>';
            
            if ($limitreached) {
                return '<div style="opacity:0.5; filter:grayscale(100%); display:inline-block;" title="' . $strcollected . '">' . $imgHtml . ' <i class="fa fa-check text-success" style="position:absolute; bottom:0; right:0;"></i></div>';
            }
            
            $style = $iscooldown ? 'opacity:0.6; cursor:wait;' : 'cursor:pointer; filter:drop-shadow(0 4px 2px rgba(0,0,0,0.1));';
            $action = $iscooldown ? '' : 'href="' . $collecturl->out() . '" class="ph-action-collect ph-hover-scale"';
            
            if ($iscooldown) {
                 return '<div style="display:inline-block; ' . $style . '">' . $imgHtml . '</div>';
            }
            return '<a ' . $action . ' style="display:inline-block; transition:transform 0.2s; ' . $style . '" data-mode="image">' . $imgHtml . '</a>';
        }

        // C. MODO CARD (Padrão Completo)
        $statusClass = $limitreached ? 'ph-owned' : ($iscooldown ? '' : 'ph-item-trigger');
        $btnHtml = '';

        if ($limitreached) {
            $btnHtml = '<button disabled class="btn btn-light btn-sm w-100 text-success border-success">✔ ' . $strcollected . '</button>';
        } else if ($iscooldown) {
            $btnHtml = '<button disabled class="btn btn-light btn-sm w-100 text-muted">⏳ <span class="ph-timer" data-deadline="' . $readytime . '">...</span></button>';
        } else {
            $btnHtml = '<a href="' . $collecturl->out() . '" class="btn btn-primary btn-sm w-100 ph-action-collect shadow-sm mt-2" data-mode="card">' . $strtake . '</a>';
        }

        $iconHtml = $media['is_image'] 
            ? '<img src="' . $media['url'] . '" style="max-width:100%; max-height:100%; object-fit:contain;" alt="">'
            : '<div style="font-size:2.5em;">' . $media['content'] . '</div>';

        return '
        <div class="playerhud-item-card card p-3 ' . $statusClass . '" style="width: 160px; display:inline-block; vertical-align:top; margin:5px; position: relative;">
            ' . ($count > 0 ? '<span class="badge bg-info text-dark rounded-pill position-absolute" style="top:5px; right:5px; font-size:0.7rem;">x' . $count . '</span>' : '') . '
            <div class="text-center mb-2" style="height: 60px; display: flex; align-items: center; justify-content: center;">' . $iconHtml . '</div>
            <strong class="text-center d-block mb-2 text-truncate" title="' . s($data->itemname) . '">' . format_string($data->itemname) . '</strong>
            ' . $btnHtml . '
        </div>';
    }

    /**
     * Renderiza o Card de Troca.
     */
    public static function render_trade($tradeid, $blockinstanceid) {
        // Implementação futura para trocas no filtro
        return ''; 
    }
}
