# Fleet Sync Service and Schema Migrations

This repository now has two separate database systems:

1. Data synchronization: copies changed production data from the VPS database to localhost.
2. Schema migrations: applies local development schema changes to the VPS database.

The systems intentionally move in opposite directions and must not be mixed.

## Architecture

Data sync lives in `fleet_sync_service/`.

- `sync_data.py`: CLI entry point.
- `config.py`: reads and validates `config.ini`.
- `database.py`: MySQL/MariaDB connection and SQL safety helpers.
- `tunnel.py`: optional SSH tunnel for VPS MySQL access.
- `validation.py`: table validation before sync.
- `state_store.py`: reads and writes `state/last_sync.json`.
- `sync_engine.py`: incremental insert/update engine.
- `logging_setup.py`: rotating file and console logs.
- `logs/`: runtime sync logs.
- `state/`: runtime sync state.

Schema migrations live at repository level.

- `run_migrations.py`: CLI migration runner.
- `database/migrations/`: forward-only SQL migration files.
- `schema_migrations`: production metadata table created by the runner.

Existing SQL files in `database/update_*.sql` are legacy/manual scripts. The migration runner executes only `database/migrations/*.sql`.

## Installation

Install Python 3.10 or newer, then install dependencies:

```powershell
cd C:\laragon\www\aplicatie_fleet
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r fleet_sync_service\requirements.txt
```

Copy the example config:

```powershell
Copy-Item fleet_sync_service\config.example.ini fleet_sync_service\config.ini
```

Edit `fleet_sync_service/config.ini` and fill in:

- `[vps_database]`: production VPS MySQL/MariaDB credentials.
- `[ssh_tunnel]`: optional SSH forwarding to reach MySQL on the VPS.
- `[local_database]`: localhost development database credentials.
- `[sync] tables`: comma-separated tables to copy from VPS to localhost.
- `[logging]`: log level and file rotation.
- `[migrations]`: migration folder, migration log file, and history table name.

`config.ini` is ignored by Git and must stay private.

### SSH Tunnel for VPS MySQL

If the VPS MySQL port `3306` is not open publicly, enable the SSH tunnel. This matches a common MySQL Workbench setup where SSH connects to the VPS, then MySQL is reached on the VPS itself at `127.0.0.1:3306`.

```ini
[ssh_tunnel]
enabled = true
host = VPS_IP
port = 22
user = root
password =
private_key =
remote_bind_host = 127.0.0.1
remote_bind_port = 3306
local_bind_host = 127.0.0.1
local_bind_port = 3307
```

When `enabled=true`, the sync service opens:

```text
local 127.0.0.1:3307 -> SSH VPS -> VPS 127.0.0.1:3306
```

Then the VPS MySQL connection is made through `127.0.0.1:3307`. The local development database connection remains unchanged.

Use either `password` or `private_key`. Leave both empty only if your SSH agent or default SSH keys can authenticate.

## Data Synchronization

Direction:

```text
Production VPS database -> Localhost development database
```

The VPS database is read only. The sync engine writes only to the local database and only uses `INSERT` and `UPDATE`.

For every configured table, the service:

1. Validates that the table exists on both databases.
2. Validates that both databases have the same primary key.
3. Uses `updated_at` as the incremental timestamp when available.
4. Falls back to `created_at` when `updated_at` is missing.
5. Reads VPS rows newer than the last successful timestamp.
6. Inserts missing rows locally.
7. Updates only changed local columns.
8. Never deletes, truncates, drops, or recreates tables.

The sync state is stored per table in:

```text
fleet_sync_service/state/last_sync.json
```

Dry-run does not write data and does not update the state file.

### Sync Commands

Run a normal sync:

```powershell
cd C:\laragon\www\aplicatie_fleet
python fleet_sync_service\sync_data.py
```

Run without writing local data:

```powershell
python fleet_sync_service\sync_data.py --dry-run
```

Test both database connections:

```powershell
python fleet_sync_service\sync_data.py --test-connections
```

With SSH tunneling enabled, this command tests in order:

1. SSH tunnel startup.
2. VPS MySQL through the tunnel.
3. Localhost MySQL directly.

From inside `fleet_sync_service/`, these also work:

```powershell
python sync_data.py
python sync_data.py --dry-run
python sync_data.py --test-connections
```

### Windows Task Scheduler

Create a task that runs every 5 minutes.

Recommended settings:

- Program: `C:\laragon\www\aplicatie_fleet\.venv\Scripts\python.exe`
- Arguments: `C:\laragon\www\aplicatie_fleet\fleet_sync_service\sync_data.py`
- Start in: `C:\laragon\www\aplicatie_fleet`
- Trigger: daily, repeat every 5 minutes, indefinitely.
- Run whether user is logged on or not.

Before enabling the task, run:

```powershell
python fleet_sync_service\sync_data.py --test-connections
python fleet_sync_service\sync_data.py --dry-run
python fleet_sync_service\sync_data.py
```

Logs are written to `fleet_sync_service/logs/sync_data.log`.

