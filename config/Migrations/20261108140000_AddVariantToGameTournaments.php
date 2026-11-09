<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * One catalogue entry per tournament, not per game type.
 *
 * The game runs several tournaments under one type, told apart by the variant:
 * 1007 is Doomsday (1) and Arachne's Swarm (14), 1015 Gold Rush (30) and Silver
 * Rush (22). The entry now carries the variant it was made for (0: any), and the
 * key is type, variant and ranking. The existing entries take the variant they
 * were last seen with, which is the one of their name.
 */
class AddVariantToGameTournaments extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('game_tournaments');
        if (!$table->hasColumn('variant')) {
            $table->addColumn('variant', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'after' => 'game_type',
                'comment' => 'The variant of the game type this entry is (1007:14 Arachne\'s Swarm); 0 = any',
            ])->update();
            foreach ($this->fetchAll('SELECT id, last_variant FROM game_tournaments') as $row) {
                $variant = (string)($row['last_variant'] ?? '');
                if ($variant !== '' && ctype_digit($variant)) {
                    $this->execute(sprintf('UPDATE game_tournaments SET variant = %d WHERE id = %d', (int)$variant, (int)$row['id']));
                }
            }
        }
        $table = $this->table('game_tournaments');
        if ($table->hasIndexByName('game_tournaments_type_ranking_unique')) {
            $table->removeIndexByName('game_tournaments_type_ranking_unique')->update();
        }
        if (!$table->hasIndexByName('game_tournaments_type_variant_ranking_unique')) {
            $table->addIndex(['game_type', 'variant', 'ranking'], ['unique' => true, 'name' => 'game_tournaments_type_variant_ranking_unique'])->update();
        }
    }

    /**
     * Down Method: only possible while each type has one entry per ranking.
     *
     * @return void
     */
    public function down(): void
    {
        $table = $this->table('game_tournaments');
        if ($table->hasIndexByName('game_tournaments_type_variant_ranking_unique')) {
            $table->removeIndexByName('game_tournaments_type_variant_ranking_unique')->update();
        }
        if (!$table->hasIndexByName('game_tournaments_type_ranking_unique')) {
            $table->addIndex(['game_type', 'ranking'], ['unique' => true, 'name' => 'game_tournaments_type_ranking_unique'])->update();
        }
        if ($table->hasColumn('variant')) {
            $table->removeColumn('variant')->update();
        }
    }
}
