<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\GameTournament;
use App\Model\Entity\LiveTournament;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * The game's Tournaments window, kept on the site as the collector last read it,
 * and the game's own images and items.
 *
 * Every reading replaces the list: a tournament that ended is gone from the
 * running ones (its result arrives with the Journal search, and is published by
 * the rules of its tournament), a new one appears. The images are kept by their
 * name in the game's Unity CDN with the id of the file they came from, so the
 * collector sends again only what the game changed.
 */
class LiveTournamentService
{
    use LocatorAwareTrait;

    public const MAX_TOURNAMENTS = 120;
    public const MAX_IMAGES = 40;
    public const MAX_IMAGE_BYTES = 2097152;

    /**
     * Replace the list with a reading.
     *
     * Body: `{read_at, clan_id, tournaments: [{key, game_type, variant, run, state,
     * starts_at, ends_at, name, banner, icon, clan_points, clan_place, top_clans,
     * members, rewards}], items: [{item_id, name, icon}]}`.
     *
     * @param array<string, mixed> $data Decoded body.
     * @return array{errors: array<string, string>, kept: int, removed: int, items: int}
     */
    public function record(array $data): array
    {
        $list = $data['tournaments'] ?? null;
        if (!is_array($list) || count($list) > self::MAX_TOURNAMENTS) {
            return ['errors' => ['tournaments' => __('Send tournaments as a list of at most {0}.', self::MAX_TOURNAMENTS)], 'kept' => 0, 'removed' => 0, 'items' => 0];
        }
        $readAt = $this->date($data['read_at'] ?? null) ?? DateTime::now();
        $table = $this->fetchTable('LiveTournaments');
        $errors = [];
        $keys = [];
        $removed = 0;
        $table->getConnection()->transactional(function () use ($list, $readAt, $table, &$errors, &$keys, &$removed): void {
            foreach (array_values($list) as $i => $item) {
                $row = is_array($item) ? $this->checkOne($item) : null;
                if ($row === null) {
                    $errors["tournaments.$i"] = __('This tournament is not valid.');
                    continue;
                }
                $keys[] = $row['game_key'];
                $entity = $table->find()->where(['game_key' => $row['game_key']])->first() ?? $table->newEmptyEntity();
                $entity->set($row + ['read_at' => $readAt], ['guard' => false]);
                $table->saveOrFail($entity);
                $this->linkCatalogue($row);
            }
            $gone = $keys === [] ? [] : ['game_key NOT IN' => $keys];
            $removed = $table->deleteAll($gone);
        });

        return ['errors' => $errors, 'kept' => count($keys), 'removed' => $removed, 'items' => $this->recordItems((array)($data['items'] ?? []))];
    }

