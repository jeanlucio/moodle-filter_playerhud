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
 * Delegates rendering to specialized output classes.
 *
 * @package    filter_playerhud
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \moodle_text_filter {
    /** @var bool Flag to ensure assets are injected only once per page. */
    protected static $assetsinjected = false;

    /** @var array Cache for block instances in courses to avoid redundant queries. */
    protected static $blockcache = [];

    /**
     * Filter the text to replace PlayerHUD shortcodes.
     *
     * @param string $text The text to filter.
     * @param array $options Filter options.
     * @return string The filtered text.
     */
    public function filter($text, array $options = []) {
        global $DB, $COURSE, $PAGE;

        if (strpos($text, '[PLAYERHUD_') === false) {
            return $text;
        }

        if (!isloggedin() || isguestuser() || $COURSE->id == SITEID) {
            $text = str_replace('[PLAYERHUD_WIDGET]', '', $text);
            $text = preg_replace('/\[PLAYERHUD_DROP\s+[^\]]+\]/i', '', $text);
            $text = preg_replace('/\[PLAYERHUD_TRADE\s+[^\]]+\]/i', '', $text);
            return $text;
        }

        if (!isset(self::$blockcache[$COURSE->id])) {
            $context = \context_course::instance($COURSE->id);
            $sql = "SELECT bi.id, bi.configdata
                      FROM {block_instances} bi
                     WHERE bi.blockname = 'playerhud'
                       AND bi.parentcontextid = :ctxid";
            self::$blockcache[$COURSE->id] = $DB->get_record_sql($sql, ['ctxid' => $context->id], IGNORE_MULTIPLE);
        }

        $blockinstance = self::$blockcache[$COURSE->id];
        if (!$blockinstance) {
            $text = str_replace('[PLAYERHUD_WIDGET]', '', $text);
            $text = preg_replace('/\[PLAYERHUD_DROP\s+[^\]]+\]/i', '', $text);
            $text = preg_replace('/\[PLAYERHUD_TRADE\s+[^\]]+\]/i', '', $text);
            return $text;
        }

        $needsassets = false;

        if (strpos($text, '[PLAYERHUD_WIDGET]') !== false) {
            if (class_exists('\filter_playerhud\output\widget')) {
                $widgetrenderer = new \filter_playerhud\output\widget($blockinstance, $COURSE->id);
                $text = str_replace('[PLAYERHUD_WIDGET]', $widgetrenderer->render(), $text);
                $needsassets = true;
            }
        }

        // Extract shortcode parameters for bulk loading (Zero N+1 Strategy).
        $dropcodes = [];
        if (preg_match_all('/\[PLAYERHUD_DROP\s+([^\]]+)\]/i', $text, $matches)) {
            foreach ($matches[1] as $attrstr) {
                if (preg_match('/code=([a-zA-Z0-9]+)/i', $attrstr, $code)) {
                    $dropcodes[] = $code[1];
                }
            }
        }

        $tradecodes = [];
        if (preg_match_all('/\[PLAYERHUD_TRADE\s+code=([a-zA-Z0-9]+)\]/i', $text, $matches)) {
            $tradecodes = $matches[1];
        }

        // Bulk load data in render class to prevent queries inside preg_replace_callback.
        if ((!empty($dropcodes) || !empty($tradecodes)) && class_exists('\filter_playerhud\output\render')) {
            \filter_playerhud\output\render::preload_data($dropcodes, $tradecodes, $blockinstance->id);
        }

        if (!empty($dropcodes)) {
            $text = preg_replace_callback('/\[PLAYERHUD_DROP\s+([^\]]+)\]/i', function ($matches) use ($blockinstance) {
                return \filter_playerhud\output\render::render_drop($matches[1], $blockinstance->id);
            }, $text);
            $needsassets = true;
        }

        if (!empty($tradecodes)) {
            $text = preg_replace_callback(
                '/\[PLAYERHUD_TRADE\s+code=([a-zA-Z0-9]+)\]/i',
                function ($matches) use ($blockinstance) {
                    return \filter_playerhud\output\render::render_trade_by_code($matches[1], $blockinstance->id);
                },
                $text
            );
            $needsassets = true;
        }

        // Inject global assets (Modals and JS) if needed.
        if ($needsassets && !self::$assetsinjected) {
            if (class_exists('\filter_playerhud\output\assets')) {
                $assets = new \filter_playerhud\output\assets();
                $text .= $assets->get_modals_html();
            }

            $jstimerstrings = [
                'ready' => get_string('ready', 'block_playerhud'),
                'take'  => get_string('take', 'block_playerhud'),
                'label' => get_string('next_collection_in', 'block_playerhud'),
                'min'   => get_string('time_min', 'block_playerhud'),
                'sec'   => get_string('time_sec', 'block_playerhud'),
            ];

            if (isset($PAGE) && $PAGE->requires) {
                $PAGE->requires->js_call_amd('block_playerhud/timers', 'init', [$jstimerstrings]);
            }

            $jscollectstrings = [
                'collected' => get_string('collected', 'block_playerhud'),
                'error' => get_string('error_connection', 'block_playerhud'),
                'last_collected' => get_string('last_collected', 'block_playerhud'),
                'confirm_title' => get_string('confirmation', 'admin'),
                'yes' => get_string('yes'),
                'cancel' => get_string('cancel'),
                'level' => get_string('level', 'block_playerhud'),
                'xp' => get_string('xp', 'block_playerhud'),
            ];

            if (isset($PAGE) && $PAGE->requires) {
                $PAGE->requires->js_call_amd('block_playerhud/filter_collect', 'init', [['strings' => $jscollectstrings]]);
            }

            self::$assetsinjected = true;
        }

        return $text;
    }
}
