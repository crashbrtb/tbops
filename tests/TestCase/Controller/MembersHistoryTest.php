<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\MemberPowerHistoryService;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * @uses \App\Controller\MembersController::history()
 * @uses \App\Service\MemberPowerHistoryService
 */
class MembersHistoryTest extends TestCase
{
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Users',
        'app.Roles',
        'app.RolesUsers',
        'app.Config',
        'app.Members',
        'app.MemberPowerSnapshots',
    ];

    private function loginAdmin(): void
    {
        $this->session(['Auth' => ['id' => 1, 'email' => 'admin@example.com', 'created' => new DateTime('2026-01-01')]]);
    }

    /**
     * @param string $player Name.
     * @param array<string, int> $readings Power by day (days ago => power).
     * @param bool $active Whether the member is in the clan.
     * @return int Member id.
     */
    private function memberWith(string $player, array $readings, bool $active = true): int
    {
        $members = $this->fetchTable('Members');
        $member = $members->newEntity(['player' => $player, 'active' => (int)$active, 'power' => 0]);
        $members->saveOrFail($member);

        $snapshots = $this->fetchTable('MemberPowerSnapshots');
        foreach ($readings as $daysAgo => $power) {
            $snapshots->saveOrFail($snapshots->newEntity([
                'member_id' => $member->id,
                'snapshot_date' => Date::today()->subDays((int)$daysAgo)->format('Y-m-d'),
                'power' => $power,
                'source' => 'member_list',
                'recorded_at' => DateTime::now(),
            ]));
        }

        return (int)$member->id;
    }

    public function testOnlyAdministratorsSeeTheHistory(): void
    {
        $this->get('/members/history');
        $this->assertResponseCode(403);
    }

    public function testThePageSaysWhenThereIsNoHistoryYet(): void
    {
        $this->loginAdmin();
        $this->get('/members/history');

        $this->assertResponseOk();
        $this->assertResponseContains('There is no power history for this period yet.');
    }

    public function testTheClanSumsItsMembersAndEachMemberShowsTheirGrowth(): void
    {
        $this->fetchTable('Members')->deleteAll([]);
        $brunilda = $this->memberWith('Brunilda', [20 => 1_000_000, 10 => 1_200_000, 0 => 1_500_000]);
        $this->memberWith('Naughtius', [20 => 2_000_000, 0 => 1_900_000]);
        $this->memberWith('Gone', [20 => 5_000_000], false);
        // Out of the 90-day period.
        $this->memberWith('Ancient', [200 => 9_000_000]);

        $service = new MemberPowerHistoryService();
        $from = $service->periodStart(90);

        $clan = $service->clanSeries($from);
        $this->assertSame([8_000_000, 1_200_000, 3_400_000], array_column($clan, 'power'));
        $this->assertSame([3, 1, 2], array_column($clan, 'players'));

        $growth = $service->growth($from);
        $this->assertSame(['Brunilda', 'Naughtius'], array_column($growth, 'player'), 'active members, biggest growth first');
        $this->assertSame(500_000, $growth[0]['change']);
        $this->assertSame(50.0, $growth[0]['percent']);
        $this->assertSame(3, $growth[0]['readings']);
        $this->assertSame(-100_000, $growth[1]['change']);

        $this->assertCount(4, $service->clanSeries($service->periodStart(0)), 'everything');

        $this->loginAdmin();
        $this->get('/members/history?period=90&player=' . $brunilda);
        $this->assertResponseOk();
        $this->assertResponseContains('clanPowerChart');
        $this->assertResponseContains('playerPowerChart');
        $this->assertResponseContains('Brunilda');
        $this->assertResponseNotContains('Ancient');
    }

    public function testAnUnknownPeriodFallsBackToTheDefault(): void
    {
        $this->memberWith('Brunilda', [5 => 1_000]);

        $this->loginAdmin();
        $this->get('/members/history?period=7');

        $this->assertResponseOk();
        $this->assertSame(MemberPowerHistoryService::DEFAULT_PERIOD, $this->viewVariable('period'));
    }
}
