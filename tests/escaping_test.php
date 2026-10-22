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
 * Tests that the names the filter renders are escaped exactly once.
 *
 * @package    filter_playerhud
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_playerhud;

use advanced_testcase;
use filter_playerhud\output\render;
use filter_playerhud\output\widget;

/**
 * An item, trade or class named with an ampersand and quotes must reach the page escaped once:
 * format_string() already returns HTML, so a double-mustache tag would print "&amp;amp;".
 *
 * @package    filter_playerhud
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \filter_playerhud\output\render
 * @covers     \filter_playerhud\output\widget
 */
final class escaping_test extends advanced_testcase {
    /** @var string Name used for every record. */
    private const CANARY = 'Cafe & "Co"';

    /** @var string The canary escaped exactly once. */
    private const ESCAPED = 'Cafe &amp; &quot;Co&quot;';

    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The student. */
    private \stdClass $student;

    /** @var int The block instance id. */
    private int $blockid;

    /**
     * Builds a course with a PlayerHUD block and a student who plays.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB, $COURSE, $PAGE;

        $this->resetAfterTest(true);
        $this->course = $this->getDataGenerator()->create_course();
        $COURSE = $this->course;
        $context = \context_course::instance($this->course->id);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($this->student);

        $this->blockid = (int) $this->getDataGenerator()->create_block('playerhud', [
            'parentcontextid' => $context->id,
        ])->id;
        $DB->set_field('block_instances', 'configdata', base64_encode(serialize(new \stdClass())), ['id' => $this->blockid]);
        $PAGE->set_url('/course/view.php', ['id' => $this->course->id]);
        $PAGE->set_context($context);

        $now = time();
        $DB->insert_record('block_playerhud_user', (object) [
            'blockinstanceid' => $this->blockid, 'userid' => $this->student->id, 'currentxp' => 10,
            'enable_gamification' => 1, 'ranking_visibility' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        render::reset_caches();
        text_filter::reset_caches();
    }

    /**
     * Resets the static caches so one test does not feed the next.
     */
    protected function tearDown(): void {
        render::reset_caches();
        text_filter::reset_caches();
        parent::tearDown();
    }

    /**
     * Sets the site setting and makes format_string() read it again; it caches its result per container
     * without the setting in the key.
     *
     * @param int $striptags Value of formatstringstriptags.
     */
    private function apply_striptags(int $striptags): void {
        set_config('formatstringstriptags', $striptags);
        \core\di::reset_container();
    }

    /**
     * Creates an item named with the canary.
     *
     * @return int The item id.
     */
    private function create_item(): int {
        global $DB;

        return (int) $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $this->blockid, 'name' => self::CANARY, 'xp' => 10, 'enabled' => 1,
            'secret' => 0, 'tradable' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Asserts that the canary appears escaped once and never twice.
     *
     * @param string $html The rendered HTML.
     */
    private function assert_escaped_once(string $html): void {
        $this->assertStringContainsString(self::ESCAPED, $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('&amp;quot;', $html);
    }

    /**
     * Data provider: the two values of formatstringstriptags.
     *
     * @return array
     */
    public static function striptags_provider(): array {
        return ['strip tags on' => [1], 'strip tags off' => [0]];
    }

    /**
     * A drop shows the item name in the button, in the hidden labels and in the data the modal reads.
     *
     * @dataProvider striptags_provider
     * @param int $striptags Value of formatstringstriptags.
     */
    public function test_drop_escapes_the_item_name_once(int $striptags): void {
        global $DB;

        $this->apply_striptags($striptags);
        $now = time();
        $DB->insert_record('block_playerhud_drops', (object) [
            'blockinstanceid' => $this->blockid, 'itemid' => $this->create_item(), 'name' => 'Drop', 'maxusage' => 1,
            'value' => 1, 'respawntime' => 0, 'code' => 'ESC1', 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $filter = new text_filter(\context_course::instance($this->course->id), []);
        $html = $filter->filter(
            '[PLAYERHUD_DROP mode=card code=ESC1] [PLAYERHUD_DROP mode=text code=ESC1] '
            . '[PLAYERHUD_DROP mode=image code=ESC1]'
        );

        $this->assert_escaped_once($html);
        $this->assertStringContainsString('data-name="' . self::ESCAPED . '"', $html);
    }

    /**
     * A trade shows its own name and the names of the items it asks for and gives.
     *
     * @dataProvider striptags_provider
     * @param int $striptags Value of formatstringstriptags.
     */
    public function test_trade_escapes_the_trade_and_item_names_once(int $striptags): void {
        global $DB;

        $this->apply_striptags($striptags);
        $now = time();
        $tradeid = $DB->insert_record('block_playerhud_trades', (object) [
            'blockinstanceid' => $this->blockid, 'name' => self::CANARY, 'groupid' => 0, 'centralized' => 1,
            'onetime' => 0, 'code' => 'TRD1', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        foreach (['block_playerhud_trade_reqs', 'block_playerhud_trade_rewards'] as $table) {
            $DB->insert_record($table, (object) [
                'tradeid' => $tradeid, 'itemid' => $this->create_item(), 'qty' => 1,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }

        $html = render::render_trade($tradeid, $this->blockid);

        $this->assertNotSame('', $html);
        $this->assert_escaped_once($html);
        $this->assertStringContainsString('aria-label="' . self::ESCAPED . '"', $html);
        $this->assertStringContainsString('title="' . self::ESCAPED . '"', $html);
    }

    /**
     * The widget shows the recent items and the class name, and its popover JSON carries plain text.
     *
     * @dataProvider striptags_provider
     * @param int $striptags Value of formatstringstriptags.
     */
    public function test_widget_escapes_names_once(int $striptags): void {
        global $DB, $OUTPUT, $PAGE;

        $this->apply_striptags($striptags);
        $now = time();
        for ($i = 0; $i < 6; $i++) {
            $DB->insert_record('block_playerhud_inventory', (object) [
                'userid' => $this->student->id, 'itemid' => $this->create_item(), 'dropid' => 0, 'source' => 'map',
                'timecreated' => $now + $i, 'xpawarded' => 0,
            ]);
        }
        $classid = $DB->insert_record('block_playerhud_classes', (object) [
            'blockinstanceid' => $this->blockid, 'name' => self::CANARY, 'base_hp' => 10,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('block_playerhud_rpg_progress', (object) [
            'blockinstanceid' => $this->blockid, 'userid' => $this->student->id, 'classid' => $classid, 'karma' => 0,
            'current_nodes' => '{}', 'completed_chapters' => '[]', 'timecreated' => $now, 'timemodified' => $now,
        ]);

        $DB->set_field('block_instances', 'configdata', base64_encode(serialize((object) [
            'enable_rpg' => 1,
        ])), ['id' => $this->blockid]);
        $instance = $DB->get_record('block_instances', ['id' => $this->blockid], '*', MUST_EXIST);
        $widget = new widget($instance, $this->course->id);
        $output = $PAGE->get_renderer('core');
        $data = $widget->export_for_template($output);
        $html = $OUTPUT->render_from_template('filter_playerhud/widget', $data);

        $this->assert_escaped_once($html);
        $this->assertStringContainsString('data-name="' . self::ESCAPED . '"', $html);
        $this->assertSame(1, preg_match('/data-overflow="([^"]*)"/', $html, $matches));
        $overflow = json_decode(html_entity_decode($matches[1]), true);
        $this->assertSame(self::CANARY, $overflow[0]['n']);
    }
}
