<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Starts empty: listing it makes the test suite clear the table between tests.
 * Each test raises the alerts it is about.
 */
class AdminNotificationsFixture extends TestFixture
{
    /**
     * @var array<array<string, mixed>>
     */
    public array $records = [];
}
