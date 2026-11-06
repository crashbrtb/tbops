<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Member;
use Cake\Chronos\ChronosDate;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use DateTimeInterface;

/**
 * The power of the clan and of each member over time.
 *
 * Every time the game's list of the clan is applied (MemberRosterService), the
 * power each player had is kept as that day's reading: one per member and day,
 * the later reading of a day replacing the earlier. The roster already refuses
 * a list older than the one applied, so a reading never goes back in time.
 *
 * The clan's power on a day is the sum of the readings of that day. A member
 * who left simply has no more readings.
 */
class MemberPowerHistoryService
{
    use LocatorAwareTrait;

    /** The periods the history page offers, in days; 0 is everything. */
    public const PERIODS = [30, 90, 180, 365, 0];

    public const DEFAULT_PERIOD = 90;

    /**
     * A day counts as a reading of the whole clan when it has at least this
     * share of the most players any day of the period has.
     */
    public const WHOLE_CLAN_SHARE = 0.8;

    /**
     * Keep the power of these members as their reading of the day `$at` falls on.
     *
     * @param list<array{0: \App\Model\Entity\Member, 1: int}> $readings Saved member, power read.
     * @param \Cake\I18n\DateTime $at When the game was read.
     * @param string $source MemberPowerSnapshot::SOURCE_*.
     * @return int Readings written.
     */
    public function record(array $readings, DateTime $at, string $source): int
    {
        $readings = array_values(array_filter(
            $readings,
            fn (array $r): bool => $r[0] instanceof Member && $r[0]->id !== null && $r[1] >= 0
        ));
        if ($readings === []) {
            return 0;
        }

        $snapshots = $this->fetchTable('MemberPowerSnapshots');
        $day = $at->setTimezone((string)date_default_timezone_get())->format('Y-m-d');
        $recordedAt = $at->setTimezone('UTC');

        $existing = [];
        $ids = array_map(fn (array $r): int => (int)$r[0]->id, $readings);
        foreach ($snapshots->find()->where(['member_id IN' => $ids, 'snapshot_date' => $day])->all() as $snapshot) {
            $existing[(int)$snapshot->member_id] = $snapshot;
        }

        $written = 0;
        foreach ($readings as [$member, $power]) {
            $snapshot = $existing[(int)$member->id] ?? $snapshots->newEntity([
                'member_id' => (int)$member->id,
                'snapshot_date' => $day,
            ]);
            // Two lists of the same moment (a tournament and the Members list
            // read at its end): the one already kept stays.
            $kept = $snapshot->isNew() ? null : $snapshot->recorded_at;
            if ($kept !== null && $kept->greaterThan($recordedAt)) {
                continue;
            }
            $snapshot->set([
                'game_player_id' => $member->game_player_id !== null ? (int)$member->game_player_id : null,
                'power' => $power,
                'source' => $source,
                'recorded_at' => $recordedAt,
            ]);
            $snapshots->saveOrFail($snapshot);
            $written++;
        }

        return $written;
    }

    /**
     * The first day a period covers.
     *
     * @param int $days One of PERIODS; 0 is everything.
     * @return \Cake\I18n\Date|null Null for everything.
     */
    public function periodStart(int $days): ?Date
    {
        return $days > 0 ? Date::today()->subDays($days) : null;
    }

    /**
     * The clan's power on each day with a reading of the whole clan.
     *
     * A day whose reading covers far fewer players than the clan has - an old
     * tournament where only some players were linked to a member - is not the
     * clan's power and is left out: summed, it would make the clan look like it
     * grew from almost nothing. Each member's own readings are not affected.
     *
     * @param \Cake\I18n\Date|null $from First day, or null for everything.
     * @return list<array{date: string, power: int, players: int}>
     */
    public function clanSeries(?Date $from): array
    {
        $query = $this->fetchTable('MemberPowerSnapshots')->find();
        $query->select([
            'snapshot_date',
            'power' => $query->func()->sum('power'),
            'players' => $query->func()->count('*'),
        ])
            ->groupBy(['snapshot_date'])
            ->orderBy(['snapshot_date' => 'ASC'])
            ->disableHydration();
        if ($from !== null) {
            $query->where(['snapshot_date >=' => $from->format('Y-m-d')]);
        }

        $series = [];
        foreach ($query->all() as $row) {
            $series[] = [
                'date' => $this->day($row['snapshot_date']),
                'power' => (int)$row['power'],
                'players' => (int)$row['players'],
            ];
        }

        $most = $series !== [] ? max(array_column($series, 'players')) : 0;
        $least = (int)ceil($most * self::WHOLE_CLAN_SHARE);

        return array_values(array_filter($series, fn (array $day): bool => $day['players'] >= $least));
    }

