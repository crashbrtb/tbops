<?php
declare(strict_types=1);

use Migrations\AbstractSeed;

/**
 * Initial data seed.
 *
 * Inserts the essential records required for the application to work:
 * - roles (3 records)
 * - config (21 records)
 * - standard_chests (96 records)
 *
 * This seed is idempotent: it checks if data already exists
 * before inserting, so it is safe to run multiple times.
 */
class InitialDataSeed extends AbstractSeed
{
    /**
     * Run the seed.
     */
    public function run(): void
    {
        $this->seedRoles();
        $this->seedConfig();
        $this->seedStandardChests();
    }

    /**
     * Seed the roles table with 3 default roles.
     */
    private function seedRoles(): void
    {
        $table = $this->table('roles');
        $exists = $this->fetchRow('SELECT COUNT(*) as cnt FROM roles');

        if ($exists && $exists['cnt'] > 0) {
            return;
        }

        $data = [
            [
                'id' => 1,
                'name' => 'admin',
                'description' => 'Administrador',
                'alias' => 'admin',
                'created' => '2024-10-06 11:02:02',
                'modified' => '2024-10-06 11:02:02',
            ],
            [
                'id' => 2,
                'name' => 'user',
                'description' => 'Users',
                'alias' => 'user',
                'created' => '2024-10-06 11:02:29',
                'modified' => '2024-10-06 11:02:29',
            ],
            [
                'id' => 3,
                'name' => 'bankers',
                'description' => 'Person responsible for managing the clan\'s bank.',
                'alias' => 'bankers',
                'created' => '2025-11-16 23:13:05',
                'modified' => '2025-11-16 23:13:05',
            ],
        ];

        $table->insert($data)->save();
    }

    /**
     * Seed the config table with 21 default configuration parameters.
     */
    private function seedConfig(): void
    {
        $data = [
            [
                'param' => 'reference_day',
                'value' => '2025-07-30 17:00:00',
                'description' => 'Select a reference date for the start/end of the count. For example: If it is a weekly count, select a date that represents the day of the week that the count will start/end. If the count is for the Rise of Ancients event, select a date that represents the day of the event. Always use the format (YYYY-MM-DD hh:mm:ss) with UTC time (ex: "2025-05-13 17:00:00")',
            ],
            [
                'param' => 'every_how_many_days',
                'value' => '6',
                'description' => 'Every how many days: Sets how many days each counting period lasts. Suggestion: 6 to start counting every elder 7 for a weekly count',
            ],
            [
                'param' => 'minimum_chest_score',
                'value' => '15000',
                'description' => 'Minimum Chest Score',
            ],
            [
                'param' => 'minimum_epic_score',
                'value' => '6000',
                'description' => 'Minimum points for collecting MONSTER epic chests.',
            ],
            [
                'param' => 'clan_name',
                'value' => 'Special Task Force',
                'description' => 'Clan name',
            ],
            [
                'param' => 'clan_acronym',
                'value' => 'ABC',
                'description' => 'clan acronym',
            ],
            [
                'param' => 'kingdom_number',
                'value' => 'K001',
                'description' => 'kingdom number kxxx',
            ],
            [
                'param' => 'score_color_start_r',
                'value' => '255',
                'description' => 'R (Red) value of the initial color for low score (0-255).',
            ],
            [
                'param' => 'score_color_start_g',
                'value' => '0',
                'description' => 'G (Green) value of the starting color for low score (0-255).',
            ],
            [
                'param' => 'score_color_start_b',
                'value' => '0',
                'description' => 'Starting color B (Blue) value for low score (0-255).',
            ],
            [
                'param' => 'score_color_end_r',
                'value' => '0',
                'description' => 'R (Red) value of the final color for high score (0-255).',
            ],
            [
                'param' => 'score_color_end_g',
                'value' => '255',
                'description' => 'G (Green) value of the final color for high score (0-255).',
            ],
            [
                'param' => 'score_color_end_b',
                'value' => '0',
                'description' => 'Final color B (Blue) value for high score (0-255).',
            ],
            [
                'param' => 'score_color_transition_start',
                'value' => '0.9',
                'description' => 'Value between 0 and 1 (e.g. 0.9 for 90%) that defines the point at which the score color starts to change from the initial color to the final color.',
            ],
            [
                'param' => 'minimum_epic_chest_score',
                'value' => '6000',
                'description' => 'Minimum epic chest score',
            ],
            [
                'param' => 'deposit_fee',
                'value' => '50',
                'description' => 'Fixed deposit fee in millions of Silver',
            ],
            [
                'param' => 'withdrawal_fee',
                'value' => '50',
                'description' => 'Fixed withdrawal fee in millions of Silver',
            ],
            [
                'param' => 'transfer_fee',
                'value' => '10',
                'description' => 'Fixed transfer fee in millions of Silver',
            ],
            [
                'param' => 'caravan_fee',
                'value' => '20',
                'description' => 'Caravan fee percentage for deposits',
            ],
            [
                'param' => 'bank_function',
                'value' => '1',
                'description' => '1 = Bank active / 0 = no Bank',
            ],
            [
                'param' => 'calculator_function',
                'value' => '1',
                'description' => '1 = Troop Calculator active / 0 = no Troop Calculator',
            ],
            [
                'param' => 'clan_data_function',
                'value' => '0',
                'description' => '1 = Clan Data menu (power history) shown to everyone, signed in or not / 0 = administrators only',
            ],
            [
                'param' => 'collected_chests_retention_days',
                'value' => '30',
                'description' => 'Retention time in days for old collected chests. Minimum retention time must be greater than 7 days, and default is 30 days. Setting to 0 disables automatic purge.',
            ],
        ];

        // Insert only the parameters that are still missing. A per-parameter
        // check is required because migrations may already have populated the
        // table (e.g. AddCollectedChestsRetentionDaysConfig), which would make
        // a table-wide "is it empty?" guard skip every remaining parameter.
        $existing = array_column($this->fetchAll('SELECT param FROM config'), 'param');

        $missing = array_values(array_filter(
            $data,
            fn (array $row): bool => !in_array($row['param'], $existing, true)
        ));

        if (!$missing) {
            return;
        }

        $this->table('config')->insert($missing)->save();
    }

