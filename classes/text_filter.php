<?php
namespace filter_playerhud;

defined('MOODLE_INTERNAL') || die();

class text_filter extends \moodle_text_filter {
    
    protected static $course_huds = [];
    protected static $modal_injected = false; 

    public function filter($text, array $options = array()) {
        global $USER, $DB, $OUTPUT, $COURSE;

        // 1. Verificação Rápida
        if (strpos($text, '[PLAYERHUD_') === false) { return $text; }

        // 2. Verifica permissões e contexto
        if (!isloggedin() || isguestuser() || $COURSE->id == SITEID) {
            $text = str_replace('[PLAYERHUD_WIDGET]', '', $text);
            $text = preg_replace('/\[PLAYERHUD_DROP\s+[^\]]+\]/', '', $text);
            $text = preg_replace('/\[PLAYERHUD_TRADE\s+[^\]]+\]/', '', $text);
            return $text; 
        }

        // 3. Cache da Instância
        if (!isset(self::$course_huds[$COURSE->id])) {
            self::$course_huds[$COURSE->id] = $DB->get_record('playerhud', ['course' => $COURSE->id], '*', IGNORE_MULTIPLE);
        }
        $playerhud = self::$course_huds[$COURSE->id];
        
        if (!$playerhud) {
            $text = str_replace('[PLAYERHUD_WIDGET]', '', $text);
            return $text;
        }

        $cm = get_coursemodule_from_instance('playerhud', $playerhud->id, $playerhud->course);
        $player = \mod_playerhud\game::get_player($playerhud->id, $USER->id);
        
        // Verifica Opt-in
        $is_gamified = (!empty($player->enable_gamification) && $player->enable_gamification == 1);
        $course_context = \context_course::instance($COURSE->id);
        $is_teacher = has_capability('mod/playerhud:addinstance', $course_context);

        // CENÁRIO: USUÁRIO OFF (OPT-OUT)
        if (!$is_gamified) {
            if (strpos($text, '[PLAYERHUD_WIDGET]') !== false) {
                $url = new \moodle_url('/mod/playerhud/view.php', ['id' => $cm->id]);
                $simple_msg = \html_writer::tag('div', 
                    \html_writer::link($url, get_string('click_to_enable', 'filter_playerhud'), ['class' => 'btn btn-sm btn-light border shadow-sm']),
                    ['class' => 'text-center my-3']
                );
                $text = str_replace('[PLAYERHUD_WIDGET]', $simple_msg, $text);
            }
            $text = preg_replace('/\[PLAYERHUD_DROP\s+[^\]]+\]/', '', $text);
            $text = preg_replace('/\[PLAYERHUD_TRADE\s+[^\]]+\]/', '', $text);
            return $text;
        }

        // CENÁRIO: USUÁRIO ON
        $cm->context = \context_module::instance($cm->id);
        $needs_script = false;
        $now = time();

        // --- PARTE 1: O WIDGET ---
        if (strpos($text, '[PLAYERHUD_WIDGET]') !== false) {
             $stats = \mod_playerhud\game::get_game_stats($playerhud, $player->currentxp);             
             
             // Busca e Ordena Itens
             $sql_items = "SELECT i.* FROM {playerhud_items} i 
                           WHERE i.playerhudid = :pid AND i.enabled = 1 
                           AND EXISTS (SELECT 1 FROM {playerhud_drops} d WHERE d.itemid = i.id)
                           ORDER BY i.xp ASC";
             $all_items = $DB->get_records_sql($sql_items, ['pid' => $playerhud->id]);
             $raw_inventory = $DB->get_records('playerhud_inventory', ['userid' => $USER->id], 'timecreated DESC');
             
             $inventory_by_item = [];
             $last_collection_map = [];

             foreach ($raw_inventory as $inv) {
                 $inventory_by_item[$inv->itemid][] = $inv;
                 if (!isset($last_collection_map[$inv->itemid])) {
                     $last_collection_map[$inv->itemid] = $inv->timecreated;
                 }
             }

             // Ordenação
             $sorted_items = (array)$all_items;
             usort($sorted_items, function($a, $b) use ($last_collection_map) {
                 $time_a = isset($last_collection_map[$a->id]) ? $last_collection_map[$a->id] : 0;
                 $time_b = isset($last_collection_map[$b->id]) ? $last_collection_map[$b->id] : 0;
                 if ($time_a == $time_b) return ($a->xp < $b->xp) ? -1 : 1; 
                 return ($time_a > $time_b) ? -1 : 1;
             });

             // Monta HTML
             $widget_items_html = '';
             $items_shown = 0;
             $MAX_ITEMS_WIDGET = 4; 

             foreach ($sorted_items as $item) {
                if ($items_shown >= $MAX_ITEMS_WIDGET) break;
                
                $user_copies = isset($inventory_by_item[$item->id]) ? $inventory_by_item[$item->id] : [];
                $media_data = \mod_playerhud\utils::get_item_display_data($item, $cm->context);
                
                if (empty($user_copies)) {
                    // Item Faltante
                    $style = "width:30px; height:30px; object-fit:contain; filter: grayscale(100%); opacity: 0.4;";
                    $display = $media_data['is_image'] ? 
                        \html_writer::empty_tag('img', ['src' => $media_data['url'], 'style' => $style]) : 
                        \html_writer::span($media_data['content'], '', ['style' => "font-size:24px; $style"]);

                    $widget_items_html .= $this->render_mini_card(
                        $item, $display, format_string($item->name)." ".get_string('not_collected', 'mod_playerhud'), 
                        "ph-widget-trigger", $media_data, false, 0, "", ""
                    );
                    $items_shown++;
                } else {
                    // Item Coletado
                    $stack_finite = 0;
                    $stack_infinite = 0;
                    foreach ($user_copies as $copy) {
                        $is_infinite = false;
                        if ($copy->dropid > 0) {
                            $drop_info = $DB->get_record('playerhud_drops', ['id' => $copy->dropid]);
                            if ($drop_info && $drop_info->maxusage == 0) $is_infinite = true;
                        }
                        if ($is_infinite) $stack_infinite++; else $stack_finite++;
                    }

                    $style = "width:30px; height:30px; object-fit:contain;";
                    $display = $media_data['is_image'] ? 
                        \html_writer::empty_tag('img', ['src' => $media_data['url'], 'style' => $style]) : 
                        \html_writer::span($media_data['content'], '', ['style' => "font-size:24px; $style"]);

                    if ($stack_finite > 0 && $items_shown < $MAX_ITEMS_WIDGET) {
                        $widget_items_html .= $this->render_mini_card($item, $display, format_string($item->name), "ph-widget-trigger", $media_data, true, $stack_finite, "x{$stack_finite}", "");
                        $items_shown++;
                    }
                    if ($stack_infinite > 0 && $items_shown < $MAX_ITEMS_WIDGET) {
                        $widget_items_html .= $this->render_mini_card($item, $display, format_string($item->name)." (Infinito)", "ph-widget-trigger", $media_data, true, $stack_infinite, "x{$stack_infinite}", "(Infinito)");
                        $items_shown++;
                    }
                }
             }

             if (empty($widget_items_html)) $widget_items_html = \html_writer::span(get_string('empty', 'filter_playerhud'), 'small text-muted');
             $url = new \moodle_url('/mod/playerhud/view.php', ['id' => $cm->id]);
             
             // Ranking
             $rank_html = '';
             if (!empty($playerhud->enable_ranking) && $player->ranking_visibility == 1 && !$is_teacher) {
                 $my_rank = \mod_playerhud\game::get_user_rank($playerhud->id, $USER->id, $player->currentxp);
                 $rank_icon = ($my_rank <= 3) ? '🏆' : '#';
                 $rank_url = new \moodle_url('/mod/playerhud/view.php', ['id' => $cm->id, 'tab' => 'ranking']);
                 $rank_html = '<a href="'.$rank_url->out().'" class="badge badge-light border text-decoration-none ml-2" title="'.get_string('view_ranking', 'mod_playerhud').'" style="font-size: 0.9em;">'.$rank_icon.' '.$my_rank.'</a>';
             }

// ... (código anterior de cálculo de itens e stats continua igual) ...

             // 1. DADOS RPG (Busca Classe e Karma)
             $rpg_html = '';
             $avatar_html = $OUTPUT->user_picture($USER, ['size' => 50, 'class' => 'ph-base-avatar']); // Padrão
             $class_name = "";
             $karma_bar = "";
             
             // Busca progresso RPG
             $rpg_progress = $DB->get_record('playerhud_rpg_progress', ['userid' => $USER->id, 'playerhudid' => $playerhud->id]);
             
             if ($rpg_progress && $rpg_progress->classid > 0) {
                 $my_class = $DB->get_record('playerhud_classes', ['id' => $rpg_progress->classid]);
                 if ($my_class) {
                     // Busca imagem evolutiva
                     $evo_url = \mod_playerhud\utils::get_class_evolution_image($my_class, $stats['level'], $cm->context);
                     
                     if ($evo_url) {
                         // Se tiver imagem de classe, ela sobrepõe ou fica ao lado
                         // Design Decisão: Mostrar a Imagem da Classe GRANDE e a foto do aluno pequena no canto
                         $avatar_html = '
                         <div class="ph-rpg-avatar-wrapper">
                            <div class="ph-class-portrait" style="background-image: url('.$evo_url.');"></div>
                            <div class="ph-user-mini">'.$OUTPUT->user_picture($USER, ['size' => 30]).'</div>
                         </div>';
                     }
                     
                     $class_name = '<div class="ph-class-badge" style="background-color: var(--ph-tier-color);">'.format_string($my_class->name).'</div>';
                 }
                 
                 // Barra de Karma (Visualização simples: -100 a +100)
                 // Normalizamos para % (0 a 100, onde 50 é neutro)
                 // Assumindo range -50 a +50 para simplificar visualização
                 $karma_percent = 50 + ($rpg_progress->karma); 
                 $karma_percent = max(0, min(100, $karma_percent)); // Clamp 0-100
                 
                 $karma_color = ($rpg_progress->karma >= 0) ? '#4fd6fa' : '#dc3545'; // Azul (Luz) ou Vermelho (Trevas)
                 $karma_icon = ($rpg_progress->karma >= 0) ? '✨' : '🔥';
                 
                 $karma_bar = '
                 <div class="ph-karma-container" title="Destino / Karma: '.$rpg_progress->karma.'">
                    <div class="ph-karma-track">
                        <div class="ph-karma-fill" style="width: '.$karma_percent.'%; background-color: '.$karma_color.';"></div>
                    </div>
                    <div class="ph-karma-icon" style="left: '.$karma_percent.'%;">'.$karma_icon.'</div>
                 </div>';
             } else {
                 // Sem classe (Ainda não escolheu)
                 $class_name = '<div class="badge badge-secondary">Novato</div>';
             }

             // URL da Mochila
             $url = new \moodle_url('/mod/playerhud/view.php', ['id' => $cm->id]);

             // URL da História
            $url_story = new \moodle_url('/mod/playerhud/view.php', ['id' => $cm->id, 'tab' => 'chapters']);

             // --- HTML FINAL (LAYOUT DE 2 ANDARES) ---
             $html = '
            <div class="playerhud-widget-container tier-'.$stats['level_class'].'">
                
                <div class="ph-hud-top-row">
                    <div class="ph-hud-xp-info">
                        <span class="ph-hud-level">LVL <strong>'.$stats['level'].'</strong></span>
                        <div class="ph-hud-xp-bar">
                            <div class="ph-hud-xp-fill" style="width: '.$stats['progress'].'%;"></div>
                        </div>
                        <span class="ph-hud-xp-text">'.$player->currentxp.' / '.$stats['total_game_xp'].'</span>
                    </div>
                    <div class="ph-hud-shortcuts">
                        '.$rank_html.'
                        <a href="'.$url->out().'" class="ph-btn-backpack" title="'.get_string('openbackpack', 'filter_playerhud').'">🎒</a>
                        <a href="'.$url_story->out().'" class="ph-btn-backpack mr-1" title="'.get_string('story_shortcut', 'mod_playerhud').'">📖</a>
                    </div>
                </div>

                <div class="ph-hud-main-row">
                    
                    <div class="ph-hud-portrait-area">
                        '.$avatar_html.'
                    </div>

                    <div class="ph-hud-info-area">
                        <div class="ph-player-name">'.fullname($USER).'</div>
                        '.$class_name.'
                        '.$karma_bar.'
                    </div>

                    <div class="ph-hud-items-area">
                        '.$widget_items_html.'
                    </div>
                </div>
            </div>';

             $text = str_replace('[PLAYERHUD_WIDGET]', $html, $text);
             $needs_script = true;
        }

        // --- PARTE 2: BOTÕES DE DROP ---
        $pattern = '/\[PLAYERHUD_DROP\s+([^\]]+)\]/i';
        if (preg_match_all($pattern, $text, $matches)) {
            $needs_script = true;
            foreach ($matches[1] as $key => $attributes_str) {
                $fullcode = $matches[0][$key];
                
                $attrs = [];
                if (preg_match('/id=(\d+)/i', $attributes_str, $m)) $attrs['id'] = $m[1];
                if (preg_match('/mode=([a-z]+)/i', $attributes_str, $m)) $attrs['mode'] = strtolower($m[1]);
                if (preg_match('/text=["\']?([^"\']*)["\']?/i', $attributes_str, $m)) $attrs['text'] = $m[1];
                
                if (empty($attrs['id'])) continue;
                $dropid = (int)$attrs['id'];
                $mode = isset($attrs['mode']) ? $attrs['mode'] : 'card';
                $custom_text = isset($attrs['text']) ? $attrs['text'] : null;

                $drop = $DB->get_record('playerhud_drops', ['id' => $dropid]);
                if (!$drop) { $text = str_replace($fullcode, '', $text); continue; }

                $item = $DB->get_record('playerhud_items', ['id' => $drop->itemid]);
                if (!$item || !$item->enabled) { $text = str_replace($fullcode, '', $text); continue; }

                $inventory = $DB->get_records('playerhud_inventory', ['userid' => $USER->id, 'dropid' => $drop->id], 'timecreated DESC');
                $count = count($inventory);
                $last_collected = $inventory ? reset($inventory) : null;
                $limit_reached = ($drop->maxusage > 0 && $count >= $drop->maxusage);
                $seconds_wait = $drop->respawntime;
                $ready_time = $last_collected ? ($last_collected->timecreated + $seconds_wait) : 0;
                $is_cooldown = ($last_collected && $now < $ready_time);

                $collecturl = new \moodle_url('/mod/playerhud/collect.php', ['id' => $cm->id, 'dropid' => $drop->id, 'sesskey' => sesskey()]);
                $media_data = \mod_playerhud\utils::get_item_display_data($item, $cm->context);
                $display_label = $custom_text ? format_string($custom_text) : format_string($item->name);

                $html_output = '';

                if ($mode == 'text') {
                    if ($limit_reached) {
                        $html_output = '<span class="text-success" style="cursor:default;">✅ ' . $display_label . '</span>';
                    } elseif ($is_cooldown) {
                        $html_output = '<span class="text-muted" style="cursor:wait;" title="Aguarde...">⏳ ' . $display_label . ' <small class="ph-timer" data-deadline="'.$ready_time.'">...</small></span>';
                    } else {
                        $html_output = '<a href="'.$collecturl->out().'" class="ph-action-collect text-primary" data-mode="text">'.$display_label.'</a>';
                    }
                } elseif ($mode == 'image') {
                    $img_src = $media_data['is_image'] ? $media_data['url'] : '';
                    $emoji   = $media_data['is_image'] ? '' : $media_data['content'];
                    $style = "transition: transform 0.2s; display:inline-block; cursor:pointer;";
                    $timer_html = '';

                    if ($limit_reached) {
                        $style .= "opacity: 0.5; filter: grayscale(100%); cursor: default;";
                        $title = get_string('collected', 'filter_playerhud');
                    } elseif ($is_cooldown) {
                        $style .= "opacity: 0.7; cursor: wait;";
                        $title = get_string('wait', 'filter_playerhud');
                        $timer_html = '<div class="small text-muted text-center ph-timer" style="font-size:10px; line-height:1;" data-deadline="'.$ready_time.'">...</div>';
                    } else {
                        $style .= "filter: drop-shadow(0 4px 2px rgba(0,0,0,0.1));";
                        $title = get_string('take', 'filter_playerhud') . " " . $display_label;
                    }

                    $content = $media_data['is_image'] ? '<img src="'.$img_src.'" style="width:50px; height:50px; object-fit:contain;">' : '<span style="font-size:40px;">'.$emoji.'</span>';

                    if (!$limit_reached && !$is_cooldown) {
                        $html_output = '<a href="'.$collecturl->out().'" class="ph-action-collect ph-hover-scale" style="'.$style.'" title="'.$title.'" data-mode="image">'.$content.'</a>';
                    } else {
                        $html_output = '<div style="display:inline-block; text-align:center;"><div style="'.$style.'" title="'.$title.'">'.$content.'</div>'.$timer_html.'</div>';
                    }
                } else {
                    // MODO CARD PADRONIZADO
                    $display_xp = ($drop->maxusage == 0) ? get_string('infinite', 'filter_playerhud') : "+".$item->xp." XP";
                    $display_icon = $media_data['is_image'] ? 
                        '<img src="'.$media_data['url'].'" style="max-width: 100%; max-height: 100%; object-fit: contain;">' : 
                        '<div class="emoji-display" style="font-size: 2em;">'.$media_data['content'].'</div>';
                    $counter_badge = ($count > 0) ? '<span class="badge badge-info badge-pill position-absolute" style="top:5px; right:5px; font-size:0.7rem;">'.get_string('yours', 'filter_playerhud', $count).'</span>' : '';

                    if ($limit_reached) {
                        $card_status_class = "ph-owned"; 
                        $status_msg = '<div class="mt-2 text-center small font-weight-bold text-success" style="font-size: 0.7rem;"><i class="fa fa-check-circle"></i> '.get_string('collected', 'filter_playerhud').'</div>';
                        $action_btn = '<button disabled class="btn btn-light btn-sm btn-block text-success border-success" style="font-size:0.7rem;">✔</button>';
                    } elseif ($is_cooldown) {
                        $card_status_class = "";
                        $status_msg = '<div class="mt-2 text-center small font-weight-bold text-warning" style="font-size: 0.7rem;">'.get_string('wait', 'filter_playerhud').' <span class="ph-timer" data-deadline="'.$ready_time.'">...</span></div>';
                        $action_btn = '<button disabled class="btn btn-light btn-sm btn-block text-muted" style="font-size:0.7rem;">⏳</button>';
                    } else {
                        $card_status_class = "ph-item-trigger"; 
                        $status_msg = '<div class="text-center"><small class="text-muted font-weight-bold">'.$display_xp.'</small></div>';
                        $action_btn = '<a href="'.$collecturl->out().'" class="btn btn-primary btn-sm btn-block ph-action-collect shadow-sm mt-2" data-mode="card" style="font-size:0.75rem;">'.get_string('take', 'filter_playerhud').'</a>';
                    }

                    $html_output = '
                    <div class="playerhud-item-card card p-3 '.$card_status_class.'" style="width: 150px; display:inline-block; vertical-align:top; margin:5px; position: relative;">
                        '.$counter_badge.'
                        <div class="playerhud-icon-container text-center mb-2" style="height: 50px; display: flex; align-items: center; justify-content: center;">'.$display_icon.'</div>
                        <strong class="text-center d-block mb-1 text-truncate" style="line-height: 1.2;">'.format_string($item->name).'</strong>
                        '.$status_msg.'
                        '.($limit_reached ? '' : $action_btn).'
                    </div>';
                }
                $text = str_replace($fullcode, $html_output, $text);
            }
        }

        // --- PARTE 3: CARTÃO DE TROCA (QUE EU TINHA ESQUECIDO) ---
        // Refatorado para usar o estilo novo (ph-trade-card)
        $pattern_trade = '/\[PLAYERHUD_TRADE id=(\d+)\]/i';
        if (preg_match_all($pattern_trade, $text, $matches_trade)) {
            foreach ($matches_trade[1] as $key => $tradeid) {
                $fullcode = $matches_trade[0][$key];
                $trade = $DB->get_record('playerhud_trades', ['id' => $tradeid]);
                if (!$trade) { $text = str_replace($fullcode, '', $text); continue; }

                if ($trade->groupid != 0) {
                    if ($trade->groupid > 0) {
                        if (!groups_is_member($trade->groupid, $USER->id)) { $text = str_replace($fullcode, '', $text); continue; }
                    } else {
                        $groupingid = abs($trade->groupid);
                        $user_groups = groups_get_all_groups($COURSE->id, $USER->id, $groupingid);
                        if (empty($user_groups)) { $text = str_replace($fullcode, '', $text); continue; }
                    }
                }

                $sql_req = "SELECT r.*, i.name, i.image FROM {playerhud_trade_requirements} r JOIN {playerhud_items} i ON r.itemid = i.id WHERE r.tradeid = :tid";
                $reqs = $DB->get_records_sql($sql_req, ['tid' => $trade->id]);
                $sql_rew = "SELECT r.*, i.name, i.image, i.xp FROM {playerhud_trade_rewards} r JOIN {playerhud_items} i ON r.itemid = i.id WHERE r.tradeid = :tid";
                $rews = $DB->get_records_sql($sql_rew, ['tid' => $trade->id]);

                $html_req = '';
                $can_afford = true;
                foreach ($reqs as $r) {
                    $media = \mod_playerhud\utils::get_item_display_data((object)['id'=>$r->itemid, 'image'=>$r->image], $cm->context);
                    $icon = $media['is_image'] ? "<img src='{$media['url']}' style='width:24px;height:24px;object-fit:contain;'>" : "<span style='font-size:20px;'>{$media['content']}</span>";
                    
                    // Verifica saldo na hora
                    $user_has = $DB->count_records('playerhud_inventory', ['userid'=>$USER->id, 'itemid'=>$r->itemid]);
                    if($user_has < $r->qty) $can_afford = false;
                    $status_cls = ($user_has >= $r->qty) ? 'text-muted' : 'text-danger font-weight-bold';

                    $html_req .= "<div class='ph-trade-item-row $status_cls'><div class='mr-2'>$icon</div><div>{$r->qty}x {$r->name} <small>({$user_has}/{$r->qty})</small></div></div>";
                }

                $html_rew = '';
                foreach ($rews as $r) {
                    $media = \mod_playerhud\utils::get_item_display_data((object)['id'=>$r->itemid, 'image'=>$r->image], $cm->context);
                    $icon = $media['is_image'] ? "<img src='{$media['url']}' style='width:24px;height:24px;object-fit:contain;'>" : "<span style='font-size:20px;'>{$media['content']}</span>";
                    $html_rew .= "<div class='ph-trade-item-row text-dark'><div class='mr-2'>$icon</div><div><strong>{$r->qty}x {$r->name}</strong></div></div>";
                }

                $process_url = new \moodle_url('/mod/playerhud/process_trade.php', ['id' => $cm->id, 'tradeid' => $trade->id, 'sesskey' => sesskey()]);
                $is_completed = ($trade->onetime && $DB->record_exists('playerhud_trade_log', ['tradeid'=>$trade->id, 'userid'=>$USER->id]));

                if ($is_completed) {
                    $btn = '<button class="btn btn-secondary btn-sm btn-block" disabled><i class="fa fa-check"></i> '.get_string('trade_redeemed', 'mod_playerhud').'</button>';
                } elseif ($can_afford) {
                    $btn = '<a href="'.$process_url->out().'" class="btn btn-success btn-sm btn-block shadow-sm" onclick="return confirm(\''.get_string('trade_confirm', 'mod_playerhud').'\');"><i class="fa fa-exchange"></i> '.get_string('trade_perform', 'mod_playerhud').'</a>';
                } else {
                    $btn = '<button class="btn btn-light btn-sm btn-block text-muted" disabled style="cursor:not-allowed;"><i class="fa fa-lock"></i> '.get_string('trade_insufficient', 'mod_playerhud').'</button>';
                }

                $disabled_cls = (!$can_afford && !$is_completed) ? 'ph-trade-disabled' : '';

                $card_html = '
                <div class="ph-trade-card '.$disabled_cls.'" style="max-width: 400px;">
                    <div class="p-2 bg-light border-bottom font-weight-bold">
                       ⚖️ '.format_string($trade->name).'
                    </div>
                    <div class="ph-trade-body">
                        <div class="ph-trade-section ph-trade-req">
                            <small class="text-uppercase text-danger font-weight-bold mb-2" style="font-size:0.65rem;">'.get_string('shop_pay', 'mod_playerhud').'</small>
                            '.$html_req.'
                        </div>
                        <div class="ph-trade-arrow text-muted"><i class="fa fa-arrow-right"></i></div>
                        <div class="ph-trade-section ph-trade-give">
                            <small class="text-uppercase text-success font-weight-bold mb-2" style="font-size:0.65rem;">'.get_string('shop_receive', 'mod_playerhud').'</small>
                            '.$html_rew.'
                        </div>
                    </div>
                    <div class="card-footer bg-white border-0 pt-0 text-center">'.$btn.'</div>
                </div>';
                $text = str_replace($fullcode, $card_html, $text);
            }
        }

        // --- PARTE 4: INJEÇÃO DE SCRIPT ---
        if ($needs_script) {
            if (!self::$modal_injected) {
                $text .= $this->get_modal_html();
                $text .= $this->get_story_modal_html();
                self::$modal_injected = true;
            }
            if (strpos($text, 'ph-super-script') === false) {
                $text .= $this->get_javascript_footer();
            }
        }

        return $text;
    }

