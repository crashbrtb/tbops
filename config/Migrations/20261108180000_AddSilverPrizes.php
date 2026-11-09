<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Prizes the tournament sets itself, Silver among them.
 *
 * - game_tournaments.extra_prizes: JSON list of the prizes every new event of
 *   the tournament hands out beside the clan's: Silver (in millions, as the
 *   bank keeps it) or any item, each with its amount and split rule.
 * - event_rewards.is_silver: the line is Silver, credited to the players'
 *   accounts in the clan bank; event_rewards.from_catalogue: the line was
 *   written from the tournament's extra prizes, so it follows them.
 * - event_payouts: the Silver each player of a published event is due, waiting
 *   for an administrator to credit it (or waive it), then credited.
 */
class AddSilverPrizes extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        $catalogue = $this->table('game_tournaments');
        if (!$catalogue->hasColumn('extra_prizes')) {
            $catalogue->addColumn('extra_prizes', 'text', [
                'null' => true,
                'default' => null,
                'after' => 'prize_items',
                'comment' => 'JSON list of {silver, name, quantity, rule, min_points, remainder}: prizes set by the clan',
            ])->update();
        }

        $rewards = $this->table('event_rewards');
        if (!$rewards->hasColumn('is_silver')) {
            $rewards->addColumn('is_silver', 'boolean', [
                'null' => false,
                'default' => false,
                'after' => 'quantity',
                'comment' => 'Silver in millions, credited to the players in the clan bank',
            ])->addColumn('from_catalogue', 'boolean', [
                'null' => false,
                'default' => false,
                'after' => 'is_silver',
                'comment' => 'Written from the tournament\'s extra prizes',
            ])->update();
        }

        if ($this->hasTable('event_payouts')) {
            return;
        }
        $this->table('event_payouts', ['encoding' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci'])
            ->addColumn('event_id', 'integer', ['null' => false])
            ->addColumn('member_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('player', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('amount', 'biginteger', ['signed' => false, 'null' => false, 'comment' => 'Millions of Silver'])
            ->addColumn('status', 'string', ['limit' => 12, 'null' => false, 'default' => 'pending',
                'comment' => 'pending, waived, credited or reversed'])
            ->addColumn('note', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('bank_transaction_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('user_id', 'integer', ['null' => true, 'default' => null,
                'comment' => 'The administrator who last credited, waived or reversed it'])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['event_id', 'player'], ['unique' => true, 'name' => 'event_payouts_event_player_unique'])
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
        if ($this->hasTable('event_payouts')) {
            $this->table('event_payouts')->drop()->save();
        }
        $rewards = $this->table('event_rewards');
        if ($rewards->hasColumn('is_silver')) {
            $rewards->removeColumn('is_silver')->removeColumn('from_catalogue')->update();
        }
        $catalogue = $this->table('game_tournaments');
        if ($catalogue->hasColumn('extra_prizes')) {
            $catalogue->removeColumn('extra_prizes')->update();
        }
    }
}
