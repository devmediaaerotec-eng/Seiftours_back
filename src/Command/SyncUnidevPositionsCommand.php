<?php
namespace App\Command;

use App\Service\PositionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Synchronise les positions GPS Unidevpro vers vehicle_positions / bus_last_position
 * via PositionService. Le VEH_ID d'Unidevpro est stocké dans buses.gps_device_id.
 *
 *   php bin/console fleet:sync-positions --once          (test manuel)
 *   php bin/console fleet:sync-positions --interval=20   (en continu, sous supervisor/systemd)
 */
#[AsCommand(name: 'fleet:sync-positions', description: 'Synchronise les positions GPS depuis Unidevpro')]
final class SyncUnidevPositionsCommand extends Command
{
    private const MIN_MOVE_METERS = 15; // en dessous : bruit GPS, on garde le cap précédent

    public function __construct(
        private Connection $db,
        private HttpClientInterface $http,
        private PositionService $positions,
        private string $baseUrl,
        private string $token,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Secondes entre deux synchronisations', '20')
             ->addOption('once', null, InputOption::VALUE_NONE, 'Une seule synchronisation puis arrêt');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $interval = max(10, (int) $in->getOption('interval'));
        do {
            try {
                $this->sync($out);
            } catch (\Throwable $e) {
                $out->writeln('<error>[' . gmdate('H:i:s') . '] ' . $e->getMessage() . '</error>');
                if ($in->getOption('once')) {
                    return Command::FAILURE;
                }
            }
            if ($in->getOption('once')) {
                break;
            }
            sleep($interval);
        } while (true);

        return Command::SUCCESS;
    }

    private function sync(OutputInterface $out): void
    {
        if ($this->token === '') {
            throw new \RuntimeException('UNIDEV_TOKEN est vide : renseignez-le dans .env.local');
        }

        $vehicles = $this->http->request('GET', rtrim($this->baseUrl, '/') . '/vehicles', [
            'query'       => ['includePosition' => 'true'],
            'auth_bearer' => $this->token,
            'timeout'     => 15,
        ])->toArray(); // lève une exception si 401 / 5xx

        $byDevice = $byPlate = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, plate_number, gps_device_id FROM buses WHERE is_active = 1') as $b) {
            if ($b['gps_device_id']) {
                $byDevice[$b['gps_device_id']] = $b;
            }
            if ($k = $this->plateKey((string) $b['plate_number'])) {
                $byPlate[$k] = $b;
            }
        }
        $last = $this->db->fetchAllAssociativeIndexed('SELECT bus_id, lat, lng, heading, recorded_at FROM bus_last_position');
        $utc  = new \DateTimeZone('UTC');

        $saved = 0;
        $unmatched = $errors = [];

        foreach ($vehicles as $v) {
            $vid = (string) ($v['VEH_ID'] ?? '');
            if ($vid === '') {
                continue;
            }

            // 1) rapprochement par gps_device_id ; 2) sinon par immatriculation, puis on mémorise le lien
            $bus = $byDevice[$vid] ?? null;
            if (!$bus) {
                $cand = $byPlate[$this->plateKey((string) ($v['VEH_NAME'] ?? ''))] ?? null;
                if ($cand && empty($cand['gps_device_id'])) {
                    $this->db->update('buses', ['gps_device_id' => $vid], ['id' => $cand['id']]);
                    $bus = $byDevice[$vid] = $cand;
                }
            }
            if (!$bus) {
                $unmatched[] = $vid . ' (' . ($v['VEH_NAME'] ?? '?') . ')';
                continue;
            }

            $d = $v['POSITION']['DETAILS'] ?? [];
            if (!isset($d['latitude'], $d['longitude'], $d['time'])) {
                continue; // pas encore de position
            }
            $lat = (float) $d['latitude'];
            $lng = (float) $d['longitude'];
            $at  = new \DateTimeImmutable($d['time']);
            $busId = (int) $bus['id'];
            $p = $last[$busId] ?? null;

            if ($p && $at <= new \DateTimeImmutable($p['recorded_at'], $utc)) {
                continue; // déjà enregistrée
            }

            // Cap : calculé depuis la position précédente, sinon on garde l'ancien
            $heading = isset($p['heading']) ? (int) $p['heading'] : null;
            if ($p && $this->haversine((float) $p['lat'], (float) $p['lng'], $lat, $lng) >= self::MIN_MOVE_METERS) {
                $heading = (int) round($this->bearing((float) $p['lat'], (float) $p['lng'], $lat, $lng));
            }

            // Vitesse fournie par Unidevpro (à confirmer : km/h ?) ; contact coupé = arrêt
            $speed = isset($d['speed']) && is_numeric($d['speed']) ? round((float) $d['speed'], 1) : null;
            if (($d['ignition'] ?? true) === false) {
                $speed = 0.0;
            }

            // Contact et kilométrage (l'odomètre Unidevpro semble être en mètres : à confirmer)
            $ignition = array_key_exists('ignition', $d) ? (bool) $d['ignition'] : null;
            $odo = $v['POSITION']['ODOMETER'] ?? null;
            $odoKm = is_numeric($odo) ? round((float) $odo / 1000, 1) : null;

            [$ok, $err] = $this->positions->record($busId, $lat, $lng, $speed, $heading, null, $at, false, $ignition, $odoKm);
            $ok ? $saved++ : $errors[] = ($v['VEH_NAME'] ?? $vid) . ' : ' . $err;
        }

        $out->writeln(sprintf('[%s] %d véhicules reçus, %d nouvelles positions', gmdate('H:i:s'), count($vehicles), $saved));
        foreach ($errors as $e) {
            $out->writeln("<comment>  rejeté : $e</comment>");
        }
        if ($unmatched) {
            $out->writeln('<comment>  sans bus correspondant (renseignez buses.gps_device_id) : ' . implode(', ', $unmatched) . '</comment>');
        }
    }

    /** "6736 TU 123 Tayota" / "6736 TU 123" → "6736TU123" ; vide si aucune immatriculation reconnue. */
    private function plateKey(string $s): string
    {
        return preg_match('/(\d{1,4})\s*TU\s*(\d{1,4})/i', $s, $m) ? $m[1] . 'TU' . $m[2] : '';
    }

    /** Distance en mètres. */
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = deg2rad($lat2 - $lat1);
        $b = deg2rad($lng2 - $lng1);
        $h = sin($a / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($b / 2) ** 2;

        return 2 * 6371000 * asin(min(1, sqrt($h)));
    }

    /** Cap en degrés (0 = nord). */
    private function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dl = deg2rad($lng2 - $lng1);

        return fmod(rad2deg(atan2(sin($dl) * cos($p2), cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl))) + 360, 360);
    }
}