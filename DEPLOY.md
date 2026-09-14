# Deploy to cPanel

The short version, written for the download-the-zip-and-extract workflow. The reasoning behind each
step, and the parts specific to unusual hosts, are in [docs/deploy.md](docs/deploy.md).

---

## The one rule

**`public/` is the only folder a browser may reach. `app/` must never be web-reachable.**

`app/` holds the poller, the scoring layer and the config loader. If a browser can request it, your
API key and database password are one URL away. Everything below is arranged around that.

---

## 1. Extract

Download the repo zip, upload it to cPanel File Manager, and extract it **outside `public_html`** —
in your home directory is right.

```
/home/USER/
├── divergence/          ← extracted here. Everything lives in this one folder.
│   ├── app/
│   ├── public/
│   ├── docs/
│   └── tests/
└── public_html/         ← untouched for now
```

GitHub's zip extracts into a folder named `coinmarketcap-divergence-main`. Rename it to
`divergence` so the paths below match.

## 2. Delete what the host does not need

Safe to delete after extracting — neither is used at runtime:

| Folder | Why it can go |
|---|---|
| `docs/` | documentation, including `schema.sql`. It is on GitHub. |
| `tests/` | only useful if you want to run `php tests/run.php` on the host to diagnose a PHP version difference. Keeping it costs 720KB. |

Also delete, if your extraction produced them: `.git/`, `.github/`, `README.md`, `CLAUDE.md`,
`DEPLOY.md`, `LICENSE`. None are read at runtime.

**Keep `app/` and `public/`.** That is the whole application.

## 3. Point the document root at `public/`

The clean way, and the one that needs no file edits: **add a subdomain** in cPanel and set its
document root to `/home/USER/divergence/public`.

If you must use a domain locked to `public_html`, move the *contents* of `public/` into
`public_html/`, leave `app/` where it is, and edit the eight `require_once` lines at the top of
`public_html/bootstrap.php` from `__DIR__ . '/../app/lib/...'` to the real path, e.g.
`'/home/USER/divergence/app/lib/...'`.

Never move `app/` into `public_html`.

## 4. The database

Already done if you have run `docs/schema.sql`. Otherwise: phpMyAdmin → select your database → SQL
tab → paste the contents of `docs/schema.sql` → Go. It creates seven tables and is safe to re-run.

## 5. The config file

Rename `config.example.php` to **`config.php`** and leave it where it is — in the repo root, beside
`app/` and `public/`:

```
/home/USER/site/
├── config.php          ← here. chmod 600.
├── app/
└── public/             ← the document root
```

It is not web-reachable there: the document root is `public/`, one level below it. Nothing above
`public/` is served.

Fill in the API key, and the database name, user and password. Then set permissions to **600** in
File Manager — **not 644**. On shared hosting 644 lets other accounts on the same server read your
key and database password.

If you would rather keep the config outside the repo folder entirely — worth it when you replace the
whole folder on each update — put it one directory above instead. The loader checks there first.

**Never put it inside `public/`.** The app refuses to start if it finds it there, and tells you to
rotate the key.

## 6. Check the host can do the job

Over SSH, or through cPanel's Terminal:

```bash
php /home/USER/divergence/app/bin/preflight.php
```

This is the step that catches the things nothing else can: whether PHP CLI can reach
`pro-api.coinmarketcap.com` (some hosts firewall CLI separately from the web), whether the database
grants are right, and whether the config is somewhere safe.

Find the right PHP binary first — cron needs the absolute path and it is often **not** the one the
web server uses:

```bash
which php
```

## 7. Record one sample by hand

```bash
php /home/USER/divergence/app/poller/run.php --once
php /home/USER/divergence/app/bin/extract.php --verbose
php /home/USER/divergence/app/bin/score.php --verbose
```

If those three succeed, open the site. It will show a single point.

## 8. Cron

cPanel → Cron Jobs. Four entries. Replace `/usr/local/bin/php` with whatever `which php` printed.

