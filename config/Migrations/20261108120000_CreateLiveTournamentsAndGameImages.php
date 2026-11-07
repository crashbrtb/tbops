<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * The game's Tournaments window on the site, and the game's own images.
 *
 * - live_tournaments: what runs now and what is about to start, as the collector
 *   read it from the game: dates, the clan's points and place, the best clans,
 *   the clan's players and the rewards of each place. Replaced on every reading.
 * - game_images: the game's images (banners, icons), as PNG, by their name in the
 *   game's Unity CDN, with the id of the CDN file they came from: a new file id
 *   means the game changed the image and it is replaced.
 * - game_items: the game's items (name and icon), for the prizes.
 * - game_tournaments: the banner and icon of the tournament in the game.
 *
 * Column comments carry no commas or semicolons (see CreateEventImports).
 */
class CreateLiveTournamentsAndGameImages extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        if (!$this->hasTable('live_tournaments')) {
            $this->table('live_tournaments')
                ->addColumn('game_key', 'string', ['limit' => 32, 'null' => false, 'comment' => 'Type and variant as type:variant'])
                ->addColumn('game_type', 'integer', ['null' => false])
                ->addColumn('variant', 'integer', ['null' => false, 'default' => 1])
                ->addColumn('run', 'integer', ['null' => false, 'default' => 0, 'comment' => 'Edition number of the game'])
                ->addColumn('state', 'string', ['limit' => 10, 'null' => false, 'comment' => 'active or upcoming'])
                ->addColumn('starts_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('ends_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('name', 'string', ['limit' => 120, 'null' => false, 'default' => ''])
                ->addColumn('banner', 'string', ['limit' => 120, 'null' => true, 'default' => null])
                ->addColumn('icon', 'string', ['limit' => 120, 'null' => true, 'default' => null])
                ->addColumn('clan_points', 'biginteger', ['null' => true, 'default' => null, 'signed' => false])
                ->addColumn('clan_place', 'integer', ['null' => true, 'default' => null])
                ->addColumn('top_clans', 'text', ['null' => true, 'default' => null, 'comment' => 'JSON'])
                ->addColumn('members', 'text', ['null' => true, 'default' => null, 'limit' => 16777215, 'comment' => 'JSON'])
                ->addColumn('rewards', 'text', ['null' => true, 'default' => null, 'comment' => 'JSON'])
                ->addColumn('read_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['game_key'], ['unique' => true])
                ->create();
        }
        if (!$this->hasTable('game_images')) {
            $this->table('game_images', ['id' => false, 'primary_key' => ['name']])
                ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
                ->addColumn('file_id', 'string', ['limit' => 64, 'null' => false, 'comment' => 'File of the CDN the image came from'])
                ->addColumn('mime', 'string', ['limit' => 40, 'null' => false, 'default' => 'image/png'])
                ->addColumn('data', 'blob', ['null' => false, 'limit' => 16777215])
                ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
                ->create();
        }
        if (!$this->hasTable('game_items')) {
            $this->table('game_items', ['id' => false, 'primary_key' => ['item_id']])
                ->addColumn('item_id', 'biginteger', ['null' => false, 'signed' => false])
                ->addColumn('name', 'string', ['limit' => 120, 'null' => false, 'default' => ''])
                ->addColumn('icon', 'string', ['limit' => 120, 'null' => true, 'default' => null])
                ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
                ->create();
        }
        $catalogue = $this->table('game_tournaments');
        if (!$catalogue->hasColumn('game_icon')) {
            $catalogue
                ->addColumn('game_icon', 'string', ['limit' => 120, 'null' => true, 'default' => null, 'comment' => 'Icon of the tournament in the game'])
                ->addColumn('game_banner', 'string', ['limit' => 120, 'null' => true, 'default' => null, 'comment' => 'Banner of the tournament in the game'])
                ->update();
        }
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        $catalogue = $this->table('game_tournaments');
        if ($catalogue->hasColumn('game_icon')) {
            $catalogue->removeColumn('game_banner')->removeColumn('game_icon')->update();
        }
        foreach (['game_items', 'game_images', 'live_tournaments'] as $name) {
            if ($this->hasTable($name)) {
                $this->table($name)->drop()->save();
            }
        }
    }
}
