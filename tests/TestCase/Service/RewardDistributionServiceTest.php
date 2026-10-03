<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Model\Entity\EventReward;
use App\Service\RewardDistributionService;
use Cake\TestSuite\TestCase;

/**
 * @uses \App\Service\RewardDistributionService
 */
class RewardDistributionServiceTest extends TestCase
{
    protected RewardDistributionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RewardDistributionService();
    }

    /**
     * @param list<array{0: int, 1: bool}> $rows [points, eligible] best placed first.
     * @return list<array{key: string, position: int, points: int, eligible: bool}>
     */
    private function ranking(array $rows): array
    {
        $players = [];
        foreach ($rows as $i => [$points, $eligible]) {
            $players[] = ['key' => 'p' . ($i + 1), 'position' => $i + 1, 'points' => $points, 'eligible' => $eligible];
        }

        return $players;
    }

    public function testEqualSplitGivesTheLeftoverToTheBestPlaced(): void
    {
        $players = $this->ranking([[900, true], [500, true], [100, true]]);

        $result = $this->service->split($players, [
            'quantity' => 100, 'rule' => EventReward::RULE_EQUAL, 'remainder' => EventReward::REMAINDER_TOP_RANKED,
        ]);

        $this->assertSame(['p1' => 34, 'p2' => 33, 'p3' => 33], $result['amounts']);
        $this->assertSame(100, $result['distributed']);
        $this->assertSame(0, $result['leftover']);
        $this->assertSame(3, $result['recipients']);
    }

    public function testEqualSplitCanKeepTheLeftover(): void
    {
        $players = $this->ranking([[900, true], [500, true], [100, true]]);

        $result = $this->service->split($players, [
            'quantity' => 100, 'rule' => EventReward::RULE_EQUAL, 'remainder' => EventReward::REMAINDER_KEEP,
        ]);

        $this->assertSame(['p1' => 33, 'p2' => 33, 'p3' => 33], $result['amounts']);
        $this->assertSame(1, $result['leftover']);
    }

    public function testAdministrativeAccountsTakeNoPartAndDoNotDiluteTheTotal(): void
    {
        // p2 is an administrative account with the most points.
        $players = $this->ranking([[300, true], [5000, false], [100, true]]);

        $result = $this->service->split($players, ['quantity' => 400, 'rule' => EventReward::RULE_PROPORTIONAL]);

        $this->assertSame(['p1' => 300, 'p2' => 0, 'p3' => 100], $result['amounts']);
        $this->assertSame(400, $result['pool_points']);

        $shares = $this->service->participation($players);
        $this->assertSame(75.0, $shares['p1']);
        $this->assertSame(0.0, $shares['p2']);
    }

    public function testMinimumPointsExcludesPlayersBelowIt(): void
    {
        $players = $this->ranking([[1000, true], [10, true], [0, true]]);

        $equal = $this->service->split($players, ['quantity' => 10, 'rule' => EventReward::RULE_EQUAL, 'min_points' => 1]);
        $this->assertSame(['p1' => 5, 'p2' => 5, 'p3' => 0], $equal['amounts']);

        $strict = $this->service->split($players, ['quantity' => 10, 'rule' => EventReward::RULE_EQUAL, 'min_points' => 100]);
        $this->assertSame(['p1' => 10, 'p2' => 0, 'p3' => 0], $strict['amounts']);

        $everyone = $this->service->split($players, ['quantity' => 9, 'rule' => EventReward::RULE_EQUAL, 'min_points' => 0]);
        $this->assertSame(['p1' => 3, 'p2' => 3, 'p3' => 3], $everyone['amounts']);
    }

    public function testProportionalUsesLargestRemainderAndAlwaysAddsUp(): void
    {
        // Exact shares 33.33 / 33.33 / 33.33: two units go by points, then position.
        $players = $this->ranking([[10, true], [10, true], [10, true]]);
        $result = $this->service->split($players, ['quantity' => 100, 'rule' => EventReward::RULE_PROPORTIONAL]);
        $this->assertSame(['p1' => 34, 'p2' => 33, 'p3' => 33], $result['amounts']);

        // 7 * 5/9 = 3.89, 7 * 3/9 = 2.33, 7 * 1/9 = 0.78: floors 3+2+0, leftovers by fraction.
        $players = $this->ranking([[5, true], [3, true], [1, true]]);
        $result = $this->service->split($players, ['quantity' => 7, 'rule' => EventReward::RULE_PROPORTIONAL]);
        $this->assertSame(['p1' => 4, 'p2' => 2, 'p3' => 1], $result['amounts']);
        $this->assertSame(7, $result['distributed']);
    }

    public function testProportionalCanKeepTheLeftover(): void
    {
        $players = $this->ranking([[5, true], [3, true], [1, true]]);

        $result = $this->service->split($players, [
            'quantity' => 7, 'rule' => EventReward::RULE_PROPORTIONAL, 'remainder' => EventReward::REMAINDER_KEEP,
        ]);

        $this->assertSame(['p1' => 3, 'p2' => 2, 'p3' => 0], $result['amounts']);
        $this->assertSame(2, $result['leftover']);
    }

    public function testNothingIsHandedOutWhenNobodyQualifies(): void
    {
        $players = $this->ranking([[500, false], [0, true]]);

        $equal = $this->service->split($players, ['quantity' => 50, 'rule' => EventReward::RULE_EQUAL]);
        $this->assertSame(0, $equal['distributed']);
        $this->assertSame(50, $equal['leftover']);

        // Eligible players who all scored 0 have no proportion to follow.
        $zero = $this->service->split($players, ['quantity' => 50, 'rule' => EventReward::RULE_PROPORTIONAL, 'min_points' => 0]);
        $this->assertSame(0, $zero['distributed']);
        $this->assertSame(50, $zero['leftover']);
    }

    public function testRealTournamentScaleStaysExact(): void
    {
        // 98 players with billions of points, like the first real upload, and a
        // quantity large enough that quantity * points overflows 64 bits.
        $rows = [];
        for ($i = 0; $i < 98; $i++) {
            $rows[] = [$i >= 94 ? 0 : 1_703_103_642 - $i * 13_579_111, $i !== 10];
        }
        $players = $this->ranking($rows);

        foreach ([1, 97, 12_345, 999_999_937] as $quantity) {
            foreach ([EventReward::RULE_PROPORTIONAL, EventReward::RULE_EQUAL] as $rule) {
                $result = $this->service->split($players, ['quantity' => $quantity, 'rule' => $rule]);
                $this->assertSame($quantity, array_sum($result['amounts']), "{$rule} {$quantity}");
                $this->assertSame(0, $result['amounts']['p11'], 'the ineligible player never receives');
                $this->assertSame(0, $result['amounts']['p98'], 'zero points is below the default minimum');
            }
        }

        $result = $this->service->split($players, ['quantity' => 999_999_937, 'rule' => EventReward::RULE_PROPORTIONAL]);
        $amounts = array_values(array_filter($result['amounts'], fn (int $a): bool => $a > 0));
        $sorted = $amounts;
        rsort($sorted);
        $this->assertSame($sorted, $amounts, 'more points never means a smaller part');
    }

    public function testDistributeSplitsEveryRewardOverTheSameRanking(): void
    {
        $players = $this->ranking([[300, true], [100, true]]);

        $result = $this->service->distribute($players, [
            7 => ['quantity' => 40, 'rule' => EventReward::RULE_PROPORTIONAL],
            8 => ['quantity' => 10, 'rule' => EventReward::RULE_EQUAL],
        ]);

        $this->assertSame(['p1' => 30, 'p2' => 10], $result['rewards'][7]['amounts']);
        $this->assertSame(['p1' => 5, 'p2' => 5], $result['rewards'][8]['amounts']);
        $this->assertSame(400, $result['eligible_points']);
        $this->assertSame(['p1' => 75.0, 'p2' => 25.0], $result['participation']);
    }

    public function testByPositionGivesEachPlaceItsAmount(): void
    {
        $players = $this->ranking([[900, true], [500, true], [100, true], [50, true]]);

        $result = $this->service->split($players, [
            'quantity' => 850, 'rule' => EventReward::RULE_POSITION, 'positions' => [500, 250, 100],
        ]);

        $this->assertSame(['p1' => 500, 'p2' => 250, 'p3' => 100, 'p4' => 0], $result['amounts']);
        $this->assertSame(850, $result['distributed']);
        $this->assertSame(0, $result['leftover']);
    }

    public function testByPositionCountsPlacesAmongRecipientsOnly(): void
    {
        // The best placed is an administrative account: first place goes to the next.
        $players = $this->ranking([[900, false], [500, true], [100, true]]);

        $result = $this->service->split($players, [
            'quantity' => 750, 'rule' => EventReward::RULE_POSITION, 'positions' => [500, 250],
        ]);

        $this->assertSame(['p1' => 0, 'p2' => 500, 'p3' => 250], $result['amounts']);
    }

    public function testByPositionKeepsThePlacesNobodyFills(): void
    {
        $players = $this->ranking([[900, true]]);

        $result = $this->service->split($players, [
            'quantity' => 850, 'rule' => EventReward::RULE_POSITION, 'positions' => [500, 250, 100],
        ]);

        $this->assertSame(['p1' => 500], $result['amounts']);
        $this->assertSame(350, $result['leftover']);
    }

    public function testPlayersWhoAreNotQualifiedTakeNoPart(): void
    {
        // p1 missed a required goal: proportional and position rewards skip them.
        $players = $this->ranking([[900, true], [300, true], [100, true]]);
        $players[0]['qualified'] = false;

        $proportional = $this->service->split($players, ['quantity' => 40, 'rule' => EventReward::RULE_PROPORTIONAL]);
        $this->assertSame(['p1' => 0, 'p2' => 30, 'p3' => 10], $proportional['amounts']);
        $this->assertSame(2, $proportional['recipients']);

        $byPosition = $this->service->split($players, [
            'quantity' => 15, 'rule' => EventReward::RULE_POSITION, 'positions' => [10, 5],
        ]);
        $this->assertSame(['p1' => 0, 'p2' => 10, 'p3' => 5], $byPosition['amounts']);
    }
}
