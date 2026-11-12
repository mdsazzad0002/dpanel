# Moving between two dPanels without a backup file

**Migrate → DPanel to DPanel → Live connection** links this panel to another
dPanel. You can then take websites (or the whole panel) from it, or send them
to it, without downloading a backup and uploading it again. A later **sync**
moves only the files that changed.

Only admins can use it.

## Linking two panels

1. On the **other** panel, open Profile menu → **API Tokens** and make a token
   with the **Live migration** scope:
   - **Read**: this panel may *take* websites and the panel backup from it.
   - **Read and change**: it may also *send* them there.
2. On **this** panel, open Migrate → DPanel to DPanel, press **Link another
   panel**, and enter the other panel's address (such as
   `https://panel.example.com:2083`) and the token.

The panel checks the token at once and shows what it allows: **Take**,
**Send**, or both. Only this panel calls the other one, so this panel can be a
local machine with no public address, and it can still both take and send.
Link from both sides only if you also want to start transfers from the other
panel.

## Websites

Pick websites in **Take from …** (the other panel's list) or **Send to …**
(this panel's list).

- **Copy** moves the files and the website's database. A website missing on
  the receiving panel is created there, with PHP version, main/subdomain and
  owner. The owner is a user there with the same email, or the user you choose,
  or else the admin. A website that already exists keeps its settings; its
  current files and database go to its `.trash` first.
- **Sync changed files** compares both sides' file lists (path, size, SHA-256;
  `.gitignore` applies, `.git` is left out) and moves only files that differ.
  The database is not touched. Tick "also remove files…" to delete files the
  sending side no longer has. Replaced and removed files go to `.trash`.
- **Sync again** on a finished transfer runs the file sync once more.

A subdomain waits until its main website has been copied. Aliases, Node.js,
Python and Docker websites are not copied: create the app on the other panel,
then use **Sync changed files**.

New websites start with SSL off, because DNS still points at the old server.
Turn SSL on once DNS points at the new server.

## The whole panel

**Clone the whole panel** moves the same full backup that
`serverpanel:backup` makes (the `…_dpanel.tar.gz` the upload form takes):

- **Take**: the other panel makes the backup, this panel downloads it, and it
  appears in this page's backup list. Restoring it (type `RESTORE`) replaces
  this panel's `.env`, data and panel database.
- **Send**: this panel makes the backup and uploads it. Restore it on the
  other panel, from its own Migrate → DPanel to DPanel page.

Making the backup also runs that panel's normal backup clean-up and, when it is
turned on, its remote backup upload.

## How it works

- The other panel answers at `/api/v1/migration/…` (see the API document at
  `/api/v1/openapi`, tag *Migration*).
- Packages go in 5 MB chunks, each checked with SHA-256, and the whole package
  is checked again at the end. A dropped chunk is sent again; progress is
  saved after every chunk, so a transfer that outlasts the worker's time limit
  carries on where it stopped.
- The jobs run on the `heavy` queue on both panels.
- Unfinished packages are removed after a day (`panel-link:prune`, hourly).

## Not moved yet

- Mailboxes and their mail, and DNS zones. The website list shows how many
  mailboxes a website has; move them separately for now.
- Cron jobs, FTP accounts, and a website's second or later database (only the
  website's own database goes with **Copy**).
