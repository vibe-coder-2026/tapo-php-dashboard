# tapo-php-dashboard

A self-hosted web dashboard and API for TP-Link **Tapo** (P110, L530) and **Kasa** (HS110) smart
devices. Built in pure PHP — no Node, no framework, no WebSocket server.

**Features**
- Live dashboard via Server-Sent Events — state pushed to all browsers simultaneously
- Per-device worker processes: plugs never see more than one connection regardless of browser count
- Group cards for controlling multiple devices at once
- Auth via `X-Forwarded-Groups` header (oauth2-proxy / Keycloak)
- `/i3block/stream` endpoint for persistent i3blocks status blocks
- Hourly energy logging to SQLite (Tapo native history, Kasa snapshot diff, bulb daily totals)
- Docker-ready with `tini` for correct PID 1 signal handling

**Requirements:** PHP 8.2+, extensions: `openssl curl json sqlite3 sockets pcntl posix`

---

## Quick start

```sh
# 1. Install dependencies
composer install --no-dev

# 2. Configure
cp .env.example .env && cp devices.example.json devices.json
# edit .env (credentials) and devices.json (your devices)

# 3. Run
php launcher.php --port 9100
# or
docker compose up -d --build
```

Dashboard at `http://<host>:9100/`

---

## Overview

Four process types collaborate:

```
browsers  ──SSE /events──┐
          ──fetch /action─┤
                          ▼
                    Gateway  (pure-PHP HTTP+SSE server, 0.0.0.0:PORT)
                          │ Unix sockets  /tmp/tapo-dash/<id>.sock
             ┌────────────┼────────────┐
             ▼            ▼            ▼
           Worker       Worker       Worker     (one per device)
             │            │            │        owns the ONE connection,
             ▼            ▼            ▼        polls, caches state
          tp-plug-1   tp-kasa-…   tp-bulb-01

                    Collector  (optional — daemon, hourly energy logging to SQLite)
```

`Launcher` forks and supervises all workers + the gateway (+ collector if `DB_PATH` is set),
respawns any that die.

Key invariant: **every device has exactly one worker process**. Browser count is irrelevant to plug
connection count. The plugs enforce a low connection ceiling (~3–4); the worker architecture
prevents hitting it no matter how many browsers are watching.

---

## Files

### Entry points (project root)

| File | Role |
|------|------|
| `launcher.php` | Thin entry — parses `--port`, reads `DB_PATH` from `.env`, runs `Launcher`. |
| `worker.php` | Thin entry — runs `Worker` for the given device id. |
| `gateway.php` | Thin entry — runs `Gateway` on the given port. |
| `collect.php` | Thin entry — runs `Collector` once, or as a daemon with `--daemon`. |

### Classes (`src/`)

| Class | Role |
|-------|------|
| `Launcher` | `pcntl_fork` supervisor. Spawns one `worker.php <id>` per device + `gateway.php` + optionally `collect.php --daemon`. Respawns on exit (1 s backoff on crash-loops). SIGTERM/SIGINT → forward to children, reap, unlink sockets. |
| `Worker` | Long-lived process per device. Binds `unix:///tmp/tapo-dash/<id>.sock`. `stream_select` loop: on timeout (~5 s) re-polls device; on readable, reads one JSON command, acts, re-polls, writes state back. Rebuilds the device object on any error to force a fresh handshake. |
| `Gateway` | Non-blocking `stream_socket_server` loop. Routes HTTP requests, serves SSE, forwards commands to worker sockets. Never connects to a plug directly. |
| `WorkerClient` | Sends one JSON command to a worker socket and returns the state. Used by `Gateway` and `Collector`. |
| `DeviceRegistry` | Loads `devices.json` (memoised). Exposes devices, groups, socket paths, `spoof_groups`, `min_max`. |
| `Auth` | `parseGroupsHeader()`, `canControl()`, `isVisible()`. |
| `Config` | Loads `TP_LINK_TAPO_USER` / `TP_LINK_TAPO_PASS` from `.env`. |
| `Collector` | Hourly energy collection. Tapo: `getEnergyData()` 7-day lookback. Kasa: snapshot diff. Bulbs: `today_energy` daily. Writes to SQLite. |

