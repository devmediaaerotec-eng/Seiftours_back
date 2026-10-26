<?php
namespace App\Service;

use App\Entity\StaffUser;
use Doctrine\DBAL\Connection;

final class CatalogCrud
{
    private const LANGS = ['en', 'ar'];

    public function __construct(private Connection $db) {}

    // ------------------------------------------------------------ lecture

    public function count(string $table, string $column, int $id): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?", [$id]);
    }

    public function usage(array $cfg, int $id): int
    {
        $n = 0;
        foreach ($cfg['usage'] ?? [] as [$table, $column]) {
            $n += $this->count($table, $column, $id);
        }
        return $n;
    }

    public function normalize(array $row): array
    {
        foreach (['is_active', 'is_featured'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (bool) $row[$k];
            }
        }
        if (array_key_exists('i18n', $row)) {
            $row['i18n'] = json_decode($row['i18n'] ?: '{}', true) ?: new \stdClass();
        }
        return $row;
    }

    public function all(array $cfg): array
    {
        $rows = $this->db->fetchAllAssociative("SELECT * FROM `{$cfg['table']}` ORDER BY sort_order, id");
        return array_map(function (array $r) use ($cfg) {
            $r = $this->normalize($r);
            $r['used_by'] = $this->usage($cfg, (int) $r['id']);
            return $r;
        }, $rows);
    }

    public function find(array $cfg, int $id): ?array
    {
        $r = $this->db->fetchAssociative("SELECT * FROM `{$cfg['table']}` WHERE id = ?", [$id]);
        return $r ? $this->normalize($r) : null;
    }

    // --------------------------------------------------------- validation

    /**
     * @param callable|null $guard fn(array $row, ?array $existing): array<string,string>  (règles métier)
     * @return array{0: array, 1: array<string,string>}  [ligne prête pour la base, erreurs par champ]
     */
    public function validate(array $cfg, array $in, ?int $id, ?callable $guard = null): array
    {
        $errors = [];
        $row = [];

        foreach ($cfg['fields'] as $name => $f) {
            if ($f['type'] === 'bool') {
                $row[$name] = array_key_exists($name, $in)
                    ? (int) filter_var($in[$name], FILTER_VALIDATE_BOOLEAN)
                    : (int) ($f['default'] ?? false);
                continue;
            }

            $v = $in[$name] ?? null;
            if (is_string($v)) {
                $v = trim($v);
            }
            if ($v === null || $v === '') {
                if ($f['required'] ?? false) {
                    $errors[$name] = 'Champ requis.';
                } else {
                    $row[$name] = $f['default'] ?? null;
                }
                continue;
            }
[$value, $error] = $this->check($name, $f, $v, $cfg['table'], $id);
             if ($error !== null) {
                $errors[$name] = $error;
            } else {
                $row[$name] = $value;
            }
        }

        if (!empty($cfg['i18n'])) {
            $row['i18n'] = $this->i18n($in['i18n'] ?? [], $cfg['i18n']);
        }

        if (!$errors && $guard) {
            $errors = $guard($row, $id ? $this->find($cfg, $id) : null);
        }

        return [$row, $errors];
    }
 private function check(string $name, array $f, mixed $v, string $table, ?int $id): array
{
    switch ($f['type']) {
        case 'string':
        case 'text':
        case 'slug':
            if (!is_string($v)) {
                return [null, 'Texte attendu.'];
            }
            if (!empty($f['upper'])) {
                $v = mb_strtoupper($v);
            }
            $max = $f['max'] ?? ($f['type'] === 'text' ? 60000 : 190);
            if (mb_strlen($v) > $max) {
                return [null, "$max caractères maximum."];
            }
            if ($f['type'] === 'slug') {
                if (!preg_match('/^[a-z0-9-]+$/', $v)) {
                    return [null, 'Minuscules, chiffres et tirets uniquement.'];
                }
                if ($this->db->fetchOne("SELECT 1 FROM `$table` WHERE slug = ? AND id <> ?", [$v, $id ?? 0])) {
                    return [null, 'Ce slug est déjà utilisé.'];
                }
            }
            if (!empty($f['pattern']) && !preg_match($f['pattern'], $v)) {
                return [null, $f['pattern_message'] ?? 'Format invalide.'];
            }
            if (!empty($f['email']) && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                return [null, 'E-mail invalide.'];
            }
            if (!empty($f['unique'])
                && $this->db->fetchOne("SELECT 1 FROM `$table` WHERE `$name` = ? AND id <> ?", [$v, $id ?? 0])) {
                return [null, $f['unique_message'] ?? 'Cette valeur est déjà utilisée.'];
            }
            return [$v, null];

        case 'enum':
            return in_array($v, $f['values'], true) ? [$v, null] : [null, 'Valeur non autorisée.'];

        case 'int':
            if (!is_int($v) || $v < ($f['min'] ?? 0) || $v > ($f['max'] ?? 9_000_000_000_000)) {
                return [null, 'Nombre invalide.'];
            }
            return [$v, null];

        case 'date':
            if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)
                || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return [null, 'Date invalide.'];
            }
            return [$v, null];

        case 'fk':
            if (!is_int($v) || $v < 1) {
                return [null, 'Identifiant invalide.'];
            }
            if (!$this->db->fetchOne("SELECT 1 FROM `{$f['table']}` WHERE id = ?", [$v])) {
                return [null, 'Élément introuvable.'];
            }
            return [$v, null];
    }
    return [null, 'Type de champ inconnu.'];
}

    /** Garde seulement les langues et champs autorisés ; renvoie toujours un objet JSON ('{}' si vide). */
    private function i18n(mixed $raw, array $fields): string
    {
        $out = [];
        if (is_array($raw)) {
            foreach (self::LANGS as $lang) {
                foreach ($fields as $f) {
                    $v = is_array($raw[$lang] ?? null) ? ($raw[$lang][$f] ?? null) : null;
                    if (is_string($v) && trim($v) !== '') {
                        $out[$lang][$f] = trim($v);
                    }
                }
            }
        }
        return $out === [] ? '{}' : json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------- écriture

    public function insert(array $cfg, array $row): int
    {
        $this->db->insert($cfg['table'], $row);
        return (int) $this->db->lastInsertId();
    }

    public function update(array $cfg, int $id, array $row): void
    {
        $this->db->update($cfg['table'], $row, ['id' => $id]);
    }

    public function remove(array $cfg, int $id): void
    {
        $this->db->delete($cfg['table'], ['id' => $id]);
    }

    public function flags(array $cfg, int $id, array $body): array
    {
        $set = [];
        foreach ($cfg['flags'] ?? ['is_active'] as $k) {
            if (array_key_exists($k, $body)) {
                $set[$k] = (int) filter_var($body[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if ($set) {
            $this->db->update($cfg['table'], $set, ['id' => $id]);
        }
        return $set;
    }

    /** La liste doit contenir exactement tous les identifiants existants. */
    public function reorder(array $cfg, array $ids): bool
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $existing = array_map('intval', $this->db->fetchFirstColumn("SELECT id FROM `{$cfg['table']}`"));
        $a = $ids;
        $b = $existing;
        sort($a);
        sort($b);
        if ($a !== $b) {
            return false;
        }
        $this->db->beginTransaction();
        try {
            foreach ($ids as $i => $id) {
                $this->db->update($cfg['table'], ['sort_order' => $i + 1], ['id' => $id]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return true;
    }

    public function audit(StaffUser $u, ?string $ip, string $action, string $entity, int $id, ?array $before, ?array $after): void
    {
        $enc = static fn (?array $a) => $a === null ? null
            : json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->db->insert('audit_log', [
            'staff_id'    => $u->getId(),
            'action'      => $action,
            'entity_type' => $entity,
            'entity_id'   => (string) $id,
            'before_data' => $enc($before),
            'after_data'  => $enc($after),
            'ip'          => $ip,
        ]);
    }
}