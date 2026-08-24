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
use core_useragent;
use filter_playerhud\output\assets;
use filter_playerhud\output\widget;
use filter_playerhud\privacy\provider;
use filter_playerhud\text_filter;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/filter/playerhud/tests/fixtures/wakeup_probe.php');

/**
 * Tests for the PlayerHUD text filter.
 *
 * @package    filter_playerhud
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \filter_playerhud\output\render
 * @covers \filter_playerhud\output\widget
 */
final class filter_test extends advanced_testcase {
    /**
     * Setups a complete Moodle course environment with a logged-in user,
     * a block instance, and basic item structures to satisfy the filter requirements.
     *
     * @return array Array containing the context, instance ID, item ID, and user object.
     */
    protected function setup_environment(): array {
        global $DB, $COURSE;

        // 1. Create Course and Context.
        $course = $this->getDataGenerator()->create_course();
        $COURSE = $course; // Force global COURSE to bypass SITEID security check.
        $coursecontext = \context_course::instance($course->id);

        // 2. Create User and Log in.
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        // 3. Create a real block instance.
        $bi = new \stdClass();
        $bi->blockname = 'playerhud';
        $bi->parentcontextid = $coursecontext->id;
        $bi->showinsubcontexts = 0;
        $bi->pagetypepattern = 'course-view-*';
        $bi->subpagepattern = null;
        $bi->defaultregion = 'side-pre';
        $bi->defaultweight = 0;
        $bi->configdata = base64_encode(serialize(new \stdClass()));
        $bi->timecreated = time();
        $bi->timemodified = time();
        $instanceid = $DB->insert_record('block_instances', $bi);

        // 4. Create PlayerHUD user profile (Active gamification).
        $player = new \stdClass();
        $player->blockinstanceid = $instanceid;
        $player->userid = $user->id;
        $player->currentxp = 0;
        $player->enable_gamification = 1;
        $player->ranking_visibility = 1;
        $player->timecreated = time();
        $player->timemodified = time();
        $DB->insert_record('block_playerhud_user', $player);

        // 5. Create a Dummy Item.
        $item = new \stdClass();
        $item->blockinstanceid = $instanceid;
        $item->name = 'Test Item';
        $item->xp = 100;
        $item->image = '';
        $item->description = '';
        $item->enabled = 1;
        $item->secret = 0;
        $item->timecreated = time();
        $item->timemodified = time();
        $itemid = $DB->insert_record('block_playerhud_items', $item);

        return [$coursecontext, $instanceid, $itemid, $user];
    }

    /**
     * Tear down the test environment.
     * Cleans up static caches to prevent data leaking between tests.
     */
    protected function tearDown(): void {
        \filter_playerhud\output\render::reset_caches();
        \filter_playerhud\text_filter::reset_caches();
        parent::tearDown();
    }

    /**
     * Test that shortcodes are properly parsed into HTML.
     *
     * @covers \filter_playerhud\text_filter::filter
     */
    public function test_filter_parsing(): void {
        global $DB;
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid] = $this->setup_environment();

        // Create a Drop for the shortcode.
        $drop = new \stdClass();
        $drop->blockinstanceid = $instanceid;
        $drop->itemid = $itemid;
        $drop->name = 'Test Drop';
        $drop->maxusage = 1;
        $drop->respawntime = 0;
        $drop->code = 'XPTO123';
        $drop->timecreated = time();
        $drop->timemodified = time();
        $DB->insert_record('block_playerhud_drops', $drop);

        $filter = new text_filter($context, []);
        $inputtext = 'Here is a drop: [PLAYERHUD_DROP code=XPTO123] in the middle.';
        $filteredtext = $filter->filter($inputtext);

