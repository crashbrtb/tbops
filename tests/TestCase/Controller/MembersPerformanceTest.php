<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * @uses \App\Controller\MembersController::performance()
 * @uses \App\Service\MemberPerformanceService
 */
class MembersPerformanceTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Users',
        'app.Roles',
        'app.RolesUsers',
        'app.Config',
        'app.Members',
        'app.Events',
        'app.EventStandings',
        'app.PlayerCycleSummaries',
    ];

    private function loginAdmin(): void
    {
        $this->session(['Auth' => ['id' => 1, 'email' => 'admin@example.com', 'created' => new DateTime('2026-01-01')]]);
    }

    public function testPerformanceRequiresAdmin(): void
    {
        $this->get('/members/performance');
        $this->assertResponseCode(302);
    }

    public function testPerformanceRendersForAdmin(): void
    {
        $this->loginAdmin();
        $this->get('/members/performance');
        $this->assertResponseOk();
        $this->assertResponseContains('performanceRankingTable');
        $this->assertResponseContains('eventPerformanceChart');
        $this->assertResponseContains('chestPerformanceChart');
        $this->assertResponseContains('playerHistoryModal');
    }

    public function testPerformanceAcceptsPeriod(): void
    {
        $this->loginAdmin();
        $this->get('/members/performance?period=60');
        $this->assertResponseOk();
        $this->assertResponseContains('periodSelect');
    }
}

