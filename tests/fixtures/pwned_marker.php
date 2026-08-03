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
 * Test double proving unserialize_object() blocks PHP object injection.
 *
 * @package    filter_playerhud
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_playerhud;

/**
 * A class smuggled through a crafted configdata payload must never be instantiated as
 * itself, so its __wakeup() side effect (simulating a POP-gadget) must never fire.
 *
 * @package    filter_playerhud
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filter_playerhud_pwned_marker {
    /** @var int Times __wakeup() has fired across the test run. */
    public static int $wakeups = 0;

    /**
     * Records that this class was actually instantiated during unserialize().
     */
    public function __wakeup(): void {
        self::$wakeups++;
    }
}
