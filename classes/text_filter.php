<?php
namespace filter_playerhud;

defined('MOODLE_INTERNAL') || die();

/**
 * Text filter for PlayerHUD (Block Version).
 * Delegates rendering to specialized output classes.
 */
class text_filter extends \moodle_text_filter {

    /** @var bool Flag to ensure assets are injected only once. */
    protected static $assetsinjected = false;

    /** @var array Cache for block instances in courses. */
    protected static $blockcache = [];

    public function filter($text, array $options = []) {
        global $USER, $DB, $COURSE, $CFG, $PAGE;

        // 1. Quick fail check.
        if (strpos($text, '[PLAYERHUD_') === false) {
            return $text;
        }

        // 2. Validate Context & Login.
        if (!isloggedin() || isguestuser() || $COURSE->id == SITEID) {
            $text = str_replace('[PLAYERHUD_WIDGET]', '', $text);
            $text = preg_replace('/\[PLAYERHUD_DROP\s+[^\]]+\]/', '', $text);
            $text = preg_replace('/\[PLAYERHUD_TRADE\s+[^\]]+\]/', '', $text);
            return $text;
        }

        // 3. Find Block Instance for this Course.
        if (!isset(self::$blockcache[$COURSE->id])) {
            $context = \context_course::instance($COURSE->id);
            $sql = "SELECT bi.id, bi.configdata 
                      FROM {block_instances} bi
                     WHERE bi.blockname = 'playerhud' 
                       AND bi.parentcontextid = :ctxid";
            
            $record = $DB->get_record_sql($sql, ['ctxid' => $context->id], IGNORE_MULTIPLE);
            self::$blockcache[$COURSE->id] = $record;
        }
        
        $blockinstance = self::$blockcache[$COURSE->id];

        if (!$blockinstance) {
            $text = str_replace('[PLAYERHUD_WIDGET]', '', $text);
            $text = preg_replace('/\[PLAYERHUD_DROP\s+[^\]]+\]/', '', $text);
            $text = preg_replace('/\[PLAYERHUD_TRADE\s+[^\]]+\]/', '', $text);
            return $text;
        }

        // 4. Load Logic Classes.
        $needs_assets = false;

        // A. WIDGET
        if (strpos($text, '[PLAYERHUD_WIDGET]') !== false) {
            if (class_exists('\filter_playerhud\output\widget')) {
                $widgetRenderer = new \filter_playerhud\output\widget($blockinstance, $COURSE->id);
                $html = $widgetRenderer->render();
                $text = str_replace('[PLAYERHUD_WIDGET]', $html, $text);
                $needs_assets = true;
            }
        }

        // B. DROPS
        if (strpos($text, '[PLAYERHUD_DROP') !== false) {
            if (method_exists('\filter_playerhud\output\render', 'render_drop')) {
                $text = preg_replace_callback('/\[PLAYERHUD_DROP\s+([^\]]+)\]/i', function($matches) use ($blockinstance) {
                    return \filter_playerhud\output\render::render_drop($matches[1], $blockinstance->id);
                }, $text);
                $needs_assets = true;
            }
        }

        // C. TRADES
        if (strpos($text, '[PLAYERHUD_TRADE') !== false) {
            if (method_exists('\filter_playerhud\output\render', 'render_trade')) {
                $text = preg_replace_callback('/\[PLAYERHUD_TRADE\s+id=(\d+)\]/i', function($matches) use ($blockinstance) {
                    return \filter_playerhud\output\render::render_trade($matches[1], $blockinstance->id);
                }, $text);
                $needs_assets = true;
            }
        }

        // 5. Inject Global Assets (JS via AMD / Modais) only once.
        if ($needs_assets && !self::$assetsinjected) {
            
            if (class_exists('\filter_playerhud\output\assets')) {
                $assets = new \filter_playerhud\output\assets();
                $text .= $assets->get_modals_html();
            }

            // A. JS de Timers
            $jsTimerStrings = [
                'ready' => get_string('ready', 'block_playerhud'),
                'take'  => get_string('take', 'block_playerhud'),
                'label' => get_string('next_collection_in', 'block_playerhud')
            ];
            if (isset($PAGE) && $PAGE->requires) {
                $PAGE->requires->js_call_amd('block_playerhud/timers', 'init', [$jsTimerStrings]);
            }

            // B. JS de Coleta AJAX [ATUALIZADO AQUI]
            $jsCollectStrings = [
                'collected' => get_string('collected', 'block_playerhud'),
                'error' => get_string('error_connection', 'block_playerhud'),
                // Adicionando a string que faltava:
                'last_collected' => get_string('last_collected', 'block_playerhud'),
                'confirm_title' => get_string('confirmation', 'admin'),
                'yes' => get_string('yes'),
                'cancel' => get_string('cancel') 
            ];
            if (isset($PAGE) && $PAGE->requires) {
                $PAGE->requires->js_call_amd('block_playerhud/filter_collect', 'init', [$jsCollectStrings]);
            }

            self::$assetsinjected = true;
        }

        return $text;
    }
}
