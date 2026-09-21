<?php

require_once 'tests/units/Base.php';

use Kanboard\Model\UserModel;
use Kanboard\Plugin\Kensho\Model\KenshoProfileModel;
use KanboardTests\units\Base;

/**
 * The upgrade path for installs that already hold profiles.
 *
 * version_1 creates kensho_profiles and COPIES existing `kensho_profile` rows
 * out of user_has_metadata. It copies rather than moves, so downgrading to
 * 1.0.1 still finds its data.
 *
 * This runs the real migration function — the same one Kanboard's SchemaHandler
 * calls when the plugin is enabled or updated.
 */
class SchemaMigrationTest extends Base
{
    private function migrate(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';
        \Kanboard\Plugin\Kensho\Schema\version_1($this->container['db']->getConnection());
    }

    /** A profile exactly as 1.0.1 wrote it into user_has_metadata. */
    private function seedLegacyProfile(int $userId, array $overrides = []): array
    {
        $profile = array_merge([
            'version'     => 1,
            'window_days' => 180,
            'settings'    => ['show_score' => false],
            'areas'       => [
                [
                    'id'         => 'area_health_fitness',
                    'label'      => 'Health & Fitness',
                    'importance' => 9,
                    'identity'   => 'Someone who trains consistently, not sporadically.',
                    'goals'      => ['Show up three times a week.'],
                    'weight'     => 1.0,
                    'sources'    => ['project_ids' => [4], 'category_ids' => [], 'tag_ids' => []],
                ],
                [
                    'id'         => 'area_deep_work',
                    'label'      => 'Deep Work',
                    'importance' => 8,
                    'identity'   => 'A person who ships hard things without thrashing.',
                    'goals'      => [],
                    'weight'     => 1.0,
                    'sources'    => ['project_ids' => [], 'category_ids' => [], 'tag_ids' => []],
                ],
            ],
            'reflections' => [
                [
                    'id'               => 'r_abc1234567',
                    'date'             => 1783000000,
                    'window_days'      => 180,
                    'score'            => 72,
                    'discrepancies'    => [],
                    'narrative'        => 'You say craft matters but the week went to meetings.',
                    'provider_profile' => 'openai:gpt-4o',
                ],
            ],
        ], $overrides);

        $this->container['userMetadataModel']->save($userId, [
            KenshoProfileModel::KEY => json_encode($profile, JSON_UNESCAPED_UNICODE),
        ]);

        return $profile;
    }

    private function createUser(string $username): int
    {
        return (new UserModel($this->container))->create(['username' => $username, 'password' => 'p4ssw0rd']);
    }

    public function testMigrationCopiesLegacyProfilesIntoTheNewTable(): void
    {
        $this->seedLegacyProfile(1);

        $this->migrate();

        $profile = (new KenshoProfileModel($this->container))->getProfile(1);

        $this->assertCount(2, $profile['areas'], 'both focus areas migrated');
        $this->assertSame('Health & Fitness', $profile['areas'][0]['label']);
        $this->assertSame(
            'Someone who trains consistently, not sporadically.',
            $profile['areas'][0]['identity'],
            'the identity note survives whole — this is the data the old column truncated'
        );
        $this->assertSame([4], $profile['areas'][0]['sources']['project_ids'], 'nested source bindings survive');
        $this->assertSame(180, $profile['window_days'], 'settings survive');
        $this->assertFalse($profile['settings']['show_score']);
        $this->assertCount(1, $profile['reflections'], 'reflection history survives');
        $this->assertSame(72, $profile['reflections'][0]['score']);
    }

    public function testMigrationCopiesEveryUsersProfile(): void
    {
        $second = $this->createUser('dana');
        $this->seedLegacyProfile(1);
        $this->seedLegacyProfile($second, ['window_days' => 30]);

        $this->migrate();

        $model = new KenshoProfileModel($this->container);
        $this->assertSame(180, $model->getProfile(1)['window_days']);
        $this->assertSame(30, $model->getProfile($second)['window_days'], "the second user's profile migrated too");
    }

    public function testMigrationLeavesLegacyRowsInPlaceForRollback(): void
    {
        $this->seedLegacyProfile(1);

        $this->migrate();

        $legacy = $this->container['userMetadataModel']->get(1, KenshoProfileModel::KEY, '');
        $this->assertNotSame('', $legacy, 'the original metadata row must survive so a downgrade to 1.0.1 still works');
        $this->assertSame(180, json_decode($legacy, true)['window_days']);
    }

    /**
     * On MySQL the old rows may have been truncated mid-JSON by the very bug
     * this migration fixes. Those are unrecoverable — but they must not stop
     * the migration, or the plugin cannot be enabled at all.
     */
    public function testMigrationSkipsCorruptRowsWithoutFailing(): void
    {
        $intact = $this->createUser('intact');
        $broken = $this->createUser('broken');
        $this->seedLegacyProfile($intact);

        // A row truncated at 255 chars, exactly as MySQL would have left it.
        $this->container['userMetadataModel']->save($broken, [
            KenshoProfileModel::KEY => substr(json_encode($this->seedLegacyProfile($broken)), 0, 255),
        ]);

        $this->migrate();

        $model = new KenshoProfileModel($this->container);
        $this->assertCount(2, $model->getProfile($intact)['areas'], 'the intact profile migrated');
        $this->assertSame(
            KenshoProfileModel::defaults(),
            $model->getProfile($broken),
            'the truncated profile was skipped and the user falls back to defaults'
        );
    }

    public function testMigrationIsSafeOnAnInstallWithNoData(): void
    {
        $this->migrate();

        $this->assertSame(KenshoProfileModel::defaults(), (new KenshoProfileModel($this->container))->getProfile(1));
    }

    /** A deleted user must not leave an orphaned profile behind. */
    public function testProfileCascadesWhenTheUserIsDeleted(): void
    {
        $this->migrate();
        $doomed = $this->createUser('doomed');

        $model = new KenshoProfileModel($this->container);
        $model->upsertArea($doomed, ['label' => 'Health', 'importance' => 9]);
        $this->assertCount(1, $model->getProfile($doomed)['areas']);

        $this->container['db']->getConnection()->exec('PRAGMA foreign_keys = ON');
        (new UserModel($this->container))->remove($doomed);

        $orphans = $this->container['db']
            ->table(KenshoProfileModel::TABLE)
            ->eq('user_id', $doomed)
            ->count();
        $this->assertSame(0, $orphans, 'the profile is removed with its user');
    }
}
