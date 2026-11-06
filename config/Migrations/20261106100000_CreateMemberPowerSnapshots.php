<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * The power of every member, one reading a day, so the growth of the clan and
 * of each player can be followed.
 *
 * - member_power_snapshots: one row per member and day. Written each time the
 *   game's list of the clan is applied (the collector's Members list or a
 *   tournament ranking); a later reading of the same day replaces the earlier.
 *
 * The tournaments already published carry each player's power at their end,
 * so the history is started from them.
 */
class CreateMemberPowerSnapshots extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        if ($this->hasTable('member_power_snapshots')) {
            return;
        }

        $this->table('member_power_snapshots', ['encoding' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci'])
            ->addColumn('member_id', 'integer', ['null' => false])
            ->addColumn('game_player_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
            ->addColumn('snapshot_date', 'date', ['null' => false])
            ->addColumn('power', 'biginteger', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('source', 'string', [
                'limit' => 16,
                'null' => false,
                'comment' => 'member_list | tournament',
            ])
            ->addColumn('recorded_at', 'datetime', ['null' => false, 'comment' => 'UTC: when the game was read'])
            ->addIndex(['member_id', 'snapshot_date'], [
                'unique' => true,
                'name' => 'member_power_snapshots_member_day',
            ])
            ->addIndex(['snapshot_date'], ['name' => 'member_power_snapshots_day'])
            ->addForeignKey('member_id', 'members', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->backfillFromTournaments();
    }

    /**
     * One reading per member and day from the published tournaments; of two
     * tournaments that ended the same day, the later one.
     *
     * @return void
     */
    private function backfillFromTournaments(): void
    {
        if (!$this->hasTable('event_standings') || !$this->table('event_standings')->hasColumn('power')) {
            return;
        }

        $rows = $this->fetchAll(
            'SELECT s.member_id, s.game_player_id, s.power, e.ends_at
            FROM event_standings s
            INNER JOIN events e ON e.id = s.event_id
            INNER JOIN members m ON m.id = s.member_id
            WHERE e.published_at IS NOT NULL AND s.member_id IS NOT NULL AND s.power IS NOT NULL AND s.power > 0
            ORDER BY e.ends_at ASC, s.id ASC'
        );

        $byDay = [];
        foreach ($rows as $row) {
            $endsAt = (string)$row['ends_at'];
            $byDay[$row['member_id'] . '|' . substr($endsAt, 0, 10)] = [
                'member_id' => (int)$row['member_id'],
                'game_player_id' => $row['game_player_id'] !== null ? (int)$row['game_player_id'] : null,
                'snapshot_date' => substr($endsAt, 0, 10),
                'power' => (int)$row['power'],
                'source' => 'tournament',
                'recorded_at' => substr($endsAt, 0, 19),
            ];
        }

        foreach (array_chunk(array_values($byDay), 500) as $chunk) {
            $this->table('member_power_snapshots')->insert($chunk)->saveData();
        }
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        if ($this->hasTable('member_power_snapshots')) {
            $this->table('member_power_snapshots')->drop()->save();
        }
    }
}
