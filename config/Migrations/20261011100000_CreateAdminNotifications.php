<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Alerts for the administrators, and the history of every one sent.
 *
 * - admin_notifications: one row per alert. `type` says what it is about and
 *   `data` (JSON) carries what is needed to word it in the reader's language.
 *   `dedupe_key` keeps the same thing from being reported twice - also after it
 *   was read. An alert is unread while `read_at` is empty; `read_by` is empty
 *   when it was closed by the site itself because it no longer applies.
 */
class CreateAdminNotifications extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        if ($this->hasTable('admin_notifications')) {
            return;
        }

        $this->table('admin_notifications', ['encoding' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci'])
            ->addColumn('type', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('dedupe_key', 'string', [
                'limit' => 100,
                'null' => true,
                'default' => null,
                'comment' => 'What the alert is about; empty once it may be raised again',
            ])
            ->addColumn('data', 'text', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('read_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('read_by', 'integer', [
                'null' => true,
                'default' => null,
                'comment' => 'Administrator who marked it as read',
            ])
            ->addIndex(['dedupe_key'], ['unique' => true, 'name' => 'admin_notifications_dedupe_key_unique'])
            ->addIndex(['read_at'], ['name' => 'admin_notifications_read_at'])
            ->create();
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        if ($this->hasTable('admin_notifications')) {
            $this->table('admin_notifications')->drop()->save();
        }
    }
}
