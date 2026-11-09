<?php
declare(strict_types=1);

namespace App\Test\TestCase\Utility;

use App\Utility\GameNumber;
use Cake\TestSuite\TestCase;

/**
 * Silver in millions, written the way the game writes it.
 */
class GameNumberTest extends TestCase
{
    public function testSilverInTheGamesNotation(): void
    {
        $this->assertSame('1M', GameNumber::silver(1));
        $this->assertSame('500M', GameNumber::silver(500));
        $this->assertSame('1B', GameNumber::silver(1000));
        $this->assertSame('1,25B', GameNumber::silver(1250));
        $this->assertSame('1,5B', GameNumber::silver(1500), 'no trailing zero');
        $this->assertSame('10B', GameNumber::silver(10000));
        $this->assertSame('12,3B', GameNumber::silver(12345), 'three digits');
        $this->assertSame('1T', GameNumber::silver(999999), 'rounds up to the next unit');
        $this->assertSame('-300M', GameNumber::silver(-300));
        $this->assertSame('0', GameNumber::silver(0));
    }
}
