<?php
// O namespace diz ao Moodle onde este arquivo mora.
namespace filter_playerhud;

defined('MOODLE_INTERNAL') || die();

class text_filter extends \moodle_text_filter {
    
    public function filter($text, array $options = array()) {
        global $USER, $DB, $OUTPUT, $COURSE;

        // 1. Verificação rápida (Performance)
        if (strpos($text, '[PLAYERHUD_') === false) {
            return $text;
        }

        // 2. Segurança: Se não estiver logado ou for visitante, esconde os códigos
        if (!isloggedin() || isguestuser() || $COURSE->id == SITEID) {
            $text = str_replace('[PLAYERHUD_WIDGET]', '', $text);
            $text = preg_replace('/\[PLAYERHUD_ITEM id=\d+\]/', '', $text);
            return $text; 
        }

        // 3. Busca a instância do PlayerHUD neste curso
        $playerhud = $DB->get_record('playerhud', ['course' => $COURSE->id], '*', IGNORE_MULTIPLE);

        if (!$playerhud) {
            // Se o professor ainda não criou a atividade
            if (has_capability('moodle/course:update', \context_course::instance($COURSE->id))) {
                if (strpos($text, '[PLAYERHUD_WIDGET]') !== false) {
                    return '<div class="alert alert-warning">Aviso: Crie uma atividade PlayerHUD neste curso para ativar o Widget.</div>';
                }
            }
            return $text;
        }

        // Busca o ID do Módulo (necessário para criar links)
        $cm = get_coursemodule_from_instance('playerhud', $playerhud->id, $playerhud->course);


        // =========================================================
        // PARTE 1: O WIDGET (BARRA DE TOPO) [PLAYERHUD_WIDGET]
        // =========================================================
        if (strpos($text, '[PLAYERHUD_WIDGET]') !== false) {
            
            // Busca dados do Jogador
            $player = \mod_playerhud\game::get_player($playerhud->id, $USER->id);
            
            // --- CORREÇÃO AQUI: USA A NOVA FUNÇÃO get_game_stats ---
            $stats = \mod_playerhud\game::get_game_stats($playerhud->id, $player->currentxp);
            
            $current_xp = $player->currentxp;
            $level      = $stats['level'];
            $total_xp   = $stats['total_game_xp']; // Meta Total do Jogo
            $width      = $stats['progress'];      // Porcentagem (0-100)
            
            // Busca itens (limitado a 4)
            $items = \mod_playerhud\game::get_inventory($USER->id, $playerhud->id);
            $items = array_slice($items, 0, 4);
            
            // Constrói o HTML do Widget
            $html = '
            <div class="playerhud-widget-bar" style="background: #fff; padding: 15px; border-radius: 10px; border: 1px solid #dee2e6; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <div class="d-flex align-items-center flex-wrap gap-3">
                    
                    <div class="d-flex align-items-center mr-4">
                        ' . $OUTPUT->user_picture($USER, ['size' => 50]) . '
                        <div class="ml-2" style="margin-left: 10px;">
                            <div style="font-weight: bold; font-size: 1.1em;">' . fullname($USER) . '</div>
                            <div class="badge badge-pill badge-primary">Nível '.$level.'</div>
                        </div>
                    </div>

                    <div class="flex-grow-1" style="min-width: 200px;">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>XP Atual: <strong>'.$current_xp.'</strong></span>
                            <span>Meta do Curso: '.$total_xp.'</span>
                        </div>
                        <div class="progress" style="height: 12px; border-radius: 6px; background-color: #e9ecef;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: '.$width.'%;"></div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 pl-3" style="border-left: 1px solid #eee; padding-left: 15px;">
                        <div class="text-muted small mr-2">Itens:</div>';
            
            if ($items) {
                foreach ($items as $item) {
                    $icon = (strpos($item->image, 'http') === 0) 
                        ? '<img src="'.$item->image.'" style="width:30px; height:30px; object-fit:contain;" title="'.$item->name.'">' 
                        : '<span style="font-size:24px;" title="'.$item->name.'">'.$item->image.'</span>';
                    
                    $html .= '<div class="playerhud-mini-item" style="transition: transform 0.2s;">'.$icon.'</div>';
                }
            } else {
                $html .= '<span class="small text-muted">- Vazio -</span>';
            }

            $url = new \moodle_url('/mod/playerhud/view.php', ['id' => $cm->id]);

            $html .= '
                    </div>

                    <div class="ml-auto">
                        <a href="'.$url->out().'" class="btn btn-sm btn-outline-primary">
                            Abrir Mochila 🎒
                        </a>
                    </div>

                </div>
            </div>';

            $text = str_replace('[PLAYERHUD_WIDGET]', $html, $text);
        }


        // =========================================================
        // PARTE 2: ITENS COLETÁVEIS [PLAYERHUD_ITEM id=X]
        // =========================================================
        $pattern = '/\[PLAYERHUD_ITEM id=(\d+)\]/i';
        
        if (preg_match_all($pattern, $text, $matches)) {
            foreach ($matches[1] as $key => $itemid) {
                $fullcode = $matches[0][$key];
                
                $item = $DB->get_record('playerhud_items', ['id' => $itemid]);
                
                if (!$item) {
                    $text = str_replace($fullcode, '<span class="badge badge-danger">Item ID '.$itemid.' não encontrado</span>', $text);
                    continue;
                }

                $has_item = \mod_playerhud\game::has_item($USER->id, $itemid);

                if ($has_item) {
                    // JÁ TEM (Verde)
                    $replacement = '
                    <div class="playerhud-collect-card collected" style="border: 2px solid #28a745; background: #e8f5e9; padding: 15px; border-radius: 8px; display: inline-flex; align-items: center; gap: 15px; margin: 10px 0;">
                        <div style="font-size: 30px;">✅</div>
                        <div>
                            <strong>'.format_string($item->name).'</strong><br>
                            <small class="text-success">Coletado! (+'.$item->xp.' XP)</small>
                        </div>
                    </div>';
                } else {
                    // DISPONÍVEL (Botão)
                    $collecturl = new \moodle_url('/mod/playerhud/collect.php', ['id' => $cm->id, 'itemid' => $item->id]);
                    
                    $icon = (strpos($item->image, 'http') === 0) 
                        ? '<img src="'.$item->image.'" style="width:40px; height:40px; object-fit:contain;">' 
                        : '<span style="font-size:30px;">'.$item->image.'</span>';

                    $replacement = '
                    <div class="playerhud-collect-card available" style="border: 2px dashed #0f6cbf; background: #f8f9fa; padding: 15px; border-radius: 8px; display: inline-flex; align-items: center; gap: 15px; margin: 10px 0;">
                        <div>'.$icon.'</div>
                        <div>
                            <strong>'.format_string($item->name).'</strong><br>
                            <small class="text-muted">Item Disponível • '.$item->xp.' XP</small>
                        </div>
                        <a href="'.$collecturl->out().'" class="btn btn-primary btn-sm">🖐 Pegar Item</a>
                    </div>';
                }
                $text = str_replace($fullcode, $replacement, $text);
            }
        }

        return $text;
    }
}