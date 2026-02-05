<?php
namespace filter_playerhud\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider for filter_playerhud.
 * Implementa null_provider pois este plugin não armazena dados próprios.
 *
 * @package    filter_playerhud
 * @copyright  2026 Jean Lúcio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\null_provider {

    /**
     * Retorna a string de idioma que explica por que este plugin não armazena dados.
     *
     * @return string O identificador da string de idioma.
     */
    public static function get_reason() : string {
        return 'privacy:metadata';
    }
}