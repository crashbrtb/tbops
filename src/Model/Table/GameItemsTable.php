<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * GameItems Model: the game's items, their names and icons, for the prizes.
 */
class GameItemsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('game_items');
        $this->setDisplayField('name');
        $this->setPrimaryKey('item_id');
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['modified' => 'always']]]);
    }

    /**
     * Item id => icon name, of the items that have one.
     *
     * @param list<int> $ids Items.
     * @return array<int, string>
     */
    public function iconsOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->find()
            ->select(['item_id', 'icon'])
            ->where(['item_id IN' => array_values(array_unique($ids)), 'icon IS NOT' => null])
            ->disableHydration()
            ->all()
            ->combine('item_id', 'icon')
            ->toArray();
    }
}
