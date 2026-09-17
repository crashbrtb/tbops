<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\GameTournament;
use App\Model\Table\GameTournamentsTable;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * Fills the tournament catalogue from what the mapper reads off the Journal.
 *
 * The game only ever sends a tournament's type; its name exists on screen, in
 * the title "Your Clanmates' results in <name>". The mapper opens each result,
 * sees which type the game sends for it and reports the pair here. A type can
 * have several rankings with different goals (the Dark Omens has two); each is
 * its own entry.
 *
 * Names chosen by an administrator are never overwritten, and an image is only
 * added where the catalogue has none, so running the mapper again is harmless.
 */
class TournamentCatalogService
{
    use LocatorAwareTrait;

    public const MAX_ENTRIES = 200;
    public const MAX_IMAGE_BYTES = 524288;

    /**
     * Image types accepted, by what getimagesizefromstring() reports.
     *
     * @var array<int, string>
     */
    public const IMAGE_TYPES = [
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_WEBP => 'image/webp',
        IMAGETYPE_GIF => 'image/gif',
    ];

    /**
     * @param array<string, mixed> $data Body: `{entries: [{tournament_key|game_type, ranking, name, ended_at, image}]}`.
     * @return array{errors: array<string, string>, results: list<array<string, mixed>>}
     */
    public function register(array $data): array
    {
        $entries = $data['entries'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || $entries === []) {
            return ['errors' => ['entries' => __('Send at least one tournament.')], 'results' => []];
        }
        if (count($entries) > self::MAX_ENTRIES) {
            return ['errors' => ['entries' => __('Send at most {0} tournaments at a time.', self::MAX_ENTRIES)], 'results' => []];
        }

        $errors = [];
        $clean = [];
        foreach ($entries as $i => $entry) {
            $line = $i + 1;
            if (!is_array($entry)) {
                $errors["entries.{$line}"] = __('Line {0} is not a tournament.', $line);
                continue;
            }
            [$type, $variant] = isset($entry['game_type'])
                ? [is_int($entry['game_type']) ? $entry['game_type'] : null, null]
                : GameTournamentsTable::parseKey(is_string($entry['tournament_key'] ?? null) ? $entry['tournament_key'] : null);
            if ($type === null || $type <= 0) {
                $errors["entries.{$line}.tournament_key"] = __('The tournament type is missing or invalid.');
                continue;
            }
            $ranking = GameTournamentsTable::normalizeRanking($entry['ranking'] ?? null);
            if ($ranking === null) {
                $errors["entries.{$line}.ranking"] = __('The tournament ranking is invalid.');
                continue;
            }
            $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
            if (mb_strlen($name) > 120) {
                $errors["entries.{$line}.name"] = __('The tournament name has more than {0} characters.', 120);
                continue;
            }
            $endedAt = null;
            if (!empty($entry['ended_at']) && is_string($entry['ended_at'])) {
                try {
                    $endedAt = (new DateTime($entry['ended_at']))->setTimezone('UTC');
                } catch (Throwable) {
                    $errors["entries.{$line}.ended_at"] = __('The tournament date is not a valid date.');
                    continue;
                }
            }
            $image = null;
            if (!empty($entry['image']) && is_string($entry['image'])) {
                $image = $this->decodeImage($entry['image']);
                if ($image === null) {
                    $errors["entries.{$line}.image"] = __('The image must be a PNG, JPEG, WebP or GIF of at most {0} KB.', (int)(self::MAX_IMAGE_BYTES / 1024));
                    continue;
                }
            }
            $clean[] = compact('type', 'variant', 'ranking', 'name', 'endedAt', 'image');
        }

        if ($errors) {
            return ['errors' => $errors, 'results' => []];
        }

        $catalogue = $this->fetchTable('GameTournaments');
        $results = [];
        $catalogue->getConnection()->transactional(function () use ($catalogue, $clean, &$results): void {
            foreach ($clean as $item) {
                $existed = $catalogue->exists(['game_type' => $item['type'], 'ranking' => $item['ranking']]);
                $entry = $catalogue->touchType($item['type'], $item['variant'], $item['endedAt'], $item['ranking']);
                $renamed = $item['name'] !== ''
                    && $catalogue->offerName($entry, $item['name'], GameTournament::SOURCE_JOURNAL);

                $imageSaved = false;
                if ($item['image'] !== null && !$entry->has_image) {
                    $catalogue->updateAll(
                        ['image' => $item['image'][0], 'image_mime' => $item['image'][1], 'modified' => DateTime::now()],
                        ['id' => $entry->id]
                    );
                    $imageSaved = true;
                }

                $results[] = [
                    'game_type' => $item['type'],
                    'ranking' => $item['ranking'],
                    'id' => $entry->id,
                    'created' => !$existed,
                    'renamed' => $renamed,
                    'name' => $entry->name,
                    'name_kept' => $item['name'] !== '' && !$renamed && $entry->name !== $item['name'],
                    'image_saved' => $imageSaved,
                ];
            }
        });

        return ['errors' => [], 'results' => $results];
    }

    /**
     * Bytes and mime type of a base64 image, or null when it is not one.
     *
     * @param string $base64 Image, optionally as a data URI.
     * @return array{0: string, 1: string}|null
     */
    public function decodeImage(string $base64): ?array
    {
        $base64 = preg_replace('/^data:[^;]+;base64,/', '', trim($base64)) ?? '';
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        return $this->imageType($bytes);
    }

    /**
     * @param string $bytes Raw image.
     * @return array{0: string, 1: string}|null
     */
    public function imageType(string $bytes): ?array
    {
        // Silenced: bytes that are not an image are an expected input here.
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset(self::IMAGE_TYPES[$info[2]])) {
            return null;
        }

        return [$bytes, self::IMAGE_TYPES[$info[2]]];
    }
}
