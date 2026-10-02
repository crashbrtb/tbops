<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * AdminUsersFixture
 *
 * Backs the `AdminUsers` association alias, which points at the `users` table.
 */
class AdminUsersFixture extends TestFixture
{
    /**
     * @var string
     */
    public string $table = 'users';

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
                'name' => 'Lorem ipsum dolor sit amet',
                'email' => 'Lorem ipsum dolor sit amet',
                'password' => 'Lorem ipsum dolor sit amet',
                'created' => '2025-05-18 21:26:04',
                'modified' => '2025-05-18 21:26:04',
            ],
        ];
        parent::init();
    }
}
