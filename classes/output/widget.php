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
        global $USER, $DB, $OUTPUT;

        // 1. Get Player Data
        // Nota: Usamos as classes do BLOCO agora.
        $player = $DB->get_record('block_playerhud_user', [
            'blockinstanceid' => $this->instance->id, 
            'userid' => $USER->id
        ]);

        if (!$player) {
            // Se o aluno nunca entrou, cria o registro silenciosamente ou mostra botão.
            // Para performance no filtro, mostramos botão de "Ativar".
            $url = new \moodle_url('/blocks/playerhud/view.php', ['id' => $this->courseid, 'instanceid' => $this->instance->id]);
            return \html_writer::tag('div', 
                \html_writer::link($url, get_string('click_to_enable', 'filter_playerhud'), ['class' => 'btn btn-primary']),
                ['class' => 'text-center my-3']
            );
        }

        if (!$player->enable_gamification) {
            return ''; // Opt-out
        }

        // 2. Calculate Stats
        $config = unserialize(base64_decode($this->instance->configdata));
        if (!$config) $config = new \stdClass();

        // Usamos a lógica do bloco
        require_once($GLOBALS['CFG']->dirroot . '/blocks/playerhud/classes/game.php');
        $stats = \block_playerhud\game::get_game_stats($config, $this->instance->id, $player->currentxp);

        // 3. Render HTML (Compact Version for Filter)
        $url_backpack = new \moodle_url('/blocks/playerhud/view.php', ['id' => $this->courseid, 'instanceid' => $this->instance->id]);
        $url_story = new \moodle_url('/blocks/playerhud/view.php', ['id' => $this->courseid, 'instanceid' => $this->instance->id, 'tab' => 'chapters']);

        // Avatar simples para o filtro (para não pesar)
        $avatar = $OUTPUT->user_picture($USER, ['size' => 50, 'class' => 'ph-base-avatar rounded-circle']);

        // HTML structure
        $html = '
        <div class="playerhud-widget-container tier-' . $stats['level_class'] . ' p-3 border rounded mb-3 bg-white shadow-sm">
            <div class="d-flex align-items-center">
                <div class="me-3">' . $avatar . '</div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong class="text-dark">' . fullname($USER) . '</strong>
                        <span class="badge bg-light text-dark border">Level ' . $stats['level'] . '</span>
                    </div>
                    <div class="progress" style="height: 10px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: ' . $stats['progress'] . '%;"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-1" style="font-size: 0.75rem;">
                        <span class="text-muted">' . $player->currentxp . ' XP</span>
                        <span class="text-muted">' . $stats['total_game_xp'] . ' Total</span>
                    </div>
                </div>
                <div class="ms-3 d-flex flex-column gap-1">
                    <a href="' . $url_backpack->out() . '" class="btn btn-sm btn-primary" title="' . get_string('openbackpack', 'filter_playerhud') . '">
                        🎒
                    </a>
                    <a href="' . $url_story->out() . '" class="btn btn-sm btn-outline-secondary" title="' . get_string('story_shortcut', 'filter_playerhud') . '">
                        📖
                    </a>
                </div>
            </div>
        </div>';

        return $html;
    }
}