## Schema Migrations

Direction:

```text
Local development schema -> Production VPS schema
```

Use migrations when local development adds or changes schema: new tables, new columns, indexes, or safe data backfills. Do not import full SQL dumps into production.

Each schema change gets a new SQL file:

```text
database/migrations/001_create_documente_table.sql
database/migrations/002_add_warranty_columns.sql
database/migrations/003_add_created_by.sql
database/migrations/004_create_notifications.sql
```

Never modify an old migration after it has been applied. The runner stores a SHA-256 checksum and stops if an applied file changes.

### Migration History

The runner creates this production table automatically:

```sql
schema_migrations (
    id,
    migration_name,
    applied_at,
    checksum,
    duration_ms,
    status
)
```

Only missing migrations are executed. If a migration fails, the runner records the failure and stops immediately.

### Migration Commands

Show status:

```powershell
python run_migrations.py --status
```

Preview pending migrations:

```powershell
python run_migrations.py --dry-run
```

Apply pending migrations to the VPS database:

```powershell
python run_migrations.py --apply
```

Migration logs are written to the file configured by `[migrations] log_file`, usually `database/migrations/migrations.log`.

### Transaction Marker

MySQL/MariaDB DDL often commits implicitly. If a migration is safe to run inside a transaction, add this marker at the top:

```sql
-- @transaction
ALTER TABLE vehicule ADD COLUMN warranty_expires_at DATE NULL;
```

If a marked migration fails, the runner calls rollback. For unmarked migrations, the runner stops and records failure but does not promise rollback.

### Migration Safety Rules

The runner blocks:

- `DROP DATABASE`
- `DROP SCHEMA`
- `DROP TABLE`
- `TRUNCATE`
- `DELETE FROM`

If `ALTER TABLE ... DROP COLUMN` is detected, the runner prints a warning and requires interactive confirmation by typing:

```text
EXECUTE_DROP_COLUMN
```

This protects production data from accidental destructive schema changes.

## Creating Migrations

When you add a page or feature that requires schema changes:

1. Create the PHP/controller/model/view changes locally.
2. Create the next numbered SQL file under `database/migrations/`.
3. Keep the migration focused on one schema change.
4. Make it idempotent where practical, for example with `CREATE TABLE IF NOT EXISTS`.
5. Run `python run_migrations.py --dry-run`.
6. Commit the PHP code and migration file together.
7. Pull the code on the VPS.
8. Run `python run_migrations.py --apply` on the VPS.

Example:

```sql
-- @transaction
ALTER TABLE vehicule
    ADD COLUMN warranty_expires_at DATE NULL,
    ADD COLUMN warranty_provider VARCHAR(150) NULL;
```

Best practice: do not rename or delete columns in the same deployment that reads old data. Add new columns first, deploy code that writes both if needed, backfill safely, then remove old code later.

## Recommended Workflow

```text
Alexandra and the team use production.
Production data changes on the VPS.
Task Scheduler runs every 5 minutes.
Localhost receives only new or modified rows.
You develop locally.
Schema changes are added as new migration files.
You commit code and migrations to GitHub.
The VPS pulls the latest code.
You run python run_migrations.py --apply on the VPS.
Only missing schema changes are applied.
Production data remains intact.
```

## Rollback Recommendations

This version is forward-only. Do not rely on automatic rollback for production DDL.

Before applying migrations on the VPS:

- Take a database backup.
- Run `--dry-run`.
- Review each pending SQL file.
- Prefer additive changes: create tables, add nullable columns, add indexes.
- Avoid destructive changes. If absolutely needed, create a manual backup and maintenance plan.

For failed untransactional migrations, inspect production manually before retrying. Some statements may already have applied.

## Troubleshooting

Connection failure:

- Run `python fleet_sync_service\sync_data.py --test-connections`.
- If the error starts with `SSH tunnel error`, check SSH host, port, user, password/private key, and firewall rules for port `22`.
- If the error starts with `MySQL error`, check database host, port, username, password, and database name.
- With SSH enabled, remember that VPS MySQL is reached through the local tunnel port, usually `127.0.0.1:3307`.

Table skipped during sync:

- Confirm the table exists on both databases.
- Confirm both databases have the same primary key.
- Add `updated_at` or `created_at` to both schemas.
- Check `fleet_sync_service/logs/sync_data.log`.

Rows not updating:

- Prefer `updated_at` columns that change on every update.
- `created_at` fallback can only detect inserted rows unless your app updates that value.

Migration blocked:

- Remove destructive SQL.
- For `ALTER TABLE DROP COLUMN`, confirm it is intentional and type the required confirmation only during a manual run.

Checksum mismatch:

- An already-applied migration file was edited.
- Restore the original file and create a new migration for additional changes.

## Future Extensions

The sync engine is modular so later versions can add:

- Bidirectional synchronization.
- Conflict resolution.
- Deleted-record synchronization.
- Real-time synchronization.
- WebSocket notifications.
- MySQL binary log replication.
- REST API synchronization.

Those features are intentionally not implemented in this version.
