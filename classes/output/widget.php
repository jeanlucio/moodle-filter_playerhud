<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace filter_playerhud\output;

use renderable;
use templatable;
use renderer_base;
use moodle_url;
use context_block;
use html_writer;
use core_useragent;

/**
 * Widget output class for PlayerHUD.
 *
 * @package    filter_playerhud
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class widget implements renderable, templatable {
    /** @var object The block instance. */
    protected $instance;
    /** @var int The course ID. */
    protected $courseid;

    /**
     * Constructor.
     *
     * @param object $instance The block instance.
     * @param int $courseid The course ID.
     */
    public function __construct($instance, $courseid) {
        $this->instance = $instance;
        $this->courseid = $courseid;
    }

    /**
     * Export data for template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template data.
     */
    public function export_for_template(renderer_base $output) {
        global $USER, $DB, $CFG, $PAGE;

        // 1. Detect Moodle App.
        $isapp = \core_useragent::is_moodle_app();

        // App Logic (Simple Notice).
        if ($isapp) {
            // Fix: Redirect to plugin VIEW.PHP (Backpack), and not to the course.
            // This restores the old experience of going straight to the dashboard.
            $urlbackpack = new moodle_url('/blocks/playerhud/view.php', [
                'id' => $this->courseid,
                'instanceid' => $this->instance->id,
            ]);

            return [
                'is_active' => true,
                'is_app' => true,
                'url_redirect' => $urlbackpack->out(false), // Absolute URL.
            ];
        }

        // Web Logic (Load Everything).

        // Get Player Data.
        $player = $DB->get_record('block_playerhud_user', [
            'blockinstanceid' => $this->instance->id,
            'userid' => $USER->id,
        ]);

        // Immediate Reactivation Logic (Web Only).
        if (!$player || !$player->enable_gamification) {
            $returnurl = $PAGE->url->out_as_local_url(false);
            $urlactivate = new moodle_url('/blocks/playerhud/view.php', [
                'id' => $this->courseid,
                'instanceid' => $this->instance->id,
                'action' => 'toggle_hud',
                'state' => 1,
                'sesskey' => sesskey(),
                'returnurl' => $returnurl,
            ]);
            return [
                'is_active' => false,
                'optin_url' => $urlactivate->out(false),
                'optin_text' => get_string('click_to_enable', 'filter_playerhud'),
                'is_app' => false,
            ];
        }

        // Load Config & Stats.
        $config = unserialize(base64_decode($this->instance->configdata));
        if (!$config) {
            $config = new \stdClass();
        }

        $enableitems  = isset($config->enable_items) ? (bool) $config->enable_items : true;
        $enablequests = isset($config->enable_quests) ? (bool) $config->enable_quests : true;

        $stats = \block_playerhud\game::get_game_stats($config, $this->instance->id, $player->currentxp);
        $xptotalgame = isset($stats['total_game_xp']) ? $stats['total_game_xp'] : 0;

        $strxp = get_string('currentxp', 'filter_playerhud');
        $xpdisplay = $player->currentxp . ' / ' . $xptotalgame . ' ' . $strxp;

        if ($player->currentxp >= $xptotalgame && $xptotalgame > 0) {
            $xpdisplay .= ' 🏆';
        }

        // Recent Items Logic — only when items feature is enabled.
        $context = context_block::instance($this->instance->id);
        $recentitems = [];

        if ($enableitems) {
            $rawinventory = \block_playerhud\game::get_inventory($USER->id, $this->instance->id);
            $count = 0;
            $seen = [];
            $itemstodisplay = [];

            foreach ($rawinventory as $invitem) {
                if ($count >= 6) {
                    break;
                }
                if (in_array($invitem->id, $seen)) {
                    continue;
                }
                $seen[] = $invitem->id;
                $itemstodisplay[$invitem->id] = $invitem;
                $count++;
            }

            $allmedia = \block_playerhud\utils::get_items_display_data($itemstodisplay, $context);

            foreach ($itemstodisplay as $invitem) {
                $media = $allmedia[$invitem->id];
                $imagepayload = $media['is_image'] ? $media['url'] : strip_tags($media['content']);

                $recentitems[] = [
                    'name' => format_string($invitem->name),
                    'xp' => $invitem->xp . ' ' . $strxp,
                    'image' => $imagepayload,
                    'isimage' => $media['is_image'] ? 1 : 0,
                    'content' => $imagepayload,
                    'description' => !empty($invitem->description) ? format_text($invitem->description, FORMAT_HTML) : '',
                    'date' => userdate($invitem->collecteddate, get_string('strftimedatefullshort', 'langconfig')),
                    'timestamp' => $invitem->collecteddate,
                ];
            }
        }

        // Ranking Logic.
        $rankdata = null;
        $enableranking = isset($config->enable_ranking) ? $config->enable_ranking : 1;
        $isteacher = has_capability('block/playerhud:manage', $context);

        if ($enableranking) {
            $urlranking = new moodle_url('/blocks/playerhud/view.php', [
                'id' => $this->courseid,
                'instanceid' => $this->instance->id,
                'tab' => 'ranking',
            ]);

            if (!$isteacher && $player->ranking_visibility == 1 && $player->enable_gamification == 1) {
                $rank = \block_playerhud\game::get_user_rank($this->instance->id, $USER->id, $player->currentxp);
                $rankdisplay = $rank;
                $ranktooltip = "#{$rank} - " . get_string('view_ranking', 'filter_playerhud');
            } else if ($isteacher) {
                $rankdisplay = '-';
                $ranktooltip = get_string('view_ranking', 'filter_playerhud');
            } else {
                $rankdisplay = '-';
                $ranktooltip = get_string('enable_ranking', 'filter_playerhud');
            }

            $rankdata = [
                'rank' => $rankdisplay,
                'url' => $urlranking->out(false),
                'tooltip' => $ranktooltip,
                'label' => get_string('view_ranking', 'filter_playerhud'),
            ];
        }

        // Portrait data for widget left column (only when RPG mode is on).
        $portraiturl = null;
        $classfullname = null;
        $classname = null;
        $classtier = null;
        $classtiername = null;
        $classdescription = '';
        $hasclassdescription = false;
        $tierstars = null;
        $rpgmodeenabled = isset($config->enable_rpg) ? (bool) $config->enable_rpg : true;
        $rpgprogress = $rpgmodeenabled
            ? \block_playerhud\game::get_player_class($this->instance->id, $USER->id)
            : null;
        $hasclass = false;
        if ($rpgprogress && (int) $rpgprogress->classid > 0) {
            $class = $DB->get_record('block_playerhud_classes', ['id' => $rpgprogress->classid]);
            if ($class) {
                $hasclass = true;
                $portraittier = \block_playerhud\utils::get_class_portrait_tier(
                    $this->instance->id,
                    $USER->id
                );
                $portraiturl = \block_playerhud\utils::get_class_evolution_image_by_tier(
                    $class,
                    $portraittier,
                    $context
                );
                $classname = format_string($class->name);
                $classfullname = format_string($class->name);
                $classtier = $portraittier;
                $classtiername = get_string('class_tier_' . $portraittier, 'block_playerhud');
                $classdescription = format_text($class->description ?? '', FORMAT_HTML, ['context' => $context]);
                $hasclassdescription = !empty(trim(strip_tags($classdescription)));
                $stars = [];
                for ($i = 1; $i <= 5; $i++) {
                    $stars[] = ['filled' => ($i <= $portraittier)];
                }
                $tierstars = $stars;
            }
        }

        // Karma bar data (only when RPG mode is on).
        $karmadata = null;
        if ($rpgmodeenabled) {
            $karma = \block_playerhud\game::get_player_karma($this->instance->id, $USER->id);
            $karmapercent = max(0, min(100, (int) round(($karma + 999) / 1998 * 100)));
            if ($karma < 0) {
                $karmabarclass = 'ph-karma-fill--evil';
                $karmaiconclass = 'ph-karma--evil';
            } else if ($karma > 0) {
                $karmabarclass = 'ph-karma-fill--good';
                $karmaiconclass = 'ph-karma--good';
            } else {
                $karmabarclass = 'ph-karma-fill--neutral';
                $karmaiconclass = 'ph-karma--neutral';
            }
            $karmadata = [
                'value'         => $karma,
                'value_display' => ($karma === 0)
                    ? get_string('karma_neutral', 'block_playerhud')
                    : (($karma > 0 ? '+' : '') . $karma),
                'percent'       => $karmapercent,
                'bar_class'     => $karmabarclass,
                'icon_class'    => $karmaiconclass,
                'label'         => get_string('karma', 'filter_playerhud'),
            ];
        }

        // Quest notification dot: show when a reward is waiting to be claimed.
        $hasclaimable = $enablequests && \block_playerhud\quest::has_claimable_quests(
            $this->instance->id,
            $USER->id,
            $this->courseid,
            $player->currentxp,
            $stats['level']
        );

        // Story notification dot: show when there is an available unread chapter.
        $hasunreadchapters = $rpgmodeenabled && !$isteacher && \block_playerhud\story_manager::has_unread_chapters(
            $this->instance->id,
            $USER->id,
            $stats['level']
        );

        // Actions & URLs.
        $urlbase = new moodle_url('/blocks/playerhud/view.php', ['id' => $this->courseid, 'instanceid' => $this->instance->id]);
        $actions = [
            'url_backpack' => $urlbase->out(false),
            'url_story'    => (new moodle_url($urlbase, ['tab' => 'chapters']))->out(false),
            'url_shop'     => (new moodle_url($urlbase, ['tab' => 'shop']))->out(false),
            'url_history'  => (new moodle_url($urlbase, ['tab' => 'history']))->out(false),
            'url_quests'   => (new moodle_url($urlbase, ['tab' => 'quests']))->out(false),
        ];

        // Disable URL.
        $urldisable = new moodle_url('/blocks/playerhud/view.php', [
            'id' => $this->courseid,
            'instanceid' => $this->instance->id,
            'action' => 'toggle_hud',
            'state' => 0,
            'sesskey' => sesskey(),
            'returnurl' => '/course/view.php?id=' . $this->courseid,
        ]);

        // PlayerGroup info (soft dependency — only when mod_playergroup is installed).
        $groupinfo = null;
        if (class_exists('\mod_playergroup\api\group_info')) {
            $groupinfo = \mod_playergroup\api\group_info::get_player_group_in_course(
                (int) $this->courseid,
                (int) $USER->id
            );
        }

        return [
            'is_active' => true,
            'is_app' => false,
            'char_modal_id' => 'ph-char-modal-' . $this->instance->id,
            'userpicture' => $output->user_picture($USER, ['size' => 75]),
            'fullname' => fullname($USER),
            'level_class' => $stats['level_class'],
            'level_display' => $stats['level'] . '/' . $stats['max_levels'],
            'xp_display' => $xpdisplay,
            'progress' => $stats['progress'],
            'karma_data'      => $karmadata,
            'portrait_url'    => $portraiturl,
            'class_fullname'  => $classfullname,
            'class_name'      => $classname,
            'class_tier'      => $classtier,
            'class_tier_name' => $classtiername,
            'class_description' => $classdescription,
            'has_class_description' => $hasclassdescription,
            'tier_stars'      => $tierstars,
            'has_class'       => $hasclass,
            'has_portrait'    => ($portraiturl !== null),
            'enable_items'  => $enableitems,
            'enable_quests' => $enablequests,
            'enable_rpg'    => $rpgmodeenabled,
            'has_claimable_quests' => $hasclaimable,
            'has_unread_chapters' => $hasunreadchapters,
            'items' => $recentitems,
            'ranking' => $rankdata,
            'hasgroup'     => $groupinfo !== null,
            'groupbadge'   => $groupinfo ? $groupinfo->badge : '',
            'groupname'    => $groupinfo ? format_string($groupinfo->groupname) : '',
            'groupmembers' => $groupinfo
                ? $groupinfo->membercount . '/' . $groupinfo->maxmembers
                : '',
            'url_disable' => $urldisable->out(false),
            'str_disable_gamification' => get_string('disable_exit', 'block_playerhud'),
            'str_confirm_msg' => get_string('confirm_disable', 'block_playerhud'),
        ] + $actions;
    }

    /**
     * Render the widget.
     *
     * @return string HTML.
     */
    public function render() {
        global $OUTPUT;
        $data = $this->export_for_template($OUTPUT);

        if (empty($data['is_active'])) {
            if (isset($data['optin_url'])) {
                return html_writer::tag(
                    'div',
                    html_writer::link($data['optin_url'], $data['optin_text'], ['class' => 'btn btn-primary']),
                    ['class' => 'text-center my-3']
                );
            }
            return '';
        }

        return $OUTPUT->render_from_template('filter_playerhud/widget', $data);
    }
}
