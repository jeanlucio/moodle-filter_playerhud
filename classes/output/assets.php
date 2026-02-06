<?php
namespace filter_playerhud\output;

defined('MOODLE_INTERNAL') || die();

class assets {

    /**
     * Retorna apenas o HTML dos modais usando Template.
     */
    public function get_modals_html() {
        global $OUTPUT;
        
        $data = [
            'str_details' => get_string('details', 'block_playerhud'),
            'str_close' => get_string('close', 'block_playerhud'), // Ou 'close' do core
        ];
        
        return $OUTPUT->render_from_template('filter_playerhud/modals', $data);
    }
}
