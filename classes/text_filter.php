<?php
namespace filter_playerhud;

defined('MOODLE_INTERNAL') || die();

class text_filter extends \moodle_text_filter {
    
    protected static $course_huds = [];
    protected static $modal_injected = false; // Controle para não duplicar o modal na página

    public function filter($text, array $options = array()) {
        global $USER, $DB, $OUTPUT, $COURSE;

        // 1. Verificação Rápida
        if (strpos($text, '[PLAYERHUD_') === false) { return $text; }

        // 2. Verifica permissões
        if (!isloggedin() || isguestuser() || $COURSE->id == SITEID) {
            $text = str_replace('[PLAYERHUD_WIDGET]', '', $text);
            $text = preg_replace('/\[PLAYERHUD_DROP id=\d+\]/', '', $text);
            return $text; 
        }

        // 3. CACHE DO HUD
        if (!isset(self::$course_huds[$COURSE->id])) {
            self::$course_huds[$COURSE->id] = $DB->get_record('playerhud', ['course' => $COURSE->id], '*', IGNORE_MULTIPLE);
        }
        $playerhud = self::$course_huds[$COURSE->id];
        if (!$playerhud) return $text;

        $cm = get_coursemodule_from_instance('playerhud', $playerhud->id, $playerhud->course);
        $cm->context = \context_module::instance($cm->id);
        
        $needs_script = false;
        $now = time();

        // =========================================================
        // PARTE 1: O WIDGET (Barra de Status + Mochila Miniatura)
        // =========================================================
        if (strpos($text, '[PLAYERHUD_WIDGET]') !== false) {
             
             // Dados do Jogador
             $player = \mod_playerhud\game::get_player($playerhud->id, $USER->id);
             $stats = \mod_playerhud\game::get_game_stats($playerhud->id, $player->currentxp);
             
             // --- LÓGICA DE ITENS (Igual ao view.php) ---
             // 1. Busca todos os itens ATIVOS e COM DROPS
             $sql_items = "SELECT i.* FROM {playerhud_items} i 
                           WHERE i.playerhudid = :pid AND i.enabled = 1 
                           AND EXISTS (SELECT 1 FROM {playerhud_drops} d WHERE d.itemid = i.id)
                           ORDER BY i.xp ASC";
             $all_items = $DB->get_records_sql($sql_items, ['pid' => $playerhud->id]);

             // 2. Busca Inventário do Usuário
             $raw_inventory = $DB->get_records('playerhud_inventory', ['userid' => $USER->id]);
             $inventory_by_item = [];
             foreach ($raw_inventory as $inv) {
                 $inventory_by_item[$inv->itemid][] = $inv;
             }

             // 3. Monta a lista visual (Cards)
             $widget_items_html = '';
             $items_shown = 0;
             $MAX_ITEMS_WIDGET = 4; // Mostrar até 4 itens no topo para não quebrar layout

             foreach ($all_items as $item) {
                if ($items_shown >= $MAX_ITEMS_WIDGET) break;

                $user_copies = isset($inventory_by_item[$item->id]) ? $inventory_by_item[$item->id] : [];
                $media_data = \mod_playerhud\utils::get_item_display_data($item, $cm->context);

                // Se NÃO TEM (Desenha cinza)
                if (empty($user_copies)) {
                    if ($item->secret == 1) {
                         // Secreto escondido
                         $display = '<span style="font-size:24px; opacity:0.3;">❓</span>';
                         $tooltip = "???";
                         $trigger_class = ""; // Não clicável
                    } else {
                         // Item faltante
                         $style = "width:30px; height:30px; object-fit:contain; filter: grayscale(100%); opacity: 0.4;";
                         if ($media_data['is_image']) {
                             $display = \html_writer::empty_tag('img', ['src' => $media_data['url'], 'style' => $style]);
                         } else {
                             $display = \html_writer::span($media_data['content'], '', ['style' => "font-size:24px; $style"]);
                         }
                         $tooltip = format_string($item->name);
                         $trigger_class = "ph-widget-trigger"; // Clicável para ver o que falta
                    }
                    
                    // Renderiza CARD VAZIO
                    $widget_items_html .= $this->render_mini_card($item, $display, $tooltip, $trigger_class, $media_data, false, 0, "", "");
                    $items_shown++;

                } else {
                    // SE TEM (Separa Finito e Infinito)
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

                    // Renderiza Finito
                    if ($stack_finite > 0) {
                        $style = "width:30px; height:30px; object-fit:contain;";
                        if ($media_data['is_image']) {
                             $display = \html_writer::empty_tag('img', ['src' => $media_data['url'], 'style' => $style]);
                        } else {
                             $display = \html_writer::span($media_data['content'], '', ['style' => "font-size:24px; $style"]);
                        }
                        
                        // Busca total possível para mostrar "1/5" se quiser, ou apenas "x1"
                        $total_possible = $DB->get_field_sql("SELECT SUM(maxusage) FROM {playerhud_drops} WHERE itemid = ?", [$item->id]);
                        $badge_label = $total_possible ? "{$stack_finite}/{$total_possible}" : "x{$stack_finite}";

                        $widget_items_html .= $this->render_mini_card($item, $display, format_string($item->name), "ph-widget-trigger", $media_data, true, $stack_finite, $badge_label, "");
                        $items_shown++;
                    }

                    // Renderiza Infinito
                    if ($stack_infinite > 0 && $items_shown < $MAX_ITEMS_WIDGET) {
                        $style = "width:30px; height:30px; object-fit:contain;";
                        if ($media_data['is_image']) {
                             $display = \html_writer::empty_tag('img', ['src' => $media_data['url'], 'style' => $style]);
                        } else {
                             $display = \html_writer::span($media_data['content'], '', ['style' => "font-size:24px; $style"]);
                        }
                        
                        $widget_items_html .= $this->render_mini_card($item, $display, format_string($item->name)." (Infinito)", "ph-widget-trigger", $media_data, true, $stack_infinite, "x{$stack_infinite}", "(Infinito)");
                        $items_shown++;
                    }
                }
             }

             if (empty($widget_items_html)) $widget_items_html = \html_writer::span(get_string('empty', 'filter_playerhud'), 'small text-muted');
             
             $url = new \moodle_url('/mod/playerhud/view.php', ['id' => $cm->id]);
             
             // HTML DO WIDGET
             $html = '
            <div class="playerhud-widget-bar" style="background: #fff; padding: 15px; border-radius: 10px; border: 1px solid #dee2e6; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <div class="d-flex align-items-center flex-wrap gap-3">
                    <div class="d-flex align-items-center mr-4">' .
                        $OUTPUT->user_picture($USER, ['size' => 50]) . '
                        <div class="ml-2" style="margin-left: 10px;">
                            <div style="font-weight: bold; font-size: 1.1em;">' . fullname($USER) . '</div>
                            <div class="badge badge-pill badge-primary">'.get_string('level', 'filter_playerhud').' '.$stats['level'].'</div>
                        </div>
                    </div>
                    <div class="flex-grow-1" style="min-width: 200px;">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>'.get_string('currentxp', 'filter_playerhud').': <strong>'.$player->currentxp.'</strong></span>
                            <span>'.get_string('coursegoal', 'filter_playerhud').': '.$stats['total_game_xp'].'</span>
                        </div>
                        <div class="progress" style="height: 12px; border-radius: 6px; background-color: #e9ecef;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: '.$stats['progress'].'%;"></div>
                        </div>
                    </div>
            
                    <div class="d-flex align-items-center gap-2 pl-3" style="border-left: 1px solid #eee; padding-left: 15px;">
                        <div class="text-muted small mr-2">'.get_string('items', 'filter_playerhud').'</div>' .
                        $widget_items_html . 
                    '</div>
                    <div class="ml-auto"><a href="'.$url->out().'" class="btn btn-sm btn-outline-primary">'.get_string('openbackpack', 'filter_playerhud').'</a></div>
                </div>
            </div>';
            
            $text = str_replace('[PLAYERHUD_WIDGET]', $html, $text);
            
            // Ativa o script do Modal
            $needs_script = true;
        }

        // =========================================================
        // PARTE 2: BOTÕES DE DROP
        // =========================================================
        $pattern = '/\[PLAYERHUD_DROP id=(\d+)\]/i';
        if (preg_match_all($pattern, $text, $matches)) {
            $needs_script = true;
            foreach ($matches[1] as $key => $dropid) {
                $fullcode = $matches[0][$key];
                
                $drop = $DB->get_record('playerhud_drops', ['id' => $dropid]);
                if (!$drop) { $text = str_replace($fullcode, '', $text); continue; }

                $item = $DB->get_record('playerhud_items', ['id' => $drop->itemid]);
                if (!$item || !$item->enabled) { $text = str_replace($fullcode, '', $text); continue; }

                $inventory = $DB->get_records('playerhud_inventory', ['userid' => $USER->id, 'dropid' => $drop->id], 'timecreated DESC');
                $count = count($inventory);
                $last_collected = $inventory ? reset($inventory) : null;
                
                // Dados visuais
                $media_data = \mod_playerhud\utils::get_item_display_data($item, $cm->context);
                $display_name = format_string($item->name);
                $display_xp = ($drop->maxusage == 0) ? get_string('infinite', 'filter_playerhud') : "+".$item->xp." XP";
                
                if ($media_data['is_image']) {
                    $display_icon = '<img src="'.$media_data['url'].'" style="width:40px; height:40px; object-fit:contain;">';
                } else {
                    $display_icon = '<span style="font-size:30px;">'.$media_data['content'].'</span>';
                }

                // Regras
                $limit_reached = ($drop->maxusage > 0 && $count >= $drop->maxusage);
                $seconds_wait = $drop->respawntime;
                $ready_time = $last_collected ? ($last_collected->timecreated + $seconds_wait) : 0;
                $is_cooldown = ($last_collected && $now < $ready_time);
                
                $card_style = "padding: 15px; border-radius: 8px; display: inline-flex; align-items: center; gap: 15px; margin: 10px 0;";

                if ($limit_reached) {
                    $status_html = '<div class="playerhud-collect-card collected" style="border: 2px solid #28a745; background: #e8f5e9; '.$card_style.'"><div style="font-size: 30px;">✅</div><div><strong>'.$display_name.'</strong> <span class="badge badge-success badge-pill">'.$count.'</span><br><small class="text-success">'.get_string('collected', 'filter_playerhud').'</small></div></div>';
                } elseif ($is_cooldown) {
                    // COOLDOWN (Amarelo)
                    $status_html = '<div class="playerhud-collect-card cooldown" style="border: 2px solid #ffc107; background: #fff3cd; '.$card_style.'"><div style="font-size: 30px; opacity: 0.5;">⏳</div><div><strong>'.$display_name.'</strong> <span class="badge badge-secondary badge-pill">'.$count.'</span><br><small class="text-muted">'.get_string('wait', 'filter_playerhud').' <strong class="ph-timer" data-deadline="'.$ready_time.'">...</strong></small></div></div>';
                } else {
                    // DISPONÍVEL (Botão Ativo)
                    $collecturl = new \moodle_url('/mod/playerhud/collect.php', ['id' => $cm->id, 'dropid' => $drop->id, 'sesskey' => sesskey()]);
                    $counter_badge = ($count > 0) ? '<span class="badge badge-info badge-pill">'.get_string('yours', 'filter_playerhud', $count).'</span>' : '';
                    $status_html = '<div class="playerhud-collect-card available" id="ph-drop-'.$drop->id.'" style="border: 2px dashed #0f6cbf; background: #f8f9fa; '.$card_style.'"><div>'.$display_icon.'</div><div><strong>'.$display_name.'</strong> '.$counter_badge.'<br><small class="text-muted">'.$display_xp.'</small></div><a href="'.$collecturl->out().'" class="btn btn-primary btn-sm ml-3 ph-collect-btn" data-dropid="'.$drop->id.'">'.get_string('take', 'filter_playerhud').'</a></div>';
                }
                $text = str_replace($fullcode, $status_html, $text);
            }
        }

        // =========================================================
        // PARTE 2.5: CARTÃO DE TROCA (SHOP SHORTCODE)
        // =========================================================
        $pattern_trade = '/\[PLAYERHUD_TRADE id=(\d+)\]/i';
        if (preg_match_all($pattern_trade, $text, $matches_trade)) {
            
            foreach ($matches_trade[1] as $key => $tradeid) {
                $fullcode = $matches_trade[0][$key];
                
                // 1. Busca a Troca
                $trade = $DB->get_record('playerhud_trades', ['id' => $tradeid]);
                if (!$trade) { 
                    $text = str_replace($fullcode, '', $text); 
                    continue; 
                }

                // 2. Verificações de Grupo
                if ($trade->groupid > 0) {
                    if (!groups_is_member($trade->groupid, $USER->id)) {
                        // Se não é do grupo, esconde
                        $text = str_replace($fullcode, '', $text); 
                        continue;
                    }
                }

                // 3. Busca Requisitos (Paga)
                $sql_req = "SELECT r.*, i.name, i.image 
                              FROM {playerhud_trade_requirements} r
                              JOIN {playerhud_items} i ON r.itemid = i.id
                             WHERE r.tradeid = :tid";
                $reqs = $DB->get_records_sql($sql_req, ['tid' => $trade->id]);

                // 4. Busca Recompensas (Recebe)
                $sql_rew = "SELECT r.*, i.name, i.image, i.xp
                              FROM {playerhud_trade_rewards} r
                              JOIN {playerhud_items} i ON r.itemid = i.id
                             WHERE r.tradeid = :tid";
                $rews = $DB->get_records_sql($sql_rew, ['tid' => $trade->id]);

                // --- MONTAGEM DO HTML ---
                
                // HTML dos Requisitos
                $html_req = '';
                foreach ($reqs as $r) {
                    $media = \mod_playerhud\utils::get_item_display_data((object)['id'=>$r->itemid, 'image'=>$r->image], $cm->context);
                    $icon = $media['is_image'] ? 
                        "<img src='{$media['url']}' style='width:24px;height:24px;object-fit:contain;'>" : 
                        "<span style='font-size:20px;'>{$media['content']}</span>";
                    $html_req .= "<div class='mr-2 mb-1 text-center' style='line-height:1; display:inline-block;'>$icon<br><small>{$r->qty}x</small></div>";
                }

                // HTML das Recompensas
                $html_rew = '';
                foreach ($rews as $r) {
                    $media = \mod_playerhud\utils::get_item_display_data((object)['id'=>$r->itemid, 'image'=>$r->image], $cm->context);
                    $icon = $media['is_image'] ? 
                        "<img src='{$media['url']}' style='width:24px;height:24px;object-fit:contain;'>" : 
                        "<span style='font-size:20px;'>{$media['content']}</span>";
                    $html_rew .= "<div class='mr-2 mb-1 text-center' style='line-height:1; display:inline-block;'>$icon<br><small>{$r->qty}x</small></div>";
                }

                // Botão de Ação
                $process_url = new \moodle_url('/mod/playerhud/process_trade.php', ['id' => $cm->id, 'tradeid' => $trade->id, 'sesskey' => sesskey()]);
                
                // Verifica se já completou (se for única)
                $is_completed = false;
                if ($trade->onetime && $DB->record_exists('playerhud_trade_log', ['tradeid'=>$trade->id, 'userid'=>$USER->id])) {
                    $is_completed = true;
                }

                if ($is_completed) {
                    $btn = '<button class="btn btn-secondary btn-sm btn-block" disabled>✅ Já resgatado</button>';
                } else {
                    $btn = '<a href="'.$process_url->out().'" class="btn btn-primary btn-sm btn-block shadow-sm" onclick="return confirm(\'Confirmar troca?\');">🤝 Realizar Troca</a>';
                }

                // O CARD FINAL
                $card_html = '
                <div class="playerhud-trade-shortcode card shadow-sm mb-3" style="max-width: 400px; border: 1px solid #dee2e6;">
                    <div class="card-header bg-white font-weight-bold py-2">
                        ⚖️ '.format_string($trade->name).'
                    </div>
                    <div class="card-body p-2">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="text-center" style="width: 45%;">
                                <div class="text-danger small font-weight-bold text-uppercase mb-1">Paga</div>
                                '.$html_req.'
                            </div>
                            <div class="text-muted"><i class="fa fa-chevron-right"></i></div>
                            <div class="text-center" style="width: 45%;">
                                <div class="text-success small font-weight-bold text-uppercase mb-1">Recebe</div>
                                '.$html_rew.'
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-light p-2">
                        '.$btn.'
                    </div>
                </div>';

                $text = str_replace($fullcode, $card_html, $text);
            }
        }

        // =========================================================
        // PARTE 3: INJEÇÃO DO SCRIPT E MODAL (GLOBAL)
        // =========================================================
        if ($needs_script) {
            // Só adiciona o modal se ainda não foi adicionado nesta página request
            if (!self::$modal_injected) {
                $text .= $this->get_modal_html();
                self::$modal_injected = true;
            }
            if (strpos($text, 'ph-super-script') === false) {
                $text .= $this->get_javascript_footer();
            }
        }

        return $text;
    }