    // Auxiliares (Modal e JS)
    private function render_mini_card($item, $display_html, $tooltip, $trigger_class, $media_data, $has_it, $count, $count_label, $infinite_text) {
        $badge = ($has_it && $count > 0) ? \html_writer::span($count_label, 'badge badge-light border position-absolute', ['style' => 'bottom: -8px; right: -8px; font-size: 0.65rem; padding: 2px 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);']) : "";
        $desc = !empty($item->description) ? format_text($item->description, FORMAT_HTML) : "";
        $attrs = [
            'class' => 'playerhud-mini-item position-relative ' . $trigger_class,
            'style' => 'transition: transform 0.2s; margin-right: 12px; cursor: pointer;',
            'title' => $tooltip,
            'data-name' => format_string($item->name),
            'data-xp' => ($infinite_text ? "0 XP" : "+{$item->xp} XP"),
            'data-image' => $media_data['is_image'] ? $media_data['url'] : $media_data['content'],
            'data-isimage' => $media_data['is_image'],
            'data-count' => $count,
            'data-infinite' => $infinite_text
        ];
        $hidden_desc = \html_writer::div($desc, 'd-none ph-item-description-content');
        return \html_writer::div($display_html . $badge . $hidden_desc, null, $attrs);
    }

    private function get_modal_html() {
        return '<div class="modal fade" id="phItemModalFilter" tabindex="-1" role="dialog" aria-hidden="true" style="z-index: 10500;"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content"><div class="modal-header d-flex justify-content-between align-items-center"><h5 class="modal-title font-weight-bold m-0" id="phModalTitleF">'.get_string('details', 'mod_playerhud').'</h5><button type="button" class="close ph-modal-close-f" aria-label="Close" style="margin-left: auto;"><span aria-hidden="true">&times;</span></button></div><div class="modal-body"><div class="d-flex align-items-start"><div id="phModalImageContainerF" class="mr-4 text-center" style="min-width: 100px;"></div><div class="flex-grow-1"><div class="d-flex align-items-center flex-wrap mb-3"><h4 id="phModalNameF" class="m-0 mr-2" style="font-weight: bold;">Nome</h4><span id="phModalCountBadgeF" class="badge badge-primary badge-pill mr-1" style="font-size: 0.9em; display:none;">x0</span><span id="phModalXPF" class="badge badge-info" style="font-size: 0.9em;">XP</span></div><div id="phModalDescF" class="text-muted text-break"></div></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary ph-modal-close-f">'.get_string('close', 'mod_playerhud').'</button></div></div></div></div>';
    }

