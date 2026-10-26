<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use App\Service\MissionService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/fleet/missions')]
#[IsGranted('perm:fleet.read')]
final class MissionAdminController extends AbstractController
{
    private const SELECT = "m.id, m.reference, m.mission_type, m.title, m.starts_at, m.ends_at, m.pickup_place, m.destination,
        m.pax, m.client_name, m.client_phone, m.price_millimes, m.notes, m.status, m.control_note, m.controlled_at,
        m.notified_at, m.planning_updated_at, m.created_at, m.updated_at,
        m.bus_id, b.code AS bus_code, b.plate_number AS bus_plate, b.bus_type, b.seats AS bus_seats, b.image_url AS bus_image,
        m.driver_id, CONCAT(d.first_name, ' ', d.last_name) AS driver_name, d.first_name AS driver_first_name,
        d.phone AS driver_phone, d.photo_url AS driver_photo,
        cb.full_name AS controlled_by_name, cr.full_name AS created_by_name,
        (SELECT COUNT(*) FROM mission_events e WHERE e.mission_id = m.id AND e.event_type = 'updated') AS changes_count";
    private const FROM = 'bus_missions m
        JOIN buses b ON b.id = m.bus_id
        JOIN drivers d ON d.id = m.driver_id
        LEFT JOIN staff_users cb ON cb.id = m.controlled_by
        LEFT JOIN staff_users cr ON cr.id = m.created_by';
    /** Ce que la secrétaire peut corriger lors d'un contrôle. */
    private const CONTROL_FIELDS = ['bus_id', 'driver_id', 'starts_at', 'ends_at', 'pickup_place', 'destination'];

    public function __construct(private Connection $db, private MissionService $svc) {}

    // ---------------------------------------------------------------- outils

    private function fetch(int $id): ?array
    {
        return $this->db->fetchAssociative('SELECT ' . self::SELECT . ' FROM ' . self::FROM . ' WHERE m.id = ?', [$id]) ?: null;
    }

    private function present(array $m, ?string $now = null): array
    {
        $now ??= $this->svc->nowLocal();
        $m['bus_id']         = (int) $m['bus_id'];
        $m['driver_id']      = (int) $m['driver_id'];
        $m['bus_seats']      = (int) $m['bus_seats'];
        $m['pax']            = $m['pax'] === null ? null : (int) $m['pax'];
        $m['price_millimes'] = $m['price_millimes'] === null ? null : (int) $m['price_millimes'];
        $m['changes_count']  = (int) $m['changes_count'];
        $m['controlled']     = $m['controlled_at'] !== null;
        $m['needs_control']  = in_array($m['status'], ['planned', 'in_progress'], true) && $m['ends_at'] < $now;
        $m['needs_resend']   = $m['status'] === 'planned'
            && ($m['notified_at'] === null || $m['planning_updated_at'] > $m['notified_at']);
        return $m;
    }

    private function editable(array $m): array
    {
        return [
            'mission_type' => $m['mission_type'], 'title' => $m['title'],
            'bus_id' => (int) $m['bus_id'], 'driver_id' => (int) $m['driver_id'],
            'starts_at' => $m['starts_at'], 'ends_at' => $m['ends_at'],
            'pickup_place' => $m['pickup_place'], 'destination' => $m['destination'],
            'pax' => $m['pax'] === null ? null : (int) $m['pax'],
            'client_name' => $m['client_name'], 'client_phone' => $m['client_phone'],
            'price_millimes' => $m['price_millimes'] === null ? null : (int) $m['price_millimes'],
            'notes' => $m['notes'],
        ];
    }

    private function body(Request $r): ?array
    {
        $b = json_decode($r->getContent(), true);
        return is_array($b) ? $b : null;
    }

