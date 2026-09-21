<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\Kensho\Model\KenshoProfileModel;
use KanboardTests\units\Base;

require_once __DIR__ . '/KenshoSchemaHelper.php';

/**
 * Where the profile is allowed to live.
 *
 * `user_has_metadata.value` is VARCHAR(255) on MySQL and Postgres. A profile
 * with even a couple of focus areas serializes well past that, so storing the
 * blob there fails with SQLSTATE[22001] (MySQL 1406) or — outside strict mode —
 * truncates silently and corrupts the profile.
 *
 * SQLite ignores declared VARCHAR lengths, so this harness can never fail on
 * the real constraint. These tests therefore assert on strlen() of the value
 * actually written, which is driver-independent and catches the bug anywhere.
 */
class ProfileStorageTest extends Base
{
    use KenshoSchemaHelper;

    /** The real limit of user_has_metadata.value on MySQL and Postgres. */
    private const METADATA_VALUE_LIMIT = 255;

    private function model(): KenshoProfileModel
    {
        return new KenshoProfileModel($this->container);
    }

    /**
     * A profile as an actual user would build it: a handful of focus areas,
     * each with the identity note the goals surface invites them to write.
     */
    private function realisticProfile(): array
    {
        $profile = KenshoProfileModel::defaults();

        foreach ([
            ['Health & Fitness', 'Someone who trains consistently, not sporadically.'],
            ['Deep Work',        'A person who ships hard things without thrashing.'],
            ['Family',           'Present in the evenings, not half-there on a laptop.'],
            ['Craft & Learning', 'Always reading one thing that is slightly too hard.'],
        ] as [$label, $identity]) {
            $profile['areas'][] = [
                'label'      => $label,
                'identity'   => $identity,
                'importance' => 8,
                'goals'      => ['Show up three times a week.'],
            ];
        }

        return $profile;
    }

    /** @return array<string,string> every metadata row held for this user */
    private function storedMetadata(int $userId): array
    {
        $rows = $this->container['db']
            ->table('user_has_metadata')
            ->eq('user_id', $userId)
            ->findAll();

        return array_column($rows, 'value', 'name');
    }

    /**
     * The pin. The profile must not be written into user_has_metadata, and
     * nothing the plugin does write there may exceed the column's real width.
     */
    public function testProfileIsNotStoredInTheVarchar255MetadataColumn(): void
    {
        $this->createKenshoSchema();
        $this->model()->saveProfile(1, $this->realisticProfile());

        // The pin itself: no oversized row may be written to the metadata table.
        foreach ($this->storedMetadata(1) as $name => $value) {
            $this->assertLessThanOrEqual(
                self::METADATA_VALUE_LIMIT,
                strlen((string) $value),
                sprintf(
                    'user_has_metadata.value is VARCHAR(255) on MySQL/Postgres, but the plugin '
                    . 'wrote %d chars into row "%s". On MySQL this fails with SQLSTATE[22001]; '
                    . 'elsewhere it truncates and corrupts the profile.',
                    strlen((string) $value),
                    $name
                )
            );
        }

        $this->assertSame(
            '',
            $this->container['userMetadataModel']->get(1, KenshoProfileModel::KEY, ''),
            'the profile must no longer touch the VARCHAR(255) metadata column'
        );

        // Sanity: this profile really is bigger than the old column could hold,
        // so the test cannot quietly become vacuous if the payload ever shrinks.
        $stored = $this->container['db']
            ->table(KenshoProfileModel::TABLE)
            ->eq('user_id', 1)
            ->findOneColumn('profile');

        $this->assertGreaterThan(
            self::METADATA_VALUE_LIMIT,
            strlen((string) $stored),
            'sanity: a realistic profile must exceed 255 chars for this test to mean anything'
        );
    }

    /** The profile must survive the round trip whole — nothing silently dropped. */
    public function testRealisticProfileRoundTripsIntact(): void
    {
        $this->createKenshoSchema();
        $m = $this->model();
        $m->saveProfile(1, $this->realisticProfile());

        $reloaded = $m->getProfile(1);

        $this->assertCount(4, $reloaded['areas'], 'all four focus areas survive');
        $this->assertSame('Craft & Learning', $reloaded['areas'][3]['label'], 'the last area is not truncated away');
        $this->assertSame(
            'Always reading one thing that is slightly too hard.',
            $reloaded['areas'][3]['identity'],
            'the identity note on the last area survives whole'
        );
        $this->assertSame(['Show up three times a week.'], $reloaded['areas'][3]['goals']);
    }
}