    private function get_story_modal_html() {
        return '
        <div class="modal fade" id="phStoryModal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" role="document">
                <div class="modal-content" style="border:none; border-radius:15px; overflow:hidden;">
                    
                    <div class="modal-header bg-dark text-white" style="border-bottom: 4px solid #d4af37;">
                        <h5 class="modal-title font-weight-bold" id="phStoryTitle"><i class="fa fa-book mr-2"></i> História</h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="modal-body p-5" style="background: #fffbf2; font-family: \'Georgia\', serif; font-size: 1.1rem; line-height: 1.8; color: #2c2c2c;">
                        <div id="phStoryContent" class="animate__animated animate__fadeIn">
                            <div class="text-center text-muted py-5"><i class="fa fa-circle-o-notch fa-spin fa-3x"></i><br>Carregando...</div>
                        </div>
                    </div>

                    <div class="modal-footer justify-content-center bg-light" id="phStoryChoices" style="border-top: 1px solid #e9ecef; min-height: 80px;">
                        </div>

                </div>
            </div>
        </div>';
    }

    private function get_javascript_footer() {
        // Concatenando para facilitar a leitura e manutenção
        $js = '<style>
                .ph-hover-scale:hover { transform: scale(1.1); } 
                .ph-loading { opacity: 0.5; pointer-events: none; cursor: wait !important; }
               </style>';
        
        $swal_url = new \moodle_url('/mod/playerhud/js/sweetalert2.all.min.js');
        $js .= '<script src="' . $swal_url->out() . '"></script>';
        
        $js .= '<script id="ph-super-script">
        document.addEventListener("DOMContentLoaded", function() { 
            
            // --- 1. CONFIGURAÇÃO GERAL E TOASTS ---
            const Toast = Swal.mixin({ 
                toast: true, position: "top-end", showConfirmButton: false, timer: 2000, timerProgressBar: true, 
                didOpen: (toast) => { toast.addEventListener("mouseenter", Swal.stopTimer); toast.addEventListener("mouseleave", Swal.resumeTimer); } 
            }); 

            // --- 2. TIMERS (Contagem Regressiva dos Drops) ---
            setInterval(function() { 
                var now = Math.floor(Date.now() / 1000); 
                document.querySelectorAll(".ph-timer").forEach(function(el) { 
                    var deadline = parseInt(el.getAttribute("data-deadline")); 
                    var diff = deadline - now; 
                    if (diff <= 0) { 
                        el.innerHTML = "'.get_string('ready', 'filter_playerhud').'"; 
                        if (!el.getAttribute("data-reloading")) { 
                            el.setAttribute("data-reloading", "true"); 
                            setTimeout(function(){ location.reload(); }, 1500); 
                        } 
                    } else { 
                        var minutes = Math.floor((diff % 3600) / 60); 
                        var seconds = diff % 60; 
                        el.innerHTML = minutes + "m " + (seconds < 10 ? "0" : "") + seconds + "s"; 
                    } 
                }); 
            }, 1000); 

            // --- 3. LÓGICA DE COLETA (AJAX) ---
            document.body.addEventListener("click", function(e) { 
                var trigger = e.target.closest(".ph-action-collect"); 
                if (trigger) { 
                    e.preventDefault(); 
                    var mode = trigger.getAttribute("data-mode"); 
                    var originalContent = trigger.innerHTML; 
                    var url = trigger.getAttribute("href") + "&ajax=1"; 
                    trigger.classList.add("ph-loading"); 
                    
                    if (mode === "text") { trigger.innerHTML = "⏳ ..."; } 
                    else if (mode === "card") { trigger.innerHTML = "⏳"; } 
                    
                    fetch(url).then(response => response.json()).then(data => { 
                        if (data.success) { 
                            Toast.fire({ icon: "success", title: data.message }); 
                            if (mode === "text") { 
                                trigger.innerHTML = "✅ " + data.message; 
                                trigger.style.color = "green"; 
                            } 
                            setTimeout(function() { location.reload(); }, 2000); 
                        } else { 
                            Toast.fire({ icon: "warning", title: data.message }); 
                            trigger.innerHTML = originalContent; 
                            trigger.classList.remove("ph-loading"); 
                        } 
                    }).catch(err => { 
                        console.error(err); 
                        window.location.href = trigger.getAttribute("href"); 
                    }); 
                } 
            }); 

            // --- 4. MODAL DE ITEM (Detalhes) ---
            var openModal = function(trigger) { 
                var name = trigger.getAttribute("data-name"); 
                var infiniteTxt = trigger.getAttribute("data-infinite"); 
                var xp = trigger.getAttribute("data-xp"); 
                var img = trigger.getAttribute("data-image"); 
                var isImg = trigger.getAttribute("data-isimage"); 
                var count = trigger.getAttribute("data-count"); 
                var descDiv = trigger.querySelector(".ph-item-description-content"); 
                var descHtml = descDiv ? descDiv.innerHTML : ""; 
                
                var title = document.getElementById("phModalTitleF"); 
                var nameEl = document.getElementById("phModalNameF"); 
                var xpEl = document.getElementById("phModalXPF"); 
                var descEl = document.getElementById("phModalDescF"); 
                var badgeEl = document.getElementById("phModalCountBadgeF"); 
                var imgContainer = document.getElementById("phModalImageContainerF"); 
                
                if(title) title.innerText = name; 
                if(nameEl) { 
                    nameEl.innerHTML = name; 
                    if(infiniteTxt) nameEl.innerHTML += "<br><small class=\'text-muted\' style=\'font-weight:normal;\'>"+infiniteTxt+"</small>"; 
                } 
                if(xpEl) xpEl.innerText = xp; 
                if(descEl) { 
                    if (descHtml && descHtml.trim() !== "") descEl.innerHTML = descHtml; 
                    else descEl.innerHTML = "<i class=\'text-muted\'> - </i>"; 
                } 
                if(badgeEl) { 
                    if (count && count > 0) { 
                        badgeEl.innerText = "x" + count; 
                        badgeEl.style.display = "inline-block"; 
                    } else { badgeEl.style.display = "none"; } 
                } 
                if(imgContainer) { 
                    imgContainer.innerHTML = ""; 
                    if (isImg == "1" || isImg == "true") { 
                        imgContainer.innerHTML = "<img src=\'"+img+"\' style=\'max-width: 120px; max-height: 120px; object-fit:contain;\'>"; 
                    } else { 
                        imgContainer.innerHTML = "<span style=\'font-size: 80px;\'>"+img+"</span>"; 
                    } 
                } 
                if (typeof jQuery !== "undefined") { jQuery("#phItemModalFilter").modal("show"); } 
            }; 

            document.body.addEventListener("click", function(e) { 
                var target = e.target.closest(".ph-widget-trigger"); 
                if (target) { 
                    e.preventDefault(); 
                    openModal(target); 
                } 
                if (e.target.closest(".ph-modal-close-f")) { 
                    e.preventDefault(); 
                    if (typeof jQuery !== "undefined") { jQuery("#phItemModalFilter").modal("hide"); } 
                } 
            });

            // --- 5. MODAL DE HISTÓRIA (RPG) [NOVO] ---
            
            // Função para carregar cenas via AJAX
            var loadScene = function(cmId, action, params) {
                var contentDiv = document.getElementById("phStoryContent");
                var choicesDiv = document.getElementById("phStoryChoices");
                
                if (!contentDiv) return;

                // Estado de carregamento
                choicesDiv.innerHTML = "";
                contentDiv.style.opacity = 0.5;

                // URL base
                var url = M.cfg.wwwroot + "/mod/playerhud/ajax_story.php?id=" + cmId + "&action=" + action;
                
                // Adiciona parâmetros
                for (var key in params) {
                    url += "&" + key + "=" + params[key];
                }

                fetch(url)
                    .then(response => response.json())
                    .then(data => {
                        contentDiv.style.opacity = 1;
                        
                        if (data.error) {
                            contentDiv.innerHTML = "<div class=\'alert alert-danger\'>"+data.error+"</div>";
                            return;
                        }

                        if (data.class_updated) {
                            // Feedback visual antes do reload
                            Toast.fire({ icon: "success", title: "Classe Escolhida!" });
                            setTimeout(function() { location.reload(); }, 1500);
                            return; // Para a execução para recarregar
                        }

                        if (data.finished) {
                            contentDiv.innerHTML = "<div class=\'text-center py-5\'><h2 class=\'text-success\'>🎉</h2><h3>"+data.message+"</h3></div>";
                            choicesDiv.innerHTML = "<button type=\'button\' class=\'btn btn-secondary\' data-dismiss=\'modal\'>Fechar</button>";
                            return;
                        }

                        // Renderiza Conteúdo
                        contentDiv.innerHTML = data.node.content;
                        contentDiv.className = "animate__animated animate__fadeIn"; // Re-aplica animação

                        // Renderiza Botões
                        if (data.node.choices && data.node.choices.length > 0) {
                            data.node.choices.forEach(function(ch) {
                                var btn = document.createElement("button");
                                btn.className = "btn " + ch.class + " m-1 px-4 py-2";
                                btn.innerHTML = ch.text;
                                if (ch.disabled) btn.disabled = true;
                                
                                btn.onclick = function() {
                                    loadScene(cmId, "make_choice", { choiceid: ch.id, sesskey: params.sesskey });
                                };
                                choicesDiv.appendChild(btn);
                            });
                        } else {
                            choicesDiv.innerHTML = "<button type=\'button\' class=\'btn btn-secondary\' data-dismiss=\'modal\'>Fechar</button>";
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        contentDiv.innerHTML = "<div class=\'text-danger\'>Erro de conexão.</div>";
                    });
            };

            // Escuta o clique nos botões de capítulo (.ph-chapter-trigger)
            document.body.addEventListener("click", function(e) {
                var trigger = e.target.closest(".ph-chapter-trigger");
                if (trigger) {
                    e.preventDefault();
                    var chapterId = trigger.getAttribute("data-chapterid");
                    var cmId = trigger.getAttribute("data-cmid");
                    
                    // Pega Sesskey do Moodle
                    var sessKey = (typeof M !== "undefined" && M.cfg && M.cfg.sesskey) ? M.cfg.sesskey : "";
                    
                    if (typeof jQuery !== "undefined") { 
                        jQuery("#phStoryModal").modal("show"); 
                        loadScene(cmId, "load_start", { chapterid: chapterId, sesskey: sessKey });
                    }
                }
            });

        });
        </script>';
        
        return $js;
    }
}