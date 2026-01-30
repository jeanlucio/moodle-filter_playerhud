<?php
namespace filter_playerhud\output;

defined('MOODLE_INTERNAL') || die();

class render {

    /**
     * Renderiza o botão de Drop.
     */
    public static function render_drop($attributes_str, $blockinstanceid) {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/blocks/playerhud/lib.php');

        // 1. Parse Attributes
        $attrs = [];
        if (preg_match('/id=(\d+)/i', $attributes_str, $m)) $attrs['id'] = $m[1];
        if (preg_match('/mode=([a-z]+)/i', $attributes_str, $m)) $attrs['mode'] = strtolower($m[1]);
        if (preg_match('/text=["\']?([^"\']*)["\']?/i', $attributes_str, $m)) $attrs['text'] = $m[1];

        if (empty($attrs['id'])) return '';

        $dropid = (int)$attrs['id'];
        $mode = $attrs['mode'] ?? 'card';
        $customtext = $attrs['text'] ?? null;

        // 2. Fetch Data (Using Lib Helper to join Drop + Item)
        $data = block_playerhud_get_drop_details_for_filter($dropid);
        if (!$data || $data->blockinstanceid != $blockinstanceid) return '';

        // 3. Logic (Inventory Check)
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

        // 4. Generate Output
        // URL aponta para o script de coleta do BLOCO
        $collecturl = new \moodle_url('/blocks/playerhud/collect.php', [
            'instanceid' => $blockinstanceid,
            'dropid' => $dropid,
            'courseid' => $DB->get_field('block_instances', 'parentcontextid', ['id' => $blockinstanceid]), // Context trick needed here or passed
            'sesskey' => sesskey()
        ]);
        
        // Fix for courseid: The filter knows the course ID, we should ideally pass it. 
        // Assuming global $COURSE works here as filters run in request context.
        global $COURSE;
        $collecturl->param('courseid', $COURSE->id);

        $context = \context_block::instance($blockinstanceid);
        
        // Prepare Item Image
        // Fake item object for utils
        $fakeitem = (object)['id' => $data->itemid, 'image' => $data->image];
        $media = \block_playerhud\utils::get_item_display_data($fakeitem, $context);
        
        $label = $customtext ?: format_string($data->itemname);

        // --- RENDER MODES ---
        
        // A. TEXT MODE
        if ($mode == 'text') {
            if ($limitreached) return '<span class="text-success">✅ ' . $label . '</span>';
            if ($iscooldown) return '<span class="text-muted">⏳ ' . $label . '...</span>';
            return '<a href="' . $collecturl->out() . '" class="ph-action-collect text-primary" data-mode="text">' . $label . '</a>';
        }

        // B. IMAGE MODE
        if ($mode == 'image') {
            $imgHtml = $media['is_image'] 
                ? '<img src="' . $media['url'] . '" style="width:50px; height:50px; object-fit:contain;">'
                : '<span style="font-size:40px;">' . $media['content'] . '</span>';
            
            if ($limitreached) return '<div style="opacity:0.5; filter:grayscale(100%);">' . $imgHtml . '</div>';
            
            $style = $iscooldown ? 'opacity:0.6; cursor:wait;' : 'cursor:pointer; filter:drop-shadow(0 4px 2px rgba(0,0,0,0.1));';
            $action = $iscooldown ? '' : 'href="' . $collecturl->out() . '" class="ph-action-collect ph-hover-scale"';
            
            return '<a ' . $action . ' style="display:inline-block; transition:transform 0.2s; ' . $style . '" data-mode="image">' . $imgHtml . '</a>';
        }

        // C. CARD MODE (Default)
        $statusClass = $limitreached ? 'ph-owned' : ($iscooldown ? '' : 'ph-item-trigger');
        $btnHtml = '';

        if ($limitreached) {
            $btnHtml = '<button disabled class="btn btn-light btn-sm w-100 text-success border-success">✔ ' . get_string('collected', 'filter_playerhud') . '</button>';
        } else if ($iscooldown) {
            $btnHtml = '<button disabled class="btn btn-light btn-sm w-100 text-muted">⏳ <span class="ph-timer" data-deadline="' . $readytime . '">...</span></button>';
        } else {
            $btnHtml = '<a href="' . $collecturl->out() . '" class="btn btn-primary btn-sm w-100 ph-action-collect shadow-sm mt-2" data-mode="card">' . get_string('take', 'filter_playerhud') . '</a>';
        }

        $iconHtml = $media['is_image'] 
            ? '<img src="' . $media['url'] . '" style="max-width:100%; max-height:100%; object-fit:contain;">'
            : '<div style="font-size:2em;">' . $media['content'] . '</div>';

        return '
        <div class="playerhud-item-card card p-3 ' . $statusClass . '" style="width: 150px; display:inline-block; vertical-align:top; margin:5px; position: relative;">
            ' . ($count > 0 ? '<span class="badge bg-info text-dark rounded-pill position-absolute" style="top:5px; right:5px; font-size:0.7rem;">x' . $count . '</span>' : '') . '
            <div class="text-center mb-2" style="height: 50px; display: flex; align-items: center; justify-content: center;">' . $iconHtml . '</div>
            <strong class="text-center d-block mb-1 text-truncate">' . format_string($data->itemname) . '</strong>
            ' . $btnHtml . '
        </div>';
    }

    /**
     * Renderiza o Card de Troca.
     */
    public static function render_trade($tradeid, $blockinstanceid) {
        // Implementação similar ao do drop, mas buscando em {block_playerhud_trades}
        // Para brevidade, se a lógica for idêntica à do legado, apenas altere as tabelas SQL.
        return ''; // Placeholder se não usar trade no filtro por enquanto.
    }
}
