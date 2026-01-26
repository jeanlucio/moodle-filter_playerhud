<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace filter_playerhud;

/**
 * Text filter for PlayerHUD.
 *
 * Injects the HUD widget and parses drops/trades shortcodes.
 *
 * @package    filter_playerhud
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \moodle_text_filter {
    /** @var array Cache for course instances. */
    protected static $coursehuds = [];

    /** @var bool Flag to prevent duplicate modal injection. */
    protected static $modalinjected = false;

    /**
     * Filter the text.
     *
     * @param string $text The text to filter.
     * @param array $options Filter options.
     * @return string The filtered text.
     */
    public function filter($text, array $options = []) {
        global $USER, $DB, $OUTPUT, $COURSE;

        // 1. Quick check.
        if (strpos($text, '[PLAYERHUD_') === false) {
            return $text;
        }

        // 2. Check permissions and context.
        if (!isloggedin() || isguestuser() || $COURSE->id == SITEID) {
            $text = str_replace('[PLAYERHUD_WIDGET]', '', $text);
            $text = preg_replace('/\[PLAYERHUD_DROP\s+[^\]]+\]/', '', $text);
            $text = preg_replace('/\[PLAYERHUD_TRADE\s+[^\]]+\]/', '', $text);
            return $text;
        }

        // 3. Instance Cache.
        if (!isset(self::$coursehuds[$COURSE->id])) {
            self::$coursehuds[$COURSE->id] = $DB->get_record(
                'playerhud',
                ['course' => $COURSE->id],
                '*',
                IGNORE_MULTIPLE
            );
        }
        $playerhud = self::$coursehuds[$COURSE->id];

        if (!$playerhud) {
            $text = str_replace('[PLAYERHUD_WIDGET]', '', $text);
            return $text;
        }

        $cm = get_coursemodule_from_instance('playerhud', $playerhud->id, $playerhud->course);
        $player = \mod_playerhud\game::get_player($playerhud->id, $USER->id);

        // Opt-in check.
        $isgamified = (!empty($player->enable_gamification) && $player->enable_gamification == 1);
        $coursecontext = \context_course::instance($COURSE->id);
        $isteacher = has_capability('mod/playerhud:addinstance', $coursecontext);

        // SCENARIO: OPT-OUT (Show button to enable).
        if (!$isgamified) {
            if (strpos($text, '[PLAYERHUD_WIDGET]') !== false) {
                $url = new \moodle_url('/mod/playerhud/view.php', ['id' => $cm->id]);
                $link = \html_writer::link(
                    $url,
                    get_string('click_to_enable', 'filter_playerhud'),
                    ['class' => 'btn btn-sm btn-light border shadow-sm']
                );
                $simplemsg = \html_writer::tag('div', $link, ['class' => 'text-center my-3']);
                $text = str_replace('[PLAYERHUD_WIDGET]', $simplemsg, $text);
            }
            $text = preg_replace('/\[PLAYERHUD_DROP\s+[^\]]+\]/', '', $text);
            $text = preg_replace('/\[PLAYERHUD_TRADE\s+[^\]]+\]/', '', $text);
            return $text;
        }

        // SCENARIO: GAMIFIED USER.
        $cm->context = \context_module::instance($cm->id);
        $needsscript = false;
        $now = time();

        // Part 1: The Widget.
        if (strpos($text, '[PLAYERHUD_WIDGET]') !== false) {
            $stats = \mod_playerhud\game::get_game_stats($playerhud, $player->currentxp);

            // Fetch and sort items.
            $sqlitems = "SELECT i.* FROM {playerhud_items} i
                           WHERE i.playerhudid = :pid AND i.enabled = 1
                             AND EXISTS (SELECT 1 FROM {playerhud_drops} d WHERE d.itemid = i.id)
                        ORDER BY i.xp ASC";
            $allitems = $DB->get_records_sql($sqlitems, ['pid' => $playerhud->id]);
            $rawinventory = $DB->get_records(
                'playerhud_inventory',
                ['userid' => $USER->id],
                'timecreated DESC'
            );

            $inventorybyitem = [];
            $lastcollectionmap = [];
            foreach ($rawinventory as $inv) {
                $inventorybyitem[$inv->itemid][] = $inv;
                if (!isset($lastcollectionmap[$inv->itemid])) {
                    $lastcollectionmap[$inv->itemid] = $inv->timecreated;
                }
            }

            // Sorting logic.
            $sorteditems = (array)$allitems;
            usort($sorteditems, function ($a, $b) use ($lastcollectionmap) {
                $timea = isset($lastcollectionmap[$a->id]) ? $lastcollectionmap[$a->id] : 0;
                $timeb = isset($lastcollectionmap[$b->id]) ? $lastcollectionmap[$b->id] : 0;
                if ($timea == $timeb) {
                    return ($a->xp < $b->xp) ? -1 : 1;
                }
                return ($timea > $timeb) ? -1 : 1;
            });

            // Build HTML.
            $widgetitemshtml = '';
            $itemsshown = 0;
            $maxitemswidget = 4;

            foreach ($sorteditems as $item) {
                if ($itemsshown >= $maxitemswidget) {
                    break;
                }
                $usercopies = isset($inventorybyitem[$item->id]) ? $inventorybyitem[$item->id] : [];
                $mediadata = \mod_playerhud\utils::get_item_display_data($item, $cm->context);

                if (empty($usercopies)) {
                    // Missing Item.
                    $style = "width:30px; height:30px; object-fit:contain; filter: grayscale(100%); opacity: 0.4;";
                    $display = $mediadata['is_image'] ?
                        \html_writer::empty_tag('img', ['src' => $mediadata['url'], 'style' => $style, 'alt' => '']) :
                        \html_writer::span($mediadata['content'], '', [
                            'style' => "font-size:24px; $style",
                            'aria-hidden' => 'true',
                        ]);

                    $tooltip = format_string($item->name) . " " . get_string('not_collected', 'mod_playerhud');
                    $widgetitemshtml .= $this->render_mini_card(
                        $item,
                        $display,
                        $tooltip,
                        "ph-widget-trigger",
                        $mediadata,
                        false,
                        0,
                        "",
                        ""
                    );
                    $itemsshown++;
                } else {
                    // Owned Item.
                    $stackfinite = 0;
                    $stackinfinite = 0;
                    foreach ($usercopies as $copy) {
                        $isinfinite = false;
                        if ($copy->dropid > 0) {
                            $dropinfo = $DB->get_record('playerhud_drops', ['id' => $copy->dropid]);
                            if ($dropinfo && $dropinfo->maxusage == 0) {
                                $isinfinite = true;
                            }
                        }
                        if ($isinfinite) {
                            $stackinfinite++;
                        } else {
                            $stackfinite++;
                        }
                    }

                    $style = "width:30px; height:30px; object-fit:contain;";
                    $display = $mediadata['is_image'] ?
                        \html_writer::empty_tag('img', ['src' => $mediadata['url'], 'style' => $style, 'alt' => '']) :
                        \html_writer::span($mediadata['content'], '', [
                            'style' => "font-size:24px; $style",
                            'aria-hidden' => 'true',
                        ]);

                    if ($stackfinite > 0 && $itemsshown < $maxitemswidget) {
                        $widgetitemshtml .= $this->render_mini_card(
                            $item,
                            $display,
                            format_string($item->name),
                            "ph-widget-trigger",
                            $mediadata,
                            true,
                            $stackfinite,
                            "x{$stackfinite}",
                            ""
                        );
                        $itemsshown++;
                    }
                    if ($stackinfinite > 0 && $itemsshown < $maxitemswidget) {
                        $widgetitemshtml .= $this->render_mini_card(
                            $item,
                            $display,
                            format_string($item->name) . " (" . get_string('infinite', 'mod_playerhud') . ")",
                            "ph-widget-trigger",
                            $mediadata,
                            true,
                            $stackinfinite,
                            "x{$stackinfinite}",
                            "(" . get_string('infinite', 'mod_playerhud') . ")"
                        );
                        $itemsshown++;
                    }
                }
            }

            if (empty($widgetitemshtml)) {
                $widgetitemshtml = \html_writer::span(get_string('empty', 'filter_playerhud'), 'small text-muted');
            }

            // Ranking.
            $rankhtml = '';
            if (!empty($playerhud->enable_ranking) && $player->ranking_visibility == 1 && !$isteacher) {
                $myrank = \mod_playerhud\game::get_user_rank($playerhud->id, $USER->id, $player->currentxp);
                $rankicon = ($myrank <= 3) ? '🏆' : '#';
                $rankurl = new \moodle_url('/mod/playerhud/view.php', ['id' => $cm->id, 'tab' => 'ranking']);
                $rankhtml = '<a href="' . $rankurl->out() . '"
                                class="badge bg-light text-dark border text-decoration-none ms-2"
                                title="' . get_string('view_ranking', 'mod_playerhud') . '"
                                style="font-size: 0.9em;">' . $rankicon . ' ' . $myrank . '</a>';
            }

            // RPG Data.
            $avatarhtml = $OUTPUT->user_picture($USER, ['size' => 50, 'class' => 'ph-base-avatar']);
            $classname = "";
            $karmabar = "";

            $rpgprogress = $DB->get_record(
                'playerhud_rpg_progress',
                ['userid' => $USER->id, 'playerhudid' => $playerhud->id]
            );

            if ($rpgprogress && $rpgprogress->classid > 0) {
                $myclass = $DB->get_record('playerhud_classes', ['id' => $rpgprogress->classid]);
                if ($myclass) {
                    $evourl = \mod_playerhud\utils::get_class_evolution_image($myclass, $stats['level'], $cm->context);
                    if ($evourl) {
                        $avatarhtml = '
                        <div class="ph-rpg-avatar-wrapper">
                           <div class="ph-class-portrait" style="background-image: url(' . $evourl . ');"></div>
                           <div class="ph-user-mini">' . $OUTPUT->user_picture($USER, ['size' => 30]) . '</div>
                        </div>';
                    }
                    $classname = '<div class="ph-class-badge" style="background-color: var(--ph-tier-color);">' .
                                 format_string($myclass->name) . '</div>';
                }

                $karmapercent = 50 + ($rpgprogress->karma);
                $karmapercent = max(0, min(100, $karmapercent)); // Clamp 0-100.

                $karmacolor = ($rpgprogress->karma >= 0) ? '#4fd6fa' : '#dc3545';
                $karmaicon = ($rpgprogress->karma >= 0) ? '✨' : '🔥';
                $strkarma = get_string('karma', 'filter_playerhud');

                $karmabar = '
                <div class="ph-karma-container" title="' . $strkarma . ': ' . $rpgprogress->karma . '">
                   <div class="ph-karma-track">
                       <div class="ph-karma-fill"
                            style="width: ' . $karmapercent . '%; background-color: ' . $karmacolor . ';"></div>
                   </div>
                   <div class="ph-karma-icon" style="left: ' . $karmapercent . '%;">' . $karmaicon . '</div>
                </div>';
            } else {
                $strnovice = get_string('novice_class', 'filter_playerhud');
                $classname = '<div class="badge bg-secondary">' . $strnovice . '</div>';
            }

            $url = new \moodle_url('/mod/playerhud/view.php', ['id' => $cm->id]);
            $urlstory = new \moodle_url('/mod/playerhud/view.php', ['id' => $cm->id, 'tab' => 'chapters']);

            $html = '
           <div class="playerhud-widget-container tier-' . $stats['level_class'] . '">
               <div class="ph-hud-top-row">
                   <div class="ph-hud-xp-info">
                       <span class="ph-hud-level">
                           ' . get_string('level', 'filter_playerhud') . ' <strong>' . $stats['level'] . '</strong>
                       </span>
                       <div class="ph-hud-xp-bar">
                           <div class="ph-hud-xp-fill" style="width: ' . $stats['progress'] . '%;"></div>
                       </div>
                       <span class="ph-hud-xp-text">' .
                           $player->currentxp . ' / ' . $stats['total_game_xp'] . '
                       </span>
                   </div>
                   <div class="ph-hud-shortcuts">
                       ' . $rankhtml . '
                       <a href="' . $url->out() . '" class="ph-btn-backpack"
                          title="' . get_string('openbackpack', 'filter_playerhud') . '">🎒</a>
                       <a href="' . $urlstory->out() . '" class="ph-btn-backpack ms-1"
                          title="' . get_string('story_shortcut', 'mod_playerhud') . '">📖</a>
                   </div>
               </div>

               <div class="ph-hud-main-row">
                   <div class="ph-hud-portrait-area">
                       ' . $avatarhtml . '
                   </div>
                   <div class="ph-hud-info-area">
                       <div class="ph-player-name">' . fullname($USER) . '</div>
                       ' . $classname . '
                       ' . $karmabar . '
                   </div>
                   <div class="ph-hud-items-area">
                       ' . $widgetitemshtml . '
                   </div>
               </div>
           </div>';
            $text = str_replace('[PLAYERHUD_WIDGET]', $html, $text);
            $needsscript = true;
        }

        // Part 2: Drop Buttons.
        $pattern = '/\[PLAYERHUD_DROP\s+([^\]]+)\]/i';
        if (preg_match_all($pattern, $text, $matches)) {
            $needsscript = true;
            foreach ($matches[1] as $key => $attributesstr) {
                $fullcode = $matches[0][$key];
                $attrs = [];
                if (preg_match('/id=(\d+)/i', $attributesstr, $m)) {
                    $attrs['id'] = $m[1];
                }
                if (preg_match('/mode=([a-z]+)/i', $attributesstr, $m)) {
                    $attrs['mode'] = strtolower($m[1]);
                }
                if (preg_match('/text=["\']?([^"\']*)["\']?/i', $attributesstr, $m)) {
                    $attrs['text'] = $m[1];
                }

                if (empty($attrs['id'])) {
                    continue;
                }
                $dropid = (int)$attrs['id'];
                $mode = isset($attrs['mode']) ? $attrs['mode'] : 'card';
                $customtext = isset($attrs['text']) ? $attrs['text'] : null;

                $drop = $DB->get_record('playerhud_drops', ['id' => $dropid]);
                if (!$drop) {
                    $text = str_replace($fullcode, '', $text);
                    continue;
                }

                $item = $DB->get_record('playerhud_items', ['id' => $drop->itemid]);
                if (!$item || !$item->enabled) {
                    $text = str_replace($fullcode, '', $text);
                    continue;
                }

                $inventory = $DB->get_records(
                    'playerhud_inventory',
                    ['userid' => $USER->id, 'dropid' => $drop->id],
                    'timecreated DESC'
                );
                $count = count($inventory);
                $lastcollected = $inventory ? reset($inventory) : null;
                $limitreached = ($drop->maxusage > 0 && $count >= $drop->maxusage);
                $secondswait = $drop->respawntime;
                $readytime = $lastcollected ? ($lastcollected->timecreated + $secondswait) : 0;
                $iscooldown = ($lastcollected && $now < $readytime);
                $collecturl = new \moodle_url('/mod/playerhud/collect.php', [
                    'id' => $cm->id,
                    'dropid' => $drop->id,
                    'sesskey' => sesskey(),
                ]);
                $mediadata = \mod_playerhud\utils::get_item_display_data($item, $cm->context);
                $displaylabel = $customtext ? format_string($customtext) : format_string($item->name);

                $htmloutput = '';
                if ($mode == 'text') {
                    if ($limitreached) {
                        $htmloutput = '<span class="text-success" style="cursor:default;">✅ ' .
                                      $displaylabel . '</span>';
                    } else if ($iscooldown) {
                        $strwait = get_string('wait', 'filter_playerhud');
                        $htmloutput = '<span class="text-muted" style="cursor:wait;" title="' . $strwait . '">⏳ ' .
                                      $displaylabel .
                                      ' <small class="ph-timer" data-deadline="' . $readytime . '">...</small></span>';
                    } else {
                        $htmloutput = '<a href="' . $collecturl->out() .
                                      '" class="ph-action-collect text-primary" data-mode="text">' .
                                      $displaylabel . '</a>';
                    }
                } else if ($mode == 'image') {
                    $imgsrc = $mediadata['is_image'] ? $mediadata['url'] : '';
                    $emoji  = $mediadata['is_image'] ? '' : $mediadata['content'];
                    $style = "transition: transform 0.2s; display:inline-block; cursor:pointer;";
                    $timerhtml = '';

                    if ($limitreached) {
                        $style .= "opacity: 0.5; filter: grayscale(100%); cursor: default;";
                        $title = get_string('collected', 'filter_playerhud');
                    } else if ($iscooldown) {
                        $style .= "opacity: 0.7; cursor: wait;";
                        $title = get_string('wait', 'filter_playerhud');
                        $timerhtml = '<div class="small text-muted text-center ph-timer" ' .
                                     'style="font-size:10px; line-height:1;" data-deadline="' . $readytime .
                                     '">...</div>';
                    } else {
                        $style .= "filter: drop-shadow(0 4px 2px rgba(0,0,0,0.1));";
                        $title = get_string('take', 'filter_playerhud') . " " . $displaylabel;
                    }

                    $content = $mediadata['is_image'] ?
                        '<img src="' . $imgsrc . '" style="width:50px; height:50px; object-fit:contain;" alt="">' :
                        '<span style="font-size:40px;" aria-hidden="true">' . $emoji . '</span>';

                    if (!$limitreached && !$iscooldown) {
                        $htmloutput = '<a href="' . $collecturl->out() .
                                      '" class="ph-action-collect ph-hover-scale" style="' . $style .
                                      '" title="' . $title . '" data-mode="image">' . $content . '</a>';
                    } else {
                        $htmloutput = '<div style="display:inline-block; text-align:center;">' .
                                      '<div style="' . $style . '" title="' . $title . '">' . $content .
                                      '</div>' . $timerhtml . '</div>';
                    }
                } else {
                    // Card Mode.
                    $displayxp = ($drop->maxusage == 0) ?
                        get_string('infinite', 'filter_playerhud') : "+" . $item->xp . " XP";

                    $displayicon = $mediadata['is_image'] ?
                        '<img src="' . $mediadata['url'] .
                        '" style="max-width: 100%; max-height: 100%; object-fit: contain;" alt="">' :
                        '<div class="emoji-display" style="font-size: 2em;" aria-hidden="true">' .
                        $mediadata['content'] . '</div>';

                    $counterbadge = ($count > 0) ?
                        '<span class="badge bg-info text-dark rounded-pill position-absolute" ' .
                        'style="top:5px; right:5px; font-size:0.7rem;">' .
                        get_string('yours', 'filter_playerhud', $count) . '</span>' : '';

                    if ($limitreached) {
                        $cardstatusclass = "ph-owned";
                        $statusmsg = '<div class="mt-2 text-center small fw-bold text-success" ' .
                                     'style="font-size: 0.7rem;"><i class="fa fa-check-circle" aria-hidden="true"></i> ' .
                                     get_string('collected', 'filter_playerhud') . '</div>';
                        $actionbtn = '<button disabled class="btn btn-light btn-sm w-100 text-success border-success" ' .
                                     'style="font-size:0.7rem;">✔</button>';
                    } else if ($iscooldown) {
                        $cardstatusclass = "";
                        $statusmsg = '<div class="mt-2 text-center small fw-bold text-warning" ' .
                                     'style="font-size: 0.7rem;">' . get_string('wait', 'filter_playerhud') .
                                     ' <span class="ph-timer" data-deadline="' . $readytime . '">...</span></div>';
                        $actionbtn = '<button disabled class="btn btn-light btn-sm w-100 text-muted" ' .
                                     'style="font-size:0.7rem;">⏳</button>';
                    } else {
                        $cardstatusclass = "ph-item-trigger";
                        $statusmsg = '<div class="text-center"><small class="text-muted fw-bold">' .
                                     $displayxp . '</small></div>';
                        $actionbtn = '<a href="' . $collecturl->out() .
                                     '" class="btn btn-primary btn-sm w-100 ph-action-collect shadow-sm mt-2" ' .
                                     'data-mode="card" style="font-size:0.75rem;">' .
                                     get_string('take', 'filter_playerhud') . '</a>';
                    }

                    $htmloutput = '
                    <div class="playerhud-item-card card p-3 ' . $cardstatusclass . '"
                         style="width: 150px; display:inline-block; vertical-align:top;
                                margin:5px; position: relative;">
                        ' . $counterbadge . '
                        <div class="playerhud-icon-container text-center mb-2"
                             style="height: 50px; display: flex; align-items: center; justify-content: center;">' .
                             $displayicon .
                        '</div>
                        <strong class="text-center d-block mb-1 text-truncate" style="line-height: 1.2;">' .
                            format_string($item->name) .
                        '</strong>
                        ' . $statusmsg . '
                        ' . ($limitreached ? '' : $actionbtn) . '
                    </div>';
                }
                $text = str_replace($fullcode, $htmloutput, $text);
            }
        }

        // Part 3: Trade Cards.
        $patterntrade = '/\[PLAYERHUD_TRADE id=(\d+)\]/i';
        if (preg_match_all($patterntrade, $text, $matchestrade)) {
            foreach ($matchestrade[1] as $key => $tradeid) {
                $fullcode = $matchestrade[0][$key];
                $trade = $DB->get_record('playerhud_trades', ['id' => $tradeid]);
                if (!$trade) {
                    $text = str_replace($fullcode, '', $text);
                    continue;
                }

                if ($trade->groupid != 0) {
                    if ($trade->groupid > 0) {
                        if (!groups_is_member($trade->groupid, $USER->id)) {
                            $text = str_replace($fullcode, '', $text);
                            continue;
                        }
                    } else {
                        $groupingid = abs($trade->groupid);
                        $usergroups = groups_get_all_groups($COURSE->id, $USER->id, $groupingid);
                        if (empty($usergroups)) {
                            $text = str_replace($fullcode, '', $text);
                            continue;
                        }
                    }
                }

                $sqlreq = "SELECT r.*, i.name, i.image
                             FROM {playerhud_trade_requirements} r
                             JOIN {playerhud_items} i ON r.itemid = i.id
                            WHERE r.tradeid = :tid";
                $reqs = $DB->get_records_sql($sqlreq, ['tid' => $trade->id]);

                $sqlrew = "SELECT r.*, i.name, i.image, i.xp
                             FROM {playerhud_trade_rewards} r
                             JOIN {playerhud_items} i ON r.itemid = i.id
                            WHERE r.tradeid = :tid";
                $rews = $DB->get_records_sql($sqlrew, ['tid' => $trade->id]);

                $htmlreq = '';
                $canafford = true;
                foreach ($reqs as $r) {
                    $media = \mod_playerhud\utils::get_item_display_data(
                        (object)['id' => $r->itemid, 'image' => $r->image],
                        $cm->context
                    );
                    $icon = $media['is_image'] ?
                        "<img src='{$media['url']}' style='width:24px;height:24px;object-fit:contain;' alt=''>" :
                        "<span style='font-size:20px;' aria-hidden='true'>{$media['content']}</span>";

                    $userhas = $DB->count_records(
                        'playerhud_inventory',
                        ['userid' => $USER->id, 'itemid' => $r->itemid]
                    );
                    if ($userhas < $r->qty) {
                        $canafford = false;
                    }
                    $statuscls = ($userhas >= $r->qty) ? 'text-muted' : 'text-danger fw-bold';
                    $htmlreq .= "<div class='ph-trade-item-row $statuscls'>
                                    <div class='me-2'>$icon</div>
                                    <div>{$r->qty}x {$r->name} <small>({$userhas}/{$r->qty})</small></div>
                                 </div>";
                }

                $htmlrew = '';
                foreach ($rews as $r) {
                    $media = \mod_playerhud\utils::get_item_display_data(
                        (object)['id' => $r->itemid, 'image' => $r->image],
                        $cm->context
                    );
                    $icon = $media['is_image'] ?
                        "<img src='{$media['url']}' style='width:24px;height:24px;object-fit:contain;' alt=''>" :
                        "<span style='font-size:20px;' aria-hidden='true'>{$media['content']}</span>";
                    $htmlrew .= "<div class='ph-trade-item-row text-dark'>
                                    <div class='me-2'>$icon</div>
                                    <div><strong>{$r->qty}x {$r->name}</strong></div>
                                 </div>";
                }

                $processurl = new \moodle_url('/mod/playerhud/process_trade.php', [
                    'id' => $cm->id,
                    'tradeid' => $trade->id,
                    'sesskey' => sesskey(),
                ]);
                $iscompleted = ($trade->onetime && $DB->record_exists(
                    'playerhud_trade_log',
                    ['tradeid' => $trade->id, 'userid' => $USER->id]
                ));

                if ($iscompleted) {
                    $btn = '<button class="btn btn-secondary btn-sm w-100" disabled>
                                <i class="fa fa-check" aria-hidden="true"></i> ' .
                                get_string('trade_redeemed', 'mod_playerhud') .
                           '</button>';
                } else if ($canafford) {
                    $strconfirm = get_string('trade_confirm', 'mod_playerhud');
                    $btn = '<a href="' . $processurl->out() . '" class="btn btn-success btn-sm w-100 shadow-sm"
                               onclick="return confirm(\'' . $strconfirm . '\');">
                                <i class="fa fa-exchange" aria-hidden="true"></i> ' .
                                get_string('trade_perform', 'mod_playerhud') .
                           '</a>';
                } else {
                    $btn = '<button class="btn btn-light btn-sm w-100 text-muted" disabled style="cursor:not-allowed;">
                                <i class="fa fa-lock" aria-hidden="true"></i> ' .
                                get_string('trade_insufficient', 'mod_playerhud') .
                           '</button>';
                }

                $disabledcls = (!$canafford && !$iscompleted) ? 'ph-trade-disabled' : '';

                $cardhtml = '
                <div class="ph-trade-card ' . $disabledcls . '" style="max-width: 400px;">
                    <div class="p-2 bg-light border-bottom fw-bold">
                       <span aria-hidden="true">⚖️</span> ' . format_string($trade->name) . '
                    </div>
                    <div class="ph-trade-body">
                        <div class="ph-trade-section ph-trade-req">
                            <small class="text-uppercase text-danger fw-bold mb-2" style="font-size:0.65rem;">' .
                                get_string('shop_pay', 'mod_playerhud') .
                            '</small>
                            ' . $htmlreq . '
                        </div>
                        <div class="ph-trade-arrow text-muted">
                            <i class="fa fa-arrow-right" aria-hidden="true"></i>
                        </div>
                        <div class="ph-trade-section ph-trade-give">
                            <small class="text-uppercase text-success fw-bold mb-2" style="font-size:0.65rem;">' .
                                get_string('shop_receive', 'mod_playerhud') .
                            '</small>
                            ' . $htmlrew . '
                        </div>
                    </div>
                    <div class="card-footer bg-white border-0 pt-0 text-center">' . $btn . '</div>
                </div>';
                $text = str_replace($fullcode, $cardhtml, $text);
            }
        }

        // Part 4: Script Injection.
        if ($needsscript) {
            if (!self::$modalinjected) {
                $text .= $this->get_modal_html();
                $text .= $this->get_story_modal_html();
                self::$modalinjected = true;
            }
            if (strpos($text, 'ph-super-script') === false) {
                $text .= $this->get_javascript_footer();
            }
        }

        return $text;
    }

    /**
     * Helpers (Modal and JS).
     *
     * @param \stdClass $item Item object.
     * @param string $displayhtml HTML to display.
     * @param string $tooltip Tooltip text.
     * @param string $triggerclass Trigger CSS class.
     * @param array $mediadata Media data.
     * @param bool $hasit Does user have it?
     * @param int $count Item count.
     * @param string $countlabel Count label.
     * @param string $infinitetext Infinite text.
     * @return string HTML output.
     */
    private function render_mini_card(
        $item,
        $displayhtml,
        $tooltip,
        $triggerclass,
        $mediadata,
        $hasit,
        $count,
        $countlabel,
        $infinitetext
    ) {
        $badge = ($hasit && $count > 0) ?
            \html_writer::span(
                $countlabel,
                'badge bg-light text-dark border position-absolute',
                [
                    'style' => 'bottom: -8px; right: -8px; font-size: 0.65rem; ' .
                               'padding: 2px 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);',
                ]
            ) : "";

        $desc = !empty($item->description) ? format_text($item->description, FORMAT_HTML) : "";
        $attrs = [
            'class' => 'playerhud-mini-item position-relative ' . $triggerclass,
            'style' => 'transition: transform 0.2s; margin-right: 12px; cursor: pointer;',
            'title' => $tooltip,
            'data-name' => format_string($item->name),
            'data-xp' => ($infinitetext ? "0 XP" : "+{$item->xp} XP"),
            'data-image' => $mediadata['is_image'] ? $mediadata['url'] : $mediadata['content'],
            'data-isimage' => $mediadata['is_image'],
            'data-count' => $count,
            'data-infinite' => $infinitetext,
        ];
        $hiddendesc = \html_writer::div($desc, 'd-none ph-item-description-content');
        return \html_writer::div($displayhtml . $badge . $hiddendesc, null, $attrs);
    }

    /**
     * Get item detail modal HTML.
     */
    private function get_modal_html() {
        $strdetails = get_string('details', 'mod_playerhud');
        $strclose = get_string('close', 'mod_playerhud');

        return '<div class="modal fade" id="phItemModalFilter" tabindex="-1" role="dialog" ' .
               'aria-hidden="true" style="z-index: 10500;">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header d-flex justify-content-between align-items-center">
                        <h5 class="modal-title fw-bold m-0" id="phModalTitleF">' . $strdetails . '</h5>
                        <button type="button" class="btn-close ph-modal-close-f" aria-label="' . $strclose . '">
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex align-items-start">
                            <div id="phModalImageContainerF" class="me-4 text-center" style="min-width: 100px;"></div>
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center flex-wrap mb-3">
                                    <h4 id="phModalNameF" class="m-0 me-2" style="font-weight: bold;"></h4>
                                    <span id="phModalCountBadgeF" class="badge bg-primary rounded-pill me-1"
                                          style="font-size: 0.9em; display:none;">x0</span>
                                    <span id="phModalXPF" class="badge bg-info text-dark"
                                          style="font-size: 0.9em;">XP</span>
                                </div>
                                <div id="phModalDescF" class="text-muted text-break"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary ph-modal-close-f">' . $strclose . '</button>
                    </div>
                </div>
            </div>
        </div>';
    }

    /**
     * Get story modal HTML.
     */
    private function get_story_modal_html() {
        $strstory = get_string('story_shortcut', 'mod_playerhud');
        $strloading = get_string('loading', 'mod_playerhud');
        $strclose = get_string('close', 'mod_playerhud');

        return '
        <div class="modal fade" id="phStoryModal" tabindex="-1" role="dialog" ' .
               'aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" role="document">
                <div class="modal-content" style="border:none; border-radius:15px; overflow:hidden;">
                    <div class="modal-header bg-dark text-white" style="border-bottom: 4px solid #d4af37;">
                        <h5 class="modal-title fw-bold" id="phStoryTitle">
                            <i class="fa fa-book me-2" aria-hidden="true"></i> ' . $strstory . '
                        </h5>
                        <button type="button" class="btn-close btn-close-white"
                                data-bs-dismiss="modal" aria-label="' . $strclose . '"></button>
                    </div>
                    <div class="modal-body p-5"
                         style="background: #fffbf2; font-family: \'Georgia\', serif;
                                font-size: 1.1rem; line-height: 1.8; color: #2c2c2c;">
                        <div id="phStoryContent" class="animate__animated animate__fadeIn">
                            <div class="text-center text-muted py-5">
                                <i class="fa fa-circle-o-notch fa-spin fa-3x" aria-hidden="true"></i><br>' .
                                $strloading . '
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer justify-content-center bg-light" id="phStoryChoices"
                         style="border-top: 1px solid #e9ecef; min-height: 80px;">
                    </div>
                </div>
            </div>
        </div>';
    }

    /**
     * Get footer JS.
     */
    private function get_javascript_footer() {
        $swalurl = new \moodle_url('/mod/playerhud/js/sweetalert2.all.min.js');
        $root = \moodle_url::make_pluginfile_url(
            \context_system::instance()->id,
            'mod_playerhud',
            'dummy',
            0,
            '/',
            'dummy.txt'
        );
        $wwwroot = str_replace('/pluginfile.php/1/mod_playerhud/dummy/0/dummy.txt', '', $root->out(false));

        // Prepare JSON strings for JS.
        $jsstrings = json_encode([
            'ready' => get_string('ready', 'filter_playerhud'),
            'class_chosen' => get_string('class_chosen', 'filter_playerhud'),
            'close' => get_string('close', 'mod_playerhud'),
            'error_conn' => get_string('error_connection', 'mod_playerhud'),
        ]);

        $js = '<style>
                .ph-hover-scale:hover { transform: scale(1.1); }
                .ph-loading { opacity: 0.5; pointer-events: none; cursor: wait !important; }
               </style>';
        $js .= '<script src="' . $swalurl->out() . '"></script>';

        $js .= '<script id="ph-super-script">
        document.addEventListener("DOMContentLoaded", function() {
            var PHSTR = ' . $jsstrings . ';
            var M_WWWROOT = "' . $wwwroot . '";

            // --- 1. TOASTS ---
            const Toast = Swal.mixin({
                toast: true, position: "top-end", showConfirmButton: false,
                timer: 2000, timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener("mouseenter", Swal.stopTimer);
                    toast.addEventListener("mouseleave", Swal.resumeTimer);
                }
            });

            // --- 2. TIMERS ---
            setInterval(function() {
                var now = Math.floor(Date.now() / 1000);
                document.querySelectorAll(".ph-timer").forEach(function(el) {
                    var deadline = parseInt(el.getAttribute("data-deadline"));
                    var diff = deadline - now;
                    if (diff <= 0) {
                        el.innerHTML = PHSTR.ready;
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

            // --- 3. COLLECT LOGIC ---
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

            // --- 4. ITEM MODAL ---
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
                    if(infiniteTxt) {
                        nameEl.innerHTML += "<br><small class=\'text-muted fw-normal\'>" + infiniteTxt + "</small>";
                    }
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
                        imgContainer.innerHTML = "<img src=\'" + img +
                            "\' style=\'max-width: 120px; max-height: 120px; object-fit:contain;\' alt=\'\'>";
                    } else {
                        imgContainer.innerHTML = "<span style=\'font-size: 80px;\'>" + img + "</span>";
                    }
                }
                if (typeof jQuery !== "undefined") { jQuery("#phItemModalFilter").modal("show"); }
                else { try { new bootstrap.Modal(document.getElementById("phItemModalFilter")).show(); } catch(e){} }
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
                    else { try {
                        var m = bootstrap.Modal.getInstance(document.getElementById("phItemModalFilter"));
                        m.hide();
                    } catch(e){} }
                }
            });

            // --- 5. STORY MODAL ---
            var loadScene = function(cmId, action, params) {
                var contentDiv = document.getElementById("phStoryContent");
                var choicesDiv = document.getElementById("phStoryChoices");
                if (!contentDiv) return;

                choicesDiv.innerHTML = "";
                contentDiv.style.opacity = 0.5;

                var url = M_WWWROOT + "/mod/playerhud/ajax_story.php?id=" + cmId + "&action=" + action;
                for (var key in params) {
                    url += "&" + key + "=" + params[key];
                }

                fetch(url)
                    .then(response => response.json())
                    .then(data => {
                        contentDiv.style.opacity = 1;
                        if (data.error) {
                            contentDiv.innerHTML = "<div class=\'alert alert-danger\'>" + data.error + "</div>";
                            return;
                        }
                        if (data.class_updated) {
                            Toast.fire({ icon: "success", title: PHSTR.class_chosen });
                            setTimeout(function() { location.reload(); }, 1500);
                            return;
                        }
                        if (data.finished) {
                            contentDiv.innerHTML = "<div class=\'text-center py-5\'><h2 class=\'text-success\'>🎉</h2><h3>" +
                                                   data.message + "</h3></div>";
                            choicesDiv.innerHTML = "<button type=\'button\' class=\'btn btn-secondary\' " +
                                                   "data-bs-dismiss=\'modal\'>" +
                                                   PHSTR.close + "</button>";
                            return;
                        }
                        contentDiv.innerHTML = data.node.content;
                        contentDiv.className = "animate__animated animate__fadeIn";

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
                            choicesDiv.innerHTML = "<button type=\'button\' class=\'btn btn-secondary\' " +
                                                   "data-bs-dismiss=\'modal\'>" +
                                                   PHSTR.close + "</button>";
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        contentDiv.innerHTML = "<div class=\'text-danger\'>" + PHSTR.error_conn + "</div>";
                    });
            };

            document.body.addEventListener("click", function(e) {
                var trigger = e.target.closest(".ph-chapter-trigger");
                if (trigger) {
                    e.preventDefault();
                    var chapterId = trigger.getAttribute("data-chapterid");
                    var cmId = trigger.getAttribute("data-cmid");
                    var sessKey = (typeof M !== "undefined" && M.cfg && M.cfg.sesskey) ? M.cfg.sesskey : "";

                    if (typeof jQuery !== "undefined") { jQuery("#phStoryModal").modal("show"); }
                    else { try { new bootstrap.Modal(document.getElementById("phStoryModal")).show(); } catch(e){} }

                    loadScene(cmId, "load_start", { chapterid: chapterId, sesskey: sessKey });
                }
            });
        });
        </script>';

        return $js;
    }
}