    // Função Auxiliar para renderizar ícone do Widget
    private function render_mini_card($item, $display_html, $tooltip, $trigger_class, $media_data, $has_it, $count, $count_label, $infinite_text) {
        
        $badge = "";
        if ($has_it && $count > 0) {
            $badge = \html_writer::span($count_label, 'badge badge-light border position-absolute', 
                ['style' => 'bottom: -8px; right: -8px; font-size: 0.65rem; padding: 2px 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);']);
        }

        // Prepara dados para o Modal (data-attributes)
        $desc = "";
        if (!empty($item->description)) {
            // Limpa o texto para evitar quebra de HTML no atributo
            $desc = format_text($item->description, FORMAT_HTML);
        }
        
        // Atributos
        $attrs = [
            'class' => 'playerhud-mini-item position-relative ' . $trigger_class,
            'style' => 'transition: transform 0.2s; margin-right: 12px; cursor: pointer;',
            'title' => $tooltip,
            'data-name' => format_string($item->name), // Nome limpo
            'data-xp' => ($infinite_text ? "0 XP" : "+{$item->xp} XP"),
            'data-image' => $media_data['is_image'] ? $media_data['url'] : $media_data['content'],
            'data-isimage' => $media_data['is_image'],
            'data-count' => $count,
            'data-infinite' => $infinite_text // Passa texto "(Infinito)" se existir
        ];

        // Hack para passar HTML complexo: div oculta dentro do card
        $hidden_desc = \html_writer::div($desc, 'd-none ph-item-description-content');

        return \html_writer::div($display_html . $badge . $hidden_desc, null, $attrs);
    }

