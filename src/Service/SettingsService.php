<?php
namespace App\Service;

use Doctrine\DBAL\Connection;

final class SettingsService
{
    public const GROUPS = [
        'booking' => 'Réservations et paiement',
        'fleet'   => 'Flotte et alertes',
    ];

    public const DEFS = [
        'deposit_rate' => [
            'group' => 'booking', 'type' => 'rate', 'min' => 0, 'max' => 1, 'default' => 0.15,
            'label' => 'Acompte payé en ligne',
            'description' => "Part du total demandée en ligne pour confirmer une réservation avec acompte.",
        ],
        'deposit_min_millimes' => [
            'group' => 'booking', 'type' => 'millimes', 'min' => 0, 'max' => 100000000, 'default' => 30000,
            'label' => 'Acompte minimum',
            'description' => "L'acompte ne sera jamais inférieur à ce montant.",
        ],
        'offer_ttl_minutes' => [
            'group' => 'booking', 'type' => 'int', 'unit' => 'min', 'min' => 1, 'max' => 120, 'default' => 10,
            'label' => "Validité d'un tarif affiché",
            'description' => "Durée pendant laquelle le prix d'un hôtel reste garanti au client.",
        ],
        'payment_session_minutes' => [
            'group' => 'booking', 'type' => 'int', 'unit' => 'min', 'min' => 5, 'max' => 120, 'default' => 20,
            'label' => 'Durée de la session de paiement',
            'description' => 'Temps laissé au client pour payer sur ClicToPay avant expiration.',
        ],
        'fleet.control_overdue_hours' => [
            'group' => 'fleet', 'type' => 'int', 'unit' => 'h', 'min' => 0, 'max' => 168, 'default' => 24,
            'label' => 'Alerte « mission non contrôlée »',
            'description' => "Alerte si une mission terminée n'est pas contrôlée après ce délai. 0 = désactivée.",
        ],
        'fleet.unsent_program_hours' => [
            'group' => 'fleet', 'type' => 'int', 'unit' => 'h', 'min' => 0, 'max' => 168, 'default' => 24,
            'label' => 'Alerte « programme non envoyé »',
            'description' => "Alerte si une mission commence dans ce délai sans que le programme ait été envoyé au chauffeur. 0 = désactivée.",
        ],
        'fleet.late_start_alerts' => [
            'group' => 'fleet', 'type' => 'bool', 'default' => false,
            'label' => 'Alerte « départ en retard »',
            'description' => "Alerte si une mission planifiée n'a pas été démarrée. À activer quand les départs sont marqués dans l'application.",
        ],
        'fleet.late_start_minutes' => [
            'group' => 'fleet', 'type' => 'int', 'unit' => 'min', 'min' => 5, 'max' => 240, 'default' => 15,
            'label' => 'Retard toléré avant alerte',
            'description' => "Utilisé par l'alerte « départ en retard ».",
        ],

                'fleet.no_signal_minutes' => [
            'group' => 'fleet', 'type' => 'int', 'unit' => 'min', 'min' => 2, 'max' => 120, 'default' => 5,
            'label' => 'Alerte « signal perdu »',
            'description' => "Alerte si un bus en mission, qui transmettait sa position, n'a plus rien envoyé depuis ce délai.",
        ],
        'fleet.speed_limit_kmh' => [
            'group' => 'fleet', 'type' => 'int', 'unit' => 'km/h', 'min' => 0, 'max' => 200, 'default' => 100,
            'label' => 'Vitesse maximale autorisée',
            'description' => "Alerte « excès de vitesse » au-delà de cette vitesse. 0 = désactivée. À ajuster selon votre règle interne.",
        ],
    ];

    private ?array $rows = null;

    public function __construct(private Connection $db) {}

    private function rows(): array
    {
        if ($this->rows === null) {
            $list = $this->db->fetchAllAssociative(
                'SELECT s.setting_key, s.setting_value, s.updated_at, u.full_name AS updated_by_name
                 FROM settings s LEFT JOIN staff_users u ON u.id = s.updated_by'
            );
            $this->rows = array_column($list, null, 'setting_key');
        }
        return $this->rows;
    }

    public function get(string $key): mixed
    {
        $default = self::DEFS[$key]['default'] ?? null;
        $row = $this->rows()[$key] ?? null;
        if ($row === null) {
            return $default;
        }
        return json_decode((string) $row['setting_value'], true) ?? $default;
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function describe(): array
    {
        $groups = [];
        foreach (self::GROUPS as $id => $label) {
            $groups[$id] = ['id' => $id, 'label' => $label, 'items' => []];
        }
        foreach (self::DEFS as $key => $d) {
            $row = $this->rows()[$key] ?? null;
            $groups[$d['group']]['items'][] = [
                'key' => $key, 'label' => $d['label'], 'description' => $d['description'], 'type' => $d['type'],
                'unit' => $d['unit'] ?? null, 'min' => $d['min'] ?? null, 'max' => $d['max'] ?? null,
                'default' => $d['default'], 'value' => $this->get($key),
                'updated_at' => $row['updated_at'] ?? null, 'updated_by_name' => $row['updated_by_name'] ?? null,
            ];
        }
        return array_values($groups);
    }

    /** @return array{0: mixed, 1: ?string} */
    public function validate(string $key, mixed $v): array
    {
        $d = self::DEFS[$key] ?? null;
        if (!$d) {
            return [null, 'Réglage inconnu.'];
        }
        switch ($d['type']) {
            case 'bool':
                return is_bool($v) ? [$v, null] : [null, 'Valeur oui / non attendue.'];
            case 'rate':
                if (!is_int($v) && !is_float($v)) {
                    return [null, 'Nombre requis.'];
                }
                $v = round((float) $v, 4);
                if ($v < 0 || $v > 1) {
                    return [null, 'Pourcentage entre 0 et 100.'];
                }
                return [$v, null];
            default:   // int, millimes
                if (!is_int($v)) {
                    return [null, 'Nombre entier requis.'];
                }
                if ($v < $d['min'] || $v > $d['max']) {
                    return [null, $d['type'] === 'millimes' ? 'Montant invalide.' : "Valeur entre {$d['min']} et {$d['max']} requise."];
                }
                return [$v, null];
        }
    }

    /** @return array{0: array, 1: array<string,string>, 2: array}  [avant, erreurs, après] */
    public function save(array $values, ?int $staffId): array
    {
        $errors = [];
        $clean = [];
        foreach ($values as $k => $v) {
            [$val, $err] = $this->validate((string) $k, $v);
            $err !== null ? $errors[(string) $k] = $err : $clean[(string) $k] = $val;
        }
        if ($errors) {
            return [[], $errors, []];
        }
        $before = [];
        foreach ($clean as $k => $_) {
            $before[$k] = $this->get($k);
        }

        $this->db->beginTransaction();
        try {
            foreach ($clean as $k => $val) {
                $this->db->executeStatement(
                    'INSERT INTO settings (setting_key, setting_value, description, updated_by) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
                    [$k, json_encode($val), self::DEFS[$k]['label'], $staffId]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->rows = null;

        return [$before, [], $clean];
    }
}