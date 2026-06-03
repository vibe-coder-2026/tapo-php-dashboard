<?php

declare(strict_types=1);

namespace Tapo;

use Tapo\Kasa\HS110;
use Tapo\MeasureInterval;

/**
 * Energy collection — one-shot or daemon mode.
 *   php collect.php           → run once (manual / testing)
 *   php collect.php --daemon  → loop, sleeping to the next hour boundary between runs
 *
 * Requires DB_PATH in .env. Silently exits if not set.
 *
 * Per-type strategy:
 *   tapo  — getEnergyData() with 7-day lookback; idempotent, safe to re-run
 *   kasa  — snapshot diff; needs reliable hourly runs, missed run = lost hour
 *   bulb  — today_energy from getEnergyUsage(); overwrites each run, last of day is most accurate
 */
class Collector
{
    private \SQLite3 $db;
    private \SQLite3Stmt $insertLog;
    private \SQLite3Stmt $snapshotQuery;
    private \SQLite3Stmt $snapshotUpsert;

    private int $hourTs;
    private int $dayTs;

    public function __construct(
        private readonly Config         $config,
        private readonly DeviceRegistry $registry,
        private readonly string         $dbPath,
    ) {
        $now           = time();
        $this->hourTs  = (int) (floor($now / 3600) * 3600);
        $this->dayTs   = mktime(0, 0, 0, (int) date('n'), (int) date('j'), (int) date('Y'));
    }

    public function run(): void
    {
        $this->openDb();
        $this->collectTapo();
        $this->collectKasa();
        $this->collectBulbs();
        echo "Done.\n";
    }

    public function daemon(): void
    {
        while (true) {
            $this->run();
            // Sleep to the start of the next hour so runs stay aligned to clock hours.
            $next  = (int) (ceil((time() + 1) / 3600) * 3600);
            $sleep = max(1, $next - time());
            echo "Next run in {$sleep}s (at " . date('H:i', $next) . ")\n";
            sleep($sleep);
            // Re-initialise timestamps for the new hour.
            $now           = time();
            $this->hourTs  = (int) (floor($now / 3600) * 3600);
            $this->dayTs   = mktime(0, 0, 0, (int) date('n'), (int) date('j'), (int) date('Y'));
        }
    }

    private function openDb(): void
    {
        $this->db = new \SQLite3($this->dbPath);
        $this->db->enableExceptions(true);
        $this->db->exec('PRAGMA journal_mode=WAL');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS energy_log (
                device_host  TEXT    NOT NULL,
                device_name  TEXT,
                ts           INTEGER NOT NULL,
                interval_s   INTEGER NOT NULL DEFAULT 3600,
                wh           REAL    NOT NULL,
                PRIMARY KEY (device_host, ts)
            )
        ');


        $this->db->exec('
            CREATE TABLE IF NOT EXISTS kasa_snapshots (
                device_host  TEXT    PRIMARY KEY,
                device_name  TEXT,
                ts           INTEGER NOT NULL,
                total_wh     REAL    NOT NULL
            )
        ');

        $this->insertLog = $this->db->prepare('
            INSERT OR REPLACE INTO energy_log (device_host, device_name, ts, interval_s, wh)
            VALUES (:host, :name, :ts, :interval_s, :wh)
        ');

        $this->snapshotQuery = $this->db->prepare(
            'SELECT ts, total_wh FROM kasa_snapshots WHERE device_host = :host'
        );

        $this->snapshotUpsert = $this->db->prepare('
            INSERT OR REPLACE INTO kasa_snapshots (device_host, device_name, ts, total_wh)
            VALUES (:host, :name, :ts, :total)
        ');
    }

    private function collectTapo(): void
    {
        $end   = $this->hourTs + 3599;
        $start = $end - 7 * 86400 + 1;

        foreach ($this->registry->devices() as $id => $meta) {
            if ($meta['type'] !== 'tapo') {
                continue;
            }
            $host = $meta['host'];
            try {
                $device = new P110($host, $this->config->email(), $this->config->password());
                $name   = $device->getDeviceName();
                $result = $device->getEnergyData($start, $end, MeasureInterval::HOURS);
                $refTs  = $result['start_timestamp'];
                $stored = 0;

                foreach ($result['data'] as $i => $wh) {
                    $ts = $refTs + $i * 3600;
                    if ($ts < $start || $ts > $end) {
                        continue;
                    }
                    $this->log($host, $name, $ts, 3600, (float) $wh);
                    $stored++;
                }

                echo "[tapo] {$host} ({$name}): {$stored} hourly records\n";
            } catch (\Throwable $e) {
                echo "[tapo] {$host}: OFFLINE ({$e->getMessage()})\n";
            }
        }
    }

    private function collectKasa(): void
    {
        foreach ($this->registry->devices() as $id => $meta) {
            if ($meta['type'] !== 'kasa') {
                continue;
            }
            $host = $meta['host'];
            try {
                $device    = new HS110($host);
                $name      = $device->getDeviceName();
                $realtime  = $device->getEnergyUsage();
                $currentWh = (float) $realtime['total'] * 1000;  // kWh → Wh

                $this->snapshotQuery->bindValue(':host', $host);
                $row = $this->snapshotQuery->execute()->fetchArray(SQLITE3_ASSOC) ?: null;

                if ($row && $currentWh >= (float) $row['total_wh']) {
                    $diffWh = $currentWh - (float) $row['total_wh'];
                    $this->log($host, $name, $this->hourTs, 3600, $diffWh);
                    echo "[kasa] {$host} ({$name}): {$diffWh} Wh this hour\n";
                } elseif (!$row) {
                    echo "[kasa] {$host} ({$name}): first snapshot, no diff yet\n";
                } else {
                    echo "[kasa] {$host} ({$name}): counter reset detected, skipping diff\n";
                }

                $this->snapshotUpsert->bindValue(':host',  $host);
                $this->snapshotUpsert->bindValue(':name',  $name);
                $this->snapshotUpsert->bindValue(':ts',    $this->hourTs);
                $this->snapshotUpsert->bindValue(':total', $currentWh);
                $this->snapshotUpsert->execute();
            } catch (\Throwable $e) {
                echo "[kasa] {$host}: OFFLINE ({$e->getMessage()})\n";
            }
        }
    }

    private function collectBulbs(): void
    {
        foreach ($this->registry->devices() as $id => $meta) {
            if ($meta['type'] !== 'bulb') {
                continue;
            }
            $host = $meta['host'];
            try {
                $device  = new L530($host, $this->config->email(), $this->config->password());
                $name    = $device->getDeviceName();
                $energy  = $device->getEnergyUsage();
                $todayWh = $energy['today_energy'] ?? null;

                if ($todayWh === null) {
                    echo "[bulb] {$host} ({$name}): no today_energy field\n";
                    continue;
                }

                $this->log($host, $name, $this->dayTs, 86400, (float) $todayWh);
                echo "[bulb] {$host} ({$name}): {$todayWh} Wh today\n";
            } catch (\Throwable $e) {
                echo "[bulb] {$host}: OFFLINE ({$e->getMessage()})\n";
            }
        }
    }

    private function log(string $host, string $name, int $ts, int $intervalS, float $wh): void
    {
        $this->insertLog->bindValue(':host',       $host);
        $this->insertLog->bindValue(':name',       $name);
        $this->insertLog->bindValue(':ts',         $ts);
        $this->insertLog->bindValue(':interval_s', $intervalS);
        $this->insertLog->bindValue(':wh',         $wh);
        $this->insertLog->execute();
    }
}
