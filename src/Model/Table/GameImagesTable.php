<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * GameImages Model: the game's own images, by their name in its Unity CDN.
 */
class GameImagesTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('game_images');
        $this->setDisplayField('name');
        $this->setPrimaryKey('name');
        $this->addBehavior('Timestamp');
    }

    /**
     * Name => file id of every image kept.
     *
     * @return array<string, string>
     */
    public function fileIds(): array
    {
        return $this->find()->select(['name', 'file_id'])->disableHydration()->all()->combine('name', 'file_id')->toArray();
    }
}
