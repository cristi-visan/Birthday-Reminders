# 🎂 Birthdays

Personal birthday tracker at **birthdays.codefor.ge**. You can add people in the web UI, or let Claude read and edit the list over MCP. A Slack DM reminds you 7 days before each birthday and again on the day.

- **Front end:** vanilla JS, no build step.
- **Back end:** plain PHP 8 with no dependencies, so it runs on any cPanel host.
- **Storage:** a JSON document store in `data/`, one document per person, with daily backups kept for 30 days.
- **MCP:** Streamable HTTP at `/mcp`, protected by OAuth 2.1 (dynamic client registration and PKCE).
- **Deploy:** every push to `main` is linted, tested, and uploaded over FTP by GitHub Actions.

---

## One-time setup

### 1. Let GitHub deploy over FTP

Open the repo and go to **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Value |
|---|---|
| `FTP_SERVER` | e.g. `ftp.codefor.ge` |
| `FTP_USERNAME` | the cPanel FTP account |
| `FTP_PASSWORD` | its password |
| `FTP_SERVER_DIR` | the subdomain's folder relative to the FTP root, ending in `/` (e.g. `birthdays.codefor.ge/`). Skip it if the FTP account opens directly in that folder. |

The deploy uses FTPS by default. If your host only allows plain FTP, add a **variable** (not a secret) called `FTP_PROTOCOL` with the value `ftp`.

Next, open **Actions → Deploy to cPanel (FTP) → Run workflow** to do the first upload. After that, every push deploys only the files that changed.

The deploy never touches `config.php` or `data/`.

### 2. Create `config.php` on the server

1. In cPanel **File Manager**, open the subdomain folder.
2. Copy `config.example.php` to `config.php`.
3. Edit these settings:
   - **`password`:** used for the web login and for approving Claude.
   - **`data_dir`:** best placed outside the web root, e.g. `/home/CPANELUSER/birthdays-data`. The default `data/` folder is blocked from the web, but outside is safer.
   - **Slack settings:** fill these in after step 3.

In **MultiPHP Manager**, make sure the subdomain runs PHP 8.0 or newer.

### 3. Slack bot for reminders

1. Go to api.slack.com/apps → **Create New App** → **From scratch**, name it "Birthdays", and choose the codeforge workspace.
2. Under **OAuth & Permissions → Bot Token Scopes**, add `chat:write`.
3. Click **Install to Workspace** and copy the **Bot User OAuth Token** (`xoxb-…`) into `slack_bot_token`.
4. Under **App Home**, tick **Messages Tab** so the DMs appear in the app's chat.
5. In Slack, open your own profile → **⋯ → Copy member ID** (`U…`) and put it in `slack_user_id`.

To check it works, open the web UI → **Settings → Send test message**.

### 4. Daily cron job

In cPanel go to **Cron Jobs → Add New Cron Job**, set it to run **once per day**, and use this command:

```
/usr/local/bin/php /home/CPANELUSER/birthdays.codefor.ge/cron/remind.php > /dev/null
```

- Replace `CPANELUSER` and the folder with your real path. File Manager shows the full path.
- Cron uses the **server's** clock, which may not be on Bucharest time. Compare the time shown on the Cron Jobs page and pick the hour that gives you 09:00 locally.
- If the server misses a day, the next run catches up, and a reminder is never sent twice.

Optional checks:
- `remind.php --dry-run` prints what would be sent without sending anything.
- `remind.php --test` sends a test DM.

### 5. Connect Claude

In Claude:

1. Go to **Settings → Connectors → Add custom connector**.
2. Name it **Birthdays** and set the URL to `https://birthdays.codefor.ge/mcp`.
3. Click **Connect**, enter your password on the approval screen, and click **Allow**.

For Claude Code, run:

```
claude mcp add --transport http birthdays https://birthdays.codefor.ge/mcp
```

To revoke access, use **Settings → Disconnect all** in the web UI.

---

## MCP tools

| Tool | What it does |
|---|---|
| `upcoming_birthdays` | Next N days, default 30, soonest first. |
| `list_birthdays` | Everyone, optionally filtered by `query`, `tag` or `month`. |
| `get_birthday` | One person by id. |
| `add_birthday` | Adds a person. Refuses exact duplicates and flags similar names. |
| `update_birthday` | Changes only the fields you pass. `year: null` clears the year. |
| `delete_birthday` | Removes a person. Claude is told to confirm with you first. |

Each person has `name`, `day`, `month`, an optional `year` (used to show their age), `relation`, `tags`, `notes` and `gift_ideas`. Birthdays on 29 February fall on 28 February in non-leap years.

## Local development

```
cp config.example.php config.php        # set password, base_url = http://localhost:8080
php -S localhost:8080 -t . tests/router.php
php tests/run.php                       # smoke tests (also run in CI)
```

## Layout

```
index.html, assets/      web UI (vanilla JS)
api.php                  JSON API for the web UI (session login)
mcp.php                  MCP endpoint (bearer token)
oauth.php                OAuth discovery, registration, consent screen, tokens
cron/remind.php          daily Slack reminder (CLI only)
src/                     Store, Birthdays, Auth, OAuth, Mcp, Slack, Reminders
.htaccess                routing + blocks src/, cron/, data/, config.php
```
