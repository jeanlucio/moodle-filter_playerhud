<?php
namespace filter_playerhud\output;

defined('MOODLE_INTERNAL') || die();

class assets {

    /**
     * Retorna apenas o HTML dos modais.
     * O JavaScript agora é carregado via AMD no text_filter.php.
     */
    public function get_modals_html() {
        return '
        <div class="modal fade" id="phItemModalFilter" tabindex="-1" aria-hidden="true" style="z-index: 10500;">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="phModalTitleF">' . get_string('details', 'block_playerhud') . '</h5>
                        <button type="button" class="btn-close ph-modal-close-f" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex align-items-start">
                            <div id="phModalImageContainerF" class="me-3 text-center" style="min-width: 80px;"></div>
                            <div>
                                <h4 id="phModalNameF" class="m-0 fw-bold"></h4>
                                <div id="phModalDescF" class="text-muted mt-2"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>';
    }
}