### Configuration (gitignored)

| File | Role |
|------|------|
| `.env` | `TP_LINK_TAPO_USER`, `TP_LINK_TAPO_PASS`, `DB_PATH` (optional). Copy from `.env.example`. |
| `devices.json` | Device registry, groups, `spoof_groups`, `min_max`. Copy from `devices.example.json`. |
| `energy.db` | SQLite energy log. Created automatically when `DB_PATH` is set. |

---

## devices.json schema

```json
{
  "devices": {
    "<id>": {
      "type":      "tapo|kasa|bulb",
      "host":      "<dns-or-ip>",
      "groups":    ["admin"],
      "is_public": true
    }
  },
  "groups": {
    "<group-name>": ["<device-id>", ...]
  },
  "spoof_groups": ["admin"],
  "min_max": {
    "<device-nickname>": { "min": 10, "max": 200 }
  }
}
```

`spoof_groups` — forces all requests into these groups, bypassing the `X-Forwarded-Groups` header.
**Remove or set to `null` before deploying behind oauth2-proxy.**

`min_max` — keyed by the device's live nickname (set on the device, shown in the dashboard).
Used by `/i3block` and `/i3block/stream` for colour thresholds.

---

## Worker state shape

```json
{
  "id":          "tp-plug-1",
  "type":        "tapo|kasa|bulb",
  "name":        "Living Room",
  "on":          true,
  "power_w":     45.2,
  "brightness":  null,
  "color_temp":  null,
  "online":      true,
  "ts":          1748789000
}
```

`brightness` and `color_temp` are non-null only for bulbs. Tapo power comes from `current_power`
(mW → W); Kasa power comes from `power` (already W).

---

## Worker IPC commands (line-delimited JSON)

| action | extra fields | effect |
|--------|-------------|--------|
| `status` | — | return cached state, no device hit |
| `on` | — | `turnOn()`, re-poll |
| `off` | — | `turnOff()`, re-poll |
| `toggle` | — | `toggleState()`, re-poll |
| `preset` | `brightness`, `color_temp` | bulbs only — `set_device_info`, re-poll |
| `rename` | `name` | Tapo: `set_device_info` with base64 nickname; Kasa: `set_dev_alias`; 2 retries, then re-poll |

---

## Gateway routes

| Route | Auth | Notes |
|-------|------|-------|
| `GET /` | none | dashboard HTML |
| `GET /events` | read: `is_public` or group match | SSE stream; per-client filtered; pushes every `SSE_TICK` (2 s) |
| `GET /action?id&action[&name\|brightness\|color_temp]` | read: visible; mutate: group match | forwards to worker socket; triggers immediate `pushStates()` |
| `GET /group-action?group&action[&brightness\|color_temp]` | mutate: all members controllable | fan-out to each member worker; group toggle resolves all-on→off else→on |
| `GET /i3block?id` | none | one-shot i3blocks JSON (name from device, colour from `min_max`) |
| `GET /i3block/stream?id` | none | persistent NDJSON stream; push on every SSE tick — use with `interval=persist` |

---

## i3blocks integration

```ini
[plug_um790]
command=curl -sN "http://work-vm:9100/i3block/stream?id=tp-plug-10"
interval=persist
format=json
```

Each device card has a `⧉` button that copies the correct config snippet to clipboard.

Response shape:
```json
{ "full_text": "UM790: 45W ", "short_text": "UM790: 45W ", "color": "#00FF00" }
```

Colour logic (requires `min_max` entry keyed by device nickname):
- `current <= min` → `#FFFF00` (yellow — idle)
- `current >= max` → `#FF0000` (red — high load)
- otherwise → `#00FF00` (green)
- no `min_max` entry → always `#00FF00`

Offline: hostname from `devices.json` + ❌ in orange.

---

## Authorization model

Enforced exclusively in `Gateway` from the `X-Forwarded-Groups` header (set by oauth2-proxy /
Keycloak). Workers are auth-agnostic.

