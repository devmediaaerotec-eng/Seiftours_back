<?php
namespace App\Service;

use Doctrine\DBAL\Connection;

final class MissionService
{
    public const TYPES    = ['rental', 'transfer', 'excursion', 'circuit', 'shuttle', 'other'];
    public const STATUSES = ['planned', 'in_progress', 'completed', 'not_done', 'cancelled'];
    /** Statuts qui occupent le bus et le chauffeur. */
    public const ACTIVE   = ['planned', 'in_progress', 'completed'];

    private const STATUS_LABELS = [
        'planned' => 'Planifiée', 'in_progress' => 'En cours', 'completed' => 'Effectuée',
        'not_done' => 'Non effectuée', 'cancelled' => 'Annulée',
    ];
    private const TYPE_LABELS = [
        'rental' => 'Location', 'transfer' => 'Transfert', 'excursion' => 'Excursion',
        'circuit' => 'Circuit', 'shuttle' => 'Navette', 'other' => 'Autre',
    ];
    private const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    /** champ => [libellé, champ affiché] */
    private const TRACKED = [
        'bus_id' => ['Bus', 'bus_code'], 'driver_id' => ['Chauffeur', 'driver_name'],
        'mission_type' => ['Type', 'mission_type'], 'title' => ['Intitulé', 'title'],
        'starts_at' => ['Début', 'starts_at'], 'ends_at' => ['Fin', 'ends_at'],
        'pickup_place' => ['Lieu de départ', 'pickup_place'], 'destination' => ['Destination', 'destination'],
        'pax' => ['Passagers', 'pax'], 'client_name' => ['Client', 'client_name'],
        'client_phone' => ['Tél. client', 'client_phone'], 'price_millimes' => ['Prix', 'price_millimes'],
        'notes' => ['Consignes', 'notes'],
    ];
    /** Modifications qui obligent à renvoyer le programme au chauffeur. */
    private const PLANNING = ['bus_id', 'driver_id', 'mission_type', 'title', 'starts_at', 'ends_at',
                              'pickup_place', 'destination', 'pax', 'notes'];

    public function __construct(private Connection $db) {}

    // ------------------------------------------------------------------ dates

    public function nowLocal(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Tunis')))->format('Y-m-d H:i:s');
    }

