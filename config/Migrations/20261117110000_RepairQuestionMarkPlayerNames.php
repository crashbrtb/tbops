<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Put back the player names that a latin1 table turned into "?".
 *
 * While some tables were latin1 (see ConvertTablesToUtf8mb4), every character
 * latin1 cannot hold was stored as "?": "Uğur 43 1907" became "U?ur 43 1907".
 * The real spelling is still in the chests and in the members list, so each
 * damaged name is matched against those: every "?" stands for exactly one
 * character, everything else must be equal. A name is only repaired when
 * exactly one real spelling fits; otherwise it is left alone.
 *
 * A row is not moved when the real name already has a row of its own for the
 * same cycle (the unique player/cycle key would refuse it anyway).
 *
 * Safe to run again: once repaired, nothing matches.
 */
class RepairQuestionMarkPlayerNames extends AbstractMigration
{
    /**
     * Tables keyed by player name and cycle: table => name column.
     *
     * @var array<string, string>
     */
    private const CYCLE_TABLES = [
        'player_cycle_summaries' => 'player_name',
        'manual_goal_penalties' => 'player_name',
        'goal_penalty_waivers' => 'player_name',
    ];

    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        $known = $this->knownNames();
        if ($known === []) {
            return;
        }

        foreach (self::CYCLE_TABLES as $table => $column) {
            if (!$this->hasTable($table)) {
                continue;
            }
            $rows = $this->fetchAll(sprintf(
                "SELECT id, `%s` AS name, cycle_start_date FROM `%s` WHERE `%s` LIKE '%%?%%'",
                $column,
                $table,
                $column
            ));
            foreach ($rows as $row) {
                $real = $this->realName((string)$row['name'], $known);
                if ($real === null) {
                    continue;
                }
                $taken = $this->query(
                    sprintf('SELECT 1 FROM `%s` WHERE `%s` = ? AND cycle_start_date = ? AND id <> ?', $table, $column),
                    [$real, $row['cycle_start_date'], $row['id']]
                )->fetch();
                if ($taken) {
                    continue;
                }
                $this->execute(
                    sprintf('UPDATE `%s` SET `%s` = ? WHERE id = ?', $table, $column),
                    [$real, $row['id']]
                );
            }
        }

        // A member whose name was damaged too gets the chest spelling back
        if ($this->hasTable('members')) {
            foreach ($this->fetchAll("SELECT id, player FROM members WHERE player LIKE '%?%'") as $row) {
                $real = $this->realName((string)$row['player'], $known);
                if ($real !== null) {
                    $this->execute('UPDATE members SET player = ? WHERE id = ?', [$real, $row['id']]);
                }
            }
        }
    }

    /**
     * Down Method.
     *
     * Nothing to undo: the "?" spellings were damage, not data.
     *
     * @return void
     */
    public function down(): void
    {
    }

    /**
     * The spellings the chests and the members carry, with no "?" in them.
     *
     * @return array<string, true>
     */
    private function knownNames(): array
    {
        $names = [];
        foreach (['collected_chests' => 'player', 'members' => 'player'] as $table => $column) {
            if (!$this->hasTable($table)) {
                continue;
            }
            $rows = $this->fetchAll(sprintf(
                "SELECT DISTINCT `%s` AS name FROM `%s` WHERE `%s` IS NOT NULL AND `%s` NOT LIKE '%%?%%'",
                $column,
                $table,
                $column,
                $column
            ));
            foreach ($rows as $row) {
                $names[(string)$row['name']] = true;
            }
        }

        return $names;
    }

    /**
     * The one known spelling a damaged name stands for, if exactly one fits.
     *
     * @param string $damaged Name with "?" in it.
     * @param array<string, true> $known Known spellings.
     * @return string|null
     */
    private function realName(string $damaged, array $known): ?string
    {
        $pattern = '/^' . str_replace('\\?', '.', preg_quote($damaged, '/')) . '$/u';
        $found = null;
        foreach (array_keys($known) as $name) {
            $name = (string)$name;
            if (!preg_match($pattern, $name)) {
                continue;
            }
            if ($found !== null && $found !== $name) {
                return null;
            }
            $found = $name;
        }

        return $found;
    }
}
