<?php
namespace filter_playerhud\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use templatable;
use renderer_base;
use moodle_url;
use context_block;
use html_writer;

class widget implements renderable, templatable {
    protected $instance;
    protected $courseid;

    public function __construct($instance, $courseid) {
        $this->instance = $instance;
        $this->courseid = $courseid;
    }

public function export_for_template(renderer_base $output) {
        global $USER, $DB, $CFG, $PAGE; // Adicionado $PAGE

        // 1. Get Player Data
        $player = $DB->get_record('block_playerhud_user', [
            'blockinstanceid' => $this->instance->id, 
            'userid' => $USER->id
        ]);

        // URL base da mochila
        $url_backpack = new moodle_url('/blocks/playerhud/view.php', ['id' => $this->courseid, 'instanceid' => $this->instance->id]);
        
        if (!$player || !$player->enable_gamification) {
            // LÓGICA DE REACTIVAÇÃO IMEDIATA
            // Criamos uma URL que já executa a ação de ativar e retorna para a página atual.
            
            $returnurl = $PAGE->url->out_as_local_url(false);
            
            $url_immediate_activate = new moodle_url('/blocks/playerhud/view.php', [
                'id' => $this->courseid,
                'instanceid' => $this->instance->id,
                'action' => 'toggle_hud',
                'state' => 1,
                'sesskey' => sesskey(),
                'returnurl' => $returnurl
            ]);

            return [
                'is_active' => false,
                'optin_url' => $url_immediate_activate->out(false),
                'optin_text' => get_string('click_to_enable', 'filter_playerhud')
            ];
        }

        // 2. Load Config & Stats
        $config = unserialize(base64_decode($this->instance->configdata));
        if (!$config) $config = new \stdClass();

        require_once($CFG->dirroot . '/blocks/playerhud/classes/game.php');
        require_once($CFG->dirroot . '/blocks/playerhud/classes/utils.php');

        $stats = \block_playerhud\game::get_game_stats($config, $this->instance->id, $player->currentxp);
        $xp_total_game = isset($stats['total_game_xp']) ? $stats['total_game_xp'] : 0;
        
        // Internationalization for XP display
        $str_xp = get_string('currentxp', 'filter_playerhud'); // Ensure this string is "XP" or similar
        $xp_display = $player->currentxp . ' / ' . $xp_total_game . ' ' . $str_xp;
        
        if ($player->currentxp >= $xp_total_game && $xp_total_game > 0) {
            $xp_display .= ' 🏆';
        }

        // 3. Items Logic
        $recentitems = [];
        $rawinventory = \block_playerhud\game::get_inventory($USER->id, $this->instance->id);
        $count = 0;
        $seen = [];
        $context = context_block::instance($this->instance->id);

        foreach ($rawinventory as $invitem) {
            if ($count >= 6) break;
            if (in_array($invitem->id, $seen)) continue;
            $seen[] = $invitem->id;

            $media = \block_playerhud\utils::get_item_display_data($invitem, $context);
            $image_payload = $media['is_image'] ? $media['url'] : strip_tags($media['content']);

            $recentitems[] = [
                'name' => format_string($invitem->name),
                'xp' => $invitem->xp . ' ' . $str_xp,
                'image' => $image_payload, 
                'isimage' => $media['is_image'] ? 1 : 0,
                'content' => $image_payload, 
                'description' => !empty($invitem->description) ? format_text($invitem->description, FORMAT_HTML) : '',
                'date' => userdate($invitem->collecteddate, get_string('strftimedatefullshort', 'langconfig')),
                'timestamp' => $invitem->collecteddate
            ];
            $count++;
        }

        // 4. Ranking Logic
        $rank_data = null;
        $enable_ranking = isset($config->enable_ranking) ? $config->enable_ranking : 1;
        
        if ($enable_ranking) {
            $rank = \block_playerhud\game::get_user_rank($this->instance->id, $USER->id, $player->currentxp);
            $url_ranking = new moodle_url('/blocks/playerhud/view.php', [
                'id' => $this->courseid, 
                'instanceid' => $this->instance->id, 
                'tab' => 'ranking'
            ]);
            
            $rank_data = [
                'rank' => $rank,
                'url' => $url_ranking->out(false),
                'label' => get_string('view_ranking', 'filter_playerhud')
            ];
        }

        // 5. Actions & URLs
        $url_base = new moodle_url('/blocks/playerhud/view.php', ['id' => $this->courseid, 'instanceid' => $this->instance->id]);
        
        $actions = [
            'url_backpack' => $url_base->out(false),
            'url_story'    => (new moodle_url($url_base, ['tab' => 'chapters']))->out(false),
            'url_shop'     => (new moodle_url($url_base, ['tab' => 'trades']))->out(false),
            'url_quests'   => (new moodle_url($url_base, ['tab' => 'quests']))->out(false),
        ];

        // NOVO: URL de Desativação com retorno para o curso
        $url_disable = new moodle_url('/blocks/playerhud/view.php', [
            'id' => $this->courseid,
            'instanceid' => $this->instance->id,
            'action' => 'toggle_hud',
            'state' => 0,
            'sesskey' => sesskey(),
            'returnurl' => '/course/view.php?id=' . $this->courseid // Retorna para o curso
        ]);

        return [
            'is_active' => true,
            'userpicture' => $output->user_picture($USER, ['size' => 75]),
            'fullname' => fullname($USER),
            'level_class' => $stats['level_class'],
            'level_display' => $stats['level'] . '/' . $stats['max_levels'],
            'xp_display' => $xp_display,
            'progress' => $stats['progress'],
            'items' => $recentitems,
            'ranking' => $rank_data,
            // NOVOS DADOS PARA O BOTÃO SAIR
            'url_disable' => $url_disable->out(false),
            'str_disable_gamification' => get_string('disable_exit', 'block_playerhud'),
            'str_confirm_msg' => get_string('confirm_disable', 'block_playerhud'),
        ] + $actions; 
    }

    public function render() {
        global $OUTPUT;
        $data = $this->export_for_template($OUTPUT);
        
        if (empty($data['is_active'])) {
            if (isset($data['optin_url'])) {
                return html_writer::tag('div', 
                    html_writer::link($data['optin_url'], $data['optin_text'], ['class' => 'btn btn-primary']),
                    ['class' => 'text-center my-3']
                );
            }
            return '';
        }

        return $OUTPUT->render_from_template('filter_playerhud/widget', $data);
    }
}
