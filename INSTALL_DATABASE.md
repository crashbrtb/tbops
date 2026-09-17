# Database Installation

This document explains how to install the database for the TBOps application.

## 📋 Overview

The project uses **CakePHP Migrations** to manage the database schema and **Seeds** to populate initial data. This ensures:

- Versioned, repeatable database setup
- Easy rollback if needed
- Consistent environment across development and production
- No manual SQL file imports required

---

## 🚀 Step-by-Step Installation (New Installation)

### 1. Create the Database

```bash
mysql -u root -p -e "CREATE DATABASE tbops CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 2. Configure Database Connection

Edit `config/app_local.php` and set your database credentials:

```php
'Datasources' => [
    'default' => [
        'host' => 'localhost',
        'username' => 'your_user',
        'password' => 'your_password',
        'database' => 'tbops',
    ],
],
```

### 3. Run Migrations (Create Tables)

```bash
php bin/cake.php migrations migrate
```

This will create all 15 application tables with their indexes and foreign keys.

### 4. Run Seeds (Insert Initial Data)

```bash
php bin/cake.php migrations seed
```

This inserts the essential initial data:
- **roles**: 3 roles (admin, user, bankers)
- **config**: 21 configuration parameters
- **standard_chests**: 96 chest types with configured scores

The seed is idempotent per row: it inserts only the records that are still
missing, so it is safe to run again at any time.

### 5. Adjust Your Clan's Settings

The seed ships placeholders. Set them to your own values, either in the
Settings screen or directly in the `config` table:

| Parameter | Placeholder | Meaning |
|-----------|-------------|---------|
| `kingdom_number` | `K001` | Kingdom number |
| `clan_acronym` | `ABC` | Clan acronym |
| `clan_name` | `Special Task Force` | Clan name |
| `reference_day` | `2025-07-30 17:00:00` | Start/end reference of the counting cycle, UTC |

### 6. Verify Installation

```bash
# Check migration status
php bin/cake.php migrations status
```

```sql
-- Verify initial data
SELECT COUNT(*) FROM roles;           -- Should return 3
SELECT COUNT(*) FROM config;          -- Should return 21
SELECT COUNT(*) FROM standard_chests; -- Should return 96
```

### 7. Create the First Administrator

```bash
php bin/cake.php create_admin
```

---

## 🔄 Migrating from SQL Dump (Existing Databases)

If you already have a database created from the old `databasemodel.sql` dump, you need to mark the initial migration as already applied:

```bash
php bin/cake.php migrations mark_migrated 20260822220000
```

This tells CakePHP that the `InitialSchema` migration has already been applied (since the tables already exist), preventing conflicts.

> ⚠️ **Important:** Only run `mark_migrated` if your database was already created from the SQL dump. For new installations, use `migrations migrate` as described above.

---

## 📝 Database Structure

### Tables (19 tables):

| Table | Description |
|-------|-------------|
| `users` | System users |
| `roles` | Roles/permissions (admin, user, bankers) |
| `roles_users` | User-role relationships |
| `members` | Clan members |
| `collected_chests` | Collected chests |
| `standard_chests` | Standard chest types (96 types) |
| `config` | System configuration (21 parameters) |
| `player_cycle_summaries` | Player cycle summaries |
| `player_name_mappings` | Player name mappings |
| `bank_accounts` | Member bank accounts |
| `bank_transactions` | Bank transactions |
| `bank_approval_logs` | Bank approval logs |
| `errors` | Error log |
| `events` | Tournament events: window, criteria, prize and banner |
| `event_chests` | Chest types counted by a custom-chest event |
| `event_standings` | Frozen results, recorded when an event closes |
| `event_assets` | Default banner artwork for the scoreboard |
| `incomplete_chests` | Incomplete chests |
| `troops` | Troop types and their attributes |

### Initial Data (via Seeds):

- **roles**: 3 roles (admin, user, bankers)
- **config**: 21 initial configuration parameters
- **standard_chests**: 96 chest types with configured scores

---

## ⚙️ Useful Migration Commands

```bash
# Check current migration status
php bin/cake.php migrations status

# Run all pending migrations
php bin/cake.php migrations migrate

# Rollback the last migration
php bin/cake.php migrations rollback

# Run all seeds
php bin/cake.php migrations seed

# Run a specific seed
php bin/cake.php migrations seed --seed InitialDataSeed
```

---

## ⚠️ Troubleshooting

### Error: Home page returns HTTP 500 after installing

Check whether the seed actually ran:

```sql
SELECT COUNT(*) FROM config;   -- must return 21
```

If it returns fewer rows, the configuration parameters are missing and the
score page cannot be calculated. Run the seed again:

```bash
php bin/cake.php migrations seed --seed InitialDataSeed
```

The seed inserts only the parameters that are absent, so running it on a
partially populated table is safe.

### Error: "Table already exists"

If migrations fail because tables already exist, mark them as migrated:

```bash
php bin/cake.php migrations mark_migrated 20260822220000
```

### Error: "Access denied"

Verify that the user has permissions:

```sql
-- Grant permissions (as root)
GRANT ALL PRIVILEGES ON tbops.* TO 'your_user'@'localhost';
FLUSH PRIVILEGES;
```

### Error: "Unknown database"

Make sure the database was created before running migrations:

```sql
CREATE DATABASE tbops CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Reset Database (Development Only)

```bash
# Rollback all migrations (WARNING: deletes all data!)
php bin/cake.php migrations rollback -t 0

# Re-run migrations
php bin/cake.php migrations migrate

# Re-seed data
php bin/cake.php migrations seed
```

---

## 🔐 Security

⚠️ **Important:**
- After installation, create the first administrator using:
  ```bash
  php bin/cake.php create_admin
  ```
- Keep database credentials secure in `config/app_local.php`
- Never commit `config/app_local.php` to Git (already in `.gitignore`)

---

## 📚 References

- [CakePHP Migrations Documentation](https://book.cakephp.org/migrations/4/en/index.html)
- [Phinx Documentation](https://phinx.org/)
- [MySQL Documentation](https://dev.mysql.com/doc/)
