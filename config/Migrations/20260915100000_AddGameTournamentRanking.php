<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * One catalogue entry per ranking of a tournament, not per tournament type.
 *
 * Most tournaments have one ranking. Some have several with different goals
 * and different points: the Dark Omens (type 1033) reports the clanmates'
 * damage in battles and, separately, their contributions of Omen Essence. Each
 * needs its own name, duration, image and rewards.
 *
 * - `game_tournaments.ranking`  which ranking of the type the entry is, as the
 *                               game names it (the Journal message kind, plus the
 *                               statistic when it has one). Empty for the classic
 *                               tournament result, so existing entries keep meaning
 *                               what they meant.
 * - the unique index moves from `game_type` to `game_type` + `ranking`.
 *
 * Column comments on altered tables carry no commas or semicolons (see CreateEventImports).
 */
class AddGameTournamentRanking extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('game_tournaments');
        if (!$table->hasColumn('ranking')) {
            $table
                ->addColumn('ranking', 'string', [
                    'limit' => 120,
                    'null' => false,
                    'default' => '',
                    'after' => 'game_type',
                    'comment' => 'Which ranking of the type - empty for the classic tournament result',
                ])
                ->update();
        }

        $table = $this->table('game_tournaments');
        if ($table->hasIndexByName('game_tournaments_type_unique')) {
            $table->removeIndexByName('game_tournaments_type_unique')->update();
        }
        if (!$table->hasIndexByName('game_tournaments_type_ranking_unique')) {
            $table->addIndex(['game_type', 'ranking'], ['unique' => true, 'name' => 'game_tournaments_type_ranking_unique'])->update();
        }
    }

    /**
     * Down Method. Fails while a type has more than one ranking in the catalogue.
     *
     * @return void
     */
    public function down(): void
    {
        $table = $this->table('game_tournaments');
        if ($table->hasIndexByName('game_tournaments_type_ranking_unique')) {
            $table->removeIndexByName('game_tournaments_type_ranking_unique')->update();
        }
        if (!$table->hasIndexByName('game_tournaments_type_unique')) {
            $table->addIndex(['game_type'], ['unique' => true, 'name' => 'game_tournaments_type_unique'])->update();
        }
        if ($table->hasColumn('ranking')) {
            $table->removeColumn('ranking')->update();
        }
    }
}
