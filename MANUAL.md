# TBOps User Manual

How the site works, menu by menu, with the rules behind each number and
examples. For installing the site see [INSTALL.md](INSTALL.md); for an overview
see the [README](README.md).

**Contents**

1. [Key concepts and premises](#1-key-concepts-and-premises)
2. [The chest collector](#2-the-chest-collector)
3. [Public menus](#3-public-menus) — Chests · Tournaments · Tools · Bank · Clan Data
4. [Admin menu](#4-admin-menu) — Members · Users · Chests · Bank · Events · System
5. [Routines and examples](#5-routines-and-examples)
6. [Frequently asked questions](#6-frequently-asked-questions)
7. [Appendix: collector API](#7-appendix-collector-api)

---

## 1. Key concepts and premises

Read this section first: the rest of the manual relies on it.

### Members and users are different things

| | Member | User |
|---|---|---|
| What it is | A character (player) in the clan | A login on the site |
| Where it comes from | The game, through the collector | Created by an administrator, or self-registered with Google and approved |
| Used for | Chests, scores, goals, events, bank accounts | Signing in, banking operations, administration |

A user is **linked** to one or more members (for example a player with a main
city and a farm). A user can only operate the bank accounts of the members
linked to them.

### Roles

| Role | What it can do |
|---|---|
| *Visitor* (not signed in) | Scoreboard, goals, cycle history, tournaments, events, troop calculator; Clan Data when that option is on |
| `user` | The above, plus the bank for their own members |
| `bankers` | The above, plus the whole bank: approving deposits and withdrawals, operating and viewing the history of every member's account, charging, waiving and refunding fines, crediting Silver prizes. No Admin menu and no configs, not even the bank fees |
| `admin` | Everything, including the **Admin** menu |

A user can hold several roles. Give the treasurer `bankers` (and `user`) in
**Admin › Users** to let them run the bank without access to the rest of the
administration.

### Time is UTC, and the day starts at the game's reset

Every date on the site is **UTC**. The game resets at **17:00 UTC**, so cycles,
backups and tournaments are aligned to that hour. A chest's time is when the
game generated it (its expiry minus the chest's 20 hours), not when it was opened.

### The counting cycle

Chests are counted in **cycles**, defined by two configs:

- `reference_day`: any moment on which a cycle started (`2025-07-30 17:00:00`);
- `every_how_many_days`: the cycle length.

Every cycle starts at `reference_day + N × length`. With a reference of
Wednesday 17:00 UTC and a length of 7, every cycle runs from Wednesday 17:00 to
the next Wednesday 17:00.

### Points

Each chest type has a **score** in the chest catalogue (*Standard Chests*). A
player's **chest score** is the sum of the scores of the chests they collected in
the cycle. Chests flagged **Epic Monster** also add to the **epic score**, which
has its own goal. A chest with score 0 is counted but adds no points.

### Silver is stored in millions

The bank keeps every amount in **millions of Silver**: typing `500` means
500 million, `1000` means 1 billion. Amounts are shown in game notation: three
significant digits with `K`, `M`, `B`, `T` (`10B`, `1,5B`).

### Guard level

Goals can depend on the member's guard level (`guards` on the member record):
**G1 to G9**, or **T9** when guards, specialists, monsters and engineers are all
level 9. A member whose level is unknown (**G0**), or a chest name with no member
record, gets the **highest** goal of the table: nobody gets a lighter goal
because their profile was not filled in.

### Administrative accounts

A member marked as an **administrative account** (bank farms, alt accounts,
holders) appears in rankings but never receives a prize and does not count in the
split.

---

## 2. The chest collector

The collector ([chestcounter_collector](https://github.com/crashbrtb/chestcounter_collector))
is a Windows program (version 4, written in Rust) that runs on the computer of a
clan member — the **executor**. It opens Total Battle in Chrome, talks to the game
**over the network** from inside the game's own tab and writes the results to the
site. Nothing is clicked, nothing is read off the screen and there is nothing to
calibrate.

The full technical reference is the
[collector README](https://github.com/crashbrtb/chestcounter_collector#readme).
This chapter explains what the site needs from it.

### What it does

| Module | What it does | How often | Writes to the site |
|---|---|---|---|
| **Clan chests** | Opens every clan gift waiting in the game and records each chest | Every run | Database: `collected_chests`, `incomplete_chests`, `game_chest_types`, `job_runs` |
| **Clan members** | Reads the clan's Members list: game id, name, power, rank, level | Once a day | API: `/api/v1/members` |
| **Tournament rankings** | Reads the clan's tournament results from the Journal, the clan's ranking among clans and the live Tournaments window | Once a day | API: `/api/v1/tournaments`, `/clan-results`, `/live-tournaments` |
| **Shield of Peace** | Renews the city's peace shield with gold when less than a day is left | Every run | — |

### Premises

- **A Windows PC that is on** when the runs are scheduled, with Google Chrome.
  The collector opens its own Chrome window per game account; it does not use the
  executor's everyday browser profile.
- **A game account with access to the clan's gifts** (the chests are opened by
  that account) and its login and password.
- **Remote access to the site's MySQL database** from that PC, for the chests.
- **An API token** from the site, for members and tournaments.
- **One database per profile.** A profile (city) collects into exactly one
  database, and two active profiles cannot share one.

### Setting it up

1. On the site, ask whoever hosts it for a **MySQL user** that may connect from
   the executor's PC ([INSTALL.md](INSTALL.md#connecting-the-chest-collector)).
2. On the site, open **Admin › System › API Tokens**, create a token named after
   the PC (`Leader PC`) and copy it: it is shown only once.
3. On the PC, download the installer from the collector's repository and run
   `TBChestCollector-Setup-<version>.exe`. The default folder,
   `%LOCALAPPDATA%\Programs\TB Chest Collector`, needs no administrator rights.
4. Open **Configure** (Start menu) and add:
   - an **account**: the totalbattle.com e-mail and password;
   - a **profile** under it: the city, with the **database** (host, port, user,
     password, database name) and, under *Modules…*, the **site address** and the
     **token**. *Test connection* checks the token.
5. Press **Open window** on the account and sign in once by hand: the game e-mails
   a code to any browser it does not know. The session stays in that window's
   folder afterwards.
6. In the **Execution** tab, choose the interval (every 5 minutes up to once a
   day) and press **Schedule**. This creates the *TB Chest Collector* task in
   Windows Task Scheduler. One task covers every module.
7. On the site, open **Admin › System › Monitoring** and set the `collector`
   limit slightly above that interval (interval 60 min → limit 90–180 min).

Run `tbcollector diagnose` to check the browser, every database and every site
without collecting anything.

### How the data reaches the site

```
Gift in the game ─► collector writes the chest ─► opens the gift ─► checks it really opened
                         │                                              │
                         └──── not opened? the row is taken back ◄──────┘
```

- **Chests are written before being opened.** A chest written but not opened can
  be put right; one opened but not written would be lost.
- **Unknown chest types** are looked up in the game's own chest catalogue and
  registered in `game_chest_types`. On the site they show up in
  **New Chests** until someone gives them a score.
- **Chests missing a name** (type or player) go to **Incomplete Chests** for
  manual correction.
- **Members list**: every player in the list becomes active, every member not in
  it becomes inactive. Only a complete list is ever sent.
- **Tournaments**: each Journal result becomes a game event on the site. The
  tournament catalogue decides whether it is published straight away or waits for
  review. Nothing is sent twice.
- **Heartbeat**: every chest run writes a `job_runs` row, which feeds
  **Monitoring**.

### Running it by hand

| Command | Effect |
|---|---|
| `tbcollector` | Runs every enabled module that is due (what the scheduled task calls) |
| `tbcollector --force` | Ignores the schedule: once-a-day modules run again |
| `tbcollector --module chests` | Only this module (`shield`, `chests`, `members`, `events`) |
| `tbcollector --profile NAME` | Only this profile |
| `tbcollector --check` | Validates the configuration, collects nothing |
| `tbcollector diagnose` | Checks browser, databases and sites (read-only) |

**To cancel a run, hold ESC** for a moment, from any window. Whatever was already
collected stays saved.

Every run ends its log, `execution_logs\collector_YYYY-MM-DD.log`, with a
**RUN SUMMARY** block: the status, chests per profile, each module's result and
every warning. Read that block first when something looks wrong.

> **Security:** passwords and tokens are stored in plain text in
> `config\config.json` on the collector PC. Keep that PC and folder private.

---

## 3. Public menus

### Chests › Scoreboard

The site's home page (`/score`). Shows the **current cycle**:

- the goals in force (global or by guard level), the goal penalty if any, the
  time left until the cycle ends and the **last chest update** (when the
  collector last wrote a chest);
- **Score Summary**: position, player, final score and monster chests. Click a
  player for the breakdown of their chests, or their monster chests and dates;
- **Monster Chests**: the matrix of epic monsters per player;
- **View All (Detailed Data)**: one column per chest source.

The score colour goes from red to green as the player approaches the goal (the
colours and the point where the change starts are configs). An **arrow** next to
a player means a raised goal this cycle; its tooltip gives the reason.

**Cycle history** (`/history`) lists the archived cycles with each player's
result.

### Chests › Goals

The catalogue of chests that score, with the points of each and the goals in
force. *Show only scored* hides chests worth 0. Use it to tell players which
chests are worth collecting.

### Tournaments

**Tournaments and Goals**: the tournaments running in the game now and those
about to start, as the collector last read them, with dates, the clan's points
and place, the best clans, the clan's players and the rewards of each place. A
green badge on the menu counts the events running.

The pages `/events` and `/events/history` (also public) list the clan events
and the published game tournaments with their results, goals and prizes; each
event has its own page with its standings.

### Tools › Troop Calculator

Helps compose marches: leadership, dominance and authority capacity, the Monsters
Boost research and strength against epic monsters. Under *Automations* there are
links to other clan tools (Crypt Invader, Citadel Attack).

Hidden when `calculator_function = 0`.

### Bank

Shown when `bank_function = 1`. Requires signing in.

| Page | What it does |
|---|---|
| **Bank** | Every member's balance, total Silver held and total fees collected |
| **Deposit** | Records Silver sent to the bank in the game |
| **Withdraw** | Asks for Silver back |
| **Transfer** | Moves balance from one of your members to another player |
| **Statement** | Every movement of your members, with the approval log |

**Rules**

- A player only operates members linked to their user. An administrator or a
  banker can operate any member.
- **Deposits and withdrawals by a player wait for approval**; the balance only
  changes when an administrator or a banker approves. Done by an administrator or
  a banker, they are approved at once.
- Administrators and bankers also find **Bank Approvals** and **Tournament
  Fines** at the bottom of this menu, and the **View history** of each member on
  the Bank page.
- **Transfers are immediate** and need a positive balance.
- Fees (`deposit_fee`, `withdrawal_fee`, `transfer_fee`, `caravan_fee`) go to the
  clan, shown as *Total Fees Collected*.
- A balance can be **negative**: that is a debt, usually from a fine.

**Examples** (fees: deposit 50, withdrawal 50, transfer 10, caravan 20%):

| Operation | Typed | Balance change | Fee to the clan |
|---|---|---|---|
| Deposit | 1000 | +950 | 50 |
| Deposit **by caravan** | 1000 | +800 | 200 (20%, no fixed fee) |
| Withdrawal | 500 | −550 | 50 |
| Transfer to Bob | 100 | you −110, Bob +100 | 10 |

> The site records the movements; the Silver itself is handed over **in the
> game** by whoever runs the bank. Approve a deposit only after the Silver has
> arrived, and pay a withdrawal in the game when you approve it.

### Clan Data

Power History and Tournament History (see [Members](#members)). Off by default:
with `clan_data_function = 1` everyone sees it, signed in or not; otherwise only
administrators, through the Admin menu.

### Language and account

The language selector switches between the 10 languages. The user menu shows
pending bank approvals and, for administrators, unread notifications, and has
*Change password* and *Sign out*.

---

## 4. Admin menu

Visible to administrators only.

### Members

#### List

The clan roster: name, game id, power, troop levels (guards, specialists,
monsters, engineers), active/inactive, linked user and the **Tournament rewards**
column (*Receives rewards* / *Administrative*).

- **Active/inactive** follows the game: the collector's daily Members list and
  every tournament ranking set it. Once a list from the game has been applied,
  chests no longer turn members on or off or create them.
- **Troop levels** decide the guard level used by the goals. Keep them up to date.
- **Renamed players** are followed by game id, and their chests, mappings,
  summaries and penalties move to the new name automatically.

#### Member Performance

Success rate of each member: chest goals met per cycle, event goals met and the
overall rate, with the ranking and each player's full history. Useful to decide
promotions, warnings and removals.

#### Power History

The clan's and each member's power, one reading a day from the collector:
change over the period and growth of each member.

#### Tournament History

How the clan did in each game tournament: its place among the clans, points and
the prizes it received.

### Users

| Page | What it does |
|---|---|
| **List** | Users, their roles, the members linked, *Linked with Google*, approve/deactivate |
| **Add** | Creates a user, picks their roles and links their members |
| **Roles** | The roles (`admin`, `user`, `bankers`; see [Roles](#roles)) |

Someone who signs in with **Google** for the first time gets an inactive account
and sees *Awaiting approval* until an administrator activates it and links their
members.

### Chests

#### Standard Chests

The chest catalogue: each chest's name, source, alias, **score** and whether it is
an **Epic Monster**. Changing a score affects every cycle shown from then on,
including the current one.

#### New Chests

Chest types found in the collection that are not in the catalogue yet. Give each
a score (and the Epic Monster flag) and add it. Until then it is counted with
score 0.

#### Incomplete Chests

Chests the collector could not name fully (type or player missing), with the
game's ids. Correct each one by hand, or mark it *Not recoverable*. The menu badge
counts the pending ones.

#### Summary last cycles

The archived results per cycle: total chests, score, epic score, the goal each
player had and whether they reached it. The maintenance fills this in by itself;
the buttons *Process Newest Completed Cycle* / *Process Previous Cycle* do it on
demand, for example after correcting chests of a closed cycle.

#### Goal Penalties

Who has a raised goal in this cycle and why. Here you can:

- **release** a player from the automatic penalty (current or previous cycle);
- add a **manual penalty**: a percentage on the total goal, the epic goal or both,
  with a mandatory reason (up to 100 characters).

**How the automatic penalty works** (`goal_penalty_enabled`,
`goal_penalty_percent`, `goal_penalty_target`):

- A player below a goal in cycle N gets that goal raised by the percentage in
  cycle N+1 (rounded up).
- It **does not stack**: missing again keeps the same raised goal. Reaching the
  raised goal returns the player to the base goal in the next cycle. Reaching only
  the base goal while carrying a raised one is still a miss.
- With `target = both`, missing either goal is a miss, and only the missed goal is
  raised.
- **Spared**: a player's first cycle in the clan; a player released by an
  administrator; a player with no chest at all in the previous cycle.
- A manual penalty **adds** to the automatic one on the same goal.

> **Example.** Base goal 15,000, penalty 10%. Ana scored 13,350 (89%) last cycle:
> this cycle her goal is **16,500**, with the reason *Previous goal not reached
> (89%)*. An officer adds a manual 5% for "skipped clan war": her goal becomes
> 15,000 + 15% = **17,250**. If she reaches 17,250, next cycle she is back to 15,000.

### Bank › Bank Approvals

The queue of pending deposits and withdrawals, with who asked, the net effect and
the description. Approve (the balance changes at once) or reject, with an optional
reason recorded in the approval log. Bankers reach the same page through the
**Bank** menu.

### Events

There are two kinds of event:

| | Game tournament | Clan event |
|---|---|---|
| What | A tournament played in the game | An internal challenge |
| Ranking from | The game's Journal, via the collector | Collected chests |
| Created by | The collector (automatically) | An administrator |
| Dates | Read from the game, after it ends | A window that starts in the future |

#### Manage Events

Every event and its state. For a game tournament: **Review result**, publish,
unpublish, recalculate, export to CSV. For a clan event: edit, cancel, **Close and
record the result** (the maintenance also closes it when its window ends).

**Reviewing a tournament**

1. Players are matched to members by **game id**, then by exact name, then by a
   similar name (spaces, accents and case aside).
2. Untick whoever should not share, correct points if needed and **recalculate**.
3. Check the prizes (publishing is refused while a tournament type has none),
   then **publish**.
4. After publishing, the **fines** and the **Silver prizes** of the event are
   listed as pending (see below).

#### New Event

Creates a **clan event**: name, window, criterion, goal and prizes.

| Criterion | Counts |
|---|---|
| Number of chests collected | Every chest in the window |
| Chest score | Points, as in the scoreboard |
| Number of epic monster chests | Only Epic Monster chests |
| Custom chests | Only the chests you choose |

The scoreboard shows a running event with its banner and live standings,
including each player's projected prize.

#### Tournament Catalogue

One entry per **tournament type** of the game (the game sends only a code like
`1024:1`, never the name). Each entry holds:

| Section | What it sets |
|---|---|
| Name and description | How the tournament appears on the site |
| Goal | Default goal for new events of this type: global or by guard level, and whether it is **required** to earn a prize |
| Prizes | The prize lines, split rule and minimum points; also which **clan prizes** to split among the players |
| Fine | The fine for missing the goal |
| Publish events automatically | Publish a complete ranking without review |

Duration and images come from the game. A new event of a type inherits its goal
and prizes from the catalogue, or from the last event of the same type.

**Prize split rules** — always in whole units, always adding up to the quantity:

- **Proportional** (largest remainder): each player gets the whole part of
  `quantity × points ÷ total`; the units left go to the largest fractions.
- **Equal parts**: same share each; the leftover goes one each to the best placed,
  or stays with the clan.
- **By position**: a fixed amount per place, counted among the players who can
  receive; empty places stay with the clan.

Only **recipients** take part: eligible (not administrative), qualified (reached
the goal, when it is required) and above the minimum points.

> **Example — proportional.** 10 pieces; Ana 400 points, Bob 350, Caio 250
> (total 1,000). Exact shares 4.0 / 3.5 / 2.5 → whole parts 4 / 3 / 2 = 9. One
> piece is left; Bob and Caio tie at .5, Bob has more points: **4 / 4 / 2**.
>
> **Example — equal parts.** 10 pieces among 3: 3 each, 1 left over, which goes to
> the best placed or stays with the clan.
>
> **Example — required goal.** Goal 1M points, required. Ana 3M, Bob 2M, Caio 0.5M:
> Caio is ranked but receives nothing, and the split is over Ana and Bob only.

#### Tournament Fines

Every fine of the game tournaments, who still owes and what was given up.
Administrators and bankers run it; bankers also see the **Fines** and **Silver
prize** blocks on a published tournament's page, but not its review, publishing
or the catalogue.

**Fine rules** (in the tournament catalogue, amounts in millions of Silver):

| Mode | Fine |
|---|---|
| None | No fine |
| Fixed | Everyone who missed the goal owes the amount |
| Proportional | The amount × the share of the goal that was missing (rounded up) |
| Tiers | Ranges of the share of the goal reached, each with its amount |

> **Proportional:** fine 500, Bob reached 60% of the goal → owes 40% = **200**.
>
> **Tiers:** 0–10% → 1,000 · 10–50% → 500 · 50–100% → 100. A range includes its
> start, so exactly 10% falls in 10–50% and owes **500**.

**Flow:** when a tournament is published, the fines are listed as **pending**. The
administrator **waives** whom they choose and **charges** the rest. A charge is a
withdrawal of 0 with the fine as fee: the player's balance drops by the fine, even
below zero, and the fine joins the clan's fees. The player clears the debt by
depositing. A charged fine can be **refunded**; the debt of a player who left can
be **given up as lost**.

> Bob is fined 500 with a balance of 0: his balance becomes −500. He sends 500
> Silver in the game, the deposit is approved, his balance returns to 0 and the
> clan's fees keep the 500.

**Silver prizes** work the same way in reverse: Silver prize lines are listed as
pending when the tournament is published, and the administrator **credits** them
(an approved deposit with no fee), leaves someone out, or takes a credit back.
Nothing touches a balance without an administrator's action.

#### Pending Tournaments

Tournament types the collector found but nobody could name, with the picture of
their Journal page. Type the name once: it is used for every tournament of that
code from then on, and their rankings are released.

#### Event Banners

The default artwork beside the goals on the scoreboard: one for when an event is
running, one for when nothing is. PNG, JPEG, GIF or WebP up to 3 MB, around
640 × 160 pixels.

### System

#### Configs

Three tabs: **General**, **Bank** and **Chests**.

| Parameter | Meaning |
|---|---|
| `clan_name`, `clan_acronym`, `kingdom_number` | The clan's identity |
| `reference_day` | A moment (UTC) on which a cycle started |
| `every_how_many_days` | Cycle length in days |
| `minimum_chest_score`, `minimum_epic_chest_score` | Global goals (total and epic) |
| `chest_goal_mode` | `global` (one goal) or `guard` (a table by G1–G9 / T9; a blank level uses the global goal) |
| `goal_penalty_enabled`, `goal_penalty_percent`, `goal_penalty_target` | The automatic goal penalty (`total`, `epic` or `both`) |
| `score_color_*` | Scoreboard colours (RGB start/end, transition point 0–1) |
| `bank_function` | 1 = bank on |
| `deposit_fee`, `withdrawal_fee`, `transfer_fee` | Fixed fees, in millions |
| `caravan_fee` | Percentage taken on a caravan deposit (replaces the deposit fee) |
| `calculator_function` | 1 = Tools menu on |
| `clan_data_function` | 1 = Clan Data public |
| `collected_chests_retention_days` | Days chests are kept (more than 7; 0 = never purge) |
| `database_backup_*` | The nightly backup (set in Maintenance) |

> Purged chests are gone, but the **cycle summaries** keep each player's results,
> so the history stays.

#### Branding

The logo and the favicon, chosen separately from ten emblems (dragons, swords,
helms, castles, ...) or uploaded. Uploads are checked and converted:

| | Logo | Favicon |
|---|---|---|
| Formats | PNG, JPEG, GIF, WebP | PNG, JPEG, GIF, WebP, ICO |
| Size | 128×128 to 2048×2048, up to 2 MB | 32×32 to 512×512, up to 512 KB |
| Shape | square, up to 3× wider than tall | square (±10%) |

#### Theme

Four colour schemes for everyone: **Morning Light** (default), **Deepest Dark**,
**Wildflowers** and **Twilight**.

#### Monitoring

The state of the collector, the maintenance, the backup and the tournament
uploads; the last 50 runs; each job's limit; the key for `/api/v1/health` and the
Google Apps Script that e-mails you when a job stops. A run with zero chests still
counts as a good run. See [INSTALL.md](INSTALL.md#monitoring-the-jobs).

#### Maintenance

Whether the daily maintenance is scheduled, installing it in the crontab, the
backup settings and the last output. See
[INSTALL.md](INSTALL.md#scheduled-jobs-cron).

#### Notifications

Alerts for administrators, also shown as a red mark on the user menu. The main
one: **two active members whose names the chest counter cannot tell apart**
(`ALOK BR` and `Alok BR`, or names that differ only in spaces or accents). Since
a chest carries only the player's name, their chests would be mixed. Rename one,
or mark the alert as read to say "I know".

#### API Tokens

Personal tokens the collector uses to send members and tournaments. Shown once at
creation; only a hash is kept. Only active administrators' tokens work. Revoking a
token stops the collector using it at once.

---

## 5. Routines and examples

### Every day (administrator, ~5 minutes)

1. **Monitoring**: everything green? If the collector is down, check the PC.
2. **Bank Approvals**: approve the deposits whose Silver arrived; pay and approve
   withdrawals.
3. **Manage Events**: review and publish the tournaments waiting for review.
4. **Tournament Fines** / Silver prizes: charge or waive the pending fines, credit
   the prizes.
5. **Incomplete Chests**, **New Chests** and **Pending Tournaments**: clear the
   badges.

### At the end of a cycle

1. The maintenance archives the cycle by itself (check **Summary last cycles**).
2. **Goal Penalties**: release whoever had a valid excuse (it can be done for the
   current and the previous cycle).
3. **Member Performance**: who is consistently below the goal.

### Setting up goals by guard level

1. **Configs › Chests**: `chest_goal_mode = guard`.
2. Fill the table, e.g. G1–G4: 8,000 · G5–G6: 12,000 · G7–G8: 15,000 ·
   G9/T9: 20,000. Leave a level blank to use the global goal.
3. Keep the troop levels in **Members › List** up to date: a member at G0 gets the
   highest goal (20,000 here).

### Running a clan event

> *"Crypt week": most epic monster chests from Monday 17:00 to Sunday 17:00 UTC,
> goal 30 chests required, prize 100 artifacts split proportionally.*

1. **New Event** → criterion *Number of epic monster chests*, window Mon 17:00 –
   Sun 17:00 UTC.
2. Goal 30, *required*. Prize: 100 artifacts, proportional.
3. During the week the scoreboard shows the live standings and projected prizes.
4. When the window closes, the maintenance records the result. Hand out the
   artifacts in the game as listed.

### A new player joins mid-cycle

The collector's daily Members list makes them active. Their first cycle never
counts as a miss for the goal penalty. Link them to a user if they will use the
bank, and fill in their troop levels.

### A player changes their name in the game

Nothing to do: the next Members list finds the same game id under a new name and
moves their chests, mappings, summaries and penalties to it.

---

## 6. Frequently asked questions

**The scoreboard has not changed for hours.**
Look at *Last chest update* and **Monitoring**. If the collector is down, read the
RUN SUMMARY on the collector PC. If the collector is fine, maybe nobody opened
gifts: a run with no chests is normal.

**A player says a chest is missing.**
Check **Incomplete Chests** (missing name) and **New Chests** (type without a
score, counted as 0). Chests are counted by the time the game generated them, so
a chest from just before the reset belongs to the previous cycle.

**Why did a player get the highest goal?**
Their guard level is G0 (troop levels not filled in) or they have no member
record. Fix it in **Members › List**.

**A tournament did not show up.**
Check **Pending Tournaments** (unnamed type) and **Manage Events** (waiting for
review). The tournament module runs once a day, after the game's day starts.

**Can I change a prize or a fine after publishing?**
Unpublish, change, recalculate and publish again. Amounts already charged or
credited stay until you refund or take them back.

**Does the site send Silver or items to players?**
No. The site calculates and records; the delivery is done in the game.

---

## 7. Appendix: collector API

For integrators. All endpoints are under `/api/v1`, answer JSON and need an API
token sent as `Authorization: Bearer cct_...` (and/or `X-Api-Token: cct_...`).

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/me` | Check the token |
| `POST` | `/tournaments` | Register a tournament (if new) and send its ranking |
| `GET` | `/tournaments/known` | Catalogue: type, name, duration, last rewards |
| `POST` | `/tournament-catalog` | Names (and images) of tournament types |
| `GET` | `/events/awaiting` | Hand-made game events waiting for a ranking |
| `POST` | `/events/{id}/imports` | Send a ranking to a hand-made game event |
| `POST` | `/members` | The clan's Members list |
| `POST` | `/clan-results` | The clan's place and prizes in each tournament |
| `POST` | `/live-tournaments` | The game's Tournaments window |
| `GET` | `/health` | Job status (header `X-Monitor-Key`, not a token) |

`POST /api/v1/tournaments`:

```json
{
  "result_uid": "eede1d1c-3f13-481a-819d-51fdcebb1e54",
  "tournament_key": "1024:1",
  "name": "Rise of the Ancients",
  "ended_at": "2026-09-12T17:00:26Z",
  "capture_method": "packet",
  "client_version": "1.0.0",
  "rows": [
    {"position": 1, "name": "Naughtius Maximus", "points": 1703103642, "player_id": 300647954111}
  ]
}
```

`result_uid` makes a second send reach the same event. Answers: `201` new event or
draft, `200` identical to what is there, `401` bad token, `403` not an
administrator, `409` already published, `422` invalid data (with the reason per
field). The answer includes `review_url`.

`POST /api/v1/members`:

```json
{
  "clan": {"id": 936302870583, "tag": "LPL", "name": "LION PRIDE LEGION"},
  "read_at": "2026-10-11T13:09:05Z",
  "client_version": "1.0.0",
  "members": [
    {"player_id": 644245120340, "name": "Alok BR", "power": 1660747555}
  ]
}
```

Every player listed becomes active (created, linked, renamed, power updated);
every member not listed becomes inactive. At least 5 and at most 500 players.
`409` with a `reason` changes nothing: `older` (a newer list or tournament was
already applied) or `other_clan` (the clan id differs from the last list, so a
profile pointed at the wrong site cannot empty this clan).
