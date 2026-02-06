<?php
namespace filter_playerhud\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use templatable;
use renderer_base;

class widget implements renderable, templatable {
    protected $instance;
    protected $courseid;

    public function __construct($instance, $courseid) {
        $this->instance = $instance;
        $this->courseid = $courseid;
    }

    /**
     * Export data for the Mustache template.
     */
    public function export_for_template(renderer_base $output) {
        global $USER, $DB, $CFG;

        // 1. Get Player Data
        $player = $DB->get_record('block_playerhud_user', [
            'blockinstanceid' => $this->instance->id, 
            'userid' => $USER->id
        ]);

        $url_backpack = new \moodle_url('/blocks/playerhud/view.php', ['id' => $this->courseid, 'instanceid' => $this->instance->id]);
        
        // Opt-in check (simples: se não existir ou desativado, retorna nulo ou link de ativação)
        // Nota: O template deve lidar se não houver dados, ou retornamos um template diferente para optin.
        // Aqui assumimos que o filtro só mostra se ativo, ou mostra botão de ativação.
        if (!$player || !$player->enable_gamification) {
            return [
                'is_active' => false,
                'optin_url' => $url_backpack->out(),
                'optin_text' => get_string('click_to_enable', 'filter_playerhud')
            ];
        }

        // 2. Stats
        $config = unserialize(base64_decode($this->instance->configdata));
        if (!$config) $config = new \stdClass();

        require_once($CFG->dirroot . '/blocks/playerhud/classes/game.php');
        require_once($CFG->dirroot . '/blocks/playerhud/classes/utils.php');

        $stats = \block_playerhud\game::get_game_stats($config, $this->instance->id, $player->currentxp);
        $xp_total_game = isset($stats['total_game_xp']) ? $stats['total_game_xp'] : 0;
        
        $xp_display = $player->currentxp . ' / ' . $xp_total_game . ' XP';
        if ($player->currentxp >= $xp_total_game && $xp_total_game > 0) {
            $xp_display .= ' 🏆';
        }

        // 3. Items
        $recentitems = [];
        $rawinventory = \block_playerhud\game::get_inventory($USER->id, $this->instance->id);
        $count = 0;
        $seen = [];
        $context = \context_block::instance($this->instance->id);

        foreach ($rawinventory as $invitem) {
            if ($count >= 6) break; // Limite visual
            if (in_array($invitem->id, $seen)) continue;
            $seen[] = $invitem->id;

            $media = \block_playerhud\utils::get_item_display_data($invitem, $context);
            
            // CORREÇÃO: 'image' deve conter o payload (URL ou Emoji) para o data-attribute do JS
            $image_payload = $media['is_image'] ? $media['url'] : strip_tags($media['content']);

            $recentitems[] = [
                'name' => format_string($invitem->name),
                'xp' => '+' . $invitem->xp . ' XP',
                
                // Variáveis para o JS (Data Attributes)
                'image' => $image_payload, 
                'isimage' => $media['is_image'] ? 1 : 0, // Força 1 ou 0 para o JS entender
                
                // Variável para exibição visual no template
                'content' => $image_payload, 
                
                'description' => !empty($invitem->description) ? format_text($invitem->description, FORMAT_HTML) : '',
                'date' => userdate($invitem->collecteddate, get_string('strftimedatefullshort', 'langconfig'))
            ];
            $count++;
        }

        return [
            'is_active' => true,
            'userpicture' => $output->user_picture($USER, ['size' => 75]),
            'fullname' => fullname($USER),
            'level_class' => $stats['level_class'],
            'level_display' => $stats['level'] . '/' . $stats['max_levels'],
            'xp_display' => $xp_display,
            'progress' => $stats['progress'],
            'items' => $recentitems,
            // CORREÇÃO: Usar out(false) para evitar duplo escape no Mustache
            'url_backpack' => $url_backpack->out(false),
            'url_story' => (new \moodle_url('/blocks/playerhud/view.php', ['id' => $this->courseid, 'instanceid' => $this->instance->id, 'tab' => 'chapters']))->out(false)
        ];
    }

    /**
     * Legacy render method called by text_filter.
     * Delegates to template rendering.
     */
    public function render() {
        global $PAGE, $OUTPUT;
        $data = $this->export_for_template($OUTPUT);
        
        // Se não ativo, podemos renderizar um HTML simples de botão aqui ou um template separado.
        if (empty($data['is_active'])) {
            // Fallback simples para o botão de ativar
            if (isset($data['optin_url'])) {
                return \html_writer::tag('div', 
                    \html_writer::link($data['optin_url'], $data['optin_text'], ['class' => 'btn btn-primary']),
                    ['class' => 'text-center my-3']
                );
            }
            return '';
        }

        return $OUTPUT->render_from_template('filter_playerhud/widget', $data);
    }
}