<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Registre des règlements fournisseurs (virements, chèques, espèces) : remplace le fichier Excel. */
#[Route('/api/admin/supplier-payments')]
#[IsGranted('perm:supplier_payments.read')]
final class SupplierPaymentAdminController extends AbstractController
{
    private const METHODS  = ['transfer' => 'VIREMENT', 'cheque' => 'CHEQUE', 'cash' => 'ESPECES', 'other' => 'AUTRE'];
    private const STATUSES = ['pending', 'cleared', 'cancelled'];
    private const MAX_ROWS = 2000;

    public function __construct(private Connection $db) {}

    /* ------------------------------------------------------------------ lecture */

    /** Filtres communs à la liste, aux totaux et à l'export */
    private function filters(Request $r): array
    {
        $where = ['1=1'];
        $p = [];
        $year = $r->query->getInt('year');
        if ($year >= 2000 && $year <= 2100) {
            $month = $r->query->getInt('month');
            if ($month >= 1 && $month <= 12) {
                $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
                $to = $from->modify('+1 month');
            } else {
                $from = new \DateTimeImmutable("$year-01-01");
                $to = $from->modify('+1 year');
            }
            $where[] = 'p.paid_on >= ? AND p.paid_on < ?';
            array_push($p, $from->format('Y-m-d'), $to->format('Y-m-d'));
        }
        if (($s = trim($r->query->getString('supplier'))) !== '') { $where[] = 'p.supplier_name = ?'; $p[] = $s; }
        $m = $r->query->getString('method');
        if (isset(self::METHODS[$m])) { $where[] = 'p.method = ?'; $p[] = $m; }
        $st = $r->query->getString('status');
        if (in_array($st, self::STATUSES, true)) { $where[] = 'p.status = ?'; $p[] = $st; }
        if (($q = trim($r->query->getString('q'))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(p.supplier_name LIKE ? OR p.label LIKE ? OR p.reference LIKE ? OR CAST(p.sales_invoices AS CHAR) LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }

        return [implode(' AND ', $where), $p];
    }

    private function shape(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['amount_millimes'] = (int) $row['amount_millimes'];
        $row['sales_invoices'] = json_decode((string) $row['sales_invoices'], true) ?: [];

        return $row;
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        [$cond, $p] = $this->filters($r);
        $items = $this->db->fetchAllAssociative(
            "SELECT p.*, s.full_name AS created_by_name FROM supplier_payments p
             LEFT JOIN staff_users s ON s.id = p.created_by
             WHERE $cond ORDER BY p.paid_on DESC, p.id DESC LIMIT " . self::MAX_ROWS, $p);

        // Les règlements annulés restent visibles mais ne comptent pas dans les totaux
        $live = "$cond AND p.status <> 'cancelled'";
        $byMethod = [];
        $total = 0;
        $count = 0;
        foreach ($this->db->fetchAllAssociative(
            "SELECT p.method, COUNT(*) AS n, SUM(p.amount_millimes) AS total FROM supplier_payments p WHERE $live GROUP BY p.method", $p) as $row) {
            $byMethod[$row['method']] = ['count' => (int) $row['n'], 'total' => (int) $row['total']];
            $total += (int) $row['total'];
            $count += (int) $row['n'];
        }
        $byMonth = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT DATE_FORMAT(p.paid_on, '%Y-%m') AS ym, COUNT(*) AS n, SUM(p.amount_millimes) AS total
             FROM supplier_payments p WHERE $live GROUP BY ym", $p) as $row) {
            $byMonth[$row['ym']] = ['count' => (int) $row['n'], 'total' => (int) $row['total']];
        }

        return $this->json([
            'items' => array_map([$this, 'shape'], $items),
            'summary' => ['count' => $count, 'total' => $total, 'by_method' => $byMethod, 'by_month' => $byMonth],
            'truncated' => count($items) >= self::MAX_ROWS,
        ]);
    }

    /** Listes des filtres et de l'auto-complétion du formulaire */
    #[Route('/meta', methods: ['GET'])]
    public function meta(): JsonResponse
    {
        return $this->json([
            'suppliers' => array_map(fn ($s) => ['name' => $s['name'], 'count' => (int) $s['n'], 'total' => (int) $s['total']],
                $this->db->fetchAllAssociative(
                    "SELECT supplier_name AS name, COUNT(*) AS n, SUM(amount_millimes) AS total
                     FROM supplier_payments WHERE status <> 'cancelled' GROUP BY supplier_name ORDER BY supplier_name")),
            'years' => array_map('intval', $this->db->fetchFirstColumn('SELECT DISTINCT YEAR(paid_on) FROM supplier_payments ORDER BY 1 DESC')),
            'banks' => $this->db->fetchFirstColumn("SELECT DISTINCT bank FROM supplier_payments WHERE bank IS NOT NULL ORDER BY bank"),
        ]);
    }

    /** Export CSV ouvrable dans Excel (mêmes colonnes que l'ancien fichier) */
    #[Route('/export', methods: ['GET'])]
    public function export(Request $r): Response
    {
        [$cond, $p] = $this->filters($r);
        $rows = $this->db->fetchAllAssociative(
            "SELECT p.* FROM supplier_payments p WHERE $cond ORDER BY p.paid_on, p.id", $p);

        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");                                  // BOM : Excel lit bien les accents
        fputcsv($out, ['Date', 'Nom du fournisseur', 'Mode de règlement', 'Montant', 'LIBELLEES', 'facture de vente', 'Statut', 'Banque'], ';');
        foreach ($rows as $row) {
            $mode = self::METHODS[$row['method']] . ($row['reference'] ? ' N°' . $row['reference'] : '');
            fputcsv($out, [
                (new \DateTimeImmutable($row['paid_on']))->format('d/m/Y'),
                $row['supplier_name'],
                $mode,
                number_format((int) $row['amount_millimes'] / 1000, 3, ',', ''),
                $row['label'],
                implode(' / ', json_decode((string) $row['sales_invoices'], true) ?: []),
                ['pending' => 'En attente', 'cleared' => 'Réglé', 'cancelled' => 'Annulé'][$row['status']],
                $row['bank'],
            ], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $name = 'reglements-fournisseurs' . ($r->query->getInt('year') ? '-' . $r->query->getInt('year') : '') . '.csv';

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    /* ------------------------------------------------------------------ écriture */

    #[Route('', methods: ['POST'])]
    #[IsGranted('perm:supplier_payments.manage')]
    public function create(Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        return $this->save($r, $user, null);
    }

    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:supplier_payments.manage')]
    public function update(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        return $this->save($r, $user, $id);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:supplier_payments.manage')]
    public function delete(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $before = $this->db->fetchAssociative('SELECT * FROM supplier_payments WHERE id = ?', [$id]);
        if (!$before) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $this->db->delete('supplier_payments', ['id' => $id]);
        $this->audit($r, $user, 'supplier_payment.delete', $id, $before, null);

        return $this->json(['ok' => true]);
    }

    private function save(Request $r, StaffUser $user, ?int $id): JsonResponse
    {
        $before = null;
        if ($id !== null) {
            $before = $this->db->fetchAssociative('SELECT * FROM supplier_payments WHERE id = ?', [$id]);
            if (!$before) {
                return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
            }
        }
        $in = json_decode($r->getContent(), true);
        if (!is_array($in)) {
            return $this->json(['error' => 'invalid_json', 'message' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        [$row, $e] = $this->validate($in);
        if ($e) {
            return $this->json(['error' => 'validation_failed', 'message' => 'Certains champs sont invalides.', 'violations' => $e], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Même N° de virement / chèque déjà saisi : on demande confirmation avant de créer un doublon
        if ($row['reference'] !== null && in_array($row['method'], ['transfer', 'cheque'], true) && empty($in['confirm_duplicate'])) {
            $dup = $this->db->fetchAssociative(
                'SELECT id, paid_on, supplier_name, amount_millimes FROM supplier_payments WHERE method = ? AND reference = ? AND id <> ? LIMIT 1',
                [$row['method'], $row['reference'], $id ?? 0]);
            if ($dup) {
                return $this->json([
                    'error' => 'duplicate',
                    'message' => sprintf('Le %s N°%s existe déjà (%s, %s, %s TND).', $row['method'] === 'cheque' ? 'chèque' : 'virement', $row['reference'],
                        (new \DateTimeImmutable($dup['paid_on']))->format('d/m/Y'), $dup['supplier_name'], number_format($dup['amount_millimes'] / 1000, 3, ',', ' ')),
                    'existing_id' => (int) $dup['id'],
                ], Response::HTTP_CONFLICT);
            }
        }

        $db = $row;
        $db['sales_invoices'] = json_encode($row['sales_invoices'], JSON_UNESCAPED_UNICODE);
        if ($id === null) {
            $db['created_by'] = $user->getId();
            $this->db->insert('supplier_payments', $db);
            $id = (int) $this->db->lastInsertId();
        } else {
            $this->db->update('supplier_payments', $db, ['id' => $id]);
        }
        $this->audit($r, $user, $before ? 'supplier_payment.update' : 'supplier_payment.create', $id, $before, $row);

        $saved = $this->db->fetchAssociative(
            'SELECT p.*, s.full_name AS created_by_name FROM supplier_payments p LEFT JOIN staff_users s ON s.id = p.created_by WHERE p.id = ?', [$id]);

        return $this->json($this->shape($saved), $before ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /** @return array{0: array<string,mixed>, 1: array<string,string>} */
    private function validate(array $in): array
    {
        $e = [];
        $str = fn (string $k, int $max): ?string => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);

        $date = $str('paid_on', 10);
        $dt = $date ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            $e['paid_on'] = 'Date invalide.';
        } elseif ($dt < new \DateTimeImmutable('2000-01-01') || $dt > new \DateTimeImmutable('+1 year')) {
            $e['paid_on'] = 'Date hors période.';
        }

        $supplier = $str('supplier_name', 160);
        if ($supplier === null || mb_strlen($supplier) < 2) {
            $e['supplier_name'] = 'Indiquez le fournisseur.';
        } else {
            $supplier = mb_strtoupper(preg_replace('/\s+/u', ' ', $supplier));      // « Travel  tech » = « TRAVEL TECH »
        }

        $method = (string) ($in['method'] ?? '');
        if (!isset(self::METHODS[$method])) { $e['method'] = 'Choisissez un mode de règlement.'; }

        $reference = $str('reference', 60);
        if (in_array($method, ['transfer', 'cheque'], true) && $reference === null) {
            $e['reference'] = $method === 'cheque' ? 'Indiquez le numéro du chèque.' : 'Indiquez le numéro du virement.';
        }

        $amount = $in['amount_millimes'] ?? null;
        if (!is_numeric($amount) || (int) $amount != $amount || (int) $amount <= 0 || (int) $amount > 100_000_000_000) {
            $e['amount_millimes'] = 'Montant invalide.';
        }

        $invoices = [];
        $raw = $in['sales_invoices'] ?? [];
        if (!is_array($raw) || count($raw) > 30) {
            $e['sales_invoices'] = 'Liste de factures invalide.';
        } else {
            foreach ($raw as $inv) {
                $inv = mb_substr(trim((string) $inv), 0, 60);
                if ($inv !== '' && !in_array($inv, $invoices, true)) { $invoices[] = $inv; }
            }
        }

        $status = (string) ($in['status'] ?? ($method === 'cheque' ? 'pending' : 'cleared'));
        if (!in_array($status, self::STATUSES, true)) { $e['status'] = 'Statut inconnu.'; }

        return [[
            'paid_on' => $date, 'supplier_name' => $supplier, 'method' => $method, 'reference' => $reference,
            'bank' => $str('bank', 80), 'amount_millimes' => (int) $amount, 'label' => $str('label', 255),
            'sales_invoices' => $invoices, 'status' => $status, 'notes' => $str('notes', 3000),
        ], $e];
    }

    private function audit(Request $r, StaffUser $u, string $action, int $id, ?array $before, ?array $after): void
    {
        $enc = fn (?array $a) => $a === null ? null : json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->db->insert('audit_log', [
            'staff_id' => $u->getId(), 'action' => $action, 'entity_type' => 'supplier_payment', 'entity_id' => (string) $id,
            'before_data' => $enc($before), 'after_data' => $enc($after), 'ip' => $r->getClientIp(),
        ]);
    }
}
