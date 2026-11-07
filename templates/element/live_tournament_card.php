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

$image = fn (?string $name): ?string => $name ? $this->Url->build(['controller' => 'GameImages', 'action' => 'view', $name]) : null;
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
    <a href="<?= $this->Url->build($historyUrl) ?>" class="live-tournament-banner"
       style="<?= $live->banner ? 'background-image: url(' . h($image($live->banner)) . ');' : '' ?>"
       title="<?= h(__('History of {0}', $live->name)) ?>">
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
    </a>

    <div class="live-tournament-body">
        <div class="live-tournament-site">
            <?php if ($goal !== null && $goal->isActive()): ?>
                <div><i class="fas fa-bullseye text-primary"></i>
                    <?php if ($goal->isByGuard()): ?>
                        <?= __('Goal by guard level') ?>:
                        <?php foreach ($goal->levelTable() as $level => $points): ?>
                            <span class="badge badge-light">G<?= $level ?> <?= $this->Number->format($points) ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?= h(__('Goal: {0} points', $this->Number->format($goal->points))) ?>
                    <?php endif; ?>
                    <?= $goal->isRequired() ? '<span class="text-muted">(' . h(__('required for rewards')) . ')</span>' : '' ?>
                </div>
            <?php endif; ?>
            <?php if ($selection !== []): ?>
                <div><i class="fas fa-gift text-primary"></i> <?= __('Prizes split among the players') ?>:
                    <?php foreach ($selection as $prize): ?>
                        <span class="live-prize"><?= $itemIcon($prize) ?><?= h($prize['name']) ?>
                            <small class="text-muted">(<?= h(mb_strtolower($ruleLabels[$prize['rule']] ?? $prize['rule'])) ?>)</small></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($lastEvent !== null && $lastEvent->event_rewards): ?>
                <div><i class="fas fa-history text-primary"></i>
                    <?= h(__('Last prize split ({0})', $lastEvent->ends_at->format('d/m/Y'))) ?>:
                    <?php foreach ($lastEvent->event_rewards as $reward): ?>
                        <span class="live-prize"><?= $itemIcon(['item_id' => (int)$reward->get('game_item_id')]) ?>
                            <?= h($reward->item_name) ?> x<?= $this->Number->format((int)$reward->quantity) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($tiers !== []): ?>
            <details class="live-tournament-section">
                <summary><i class="fas fa-trophy text-warning"></i> <?= __('Rewards of each place') ?>
                    <?php if ($clanTiersFor !== []): ?>
                        &middot; <?= __('the clan\'s place now:') ?>
                        <?php foreach ($clanTiersFor as $item): ?>
                            <span class="live-prize"><?= $itemIcon($item) ?><?= $this->Number->format((int)$item['amount']) ?></span>
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
                                        <span class="live-prize" title="<?= h($item['name']) ?>"><?= $itemIcon($item) ?><?= h($item['name']) ?> x<?= $this->Number->format((int)$item['amount']) ?></span>
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

        <div class="live-tournament-footer">
            <?= $this->Html->link('<i class="fas fa-history mr-1"></i>' . __('History of these events'), $historyUrl, ['escape' => false]) ?>
        </div>
    </div>
</article>
