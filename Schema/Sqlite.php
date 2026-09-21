<?php

namespace Kanboard\Plugin\Kensho\Schema;

use PDO;

const VERSION = 1;

/**
 * Move the user profile out of user_has_metadata.
 *
 * That `value` column is VARCHAR(255) on MySQL and Postgres, but a profile with
 * even two focus areas — or a single reflection — already serializes past that.
 * Every write failed with SQLSTATE[22001] (MySQL 1406), or truncated silently
 * outside strict mode, corrupting the profile. SQLite ignores VARCHAR lengths,
 * which is why the plugin appeared to work there.
 *
 * Existing rows are COPIED, not moved: the originals stay in user_has_metadata
 * so downgrading to 1.0.1 still finds its data.
 */
function version_1(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE kensho_profiles (
            user_id INTEGER NOT NULL,
            profile TEXT NOT NULL,
            PRIMARY KEY(user_id),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    migrate_from_metadata($pdo);
}

/**
 * Copy `kensho_profile` metadata rows into the new table.
 *
 * Rows that no longer decode are skipped rather than failing the migration: on
 * MySQL they were truncated mid-JSON by the very bug this fixes, and a
 * migration that throws would leave the plugin unable to enable at all. A
 * skipped user simply starts again from a default profile.
 */
function migrate_from_metadata(PDO $pdo)
{
    $rows = $pdo
        ->query("SELECT user_id, value FROM user_has_metadata WHERE name = 'kensho_profile'")
        ->fetchAll(PDO::FETCH_ASSOC);

    $insert = $pdo->prepare('INSERT INTO kensho_profiles (user_id, profile) VALUES (?, ?)');

    foreach ($rows as $row) {
        if (! is_array(json_decode((string) $row['value'], true))) {
            continue; // truncated or corrupt — unrecoverable, and not worth bricking the install over
        }
        $insert->execute([(int) $row['user_id'], (string) $row['value']]);
    }
}
