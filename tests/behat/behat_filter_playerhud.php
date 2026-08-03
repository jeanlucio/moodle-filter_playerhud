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
 * @copyright  2026 Jean Lucio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing

/**
 * Custom Behat step definitions for the PlayerHUD filter.
 *
 * Most step definitions shared with block_playerhud (label creation, generic modal
 * assertions, URL tracking, etc.) are declared in behat_block_playerhud and are globally
 * available because both plugins are installed together during CI.
 *
 * This class holds only steps that inspect the item-description modal populated by
 * filter_collect.js — kept here rather than in the block's step file so ownership of the
 * assertion matches ownership of the vulnerability class it guards against (this plugin's
 * render_drop(), not the block's own rendering).
 */
class behat_filter_playerhud extends behat_base {
    /**
     * Asserts that the raw HTML of the item description modal does NOT contain the given
     * substring.
     *
     * Deliberately inspects getHtml() (the DOM markup/attributes), not getText() (rendered
     * visible text) — the two are not equivalent for XSS purposes. A dangerous attribute
     * like onerror="..." never shows up as visible text either way, so a text-based
     * assertion cannot tell a sanitised description from a live-HTML-injected one; only
     * inspecting the actual markup does. See "does not render raw HTML tags" below for what
     * a text-based assertion in this same feature does and does not prove.
     *
     * The description container's id suffix depends on which modal root filter_collect.js's
     * own getModalElements() finds first (#phItemModalView / #ph-item-modal-view from
     * block_playerhud when the block is on the page, or this plugin's own #phItemModalFilter
     * otherwise) — so both candidate ids are tried, mirroring that same resolution order.
     *
     * @param string $text Substring that must be absent from the modal description's HTML.
     * @Then the PlayerHUD filter modal description HTML should not contain :text
     */
    public function the_playerhud_filter_modal_description_html_should_not_contain(string $text): void {
        $candidates = ['#phModalDescView', '#phModalDescF'];
        foreach ($candidates as $selector) {
            try {
                $node = $this->find('css', $selector);
            } catch (\Behat\Mink\Exception\ElementNotFoundException $e) {
                continue;
            }
            $html = $node->getHtml();
            if (str_contains($html, $text)) {
                throw new \Exception(
                    "'{$text}' was found in the PlayerHUD modal description HTML ({$selector}): {$html}"
                );
            }
            return;
        }
        throw new \Exception('No PlayerHUD modal description element found on the page.');
    }
}
