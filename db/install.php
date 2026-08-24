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
 * PlayerHUD filter post install hook.
 *
 * @package    filter_playerhud
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Enables the filter by default on a fresh install.
 *
 * Without this, the filter would install in Moodle's default "Off, but available" state and
 * shortcodes typed in course content would stay as literal unprocessed text until an admin
 * manually enables it in Site administration > Plugins > Filters > Manage filters. Mirrors the
 * pattern used by core filters such as filter_urltolink and filter_mediaplugin.
 *
 * @return void
 */
function xmldb_filter_playerhud_install(): void {
    global $CFG;
    require_once($CFG->libdir . '/filterlib.php');

    filter_set_global_state('playerhud', TEXTFILTER_ON);
}
