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

namespace filter_playerhud\output;

use renderable;
use templatable;
use renderer_base;
use moodle_url;
use context_block;
use html_writer;

/**
 * Widget output class for PlayerHUD.
 *
 * @package    filter_playerhud
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

        // Get Player Data.
        $player = $DB->get_record('block_playerhud_user', [
            'blockinstanceid' => $this->instance->id,
            'userid' => $USER->id,
        ]);

        // Immediate Reactivation Logic.
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
            ];
        }

        // Load Config & Stats.
        $config = unserialize(base64_decode($this->instance->configdata));
        if (!$config) {
            $config = new \stdClass();
        }

        require_once($CFG->dirroot . '/blocks/playerhud/classes/game.php');
        require_once($CFG->dirroot . '/blocks/playerhud/classes/utils.php');

        $stats = \block_playerhud\game::get_game_stats($config, $this->instance->id, $player->currentxp);
        $xptotalgame = isset($stats['total_game_xp']) ? $stats['total_game_xp'] : 0;

        $strxp = get_string('currentxp', 'filter_playerhud');
        $xpdisplay = $player->currentxp . ' / ' . $xptotalgame . ' ' . $strxp;

        if ($player->currentxp >= $xptotalgame && $xptotalgame > 0) {
            $xpdisplay .= ' 🏆';
        }

        // Recent Items Logic.
        $recentitems = [];
        $rawinventory = \block_playerhud\game::get_inventory($USER->id, $this->instance->id);
        $count = 0;
        $seen = [];
        $context = context_block::instance($this->instance->id);

        foreach ($rawinventory as $invitem) {
            if ($count >= 6) {
                break;
            }
            if (in_array($invitem->id, $seen)) {
                continue;
            }
            $seen[] = $invitem->id;

            $media = \block_playerhud\utils::get_item_display_data($invitem, $context);
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
            $count++;
        }

        // Ranking Logic.
        $rankdata = null;
        $enableranking = isset($config->enable_ranking) ? $config->enable_ranking : 1;

        if ($enableranking) {
            $urlranking = new moodle_url('/blocks/playerhud/view.php', [
                'id' => $this->courseid,
                'instanceid' => $this->instance->id,
                'tab' => 'ranking',
            ]);

            $isteacher = has_capability('block/playerhud:manage', $context);

            if (!$isteacher && $player->ranking_visibility == 1 && $player->enable_gamification == 1) {
                $rank = \block_playerhud\game::get_user_rank($this->instance->id, $USER->id, $player->currentxp);
                $rankdisplay = $rank;
                $ranktooltip = "#{$rank} - " . get_string('view_ranking', 'filter_playerhud');
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

        // Actions & URLs.
        $urlbase = new moodle_url('/blocks/playerhud/view.php', ['id' => $this->courseid, 'instanceid' => $this->instance->id]);

        $actions = [
            'url_backpack' => $urlbase->out(false),
            'url_story'    => (new moodle_url($urlbase, ['tab' => 'chapters']))->out(false),
            'url_shop'     => (new moodle_url($urlbase, ['tab' => 'trades']))->out(false),
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

        return [
            'is_active' => true,
            'userpicture' => $output->user_picture($USER, ['size' => 75]),
            'fullname' => fullname($USER),
            'level_class' => $stats['level_class'],
            'level_display' => $stats['level'] . '/' . $stats['max_levels'],
            'xp_display' => $xpdisplay,
            'progress' => $stats['progress'],
            'items' => $recentitems,
            'ranking' => $rankdata,
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
