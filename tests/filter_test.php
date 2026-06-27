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
use filter_playerhud\output\assets;
use filter_playerhud\privacy\provider;
use filter_playerhud\text_filter;

/**
 * Tests for the PlayerHUD text filter.
 *
 * @package    filter_playerhud
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
     * @return int The new drop ID.
     */
    protected function create_drop(
        int $instanceid,
        int $itemid,
        string $code,
        int $maxusage = 1,
        int $respawntime = 0
    ): int {
        global $DB;

        $drop = new \stdClass();
        $drop->blockinstanceid = $instanceid;
        $drop->itemid = $itemid;
        $drop->name = 'Drop ' . $code;
        $drop->maxusage = $maxusage;
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
}
