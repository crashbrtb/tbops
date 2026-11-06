<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\MemberPowerHistoryService;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\I18n\I18n;
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

    /**
     * @param string $value '1' or '0'.
     * @return void
     */
    private function clanData(string $value): void
    {
        $config = $this->fetchTable('Config');
        $row = $config->find()->where(['param' => 'clan_data_function'])->first()
            ?? $config->newEntity(['param' => 'clan_data_function', 'description' => 'Clan Data menu']);
        $row->set('value', $value);
        $config->saveOrFail($row);
    }

    public function testWithClanDataOffOnlyAdministratorsSeeTheHistory(): void
    {
        $this->get('/members/history');
        $this->assertResponseCode(404);

        $this->session(['Auth' => ['id' => 2, 'email' => 'member@example.com', 'created' => new DateTime('2026-01-01')]]);
        $this->get('/members/history');
        $this->assertResponseCode(404);

        $this->loginAdmin();
        $this->get('/members/history');
        $this->assertResponseOk();
        $this->assertResponseNotContains('clanDataDropdown', 'no Clan Data menu while it is off');
    }

    public function testWithClanDataOnEveryoneSeesTheHistoryFromItsMenu(): void
    {
        $this->clanData('1');
        $this->memberWith('Brunilda', [5 => 1_000, 0 => 2_000]);

        // Signed in or not.
        $this->get('/members/history');
        $this->assertResponseOk();
        $this->assertResponseContains('clanDataDropdown');
        $this->assertResponseContains('Brunilda');
        $this->assertResponseNotContains('/members/view/');

        $this->session(['Auth' => ['id' => 2, 'email' => 'member@example.com', 'created' => new DateTime('2026-01-01')]]);
        $this->get('/members/history');
        $this->assertResponseOk();
        $this->assertResponseContains('clanDataDropdown');
        $this->assertResponseContains('Brunilda');
        $this->assertResponseNotContains('/members/view/', 'members\' profiles stay with the administrators');
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

        // Only the day that read the whole clan (3 players) is the clan's power.
        $clan = $service->clanSeries($from);
        $this->assertSame([8_000_000], array_column($clan, 'power'));
        $this->assertSame([3], array_column($clan, 'players'));

        $growth = $service->growth($from);
        $this->assertSame(['Brunilda', 'Naughtius'], array_column($growth, 'player'), 'active members, biggest growth first');
        $this->assertSame(500_000, $growth[0]['change']);
        $this->assertSame(50.0, $growth[0]['percent']);
        $this->assertSame(3, $growth[0]['readings']);
        $this->assertSame(-100_000, $growth[1]['change']);

        $this->assertCount(1, $service->clanSeries($service->periodStart(0)), 'everything');

        $this->loginAdmin();
        $this->get('/members/history?period=90&player=' . $brunilda);
        $this->assertResponseOk();
        $this->assertResponseContains('clanPowerChart');
        $this->assertResponseContains('playerPowerChart');
        $this->assertResponseContains('Brunilda');
        $this->assertResponseNotContains('Ancient');
    }

    public function testADayThatReadOnlyPartOfTheClanIsNotTheClansPower(): void
    {
        $this->fetchTable('Members')->deleteAll([]);
        // An old tournament linked 2 of the 10 players; the Members list read all 10 since.
        for ($i = 1; $i <= 10; $i++) {
            $readings = [10 => 1_000 * $i, 0 => 1_100 * $i];
            if ($i <= 2) {
                $readings[30] = 900 * $i;
            }
            $this->memberWith("Player {$i}", $readings);
        }

        $service = new MemberPowerHistoryService();
        $clan = $service->clanSeries($service->periodStart(90));
        $this->assertSame([55_000, 60_500], array_column($clan, 'power'), 'the 2-player day is left out');

        // Each member still grows from their own first reading.
        $growth = array_column($service->growth($service->periodStart(90)), null, 'player');
        $this->assertSame(900, $growth['Player 1']['first']);
        $this->assertSame(3, $growth['Player 1']['readings']);
        $this->assertSame(3_000, $growth['Player 3']['first']);
    }

    public function testDatesAreRightWhateverTheLanguage(): void
    {
        $this->fetchTable('Members')->deleteAll([]);
        $this->memberWith('Brunilda', [1 => 1_000]);
        $expected = Date::today()->subDays(1)->format('Y-m-d');

        $locale = I18n::getLocale();
        I18n::setLocale('pt_BR');
        try {
            $service = new MemberPowerHistoryService();
            $this->assertSame($expected, $service->clanSeries(null)[0]['date']);
            $this->assertSame($expected, $service->playerSeries(
                (int)$this->fetchTable('Members')->find()->firstOrFail()->id,
                null
            )[0]['date']);
            $this->assertSame($expected, $service->growth(null)[0]['last_date']);
        } finally {
            I18n::setLocale($locale);
        }
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
