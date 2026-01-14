<?php
namespace filter_playerhud;

defined('MOODLE_INTERNAL') || die();

class text_filter extends \moodle_text_filter {
    
    public function filter($text, array $options = array()) {
        global $USER, $DB, $OUTPUT, $COURSE;

        if (strpos($text, '[PLAYERHUD_') === false) { return $text; }

        if (!isloggedin() || isguestuser() || $COURSE->id == SITEID) {
            $text = str_replace('[PLAYERHUD_WIDGET]', '', $text);
            $text = preg_replace('/\[PLAYERHUD_ITEM id=\d+\]/', '', $text);
            return $text; 
        }

        $playerhud = $DB->get_record('playerhud', ['course' => $COURSE->id], '*', IGNORE_MULTIPLE);
        if (!$playerhud) return $text;

        $cm = get_coursemodule_from_instance('playerhud', $playerhud->id, $playerhud->course);
        $now = time();
        $needs_script = false;

        // =========================================================
        // PARTE 1: O WIDGET (Lógica B: Últimas Conquistas ÚNICAS)
        // =========================================================
        if (strpos($text, '[PLAYERHUD_WIDGET]') !== false) {
             
             $player = \mod_playerhud\game::get_player($playerhud->id, $USER->id);
             $stats = \mod_playerhud\game::get_game_stats($playerhud->id, $player->currentxp);
             
             // Busca Histórico (Apenas IDs para verificação rápida)
             $my_inventory = $DB->get_records('playerhud_inventory', ['userid' => $USER->id]);
             $my_item_ids = array_map(function($inv) { return $inv->itemid; }, $my_inventory);

             // --- CORREÇÃO SQL: Agrupa duplicatas para evitar erro de chave ---
             // Seleciona o Item apenas UMA vez, ordenado pela data da ÚLTIMA coleta
             $sql_recent = "SELECT i.* FROM {playerhud_items} i 
                            JOIN (
                                SELECT itemid, MAX(timecreated) as lasttime
                                FROM {playerhud_inventory} 
                                WHERE userid = :userid
                                GROUP BY itemid
                            ) unique_inv ON unique_inv.itemid = i.id
                            WHERE i.playerhudid = :pid 
                            ORDER BY unique_inv.lasttime DESC";

             $recent_items = $DB->get_records_sql($sql_recent, ['userid' => $USER->id, 'pid' => $playerhud->id], 0, 4);
             
             // Lógica de preenchimento (Slots Vazios)
             $display_items = $recent_items; 
             $slots_needed = 4 - count($recent_items);

             if ($slots_needed > 0) {
                 $exclude_ids = !empty($my_item_ids) ? $my_item_ids : [0];
                 list($insql, $inparams) = $DB->get_in_or_equal($exclude_ids, SQL_PARAMS_NAMED, 'excl', false);
                 
                 // Busca próximas metas (Itens que NÃO tenho)
                 $sql_next = "SELECT * FROM {playerhud_items} 
                              WHERE playerhudid = :pid AND enabled = 1 AND id $insql 
                              ORDER BY xp ASC";
                 
                 $params = array_merge(['pid' => $playerhud->id], $inparams);
                 $next_goals = $DB->get_records_sql($sql_next, $params, 0, $slots_needed);
                 
                 $display_items = array_merge($recent_items, $next_goals);
             }

             $items_html = '';
             foreach ($display_items as $item) {
                 $has_it = in_array($item->id, $my_item_ids);
                 if ($has_it) {
                     $img_style = "width:30px; height:30px; object-fit:contain;";
                     $icon_char = $item->image;
                     $tooltip = format_string($item->name);
                 } else {
                     if ($item->secret == 1) {
                         $img_style = "width:30px; height:30px; object-fit:contain; opacity: 0.5;";
                         $icon_char = '❓'; 
                         $tooltip = "???";
                     } else {
                         $img_style = "width:30px; height:30px; object-fit:contain; filter: grayscale(100%); opacity: 0.4;";
                         $icon_char = $item->image;
                         $tooltip = format_string($item->name);
                     }
                 }
                 if (strpos($item->image, 'http') === 0 && !($item->secret == 1 && !$has_it)) {
                     $display = '<img src="'.$item->image.'" style="'.$img_style.'" title="'.$tooltip.'">';
                 } else {
                     $display = '<span style="font-size:24px; cursor:help; '.$img_style.'" title="'.$tooltip.'">'.$icon_char.'</span>';
                 }
                 $items_html .= '<div class="playerhud-mini-item" style="transition: transform 0.2s; margin-right: 5px;">'.$display.'</div>';
             }
             if (empty($items_html)) $items_html = '<span class="small text-muted">- Vazio -</span>';

             $url = new \moodle_url('/mod/playerhud/view.php', ['id' => $cm->id]);
             $html = '
            <div class="playerhud-widget-bar" style="background: #fff; padding: 15px; border-radius: 10px; border: 1px solid #dee2e6; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <div class="d-flex align-items-center flex-wrap gap-3">
                    <div class="d-flex align-items-center mr-4">
                        ' . $OUTPUT->user_picture($USER, ['size' => 50]) . '
                        <div class="ml-2" style="margin-left: 10px;">
                            <div style="font-weight: bold; font-size: 1.1em;">' . fullname($USER) . '</div>
                            <div class="badge badge-pill badge-primary">Nível '.$stats['level'].'</div>
                        </div>
                    </div>
                    <div class="flex-grow-1" style="min-width: 200px;">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>XP Atual: <strong>'.$player->currentxp.'</strong></span>
                            <span>Meta do Curso: '.$stats['total_game_xp'].'</span>
                        </div>
                        <div class="progress" style="height: 12px; border-radius: 6px; background-color: #e9ecef;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: '.$stats['progress'].'%;"></div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 pl-3" style="border-left: 1px solid #eee; padding-left: 15px;">
                        <div class="text-muted small mr-2">Itens:</div>
                        ' . $items_html . '
                    </div>
                    <div class="ml-auto">
                        <a href="'.$url->out().'" class="btn btn-sm btn-outline-primary">Abrir Mochila 🎒</a>
                    </div>
                </div>
            </div>';
             $text = str_replace('[PLAYERHUD_WIDGET]', $html, $text);
        }

        // =========================================================
        // PARTE 2: BOTÕES DE COLETA
        // =========================================================
        $pattern = '/\[PLAYERHUD_ITEM id=(\d+)\]/i';

        if (preg_match_all($pattern, $text, $matches)) {
            $needs_script = true; 
            foreach ($matches[1] as $key => $itemid) {
                $fullcode = $matches[0][$key];
                $item = $DB->get_record('playerhud_items', ['id' => $itemid]);
                
                if (!$item || !$item->enabled) {
                    $text = str_replace($fullcode, '', $text);
                    continue;
                }

                $inventory = $DB->get_records('playerhud_inventory', ['userid' => $USER->id, 'itemid' => $item->id], 'timecreated DESC');
                $count = count($inventory);
                $last_collected = $inventory ? reset($inventory) : null;
                $has_it = ($count > 0);

                if ($item->secret == 1 && !$has_it) {
                    $display_name = "Item Misterioso";
                    $display_xp = "??? XP";
                    $display_icon = '<span style="font-size:30px;">❓</span>';
                } else {
                    $display_name = format_string($item->name);
                    $display_xp = ($item->maxusage == 0) ? "♾️ Infinito" : "+".$item->xp." XP";
                    $display_icon = (strpos($item->image, 'http') === 0) 
                        ? '<img src="'.$item->image.'" style="width:40px; height:40px; object-fit:contain;">' 
                        : '<span style="font-size:30px;">'.$item->image.'</span>';
                }

                $limit_reached = ($item->maxusage > 0 && $count >= $item->maxusage);
                $seconds_wait = $item->respawntime * 60;
                $ready_time = $last_collected ? ($last_collected->timecreated + $seconds_wait) : 0;
                $is_cooldown = ($last_collected && $now < $ready_time);

                if ($limit_reached) {
                    $status_html = '<div class="playerhud-collect-card collected" style="border: 2px solid #28a745; background: #e8f5e9; padding: 15px; border-radius: 8px; display: inline-flex; align-items: center; gap: 15px; margin: 10px 0;">
                        <div style="font-size: 30px;">✅</div>
                        <div>
                            <strong>'.$display_name.'</strong> <span class="badge badge-success badge-pill">'.$count.'</span><br>
                            <small class="text-success">Coletado!</small>
                        </div>
                    </div>';
                } elseif ($is_cooldown) {
                    $status_html = '<div class="playerhud-collect-card cooldown" style="border: 2px solid #ffc107; background: #fff3cd; padding: 15px; border-radius: 8px; display: inline-flex; align-items: center; gap: 15px; margin: 10px 0;">
                        <div style="font-size: 30px; opacity: 0.5;">⏳</div>
                        <div>
                            <strong>'.$display_name.'</strong> <span class="badge badge-secondary badge-pill">'.$count.'</span><br>
                            <small class="text-muted">Aguarde: <strong class="ph-timer" data-deadline="'.$ready_time.'">...</strong></small>
                        </div>
                    </div>';
                } else {
                    $collecturl = new \moodle_url('/mod/playerhud/collect.php', ['id' => $cm->id, 'itemid' => $item->id]);
                    $counter_badge = ($count > 0) ? '<span class="badge badge-info badge-pill">Tens: '.$count.'</span>' : '';
                    
                    $status_html = '
                    <div class="playerhud-collect-card available" id="ph-card-'.$item->id.'" style="border: 2px dashed #0f6cbf; background: #f8f9fa; padding: 15px; border-radius: 8px; display: inline-flex; align-items: center; gap: 15px; margin: 10px 0;">
                        <div>'.$display_icon.'</div>
                        <div>
                            <strong>'.$display_name.'</strong> '.$counter_badge.'<br>
                            <small class="text-muted">'.$display_xp.'</small>
                        </div>
                        <a href="'.$collecturl->out().'" class="btn btn-primary btn-sm ml-3 ph-collect-btn" data-itemid="'.$item->id.'">🖐 Pegar</a>
                    </div>';
                }

                $text = str_replace($fullcode, $status_html, $text);
            }
        }

        // =========================================================
        // PARTE 3: SCRIPT COM TOAST (Mensagem Discreta - 3 Segundos)
        // =========================================================
        if ($needs_script && strpos($text, 'ph-super-script') === false) {
            $text .= '
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
            <script id="ph-super-script">
            document.addEventListener("DOMContentLoaded", function() {
                
                const Toast = Swal.mixin({
                    toast: true,
                    position: "top-end",
                    showConfirmButton: false,
                    timer: 3000, 
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.addEventListener("mouseenter", Swal.stopTimer)
                        toast.addEventListener("mouseleave", Swal.resumeTimer)
                    }
                });

                setInterval(function() {
                    var now = Math.floor(Date.now() / 1000);
                    document.querySelectorAll(".ph-timer").forEach(function(el) {
                        var deadline = parseInt(el.getAttribute("data-deadline"));
                        var diff = deadline - now;
                        if (diff <= 0) {
                            el.innerHTML = "Pronto!";
                            el.style.color = "green";
                        } else {
                            var m = Math.floor(diff / 60);
                            var s = diff % 60;
                            el.innerHTML = m + "m " + (s < 10 ? "0" : "") + s + "s";
                        }
                    });
                }, 1000);

                document.body.addEventListener("click", function(e) {
                    if (e.target && e.target.classList.contains("ph-collect-btn")) {
                        e.preventDefault(); 
                        
                        var btn = e.target;
                        var originalText = btn.innerHTML;
                        var url = btn.getAttribute("href") + "&ajax=1";
                        
                        btn.innerHTML = "⏳ ...";
                        btn.style.opacity = "0.7";

                        fetch(url)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                Toast.fire({
                                    icon: "success",
                                    title: data.message
                                }).then(() => {
                                    location.reload(); 
                                });
                            } else {
                                Toast.fire({
                                    icon: "warning",
                                    title: data.message
                                });
                                btn.innerHTML = originalText;
                                btn.style.opacity = "1";
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            window.location.href = btn.getAttribute("href");
                        });
                    }
                });

            });
            </script>';
        }

        return $text;
    }
}