<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Convert every application table that is not fully utf8mb4 to utf8mb4.
 *
 * Several migrations created tables without stating a character set
 * (manual_goal_penalties, goal_penalty_waivers, troops, live_tournaments,
 * clan_tournament_results, game_images, game_items...). On a server whose
 * database default is latin1 those tables came out latin1, and then:
 *
 * - comparing one of their columns with a utf8mb4 value fails with
 *   "Illegal mix of collations (latin1_general_ci,IMPLICIT) and
 *   (utf8mb4_general_ci,COERCIBLE)" - e.g. adding a manual penalty to
 *   "Uğur 43 1907";
 * - characters latin1 cannot hold (ğ, ş, Cyrillic, CJK...) are stored as "?".
 *
 * The connection already talks utf8mb4, so whatever is stored is real
 * latin1 text and CONVERT TO translates it without loss. Characters that
 * were already replaced by "?" cannot be recovered.
 *
 * Idempotent: tables already in utf8mb4 (table default and every text
 * column) are left alone. phinxlog belongs to the migrations tool and is
 * skipped.
 */
class ConvertTablesToUtf8mb4 extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'mysql') {
            return;
        }

        $rows = $this->fetchAll(
            "SELECT DISTINCT t.TABLE_NAME AS name
               FROM information_schema.TABLES t
               LEFT JOIN information_schema.COLUMNS c
                 ON c.TABLE_SCHEMA = t.TABLE_SCHEMA
                AND c.TABLE_NAME = t.TABLE_NAME
                AND c.CHARACTER_SET_NAME IS NOT NULL
                AND c.CHARACTER_SET_NAME <> 'utf8mb4'
              WHERE t.TABLE_SCHEMA = DATABASE()
                AND t.TABLE_TYPE = 'BASE TABLE'
                AND t.TABLE_NAME <> 'phinxlog'
                AND (t.TABLE_COLLATION NOT LIKE 'utf8mb4%' OR c.COLUMN_NAME IS NOT NULL)"
        );

        foreach ($rows as $row) {
            $this->execute(sprintf(
                'ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
                str_replace('`', '``', (string)($row['name'] ?? $row[0]))
            ));
        }
    }

    /**
     * Down Method.
     *
     * Nothing to undo: going back to latin1 would only lose characters again.
     *
     * @return void
     */
    public function down(): void
    {
    }
}
