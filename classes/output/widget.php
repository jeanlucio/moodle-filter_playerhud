<?php
namespace filter_playerhud\output;

defined('MOODLE_INTERNAL') || die();

class widget {
    protected $instance;
    protected $courseid;

    public function __construct($instance, $courseid) {
        $this->instance = $instance;
        $this->courseid = $courseid;
    }

public function render() {
        global $USER, $DB, $OUTPUT, $CFG;

        // 1. Get Player Data
        $player = $DB->get_record('block_playerhud_user', [
            'blockinstanceid' => $this->instance->id, 
            'userid' => $USER->id
        ]);

        if (!$player) {
            $url = new \moodle_url('/blocks/playerhud/view.php', ['id' => $this->courseid, 'instanceid' => $this->instance->id]);
            return \html_writer::tag('div', 
                \html_writer::link($url, get_string('click_to_enable', 'filter_playerhud'), ['class' => 'btn btn-primary']),
                ['class' => 'text-center my-3']
            );
        }

        if (!$player->enable_gamification) {
            return ''; // Opt-out
        }

        // 2. Load Config & Stats
        $config = unserialize(base64_decode($this->instance->configdata));
        if (!$config) $config = new \stdClass();

        require_once($CFG->dirroot . '/blocks/playerhud/classes/game.php');
        require_once($CFG->dirroot . '/blocks/playerhud/classes/utils.php');
        require_once($CFG->dirroot . '/blocks/playerhud/lib.php');

        $stats = \block_playerhud\game::get_game_stats($config, $this->instance->id, $player->currentxp);

       // --- CÁLCULO CORRIGIDO: XP Atual / Total Geral do Jogo ---
        $xp_total_game = isset($stats['total_game_xp']) ? $stats['total_game_xp'] : 0;
        
        // Aqui mantemos o " XP" pois o widget constrói o HTML manualmente
        $xp_display = $player->currentxp . ' / ' . $xp_total_game . ' XP';

        // Lógica do Troféu
        if ($player->currentxp >= $xp_total_game && $xp_total_game > 0) {
            $xp_display .= ' 🏆';
        }
        
        $level_display = $stats['level'] . '/' . $stats['max_levels'];
        // -------------------------------

        // 3. Fetch Recent Items
        $recentitems = [];
        $rawinventory = \block_playerhud\game::get_inventory($USER->id, $this->instance->id);
        
        $limit = 5; 
        $count = 0;
        $seen_items = [];
        $context = \context_block::instance($this->instance->id);

        foreach ($rawinventory as $invitem) {
            if ($count >= $limit) break;
            if (in_array($invitem->id, $seen_items)) continue;
            
            $seen_items[] = $invitem->id;

            $media = \block_playerhud\utils::get_item_display_data($invitem, $context);
            
            $recentitems[] = (object)[
                'name' => format_string($invitem->name),
                'xp' => '+' . $invitem->xp . ' XP',
                'image' => $media['is_image'] ? $media['url'] : strip_tags($media['content']),
                'isimage' => $media['is_image'],
                'content' => $media['content'],
                'description' => htmlspecialchars($invitem->description ?? ''),
                'date' => userdate($invitem->collecteddate, get_string('strftimedatefullshort', 'langconfig'))
            ];
            $count++;
        }

        // 4. Build HTML
        $url_backpack = new \moodle_url('/blocks/playerhud/view.php', ['id' => $this->courseid, 'instanceid' => $this->instance->id]);
        $url_story = new \moodle_url('/blocks/playerhud/view.php', ['id' => $this->courseid, 'instanceid' => $this->instance->id, 'tab' => 'chapters']);
        
        $avatar = $OUTPUT->user_picture($USER, ['size' => 75, 'class' => 'ph-base-avatar rounded-circle border border-2 border-white shadow-sm']);

        $strlevel = get_string('level', 'filter_playerhud');
        $stropen = get_string('openbackpack', 'filter_playerhud');
        $strstory = get_string('story_shortcut', 'filter_playerhud');

        // Render Items HTML
        $items_html = '<div class="d-flex flex-wrap gap-1 mt-2 ph-widget-stash" style="min-height: 34px;">';
        
        if (!empty($recentitems)) {
            foreach ($recentitems as $item) {
                $content = $item->isimage 
                    ? '<img src="' . $item->image . '" alt="" style="width: 100%; height: 100%; object-fit: contain;">' 
                    : '<span class="ph-mini-emoji" aria-hidden="true" style="font-size:1.2rem; line-height: 1;">' . $item->content . '</span>';
                
                // NOTA: Adicionada classe 'ph-item-trigger' para o JS capturar o clique
                // Adicionado tabindex="0" e role="button" para acessibilidade
                $items_html .= '
                <div class="ph-mini-item ph-item-trigger border bg-white rounded d-flex align-items-center justify-content-center overflow-hidden position-relative shadow-sm" 
                     role="button" 
                     tabindex="0"
                     style="width:34px; height:34px; min-width:34px;"
                     title="' . $item->name . '"
                     aria-label="' . get_string('details', 'block_playerhud') . ': ' . $item->name . '"
                     data-name="' . $item->name . '"
                     data-xp="' . $item->xp . '"
                     data-image="' . $item->image . '"
                     data-isimage="' . ($item->isimage ? 1 : 0) . '"
                     data-date="' . $item->date . '">
                     <div class="d-none ph-item-description-content">' . $item->description . '</div>
                     ' . $content . '
                </div>';
            }
        } else {
            $items_html .= '<span class="small text-muted align-self-center" style="font-size:0.7rem;">' . get_string('items', 'filter_playerhud') . ' ' . get_string('empty', 'filter_playerhud') . '</span>';
        }
        $items_html .= '</div>';

        $html = '
        <div class="playerhud-widget-container rounded mb-4 shadow-sm overflow-hidden d-flex align-items-stretch position-relative">
            
            <div class="p-3 bg-light d-flex align-items-center justify-content-center border-end" style="min-width: 110px;">
                ' . $avatar . '
            </div>

            <div class="p-3 flex-grow-1 d-flex flex-column justify-content-center">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="d-flex align-items-center">
                        <h4 class="m-0 me-2 fw-bold text-dark">' . fullname($USER) . '</h4>
                        <span class="badge ' . $stats['level_class'] . ' border shadow-sm px-2">' . $strlevel . ' ' . $level_display . '</span>
                    </div>
                    <div class="small text-muted fw-bold">' . $xp_display . '</div>
                </div>

                <div class="progress" style="height: 10px; background-color: rgba(0,0,0,0.05); border-radius: 5px;">
                    <div class="progress-bar ' . $stats['level_class'] . '" role="progressbar" 
                         style="width: ' . $stats['progress'] . '%;" 
                         aria-valuenow="' . $stats['progress'] . '" aria-valuemin="0" aria-valuemax="100">
                         <span class="visually-hidden">' . $stats['progress'] . '% Complete</span>
                    </div>
                </div>

                ' . $items_html . '
            </div>

            <div class="p-2 border-start bg-light d-flex flex-column justify-content-center gap-2" style="min-width: 70px;">
                <a href="' . $url_backpack->out() . '" 
                   class="btn btn-primary btn-sm d-flex align-items-center justify-content-center shadow-sm" 
                   style="width: 45px; height: 45px; border-radius: 10px;"
                   data-bs-toggle="tooltip" 
                   data-bs-placement="left" 
                   title="' . $stropen . '"
                   aria-label="' . $stropen . '">
                    <span aria-hidden="true" style="font-size:1.4rem;">🎒</span>
                </a>
                
                <a href="' . $url_story->out() . '" 
                   class="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center shadow-sm bg-white" 
                   style="width: 45px; height: 45px; border-radius: 10px;"
                   data-bs-toggle="tooltip" 
                   data-bs-placement="left" 
                   title="' . $strstory . '"
                   aria-label="' . $strstory . '">
                    <span aria-hidden="true" style="font-size:1.4rem;">📖</span>
                </a>
            </div>
        </div>
        
        <script>
        require(["jquery", "theme_boost/tooltip"], function($, Tooltip) {
            $("[data-bs-toggle=\'tooltip\']").tooltip();
        });
        </script>
        ';

        $html = str_replace('playerhud-widget-container', 'playerhud-widget-container ' . $stats['level_class'], $html);

        return $html;
    }
}
