<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * DestinationMembersFixture
 *
 * Backs the `DestinationMembers` association alias, which points at the `members` table.
 */
class DestinationMembersFixture extends TestFixture
{
    /**
     * @var string
     */
    public string $table = 'members';

    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [
            [
                'id' => 2,
                'player' => 'Lorem ipsum dolor sit amet',
                'power' => 1,
                'guards' => 1,
                'specialists' => 1,
                'monsters' => 1,
                'engineers' => 1,
                'active' => 1,
                'created_at' => 1748572455,
                'modified_at' => 1748572455,
            ],
        ];
        parent::init();
    }
}
