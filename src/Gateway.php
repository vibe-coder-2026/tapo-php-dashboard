<?php

declare(strict_types=1);

namespace Tapo;

/**
 * Web-facing gateway daemon (pure-PHP, non-blocking).
 *
 * Browsers talk only to this process; it never connects to a plug directly.
 * Routes:
 *   GET /              -> dashboard HTML
 *   GET /events        -> SSE stream (per-client filtered by auth groups)
 *   GET /action        -> forward command to a worker
 *   GET /group-action  -> fan-out command to all members of a device group
 *
 * Usage: (new Gateway($port, $registry, $auth, $workerClient))->run()
 */
class Gateway
{
    private const SSE_TICK      = 2.0;
    private const READ_CHUNK    = 65536;
    private const GROUPS_HEADER = 'x-forwarded-groups';

    private mixed $server;
    private array $clients  = [];
    private float $nextTick;

    public function __construct(
        private readonly int            $port,
        private readonly DeviceRegistry $registry,
        private readonly Auth           $auth,
        private readonly WorkerClient   $workerClient,
        private readonly ?array         $spoofGroups = null,
    ) {}

    public function run(): void
    {
        $this->server = stream_socket_server("tcp://0.0.0.0:{$this->port}", $errno, $errstr);
        if ($this->server === false) {
            fwrite(STDERR, "[gateway] failed to bind 0.0.0.0:{$this->port}: {$errstr}\n");
            exit(1);
        }
        stream_set_blocking($this->server, false);

        pcntl_async_signals(true);
        $stop = function () { fwrite(STDOUT, "[gateway] shutting down\n"); exit(0); };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);

        fwrite(STDOUT, "[gateway] listening on 0.0.0.0:{$this->port}\n");
        $this->nextTick = microtime(true) + self::SSE_TICK;

        while (true) {
            $read  = [$this->server];
            $write = [];
            foreach ($this->clients as $c) {
                $read[] = $c['sock'];
                if ($c['wbuf'] !== '') {
                    $write[] = $c['sock'];
                }
            }
            $except = null;

            $timeout = max(0, $this->nextTick - microtime(true));
            $sec     = (int) $timeout;
            $usec    = (int) (($timeout - $sec) * 1_000_000);

            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) {
                continue;
            }

            if (in_array($this->server, $read, true)) {
                while (($conn = @stream_socket_accept($this->server, 0)) !== false) {
                    stream_set_blocking($conn, false);
                    $this->clients[(int) $conn] = [
                        'sock'    => $conn,
                        'rbuf'    => '',
                        'wbuf'    => '',
                        'sse'     => false,
                        'i3block' => null,   // device id if this is a persist stream
                        'close'   => false,
                        'groups'  => null,
                    ];
                }
                $read = array_filter($read, fn($s) => $s !== $this->server);
            }

            foreach ($read as $sock) {
                $cid = (int) $sock;
                if (!isset($this->clients[$cid])) {
                    continue;
                }
                $data = @fread($sock, self::READ_CHUNK);
                if ($data === '' || $data === false) {
                    if (feof($sock)) {
                        $this->closeClient($cid);
                    }
                    continue;
                }
                $this->clients[$cid]['rbuf'] .= $data;
                if (!$this->clients[$cid]['sse'] && $this->clients[$cid]['i3block'] === null
                    && str_contains($this->clients[$cid]['rbuf'], "\r\n\r\n")) {
                    $this->routeRequest($cid);
                }
            }

            foreach ($write as $sock) {
                $cid = (int) $sock;
                if (!isset($this->clients[$cid]) || $this->clients[$cid]['wbuf'] === '') {
                    continue;
                }
                $n = @fwrite($sock, $this->clients[$cid]['wbuf']);
                if ($n === false) {
                    $this->closeClient($cid);
                    continue;
                }
                $this->clients[$cid]['wbuf'] = substr($this->clients[$cid]['wbuf'], $n);
                if ($this->clients[$cid]['wbuf'] === '' && $this->clients[$cid]['close']) {
                    $this->closeClient($cid);
                }
            }

