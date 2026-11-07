<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\GameTournament;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * @uses \App\Controller\MembersController::tournaments()
 * @uses \App\Service\ClanResultService
 */
class MembersTournamentHistoryTest extends TestCase
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
        'app.GameTournaments',
        'app.ClanTournamentResults',
    ];

    private function loginAdmin(): void
    {
        $this->session(['Auth' => ['id' => 1, 'email' => 'admin@example.com', 'created' => new DateTime('2026-01-01')]]);
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

    /**
     * @param int $type Game type.
     * @param string $endedAt End.
     * @param int|null $position Place, null when not listed.
     * @param int|null $points Points.
     * @return void
     */
    private function clanResult(int $type, string $endedAt, ?int $position, ?int $points): void
    {
        $results = $this->fetchTable('ClanTournamentResults');
        $result = $results->newEmptyEntity();
        $result->set([
            'entry_uid' => uniqid('entry-', true),
            'kind' => 'multistage_tournament_results_entry',
            'game_type' => $type,
            'game_tournament_key' => $type . ':1',
            'ended_at' => new DateTime($endedAt),
            'position' => $position,
            'points' => $points,
            'clans_listed' => $position === null ? 100 : 3,
            'rewards' => json_encode($position === null ? [] : [['item_id' => 31, 'name' => 'Dragon Coins', 'amount' => 4000000]]),
        ], ['guard' => false]);
        $results->saveOrFail($result);
    }

    public function testWithClanDataOffOnlyAdministratorsSeeIt(): void
    {
        $this->clanData('0');
        $this->get('/members/tournaments');
        $this->assertResponseCode(404);

        $this->loginAdmin();
        $this->get('/members/tournaments');
        $this->assertResponseOk();
        $this->assertResponseContains('No clan result yet');
    }

    public function testThePlaceAndPointsOfTheTournamentChosenAreDrawn(): void
    {
        $this->clanData('1');
        $catalogue = $this->fetchTable('GameTournaments');
        $olympus = $catalogue->touchType(1032, '1', null, 'multistage_tournament_clan_statistics_entry');
        $catalogue->offerName($olympus, 'Trials of Olympus', GameTournament::SOURCE_JOURNAL);
        $ancients = $catalogue->touchType(1024, '1');
        $catalogue->offerName($ancients, 'Rise of the Ancients', GameTournament::SOURCE_JOURNAL);
        $catalogue->updateAll(['display_name' => 'Olimpo'], ['id' => $olympus->id]);

        $this->clanResult(1032, '2026-10-11 18:00:51', 2, 27198760);
        $this->clanResult(1032, '2026-11-06 18:00:19', 1, 43043175);
        $this->clanResult(1024, '2026-11-05 17:00:41', null, null);

        // Open to everyone, signed in or not.
        $this->get('/members/tournaments');
        $this->assertResponseOk();
        $this->assertResponseContains('Olimpo (2)', 'the tournament ranked most often is drawn first');
        $this->assertResponseContains('clanPlaceChart');
        $this->assertResponseContains('clanPointsChart');
        $this->assertResponseContains('43,043,175', 'the points of the clan');
        $this->assertResponseContains('Not in the top 100');
        $this->assertResponseContains('Dragon Coins x4,000,000');

        $data = $this->chartData();
        $this->assertSame([2, 1], array_column($data['points'], 'position'), 'oldest first');
        $this->assertSame([27198760, 43043175], array_column($data['points'], 'points'));

        $this->get('/members/tournaments?type=1024');
        $this->assertResponseOk();
        $this->assertResponseContains('The clan was never among the clans listed in this tournament.');
        $this->assertResponseNotContains('clanPlaceChart');
    }

    /**
     * @return array<string, mixed>
     */
    private function chartData(): array
    {
        $body = (string)$this->_response->getBody();
        preg_match('#<script type="application/json" id="clanHistoryData">(.*?)</script>#s', $body, $match);

        return json_decode($match[1] ?? '{}', true) ?? [];
    }
}
