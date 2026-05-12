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
 * All step definitions shared with block_playerhud (label creation, modal
 * assertions, URL tracking, etc.) are declared in behat_block_playerhud and
 * are globally available because both plugins are installed together during CI.
 * This class is intentionally empty to avoid duplicate step definition errors.
 */
class behat_filter_playerhud extends behat_base {
}
