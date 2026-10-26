<?php
namespace App\Service;

use Doctrine\DBAL\Connection;

final class AlertEngine
{
    private const LOCK = 'seiftours_fleet_engine';

    public function __construct(private Connection $db, private SettingsService $settings) {}

    /** @return array{created:int,updated:int,resolved:int}|null  null = déjà en cours ou pas encore dû */
    public function run(bool $force = true, int $every = 60): ?array
    {
        if ((int) $this->db->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK]) !== 1) {
            return null;
        }
        try {
            if (!$force) {
                $last = (int) $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'fleet.engine_last_run'");
                if (time() - $last < $every) {
                    return null;
                }
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Tunis'));
            $wanted = [];
                         $this->documents($now, $wanted);
            $this->controlOverdue($now, $wanted);
            $this->unsentPrograms($now, $wanted);
            $this->lateStarts($now, $wanted);
            $this->noSignal($now, $wanted);
            $this->speeding($now, $wanted);
            $result = $this->sync($wanted);
            $result = $this->sync($wanted);

            $this->db->executeStatement(
                "INSERT INTO settings (setting_key, setting_value, description) VALUES ('fleet.engine_last_run', ?, 'Dernier passage du moteur d''alertes')
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                [(string) time()]
            );
            return $result;
        } finally {
            $this->db->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    // ----------------------------------------------------------------- règles

    private function documents(\DateTimeImmutable $now, array &$wanted): void
    {
        $today = $now->setTime(0, 0);
        $limit = $today->modify('+' . DocumentStatus::SOON_DAYS . ' days')->format('Y-m-d');

        $buses = $this->db->fetchAllAssociative(
            'SELECT id, code, insurance_expires_on, inspection_expires_on FROM buses
             WHERE is_active = 1 AND ((insurance_expires_on IS NOT NULL AND insurance_expires_on <= ?)
                                   OR (inspection_expires_on IS NOT NULL AND inspection_expires_on <= ?))',
            [$limit, $limit]
        );
        foreach ($buses as $b) {
            foreach (['insurance_expires_on' => 'Assurance', 'inspection_expires_on' => 'Visite technique'] as $col => $label) {
                if ($b[$col] !== null && $b[$col] <= $limit) {
                    $wanted["doc:bus:{$b['id']}:$col:{$b[$col]}"] =
                        $this->docAlert("$label du bus {$b['code']}", $b[$col], $today) + ['bus_id' => (int) $b['id']];
                }
            }
        }

        $drivers = $this->db->fetchAllAssociative(
            "SELECT id, CONCAT(first_name, ' ', last_name) AS name, license_expires_on FROM drivers
             WHERE is_active = 1 AND license_expires_on <= ?", [$limit]
        );
        foreach ($drivers as $d) {
            $wanted["doc:driver:{$d['id']}:license:{$d['license_expires_on']}"] =
                $this->docAlert("Permis de {$d['name']}", $d['license_expires_on'], $today) + ['driver_id' => (int) $d['id']];
        }
    }
    private function noSignal(\DateTimeImmutable $now, array &$wanted): void
    {
        $min = max(2, $this->settings->int('fleet.no_signal_minutes'));
        // Seuls les bus qui ont déjà transmis une position sont surveillés : sans suivi installé, aucune fausse alerte
        $rows = $this->db->fetchAllAssociative(
            "SELECT m.id, m.reference, m.bus_id, m.driver_id, b.code AS bus_code, p.recorded_at AS last_at, p.lat, p.lng,
                    TIMESTAMPDIFF(MINUTE, p.recorded_at, UTC_TIMESTAMP(3)) AS silent
             FROM bus_missions m
             JOIN buses b ON b.id = m.bus_id
             JOIN bus_last_position p ON p.bus_id = m.bus_id
             WHERE m.status = 'in_progress' AND p.recorded_at < UTC_TIMESTAMP(3) - INTERVAL $min MINUTE"
        );
        foreach ($rows as $r) {
            $stamp = strtotime(substr($r['last_at'], 0, 19) . ' UTC');      // un épisode de silence = une alerte
            $wanted["nosig:{$r['id']}:$stamp"] = [
                'type' => 'no_signal',
                'severity' => (int) $r['silent'] >= 15 ? 'critical' : 'warning',
                'message' => "Signal perdu : le bus {$r['bus_code']} ne transmet plus depuis {$r['silent']} min (mission {$r['reference']})",
                'bus_id' => (int) $r['bus_id'], 'driver_id' => (int) $r['driver_id'], 'mission_id' => (int) $r['id'],
                'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'],
            ];
        }
    }

    private function speeding(\DateTimeImmutable $now, array &$wanted): void
    {
        $limit = $this->settings->int('fleet.speed_limit_kmh');
        if ($limit <= 0) {
            return;
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT p.bus_id, p.driver_id, p.mission_id, p.speed_kmh, p.recorded_at, p.lat, p.lng, b.code AS bus_code,
                    CONCAT(d.first_name, ' ', d.last_name) AS driver_name
             FROM bus_last_position p JOIN buses b ON b.id = p.bus_id LEFT JOIN drivers d ON d.id = p.driver_id
             WHERE p.speed_kmh > ? AND p.recorded_at >= UTC_TIMESTAMP(3) - INTERVAL 3 MINUTE",
            [$limit]
        );
        foreach ($rows as $p) {
            $bucket = intdiv(strtotime(substr($p['recorded_at'], 0, 19) . ' UTC'), 1800);   // au plus une alerte par bus et par 30 min
            $speed = (int) round((float) $p['speed_kmh']);
            $wanted["speed:{$p['bus_id']}:$bucket"] = [
                'type' => 'speeding',
                'severity' => $speed > $limit + 20 ? 'critical' : 'warning',
                'message' => "Excès de vitesse : bus {$p['bus_code']} à $speed km/h (limite $limit)" . ($p['driver_name'] ? " — {$p['driver_name']}" : ''),
                'bus_id' => (int) $p['bus_id'], 'driver_id' => $p['driver_id'] ? (int) $p['driver_id'] : null,
                'mission_id' => $p['mission_id'] ? (int) $p['mission_id'] : null,
                'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'],
            ];
        }
    }
    private function docAlert(string $subject, string $date, \DateTimeImmutable $today): array
    {
        $days = DocumentStatus::of($date, $today)['days'];
        $fr = (new \DateTimeImmutable($date))->format('d/m/Y');
        if ($days < 0) {
            return ['type' => 'document_expiring', 'severity' => 'critical', 'message' => "$subject expirée depuis " . (-$days) . " jour(s) (le $fr)"];
        }
        if ($days === 0) {
            return ['type' => 'document_expiring', 'severity' => 'critical', 'message' => "$subject expire aujourd'hui"];
        }
        return ['type' => 'document_expiring', 'severity' => $days <= 7 ? 'warning' : 'info',
                'message' => "$subject expire dans $days jour(s) (le $fr)"];
    }

    private function controlOverdue(\DateTimeImmutable $now, array &$wanted): void
    {
        $hours = $this->settings->int('fleet.control_overdue_hours');
        if ($hours <= 0) {
            return;
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, reference, title, ends_at, bus_id, driver_id FROM bus_missions
             WHERE status IN ('planned','in_progress') AND ends_at < ? AND ends_at >= ?",
            [$now->modify("-$hours hours")->format('Y-m-d H:i:s'), $now->modify('-30 days')->format('Y-m-d H:i:s')]
        );
        foreach ($rows as $m) {
            $wanted["ctrl:{$m['id']}"] = [
                'type' => 'control_overdue', 'severity' => 'warning',
                'message' => "Mission {$m['reference']} « {$m['title']} » du " . (new \DateTimeImmutable($m['ends_at']))->format('d/m') . ' non contrôlée',
                'bus_id' => (int) $m['bus_id'], 'driver_id' => (int) $m['driver_id'], 'mission_id' => (int) $m['id'],
            ];
        }
    }

    private function unsentPrograms(\DateTimeImmutable $now, array &$wanted): void
    {
        $hours = $this->settings->int('fleet.unsent_program_hours');
        if ($hours <= 0) {
            return;
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT m.id, m.reference, m.title, m.starts_at, m.bus_id, m.driver_id, CONCAT(d.first_name, ' ', d.last_name) AS driver_name
             FROM bus_missions m JOIN drivers d ON d.id = m.driver_id
             WHERE m.status = 'planned' AND m.starts_at > ? AND m.starts_at <= ?
               AND (m.notified_at IS NULL OR m.planning_updated_at > m.notified_at)",
            [$now->format('Y-m-d H:i:s'), $now->modify("+$hours hours")->format('Y-m-d H:i:s')]
        );
        foreach ($rows as $m) {
            $start = new \DateTimeImmutable($m['starts_at'], $now->getTimezone());
            $wanted["unsent:{$m['id']}"] = [
                'type' => 'program_unsent',
                'severity' => ($start->getTimestamp() - $now->getTimestamp()) <= 6 * 3600 ? 'critical' : 'warning',
                'message' => "Programme non envoyé à {$m['driver_name']} : mission {$m['reference']} « {$m['title']} » le " . $start->format('d/m \à H:i'),
                'bus_id' => (int) $m['bus_id'], 'driver_id' => (int) $m['driver_id'], 'mission_id' => (int) $m['id'],
            ];
        }
    }

    private function lateStarts(\DateTimeImmutable $now, array &$wanted): void
    {
        if (!$this->settings->bool('fleet.late_start_alerts')) {
            return;
        }
        $min = max(5, $this->settings->int('fleet.late_start_minutes'));
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, reference, title, starts_at, bus_id, driver_id FROM bus_missions
             WHERE status = 'planned' AND starts_at <= ? AND ends_at > ?",
            [$now->modify("-$min minutes")->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')]
        );
        foreach ($rows as $m) {
            $late = (int) floor(($now->getTimestamp() - (new \DateTimeImmutable($m['starts_at'], $now->getTimezone()))->getTimestamp()) / 60);
            $wanted["late:{$m['id']}"] = [
                'type' => 'late_departure', 'severity' => $late >= 60 ? 'critical' : 'warning',
                'message' => "Mission {$m['reference']} « {$m['title']} » non démarrée : départ prévu à " . substr($m['starts_at'], 11, 5) . " ($late min de retard)",
                'bus_id' => (int) $m['bus_id'], 'driver_id' => (int) $m['driver_id'], 'mission_id' => (int) $m['id'],
            ];
        }
    }

    // ------------------------------------------------------------ synchronisation

    private function sync(array $wanted): array
    {
        $created = $updated = $resolved = 0;

        $active = $this->db->fetchAllAssociative(
            "SELECT id, dedup_key, severity, message FROM fleet_alerts WHERE source = 'system' AND status IN ('open','acknowledged')"
        );
        $activeByKey = array_column($active, null, 'dedup_key');

        $known = [];
        if ($wanted) {
            $in = implode(',', array_fill(0, count($wanted), '?'));
            $known = array_flip($this->db->fetchFirstColumn(
                "SELECT dedup_key FROM fleet_alerts WHERE source = 'system' AND dedup_key IN ($in)", array_keys($wanted)
            ));
        }

        foreach ($wanted as $key => $a) {
            $key = (string) $key;
            $msg = mb_substr($a['message'], 0, 255);
            if (isset($activeByKey[$key])) {
                $cur = $activeByKey[$key];
                if ($cur['severity'] !== $a['severity'] || $cur['message'] !== $msg) {
                    $this->db->update('fleet_alerts', ['severity' => $a['severity'], 'message' => $msg], ['id' => $cur['id']]);
                    $updated++;
                }
            } elseif (!isset($known[$key])) {          // jamais traitée : on la crée. Déjà résolue : on la laisse tranquille
                               $this->db->insert('fleet_alerts', [
                    'type' => $a['type'], 'source' => 'system', 'severity' => $a['severity'],
                    'bus_id' => $a['bus_id'] ?? null, 'driver_id' => $a['driver_id'] ?? null, 'mission_id' => $a['mission_id'] ?? null,
                    'lat' => $a['lat'] ?? null, 'lng' => $a['lng'] ?? null,
                    'message' => $msg, 'dedup_key' => $key,
                ]);
                $created++;
            }
        }

        foreach ($active as $row) {
            if (!isset($wanted[$row['dedup_key']])) {
                $this->db->executeStatement(
                    "UPDATE fleet_alerts SET status = 'resolved', resolved_at = UTC_TIMESTAMP(3),
                            resolution_note = 'Résolue automatiquement (situation régularisée).' WHERE id = ?",
                    [$row['id']]
                );
                $resolved++;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'resolved' => $resolved];
    }
}