<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace filter_playerhud\tests;

use advanced_testcase;
use filter_playerhud;

/**
 * Tests for the PlayerHUD text filter.
 *
 * @package    filter_playerhud
 * @category   test
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class filter_test extends advanced_testcase {
    /**
     * Test that shortcodes are properly parsed into HTML.
     *
     * @covers \filter_playerhud::filter
     */
    public function test_filter_parsing(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        // Instantiate the filter.
        $filter = new \filter_playerhud($context, []);

        $inputtext = 'Here is a drop: [PLAYERHUD_DROP code=XPTO123] in the middle of the text.';
        $filteredtext = $filter->filter($inputtext);

        // Assert the shortcode was removed and replaced with the plugin's HTML button/container.
        $this->assertStringNotContainsString('[PLAYERHUD_DROP code=XPTO123]', $filteredtext);
        $this->assertStringContainsString('ph-action-collect', $filteredtext);
    }

    /**
     * Test filter performance to ensure Zero N+1 Queries (Bulk Fetching).
     *
     * @covers \filter_playerhud::filter
     */
    public function test_filter_performance_zero_n1(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $filter = new \filter_playerhud($context, []);

        // Create a text with 5 different drops.
        $inputtext = '
            Drop 1: [PLAYERHUD_DROP code=CODE1]
            Drop 2: [PLAYERHUD_DROP code=CODE2]
            Drop 3: [PLAYERHUD_DROP code=CODE3]
            Drop 4: [PLAYERHUD_DROP code=CODE4]
            Drop 5: [PLAYERHUD_DROP code=CODE5]
        ';

        // 1. Warm up the cache (optional, but good practice).
        $filter->filter('Initial text');

        // 2. Start measuring database reads.
        $readsbefore = $DB->perf_get_reads();

        // 3. Run the filter with 5 shortcodes.
        $filter->filter($inputtext);

        // 4. Calculate total reads.
        $readsafter = $DB->perf_get_reads();
        $totalreads = $readsafter - $readsbefore;

        // 5. The core architectural assertion:
        // Even with 5 shortcodes, it should NOT make 5 separate queries.
        // It should do 1 bulk query (get_records_list or similar) or hit the cache.
        // We allow up to 2 reads (one for block instances, one for drops).
        $this->assertLessThanOrEqual(2, $totalreads, 'The filter is making too many DB queries! Possible N+1 issue detected.');
    }
}
