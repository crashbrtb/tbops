<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * The clan's results in the game's tournaments, and publishing decided per tournament.
 *
 * - clan_tournament_results: one row per card of the Journal with the clans'
 *   ranking (the collector sends them): the clan's place, its points and what
 *   the clan received.
 * - game_tournaments: a display name (the game's own name stays as it is), a
 *   description, the clan prizes to split among the players (JSON) and whether
 *   a complete ranking of the tournament is published without review.
 * - event_rewards: game_item_id marks a line filled from the clan's prize.
 *
 * Publishing without review used to be one switch of the collector, on by
 * default. When this site was publishing that way (its newest ranking published
 * from the collector went public in the same request that brought it), every
 * tournament starts with auto_publish on, so nothing changes for the clan.
 *
 * Column comments carry no commas or semicolons (see CreateEventImports).
 */
class AddClanResultsAndTournamentPublishing extends AbstractMigration
{
    /**
     * Seconds between a ranking arriving and being published that still count
     * as published in the same request.
     */
    private const SAME_REQUEST_SECONDS = 120;

    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        if (!$this->hasTable('clan_tournament_results')) {
            $this->table('clan_tournament_results')
                ->addColumn('entry_uid', 'string', ['limit' => 64, 'null' => false, 'comment' => 'Id of the Journal entry'])
                ->addColumn('result_uid', 'string', ['limit' => 64, 'null' => true, 'default' => null])
                ->addColumn('kind', 'string', ['limit' => 80, 'null' => false, 'comment' => 'Kind of the Journal entry'])
                ->addColumn('game_type', 'integer', ['null' => false])
                ->addColumn('game_tournament_key', 'string', ['limit' => 32, 'null' => false])
                ->addColumn('ended_at', 'datetime', ['null' => false])
                ->addColumn('clan_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
                ->addColumn('position', 'integer', ['null' => true, 'default' => null, 'comment' => 'Null when the clan is not among the clans listed'])
                ->addColumn('points', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
                ->addColumn('clans_listed', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('rewards', 'text', ['null' => true, 'default' => null, 'comment' => 'JSON list of item_id and name and amount'])
                ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['entry_uid'], ['unique' => true])
                ->addIndex(['game_type', 'ended_at'])
                ->create();
        }

        $catalogue = $this->table('game_tournaments');
        if (!$catalogue->hasColumn('auto_publish')) {
            $catalogue
                ->addColumn('display_name', 'string', ['limit' => 120, 'null' => true, 'default' => null, 'after' => 'name'])
                ->addColumn('description', 'text', ['null' => true, 'default' => null])
                ->addColumn('prize_items', 'text', ['null' => true, 'default' => null, 'comment' => 'JSON list of the clan prizes split among the players'])
                ->addColumn('auto_publish', 'boolean', ['null' => false, 'default' => false, 'comment' => 'Publish a complete ranking without review'])
                ->update();

            if ($this->wasPublishingOnItsOwn()) {
                $this->execute('UPDATE game_tournaments SET auto_publish = 1');
            }
        }

        $rewards = $this->table('event_rewards');
        if (!$rewards->hasColumn('game_item_id')) {
            $rewards
                ->addColumn('game_item_id', 'biginteger', [
                    'null' => true,
                    'default' => null,
                    'signed' => false,
                    'comment' => 'Line filled from the clan prize of this game item',
                ])
                ->update();
        }
    }

    /**
     * Whether the newest ranking published from the collector was published in
     * the request that brought it, which only the collector's automatic
     * publishing does (a review publishes later).
     *
     * @return bool
     */
    private function wasPublishingOnItsOwn(): bool
    {
        $row = $this->fetchRow(
            'SELECT e.published_at AS published_at, i.created AS created FROM event_imports i '
            . 'INNER JOIN events e ON e.id = i.event_id '
            . "WHERE i.status = 'published' AND i.api_token_id IS NOT NULL AND e.published_at IS NOT NULL "
            . 'ORDER BY e.published_at DESC LIMIT 1'
        );
        if (!$row) {
            return false;
        }
        $published = strtotime((string)$row['published_at']);
        $created = strtotime((string)$row['created']);

        return $published !== false && $created !== false
            && $published - $created >= 0 && $published - $created <= self::SAME_REQUEST_SECONDS;
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        $rewards = $this->table('event_rewards');
        if ($rewards->hasColumn('game_item_id')) {
            $rewards->removeColumn('game_item_id')->update();
        }
        $catalogue = $this->table('game_tournaments');
        if ($catalogue->hasColumn('auto_publish')) {
            $catalogue
                ->removeColumn('auto_publish')
                ->removeColumn('prize_items')
                ->removeColumn('description')
                ->removeColumn('display_name')
                ->update();
        }
        if ($this->hasTable('clan_tournament_results')) {
            $this->table('clan_tournament_results')->drop()->save();
        }
    }
}
