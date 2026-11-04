<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * GameChestTypes Model
 *
 * The game sends a chest's type as a number; this table names it, once, for the
 * collector and for the chests waiting in the review queue. Its key is the game's
 * own number, not one of ours.
 *
 * The site does not fall back to a generic table on the web (see Application::bootstrap),
 * so the table needs this class to be used at all.
 */
class GameChestTypesTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('game_chest_types');
        $this->setDisplayField('name');
        $this->setPrimaryKey('game_type_id');
    }

    /**
     * The names of the chests each standard chest gives, by its id, in alphabetical order.
     * A type is of a standard chest by its link or, while the collector has not linked
     * it yet, by its source.
     *
     * @param iterable<\App\Model\Entity\StandardChest> $chests Standard chests.
     * @return array<int, list<string>>
     */
    public function namesFor(iterable $chests): array
    {
        $ids = [];
        $sources = [];
        $bySource = [];
        foreach ($chests as $chest) {
            $ids[] = (int)$chest->id;
            $sources[] = (string)$chest->source;
            $bySource[mb_strtolower((string)$chest->source)] = (int)$chest->id;
        }
        if ($ids === []) {
            return [];
        }

        $rows = $this->find()
            ->select(['name', 'source', 'standard_chest_id'])
            ->where(['OR' => ['standard_chest_id IN' => $ids, 'source IN' => $sources]])
            ->disableHydration()
            ->all();

        $names = [];
        foreach ($rows as $row) {
            $id = $row['standard_chest_id'] !== null ? (int)$row['standard_chest_id']
                : ($bySource[mb_strtolower((string)$row['source'])] ?? null);
            if ($id !== null && in_array($id, $ids, true)) {
                $names[$id][(string)$row['name']] = true;
            }
        }
        foreach ($names as &$list) {
            $list = array_keys($list);
            natcasesort($list);
            $list = array_values($list);
        }

        return $names;
    }

    /**
     * The standard chests that give a chest whose name is like this, as conditions on StandardChests.
     *
     * @param string $like A LIKE pattern, already escaped.
     * @return array<string, mixed>
     */
    public function standardChestsNamed(string $like): array
    {
        return [
            'StandardChests.id IN' => $this->find()->select(['standard_chest_id'])
                ->where(['name LIKE' => $like, 'standard_chest_id IS NOT' => null]),
            'StandardChests.source IN' => $this->find()->select(['source'])
                ->where(['name LIKE' => $like, 'standard_chest_id IS' => null]),
        ];
    }
}
