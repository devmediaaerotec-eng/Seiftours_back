<?php
namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;

final class MissionPdf
{
    private const DAYS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    private const STATUS = [
        'planned' => 'Planifiée', 'in_progress' => 'En cours', 'completed' => 'Effectuée',
        'not_done' => 'Non effectuée', 'cancelled' => 'Annulée',
    ];

    public static function frDay(string $ymd): string
    {
        $d = new \DateTimeImmutable($ymd);
        return ucfirst(self::DAYS[(int) $d->format('N') - 1]) . ' ' . $d->format('d/m/Y');
    }

    private static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function hm(string $dt): string
    {
        return substr($dt, 11, 5);
    }

    private function row(array $m, bool $withDriver): string
    {
        $e = [self::class, 'e'];
        $route = implode(' → ', array_filter([$m['pickup_place'], $m['destination']]));
        $end = substr($m['starts_at'], 0, 10) !== substr($m['ends_at'], 0, 10)
            ? ' <small>(→ ' . substr($m['ends_at'], 8, 2) . '/' . substr($m['ends_at'], 5, 2) . ')</small>' : '';
        $status = self::STATUS[$m['status']] ?? $m['status'];
        $late = !empty($m['is_late']) ? ' <b class="late">EN RETARD</b>' : '';

        return '<tr>'
            . '<td class="t">' . self::hm($m['starts_at']) . '–' . self::hm($m['ends_at']) . $end . '</td>'
            . '<td><b>' . $e($m['title']) . '</b><br><small>' . $e($m['reference'])
            . ($m['client_name'] ? ' · ' . $e($m['client_name']) : '') . '</small>'
            . ($m['notes'] ? '<br><small class="n">⚠ ' . $e($m['notes']) . '</small>' : '') . '</td>'
            . ($withDriver ? '<td>' . $e($m['driver_name']) . '</td>' : '')
            . '<td>' . $e($m['bus_plate']) . '<br><small>' . $e($m['bus_plate']) . '</small></td>'
            . '<td>' . $e($route ?: '—') . ($m['pax'] ? '<br><small>' . (int) $m['pax'] . ' pers.</small>' : '') . '</td>'
            . '<td>' . $status . $late . '</td></tr>';
    }

    private function table(array $items, bool $withDriver, string $currentDay = ''): string
    {
        $head = '<tr><th>Horaires</th><th>Mission</th>' . ($withDriver ? '<th>Chauffeur</th>' : '')
            . '<th>Bus</th><th>Trajet</th><th>Statut</th></tr>';
        return '<table>' . $head . implode('', array_map(fn ($m) => $this->row($m, $withDriver), $items)) . '</table>';
    }

    /**
     * mode 'driver' : une section (page) par chauffeur, puis un sous-titre par jour.
     * mode 'flat'   : un seul tableau chronologique (export de la liste).
     */
    public function render(string $title, string $subtitle, array $items, string $mode = 'driver'): string
    {
        $body = '';
        if ($mode === 'flat') {
            $body = $items ? $this->table($items, true) : '<p>Aucune mission.</p>';
        } else {
            $byDriver = [];
            foreach ($items as $m) {
                $byDriver[$m['driver_name']][substr($m['starts_at'], 0, 10)][] = $m;
            }
            ksort($byDriver);
            $first = true;
            foreach ($byDriver as $name => $days) {
                ksort($days);
                $body .= '<div class="' . ($first ? '' : 'pb') . '"><h2>' . self::e($name) . '</h2>';
                foreach ($days as $day => $list) {
                    usort($list, static fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));
                    $body .= '<h3>' . self::frDay($day) . '</h3>' . $this->table($list, false);
                }
                $body .= '</div>';
                $first = false;
            }
            if (!$byDriver) {
                $body = '<p>Aucune mission.</p>';
            }
        }

        $html = '<html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#1e293b}
            h1{font-size:17px;margin:0} .sub{color:#64748b;margin:2px 0 14px}
            h2{font-size:14px;margin:0 0 6px;padding-bottom:4px;border-bottom:2px solid #0c7a93;color:#0b6479}
            h3{font-size:11px;margin:12px 0 4px;color:#475569}
            table{width:100%;border-collapse:collapse;margin-bottom:6px}
            th{background:#f1f5f9;text-align:left;padding:5px;font-size:9px;text-transform:uppercase;color:#64748b}
            td{padding:5px;border-top:1px solid #e2e8f0;vertical-align:top}
            td.t{white-space:nowrap;font-weight:bold} small{color:#64748b} .n{color:#92400e}
            .late{color:#b91c1c} .pb{page-break-before:always}
            .foot{position:fixed;bottom:-10px;font-size:8px;color:#94a3b8}
        </style></head><body>'
            . '<h1>' . self::e($title) . '</h1><div class="sub">' . self::e($subtitle) . '</div>'
            . $body
            . '<div class="foot">Généré le ' . (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Tunis')))->format('d/m/Y H:i') . '</div>'
            . '</body></html>';

        $opts = new Options();
        $opts->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', $mode === 'flat' ? 'landscape' : 'portrait');
        $pdf->render();

        return $pdf->output();
    }
}