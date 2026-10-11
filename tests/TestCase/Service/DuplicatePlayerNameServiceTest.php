<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\DuplicatePlayerNameService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * @uses \App\Service\DuplicatePlayerNameService
 */
class DuplicatePlayerNameServiceTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Members',
        'app.AdminNotifications',
    ];

    /**
     * @param string $player Name.
     * @param int $active 1 or 0.
     * @return int Member id.
     */
    private function member(string $player, int $active = 1): int
    {
        $members = $this->fetchTable('Members');
        $member = $members->newEntity([
            'player' => $player,
            'active' => $active,
            'power' => 0,
            'guards' => 0,
            'specialists' => 0,
            'monsters' => 0,
            'engineers' => 0,
        ]);

        return (int)$members->saveOrFail($member)->id;
    }

    public function testNamesTheCounterCannotTellApart(): void
    {
        $service = new DuplicatePlayerNameService();

        $this->assertTrue($service->collide('ALOK BR', 'Alok BR'));
        $this->assertTrue($service->collide('Tony Stanna', 'TonyStanna'));
        $this->assertTrue($service->collide('Malvadão', 'Malvadao'));
        $this->assertTrue($service->collide('PITIBUL DA PENHA R)', 'PITIBUL DA PENHA R}'));

        // Alike to the eye, but different text: their chests never mix.
        $this->assertFalse($service->collide('Cash BR', 'Crash BR'));
        $this->assertFalse($service->collide('Pandeménia', 'Pandeménio'));
        $this->assertFalse($service->collide('Farm 1', 'Farm 2'));
        $this->assertFalse($service->collide('...', '---'));
    }

    public function testScanRaisesOneAlertPerPair(): void
    {
        $first = $this->member('ALOK BR');
        $second = $this->member('Alok BR');
        $this->member('Thor');

        $service = new DuplicatePlayerNameService();
        $this->assertSame(['created' => 1, 'resolved' => 0], $service->scan());
        // The same pair is not reported twice.
        $this->assertSame(['created' => 0, 'resolved' => 0], $service->scan());

        $notification = $this->fetchTable('AdminNotifications')->find('unread')->firstOrFail();
        $this->assertSame(
            [['id' => $first, 'player' => 'ALOK BR'], ['id' => $second, 'player' => 'Alok BR']],
            $notification->members()
        );
        $this->assertSame('Possible duplicate player: "ALOK BR" and "Alok BR"', $notification->message());
    }

    public function testInactiveMembersAreLeftAlone(): void
    {
        $this->member('ALOK BR');
        $this->member('Alok BR', 0);

        $this->assertSame(0, (new DuplicatePlayerNameService())->scan()['created']);
    }

    public function testAPairMarkedAsReadIsNotReportedAgain(): void
    {
        $this->member('ALOK BR');
        $this->member('Alok BR');
        $service = new DuplicatePlayerNameService();
        $service->scan();

        $notifications = $this->fetchTable('AdminNotifications');
        $this->assertSame(1, $notifications->markRead(1));

        $this->assertSame(['created' => 0, 'resolved' => 0], $service->scan());
        $this->assertSame(0, $notifications->find('unread')->count());
        $this->assertSame(1, $notifications->find()->count());
    }

    public function testAnAlertThatNoLongerAppliesIsClosedAndMayComeBack(): void
    {
        $this->member('ALOK BR');
        $second = $this->member('Alok BR');
        $service = new DuplicatePlayerNameService();
        $service->scan();

        $members = $this->fetchTable('Members');
        $members->updateAll(['player' => 'Somebody Else'], ['id' => $second]);
        $this->assertSame(['created' => 0, 'resolved' => 1], $service->scan());

        $notifications = $this->fetchTable('AdminNotifications');
        $closed = $notifications->find()->firstOrFail();
        $this->assertNotNull($closed->read_at);
        $this->assertNull($closed->read_by);

        $members->updateAll(['player' => 'Alok BR'], ['id' => $second]);
        $this->assertSame(['created' => 1, 'resolved' => 0], $service->scan());
        $this->assertSame(2, $notifications->find()->count());
    }
}