    // HTML do Modal (Cópia simplificada do Mustache)
    private function get_modal_html() {
        return '
        <div class="modal fade" id="phItemModalFilter" tabindex="-1" role="dialog" aria-hidden="true" style="z-index: 10500;">
          <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
              <div class="modal-header d-flex justify-content-between align-items-center">
                <h5 class="modal-title font-weight-bold m-0" id="phModalTitleF">Detalhes</h5>
                <button type="button" class="close ph-modal-close-f" aria-label="Close" style="margin-left: auto;">
                  <span aria-hidden="true">&times;</span>
                </button>
              </div>
              <div class="modal-body">
                <div class="d-flex align-items-start">
                    <div id="phModalImageContainerF" class="mr-4 text-center" style="min-width: 100px;"></div>
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center flex-wrap mb-3">
                            <h4 id="phModalNameF" class="m-0 mr-2" style="font-weight: bold;">Nome</h4>
                            <span id="phModalCountBadgeF" class="badge badge-primary badge-pill mr-1" style="font-size: 0.9em; display:none;">x0</span>
                            <span id="phModalXPF" class="badge badge-info" style="font-size: 0.9em;">XP</span>
                        </div>
                        <div id="phModalDescF" class="text-muted text-break"></div>
                    </div>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary ph-modal-close-f">Fechar</button>
              </div>
            </div>
          </div>
        </div>';
    }