    /**
     * @param array<string, mixed> $item One tournament.
     * @return array<string, mixed>|null
     */
    private function checkOne(array $item): ?array
    {
        $key = is_string($item['key'] ?? null) ? $item['key'] : '';
        if (!preg_match('/^(\d{1,10}):(\d{1,10})$/', $key, $m)) {
            return null;
        }
        $state = ($item['state'] ?? '') === LiveTournament::STATE_UPCOMING ? LiveTournament::STATE_UPCOMING : LiveTournament::STATE_ACTIVE;
        $whole = fn (mixed $v): ?int => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int)$v : null;
        $asset = fn (mixed $v): ?string => is_string($v) && preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $v) ? $v : null;
        $json = fn (mixed $v): ?string => is_array($v) ? (string)json_encode(array_values($v), JSON_UNESCAPED_UNICODE) : null;

        return [
            'game_key' => $key,
            'game_type' => (int)$m[1],
            'variant' => (int)$m[2],
            'run' => (int)($whole($item['run'] ?? null) ?? 0),
            'state' => $state,
            'starts_at' => $this->date($item['starts_at'] ?? null),
            'ends_at' => $this->date($item['ends_at'] ?? null),
            'name' => mb_substr(trim((string)($item['name'] ?? '')), 0, 120),
            'banner' => $asset($item['banner'] ?? null),
            'icon' => $asset($item['icon'] ?? null),
            'clan_points' => $whole($item['clan_points'] ?? null),
            'clan_place' => $whole($item['clan_place'] ?? null),
            'top_clans' => $json($item['top_clans'] ?? null),
            'members' => $json($item['members'] ?? null),
            'rewards' => $json($item['rewards'] ?? null),
        ];
    }

    /**
     * The catalogue's entries of this tournament take the game's icon and banner.
     *
     * One type of the game can hold several tournaments (1015 is Gold Rush, Silver
     * Rush, Power Points...), so only the entries named as this one take them.
     *
     * @param array<string, mixed> $row Checked tournament.
     * @return void
     */
    private function linkCatalogue(array $row): void
    {
        $fields = array_filter(['game_icon' => $row['icon'], 'game_banner' => $row['banner']]);
        if ($fields === []) {
            return;
        }
        $ids = array_map(fn (GameTournament $e): int => (int)$e->id, $this->entriesOf((int)$row['game_type'], (string)$row['name'], (int)$row['variant']));
        if ($row['state'] === LiveTournament::STATE_ACTIVE && $row['starts_at'] !== null && $row['ends_at'] !== null) {
            // The game's own duration, in whole days (a 5-hour Gold Rush lasts 1).
            $seconds = $row['ends_at']->getTimestamp() - $row['starts_at']->getTimestamp();
            $fields['duration_days'] = max(1, min(\App\Model\Table\GameTournamentsTable::MAX_DURATION_DAYS, (int)round($seconds / 86400)));
        }
        if ($ids !== []) {
            $this->fetchTable('GameTournaments')->updateAll($fields, ['id IN' => $ids]);
        }
    }

    /**
     * The catalogue's entries of a tournament: those of its type named as it is.
     *
     * The game's type is not enough: 1015 holds Gold Rush, Silver Rush, Dawn of the
     * Season..., 1007 Doomsday and Arachne's Swarm, each with its own variant. An entry
     * matches when its name is the tournament's or contains it ("Trials of Olympus
     * tournament", "contributions of Omen Essence in the Dark Omens tournament").
     * An entry made for the tournament's variant matches too. Without a name or a
     * variant, every entry of the type.
     *
     * @param int $gameType Type.
     * @param string $name The game's name of the tournament.
     * @param int $variant The tournament's variant (0: unknown).
     * @return list<\App\Model\Entity\GameTournament>
     */
    public function entriesOf(int $gameType, string $name = '', int $variant = 0): array
    {
        /** @var list<\App\Model\Entity\GameTournament> $entries */
        $entries = $this->fetchTable('GameTournaments')->find('withoutImage')
            ->where(['GameTournaments.game_type' => $gameType])
            ->orderBy(['GameTournaments.ranking' => 'ASC'])
            ->all()
            ->toList();
        if (self::nameKey($name) === '' && $variant <= 0) {
            return $entries;
        }

        return array_values(array_filter(
            $entries,
            fn (GameTournament $e): bool => ($variant > 0 && (int)$e->variant === $variant) || $e->isNamed($name)
        ));
    }

    /**
     * A name reduced to its letters and digits, to compare names written apart
     * ("Ragnarök" and "Ragnarók tournament", "Ancients’ Treasure" and "Ancients' Treasure").
     *
     * @param string $name Name.
     * @return string
     */
    public static function nameKey(string $name): string
    {
        $name = (string)preg_replace('/\s+tournament$/iu', '', trim($name));
        $ascii = function_exists('transliterator_transliterate')
            ? (string)transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name)
            : mb_strtolower($name);

        return (string)preg_replace('/[^a-z0-9]+/', '', strtolower($ascii));
    }

    /**
     * @param list<mixed> $items `[{item_id, name, icon}]`.
     * @return int Kept.
     */
    public function recordItems(array $items): int
    {
        $table = $this->fetchTable('GameItems');
        $kept = 0;
        foreach (array_slice($items, 0, 2000) as $item) {
            if (!is_array($item) || !isset($item['item_id']) || !is_numeric($item['item_id'])) {
                continue;
            }
            $id = (int)$item['item_id'];
            $entity = $table->find()->where(['item_id' => $id])->first() ?? $table->newEmptyEntity();
            $icon = is_string($item['icon'] ?? null) && preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $item['icon']) ? $item['icon'] : null;
            $entity->set(['item_id' => $id, 'name' => mb_substr(trim((string)($item['name'] ?? '')), 0, 120), 'icon' => $icon], ['guard' => false]);
            $table->saveOrFail($entity);
            $kept++;
        }

        return $kept;
    }

    /**
     * Keep the images sent: `[{name, file_id, png}]`, the PNG in base64.
     *
     * @param list<mixed> $images Images.
     * @return array{saved: int, errors: array<string, string>}
     */
    public function recordImages(array $images): array
    {
        $table = $this->fetchTable('GameImages');
        $saved = 0;
        $errors = [];
        foreach (array_slice(array_values($images), 0, self::MAX_IMAGES) as $i => $image) {
            $name = is_array($image) && is_string($image['name'] ?? null) ? $image['name'] : '';
            $file = is_array($image) && is_string($image['file_id'] ?? null) ? $image['file_id'] : '';
            $bytes = is_array($image) && is_string($image['png'] ?? null) ? base64_decode($image['png'], true) : false;
            if (!preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $name) || !preg_match('/^[A-Fa-f0-9]{8,64}$/', $file)
                || $bytes === false || strlen($bytes) > self::MAX_IMAGE_BYTES || !str_starts_with($bytes, "\x89PNG")) {
                $errors["images.$i"] = __('This image is not a valid PNG.');
                continue;
            }
            $entity = $table->find()->where(['name' => $name])->first() ?? $table->newEmptyEntity();
            $entity->set(['name' => $name, 'file_id' => strtolower($file), 'mime' => 'image/png', 'data' => $bytes], ['guard' => false]);
            $table->saveOrFail($entity);
            $this->writeFile($name, $bytes);
            $saved++;
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    /**
     * Where the image is kept as a file, served without PHP: the pages load dozens.
     *
     * @param string $name Image name.
     * @return string
     */
    public static function filePath(string $name): string
    {
        $dir = (string)\Cake\Core\Configure::read('GameImages.dir', WWW_ROOT . 'img' . DS . 'game' . DS);

        return $dir . $name . '.png';
    }

    /**
     * @param string $name Image name.
     * @param string $bytes PNG.
     * @return void
     */
    private function writeFile(string $name, string $bytes): void
    {
        $path = self::filePath($name);
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        @file_put_contents($path, $bytes);
    }

    /**
     * Writes every kept image to its file (after a deploy, or when files were lost).
     *
     * @return int Written.
     */
    public function publishFiles(): int
    {
        $written = 0;
        foreach ($this->fetchTable('GameImages')->find()->all() as $image) {
            $bytes = $image->get('data');
            $bytes = is_resource($bytes) ? (string)stream_get_contents($bytes) : (string)$bytes;
            $this->writeFile((string)$image->get('name'), $bytes);
            $written++;
        }

        return $written;
    }

    /**
     * The items the site shows as prizes and does not know yet (name and icon):
     * from the clan's results and the prizes chosen in the catalogue.
     *
     * @return list<int>
     */
    public function neededItems(): array
    {
        $ids = [];
        foreach ($this->fetchTable('ClanTournamentResults')->find()->select(['rewards'])->all() as $result) {
            foreach ($result->rewardList() as $reward) {
                $ids[$reward['item_id']] = true;
            }
        }
        foreach ($this->fetchTable('GameTournaments')->find('withoutImage')->all() as $entry) {
            foreach ($entry->prizeSelection() as $prize) {
                $ids[$prize['item_id']] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $known = $this->fetchTable('GameItems')->find()->select(['item_id'])->where(['icon IS NOT' => null])
            ->disableHydration()->all()->extract('item_id')->map(fn ($v): int => (int)$v)->toList();

        return array_values(array_diff(array_keys($ids), $known));
    }

    /**
     * The running ones, ending first, and the waiting ones, starting first.
     *
     * @return array{active: list<\App\Model\Entity\LiveTournament>, upcoming: list<\App\Model\Entity\LiveTournament>, read_at: \Cake\I18n\DateTime|null}
     */
    public function current(): array
    {
        $rows = $this->fetchTable('LiveTournaments')->find()->all()->toList();
        $active = array_values(array_filter($rows, fn (LiveTournament $t): bool => $t->isActive()));
        $upcoming = array_values(array_filter($rows, fn (LiveTournament $t): bool => !$t->isActive()));
        usort($active, fn (LiveTournament $a, LiveTournament $b): int => ($a->ends_at?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->ends_at?->getTimestamp() ?? PHP_INT_MAX));
        usort($upcoming, fn (LiveTournament $a, LiveTournament $b): int => ($a->starts_at?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->starts_at?->getTimestamp() ?? PHP_INT_MAX));
        $readAt = null;
        foreach ($rows as $row) {
            if ($row->read_at !== null && ($readAt === null || $row->read_at->greaterThan($readAt))) {
                $readAt = $row->read_at;
            }
        }

        return ['active' => $active, 'upcoming' => $upcoming, 'read_at' => $readAt];
    }

    /**
     * What the clan site says of a tournament type: the catalogue entry (goal and
     * prize policy) and the clan rewards of its latest published event.
     *
     * Only the entries named as the tournament count (see entriesOf): a Gold Rush
     * does not take the goal of the Silver Rush of the same type. `type_known` says
     * whether the type has any entry at all.
     *
     * @param int $gameType Type.
     * @param string $name The game's name of the tournament.
     * @param int $variant The tournament's variant.
     * @return array{tournament: \App\Model\Entity\GameTournament|null, last_event: \App\Model\Entity\Event|null, type_known: bool}
     */
    public function siteInfo(int $gameType, string $name = '', int $variant = 0): array
    {
        $entries = $this->entriesOf($gameType, $name, $variant);
        $typeKnown = $entries !== [] || $this->entriesOf($gameType) !== [];
        $tournament = null;
        foreach ($entries as $entry) {
            if ($tournament === null || (!EventGoal::fromEntity($tournament)->isActive() && EventGoal::fromEntity($entry)->isActive())
                || ($tournament->prizeSelection() === [] && $entry->prizeSelection() !== [])) {
                $tournament = $entry;
            }
        }
        $lastEvent = null;
        if ($entries !== []) {
            $lastEvent = $this->fetchTable('Events')->find('withoutBanner')
                ->where([
                    'Events.game_tournament_id IN' => array_map(fn (GameTournament $e): int => (int)$e->id, $entries),
                    'Events.published_at IS NOT' => null,
                ])
                ->contain(['EventRewards'])
                ->orderBy(['Events.ends_at' => 'DESC'])
                ->first();
        }

        // The entry of the type under another name, for an administrator to reach.

        return ['tournament' => $tournament, 'last_event' => $lastEvent, 'type_known' => $typeKnown];
    }

    /**
     * @param mixed $value ISO date.
     * @return \Cake\I18n\DateTime|null
     */
    private function date(mixed $value): ?DateTime
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new DateTime($value))->setTimezone('UTC');
        } catch (Throwable) {
            return null;
        }
    }
}