        // Assert the shortcode was removed and replaced with the plugin's HTML button.
        $this->assertStringNotContainsString('[PLAYERHUD_DROP code=XPTO123]', $filteredtext);
        $this->assertStringContainsString('ph-action-collect', $filteredtext);
    }

    /**
     * Test filter performance to ensure Zero N+1 Queries (Bulk Fetching).
     *
     * @covers \filter_playerhud\text_filter::filter
     */
    public function test_filter_performance_zero_n1(): void {
        global $DB;
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid] = $this->setup_environment();

        $codes = ['CODE1', 'CODE2', 'CODE3', 'CODE4', 'CODE5'];
        foreach ($codes as $code) {
            $drop = new \stdClass();
            $drop->blockinstanceid = $instanceid;
            $drop->itemid = $itemid;
            $drop->name = 'Test Drop ' . $code;
            $drop->maxusage = 1;
            $drop->respawntime = 0;
            $drop->code = $code;
            $drop->timecreated = time();
            $drop->timemodified = time();
            $DB->insert_record('block_playerhud_drops', $drop);
        }

        $filter = new text_filter($context, []);
        $inputtext = '
            Drop 1: [PLAYERHUD_DROP code=CODE1]
            Drop 2: [PLAYERHUD_DROP code=CODE2]
            Drop 3: [PLAYERHUD_DROP code=CODE3]
            Drop 4: [PLAYERHUD_DROP code=CODE4]
            Drop 5: [PLAYERHUD_DROP code=CODE5]
        ';

        // 1. WARM UP (Moodle Warmup)
        // Forces Moodle core to fetch and cache all translation strings, Mustache templates, and contexts.
        $filter->filter('Warmup [PLAYERHUD_DROP code=CODE1]');

        // 2. Clear ONLY our plugin's static cache to simulate a new page load (keeping Moodle warm).
        \filter_playerhud\text_filter::reset_caches();
        \filter_playerhud\output\render::reset_caches();

        // 3. Start the Database timer
        $readsbefore = $DB->perf_get_reads();

        // 4. Run the filter for 5 simultaneous drops!
        $filter->filter($inputtext);

        // 5. Calculate total reads
        $readsafter = $DB->perf_get_reads();
        $totalreads = $readsafter - $readsbefore;

        // 6. The Architectural Assertion:
        // A perfect O(1) architecture makes exactly 5 base queries for the entire page:
        // (1 Block + 1 Filter User + 1 Drops Batch + 1 File Storage + 1 Inventory Batch)
        // The render player query is eliminated by populate_player_cache() in text_filter.
        // The RPG class query is skipped because test items have required_class_id = '0'.
        // If they were separate (N+1) queries, it would result in more than 25 reads.
        $this->assertLessThanOrEqual(5, $totalreads, "The filter is making $totalreads DB queries! Possible N+1 issue detected.");
    }

    /**
     * A drop's media, once bulk-loaded by an earlier preload_data() batch in the same request
     * (e.g. two separate text blocks on the same page, like a forum post's intro and its own
     * content), must still be served from cache in a later render — not silently dropped by a
     * second, unrelated batch overwriting the whole media cache instead of merging into it.
     *
     * @covers \filter_playerhud\output\render::preload_data
     */
    public function test_media_cache_survives_a_later_unrelated_preload_batch(): void {
        global $DB, $COURSE;
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid] = $this->setup_environment();
        $course = get_course($context->instanceid);

        $otheritem = new \stdClass();
        $otheritem->blockinstanceid = $instanceid;
        $otheritem->name = 'Other Item';
        $otheritem->xp = 50;
        $otheritem->image = '';
        $otheritem->description = '';
        $otheritem->enabled = 1;
        $otheritem->secret = 0;
        $otheritem->timecreated = time();
        $otheritem->timemodified = time();
        $otheritemid = $DB->insert_record('block_playerhud_items', $otheritem);

        $drop = new \stdClass();
        $drop->blockinstanceid = $instanceid;
        $drop->itemid = $itemid;
        $drop->name = 'First batch drop';
        $drop->maxusage = 1;
        $drop->respawntime = 0;
        $drop->code = 'FIRSTBATCH';
        $drop->timecreated = time();
        $drop->timemodified = time();
        $DB->insert_record('block_playerhud_drops', $drop);

        $otherdrop = new \stdClass();
        $otherdrop->blockinstanceid = $instanceid;
        $otherdrop->itemid = $otheritemid;
        $otherdrop->name = 'Second batch drop';
        $otherdrop->maxusage = 1;
        $otherdrop->respawntime = 0;
        $otherdrop->code = 'SECONDBATCH';
        $otherdrop->timecreated = time();
        $otherdrop->timemodified = time();
        $DB->insert_record('block_playerhud_drops', $otherdrop);

        $filter = new text_filter($context, []);

        // Warm up Moodle core caches, then reset only this plugin's static state.
        $filter->filter('Warmup [PLAYERHUD_DROP code=FIRSTBATCH]');
        \filter_playerhud\text_filter::reset_caches();
        \filter_playerhud\output\render::reset_caches();

        // A successful render's asset-injection branch (js_call_amd()/strings_for_js()) leaves
        // $COURSE pointing back at the site course as a side effect of $PAGE's own lazy init in
        // this bare PHPUnit context — restore it before every subsequent call, matching what a
        // real page render keeps stable throughout a single request.
        $COURSE = $course;

        // Batch 1: preloads FIRSTBATCH's drop and its item's media.
        $filter->filter('[PLAYERHUD_DROP code=FIRSTBATCH]');
        $COURSE = $course;

        // Batch 2: a completely unrelated code, in a separate filter() call — simulating a
        // second text block on the same page. With the bug, this call's media preload
        // (self::$dropmediacache = ...) wipes out FIRSTBATCH's item media collected above.
        $filter->filter('[PLAYERHUD_DROP code=SECONDBATCH]');
        $COURSE = $course;

        // Batch 3: FIRSTBATCH's code again (e.g. the same drop referenced twice on the page).
        // The idempotency guard skips re-querying its drop row, so this render must be served
        // entirely from cache — including its media. The single expected read is
        // text_filter::filter()'s own player-status lookup, which is not cached across separate
        // filter() calls by design (it re-checks gamification status on every call) and is
        // unrelated to the media-cache bug this test targets; a second read here would mean the
        // media fallback fired, i.e. self::$dropmediacache lost FIRSTBATCH's item media.
        $readsbefore = $DB->perf_get_reads();
        $filteredtext = $filter->filter('[PLAYERHUD_DROP code=FIRSTBATCH]');
        $readsafter = $DB->perf_get_reads();

        $this->assertStringContainsString('ph-action-collect', $filteredtext);
        $this->assertSame(
            1,
            $readsafter - $readsbefore,
            'Re-rendering an already-preloaded drop must not query the file storage again — ' .
            'its media should still be in self::$dropmediacache from batch 1.'
        );
    }

    /**
     * Test Security: Shortcode must disappear if gamification is paused.
     *
     * @covers \filter_playerhud\text_filter::filter
     */
    public function test_filter_visibility_paused(): void {
        global $DB;
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid, $user] = $this->setup_environment();

        $drop = new \stdClass();
        $drop->blockinstanceid = $instanceid;
        $drop->itemid = $itemid;
        $drop->name = 'Test Drop';
        $drop->maxusage = 1;
        $drop->respawntime = 0;
        $drop->code = 'HIDE123';
        $drop->timecreated = time();
        $drop->timemodified = time();
        $DB->insert_record('block_playerhud_drops', $drop);

        // Disable gamification for this specific user.
        $player = $DB->get_record('block_playerhud_user', ['blockinstanceid' => $instanceid, 'userid' => $user->id]);
        $player->enable_gamification = 0;
        $DB->update_record('block_playerhud_user', $player);

        $filter = new text_filter($context, []);
        $inputtext = 'Here is a drop: [PLAYERHUD_DROP code=HIDE123] hidden.';
        $filteredtext = $filter->filter($inputtext);

        // Because gamification is paused, it should strip the shortcode and NOT render the button.
        $this->assertStringNotContainsString('[PLAYERHUD_DROP code=HIDE123]', $filteredtext);
        $this->assertStringNotContainsString('ph-action-collect', $filteredtext);
        $this->assertEquals('Here is a drop:  hidden.', $filteredtext);
    }

    /**
     * Helper to insert a drop row for a given item.
     *
     * @param int $instanceid The block instance ID.
     * @param int $itemid The item ID the drop yields.
     * @param string $code The drop collection code.
     * @param int $maxusage Maximum number of collections (0 = unlimited).
     * @param int $respawntime Cooldown in seconds before recollection (0 = none).
     * @param int $value Quantity granted per collection.
     * @return int The new drop ID.
     */
    protected function create_drop(
        int $instanceid,
        int $itemid,
        string $code,
        int $maxusage = 1,
        int $respawntime = 0,
        int $value = 1
    ): int {
        global $DB;

        $drop = new \stdClass();
        $drop->blockinstanceid = $instanceid;
        $drop->itemid = $itemid;
        $drop->name = 'Drop ' . $code;
        $drop->maxusage = $maxusage;
        $drop->value = $value;
        $drop->respawntime = $respawntime;
        $drop->code = $code;
        $drop->timecreated = time();
        $drop->timemodified = time();

        return $DB->insert_record('block_playerhud_drops', $drop);
    }

    /**
     * The null provider must return the documented reason string.
     *
     * @covers \filter_playerhud\privacy\provider::get_reason
     */
    public function test_provider_get_reason(): void {
        $this->assertEquals('privacy:metadata', provider::get_reason());
    }

    /**
     * Guests (and the site front page) must never see PlayerHUD shortcodes.
     *
     * @covers \filter_playerhud\text_filter::filter
     */
    public function test_filter_strips_for_guest(): void {
        $this->resetAfterTest(true);
        $this->setGuestUser();

        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $inputtext = 'A [PLAYERHUD_WIDGET] and a [PLAYERHUD_DROP code=ABC123] and a '
            . '[PLAYERHUD_TRADE code=XYZ789] here.';
        $filteredtext = $filter->filter($inputtext);

        $this->assertStringNotContainsString('[PLAYERHUD_WIDGET]', $filteredtext);
        $this->assertStringNotContainsString('[PLAYERHUD_DROP', $filteredtext);
        $this->assertStringNotContainsString('[PLAYERHUD_TRADE', $filteredtext);
        $this->assertStringNotContainsString('ph-action-collect', $filteredtext);
    }

    /**
     * In a course without a PlayerHUD block instance, shortcodes must be stripped.
     *
     * @covers \filter_playerhud\text_filter::filter
     */
    public function test_filter_strips_when_no_block(): void {
        global $COURSE;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $COURSE = $course;
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        $context = \context_course::instance($course->id);
        $filter = new text_filter($context, []);
        $inputtext = 'Drop here: [PLAYERHUD_DROP code=NOBLOCK] and widget [PLAYERHUD_WIDGET].';
        $filteredtext = $filter->filter($inputtext);

        $this->assertStringNotContainsString('[PLAYERHUD_DROP', $filteredtext);
        $this->assertStringNotContainsString('[PLAYERHUD_WIDGET]', $filteredtext);
        $this->assertStringNotContainsString('ph-action-collect', $filteredtext);
    }

    /**
     * The modals fragment must render some HTML for the page assets.
     *
     * @covers \filter_playerhud\output\assets::get_modals_html
     */
    public function test_assets_modals_html(): void {
        $this->resetAfterTest(true);

        $html = (new assets())->get_modals_html();

        $this->assertNotEmpty($html);
        $this->assertStringContainsString('modal', $html);
    }

    /**
     * A secret drop that was never collected must be rendered as a mystery item.
     *
     * @covers \filter_playerhud\output\render::render_drop
     */
    public function test_render_drop_secret_item(): void {
        global $DB;
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid] = $this->setup_environment();

        $secretitem = new \stdClass();
        $secretitem->blockinstanceid = $instanceid;
        $secretitem->name = 'Real Name';
        $secretitem->xp = 50;
        $secretitem->image = '';
        $secretitem->description = '';
        $secretitem->enabled = 1;
        $secretitem->secret = 1;
        $secretitem->timecreated = time();
        $secretitem->timemodified = time();
        $secretitemid = $DB->insert_record('block_playerhud_items', $secretitem);

        $this->create_drop($instanceid, $secretitemid, 'SECRET1');

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Hidden: [PLAYERHUD_DROP code=SECRET1]');

        // The real item name must be hidden; the mystery placeholders must show instead.
        $this->assertStringNotContainsString('Real Name', $filteredtext);
        $this->assertStringContainsString(get_string('mysteryitem', 'filter_playerhud'), $filteredtext);
        $this->assertStringContainsString('data-xp="???"', $filteredtext);
    }

    /**
     * An item whose description column is null (the field is nullable in install.xml, even
     * though every current writer coerces it to a string) must still render the collect
     * card instead of crashing base64_encode() with a TypeError.
     *
     * @covers \filter_playerhud\output\render::render_drop
     */
    public function test_render_drop_null_description_does_not_crash(): void {
        global $DB;
        $this->resetAfterTest(true);
        [$context, $instanceid] = $this->setup_environment();

        $item = new \stdClass();
        $item->blockinstanceid = $instanceid;
        $item->name = 'No Description Item';
        $item->xp = 10;
        $item->image = '';
        $item->description = null;
        $item->enabled = 1;
        $item->secret = 0;
        $item->timecreated = time();
        $item->timemodified = time();
        $itemid = $DB->insert_record('block_playerhud_items', $item);

        $this->create_drop($instanceid, $itemid, 'NULLDESC1');

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Take: [PLAYERHUD_DROP code=NULLDESC1]');

        $this->assertStringContainsString('ph-action-collect', $filteredtext);
        $this->assertStringContainsString('data-desc-b64=""', $filteredtext);
    }

    /**
     * A drop whose usage limit is reached must render disabled, with no collect action.
     *
     * @covers \filter_playerhud\output\render::render_drop
     */
    public function test_render_drop_limit_reached(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid] = $this->setup_environment();

        $dropid = $this->create_drop($instanceid, $itemid, 'LIMIT1', 1, 0);

        // Record one collection so count (1) reaches maxusage (1).
        $inv = new \stdClass();
        $inv->userid = $USER->id;
        $inv->itemid = $itemid;
        $inv->dropid = $dropid;
        $inv->source = 'map';
        $inv->timecreated = time();
        $DB->insert_record('block_playerhud_inventory', $inv);

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Done: [PLAYERHUD_DROP code=LIMIT1]');

        $this->assertStringContainsString('ph-owned', $filteredtext);
        $this->assertStringContainsString('aria-disabled="true"', $filteredtext);
        $this->assertStringNotContainsString('ph-action-collect', $filteredtext);
    }

    /**
     * A drop collected only through block_playerhud's new-engine stacking storage
     * (block_playerhud_stack_log) must still be recognised as limit-reached, not just a drop
     * collected via the legacy per-unit inventory table. Regression test for a real gap found
     * live: render_drop() only ever summed block_playerhud_inventory, so a drop collected via
     * the item-quantity engine appeared collectable again on every page reload, and the server
     * correctly rejecting the resulting duplicate collection surfaced as a raw error to the
     * student instead of the drop simply showing as already collected.
     *
     * @covers \filter_playerhud\output\render::render_drop
     */
    public function test_render_drop_limit_reached_via_new_engine_stack_log(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);

        if (!$DB->get_manager()->table_exists('block_playerhud_stack_log')) {
            $this->markTestSkipped('block_playerhud_stack_log does not exist in this environment.');
        }

        [$context, $instanceid, $itemid] = $this->setup_environment();

        $dropid = $this->create_drop($instanceid, $itemid, 'NEWLIM1', 1, 0);

        $DB->insert_record('block_playerhud_stack_log', (object) [
            'userid' => $USER->id,
            'itemid' => $itemid,
            'dropid' => $dropid,
            'delta' => 1,
            'source' => 'map',
            'xpawarded' => 100,
            'timecreated' => time(),
        ]);

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Done: [PLAYERHUD_DROP code=NEWLIM1]');

        $this->assertStringContainsString('ph-owned', $filteredtext);
        $this->assertStringContainsString('aria-disabled="true"', $filteredtext);
        $this->assertStringNotContainsString('ph-action-collect', $filteredtext);
    }

    /**
     * A drop's maxusage limits collection EVENTS, not units. A single collection worth more
     * than 1 unit (value > 1) must not be mistaken for having reached a higher maxusage — the
     * student is still entitled to further collections. Regression test for a real bug: the
     * card previously reached "collected" state after fewer real clicks than the teacher
     * configured whenever a drop's value-per-collection was greater than 1, because the
     * limit-reached check compared the unit total against maxusage instead of the event count.
     *
     * @covers \filter_playerhud\output\render::render_drop
     */
    public function test_render_drop_not_limit_reached_when_one_event_grants_multiple_units(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);

        if (!$DB->get_manager()->table_exists('block_playerhud_stack_log')) {
            $this->markTestSkipped('block_playerhud_stack_log does not exist in this environment.');
        }

        [$context, $instanceid, $itemid] = $this->setup_environment();

        // Maxusage=2 (two collection events allowed), value=2 (units per collection), but only
        // one event has happened so far. One event out of two allowed must still be collectable.
        $dropid = $this->create_drop($instanceid, $itemid, 'NEWLIM2', 2, 0, 2);

        $DB->insert_record('block_playerhud_stack_log', (object) [
            'userid' => $USER->id,
            'itemid' => $itemid,
            'dropid' => $dropid,
            'delta' => 2,
            'source' => 'map',
            'xpawarded' => 200,
            'timecreated' => time(),
        ]);

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Done: [PLAYERHUD_DROP code=NEWLIM2]');

        $this->assertStringContainsString('ph-action-collect', $filteredtext);
        $this->assertStringNotContainsString('aria-disabled="true"', $filteredtext);
        // Progress data-attribute reports the event count against maxusage — never a running
        // unit total.
        $this->assertStringContainsString(
            \block_playerhud\utils::format_drop_progress_count(1, 2),
            $filteredtext
        );
        // The card badge shows the drop's static per-collection value.
        $this->assertStringContainsString('x2', $filteredtext);
    }

    /**
     * In card mode, an unlimited drop that also grants more than one unit per collection must
     * show the explicit "1 of ∞" progress badge, in normal document flow above the icon, AND
     * fold the quantity into the button text — never a second badge overlaid on the artwork.
     * Regression test for a real UX bug: corner badges absolutely positioned over the icon area
     * covered part of the artwork whenever the drop used a real image instead of a small emoji.
     *
     * @covers \filter_playerhud\output\render::render_drop
     */
    public function test_render_drop_card_mode_combines_progress_badge_and_button_quantity(): void {
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid] = $this->setup_environment();

        $this->create_drop($instanceid, $itemid, 'BADGEBOTH1', 0, 0, 2);

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Take: [PLAYERHUD_DROP code=BADGEBOTH1]');

        $this->assertMatchesRegularExpression(
            '/ph-drop-badges-row[^>]*>\s*<span[^>]*>\s*'
                . preg_quote(\block_playerhud\utils::format_drop_progress_badge(0, 0), '/') . '/',
            $filteredtext
        );
        // The row must come before the icon in the markup, so it renders above it, not over it.
        $this->assertLessThan(
            strpos($filteredtext, 'ph-drop-icon-wrapper'),
            strpos($filteredtext, 'ph-drop-badges-row')
        );
        // The quantity is folded into the collect button's own label, not a second badge.
        $this->assertStringContainsString('(x2)', $filteredtext);
        $this->assertStringNotContainsString('ph-badge-value', $filteredtext);
    }

    /**
     * A finite drop collectible more than once (maxusage > 1) must show the explicit collection
     * progress badge, not a bare number. Regression test: before this fix, a card never surfaced
     * its collection limit at all unless the drop was unlimited, and once it did, it showed a
     * bare number with no wording explaining what it counted.
     *
     * @covers \filter_playerhud\output\render::render_drop
     */
    public function test_render_drop_shows_progress_badge_for_finite_multi_use(): void {
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid] = $this->setup_environment();

        $this->create_drop($instanceid, $itemid, 'BADGELIMIT1', 3, 0, 1);

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Take: [PLAYERHUD_DROP code=BADGELIMIT1]');

        $this->assertMatchesRegularExpression(
            '/ph-drop-badges-row[^>]*>\s*<span[^>]*>\s*'
                . preg_quote(\block_playerhud\utils::format_drop_progress_badge(0, 3), '/') . '/',
            $filteredtext
        );
        // Value is 1 (trivial), so the button must carry no quantity suffix.
        $this->assertStringNotContainsString('(x', $filteredtext);
    }

    /**
     * A single-use, single-value drop is the trivial case: the progress badge carries no useful
     * information (its only two states, available/collected, are already conveyed by the button
     * itself), so it must not render.
     *
     * @covers \filter_playerhud\output\render::render_drop
     */
    public function test_render_drop_hides_progress_badge_for_trivial_drop(): void {
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid] = $this->setup_environment();

        $this->create_drop($instanceid, $itemid, 'BADGENONE1', 1, 0, 1);

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Take: [PLAYERHUD_DROP code=BADGENONE1]');

        $this->assertStringNotContainsString('ph-drop-badges-row', $filteredtext);
    }

    /**
     * Image mode has no button text to fold the quantity into (its collect control is a bare
     * icon overlay), so it must keep a visible quantity badge of its own alongside the progress
     * badge, both sharing the same normal-flow row above the artwork.
     *
     * @covers \filter_playerhud\output\render::render_drop
     */
    public function test_render_drop_image_mode_keeps_value_badge(): void {
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid] = $this->setup_environment();

        $this->create_drop($instanceid, $itemid, 'BADGEIMG1', 0, 0, 2);

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Take: [PLAYERHUD_DROP mode=image code=BADGEIMG1]');

        $this->assertMatchesRegularExpression('/ph-badge-value[^>]*>\s*x2/', $filteredtext);
        $this->assertMatchesRegularExpression(
            '/ph-drop-badges-row.*'
                . preg_quote(\block_playerhud\utils::format_drop_progress_badge(0, 0), '/') . '/s',
            $filteredtext
        );
        // The row must come before the artwork in the markup, so it renders above it, not over it.
        // (The test drop has no image configured, so the artwork is the emoji fallback span.)
        $this->assertLessThan(
            strpos($filteredtext, 'ph-drop-emoji-lg'),
            strpos($filteredtext, 'ph-drop-badges-row')
        );
    }

    /**
     * Two collection events reach a maxusage of 2 regardless of how many units each one
     * granted — the event count, not the unit total, is what a drop's maxusage limits.
     *
     * @covers \filter_playerhud\output\render::render_drop
     */
    public function test_render_drop_limit_reached_counts_events_not_units(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);

        if (!$DB->get_manager()->table_exists('block_playerhud_stack_log')) {
            $this->markTestSkipped('block_playerhud_stack_log does not exist in this environment.');
        }

        [$context, $instanceid, $itemid] = $this->setup_environment();

        $dropid = $this->create_drop($instanceid, $itemid, 'NEWLIM2B', 2, 0);

        for ($i = 0; $i < 2; $i++) {
            $DB->insert_record('block_playerhud_stack_log', (object) [
                'userid' => $USER->id,
                'itemid' => $itemid,
                'dropid' => $dropid,
                'delta' => 2,
                'source' => 'map',
                'xpawarded' => 200,
                'timecreated' => time(),
            ]);
        }

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Done: [PLAYERHUD_DROP code=NEWLIM2B]');

        $this->assertStringContainsString('aria-disabled="true"', $filteredtext);
        $this->assertStringNotContainsString('ph-action-collect', $filteredtext);
    }

    /**
     * preload_data() is idempotent across two filter() calls carrying the same drop code in
     * the same request (e.g. an activity's intro and content each filtered separately, both
     * containing the same shortcode) — the second call must not double-count the one real
     * collection event, which would otherwise report progress twice the actual value and could
     * make limit_reached trip before the drop's real maxusage is reached.
     *
     * @covers \filter_playerhud\output\render::preload_data
     */
    public function test_render_drop_progress_is_not_doubled_by_a_repeated_filter_call(): void {
        global $DB, $USER, $COURSE, $PAGE;
        $this->resetAfterTest(true);

        [$context, $instanceid, $itemid] = $this->setup_environment();

        // Locks $PAGE's course before the first filter() call triggers the lazy $OUTPUT/theme
        // bootstrap — without this, that bootstrap defaults $PAGE to the site course and resets
        // the global $COURSE back to it as a side effect, which only breaks a test (like this
        // one) calling filter() more than once in the same method.
        $PAGE->set_course($COURSE);

        $dropid = $this->create_drop($instanceid, $itemid, 'DUPCALL', 3);

        $DB->insert_record('block_playerhud_inventory', (object) [
            'userid' => $USER->id,
            'itemid' => $itemid,
            'dropid' => $dropid,
            'source' => 'map',
            'timecreated' => time(),
        ]);

        $filter = new text_filter($context, []);
        // Simulates the same shortcode being filtered twice in one request (e.g. an activity's
        // intro filtered separately from its content) — preload_data() runs once per call.
        $filter->filter('Intro: [PLAYERHUD_DROP code=DUPCALL]');
        $filteredtext = $filter->filter('Content: [PLAYERHUD_DROP code=DUPCALL]');

        $this->assertStringContainsString(
            \block_playerhud\utils::format_drop_progress_count(1, 3),
            $filteredtext
        );
        $this->assertStringNotContainsString(
            \block_playerhud\utils::format_drop_progress_count(2, 3),
            $filteredtext
        );
    }

    /**
     * A recently collected drop still within its respawn window must render in cooldown.
     *
     * @covers \filter_playerhud\output\render::render_drop
     */
    public function test_render_drop_cooldown(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid] = $this->setup_environment();

        // Unlimited usage with an hour-long respawn, collected just now.
        $dropid = $this->create_drop($instanceid, $itemid, 'COOL1', 0, HOURSECS);

        $inv = new \stdClass();
        $inv->userid = $USER->id;
        $inv->itemid = $itemid;
        $inv->dropid = $dropid;
        $inv->source = 'map';
        $inv->timecreated = time();
        $DB->insert_record('block_playerhud_inventory', $inv);

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Wait: [PLAYERHUD_DROP code=COOL1]');

        $this->assertStringContainsString('ph-timer', $filteredtext);
        $this->assertStringNotContainsString('ph-action-collect', $filteredtext);
    }

    /**
     * A valid trade code must resolve and render the trade card with its requirements.
     *
     * @covers \filter_playerhud\output\render::render_trade_by_code
     * @covers \filter_playerhud\output\render::render_trade
     */
    public function test_render_trade_by_code_valid(): void {
        global $DB, $PAGE, $COURSE;
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid] = $this->setup_environment();
        $PAGE->set_url('/course/view.php', ['id' => $COURSE->id]);

        $trade = new \stdClass();
        $trade->blockinstanceid = $instanceid;
        $trade->name = 'Magic Trade';
        $trade->groupid = 0;
        $trade->centralized = 1;
        $trade->onetime = 0;
        $trade->timecreated = time();
        $tradeid = $DB->insert_record('block_playerhud_trades', $trade);

        $req = new \stdClass();
        $req->tradeid = $tradeid;
        $req->itemid = $itemid;
        $req->qty = 2;
        $DB->insert_record('block_playerhud_trade_reqs', $req);

        $reward = new \stdClass();
        $reward->tradeid = $tradeid;
        $reward->itemid = $itemid;
        $reward->qty = 1;
        $DB->insert_record('block_playerhud_trade_rewards', $reward);

        // The public code is the first 6 hex chars of md5("{id}_{timecreated}").
        $code = strtoupper(substr(md5($tradeid . '_' . $trade->timecreated), 0, 6));

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter("Offer: [PLAYERHUD_TRADE code={$code}]");

        $this->assertStringNotContainsString('[PLAYERHUD_TRADE', $filteredtext);
        $this->assertStringContainsString('ph-trade-widget-card', $filteredtext);
        $this->assertStringContainsString('Magic Trade', $filteredtext);
    }

    /**
     * A student whose balance for the required item lives only in block_playerhud's new-engine
     * stacking storage (block_playerhud_stack) must still be able to afford the trade — the
     * requirement check must not only sum the legacy per-unit inventory table. Regression test
     * for a real gap found live alongside the collection-limit one above.
     *
     * @covers \filter_playerhud\output\render::render_trade
     */
    public function test_render_trade_affordable_via_new_engine_stack_balance(): void {
        global $DB, $PAGE, $COURSE, $USER;
        $this->resetAfterTest(true);

        if (!$DB->get_manager()->table_exists('block_playerhud_stack')) {
            $this->markTestSkipped('block_playerhud_stack does not exist in this environment.');
        }

        [$context, $instanceid, $itemid] = $this->setup_environment();
        $PAGE->set_url('/course/view.php', ['id' => $COURSE->id]);

        $trade = new \stdClass();
        $trade->blockinstanceid = $instanceid;
        $trade->name = 'New Engine Trade';
        $trade->groupid = 0;
        $trade->centralized = 1;
        $trade->onetime = 0;
        $trade->timecreated = time();
        $tradeid = $DB->insert_record('block_playerhud_trades', $trade);

        $req = new \stdClass();
        $req->tradeid = $tradeid;
        $req->itemid = $itemid;
        $req->qty = 2;
        $DB->insert_record('block_playerhud_trade_reqs', $req);

        $reward = new \stdClass();
        $reward->tradeid = $tradeid;
        $reward->itemid = $itemid;
        $reward->qty = 1;
        $DB->insert_record('block_playerhud_trade_rewards', $reward);

        // Balance lives only in the new-engine table — nothing in block_playerhud_inventory.
        $DB->insert_record('block_playerhud_stack', (object) [
            'userid' => $USER->id,
            'itemid' => $itemid,
            'qty' => 2,
            'timemodified' => time(),
        ]);

        $code = strtoupper(substr(md5($tradeid . '_' . $trade->timecreated), 0, 6));

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter("Offer: [PLAYERHUD_TRADE code={$code}]");

        $this->assertStringContainsString('btn-success', $filteredtext);
        $this->assertStringNotContainsString('btn-outline-secondary', $filteredtext);
    }

    /**
     * An unknown trade code must render nothing (guards against ID enumeration).
     *
     * @covers \filter_playerhud\output\render::render_trade_by_code
     */
    public function test_render_trade_by_code_invalid(): void {
        $this->resetAfterTest(true);
        [$context, $instanceid] = $this->setup_environment();

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Offer: [PLAYERHUD_TRADE code=ZZZ999] here.');

        $this->assertStringNotContainsString('[PLAYERHUD_TRADE', $filteredtext);
        $this->assertStringNotContainsString('ph-trade-widget-card', $filteredtext);
        $this->assertEquals('Offer:  here.', $filteredtext);
    }

    /**
     * When gamification is paused, the widget renders an opt-in button, not the HUD.
     *
     * @covers \filter_playerhud\output\widget::export_for_template
     * @covers \filter_playerhud\output\widget::render
     */
    public function test_widget_optin_when_paused(): void {
        global $DB, $PAGE, $COURSE, $USER;
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid, $user] = $this->setup_environment();
        $PAGE->set_url('/course/view.php', ['id' => $COURSE->id]);

        $player = $DB->get_record('block_playerhud_user', [
            'blockinstanceid' => $instanceid,
            'userid'          => $USER->id,
        ]);
        $player->enable_gamification = 0;
        $DB->update_record('block_playerhud_user', $player);

        $instance = $DB->get_record('block_instances', ['id' => $instanceid]);
        $widget = new widget($instance, $COURSE->id);
        $data = $widget->export_for_template($PAGE->get_renderer('core'));

        $this->assertFalse($data['is_active']);
        $this->assertArrayHasKey('optin_url', $data);

        $html = $widget->render();
        $this->assertStringContainsString('btn-primary', $html);
        $this->assertStringContainsString(get_string('click_to_enable', 'filter_playerhud'), $html);
        $this->assertStringNotContainsString('ph-char-modal', $html);
    }

    /**
     * An active player renders the full HUD widget with their profile data.
     *
     * @covers \filter_playerhud\output\widget::export_for_template
     * @covers \filter_playerhud\output\widget::render
     */
    public function test_widget_renders_active(): void {
        global $DB, $PAGE, $COURSE;
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid, $user] = $this->setup_environment();
        $PAGE->set_url('/course/view.php', ['id' => $COURSE->id]);

        $instance = $DB->get_record('block_instances', ['id' => $instanceid]);
        $widget = new widget($instance, $COURSE->id);
        $data = $widget->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue($data['is_active']);
        $this->assertFalse($data['is_app']);
        $this->assertEquals(fullname($user), $data['fullname']);
        $this->assertArrayHasKey('level_display', $data);

        $html = $widget->render();
        $this->assertStringContainsString(fullname($user), $html);
    }

    /**
     * Inside the Moodle app, the widget returns a redirect to the backpack view.
     *
     * @covers \filter_playerhud\output\widget::export_for_template
     */
    public function test_widget_app_redirect(): void {
        global $DB, $PAGE, $COURSE;
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid] = $this->setup_environment();
        $PAGE->set_url('/course/view.php', ['id' => $COURSE->id]);

        // Force Moodle app detection through the user agent.
        core_useragent::instance(true, 'Mozilla/5.0 (Linux; Android) MoodleMobile');
        $this->assertTrue(core_useragent::is_moodle_app());

        $instance = $DB->get_record('block_instances', ['id' => $instanceid]);
        $widget = new widget($instance, $COURSE->id);
        $data = $widget->export_for_template($PAGE->get_renderer('core'));

        core_useragent::instance(true, '');

        $this->assertTrue($data['is_app']);
        $this->assertTrue($data['is_active']);
        $this->assertStringContainsString('/blocks/playerhud/view.php', $data['url_redirect']);
    }

    /**
     * An item description must be cleaned with format_text() before being base64-encoded
     * for the drop's data-desc-b64 attribute, since the block's JS injects that value as
     * live HTML on the client — a raw description here would let an event-handler
     * attribute execute in every student's session.
     */
    public function test_render_drop_description_is_sanitised(): void {
        global $DB;
        $this->resetAfterTest(true);
        [$context, $instanceid] = $this->setup_environment();

        $item = new \stdClass();
        $item->blockinstanceid = $instanceid;
        $item->name = 'XSS Item';
        $item->xp = 10;
        $item->image = '';
        $item->description = '<img src=x onerror="x">Texto seguro';
        $item->enabled = 1;
        $item->secret = 0;
        $item->timecreated = time();
        $item->timemodified = time();
        $itemid = $DB->insert_record('block_playerhud_items', $item);

        $this->create_drop($instanceid, $itemid, 'XSS1');

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Take: [PLAYERHUD_DROP code=XSS1]');

        $this->assertMatchesRegularExpression('/data-desc-b64="([^"]*)"/', $filteredtext);
        preg_match('/data-desc-b64="([^"]*)"/', $filteredtext, $matches);
        $decoded = base64_decode($matches[1]);

        $this->assertStringNotContainsString('onerror', $decoded);
        $this->assertStringNotContainsString('<script', $decoded);
        $this->assertStringContainsString('Texto seguro', $decoded);
    }

    /**
     * A configdata value containing a serialized object of an arbitrary class must never
     * be instantiated as that class. unserialize_object() restricts allowed classes to
     * stdClass, so the probe's __wakeup() must never fire — a bare unserialize() would
     * let it fire.
     */
    public function test_widget_configdata_rejects_arbitrary_class(): void {
        global $DB, $PAGE, $COURSE;
        $this->resetAfterTest(true);
        [$context, $instanceid] = $this->setup_environment();
        $PAGE->set_url('/course/view.php', ['id' => $COURSE->id]);

        filter_playerhud_wakeup_probe::$wakeups = 0;
        $untrusted = base64_encode(serialize(new filter_playerhud_wakeup_probe()));
        $DB->set_field('block_instances', 'configdata', $untrusted, ['id' => $instanceid]);

        $instance = $DB->get_record('block_instances', ['id' => $instanceid]);
        $widget = new widget($instance, $COURSE->id);
        $data = $widget->export_for_template($PAGE->get_renderer('core'));

        $this->assertSame(0, filter_playerhud_wakeup_probe::$wakeups);
        $this->assertTrue($data['is_active']);
    }

    /**
     * The drop card's non-image icon/emoji must be HTML-escaped (double-mustache), not
     * injected raw. Renders the template directly against the core Mustache engine, bypassing
     * PHP entirely, so this fails if the template regresses to triple-mustache regardless of
     * what the caller passes in.
     */
    public function test_drop_template_escapes_media_content(): void {
        global $OUTPUT;
        $this->resetAfterTest(true);

        $payload = '<script>x</script>';
        $html = $OUTPUT->render_from_template('filter_playerhud/drop', [
            'is_card' => true,
            'is_image_media' => false,
            'media_content' => $payload,
            'safe_name' => 'Test',
            'display_name' => 'Test',
            'collect_url' => '#',
            'btn_text' => 'Take',
            'emoji_html' => '🖐',
            'data_attributes' => '',
        ]);

        $this->assertStringNotContainsString($payload, $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * The trade widget's non-image requirement/reward icon must be HTML-escaped, matching
     * the same fix applied to the drop template.
     */
    public function test_trade_template_escapes_content(): void {
        global $OUTPUT;
        $this->resetAfterTest(true);

        $payload = '<script>x</script>';
        $html = $OUTPUT->render_from_template('filter_playerhud/trade', [
            'trade_name' => 'Test Trade',
            'reqs' => [
                ['qty' => 1, 'name' => 'Evil', 'is_image' => false, 'content' => $payload],
            ],
            'rewards' => [],
            'trade_url' => '#',
            'str_trade_btn' => 'Trade',
            'str_you_pay' => 'You pay',
            'str_you_receive' => 'You receive',
        ]);

        $this->assertStringNotContainsString($payload, $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * A user whose block/playerhud:view capability is explicitly prohibited must not see
     * the HUD, drops or trades through the filter, even though the deterministic checks
     * (login, guest, gamification) all pass. view.php, collect.php and process_trade.php
     * already enforced this capability; the filter itself never did.
     */
    public function test_filter_strips_when_view_capability_prohibited(): void {
        global $DB;
        $this->resetAfterTest(true);
        [$context, $instanceid, $itemid] = $this->setup_environment();

        $this->create_drop($instanceid, $itemid, 'NOVIEW1');

        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $blockcontext = \context_block::instance($instanceid);
        assign_capability('block/playerhud:view', CAP_PROHIBIT, $studentrole->id, $blockcontext->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $filter = new text_filter($context, []);
        // Drop only: the guard is a single early-return check shared by widget/drop/trade,
        // so proving it here proves all three without pulling in the widget renderer's own
        // $PAGE/$OUTPUT setup requirements, which are unrelated to this fix.
        $filteredtext = $filter->filter('Take: [PLAYERHUD_DROP code=NOVIEW1].');

        $this->assertStringNotContainsString('[PLAYERHUD_DROP', $filteredtext);
        $this->assertStringNotContainsString('ph-action-collect', $filteredtext);
    }

    /**
     * An item description containing the drop's own shortcode must not be re-expanded.
     * render_drop() passes 'filter' => false to format_text() specifically so a
     * self-referential (or mutually referential) description cannot recurse back into
     * text_filter::filter() from inside itself — core's filter_manager::apply_filter_chain()
     * has no reentrancy guard of its own (verified against filter/classes/filter_manager.php),
     * so an unguarded recursion would exhaust memory and fatal every request that renders it.
     * Deliberately does not revert the fix to prove this one the way the other regression
     * tests do: doing so would trigger the actual unbounded recursion in a live process.
     */
    public function test_render_drop_description_shortcode_is_not_reexpanded(): void {
        global $DB;
        $this->resetAfterTest(true);
        [$context, $instanceid] = $this->setup_environment();

        $item = new \stdClass();
        $item->blockinstanceid = $instanceid;
        $item->name = 'Recursive Item';
        $item->xp = 10;
        $item->image = '';
        $item->description = 'See also: [PLAYERHUD_DROP code=SELF1]';
        $item->enabled = 1;
        $item->secret = 0;
        $item->timecreated = time();
        $item->timemodified = time();
        $itemid = $DB->insert_record('block_playerhud_items', $item);

        $this->create_drop($instanceid, $itemid, 'SELF1');

        $filter = new text_filter($context, []);
        $filteredtext = $filter->filter('Take: [PLAYERHUD_DROP code=SELF1]');

        $this->assertMatchesRegularExpression('/data-desc-b64="([^"]*)"/', $filteredtext);
        preg_match('/data-desc-b64="([^"]*)"/', $filteredtext, $matches);
        $decoded = base64_decode($matches[1]);

        // The shortcode text must survive untouched inside the description: if it had
        // been re-expanded, this would instead contain a second, nested drop card.
        $this->assertStringContainsString('[PLAYERHUD_DROP code=SELF1]', $decoded);
        $this->assertStringNotContainsString('ph-action-collect', $decoded);
    }
}
