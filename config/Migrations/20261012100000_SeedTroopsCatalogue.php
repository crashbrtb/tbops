<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Load the troop catalogue on installs that never ran `bin/cake import_troops`.
 *
 * The calculator builds its level picker from the `troops` table, and nothing
 * but that command ever filled it: an install that only ran the migrations got
 * a calculator with an empty "Levels you are bringing" box and nothing to plan.
 *
 * Only an empty table is filled, so a catalogue that is already there - and the
 * units a player switched off in it - is left exactly as it is.
 */
class SeedTroopsCatalogue extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        if (!$this->hasTable('troops')) {
            return;
        }

        $existing = $this->fetchRow('SELECT COUNT(*) AS total FROM troops');
        if ((int)($existing['total'] ?? 0) > 0) {
            return;
        }

        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'troops.json';
        if (!is_readable($file)) {
            return;
        }

        $payload = json_decode((string)file_get_contents($file), true);
        if (!is_array($payload) || !is_array($payload['troops'] ?? null)) {
            return;
        }

        // The file may carry fields a given schema does not have, so each row is
        // cut down to the columns that exist.
        $columns = [];
        foreach ($this->table('troops')->getColumns() as $column) {
            $columns[$column->getName()] = true;
        }
        unset($columns['id']);

        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($payload['troops'] as $troop) {
            if (!is_array($troop) || !isset($troop['slug'])) {
                continue;
            }

            $row = [];
            foreach (array_keys($columns) as $name) {
                $value = $troop[$name] ?? null;
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                } elseif (is_bool($value)) {
                    $value = (int)$value;
                }
                $row[$name] = $value;
            }
            $row['enabled'] = 1;
            $row['created'] = $now;
            $row['modified'] = $now;

            $rows[] = $row;
        }

        if ($rows !== []) {
            $this->table('troops')->insert($rows)->saveData();
        }
    }

    /**
     * Down Method.
     *
     * The catalogue is game data, not schema: rolling this back leaves it alone.
     *
     * @return void
     */
    public function down(): void
    {
    }
}