    private function get_javascript_footer() {
        return '
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script id="ph-super-script">
        document.addEventListener("DOMContentLoaded", function() {
            
            const Toast = Swal.mixin({
                toast: true, position: "top-end", showConfirmButton: false, timer: 3000, timerProgressBar: true,
                didOpen: (toast) => { toast.addEventListener("mouseenter", Swal.stopTimer); toast.addEventListener("mouseleave", Swal.resumeTimer); }
            });

            // 1. TIMER E AUTO-RELOAD
            setInterval(function() {
                var now = Math.floor(Date.now() / 1000);
                document.querySelectorAll(".ph-timer").forEach(function(el) {
                    var deadline = parseInt(el.getAttribute("data-deadline"));
                    var diff = deadline - now;
                    if (diff <= 0) {
                        el.innerHTML = "'.get_string('ready', 'filter_playerhud').'";
                        el.style.color = "green";
                        // AUTO-RELOAD QUANDO PRONTO!
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

            // 2. COLETA
            document.body.addEventListener("click", function(e) {
                if (e.target && e.target.classList.contains("ph-collect-btn")) {
                    e.preventDefault(); 
                    var btn = e.target;
                    var originalText = btn.innerHTML;
                    var url = btn.getAttribute("href") + "&ajax=1";
                    
                    btn.innerHTML = "⏳ ..."; 
                    btn.style.opacity = "0.7";

                    fetch(url).then(response => response.json()).then(data => {
                        if (data.success) {
                            Toast.fire({ icon: "success", title: data.message }).then(() => { location.reload(); });
                        } else {
                            Toast.fire({ icon: "warning", title: data.message });
                            btn.innerHTML = originalText; 
                            btn.style.opacity = "1";
                        }
                    }).catch(err => { 
                        console.error(err);
                        window.location.href = btn.getAttribute("href"); 
                    });
                }
            });

            // 3. ABRIR MODAL (WIDGET)
            // Usamos jQuery se disponível (padrão Moodle) ou Vanilla JS
            var openModal = function(trigger) {
                var name = trigger.getAttribute("data-name");
                var infiniteTxt = trigger.getAttribute("data-infinite"); // Texto (Infinito)
                var xp = trigger.getAttribute("data-xp");
                var img = trigger.getAttribute("data-image");
                var isImg = trigger.getAttribute("data-isimage");
                var count = trigger.getAttribute("data-count");
                
                // Pega HTML da descrição escondida
                var descDiv = trigger.querySelector(".ph-item-description-content");
                var descHtml = descDiv ? descDiv.innerHTML : "";

                // Preenche
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
                    else descEl.innerHTML = "<i class=\'text-muted\'>Sem descrição.</i>";
                }

                if(badgeEl) {
                    if (count && count > 0) {
                        badgeEl.innerText = "x" + count;
                        badgeEl.style.display = "inline-block";
                    } else {
                        badgeEl.style.display = "none";
                    }
                }

                if(imgContainer) {
                    imgContainer.innerHTML = "";
                    if (isImg == "1" || isImg == "true") {
                        imgContainer.innerHTML = "<img src=\'"+img+"\' style=\'max-width: 120px; max-height: 120px; object-fit:contain;\'>";
                    } else {
                        imgContainer.innerHTML = "<span style=\'font-size: 80px;\'>"+img+"</span>";
                    }
                }

                // Abre usando jQuery do Moodle (Bootstrap)
                if (typeof jQuery !== "undefined") {
                    jQuery("#phItemModalFilter").modal("show");
                }
            };

            // Listener Global para o Widget
            document.body.addEventListener("click", function(e) {
                // Sobe a árvore para achar o container .ph-widget-trigger
                var target = e.target.closest(".ph-widget-trigger");
                if (target) {
                    e.preventDefault();
                    openModal(target);
                }
                
                // Fechar modal (Correção: usa .closest para pegar cliques no ícone interno)
                if (e.target.closest(".ph-modal-close-f")) {
                    // Previne comportamentos padrões se necessário
                    e.preventDefault();
                    
                    if (typeof jQuery !== "undefined") {
                        jQuery("#phItemModalFilter").modal("hide");
                    }
                }
            });

        });
        </script>';
    }
}