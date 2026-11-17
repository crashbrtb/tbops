<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Table\BankTransactionsTable;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The bankers role runs the clan bank and nothing else of the administration.
 */
class BankerRoleTest extends TestCase
{
    use IntegrationTestTrait;

    private const ADMIN = 1;
    private const PLAYER = 2;
    private const BANKER = 3;

    protected array $fixtures = [
        'app.Users',
        'app.Roles',
        'app.RolesUsers',
        'app.Config',
        'app.Members',
        'app.BankAccounts',
        'app.BankTransactions',
        'app.BankApprovalLogs',
        'app.EventFines',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();

        $users = $this->fetchTable('Users');
        foreach ([self::PLAYER => 'player', self::BANKER => 'banker'] as $id => $name) {
            $users->getConnection()->insert('users', [
                'id' => $id,
                'name' => $name,
                'email' => $name . '@example.com',
                'password' => 'x',
                'created' => '2026-01-01 00:00:00',
                'modified' => '2026-01-01 00:00:00',
            ]);
        }
        $connection = $users->getConnection();
        $connection->insert('roles', ['id' => 2, 'name' => 'user', 'description' => 'Users', 'alias' => 'user', 'created' => '2026-01-01 00:00:00', 'modified' => '2026-01-01 00:00:00']);
        $connection->insert('roles', ['id' => 3, 'name' => 'bankers', 'description' => 'Bank', 'alias' => 'bankers', 'created' => '2026-01-01 00:00:00', 'modified' => '2026-01-01 00:00:00']);
        $connection->insert('roles_users', ['user_id' => self::PLAYER, 'role_id' => 2]);
        $connection->insert('roles_users', ['user_id' => self::BANKER, 'role_id' => 3]);

        $connection->delete('config', []);
        foreach (['bank_function' => '1', 'deposit_fee' => '50', 'withdrawal_fee' => '50', 'transfer_fee' => '10', 'caravan_fee' => '20'] as $param => $value) {
            $connection->insert('config', ['param' => $param, 'value' => $value, 'description' => $param]);
        }

        $connection->delete('bank_approval_logs', []);
        $connection->delete('bank_transactions', []);
        $connection->update('members', ['user_id' => self::PLAYER], ['id' => 1]);
    }

    private function signIn(int $userId): void
    {
        $this->session(['Auth' => ['id' => $userId, 'email' => "user{$userId}@example.com", 'created' => new DateTime('2026-01-01')]]);
    }

    private function pendingDeposit(): int
    {
        $transactions = $this->fetchTable('BankTransactions');
        $transaction = $transactions->newEntity([
            'member_id' => 1,
            'user_id' => self::PLAYER,
            'type' => BankTransactionsTable::TYPE_DEPOSIT,
            'amount' => 1000,
            'fee' => 50,
            'final_amount' => 950,
            'status' => BankTransactionsTable::STATUS_PENDING,
            'description' => 'test',
        ], ['accessibleFields' => ['*' => true]]);
        $transactions->saveOrFail($transaction);

        return (int)$transaction->id;
    }

    public function testBankerSeesAndRunsTheApprovals(): void
    {
        $id = $this->pendingDeposit();

        $this->signIn(self::BANKER);
        $this->get('/bank/approvals');
        $this->assertResponseOk();

        $this->signIn(self::BANKER);
        $this->post("/bank/approve/{$id}");
        $this->assertRedirectContains('/bank/approvals');
        $this->assertSame(
            BankTransactionsTable::STATUS_APPROVED,
            $this->fetchTable('BankTransactions')->get($id)->status
        );
    }

    public function testPlayerCannotRunTheApprovals(): void
    {
        $id = $this->pendingDeposit();

        $this->signIn(self::PLAYER);
        $this->get('/bank/approvals');
        $this->assertResponseCode(403);

        $this->signIn(self::PLAYER);
        $this->post("/bank/approve/{$id}");
        $this->assertResponseCode(403);
        $this->assertSame(
            BankTransactionsTable::STATUS_PENDING,
            $this->fetchTable('BankTransactions')->get($id)->status
        );
    }

    public function testBankerOperatesEveryMemberAccount(): void
    {
        $this->signIn(self::BANKER);
        $this->get('/bank/history/1');
        $this->assertResponseOk();

        // A deposit by the banker is approved at once, like an administrator's.
        $this->signIn(self::BANKER);
        $this->post('/bank/deposit', ['member_id' => 1, 'amount' => '500', 'description' => 'in game']);
        $deposit = $this->fetchTable('BankTransactions')->find()
            ->where(['member_id' => 1, 'type' => BankTransactionsTable::TYPE_DEPOSIT])
            ->orderDesc('id')
            ->firstOrFail();
        $this->assertSame(BankTransactionsTable::STATUS_APPROVED, $deposit->status);
    }

    public function testBankerReachesTheFines(): void
    {
        $this->signIn(self::BANKER);
        $this->get('/event-fines');
        $this->assertResponseOk();
        $this->assertResponseNotContains('/game-tournaments');
    }

    public function testBankerHasNoAdministration(): void
    {
        foreach (['/config', '/members', '/users', '/monitoring', '/game-tournaments', '/api-tokens'] as $url) {
            $this->signIn(self::BANKER);
            $this->get($url);
            $this->assertResponseCode(403, $url);
        }
    }

    public function testMenuShowsTheBankToolsButNotTheAdminMenu(): void
    {
        $this->signIn(self::BANKER);
        $this->get('/bank');
        $this->assertResponseOk();
        $this->assertResponseContains('/bank/approvals');
        $this->assertResponseContains('/event-fines');
        $this->assertResponseNotContains('id="systemDropdownMenuLink"');

        $this->signIn(self::PLAYER);
        $this->get('/bank');
        $this->assertResponseOk();
        $this->assertResponseNotContains('/bank/approvals');
    }
}
