<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Fines for the players who miss a tournament's goal.
 *
 * Each tournament of the catalogue says how a miss is fined: not at all, a fixed
 * amount, or an amount in proportion to how far the player stayed from the goal.
 * When an event's result is published, the fines it calls for are listed in
 * event_fines as pending; an administrator waives whom they choose and charges
 * the rest, which debits each player's account in the clan bank.
 */
class AddTournamentFines extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        $catalogue = $this->table('game_tournaments');
        if (!$catalogue->hasColumn('fine_mode')) {
            $catalogue->addColumn('fine_mode', 'string', [
                'limit' => 12,
                'null' => false,
                'default' => 'none',
                'comment' => 'none, fixed or proportional',
            ])->addColumn('fine_amount', 'biginteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'comment' => 'The fixed fine, or the fine of a player who scored nothing (proportional)',
            ])->update();
        }

        if ($this->hasTable('event_fines')) {
            return;
        }
        $this->table('event_fines', ['encoding' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci'])
            ->addColumn('event_id', 'integer', ['null' => false])
            ->addColumn('member_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('player', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('points', 'biginteger', ['null' => false, 'default' => 0])
            ->addColumn('goal', 'biginteger', ['null' => false, 'default' => 0])
            ->addColumn('amount', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 12, 'null' => false, 'default' => 'pending',
                'comment' => 'pending, waived, charged or refunded'])
            ->addColumn('note', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('bank_transaction_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('user_id', 'integer', ['null' => true, 'default' => null,
                'comment' => 'The administrator who last waived, charged or refunded it'])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['event_id', 'player'], ['unique' => true, 'name' => 'event_fines_event_player_unique'])
            ->addIndex(['member_id'])
            ->addForeignKey('event_id', 'events', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('member_id', 'members', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        if ($this->hasTable('event_fines')) {
            $this->table('event_fines')->drop()->save();
        }
        $catalogue = $this->table('game_tournaments');
        if ($catalogue->hasColumn('fine_mode')) {
            $catalogue->removeColumn('fine_mode')->removeColumn('fine_amount')->update();
        }
    }
}