    public function parseLocal(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim(str_replace('T', ' ', $v));
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!' . $f, $v, new \DateTimeZone('UTC'));
            if ($d !== false && $d->format($f) === $v) {
                return $d;
            }
        }
        return null;
    }

    private function fr(string $ymd): string
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10))->format('d/m/Y');
    }

    public function frDay(\DateTimeImmutable $d): string
    {
        return self::DAYS[(int) $d->format('N') - 1] . ' ' . $d->format('d/m');
    }

    private function range(string $a, string $b): string
    {
        $x = new \DateTimeImmutable($a);
        $y = new \DateTimeImmutable($b);
        return $x->format('d/m') === $y->format('d/m')
            ? $x->format('d/m H:i') . '–' . $y->format('H:i')
            : $x->format('d/m H:i') . ' → ' . $y->format('d/m H:i');
    }

    private function str(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    // ------------------------------------------------------------- validation

    /**
     * @return array{0: array, 1: array<string,string>, 2: string[]}  [ligne, erreurs bloquantes, avertissements]
     */
    public function prepare(array $in, ?array $existing, bool $checkConflicts = false): array
    {
        $errors = [];
        $warnings = [];
        $row = [];

        $type = $in['mission_type'] ?? 'rental';
        if (!in_array($type, self::TYPES, true)) {
            $errors['mission_type'] = 'Type invalide.';
        }
        $row['mission_type'] = $type;

        $title = $this->str($in['title'] ?? null);
        if ($title === null) {
            $errors['title'] = 'Champ requis.';
        } elseif (mb_strlen($title) > 190) {
            $errors['title'] = '190 caractères maximum.';
        }
        $row['title'] = $title;

        $start = $this->parseLocal($in['starts_at'] ?? null);
        $end   = $this->parseLocal($in['ends_at'] ?? null);
        if (!$start) {
            $errors['starts_at'] = 'Date et heure de début requises.';
        }
        if (!$end) {
            $errors['ends_at'] = 'Date et heure de fin requises.';
        }
        if ($start && $end) {
            if ($end <= $start) {
                $errors['ends_at'] = 'La fin doit être après le début.';
            } elseif ($end > $start->modify('+90 days')) {
                $errors['ends_at'] = 'Durée maximale : 90 jours.';
            }
        }
        $row['starts_at'] = $start?->format('Y-m-d H:i:s');
        $row['ends_at']   = $end?->format('Y-m-d H:i:s');

        foreach (['bus_id', 'driver_id'] as $k) {
            $v = $in[$k] ?? null;
            if (!is_int($v) || $v < 1) {
                $errors[$k] = 'Champ requis.';
            }
            $row[$k] = is_int($v) ? $v : null;
        }

        foreach (['pickup_place' => 190, 'destination' => 190, 'client_name' => 160] as $k => $max) {
            $v = $this->str($in[$k] ?? null);
            if ($v !== null && mb_strlen($v) > $max) {
                $errors[$k] = "$max caractères maximum.";
            }
            $row[$k] = $v;
        }
        $phone = $this->str($in['client_phone'] ?? null);
        if ($phone !== null && !preg_match('/^[+0-9 ().\-]{6,30}$/', $phone)) {
            $errors['client_phone'] = 'Numéro invalide.';
        }
        $row['client_phone'] = $phone;

        $notes = $this->str($in['notes'] ?? null);
        if ($notes !== null && mb_strlen($notes) > 5000) {
            $errors['notes'] = '5000 caractères maximum.';
        }
        $row['notes'] = $notes;

        $pax = $in['pax'] ?? null;
        if ($pax !== null && (!is_int($pax) || $pax < 1 || $pax > 90)) {
            $errors['pax'] = 'Entre 1 et 90.';
        }
        $row['pax'] = is_int($pax) ? $pax : null;

        $price = $in['price_millimes'] ?? null;
        if ($price !== null && (!is_int($price) || $price < 0)) {
            $errors['price_millimes'] = 'Montant invalide.';
        }
        $row['price_millimes'] = is_int($price) ? $price : null;

        // ---- règles qui demandent la base de données
        $now    = $this->nowLocal();
        $future = $row['ends_at'] !== null && $row['ends_at'] >= $now;
        $endDay = $row['ends_at'] !== null ? substr($row['ends_at'], 0, 10) : null;

        if (!isset($errors['bus_id'])) {
            $bus = $this->db->fetchAssociative(
                'SELECT id, code, seats, status, is_active, insurance_expires_on, inspection_expires_on FROM buses WHERE id = ?',
                [$row['bus_id']]
            );
            $same = $existing && (int) $existing['bus_id'] === $row['bus_id'];
            if (!$bus) {
                $errors['bus_id'] = 'Bus introuvable.';
            } elseif (!$bus['is_active'] && !$same) {
                $errors['bus_id'] = 'Ce bus est archivé.';
            } else {
                if ($row['pax'] !== null && $row['pax'] > (int) $bus['seats']) {
                    $errors['pax'] = "Ce bus n'a que {$bus['seats']} places.";
                }
                if ($future && $endDay !== null) {
                    foreach (['insurance_expires_on' => 'Assurance', 'inspection_expires_on' => 'Visite technique'] as $col => $label) {
                        if ($bus[$col] !== null && $bus[$col] < $endDay) {
                            $errors['bus_id'] = "$label du bus {$bus['code']} expirée le " . $this->fr($bus[$col]) . '.';
                            break;
                        }
                    }
                    if (in_array($bus['status'], ['maintenance', 'out_of_service'], true)) {
                        $warnings[] = "Le bus {$bus['code']} est actuellement "
                            . ($bus['status'] === 'maintenance' ? 'en maintenance.' : 'hors service.');
                    }
                }
            }
        }

        if (!isset($errors['driver_id'])) {
            $driver = $this->db->fetchAssociative(
                "SELECT id, CONCAT(first_name, ' ', last_name) AS name, status, is_active, license_expires_on FROM drivers WHERE id = ?",
                [$row['driver_id']]
            );
            $same = $existing && (int) $existing['driver_id'] === $row['driver_id'];
            if (!$driver) {
                $errors['driver_id'] = 'Chauffeur introuvable.';
            } elseif (!$driver['is_active'] && !$same) {
                $errors['driver_id'] = 'Ce chauffeur est archivé.';
            } elseif ($future && $endDay !== null) {
                if ($driver['status'] === 'suspended') {
                    $errors['driver_id'] = "{$driver['name']} est suspendu.";
                } elseif ($driver['license_expires_on'] < $endDay) {
                    $errors['driver_id'] = "Permis de {$driver['name']} expiré le " . $this->fr($driver['license_expires_on']) . '.';
                } elseif ($driver['status'] === 'on_leave') {
                    $warnings[] = "{$driver['name']} est actuellement en congé.";
                }
            }
        }

        $activeAfter = $checkConflicts || !$existing || in_array($existing['status'], self::ACTIVE, true);
        if ($activeAfter && !array_intersect_key($errors, array_flip(['bus_id', 'driver_id', 'starts_at', 'ends_at']))) {
            foreach ($this->conflicts($row['bus_id'], $row['driver_id'], $row['starts_at'], $row['ends_at'], (int) ($existing['id'] ?? 0)) as $c) {
                $when = $this->range($c['starts_at'], $c['ends_at']);
                if ((int) $c['bus_id'] === $row['bus_id']) {
                    $errors['bus_id'] ??= "Ce bus est déjà pris : mission {$c['reference']} ($when).";
                }
                if ((int) $c['driver_id'] === $row['driver_id']) {
                    $errors['driver_id'] ??= "Ce chauffeur est déjà pris : mission {$c['reference']} ($when).";
                }
            }
        }

        return [$row, $errors, $warnings];
    }

    public function conflicts(int $busId, int $driverId, string $start, string $end, int $exclude = 0): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, reference, title, bus_id, driver_id, starts_at, ends_at
             FROM bus_missions
             WHERE id <> ? AND status IN ('planned','in_progress','completed')
               AND starts_at < ? AND ends_at > ? AND (bus_id = ? OR driver_id = ?)",
            [$exclude, $end, $start, $busId, $driverId]
        );
    }

    public function describeConflict(array $c): string
    {
        return "mission {$c['reference']} (" . $this->range($c['starts_at'], $c['ends_at']) . ')';
    }

    /** Bus et chauffeurs déjà pris sur une période (pour griser les listes du formulaire). */
    public function busy(string $start, string $end, int $exclude = 0): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT reference, title, bus_id, driver_id, starts_at, ends_at
             FROM bus_missions
             WHERE id <> ? AND status IN ('planned','in_progress','completed') AND starts_at < ? AND ends_at > ?",
            [$exclude, $end, $start]
        );
        $out = ['buses' => [], 'drivers' => []];
        foreach ($rows as $r) {
            $info = ['reference' => $r['reference'], 'title' => $r['title'], 'starts_at' => $r['starts_at'], 'ends_at' => $r['ends_at']];
            $out['buses'][(int) $r['bus_id']]       ??= $info;
            $out['drivers'][(int) $r['driver_id']]  ??= $info;
        }
        return $out;
    }

    /** Verrouille le bus et le chauffeur pour éviter deux affectations simultanées. */
    public function lock(mixed $busId, mixed $driverId): void
    {
        if (is_int($busId) && $busId > 0) {
            $this->db->fetchOne('SELECT id FROM buses WHERE id = ? FOR UPDATE', [$busId]);
        }
        if (is_int($driverId) && $driverId > 0) {
            $this->db->fetchOne('SELECT id FROM drivers WHERE id = ? FOR UPDATE', [$driverId]);
        }
    }

    public function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $ref = 'MS';
            for ($i = 0; $i < 8; $i++) {
                $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while ($this->db->fetchOne('SELECT 1 FROM bus_missions WHERE reference = ?', [$ref]));
        return $ref;
    }

    // ------------------------------------------------------------- historique

    public function diff(array $a, array $b): array
    {
        $out = [];
        foreach (self::TRACKED as $key => [$label, $disp]) {
            if ((string) ($a[$key] ?? '') === (string) ($b[$key] ?? '')) {
                continue;
            }
            $out[$key] = ['label' => $label, 'from' => $this->show($disp, $a[$disp] ?? null), 'to' => $this->show($disp, $b[$disp] ?? null)];
        }
        return $out;
    }

    public function summarize(array $changes): string
    {
        return mb_substr(implode(' · ', array_map(
            static fn (array $c) => "{$c['label']} : {$c['from']} → {$c['to']}", $changes
        )), 0, 250);
    }

    public function touchesPlanning(array $changes): bool
    {
        return array_intersect(array_keys($changes), self::PLANNING) !== [];
    }

    private function show(string $key, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        return match ($key) {
            'starts_at', 'ends_at' => (new \DateTimeImmutable((string) $v))->format('d/m H:i'),
            'price_millimes'       => number_format(((int) $v) / 1000, 3, ',', ' ') . ' TND',
            'mission_type'         => self::TYPE_LABELS[$v] ?? (string) $v,
            'notes'                => '(modifiées)',
            default                => (string) $v,
        };
    }

    public function event(int $missionId, ?int $staffId, string $type, string $summary, ?array $details = null, ?string $note = null): void
    {
        $this->db->insert('mission_events', [
            'mission_id' => $missionId,
            'staff_id'   => $staffId,
            'event_type' => $type,
            'summary'    => mb_substr($summary, 0, 255),
            'details'    => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'note'       => $note,
        ]);
    }

    // ----------------------------------------------------------------- statuts

    public function applyStatus(array $mission, string $to, ?int $staffId, ?string $note, bool $logNote = true): void
    {
        $from = $mission['status'];
        $sql = 'UPDATE bus_missions SET status = ?';
        $params = [$to];

        if (in_array($to, ['completed', 'not_done', 'cancelled'], true)) {
            $sql .= ', controlled_at = UTC_TIMESTAMP(3), controlled_by = ?';
            $params[] = $staffId;
            if ($note !== null && $note !== '') {
                $sql .= ', control_note = ?';
                $params[] = $note;
            }
        } elseif ($to === 'planned') {
            $sql .= ', controlled_at = NULL, controlled_by = NULL';
        }
        $params[] = $mission['id'];
        $this->db->executeStatement($sql . ' WHERE id = ?', $params);

        if ($from !== $to) {
            $this->event((int) $mission['id'], $staffId, 'status',
                self::STATUS_LABELS[$from] . ' → ' . self::STATUS_LABELS[$to],
                ['from' => $from, 'to' => $to], $logNote ? $note : null);
        }
        $this->syncBusStatus((int) $mission['bus_id']);
    }

    /** « En mission » est posé et retiré automatiquement selon les missions en cours. */
    public function syncBusStatus(int $busId): void
    {
        $running = (int) $this->db->fetchOne("SELECT COUNT(*) FROM bus_missions WHERE bus_id = ? AND status = 'in_progress'", [$busId]);
        if ($running > 0) {
            $this->db->executeStatement("UPDATE buses SET status = 'on_mission' WHERE id = ? AND status = 'available'", [$busId]);
        } else {
            $this->db->executeStatement("UPDATE buses SET status = 'available' WHERE id = ? AND status = 'on_mission'", [$busId]);
        }
    }

    // ----------------------------------------------------- message au chauffeur

    public function programMessage(string $firstName, \DateTimeImmutable $monday, array $missions): string
    {
        $lines = [
            'Programme du ' . $this->frDay($monday) . ' au ' . $this->frDay($monday->modify('+6 days')) . ' — Seif Tours',
            '', "Bonjour $firstName,", '',
        ];
        $byDay = [];
        foreach ($missions as $m) {
            $byDay[max(substr($m['starts_at'], 0, 10), $monday->format('Y-m-d'))][] = $m;
        }
        ksort($byDay);

        foreach ($byDay as $day => $list) {
            $lines[] = $this->frDay(new \DateTimeImmutable($day));
            foreach ($list as $m) {
                $s = substr($m['starts_at'], 11, 5);
                $e = substr($m['ends_at'], 11, 5);
                $multi = substr($m['starts_at'], 0, 10) !== substr($m['ends_at'], 0, 10);
                $when = $multi ? "$s → " . $this->frDay(new \DateTimeImmutable($m['ends_at'])) . " $e" : "$s → $e";
                $lines[] = "• $when — {$m['title']}";

                $route = implode(' → ', array_filter([$m['pickup_place'], $m['destination']]));
                $meta = array_filter([
                    $route ?: null,
                    "Bus {$m['bus_code']} ({$m['bus_plate']})",
                    $m['pax'] ? "{$m['pax']} pers." : null,
                ]);
                $lines[] = '   ' . implode(' · ', $meta);
                if ($m['client_name'] || $m['client_phone']) {
                    $lines[] = '   Client : ' . trim(($m['client_name'] ?? '') . ' ' . ($m['client_phone'] ?? ''));
                }
                if ($m['notes']) {
                    $lines[] = '   Consignes : ' . preg_replace('/\s+/', ' ', $m['notes']);
                }
            }
            $lines[] = '';
        }
        $lines[] = 'Merci de confirmer la bonne réception.';

        return implode("\n", $lines);
    }
}