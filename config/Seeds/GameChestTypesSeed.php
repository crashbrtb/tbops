<?php
declare(strict_types=1);

use Migrations\AbstractSeed;

/**
 * Game chest type catalogue seed.
 *
 * Ships the chest types the collector already learned on this clan, so a new or
 * updated deployment records those chests straight away instead of sending them
 * to the review queue for someone to name each type again from a picture.
 *
 * Idempotent per `game_type_id`: a type already in the table (named on that site
 * since) is left untouched. A type is linked to the `standard_chests` row of its
 * source when that site scores it; one that is not stays unlinked, as when the
 * collector learns it, and is linked by the collector once the source is added.
 */
class GameChestTypesSeed extends AbstractSeed
{
    /**
     * The standard chests are seeded first, so a fresh install links its types.
     *
     * @return list<string>
     */
    public function getDependencies(): array
    {
        return ['InitialDataSeed'];
    }

    /**
     * Run the seed.
     */
    public function run(): void
    {
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'game_chest_type_defaults.php';
        if (!is_file($file) || !$this->hasTable('game_chest_types')) {
            return;
        }

        $defaults = require $file;
        $now = date('Y-m-d H:i:s');
        $connection = $this->getAdapter()->getConnection();

        $known = [];
        foreach ($connection->execute('SELECT game_type_id FROM game_chest_types')->fetchAll('assoc') as $row) {
            $known[(int)$row['game_type_id']] = true;
        }
        $scored = [];
        foreach ($connection->execute('SELECT id, source FROM standard_chests')->fetchAll('assoc') as $row) {
            $scored[mb_strtolower((string)$row['source'])] ??= (int)$row['id'];
        }

        foreach ($defaults as $typeId => $type) {
            if (isset($known[$typeId])) {
                continue;
            }

            $connection->insert('game_chest_types', [
                'game_type_id' => $typeId,
                'name' => $type['name'],
                'source' => $type['source'],
                'standard_chest_id' => $scored[mb_strtolower($type['source'])] ?? null,
                'created' => $now,
                'modified' => $now,
            ]);
        }
    }
}