            if (microtime(true) >= $this->nextTick) {
                $this->pushStates();
                $this->nextTick = microtime(true) + self::SSE_TICK;
            }
        }
    }

    // --- routing ---

    private function routeRequest(int $cid): void
    {
        $rbuf      = $this->clients[$cid]['rbuf'];
        $firstLine = strtok($rbuf, "\r\n");
        $target    = explode(' ', (string) $firstLine)[1] ?? '/';

        $headers    = $this->parseHeaders($rbuf);
        $userGroups = $this->auth->parseGroupsHeader($headers[self::GROUPS_HEADER] ?? null);
        if ($this->spoofGroups !== null) {
            $userGroups = $this->spoofGroups;
        }

        $urlPath = parse_url($target, PHP_URL_PATH) ?? '/';
        parse_str((string) (parse_url($target, PHP_URL_QUERY) ?? ''), $query);

        match ($urlPath) {
            '/'             => $this->handleRoot($cid),
            '/events'       => $this->handleEvents($cid, $userGroups),
            '/action'       => $this->handleAction($cid, $query, $userGroups),
            '/group-action' => $this->handleGroupAction($cid, $query, $userGroups),
            '/i3block'        => $this->handleI3block($cid, $query),
            '/i3block/stream' => $this->handleI3blockStream($cid, $query),
            default         => $this->respond($cid, 404, 'text/plain', 'Not found'),
        };
    }

    private function handleRoot(int $cid): void
    {
        $this->respond($cid, 200, 'text/html; charset=utf-8', $this->dashboardHtml());
    }

    private function handleEvents(int $cid, ?array $userGroups): void
    {
        $this->clients[$cid]['sse']    = true;
        $this->clients[$cid]['groups'] = $userGroups;
        $this->clients[$cid]['wbuf']   =
            "HTTP/1.1 200 OK\r\n" .
            "Content-Type: text/event-stream\r\n" .
            "Cache-Control: no-cache\r\n" .
            "Connection: keep-alive\r\n" .
            "X-Accel-Buffering: no\r\n\r\n" .
            $this->sseFrame($this->indexById($this->workerClient->collectAll()), $userGroups);
    }

    private function handleAction(int $cid, array $query, ?array $userGroups): void
    {
        $id       = $query['id'] ?? '';
        $action   = $query['action'] ?? 'status';
        $registry = $this->registry->devices();

        if (!isset($registry[$id])) {
            $this->respond($cid, 404, 'application/json', json_encode(['error' => 'Unknown device']));
            return;
        }

        $meta     = $registry[$id];
        $visible  = $this->auth->isVisible($meta['is_public'], $meta['groups'], $userGroups);
        $control  = $this->auth->canControl($meta['groups'], $userGroups);
        $mutating = in_array($action, ['on', 'off', 'toggle', 'preset', 'rename'], true);

        $extra = [];
        if ($action === 'preset') {
            $extra = [
                'brightness' => (int) ($query['brightness'] ?? 100),
                'color_temp' => (int) ($query['color_temp'] ?? 4000),
            ];
        } elseif ($action === 'rename') {
            $extra = ['name' => substr(trim((string) ($query['name'] ?? '')), 0, 64)];
        }

        if (!$visible) {
            $this->respond($cid, 404, 'application/json', json_encode(['error' => 'Unknown device']));
            return;
        }
        if ($mutating && !$control) {
            $this->respond($cid, 403, 'application/json', json_encode(['error' => 'Forbidden: no group access']));
            return;
        }

        $state = $this->workerClient->query($id, $mutating ? $action : 'status', $extra);
        $state = $this->annotate($state, $meta, $userGroups);
        $this->respond($cid, 200, 'application/json', json_encode($state));
        $this->pushStates();
    }

    private function handleGroupAction(int $cid, array $query, ?array $userGroups): void
    {
        $name   = $query['group'] ?? '';
        $action = $query['action'] ?? 'status';
        $groups = $this->registry->groups();

        if (!isset($groups[$name])) {
            $this->respond($cid, 404, 'application/json', json_encode(['error' => 'Unknown group']));
            return;
        }

        $registry = $this->registry->devices();
        $members  = array_values(array_filter($groups[$name], fn($id) => isset($registry[$id])));

        $anyVisible = false;
        $controlAll = true;
        foreach ($members as $id) {
            $m = $registry[$id];
            if ($this->auth->isVisible($m['is_public'], $m['groups'], $userGroups)) {
                $anyVisible = true;
            }
            if (!$this->auth->canControl($m['groups'], $userGroups)) {
                $controlAll = false;
            }
        }

        if (!$anyVisible) {
            $this->respond($cid, 404, 'application/json', json_encode(['error' => 'Unknown group']));
            return;
        }

        $mutating = in_array($action, ['on', 'off', 'toggle', 'preset'], true);
        if ($mutating && !$controlAll) {
            $this->respond($cid, 403, 'application/json', json_encode(['error' => 'Forbidden: no group access']));
            return;
        }

        if ($mutating) {
            $byId      = $this->indexById($this->workerClient->collectAll());
            $effective = $action;
            $extra     = [];
            if ($action === 'toggle') {
                $online    = array_filter(array_map(fn($id) => $byId[$id] ?? null, $members), fn($s) => $s && $s['online']);
                $allOn     = $online && count(array_filter($online, fn($s) => $s['on'])) === count($online);
                $effective = $allOn ? 'off' : 'on';
            } elseif ($action === 'preset') {
                $extra = [
                    'brightness' => (int) ($query['brightness'] ?? 100),
                    'color_temp' => (int) ($query['color_temp'] ?? 4000),
                ];
            }
            foreach ($members as $id) {
                $this->workerClient->query($id, $effective, $extra);
            }
        }

        $byId  = $this->indexById($this->workerClient->collectAll());
        $group = null;
        foreach ($this->buildGroups($byId, $userGroups) as $g) {
            if ($g['name'] === $name) {
                $group = $g;
                break;
            }
        }
        $this->respond($cid, 200, 'application/json', json_encode($group));
        $this->pushStates();
    }

    /**
     * GET /i3block?id=<device-id>
     * One-shot — returns a single i3blocks JSON object and closes.
     */
    private function handleI3block(int $cid, array $query): void
    {
        $id = $query['id'] ?? '';
        if (!isset($this->registry->devices()[$id])) {
            $host = $id;
            $out  = json_encode(['full_text' => "{$host}: ❌", 'short_text' => "{$host}: ❌", 'color' => '#FFA500'], JSON_UNESCAPED_UNICODE);
            $this->respond($cid, 200, 'application/json', $out);
            return;
        }
        $byId = $this->indexById([$this->workerClient->query($id, 'status')]);
        $this->respond($cid, 200, 'application/json', $this->buildI3block($id, $byId));
    }

    /**
     * GET /i3block/stream?id=<device-id>
     * Persistent stream — pushes a new JSON line on every SSE tick (~2 s).
     * Use with interval=persist in i3blocks.
     */
    private function handleI3blockStream(int $cid, array $query): void
    {
        $id = $query['id'] ?? '';
        if (!isset($this->registry->devices()[$id])) {
            $this->respond($cid, 404, 'text/plain', 'Unknown device');
            return;
        }
        $this->clients[$cid]['i3block'] = $id;
        $byId = $this->indexById([$this->workerClient->query($id, 'status')]);
        $this->clients[$cid]['wbuf'] =
            "HTTP/1.1 200 OK\r\n" .
            "Content-Type: application/x-ndjson\r\n" .
            "Cache-Control: no-cache\r\n" .
            "Connection: keep-alive\r\n\r\n" .
            $this->buildI3block($id, $byId) . "\n";
    }

    /**
     * Format one i3blocks JSON line for a device.
     * Label = live device name; thresholds from min_max[name] in devices.json.
     * Offline: shows hostname in orange.
     */
    private function buildI3block(string $id, array $byId): string
    {
        $registry = $this->registry->devices();
        $host     = $registry[$id]['host'] ?? $id;
        $state    = $byId[$id] ?? null;

        if (!$state || !$state['online']) {
            return json_encode(['full_text' => "{$host}: ❌", 'short_text' => "{$host}: ❌", 'color' => '#FFA500'], JSON_UNESCAPED_UNICODE);
        }

        $label   = $state['name'];
        $current = (int) ($state['power_w'] ?? 0);
        $symbol  = $state['on'] ? '⚡' : '';
        $text    = "{$label}: {$current}W{$symbol}";

        $minMax = $this->registry->minMax($label);
        $color  = '#00FF00';
        if ($minMax !== null) {
            $color = match (true) {
                $current <= $minMax[0] => '#FFFF00',
                $current >= $minMax[1] => '#FF0000',
                default                => '#00FF00',
            };
        }

        return json_encode(['full_text' => $text, 'short_text' => $text, 'color' => $color], JSON_UNESCAPED_UNICODE);
    }

    // --- helpers ---

    private function closeClient(int $cid): void
    {
        if (isset($this->clients[$cid])) {
            @fclose($this->clients[$cid]['sock']);
            unset($this->clients[$cid]);
        }
    }

    private function respond(int $cid, int $status, string $contentType, string $body): void
    {
        $reason = [200 => 'OK', 403 => 'Forbidden', 404 => 'Not Found'][$status] ?? 'OK';
        $this->clients[$cid]['wbuf']  =
            "HTTP/1.1 {$status} {$reason}\r\n" .
            "Content-Type: {$contentType}\r\n" .
            'Content-Length: ' . strlen($body) . "\r\n" .
            "Connection: close\r\n\r\n" .
            $body;
        $this->clients[$cid]['close'] = true;
    }

    private function parseHeaders(string $rbuf): array
    {
        $headers = [];
        $lines   = explode("\r\n", $rbuf);
        array_shift($lines);
        foreach ($lines as $line) {
            if ($line === '') {
                break;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }
        return $headers;
    }

    private function pushStates(): void
    {
        $hasSse = $hasStream = false;
        foreach ($this->clients as $c) {
            if ($c['sse'])              { $hasSse    = true; }
            if ($c['i3block'] !== null) { $hasStream = true; }
        }
        if (!$hasSse && !$hasStream) {
            return;
        }

        $byId = $this->indexById($this->workerClient->collectAll());
        foreach ($this->clients as $cid => $c) {
            if ($c['sse']) {
                $this->clients[$cid]['wbuf'] .= $this->sseFrame($byId, $c['groups']);
            }
            if ($c['i3block'] !== null) {
                $this->clients[$cid]['wbuf'] .= $this->buildI3block($c['i3block'], $byId) . "\n";
            }
        }
    }

    private function sseFrame(array $byId, ?array $userGroups): string
    {
        $devices = [];
        foreach ($this->registry->devices() as $id => $meta) {
            if (!$this->auth->isVisible($meta['is_public'], $meta['groups'], $userGroups)) {
                continue;
            }
            $state     = $byId[$id] ?? [
                'id' => $id, 'type' => $meta['type'], 'name' => $id,
                'on' => false, 'power_w' => null,
                'brightness' => null, 'color_temp' => null,
                'online' => false, 'ts' => 0,
            ];
            $devices[] = $this->annotate($state, $meta, $userGroups);
        }

        return 'data: ' . json_encode([
            'user_groups' => $userGroups,
            'groups'      => $this->buildGroups($byId, $userGroups),
            'devices'     => $devices,
        ]) . "\n\n";
    }

    private function buildGroups(array $byId, ?array $userGroups): array
    {
        $registry = $this->registry->devices();
        $out      = [];

        foreach ($this->registry->groups() as $name => $memberIds) {
            $members = array_values(array_filter($memberIds, fn($id) => isset($registry[$id])));
            if (!$members) {
                continue;
            }

            $anyVisible = false;
            $control    = true;
            foreach ($members as $id) {
                $m = $registry[$id];
                if ($this->auth->isVisible($m['is_public'], $m['groups'], $userGroups)) {
                    $anyVisible = true;
                }
                if (!$this->auth->canControl($m['groups'], $userGroups)) {
                    $control = false;
                }
            }
            if (!$anyVisible) {
                continue;
            }

            $states  = array_filter(array_map(fn($id) => $byId[$id] ?? null, $members), fn($s) => $s !== null);
            $online  = array_values(array_filter($states, fn($s) => $s['online']));
            $onCount = count(array_filter($online, fn($s) => $s['on']));
            $power   = array_sum(array_map(fn($s) => $s['power_w'] ?? 0, $online));

            $types = array_values(array_unique(array_map(fn($id) => $registry[$id]['type'], $members)));
            $type  = count($types) === 1 ? $types[0] : 'mixed';

            $bri = $ct = null;
            if ($type === 'bulb' && $online) {
                $bris = array_unique(array_map(fn($s) => $s['brightness'], $online));
                $cts  = array_unique(array_map(fn($s) => $s['color_temp'], $online));
                $bri  = count($bris) === 1 ? reset($bris) : null;
                $ct   = count($cts) === 1 ? reset($cts) : null;
            }

            $out[] = [
                'name'         => $name,
                'type'         => $type,
                'count'        => count($members),
                'online'       => count($online) > 0,
                'on'           => count($online) > 0 && $onCount === count($online),
                'mixed'        => $onCount > 0 && $onCount < count($online),
                'power_w'      => $online ? round($power, 1) : null,
                'brightness'   => $bri,
                'color_temp'   => $ct,
                'controllable' => $control,
            ];
        }

        return $out;
    }

    private function annotate(array $state, array $meta, ?array $userGroups): array
    {
        $state['groups']       = $meta['groups'];
        $state['is_public']    = $meta['is_public'];
        $state['controllable'] = $this->auth->canControl($meta['groups'], $userGroups);
        return $state;
    }

    private function indexById(array $states): array
    {
        $byId = [];
        foreach ($states as $s) {
            $byId[$s['id']] = $s;
        }
        return $byId;
    }

    private function dashboardHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Smart Plug Dashboard</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">
<style>
    body { font-family: system-ui, sans-serif; margin: 0; padding: 1.5rem; background: #1a1a1a; color: #eee; }
    h1 { font-size: 1.4rem; margin: 0 0 0.25rem; }
    .sub { color: #888; font-size: 0.85rem; margin-bottom: 1rem; }
    .sub .live { color: #3fb950; }
    .sub .down { color: #f85149; }
    .sub .groups { color: #d2a8ff; }
    .empty { color: #888; padding: 2rem 0; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }
    .card { background: #2a2a2a; border-radius: 10px; padding: 1rem; border-left: 5px solid #555; }
    .card.on { border-left-color: #3fb950; }
    .card.off { border-left-color: #6e7681; }
    .card.offline { border-left-color: #f85149; opacity: 0.6; }
    .name-row { display: flex; align-items: center; gap: 0.4rem; }
    .name { font-weight: 600; font-size: 1.1rem; }
    .edit-btn, .copy-i3-btn { flex: 0 0 auto; width: auto; padding: 0 4px; background: none; color: #888;
                border: none; border-radius: 4px; font-size: 0.95rem; line-height: 1.4; cursor: pointer; }
    .edit-btn:hover:not(:disabled), .copy-i3-btn:hover { color: #fff; background: #3a3a3a; }
    .copy-i3-btn.copied { color: #3fb950; }
    .type { font-size: 0.75rem; color: #888; text-transform: uppercase; }
    .meta { font-size: 0.7rem; color: #777; margin-top: 0.15rem; }
    .state { margin: 0.5rem 0; font-size: 0.95rem; }
    .dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
    .dot.on { background: #3fb950; }
    .dot.off { background: #6e7681; }
    .dot.offline { background: #f85149; }
    .power { color: #58a6ff; font-variant-numeric: tabular-nums; }
    .btns { display: flex; gap: 0.5rem; margin-top: 0.75rem; }
    button { flex: 1; padding: 0.5rem; border: none; border-radius: 6px; cursor: pointer; font-size: 0.9rem; color: #fff; }
    button:disabled { opacity: 0.4; cursor: default; }
    .on-btn { background: #238636; }
    .off-btn { background: #6e7681; }
    .toggle-btn { background: #1f6feb; }
    .ro { margin-top: 0.75rem; font-size: 0.8rem; color: #d29922; }
    .detail { color: #ffd9a0; font-size: 0.85rem; font-variant-numeric: tabular-nums; margin-top: 0.5rem; text-align: center; }
    .presets { display: flex; flex-direction: row; justify-content: center; gap: 0.6rem; margin-top: 0.75rem; }
    .preset { width: 48px; height: 48px; border-radius: 50%; border: 2px solid transparent; cursor: pointer;
              display: flex; align-items: center; justify-content: center; font-size: 0.72rem; font-weight: 700;
              color: #2a1a00; transition: transform 0.08s; padding: 0; }
    .preset:hover:not(:disabled) { transform: scale(1.08); }
    .preset:disabled { cursor: default; filter: grayscale(0.6) brightness(0.7); }
    .preset.active { border-color: #fff; box-shadow: 0 0 0 3px rgba(255,255,255,0.25); }
    .section-title { font-size: 1rem; color: #bbb; margin: 1.4rem 0 0.6rem; border-bottom: 1px solid #333; padding-bottom: 0.3rem; }
    .card.group { background: #2d2640; border-left-color: #d2a8ff; }
    .card.group.on { border-left-color: #3fb950; }
    .card.group.mixed { border-left-color: #d29922; }
    .card.group .name { font-size: 1.2rem; }
</style>
</head>
<body>
<h1>Smart Plug Dashboard</h1>
<div class="sub">
    live: <span id="conn" class="down">connecting…</span>
    &nbsp;·&nbsp; access: <span id="access" class="groups">…</span>
</div>
<div class="section-title" id="groups-title" hidden>Groups</div>
<div class="grid" id="groups"></div>
<div id="device-sections"></div>
<div class="empty" id="empty" hidden>No devices are visible for your access level.</div>

<script>
const groupsEl = document.getElementById('groups');
const deviceSections = document.getElementById('device-sections');
const cards = {};
const groupCards = {};

const TYPE_SECTIONS = [
    { type: 'tapo', label: 'Tapo Plugs' },
    { type: 'kasa', label: 'Kasa Plugs' },
    { type: 'bulb', label: 'Tapo Bulbs' },
];
const typeGrids = {};

function addSection(type, label) {
    const title = document.createElement('div');
    title.className = 'section-title';
    title.textContent = label;
    title.hidden = true;
    const grid = document.createElement('div');
    grid.className = 'grid';
    deviceSections.appendChild(title);
    deviceSections.appendChild(grid);
    typeGrids[type] = { title, grid };
}
TYPE_SECTIONS.forEach(s => addSection(s.type, s.label));

const PRESETS = [
    { label: '100%', brightness: 100, color_temp: 4750, bg: '#fff6e2', glow: '0 0 16px 5px rgba(255,243,210,0.95)' },
    { label: '50%',  brightness: 50,  color_temp: 3500, bg: '#ffd79a', glow: '0 0 9px 3px rgba(255,196,110,0.6)' },
    { label: '10%',  brightness: 10,  color_temp: 2500, bg: '#a9772f', glow: 'none' },
];

function addPresetCircles(container, onClick) {
    PRESETS.forEach(p => {
        const b = document.createElement('button');
        b.className = 'preset';
        b.textContent = p.label;
        b.dataset.bri = p.brightness;
        b.style.background = p.bg;
        b.style.boxShadow = p.glow;
        b.title = p.brightness + '% · ' + p.color_temp + 'K';
        b.addEventListener('click', () => onClick(p.brightness, p.color_temp));
        container.appendChild(b);
    });
}

function controlsHtml(type) {
    let html =
        '<div class="btns">' +
            '<button class="on-btn">On</button>' +
            '<button class="off-btn">Off</button>' +
            '<button class="toggle-btn">Toggle</button>' +
        '</div>';
    if (type === 'bulb') {
        html += '<div class="presets"></div><div class="detail"></div>';
    }
    return html;
}

function applyControls(card, s) {
    const controlRows = [card.querySelector('.btns'), card.querySelector('.presets')].filter(Boolean);
    const allButtons = card.querySelectorAll('.btns button, .preset');
    controlRows.forEach(c => c.style.display = s.controllable ? 'flex' : 'none');
    card.querySelector('.ro').hidden = s.controllable;
    allButtons.forEach(b => b.disabled = !s.online);

    if (s.type === 'bulb') {
        const detail = card.querySelector('.detail');
        if (!s.online) {
            detail.textContent = '';
        } else if (s.brightness == null) {
            detail.textContent = s.on ? 'mixed' : '';
        } else {
            detail.textContent = s.on ? (s.brightness + '% · ' + (s.color_temp || '?') + 'K') : '';
        }
        card.querySelectorAll('.preset').forEach(b => {
            b.classList.toggle('active', s.online && s.on && Number(b.dataset.bri) === s.brightness);
        });
    }
}

function createCard(d) {
    const el = document.createElement('div');
    el.className = 'card offline';
    el.id = 'card-' + d.id;
    el.innerHTML =
        '<div class="type"></div>' +
        '<div class="name-row"><span class="name"></span><button class="edit-btn" title="Rename" hidden>✎</button><button class="copy-i3-btn" title="Copy i3blocks config">⧉</button></div>' +
        '<div class="meta"></div>' +
        '<div class="state"><span class="dot offline"></span><span class="status-text">…</span></div>' +
        '<div class="state power"></div>' +
        controlsHtml(d.type) +
        '<div class="ro" hidden>read-only — not in an allowed group</div>';

    el.querySelector('.on-btn').addEventListener('click', () => act(d.id, 'on'));
    el.querySelector('.off-btn').addEventListener('click', () => act(d.id, 'off'));
    el.querySelector('.toggle-btn').addEventListener('click', () => act(d.id, 'toggle'));
    el.querySelector('.edit-btn').addEventListener('click', () =>
        renameDevice(d.id, el.querySelector('.name').textContent));
    el.querySelector('.copy-i3-btn').addEventListener('click', () =>
        copyI3block(d.id, el.querySelector('.name').textContent, el.querySelector('.copy-i3-btn')));
    if (d.type === 'bulb') {
        addPresetCircles(el.querySelector('.presets'), (b, t) => preset(d.id, b, t));
    }
    return el;
}

function render(d) {
    let card = cards[d.id];
    if (!card) { card = cards[d.id] = createCard(d); }

    card.querySelector('.type').textContent = d.type;
    card.querySelector('.name').textContent = d.name || d.id;
    card.querySelector('.meta').textContent =
        'groups: ' + (d.groups || []).join(', ') + (d.is_public ? ' · public' : '');

    const dot = card.querySelector('.dot');
    const power = card.querySelector('.power');
    card.querySelector('.status-text').textContent = !d.online ? 'OFFLINE' : (d.on ? 'ON' : 'OFF');
    card.className = !d.online ? 'card offline' : ('card ' + (d.on ? 'on' : 'off'));
    dot.className = !d.online ? 'dot offline' : ('dot ' + (d.on ? 'on' : 'off'));
    power.textContent = (d.online && d.power_w != null) ? (d.power_w + ' W') : '';

    const edit = card.querySelector('.edit-btn');
    edit.hidden = !d.controllable;
    edit.disabled = !d.online;

    applyControls(card, d);
}

function copyI3block(id, name, btn) {
    const label  = name.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_-]/g, '');
    const origin = window.location.origin;
    const text   = '[plug_' + label + ']\ncommand=curl -sN "' + origin + '/i3block/stream?id=' + id + '"\ninterval=persist\nformat=json';
    const done  = () => {
        btn.textContent = '✓';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = '⧉'; btn.classList.remove('copied'); }, 1500);
    };
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(done);
    } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        done();
    }
}

function renameDevice(id, current) {
    const name = prompt('Rename device:', current);
    if (name === null) return;
    const trimmed = name.trim();
    if (!trimmed || trimmed === current) return;
    busy(cards[id]);
    fetch('/action?action=rename&id=' + encodeURIComponent(id) + '&name=' + encodeURIComponent(trimmed))
        .then(r => r.json()).then(d => { if (d && d.id) render(d); }).catch(() => {});
}

function act(id, action) {
    busy(cards[id]);
    fetch('/action?action=' + action + '&id=' + encodeURIComponent(id))
        .then(r => r.json()).then(d => { if (d && d.id) render(d); }).catch(() => {});
}

function preset(id, brightness, colorTemp) {
    busy(cards[id]);
    fetch('/action?action=preset&id=' + encodeURIComponent(id) +
          '&brightness=' + brightness + '&color_temp=' + colorTemp)
        .then(r => r.json()).then(d => { if (d && d.id) render(d); }).catch(() => {});
}

function busy(card) {
    card.querySelectorAll('button').forEach(b => b.disabled = true);
    card.querySelector('.status-text').textContent = '…';
}

function syncCards(devices) {
    const seen = new Set(devices.map(d => d.id));
    for (const id in cards) {
        if (!seen.has(id)) { cards[id].remove(); delete cards[id]; }
    }

    const byType = {};
    devices.forEach(d => { (byType[d.type] = byType[d.type] || []).push(d); });
    Object.keys(byType).forEach(t => { if (!typeGrids[t]) addSection(t, t); });

    const order = TYPE_SECTIONS.map(s => s.type)
        .concat(Object.keys(typeGrids).filter(t => !TYPE_SECTIONS.some(s => s.type === t)));

    order.forEach(type => {
        const sec = typeGrids[type];
        if (!sec) return;
        const list = (byType[type] || []).slice()
            .sort((a, b) => a.id.localeCompare(b.id, undefined, { numeric: true }));
        list.forEach(d => { render(d); sec.grid.appendChild(cards[d.id]); });
        sec.title.hidden = list.length === 0;
        sec.grid.style.display = list.length ? '' : 'none';
    });
}

function createGroupCard(g) {
    const el = document.createElement('div');
    el.className = 'card group offline';
    el.innerHTML =
        '<div class="type">group</div>' +
        '<div class="name"></div>' +
        '<div class="meta"></div>' +
        '<div class="state"><span class="dot offline"></span><span class="status-text">…</span></div>' +
        '<div class="state power"></div>' +
        controlsHtml(g.type) +
        '<div class="ro" hidden>read-only — not in an allowed group</div>';

    el.querySelector('.on-btn').addEventListener('click', () => groupAct(g.name, 'on'));
    el.querySelector('.off-btn').addEventListener('click', () => groupAct(g.name, 'off'));
    el.querySelector('.toggle-btn').addEventListener('click', () => groupAct(g.name, 'toggle'));
    if (g.type === 'bulb') {
        addPresetCircles(el.querySelector('.presets'), (b, t) => groupPreset(g.name, b, t));
    }
    return el;
}

function renderGroup(g) {
    let card = groupCards[g.name];
    if (!card) { card = groupCards[g.name] = createGroupCard(g); }

    card.querySelector('.name').textContent = g.name;
    card.querySelector('.meta').textContent = g.count + ' devices · ' + g.type;

    const dot = card.querySelector('.dot');
    const status = !g.online ? 'OFFLINE' : (g.mixed ? 'MIXED' : (g.on ? 'ON' : 'OFF'));
    const cls = !g.online ? 'offline' : (g.mixed ? 'mixed' : (g.on ? 'on' : 'off'));
    card.querySelector('.status-text').textContent = status;
    card.className = 'card group ' + cls;
    dot.className = 'dot ' + (g.mixed ? 'on' : cls);
    card.querySelector('.power').textContent = (g.online && g.power_w != null) ? (g.power_w + ' W') : '';

    applyControls(card, g);
}

function groupAct(name, action) {
    busy(groupCards[name]);
    fetch('/group-action?action=' + action + '&group=' + encodeURIComponent(name))
        .then(r => r.json()).then(g => { if (g && g.name) renderGroup(g); }).catch(() => {});
}

function groupPreset(name, brightness, colorTemp) {
    busy(groupCards[name]);
    fetch('/group-action?action=preset&group=' + encodeURIComponent(name) +
          '&brightness=' + brightness + '&color_temp=' + colorTemp)
        .then(r => r.json()).then(g => { if (g && g.name) renderGroup(g); }).catch(() => {});
}

function syncGroups(groups) {
    const seen = new Set(groups.map(g => g.name));
    for (const n in groupCards) {
        if (!seen.has(n)) { groupCards[n].remove(); delete groupCards[n]; }
    }
    groups.forEach(g => { renderGroup(g); groupsEl.appendChild(groupCards[g.name]); });
    document.getElementById('groups-title').hidden = groups.length === 0;
}

const conn = document.getElementById('conn');
const access = document.getElementById('access');
const es = new EventSource('/events');
es.onopen = () => { conn.textContent = 'connected'; conn.className = 'live'; };
es.onerror = () => { conn.textContent = 'reconnecting…'; conn.className = 'down'; };
es.onmessage = (e) => {
    const msg = JSON.parse(e.data);
    if (msg.user_groups === null) {
        access.textContent = 'anonymous (read-only)';
    } else if (msg.user_groups.length === 0) {
        access.textContent = 'no groups (read-only)';
    } else {
        access.textContent = msg.user_groups.join(', ');
    }
    const groups = msg.groups || [];
    const devices = msg.devices || [];
    syncGroups(groups);
    syncCards(devices);
    document.getElementById('empty').hidden = (groups.length + devices.length) > 0;
};
</script>
</body>
</html>
HTML;
    }
}
