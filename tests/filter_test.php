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
use filter_playerhud\text_filter;

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

        // 1. WARM UP (Aquecimento do Moodle)
        // Força o núcleo do Moodle a ir ao banco buscar e guardar em memória
        // todas as strings de tradução, templates Mustache e contextos.
        $filter->filter('Warmup [PLAYERHUD_DROP code=CODE1]');

        // 2. Limpa APENAS o cache estático do nosso plugin para simular o
        // início de um novo carregamento de página (mantendo o Moodle aquecido).
        \filter_playerhud\text_filter::reset_caches();
        \filter_playerhud\output\render::reset_caches();

        // 3. Inicia o cronômetro do Banco de Dados
        $readsbefore = $DB->perf_get_reads();

        // 4. Roda o filtro para 5 drops simultâneos!
        $filter->filter($inputtext);

        // 5. Calcula o total de leituras
        $readsafter = $DB->perf_get_reads();
        $totalreads = $readsafter - $readsbefore;

        // 6. A Asserção Arquitetural:
        // Uma arquitetura O(1) perfeita faz exatamente 5 consultas base para a página toda:
        // (1 Bloco + 1 Usuário Filtro + 1 Usuário Render + 1 Lote Drops + 1 Lote Inventário)
        // Se fossem consultas separadas (N+1), dariam mais de 25 leituras.
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
}
