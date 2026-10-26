<?php
namespace App\Command;

use App\Service\PositionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:fleet:simulate', description: 'DEV : simule des positions GPS pour les bus en mission')]
final class FleetSimulateCommand extends Command
{
    /** Tracés approximatifs [lat, lng] : suffisants pour une démonstration, pas pour la navigation. */
    private const ROUTES = [
        [[35.8256, 10.6369], [36.1360, 10.3820], [36.4000, 10.6167], [36.8065, 10.1815]],   // Sousse → Tunis
        [[35.8256, 10.6369], [35.7643, 10.8113], [35.5047, 11.0622]],                       // Sousse → Monastir → Mahdia
        [[36.8065, 10.1815], [36.4000, 10.6167], [36.4513, 10.7353]],                       // Tunis → Hammamet → Nabeul
        [[35.8256, 10.6369], [35.6781, 10.0963]],                                            // Sousse → Kairouan
        [[35.7581, 10.7547], [35.8256, 10.6369], [35.8939, 10.5953]],                       // Aéroport de Monastir → Port El Kantaoui
    ];
    private const CRUISE_KMH = 70;

    public function __construct(
        private Connection $db,
        private PositionService $positions,
        #[Autowire('%kernel.environment%')] private string $env,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('minutes', null, InputOption::VALUE_REQUIRED, 'Durée de la simulation en minutes', '10')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Un seul passage')
            ->addOption('backfill', null, InputOption::VALUE_REQUIRED, "Génère l'historique des N dernières minutes", '0')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Tous les bus actifs (par défaut : seulement ceux en mission)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->env !== 'dev') {
            $output->writeln("<error>Commande réservée à l'environnement de développement.</error>");
            return Command::FAILURE;
        }

        $ids = $input->getOption('all')
            ? $this->db->fetchFirstColumn('SELECT id FROM buses WHERE is_active = 1')
            : $this->db->fetchFirstColumn("SELECT DISTINCT bus_id FROM bus_missions WHERE status = 'in_progress'");
        $ids = array_map('intval', $ids);
        if (!$ids) {
            $output->writeln("Aucun bus en mission. Lancez d'abord le script de démonstration, ou utilisez --all.");
            return Command::SUCCESS;
        }

        $back = max(0, min(720, (int) $input->getOption('backfill')));
        if ($back > 0) {
            for ($t = time() - $back * 60; $t < time(); $t += 30) {
                foreach ($ids as $id) {
                    $this->tick($id, $t);
                }
            }
            $output->writeln("Historique généré ($back min).");
        }

        $until = time() + max(0, (int) $input->getOption('minutes')) * 60;
        do {
            foreach ($ids as $id) {
                $this->tick($id, time());
            }
            $output->writeln(date('H:i:s') . ' — ' . count($ids) . ' bus mis à jour');
            if ($input->getOption('once')) {
                break;
            }
            sleep(5);
        } while (time() < $until);

        return Command::SUCCESS;
    }

    /** Position d'un bus = fonction du temps : chaque passage est cohérent avec le précédent. */
    private function tick(int $busId, int $ts): void
    {
        $route  = self::ROUTES[$busId % count(self::ROUTES)];
        $total  = $this->length($route);
        $period = max(600.0, 2 * $total / self::CRUISE_KMH * 3600);
        $phase  = fmod($ts + $busId * 977, $period) / $period;
        $forward = $phase < 0.5;
        $u = $forward ? $phase * 2 : (1 - $phase) * 2;

        [$lat, $lng, $bearing] = $this->pointAt($route, $u * $total);
        if (!$forward) {
            $bearing = fmod($bearing + 180, 360);
        }

        $speed = 72 + 14 * sin($ts / 37 + $busId);
        if ($busId % 4 === 0 && intdiv($ts, 120) % 5 === 0) {
            $speed = 118 + 4 * sin($ts / 13);          // dépassement volontaire : 2 min toutes les 10 min
        }
        if ($u < 0.015 || $u > 0.985) {
            $speed = 0.0;                              // arrêt au terminus
        }

        $lat += 0.00004 * sin($ts / 7);
        $lng += 0.00004 * cos($ts / 9);
        $this->positions->record($busId, $lat, $lng, round($speed, 1), (int) round($bearing), 8, new \DateTimeImmutable('@' . $ts));
    }

    private function length(array $route): float
    {
        $sum = 0.0;
        for ($i = 0; $i < count($route) - 1; $i++) {
            $sum += $this->km($route[$i], $route[$i + 1]);
        }
        return $sum;
    }

    private function pointAt(array $route, float $dist): array
    {
        $acc = 0.0;
        $last = count($route) - 2;
        for ($i = 0; $i <= $last; $i++) {
            $len = $this->km($route[$i], $route[$i + 1]);
            if ($dist <= $acc + $len || $i === $last) {
                $f = $len > 0 ? min(1.0, max(0.0, ($dist - $acc) / $len)) : 0.0;
                return [
                    $route[$i][0] + ($route[$i + 1][0] - $route[$i][0]) * $f,
                    $route[$i][1] + ($route[$i + 1][1] - $route[$i][1]) * $f,
                    $this->bearing($route[$i], $route[$i + 1]),
                ];
            }
            $acc += $len;
        }
        return [$route[0][0], $route[0][1], 0.0];
    }

    private function km(array $a, array $b): float
    {
        $dLat = deg2rad($b[0] - $a[0]);
        $dLng = deg2rad($b[1] - $a[1]);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLng / 2) ** 2;
        return 6371 * 2 * asin(min(1.0, sqrt($h)));
    }

    private function bearing(array $a, array $b): float
    {
        $p1 = deg2rad($a[0]);
        $p2 = deg2rad($b[0]);
        $dl = deg2rad($b[1] - $a[1]);
        $y = sin($dl) * cos($p2);
        $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);
        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }
}