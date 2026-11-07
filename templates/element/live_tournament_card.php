<?php
/**
 * One tournament of the game's Tournaments window, with the game's own banner,
 * icons, the clan's place, the best clans, the clan's players and the rewards of
 * each place, and what the clan site says of it (goal, prize policy, last prize).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\LiveTournament $live
 * @var array{tournament: \App\Model\Entity\GameTournament|null, last_event: \App\Model\Entity\Event|null} $site
 * @var array<int, string> $itemIcons item id => icon name
 */

use App\Model\Entity\EventReward;
use App\Service\EventGoal;

$image = fn (?string $name): ?string => $this->GameImage->url($name);
$tournament = $site['tournament'];
$lastEvent = $site['last_event'];
$goal = $tournament !== null ? EventGoal::fromEntity($tournament) : null;
$selection = $tournament?->prizeSelection() ?? [];
$ruleLabels = EventReward::ruleOptions();
$tiers = $live->rewardTiers();
$clanTiersFor = $live->rewardsOf($live->clan_place);
$members = $live->memberList();
$topClans = $live->topClans();
$historyUrl = ['controller' => 'Events', 'action' => 'history', '?' => ['tournament' => $live->game_type]];
$when = $live->isActive() ? $live->ends_at : $live->starts_at;
$itemIcon = function (array $item) use ($image, $itemIcons): string {
    $icon = $item['icon'] ?? ($itemIcons[(int)($item['item_id'] ?? 0)] ?? null);

    return $icon ? '<img src="' . h($image($icon)) . '" alt="" class="live-item-icon" loading="lazy">' : '';
};
?>
<article class="live-tournament<?= $live->isActive() ? ' is-active' : ' is-upcoming' ?>">
    <div class="live-tournament-banner"
       style="<?= $live->banner ? 'background-image: url(' . h($image($live->banner)) . ');' : '' ?>">
        <span class="live-tournament-heading">
            <?php if ($live->icon): ?>
                <img src="<?= h($image($live->icon)) ?>" alt="" class="live-tournament-icon" loading="lazy">
            <?php endif; ?>
            <span>
                <strong class="live-tournament-name"><?= h($tournament?->displayName() ?: $live->name) ?></strong>
                <?php if ($when !== null): ?>
                    <span class="live-tournament-when" data-countdown="<?= $when->getTimestamp() ?>">
                        <?= $live->isActive() ? h(__('Ends {0} UTC', $when->format('d/m H:i'))) : h(__('Starts {0} UTC', $when->format('d/m H:i'))) ?>
                    </span>
                <?php endif; ?>
                <?php if ($live->isActive() && $live->clan_points): ?>
                    <span class="live-tournament-stat"><?= h(__('Clan points: {0}', $this->Number->format($live->clan_points))) ?></span>
                <?php endif; ?>
                <?php if ($live->isActive() && $topClans !== []): ?>
                    <span class="live-tournament-stat">
                        <?= $live->clan_place ? h(__('Clan place: #{0}', $live->clan_place)) : h(__('Clan place: 100+')) ?>
                    </span>
                <?php endif; ?>
            </span>
        </span>
    </div>

    <div class="live-tournament-body">
        <?php if ($tiers !== []): ?>
            <details class="live-tournament-section">
                <summary><i class="fas fa-trophy text-warning"></i> <?= __('Clan rewards') ?>
                    <?php if ($clanTiersFor !== []): ?>
                        &middot; <?= __('the clan\'s place now:') ?>
                        <?php foreach ($clanTiersFor as $item): ?>
                            <span class="live-prize" title="<?= h($item['name']) ?>"><?= $itemIcon($item) ?><?= $this->gameNumber((int)$item['amount']) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </summary>
                <table class="table table-sm live-table">
                    <tbody>
                        <?php foreach ($tiers as $tier): ?>
                            <tr class="<?= $live->clan_place && $live->clan_place >= $tier['from'] && $live->clan_place <= $tier['to'] ? 'is-clan' : '' ?>">
                                <td class="text-nowrap"><?= $tier['from'] === $tier['to'] ? h(__('#{0}', $tier['from'])) : h(__('#{0} to #{1}', $tier['from'], $tier['to'])) ?></td>
                                <td>
                                    <?php foreach ((array)$tier['items'] as $item): ?>
                                        <span class="live-prize" title="<?= h($item['name']) ?>"><?= $itemIcon($item) ?><?= $this->gameNumber((int)$item['amount']) ?></span>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>

        <?php if ($topClans !== []): ?>
            <details class="live-tournament-section">
                <summary><i class="fas fa-flag text-primary"></i> <?= __('Best clans') ?></summary>
                <table class="table table-sm live-table">
                    <tbody>
                        <?php foreach ($topClans as $clan): ?>
                            <tr>
                                <td class="text-nowrap">#<?= (int)$clan['position'] ?></td>
                                <td><?= $clan['tag'] !== '' ? '[' . h($clan['tag']) . '] ' : '' ?><?= h($clan['name'] ?: '#' . $clan['clan_id']) ?></td>
                                <td class="text-right"><?= $this->Number->format((int)$clan['points']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>

        <?php if ($members !== [] && array_filter($members, fn ($m): bool => (int)$m['points'] > 0)): ?>
            <details class="live-tournament-section">
                <summary><i class="fas fa-users text-primary"></i> <?= __('Clan ranking') ?>
                    <span class="text-muted">&middot; <?= h(__('{0} player(s) with points', count(array_filter($members, fn ($m): bool => (int)$m['points'] > 0)))) ?></span>
                </summary>
                <table class="table table-sm live-table">
                    <tbody>
                        <?php foreach ($members as $member): ?>
                            <?php if ((int)$member['points'] <= 0) {
                                continue;
                            } ?>
                            <tr>
                                <td class="text-nowrap">#<?= (int)$member['position'] ?></td>
                                <td><?= h($member['name'] ?: '#' . $member['player_id']) ?></td>
                                <td class="text-right"><?= $this->Number->format((int)$member['points']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>

        <?php
        $hasClanGoal = ($goal !== null && $goal->isActive());
        $hasClanPrizes = ($selection !== []);
        ?>
        <?php if ($hasClanGoal || $hasClanPrizes): ?>
            <details class="live-tournament-section">
                <summary><i class="fas fa-bullseye text-primary"></i> <?= __('Clan rewards and goals') ?></summary>
                <div class="live-tournament-site mt-2 pl-3 border-left">
                    <?php if ($hasClanGoal): ?>
                        <div class="mb-2">
                            <strong><i class="fas fa-bullseye text-primary mr-1"></i><?= __('Goal') ?>:</strong>
                            <?php if ($goal->isByGuard()): ?>
                                <?= __('Goal by guard level') ?>:
                                <div class="mt-1 d-flex flex-wrap" style="gap: 4px;">
                                    <?php foreach ($goal->levelTable() as $level => $points): ?>
                                        <span class="badge badge-light border">G<?= $level ?> <?= $this->Number->format($points) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <?= h(__('Goal: {0} points', $this->Number->format($goal->points))) ?>
                            <?php endif; ?>
                            <?= $goal->isRequired() ? '<span class="text-muted ml-1">(' . h(__('required for rewards')) . ')</span>' : '' ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($hasClanPrizes): ?>
                        <div class="mb-2">
                            <strong><i class="fas fa-gift text-primary mr-1"></i><?= __('Prizes split among the players') ?>:</strong>
                            <div class="mt-1">
                                <?php foreach ($selection as $prize): ?>
                                    <span class="live-prize" title="<?= h($prize['name']) ?>">
                                        <?= $itemIcon($prize) ?><?= h($prize['name']) ?>
                                        <small class="text-muted">(<?= h(mb_strtolower($ruleLabels[$prize['rule']] ?? $prize['rule'])) ?>)</small>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($lastEvent !== null && $lastEvent->event_rewards): ?>
                        <div class="mb-2">
                            <strong><i class="fas fa-history text-primary mr-1"></i><?= h(__('Last prize split ({0})', $lastEvent->ends_at->format('d/m/Y'))) ?>:</strong>
                            <div class="mt-1">
                                <?php foreach ($lastEvent->event_rewards as $reward): ?>
                                    <span class="live-prize" title="<?= h($reward->item_name) ?>">
                                        <?= $itemIcon(['item_id' => (int)$reward->get('game_item_id')]) ?>
                                        <?= h($reward->item_name) ?> x<?= $this->gameNumber((int)$reward->quantity) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </details>
        <?php endif; ?>
    </div>
</article>
