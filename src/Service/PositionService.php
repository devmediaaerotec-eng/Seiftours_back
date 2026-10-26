<?php
namespace App\Service;

use Doctrine\DBAL\Connection;

final class PositionService
{
    public function __construct(private Connection $db) {}

    /** @return array{0: bool, 1: ?string} [accepté, erreur] */
    public function record(int $busId, float $lat, float $lng, ?float $speed, ?int $heading, ?int $accuracy, ?\DateTimeImmutable $at = null): array
    {
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);
        $at = $at ? $at->setTimezone($utc) : $now;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            return [false, 'Coordonnées invalides.'];
        }
        if ($speed !== null && ($speed < 0 || $speed > 250)) {
            return [false, 'Vitesse invalide.'];
        }
        if ($heading !== null && ($heading < 0 || $heading > 360)) {
            return [false, 'Cap invalide.'];
        }
        if ($at > $now->modify('+2 minutes')) {
            return [false, 'Horodatage dans le futur.'];
        }
        if ($at < $now->modify('-24 hours')) {
            return [false, 'Horodatage trop ancien (24 h maximum).'];
        }
        $heading = $heading === null ? null : $heading % 360;

        $mission = $this->db->fetchAssociative(
            "SELECT id, driver_id FROM bus_missions WHERE bus_id = ? AND status = 'in_progress' ORDER BY starts_at DESC LIMIT 1",
            [$busId]
        );
        $missionId = $mission ? (int) $mission['id'] : null;
        $driverId = $mission
            ? (int) $mission['driver_id']
            : ($this->db->fetchOne('SELECT id FROM drivers WHERE assigned_bus_id = ? AND is_active = 1', [$busId]) ?: null);
        $driverId = $driverId ? (int) $driverId : null;
        $when = $at->format('Y-m-d H:i:s.v');

        $this->db->insert('vehicle_positions', [
            'bus_id' => $busId, 'mission_id' => $missionId, 'driver_id' => $driverId,
            'lat' => $lat, 'lng' => $lng, 'speed_kmh' => $speed, 'heading' => $heading,
            'accuracy_m' => $accuracy, 'recorded_at' => $when,
        ]);

        // Dernière position : une position plus ancienne n'écrase jamais une plus récente
        $this->db->executeStatement(
            'INSERT INTO bus_last_position (bus_id, mission_id, driver_id, lat, lng, speed_kmh, heading, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               mission_id = IF(VALUES(recorded_at) >= recorded_at, VALUES(mission_id), mission_id),
               driver_id  = IF(VALUES(recorded_at) >= recorded_at, VALUES(driver_id), driver_id),
               lat        = IF(VALUES(recorded_at) >= recorded_at, VALUES(lat), lat),
               lng        = IF(VALUES(recorded_at) >= recorded_at, VALUES(lng), lng),
               speed_kmh  = IF(VALUES(recorded_at) >= recorded_at, VALUES(speed_kmh), speed_kmh),
               heading    = IF(VALUES(recorded_at) >= recorded_at, VALUES(heading), heading),
               recorded_at = IF(VALUES(recorded_at) >= recorded_at, VALUES(recorded_at), recorded_at)',
            [$busId, $missionId, $driverId, $lat, $lng, $speed, $heading, $when]
        );

        return [true, null];
    }
}