```
visible      = is_public  OR  (userGroups ∩ device.groups)
controllable = (userGroups ∩ device.groups)   — requires header AND a match
```

---

## SSE payload shape

```json
{
  "user_groups": ["admin"],
  "groups": [
    {
      "name": "Bulbs", "type": "bulb", "count": 2,
      "online": true, "on": false, "mixed": false,
      "power_w": 0, "brightness": null, "color_temp": null,
      "controllable": true
    }
  ],
  "devices": [
    {
      "id": "tp-plug-1", "type": "tapo", "name": "Living Room",
      "on": true, "power_w": 45.2, "brightness": null, "color_temp": null,
      "online": true, "ts": 1748789000,
      "groups": ["admin"], "is_public": true, "controllable": true
    }
  ]
}
```

`user_groups: null` = header absent (anonymous). `groups` = aggregated group cards. `devices` =
per-user filtered individual device list.

---

## Dashboard UI structure

```
<h1>Smart Plug Dashboard</h1>
<div class="sub">     ← SSE connection status + user's groups
<section>Groups</section>   ← #groups grid, hidden if empty
<div id="device-sections">  ← injected by JS
    Tapo Plugs  → type:'tapo'
    Kasa Plugs  → type:'kasa'
    Tapo Bulbs  → type:'bulb'
</div>
```

Device type order is defined in `TYPE_SECTIONS` (JS constant). Unknown types get an auto-titled
section appended after the known ones.

**Group cards** — purple-tinted, summarise all members (on/mixed/off, summed power, shared
brightness/ct). Toggle resolves to a single on/off target so members never diverge.

**Device cards** — controllable users see On/Off/Toggle + (bulbs) three horizontal preset circles
(100%/4750 K · 50%/3500 K · 10%/2500 K) + active-ring highlighting the current preset. Read-only
users see a yellow "read-only" notice. Controllable cards show ✎ rename (disabled when offline)
and ⧉ copy i3blocks config.

---

## Energy collection

Requires `DB_PATH` in `.env`. When set, `Launcher` automatically starts `collect.php --daemon`.

| Type | Strategy | Granularity |
|------|----------|-------------|
| tapo | `getEnergyData()` 7-day lookback — idempotent, safe to re-run | hourly |
| kasa | snapshot diff of cumulative total — needs reliable hourly runs | hourly |
| bulb | `today_energy` from `getEnergyUsage()` — overwrites each run | daily |

SQLite schema (`energy_log`):

| Column | Type | Notes |
|--------|------|-------|
| `device_host` | TEXT | registry id |
| `device_name` | TEXT | live nickname |
| `ts` | INTEGER | unix timestamp — hour-start (tapo/kasa) or day-start (bulb) |
| `interval_s` | INTEGER | 3600 or 86400 |
| `wh` | REAL | Wh consumed |

Manual one-shot run: `php collect.php`

---

## Tuning constants

| Constant | Class | Default | Meaning |
|----------|-------|---------|---------|
| `POLL_INTERVAL` | `Worker` | 5.0 s | How often a worker hits the device |
| `CLIENT_TIMEOUT` | `Worker` | 2 s | Max wait for gateway on the IPC socket |
| `SSE_TICK` | `Gateway` | 2.0 s | How often state is pushed to browsers |
| `WORKER_TIMEOUT` | `WorkerClient` | 3.0 s | Max wait for a worker response |

---

## Starting / stopping

### Bare PHP

```sh
php launcher.php --port 9100 >/tmp/tapo-launcher.log 2>&1 &

# stop
kill $(ps -eo pid,args | awk '/launcher\.php/ && !/awk/ {print $1}')
```

### Docker

```sh
docker compose up -d --build
docker compose logs -f
docker compose down
```

`tini` is PID 1 — forwards SIGTERM to the launcher which gracefully shuts down all children.
Mount `.env` and `devices.json` as read-only volumes; `energy.db` on a named volume.

Socket files live in `/tmp/tapo-dash/*.sock` and are cleaned up on shutdown.

---

## PHP extensions required

`ext-openssl`, `ext-curl`, `ext-json`, `ext-sqlite3`, `ext-sockets`, `pcntl`, `posix`
