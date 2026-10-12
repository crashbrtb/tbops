<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\AdminNotification;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * @uses \App\Controller\NotificationsController
 */
class NotificationsControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Config',
        'app.Users',
        'app.Roles',
        // User 1 holds role 1, which is what requireAdmin() looks for.
        'app.RolesUsers',
        'app.Members',
        'app.AdminNotifications',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();
    }

    /**
     * @param int $id User id.
     * @return void
     */
    private function signIn(int $id): void
    {
        $this->session([
            'Auth' => [
                'id' => $id,
                'username' => 'tester',
                'email' => 'tester@example.com',
                'created' => new DateTime('2026-01-01 00:00:00'),
            ],
        ]);
    }

    /**
     * @param string $a One name.
     * @param string $b The other.
     * @return int Notification id.
     */
    private function raise(string $a = 'ALOK BR', string $b = 'Alok BR'): int
    {
        $notifications = $this->fetchTable('AdminNotifications');
        $notifications->raise(AdminNotification::TYPE_DUPLICATE_PLAYER_NAME, "test:{$a}-{$b}", [
            'members' => [['id' => 4, 'player' => $a], ['id' => 80, 'player' => $b]],
        ]);

        return (int)$notifications->find()->orderBy(['id' => 'DESC'])->firstOrFail()->id;
    }

    public function testPageIsClosedToNonAdministrators(): void
    {
        $this->signIn(2);

        $this->get('/notifications');

        $this->assertResponseCode(403);
    }

    public function testUnreadAlertShowsInTheUserMenuAndInTheHistory(): void
    {
        $this->raise();
        $this->signIn(1);

        $this->get('/notifications');

        $this->assertResponseOk();
        $this->assertResponseContains('admin-notifications-mark');
        $this->assertResponseContains('Possible duplicate player: &quot;ALOK BR&quot; and &quot;Alok BR&quot;');
        $this->assertResponseContains('Unread');
    }

    public function testMarkingAsReadTakesItOffTheMenuAndKeepsTheHistory(): void
    {
        $id = $this->raise();
        $this->signIn(1);

        $this->post('/notifications/mark-read/' . $id);

        $this->assertRedirect();
        $notification = $this->fetchTable('AdminNotifications')->get($id);
        $this->assertNotNull($notification->read_at);
        $this->assertSame(1, $notification->read_by);

        $this->get('/notifications');
        $this->assertResponseNotContains('class="admin-notifications-mark"');
        $this->assertResponseContains('Possible duplicate player');
        $this->assertResponseContains('Read by');
    }

    public function testMarkAllAsRead(): void
    {
        $this->raise();
        $this->raise('Cash BR', 'Crash BR');
        $this->signIn(1);

        $this->post('/notifications/mark-all-read');

        $this->assertRedirect();
        $this->assertSame(0, $this->fetchTable('AdminNotifications')->find('unread')->count());
    }

    public function testMarkingAsReadIsClosedToNonAdministrators(): void
    {
        $id = $this->raise();
        $this->signIn(2);

        $this->post('/notifications/mark-read/' . $id);

        $this->assertResponseCode(403);
        $this->assertNull($this->fetchTable('AdminNotifications')->get($id)->read_at);
    }

    public function testCheckNowRaisesWhatIsThere(): void
    {
        $members = $this->fetchTable('Members');
        foreach (['ALOK BR', 'Alok BR'] as $player) {
            $members->saveOrFail($members->newEntity([
                'player' => $player, 'active' => 1, 'power' => 0,
                'guards' => 0, 'specialists' => 0, 'monsters' => 0, 'engineers' => 0,
            ]));
        }
        $this->signIn(1);

        $this->post('/notifications/check');

        $this->assertRedirect(['controller' => 'Notifications', 'action' => 'index']);
        $this->assertSame(1, $this->fetchTable('AdminNotifications')->find('unread')->count());
    }
}