    private function badJson(): JsonResponse
    {
        return $this->json(['error' => 'invalid_json', 'message' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST);
    }

    private function notFound(): JsonResponse
    {
        return $this->json(['error' => 'not_found', 'message' => 'Mission introuvable.'], Response::HTTP_NOT_FOUND);
    }

    private function invalid(array $violations): JsonResponse
    {
        return $this->json(['error' => 'validation_failed', 'message' => 'Certains champs sont invalides.',
                            'violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Africa/Tunis'));
    }

    private function ymd(string $s): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $p) && checkdate((int) $p[2], (int) $p[3], (int) $p[1]) ? $s : null;
    }

    private function nextDay(string $ymd): string
    {
        return (new \DateTimeImmutable($ymd))->modify('+1 day')->format('Y-m-d');
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} lundi et lundi suivant */
    private function weekBounds(string $start): array
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, new \DateTimeZone('UTC')) ?: $this->today();
        $monday = $d->modify('-' . ((int) $d->format('N') - 1) . ' days');
        return [$monday, $monday->modify('+7 days')];
    }

    private function resources(): array
    {
        return [
            'drivers' => $this->db->fetchAllAssociative(
                "SELECT d.id, CONCAT(d.first_name, ' ', d.last_name) AS full_name, d.photo_url, d.phone, d.status,
                        d.assigned_bus_id, b.code AS bus_code
                 FROM drivers d LEFT JOIN buses b ON b.id = d.assigned_bus_id
                 WHERE d.is_active = 1 ORDER BY d.last_name, d.first_name"),
            'buses' => $this->db->fetchAllAssociative(
                "SELECT b.id, b.code, b.plate_number, b.bus_type, b.seats, b.status, b.image_url,
                        dr.id AS driver_id, CONCAT(dr.first_name, ' ', dr.last_name) AS driver_name
                 FROM buses b LEFT JOIN drivers dr ON dr.assigned_bus_id = b.id AND dr.is_active = 1
                 WHERE b.is_active = 1 ORDER BY b.code"),
        ];
    }

    // ---------------------------------------------------------------- lecture

    #[Route('/form-options', methods: ['GET'])]
    public function formOptions(): JsonResponse
    {
        return $this->json($this->resources());
    }

    #[Route('/availability', methods: ['GET'])]
    public function availability(Request $r): JsonResponse
    {
        $s = $this->svc->parseLocal($r->query->getString('starts_at'));
        $e = $this->svc->parseLocal($r->query->getString('ends_at'));
        if (!$s || !$e || $e <= $s) {
            return $this->json(['buses' => new \stdClass(), 'drivers' => new \stdClass()]);
        }
        return $this->json($this->svc->busy($s->format('Y-m-d H:i:s'), $e->format('Y-m-d H:i:s'), $r->query->getInt('exclude')));
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        $now = $this->svc->nowLocal();
        $where = ['1=1'];
        $params = [];

        if ($from = $this->ymd($r->query->getString('from'))) {
            $where[] = 'm.ends_at > ?';
            $params[] = "$from 00:00:00";
        }
        if ($to = $this->ymd($r->query->getString('to'))) {
            $where[] = 'm.starts_at < ?';
            $params[] = $this->nextDay($to) . ' 00:00:00';
        }
        $status = $r->query->getString('status');
        if (in_array($status, MissionService::STATUSES, true)) {
            $where[] = 'm.status = ?';
            $params[] = $status;
        }
        $type = $r->query->getString('type');
        if (in_array($type, MissionService::TYPES, true)) {
            $where[] = 'm.mission_type = ?';
            $params[] = $type;
        }
        if (($driver = $r->query->getInt('driver')) > 0) {
            $where[] = 'm.driver_id = ?';
            $params[] = $driver;
        }
        if (($bus = $r->query->getInt('bus')) > 0) {
            $where[] = 'm.bus_id = ?';
            $params[] = $bus;
        }
        if ($r->query->getString('control') === 'pending') {
            $where[] = "m.status IN ('planned','in_progress') AND m.ends_at < ?";
            $params[] = $now;
        }
        if (($q = trim($r->query->getString('q'))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = "(m.reference LIKE ? OR m.title LIKE ? OR m.client_name LIKE ? OR m.client_phone LIKE ?
                        OR m.pickup_place LIKE ? OR m.destination LIKE ? OR b.code LIKE ?
                        OR CONCAT(d.first_name, ' ', d.last_name) LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $order  = $r->query->getString('order') === 'desc' ? 'DESC' : 'ASC';
        $limit  = max(1, min(100, $r->query->getInt('limit', 20)));
        $page   = max(1, $r->query->getInt('page', 1));
        $offset = ($page - 1) * $limit;
        $cond   = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . self::FROM . " WHERE $cond", $params);
        $rows  = $this->db->fetchAllAssociative(
            'SELECT ' . self::SELECT . ' FROM ' . self::FROM . " WHERE $cond ORDER BY m.starts_at $order, m.id $order LIMIT $limit OFFSET $offset",
            $params
        );

        return $this->json([
            'total' => $total, 'page' => $page, 'limit' => $limit,
            'items' => array_map(fn (array $m) => $this->present($m, $now), $rows),
        ]);
    }

    #[Route('/week', methods: ['GET'])]
    public function week(Request $r): JsonResponse
    {
        [$monday, $next] = $this->weekBounds($r->query->getString('start'));
        $now = $this->svc->nowLocal();
        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . self::SELECT . ' FROM ' . self::FROM . ' WHERE m.starts_at < ? AND m.ends_at > ? ORDER BY m.starts_at, m.id',
            [$next->format('Y-m-d 00:00:00'), $monday->format('Y-m-d 00:00:00')]
        );
        $items = array_map(fn (array $m) => $this->present($m, $now), $rows);

        return $this->json([
            'start'    => $monday->format('Y-m-d'),
            'end'      => $next->modify('-1 day')->format('Y-m-d'),
            'missions' => $items,
            'to_send'  => count(array_filter($items, static fn (array $m) => $m['needs_resend'])),
        ] + $this->resources());
    }

    #[Route('/day', methods: ['GET'])]
    public function day(Request $r): JsonResponse
    {
        $date  = $this->ymd($r->query->getString('date')) ?? $this->today()->format('Y-m-d');
        $start = "$date 00:00:00";
        $now   = $this->svc->nowLocal();

        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . self::SELECT . ' FROM ' . self::FROM . ' WHERE m.starts_at < ? AND m.ends_at > ? ORDER BY m.starts_at, m.id',
            [$this->nextDay($date) . ' 00:00:00', $start]
        );
        $items = array_map(fn (array $m) => $this->present($m, $now), $rows);

        $stats = ['total' => count($items), 'to_control' => 0, 'completed' => 0, 'not_done' => 0, 'cancelled' => 0, 'planned' => 0, 'in_progress' => 0];
        foreach ($items as $m) {
            $stats[$m['status']]++;
            $stats['to_control'] += $m['needs_control'] ? 1 : 0;
        }
        $overdue = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM bus_missions WHERE status IN ('planned','in_progress') AND ends_at <= ?", [$start]
        );

        return $this->json(['date' => $date, 'missions' => $items, 'stats' => $stats, 'overdue' => $overdue]);
    }

    /** Messages prêts à envoyer aux chauffeurs pour la semaine. */
    #[Route('/week-program', methods: ['GET'])]
    #[IsGranted('perm:missions.manage')]
    public function weekProgram(Request $r): JsonResponse
    {
        [$monday, $next] = $this->weekBounds($r->query->getString('start'));
        $now = $this->svc->nowLocal();
        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . self::SELECT . ' FROM ' . self::FROM
            . " WHERE m.status IN ('planned','in_progress') AND m.starts_at < ? AND m.ends_at > ?
               ORDER BY d.last_name, d.first_name, m.starts_at",
            [$next->format('Y-m-d 00:00:00'), $monday->format('Y-m-d 00:00:00')]
        );

        $byDriver = [];
        foreach ($rows as $row) {
            $byDriver[(int) $row['driver_id']][] = $this->present($row, $now);
        }

        $drivers = [];
        $all = [];
        foreach ($byDriver as $driverId => $list) {
            $first   = $list[0];
            $message = $this->svc->programMessage($first['driver_first_name'], $monday, $list);
            $digits  = preg_replace('/\D+/', '', (string) $first['driver_phone']);
            $drivers[] = [
                'driver_id'   => $driverId,
                'name'        => $first['driver_name'],
                'photo'       => $first['driver_photo'],
                'phone'       => $first['driver_phone'],
                'count'       => count($list),
                'to_send'     => count(array_filter($list, static fn (array $m) => $m['needs_resend'])),
                'mission_ids' => array_map('intval', array_column($list, 'id')),
                'message'     => $message,
                'whatsapp'    => $digits ? 'https://wa.me/' . $digits . '?text=' . rawurlencode($message) : null,
            ];
            $all[] = $message;
        }

        return $this->json([
            'start'       => $monday->format('Y-m-d'),
            'end'         => $next->modify('-1 day')->format('Y-m-d'),
            'drivers'     => $drivers,
            'all_message' => implode("\n\n────────────\n\n", $all),
        ]);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $m = $this->fetch($id);
        if (!$m) {
            return $this->notFound();
        }
        $events = $this->db->fetchAllAssociative(
            'SELECT e.id, e.event_type, e.summary, e.note, e.details, e.created_at, s.full_name AS staff_name
             FROM mission_events e LEFT JOIN staff_users s ON s.id = e.staff_id
             WHERE e.mission_id = ? ORDER BY e.id DESC', [$id]
        );
        foreach ($events as &$e) {
            $e['details'] = $e['details'] ? json_decode($e['details'], true) : null;
        }
        unset($e);

        return $this->json(['mission' => $this->present($m), 'events' => $events]);
    }

    // ------------------------------------------------------------ planification

    #[Route('', methods: ['POST'])]
    #[IsGranted('perm:missions.manage')]
    public function create(Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        return $this->save($r, $user, null);
    }

    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:missions.manage')]
    public function update(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        return $this->save($r, $user, $id);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:missions.manage')]
    public function delete(int $id): JsonResponse
    {
        $m = $this->fetch($id);
        if (!$m) {
            return $this->notFound();
        }
        if ($m['status'] !== 'planned' || $m['controlled_at'] !== null) {
            return $this->json(['error' => 'not_deletable', 'message' => 'Seule une mission planifiée et non contrôlée peut être supprimée. Annulez-la plutôt.'], Response::HTTP_CONFLICT);
        }
        if ($m['notified_at'] !== null) {
            return $this->json(['error' => 'not_deletable', 'message' => "Cette mission a été envoyée au chauffeur : annulez-la pour garder une trace."], Response::HTTP_CONFLICT);
        }
        $this->db->delete('bus_missions', ['id' => $id]);

        return $this->json(['ok' => true]);
    }

    #[Route('/mark-notified', methods: ['POST'])]
    #[IsGranted('perm:missions.manage')]
    public function markNotified(Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $ids = $this->ids($this->body($r));
        if ($ids === null) {
            return $this->invalid(['ids' => 'Liste de missions invalide (200 maximum).']);
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchFirstColumn("SELECT id FROM bus_missions WHERE id IN ($in) AND status IN ('planned','in_progress')", $ids);

        $this->db->beginTransaction();
        try {
            foreach ($rows as $mid) {
                $this->db->executeStatement('UPDATE bus_missions SET notified_at = UTC_TIMESTAMP(3) WHERE id = ?', [$mid]);
                $this->svc->event((int) $mid, $user->getId(), 'notified', 'Programme envoyé au chauffeur');
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->json(['updated' => count($rows)]);
    }

    // ------------------------------------------------------------------ contrôle

    #[Route('/{id}/status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:missions.control')]
    public function status(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $m = $this->fetch($id);
        if (!$m) {
            return $this->notFound();
        }
        $body = $this->body($r);
        if ($body === null) {
            return $this->badJson();
        }
        $to   = $body['status'] ?? null;
        $note = trim((string) ($body['note'] ?? ''));

        if (!in_array($to, MissionService::STATUSES, true)) {
            return $this->invalid(['status' => 'Statut invalide.']);
        }
        if ($to === $m['status']) {
            return $this->invalid(['status' => 'La mission a déjà ce statut.']);
        }
        if (in_array($to, ['not_done', 'cancelled'], true) && mb_strlen($note) < 3) {
            return $this->invalid(['note' => 'Précisez la raison (3 caractères minimum).']);
        }
        if ($to === 'in_progress' && $m['status'] !== 'planned') {
            return $this->invalid(['status' => 'Seule une mission planifiée peut démarrer.']);
        }

        $this->db->beginTransaction();
        try {
            $this->svc->lock((int) $m['bus_id'], (int) $m['driver_id']);
            if (!in_array($m['status'], MissionService::ACTIVE, true) && in_array($to, MissionService::ACTIVE, true)) {
                $c = $this->svc->conflicts((int) $m['bus_id'], (int) $m['driver_id'], $m['starts_at'], $m['ends_at'], $id);
                if ($c) {
                    $this->db->rollBack();
                    return $this->json(['error' => 'conflict', 'message' => 'Réouverture impossible : bus ou chauffeur déjà pris par la ' . $this->svc->describeConflict($c[0]) . '.'], Response::HTTP_CONFLICT);
                }
            }
            $this->svc->applyStatus($m, $to, $user->getId(), $note !== '' ? $note : null);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->json(['mission' => $this->present($this->fetch($id))]);
    }

    /**
     * Contrôle quotidien : effectuée comme prévu / avec modification / non effectuée / annulée.
     * Body : { result, note?, changes? { bus_id, driver_id, starts_at, ends_at, pickup_place, destination } }
     */
    #[Route('/{id}/control', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:missions.control')]
    public function control(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $m = $this->fetch($id);
        if (!$m) {
            return $this->notFound();
        }
        $body = $this->body($r);
        if ($body === null) {
            return $this->badJson();
        }
        $result  = $body['result'] ?? null;
        $note    = trim((string) ($body['note'] ?? ''));
        $changes = is_array($body['changes'] ?? null) ? $body['changes'] : [];

        if (!in_array($result, ['as_planned', 'modified', 'not_done', 'cancelled'], true)) {
            return $this->invalid(['result' => 'Résultat invalide.']);
        }
        $errors = [];
        if ($result !== 'as_planned' && mb_strlen($note) < 3) {
            $errors['note'] = match ($result) {
                'modified'  => 'Précisez la raison du changement (3 caractères minimum).',
                'not_done'  => 'Précisez pourquoi la mission n\'a pas eu lieu.',
                default     => "Précisez la raison de l'annulation.",
            };
        }
        if (mb_strlen($note) > 2000) {
            $errors['note'] = '2000 caractères maximum.';
        }
        if ($errors) {
            return $this->invalid($errors);
        }

        $to = match ($result) { 'not_done' => 'not_done', 'cancelled' => 'cancelled', default => 'completed' };
        $warnings = [];

        $this->db->beginTransaction();
        try {
            $this->svc->lock((int) ($changes['bus_id'] ?? $m['bus_id']), (int) ($changes['driver_id'] ?? $m['driver_id']));

            if ($result === 'modified') {
                $in = array_merge($this->editable($m), array_intersect_key($changes, array_flip(self::CONTROL_FIELDS)));
                [$row, $errors, $warnings] = $this->svc->prepare($in, $m, true);
                if ($errors) {
                    $this->db->rollBack();
                    return $this->invalid($errors);
                }
                if (!$this->applyUpdate($id, $row, $m, $user, $note)) {
                    $this->db->rollBack();
                    return $this->invalid(['changes' => 'Aucune modification détectée : choisissez « Effectuée comme prévu » ou modifiez un élément.']);
                }
                $this->svc->syncBusStatus((int) $row['bus_id']);
            }

            $this->svc->applyStatus($m, $to, $user->getId(), $note !== '' ? $note : null, $result !== 'modified');
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->json(['mission' => $this->present($this->fetch($id)), 'warnings' => $warnings]);
    }

    /** Valide en lot les missions terminées non contrôlées (comme prévu). */
    #[Route('/bulk-complete', methods: ['POST'])]
    #[IsGranted('perm:missions.control')]
    public function bulkComplete(Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $ids = $this->ids($this->body($r));
        if ($ids === null) {
            return $this->invalid(['ids' => 'Liste de missions invalide (200 maximum).']);
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, status, bus_id FROM bus_missions WHERE id IN ($in) AND status IN ('planned','in_progress') AND ends_at < ?",
            [...$ids, $this->svc->nowLocal()]
        );

        $this->db->beginTransaction();
        try {
            foreach ($rows as $row) {
                $this->svc->applyStatus($row, 'completed', $user->getId(), null);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->json(['updated' => count($rows), 'skipped' => count($ids) - count($rows)]);
    }

    // ------------------------------------------------------------------ interne

    private function ids(?array $body): ?array
    {
        $raw = is_array($body['ids'] ?? null) ? $body['ids'] : [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static fn (int $i) => $i > 0)));
        return $ids && count($ids) <= 200 ? $ids : null;
    }

    private function save(Request $r, StaffUser $user, ?int $id): JsonResponse
    {
        $before = null;
        if ($id !== null && !($before = $this->fetch($id))) {
            return $this->notFound();
        }
        $body = $this->body($r);
        if ($body === null) {
            return $this->badJson();
        }

        $this->db->beginTransaction();
        try {
            $this->svc->lock($body['bus_id'] ?? null, $body['driver_id'] ?? null);
            [$row, $errors, $warnings] = $this->svc->prepare($body, $before);

            if (!$errors && $before && $before['status'] === 'in_progress'
                && ((int) $row['bus_id'] !== (int) $before['bus_id'] || (int) $row['driver_id'] !== (int) $before['driver_id'])) {
                $errors['bus_id'] = "Mission en cours : le bus et le chauffeur ne peuvent pas être changés. Terminez-la d'abord.";
            }
            if ($errors) {
                $this->db->rollBack();
                return $this->invalid($errors);
            }

            if ($before === null) {
                $row['reference']  = $this->svc->newReference();
                $row['created_by'] = $user->getId();
                $this->db->insert('bus_missions', $row);
                $id = (int) $this->db->lastInsertId();
                $this->svc->event($id, $user->getId(), 'created', 'Mission créée');
            } else {
                $this->applyUpdate($id, $row, $before, $user, null);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->json(['mission' => $this->present($this->fetch($id)), 'warnings' => $warnings],
            $before === null ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /** Enregistre la modification, journalise la différence, marque « à renvoyer » si besoin. */
    private function applyUpdate(int $id, array $row, array $before, StaffUser $user, ?string $note): array
    {
        $this->db->update('bus_missions', $row, ['id' => $id]);
        $changes = $this->svc->diff($before, $this->fetch($id));
        if ($changes) {
            $this->svc->event($id, $user->getId(), 'updated', $this->svc->summarize($changes), $changes, $note);
            if ($this->svc->touchesPlanning($changes)) {
                $this->db->executeStatement('UPDATE bus_missions SET planning_updated_at = UTC_TIMESTAMP(3) WHERE id = ?', [$id]);
            }
        }
        return $changes;
    }
}