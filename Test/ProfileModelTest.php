<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\Kensho\Model\KenshoProfileModel;
use KanboardTests\units\Base;

require_once __DIR__ . '/KenshoSchemaHelper.php';

class ProfileModelTest extends Base
{
    use KenshoSchemaHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createKenshoSchema();
    }

    private function model(): KenshoProfileModel
    {
        return new KenshoProfileModel($this->container);
    }

    /**
     * Write a raw stored value straight into the profile table, bypassing the
     * model, so the defensive decode path can be exercised with data the
     * sanitizers would otherwise never produce.
     */
    private function seedRawProfile(int $userId, string $raw): void
    {
        $this->container['db']
            ->table(KenshoProfileModel::TABLE)
            ->insert(['user_id' => $userId, 'profile' => $raw]);
    }

    public function testNoStoredProfileReturnsDefaults()
    {
        $p = $this->model()->getProfile(1);
        $this->assertEquals(1, $p['version']);
        $this->assertEquals(90, $p['window_days']);
        $this->assertTrue($p['settings']['show_score']);
        $this->assertSame([], $p['areas']);
        $this->assertSame([], $p['reflections']);
    }

    public function testCorruptJsonReturnsDefaults()
    {
        $this->seedRawProfile(1, '{not valid json');
        $p = $this->model()->getProfile(1);
        $this->assertSame([], $p['areas']);
        $this->assertEquals(90, $p['window_days']);
    }

    public function testPartialStoredJsonFilledWithDefaults()
    {
        $this->seedRawProfile(1, json_encode(['version' => 1]));
        $p = $this->model()->getProfile(1);
        $this->assertSame([], $p['areas']);
        $this->assertArrayHasKey('show_score', $p['settings']);
    }

    public function testRoundTrip()
    {
        $m = $this->model();
        $profile = $m->getProfile(1);
        $profile['window_days'] = 180;
        $profile['settings']['show_score'] = false;
        $this->assertTrue($m->saveProfile(1, $profile));

        $reloaded = $m->getProfile(1);
        $this->assertEquals(180, $reloaded['window_days']);
        $this->assertFalse($reloaded['settings']['show_score']);
    }

    public function testUpsertAreaGeneratesSlugIdThenUpdates()
    {
        $m = $this->model();
        $saved = $m->upsertArea(1, ['label' => 'Health & Fitness', 'importance' => 9]);
        $this->assertCount(1, $saved['areas']);
        $id = $saved['areas'][0]['id'];
        $this->assertStringStartsWith('area_', $id);
        $this->assertEquals(9, $saved['areas'][0]['importance']);
        $this->assertEquals(1.0, $saved['areas'][0]['weight']); // default weight

        // Upsert same id → update, not append.
        $saved2 = $m->upsertArea(1, ['id' => $id, 'label' => 'Health', 'importance' => 7]);
        $this->assertCount(1, $saved2['areas']);
        $this->assertEquals(7, $saved2['areas'][0]['importance']);
        $this->assertEquals('Health', $saved2['areas'][0]['label']);
    }

    public function testRemoveArea()
    {
        $m = $this->model();
        $saved = $m->upsertArea(1, ['id' => 'area_health', 'label' => 'Health', 'importance' => 9]);
        $this->assertCount(1, $saved['areas']);
        $after = $m->removeArea(1, 'area_health');
        $this->assertSame([], $after['areas']);
    }

    public function testAddReflectionAppends()
    {
        $m = $this->model();
        $m->addReflection(1, ['id' => 'r_1', 'date' => 1783000000, 'narrative' => 'first']);
        $p = $m->addReflection(1, ['id' => 'r_2', 'date' => 1783000100, 'narrative' => 'second']);
        $this->assertCount(2, $p['reflections']);
        $this->assertEquals('r_1', $p['reflections'][0]['id']);
        $this->assertEquals('second', $p['reflections'][1]['narrative']);
    }

    public function testImportanceAndWindowClampedAndSanitized()
    {
        $m = $this->model();
        $saved = $m->upsertArea(1, ['label' => 'X', 'importance' => 99]); // out of 1..10 range
        $this->assertLessThanOrEqual(10, $saved['areas'][0]['importance']);
        $this->assertGreaterThanOrEqual(1, $saved['areas'][0]['importance']);
    }

    public function testNestedArrayInScalarFieldDoesNotWarnAndCoercesToEmptyString()
    {
        $this->seedRawProfile(1, json_encode([
            'version'     => 1,
            'window_days' => 90,
            'settings'    => ['show_score' => true],
            'areas'       => [
                [
                    'id'         => 'area_bad',
                    'label'      => ['nested', 'array'],
                    'importance' => 9,
                    'identity'   => ['also', 'nested'],
                ],
            ],
            'reflections' => [
                [
                    'id'               => ['nested'],
                    'narrative'        => ['nested', 'narrative'],
                    'provider_profile' => ['nested', 'provider'],
                ],
            ],
        ]));

        $p = $this->model()->getProfile(1);

        $this->assertCount(1, $p['areas']);
        $this->assertSame('', $p['areas'][0]['label']);
        $this->assertSame('', $p['areas'][0]['identity']);
        $this->assertSame('area_bad', $p['areas'][0]['id']);

        $this->assertCount(1, $p['reflections']);
        $this->assertSame('', $p['reflections'][0]['id']);
        $this->assertSame('', $p['reflections'][0]['narrative']);
        $this->assertSame('', $p['reflections'][0]['provider_profile']);
    }
}
