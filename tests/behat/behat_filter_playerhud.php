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

/**
 * Step definitions for filter_playerhud Behat tests.
 *
 * @package    filter_playerhud
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing

/**
 * Custom Behat step definitions for the PlayerHUD filter.
 */
class behat_filter_playerhud extends behat_base {
    /**
     * Creates a Moodle label (mod_label) in the current course containing the given shortcode text.
     *
     * Used to embed a [PLAYERHUD_DROP] shortcode in course content so the filter
     * renders the collect button on the course homepage.
     *
     * @param string $shortcode Raw shortcode string, e.g. [PLAYERHUD_DROP code=GEM01].
     * @Given a label with shortcode :shortcode exists in the course
     */
    public function label_with_shortcode_exists_in_course(string $shortcode): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $url     = $this->getSession()->getCurrentUrl();
        $matches = [];
        // Moodle course view URL format: course/view.php?id=NNN.
        preg_match('/[?&]id=(\d+)/', $url, $matches);
        if (empty($matches[1])) {
            throw new \Exception('Cannot determine course id from current URL: ' . $url);
        }
        $courseid = (int) $matches[1];
        $course   = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        $moduleinfo              = new \stdClass();
        $moduleinfo->modulename  = 'label';
        $moduleinfo->course      = $course->id;
        $moduleinfo->section     = 0;
        $moduleinfo->visible     = 1;
        $moduleinfo->intro       = $shortcode;
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->name        = 'PlayerHUD shortcode label';
        create_module($moduleinfo);
    }

    /**
     * Clicks the first element matching a CSS selector on the page.
     *
     * @param string $selector CSS selector.
     * @When I click on the first :selector element
     */
    public function i_click_on_first_css_element(string $selector): void {
        $node = $this->find('css', $selector);
        $node->click();
    }

    /**
     * Stores the current page URL for later comparison.
     *
     * @When I remember the current page URL
     */
    public function i_remember_current_page_url(): void {
        $this->pageurl = $this->getSession()->getCurrentUrl();
    }

    /** @var string|null Remembered URL for redirect detection. */
    protected ?string $pageurl = null;

    /**
     * Asserts that the current URL has not changed since it was remembered.
     *
     * @Then the page URL has not changed
     */
    public function the_page_url_has_not_changed(): void {
        if ($this->pageurl === null) {
            throw new \Exception("No URL was remembered. Use 'I remember the current page URL' first.");
        }
        $current = $this->getSession()->getCurrentUrl();
        if ($current !== $this->pageurl) {
            throw new \Exception("Page was redirected. Expected: {$this->pageurl} — Got: {$current}");
        }
    }

    /**
     * Waits for a PlayerHUD AJAX collect response (success indicator in DOM).
     *
     * Waits until the collect button disappears or changes state.
     *
     * @When I wait for the PlayerHUD AJAX collect to complete
     */
    public function i_wait_for_playerhud_ajax_collect(): void {
        $this->spin(function () {
            $js = "return document.querySelector('.ph-action-collect') === null
                || document.querySelector('.ph-action-collect.disabled') !== null
                || document.querySelector('.ph-action-collect[aria-disabled]') !== null;";
            return (bool) $this->getSession()->evaluateScript($js);
        }, false, 10);
    }

    /**
     * Asserts that the PlayerHUD item details modal is visible in the DOM.
     *
     * @Then the PlayerHUD item details modal is visible
     */
    public function playerhud_item_details_modal_is_visible(): void {
        $this->spin(function () {
            $candidates = ['#phItemModalFilter', '#phItemModalView', '#ph-item-modal-view'];
            foreach ($candidates as $sel) {
                try {
                    $node = $this->find('css', $sel);
                    if ($node && $node->isVisible()) {
                        return true;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
            return false;
        });
    }

    /**
     * Asserts that the given text appears inside the visible PlayerHUD modal.
     *
     * @param string $text Text to search for.
     * @Then I should see :text in the PlayerHUD modal
     */
    public function i_should_see_text_in_playerhud_modal(string $text): void {
        $candidates = ['#phItemModalFilter', '#phItemModalView', '#ph-item-modal-view'];
        foreach ($candidates as $sel) {
            try {
                $node = $this->find('css', $sel);
                if ($node && $node->isVisible() && str_contains($node->getText(), $text)) {
                    return;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        throw new \Exception("Text '{$text}' not found in any visible PlayerHUD modal.");
    }

    /**
     * Asserts that the given text does NOT appear inside the visible PlayerHUD modal.
     *
     * @param string $text Text that must be absent.
     * @Then I should not see :text in the PlayerHUD modal
     */
    public function i_should_not_see_text_in_playerhud_modal(string $text): void {
        $candidates = ['#phItemModalFilter', '#phItemModalView', '#ph-item-modal-view'];
        foreach ($candidates as $sel) {
            try {
                $node = $this->find('css', $sel);
                if ($node && $node->isVisible() && str_contains($node->getText(), $text)) {
                    throw new \Exception(
                        "Text '{$text}' was found in PlayerHUD modal {$sel} but should not be."
                    );
                }
            } catch (\Behat\Mink\Exception\ElementNotFoundException $e) {
                continue;
            }
        }
    }

    /**
     * Closes the currently visible PlayerHUD modal by clicking its dismiss button.
     *
     * @When I close the PlayerHUD modal
     */
    public function i_close_the_playerhud_modal(): void {
        $candidates = [
            '#phItemModalFilter [data-bs-dismiss="modal"]',
            '#phItemModalView [data-bs-dismiss="modal"]',
            '#ph-item-modal-view [data-bs-dismiss="modal"]',
        ];
        foreach ($candidates as $sel) {
            try {
                $node = $this->find('css', $sel);
                if ($node && $node->isVisible()) {
                    $node->click();
                    $this->getSession()->wait(500);
                    return;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        throw new \Exception("No visible PlayerHUD modal dismiss button found.");
    }

    /**
     * Asserts that only one PlayerHUD item details modal element exists in the DOM.
     *
     * @Then there is only one PlayerHUD modal in the DOM
     */
    public function there_is_only_one_playerhud_modal_in_dom(): void {
        $js = "return document.querySelectorAll(
            '#phItemModalFilter, #phItemModalView, #ph-item-modal-view'
        ).length;";
        $count = $this->getSession()->evaluateScript($js);
        if ((int) $count > 1) {
            throw new \Exception(
                "Expected 1 PlayerHUD modal in DOM but found {$count}. Modal is being duplicated."
            );
        }
    }
}