    /**
     * One member's power on each day with a reading.
     *
     * @param int $memberId Member.
     * @param \Cake\I18n\Date|null $from First day, or null for everything.
     * @return list<array{date: string, power: int}>
     */
    public function playerSeries(int $memberId, ?Date $from): array
    {
        $query = $this->fetchTable('MemberPowerSnapshots')->find()
            ->select(['snapshot_date', 'power'])
            ->where(['member_id' => $memberId])
            ->orderBy(['snapshot_date' => 'ASC'])
            ->disableHydration();
        if ($from !== null) {
            $query->where(['snapshot_date >=' => $from->format('Y-m-d')]);
        }

        $series = [];
        foreach ($query->all() as $row) {
            $series[] = ['date' => $this->day($row['snapshot_date']), 'power' => (int)$row['power']];
        }

        return $series;
    }

    /**
     * How much each active member grew in the period: from their first reading
     * in it to their last.
     *
     * @param \Cake\I18n\Date|null $from First day, or null for everything.
     * @return list<array{member_id: int, player: string, first_date: string, first: int, last_date: string, last: int, change: int, percent: float|null, readings: int}>
     *   Biggest growth first.
     */
    public function growth(?Date $from): array
    {
        $query = $this->fetchTable('MemberPowerSnapshots')->find()
            ->select([
                'MemberPowerSnapshots.member_id',
                'MemberPowerSnapshots.snapshot_date',
                'MemberPowerSnapshots.power',
                'Members.player',
            ])
            ->innerJoinWith('Members')
            ->where(['Members.active' => 1])
            ->orderBy(['MemberPowerSnapshots.snapshot_date' => 'ASC'])
            ->disableHydration();
        if ($from !== null) {
            $query->where(['MemberPowerSnapshots.snapshot_date >=' => $from->format('Y-m-d')]);
        }

        $byMember = [];
        foreach ($query->all() as $row) {
            $id = (int)$row['member_id'];
            $date = $this->day($row['snapshot_date']);
            $power = (int)$row['power'];
            if (!isset($byMember[$id])) {
                $byMember[$id] = [
                    'member_id' => $id,
                    'player' => (string)($row['_matchingData']['Members']['player'] ?? ''),
                    'first_date' => $date,
                    'first' => $power,
                    'readings' => 0,
                ];
            }
            $byMember[$id]['last_date'] = $date;
            $byMember[$id]['last'] = $power;
            $byMember[$id]['readings']++;
        }

        $out = [];
        foreach ($byMember as $entry) {
            $change = $entry['last'] - $entry['first'];
            $out[] = $entry + [
                'change' => $change,
                'percent' => $entry['first'] > 0 ? round($change * 100 / $entry['first'], 2) : null,
            ];
        }
        usort($out, fn (array $a, array $b): int => [$b['change'], $a['player']] <=> [$a['change'], $b['player']]);

        return $out;
    }

    /**
     * @param mixed $value A date column as the driver returns it.
     * @return string Y-m-d.
     */
    private function day(mixed $value): string
    {
        // Cake's Date is not a DateTimeInterface, and cast to a string it takes
        // the reader's format ("05/11/2026" in pt_BR), which reads back as May.
        if ($value instanceof DateTimeInterface || $value instanceof ChronosDate) {
            return $value->format('Y-m-d');
        }

        return substr((string)$value, 0, 10);
    }
}
