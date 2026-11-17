# TBOps — Total Battle Clan Operations

TBOps is a clan management site for the game **Total Battle**. It counts the
chests every member collects for the clan, holds the clan's Silver in a bank,
charges fines, splits tournament prizes and runs clan events, all from data
gathered **automatically** from the game.

The data comes from the **chest collector**
([chestcounter_collector](https://github.com/crashbrtb/chestcounter_collector)),
a Windows program that runs on a clan member's computer, talks to the game over
the network and writes into this site's database. Nobody types chest lists or
tournament rankings by hand.

```
┌──────────────── Clan PC (Windows) ────────────────┐        ┌──────────── Web server ────────────┐
│  Total Battle in Chrome  ◄──►  Chest collector    │──SQL──►│  MySQL  ◄──►  TBOps (this site)    │
│                                (scheduled task)   │──API──►│                                    │
└───────────────────────────────────────────────────┘        └──── members, officers, admins ─────┘
```

Built with CakePHP 5 · PHP 8.1+ · MySQL/MariaDB · available in 10 languages.

---

## Features

### Chest counting and goals
- **Scoreboard** with every player's chest points, epic monster points and
  progress towards the goal of the current cycle, plus the history of past cycles.
- **Counting cycles** of any length (weekly, every 6 days, ...) starting at the
  game's daily reset.
- **Chest catalogue** with the points each chest is worth; chest types the game
  adds are detected and queued for review.
- **Goals by guard level** (G1–G9) or one goal for everyone, for total and epic
  chest points.
- **Goal penalty**: a player who misses the goal has it raised in the next cycle,
  automatically or by an officer with a stated reason, and can be released from it.
- **Cycle summaries** archived automatically, so history survives after old
  chests are purged.

### Clan bank
- Every member has a **Silver account**: deposits, withdrawals and transfers
  between players, with configurable fees (including the caravan fee).
- Requests from players wait for **administrator approval**; every movement shows
  in the member's **statement** with its approval log.
- Fines and Silver prizes move through the bank, so a player's balance shows what
  they owe and what they won.

### Game tournaments, prizes and fines
- **Tournament rankings read from the game's Journal** by the collector and
  published on the site, automatically or after review.
- **Prize split** in whole units: proportional to points, equal parts or by
  position, with minimum points and administrative accounts left out.
- **Clan prizes** the clan received in each tournament, read from the game and
  split among the players.
- **Tournament goals** (global or by guard level), optionally required to earn a
  prize.
- **Fines** for players who miss a tournament goal: fixed, proportional or by
  ranges, charged to the bank after an administrator confirms.
- **Live tournaments**: the game's current and upcoming tournaments, with the
  clan's place, the best clans and the rewards of each place.

### Clan events
- Internal challenges counted from collected chests: number of chests, chest
  score, epic monsters or a chosen set of chests, within a time window.
- Live standings while the event runs, frozen results when it closes, prizes and
  goals like the game tournaments.

### Members
- **Members list kept in sync with the game**: who is in the clan, their game id,
  power and rank, read daily by the collector; renamed players keep their history.
- **Member performance**: success rate across chest goals and event goals.
- **Power history** and **tournament history** of the clan and of each player.
- Alerts when two members' names cannot be told apart by the chest counter.

### Administration
- Users with roles, sign-in with password or Google, new accounts approved by an
  administrator, each user linked to their game characters.
- **Monitoring** of the collector, maintenance and backup, with an external
  health endpoint and a ready-made e-mail alert.
- **Scheduled maintenance and database backup** installed from the site.
- **Branding and themes**: logo, favicon, event banners and four colour themes.
- **Troop calculator** for march composition.

---

## Documentation

| Document | For whom | Contents |
|---|---|---|
| [INSTALL.md](INSTALL.md) | Whoever hosts the site | Requirements, installation, cron jobs, backup, monitoring, updating, troubleshooting |
| [MANUAL.md](MANUAL.md) | Administrators, officers and players | Every menu explained, the chest collector, rules, examples |
| [INSTALL_DATABASE.md](INSTALL_DATABASE.md) | Whoever hosts the site | Database installation in detail |
| [FIRST_ADMIN.md](FIRST_ADMIN.md) | Whoever hosts the site | Creating the first administrator |
| [Collector README](https://github.com/crashbrtb/chestcounter_collector#readme) | Whoever runs the collector | The collector's full reference |

## Quick start

```bash
git clone https://github.com/crashbrtb/tbops.git .
composer install --no-dev --optimize-autoloader
cp config/app_local.example.php config/app_local.php   # set database and salt
php bin/cake.php migrations migrate
php bin/cake.php migrations seed
php bin/cake.php create_admin
```

Then schedule the maintenance, set your clan's values and connect the collector:
see [INSTALL.md](INSTALL.md).

---

**Repository:** https://github.com/crashbrtb/tbops ·
**Collector:** https://github.com/crashbrtb/chestcounter_collector

## License

MIT License
