# Updating dPanel from the panel

**System → Updates** (admins only) shows the running version, the newest
release, and what changed between them. From there you can update dPanel or
roll an update back. It uses the same path as the command line:
`installer.sh update` for the chosen version (see
[Versions and updates](installation.md#versions-and-updates)).

## What the page shows

- **Running**: `APP_VERSION`, `DPANEL_RELEASE_REF` and `DPANEL_RELEASE_COMMIT`
  from `/var/www/dpanel/.env`, which every install and update writes.
- **Newest release**: the highest version tag (`v1.2.3`) of the GitHub
  repository dPanel installs from: `mdsazzad0002/dpanel`, or a fork set as
  `DPANEL_REPO` in `.env`. The answer is kept for 30 minutes; **Check again**
  asks GitHub at once.
- **What changes**: the commits between the running commit and the newest
  release, from GitHub's compare.
- **History**: every update and rollback started from the panel, with who
  started it, the versions, the result and the log.

## Updating

Pick a release tag (the newest is selected), `main`, or type another tag,
branch or commit, then press **Update now**. drust runs the update as its own
systemd unit (`dpanel-update-<id>`), so it keeps going while the panel and
drust restart. The page follows the steps and the log; it may not answer for
a minute or two while the panel restarts.

1. **Backup**: the panel's `.env`, its database (`mysqldump`, or a copy of a
   SQLite file), and the code of `dpanel`, `drust` and `dscript` without
   `vendor`, `node_modules`, `storage`, `public/build` and `drust/target*`.
   If the backup fails, nothing is changed.
2. **Install**: downloads `installer.sh` of the chosen version and runs it
   with `update`, without prompts.
3. **Check**: `php artisan migrate --force` succeeds, no migration is pending,
   the frontend was rebuilt (`public/build/manifest.json` is new), and
   `drust.service` runs.

If step 2 or 3 fails, the backup is put back at once (code, `.env`,
database), `dpanel chain update` runs on it, and the run ends as
**Failed, undone**. Admins get a notification with the result.

Only one update runs at a time. A server that runs dPanel from a git checkout
(`/var/www/.git` exists) is refused; update it with `git pull` and
`sudo dpanel chain update`.

## Rolling back

**Roll back** on a finished update puts back the backup taken before it: the
code and `.env` are restored, files the newer version added are removed, and
`dpanel chain update` runs on the restored code. A backup of the current state
is taken first, so a rollback can itself be rolled back the same way by hand.

**Also restore the database backup** is on by default. The database goes back
to the moment the update started, and everything changed in the panel since
then is lost (websites, mailboxes, DNS records, users). Turn it off only when
the newer version did not change the database, or the older code may fail.

The newest three backups are kept.

## Where things are

| What | Where |
| --- | --- |
| Run state and log | `/var/lib/drust/panel-update/<id>/state`, `.../log` |
| Backup | `/var/lib/drust/panel-update/<id>/backup/` (`env`, `database.sql.gz` or `database.sqlite`, `code.tar.gz`) |
| The runner | `/var/www/dscript/scripts/panel-update.sh` |

## Restoring a backup by hand

If a run ends as **Failed** (the automatic restore failed too), the message
names its backup folder. As root:

```bash
cd /var/lib/drust/panel-update/<id>/backup
tar -xzf code.tar.gz -C /var/www          # dpanel, drust, dscript
cp env /var/www/dpanel/.env
# MariaDB: drop the panel's tables first if the newer version added any
gunzip -c database.sql.gz | mysql <database>
sudo dpanel chain update
```

Run the same thing with `panel-update.sh` to get the checks:

```bash
mkdir /var/lib/drust/panel-update/manual
sudo bash /var/www/dscript/scripts/panel-update.sh rollback \
  --run-dir /var/lib/drust/panel-update/manual --root /var/www \
  --from /var/lib/drust/panel-update/<id>/backup
```

The log is in `/var/lib/drust/panel-update/manual/log`.
