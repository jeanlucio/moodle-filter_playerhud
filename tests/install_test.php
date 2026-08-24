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

namespace filter_playerhud;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/filter/playerhud/db/install.php');
require_once($CFG->libdir . '/filterlib.php');

/**
 * Tests for the PlayerHUD filter post-install hook.
 *
 * @package    filter_playerhud
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::xmldb_filter_playerhud_install
 */
final class install_test extends advanced_testcase {
    /**
     * A fresh install must leave the filter enabled, not in Moodle's default
     * "Off, but available" state, otherwise shortcodes stay as literal unprocessed
     * text until an admin manually enables the filter.
     */
    public function test_install_enables_the_filter_by_default(): void {
        $this->resetAfterTest(true);

        // Start from the state a brand-new install would have before the hook runs.
        filter_set_global_state('playerhud', TEXTFILTER_DISABLED);

        xmldb_filter_playerhud_install();

        $states = filter_get_global_states();
        $this->assertArrayHasKey('playerhud', $states);
        $this->assertEquals(TEXTFILTER_ON, $states['playerhud']->active);
    }
}