Write them to a file and install from it, rather than editing the crontab in place. A crontab is
one command away from being empty, and `crontab -l | ... | crontab -` in a single pipeline can
truncate it before the read completes:

```bash
cat > ~/divergence-crontab.txt <<'CRON'
MAILTO=""
PATH=/usr/local/bin:/usr/bin:/bin

1-59/15 * * * *  php /home/USER/divergence/app/poller/run.php --market >> /home/USER/divergence/logs/cron.log 2>&1
11-59/15 * * * * php /home/USER/divergence/app/poller/run.php --assets >> /home/USER/divergence/logs/cron.log 2>&1
6-59/15 * * * *  php /home/USER/divergence/app/bin/extract.php --quiet --limit=2000 >> /home/USER/divergence/logs/cron.log 2>&1
9-59/15 * * * *  php /home/USER/divergence/app/bin/score.php --quiet --limit=500 >> /home/USER/divergence/logs/cron.log 2>&1
CRON

crontab ~/divergence-crontab.txt && crontab -l
```

Keeping the file means the crontab can always be restored in one command, and changing it later is
an edit plus a reinstall rather than a live edit of the only copy.

**Why 15-minute schedules with odd offsets rather than `*/15`.** Shared hosts often enforce a
minimum interval and rewrite anything faster — ours replaced `*/5` with `*/15`, then redistributed
to `1-59/15` and `11-59/15` so the two pollers do not start in the same minute, appending a comment
to the crontab each time it did so. Writing it in the form the host already accepts stops it
rewriting, and staggering the pollers is good practice anyway on an account with a process limit.

If your host has no such monitor, `*/15` and `*/15` work identically.

Everything stays inside the deployment folder. `logs/` is created on the first run, so there is
nothing to make by hand — but note the cron redirect is the shell's, not the app's, so the very
first cron tick needs the directory to exist. Running step 7 by hand first creates it.

**The PHP binary matters.** Cron's `PATH` is not a login shell's, and the CLI binary is often not
the one the web server uses — on CloudLinux hosts it is typically `/opt/alt/php83/usr/bin/php`.
Use whatever `which php` printed in step 6.

**The cadence is not set here.** Each endpoint carries its own interval in `app/lib/endpoints.php`;
these entries are just a cheap tick that decides what is due. Most ticks fetch nothing and cost
nothing. 356 credits a day against a 15,000/month plan — see the arithmetic in D23.

**Why all four run every fifteen minutes.** Extraction and scoring used to run three and two times
an hour against a poller sampling four times an hour, which meant the front page could show a score
up to half an hour old while fresher samples sat unread in the database. Neither job costs a credit
or makes a network call — they are pure derivations from what is already on disk — so there was
never a budget reason for them to run slower than the thing they derive from. The offsets keep the
original ordering: poll at :01 and :11, extract at :06, score at :09, each far enough behind the one
it depends on that a slow run cannot overtake it, and none of them able to delay a fetch. That
separation is the rule the whole schedule exists to protect.

**Your host may rewrite these.** Ours enforces a 15-minute minimum and silently rewrote `*/5` to
`*/15` with a per-job offset. That is fine — with per-endpoint cadence a slower tick still delivers
every endpoint's declared interval, as long as the tick is no slower than the shortest one (15
minutes). If your host's floor is higher than that, lower the tick expectation rather than raising
the intervals: check `crontab -l` after saving to see what actually got installed.

## 9. Confirm

Twenty minutes later:

```bash
php /home/USER/divergence/app/bin/health.php
```

It exits non-zero if nothing has landed recently, so it also works as a watchdog. Then open the
site — the trail starts building from here.

---

## Updating later

Re-download the zip, extract, and replace `app/` and `public/`. Nothing in either folder holds
state: everything is in the database and the config file, and both sit outside them.

If a scoring weight changed, run `php app/bin/score.php --rebuild` afterwards — the whole history is
rescored from payloads already stored.