    /**
     * Seed the standard_chests table with 96 unique chest types.
     */
    private function seedStandardChests(): void
    {
        $table = $this->table('standard_chests');
        $exists = $this->fetchRow('SELECT COUNT(*) as cnt FROM standard_chests');

        if ($exists && $exists['cnt'] > 0) {
            return;
        }

        $data = [
            ['id' => 1, 'source' => 'Arena', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 2, 'source' => 'Story', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 3, 'source' => 'Level 5 Crypt', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 4, 'source' => 'Level 10 Crypt', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 5, 'source' => 'Level 15 Crypt', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 6, 'source' => 'Level 20 Crypt', 'score' => 3, 'monster' => 0, 'qty_chest' => null],
            ['id' => 7, 'source' => 'Level 25 Crypt', 'score' => 19, 'monster' => 0, 'qty_chest' => null],
            ['id' => 8, 'source' => 'Level 10 rare Crypt', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 9, 'source' => 'Level 15 rare Crypt', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 10, 'source' => 'Level 20 rare Crypt', 'score' => 10, 'monster' => 0, 'qty_chest' => null],
            ['id' => 11, 'source' => 'Level 25 rare Crypt', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 12, 'source' => 'Level 30 rare Crypt', 'score' => 90, 'monster' => 0, 'qty_chest' => null],
            ['id' => 13, 'source' => 'Level 15 epic Crypt', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 14, 'source' => 'Level 20 epic Crypt', 'score' => 35, 'monster' => 0, 'qty_chest' => null],
            ['id' => 15, 'source' => 'Level 25 epic Crypt', 'score' => 55, 'monster' => 0, 'qty_chest' => null],
            ['id' => 16, 'source' => 'Level 30 epic Crypt', 'score' => 80, 'monster' => 0, 'qty_chest' => null],
            ['id' => 17, 'source' => 'Level 35 epic Crypt', 'score' => 120, 'monster' => 0, 'qty_chest' => null],
            ['id' => 18, 'source' => 'Level 10 Citadel', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 19, 'source' => 'Level 15 Citadel', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 20, 'source' => 'Level 20 Citadel', 'score' => 10, 'monster' => 0, 'qty_chest' => null],
            ['id' => 21, 'source' => 'Level 25 Citadel', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 22, 'source' => 'Level 30 Citadel', 'score' => 60, 'monster' => 0, 'qty_chest' => null],
            ['id' => 23, 'source' => 'Level 20 cursed Citadel', 'score' => 10, 'monster' => 0, 'qty_chest' => null],
            ['id' => 24, 'source' => 'Level 25 cursed Citadel', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 25, 'source' => 'Wooden Chest', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 26, 'source' => 'Bronze Chest', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 27, 'source' => 'Silver Chest', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 28, 'source' => 'Golden Chest', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 29, 'source' => 'Precious Chest', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 30, 'source' => 'Magic Chest', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 31, 'source' => 'Mercenary Exchange', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 32, 'source' => 'Epic Undead squad', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 33, 'source' => 'Shadow City', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 34, 'source' => 'Level 16 heroic Monster', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 35, 'source' => 'Level 17 heroic Monster', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 36, 'source' => 'Level 18 heroic Monster', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 37, 'source' => 'Level 19 heroic Monster', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 38, 'source' => 'Level 20 heroic Monster', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 39, 'source' => 'Level 21 heroic Monster', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 40, 'source' => 'Level 22 heroic Monster', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 41, 'source' => 'Level 23 heroic Monster', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 42, 'source' => 'Level 24 heroic Monster', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 43, 'source' => 'Level 25 heroic Monster', 'score' => 10, 'monster' => 0, 'qty_chest' => null],
            ['id' => 44, 'source' => 'Level 26 heroic Monster', 'score' => 10, 'monster' => 0, 'qty_chest' => null],
            ['id' => 45, 'source' => 'Level 27 heroic Monster', 'score' => 10, 'monster' => 0, 'qty_chest' => null],
            ['id' => 46, 'source' => 'Level 28 heroic Monster', 'score' => 10, 'monster' => 0, 'qty_chest' => null],
            ['id' => 47, 'source' => 'Level 29 heroic Monster', 'score' => 10, 'monster' => 0, 'qty_chest' => null],
            ['id' => 48, 'source' => 'Level 30 heroic Monster', 'score' => 10, 'monster' => 0, 'qty_chest' => null],
            ['id' => 49, 'source' => 'Level 31 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 50, 'source' => 'Level 32 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 51, 'source' => 'Level 33 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 52, 'source' => 'Level 34 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 53, 'source' => 'Level 35 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 54, 'source' => 'Level 36 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 55, 'source' => 'Level 37 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 56, 'source' => 'Level 38 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 57, 'source' => 'Level 39 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 58, 'source' => 'Level 40 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 59, 'source' => 'Level 41 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 60, 'source' => 'Level 42 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 61, 'source' => 'Level 43 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 62, 'source' => 'Level 44 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 63, 'source' => 'Level 45 heroic Monster', 'score' => 30, 'monster' => 0, 'qty_chest' => null],
            ['id' => 64, 'source' => 'Authority Rush tournament', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 65, 'source' => 'Epic Fenrir squad', 'score' => 5, 'monster' => 1, 'qty_chest' => 25],
            ['id' => 66, 'source' => 'Epic Inferno squad', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 67, 'source' => 'Epic Jormungandr squad', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 69, 'source' => 'Tartaros Crypt level 20', 'score' => 20, 'monster' => 0, 'qty_chest' => null],
            ['id' => 70, 'source' => 'Tartaros Crypt level 25', 'score' => 60, 'monster' => 0, 'qty_chest' => null],
            ['id' => 71, 'source' => 'Tartaros Crypt level 30', 'score' => 90, 'monster' => 0, 'qty_chest' => null],
            ['id' => 72, 'source' => 'Tartaros Crypt level 35', 'score' => 120, 'monster' => 0, 'qty_chest' => null],
            ['id' => 74, 'source' => 'Hermes\' Store', 'score' => 10, 'monster' => 0, 'qty_chest' => null],
            ['id' => 75, 'source' => 'Arachne\'s Swarm Epic squad', 'score' => 35, 'monster' => 0, 'qty_chest' => null],
            ['id' => 77, 'source' => 'Union of Triumph personal reward', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 78, 'source' => 'Clan wealth', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 79, 'source' => 'Level 45 Vault of the Ancients', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 80, 'source' => 'Rise of the Ancients event', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 81, 'source' => 'Epic Ancient squad', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 82, 'source' => 'Mimic Chest', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 85, 'source' => 'Alchemy tournament', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 86, 'source' => 'Lvl 20-24 Raid Runic squad', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 87, 'source' => 'Lvl 45 Raid Runic squad', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 88, 'source' => 'Lvl 40-44 Raid Runic squad', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 89, 'source' => 'Lvi 30-34 Raid Runic squad', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 90, 'source' => 'Tartaros Crypt level 10', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 91, 'source' => 'Tartaros Crypt level 15', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 92, 'source' => 'Bank', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 93, 'source' => 'Level 40-44 Vault of the Ancients', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 94, 'source' => 'Level 35-39 Vault of the Ancients', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 96, 'source' => 'Epic Briareus squad', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 97, 'source' => 'Level 30-34 Vault of the Ancients', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 98, 'source' => 'Event "Trials of Olympus"', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 99, 'source' => 'Jérmungandr Shop', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 100, 'source' => 'Jormungandr Shop', 'score' => 0, 'monster' => 0, 'qty_chest' => null],
            ['id' => 101, 'source' => 'Epic Chimera squad', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
            ['id' => 102, 'source' => 'Epic Basilisk squad', 'score' => 5, 'monster' => 0, 'qty_chest' => null],
        ];

        $table->insert($data)->save();
    }
}
