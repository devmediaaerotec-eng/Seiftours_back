<?php
namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class TripPayloadValidator
{
    private const TYPES = ['omra', 'organized', 'circuit'];
    private const LANGS = ['en', 'ar'];
    private const TRIP_I18N = ['title', 'summary', 'description', 'includes', 'excludes'];
    private const DAY_I18N  = ['title', 'description'];

    public function __construct(private ValidatorInterface $validator, private Connection $db) {}

    /**
     * @return array{0: ?array, 1: array<string,string>}  [données nettoyées, erreurs par champ]
     */
    public function validate(array $in, ?int $tripId): array
    {
        $errors = [];
        foreach ($this->validator->validate($in, $this->constraints()) as $v) {
            $errors[$this->path($v->getPropertyPath())] ??= (string) $v->getMessage();
        }
        if ($errors) {
            return [null, $errors];
        }

        $type     = $in['type'];
        $destId   = $in['destination_id'] ?? null;
        $regionId = $in['region_id'] ?? null;

        // Un circuit vise une région ; Omra et voyage organisé visent une destination
        if ($type === 'circuit') {
            $destId = null;
            if (!$regionId) {
                $errors['region_id'] = 'Choisissez une région.';
            } elseif (!$this->db->fetchOne('SELECT 1 FROM circuit_regions WHERE id = ?', [$regionId])) {
                $errors['region_id'] = 'Région inconnue.';
            }
        } else {
            $regionId = null;
            if (!$destId) {
                $errors['destination_id'] = 'Choisissez une destination.';
            } else {
                $kind = $this->db->fetchOne('SELECT kind FROM destinations WHERE id = ?', [$destId]);
                if ($kind === false) {
                    $errors['destination_id'] = 'Destination inconnue.';
                } elseif (($type === 'omra') !== ($kind === 'omra')) {
                    $errors['destination_id'] = $type === 'omra'
                        ? 'Une Omra doit viser la destination Omra.'
                        : 'Un voyage organisé ne peut pas viser la destination Omra.';
                }
            }
        }

        if ($this->db->fetchOne('SELECT 1 FROM trips WHERE slug = ? AND id <> ?', [$in['slug'], $tripId ?? 0])) {
            $errors['slug'] = 'Ce slug est déjà utilisé.';
        }

        // Programme
        $days = [];
        $dayNumbers = [];
        foreach ($in['days'] ?? [] as $i => $d) {
            if (isset($dayNumbers[$d['day_number']])) {
                $errors["days.$i.day_number"] = 'Numéro de jour en double.';
            }
            $dayNumbers[$d['day_number']] = true;
            $days[] = [
                'day_number'  => $d['day_number'],
                'title'       => trim($d['title']),
                'description' => $this->nullable($d['description'] ?? null),
                'i18n'        => $this->i18n($d['i18n'] ?? [], self::DAY_I18N),
            ];
        }

        // Galerie
        $images = [];
        foreach (array_values($in['images'] ?? []) as $i => $img) {
            $images[] = [
                'url'        => trim($img['url']),
                'alt'        => $this->nullable($img['alt'] ?? null),
                'sort_order' => $i,
            ];
        }

        // Départs
        $departures = [];
        $dates = [];
        foreach ($in['departures'] ?? [] as $i => $d) {
            $date  = $d['departure_date'];
            $ret   = ($d['return_date'] ?? '') ?: null;
            $total = $d['seats_total'] ?? null;
            $left  = $d['seats_left'] ?? $total;

            if (isset($dates[$date])) {
                $errors["departures.$i.departure_date"] = 'Date de départ en double.';
            }
            $dates[$date] = true;
            if ($ret !== null && $ret < $date) {
                $errors["departures.$i.return_date"] = 'Le retour doit être après le départ.';
            }
            if ($total !== null && $left !== null && $left > $total) {
                $errors["departures.$i.seats_left"] = 'Plus de places restantes que de places au total.';
            }

            $departures[] = [
                'id'                         => $d['id'] ?? null,
                'departure_date'             => $date,
                'return_date'                => $ret,
                'price_per_person_millimes'  => $d['price_per_person_millimes'],
                'single_supplement_millimes' => $d['single_supplement_millimes'] ?? null,
                'child_price_millimes'       => $d['child_price_millimes'] ?? null,
                'seats_total'                => $total,
                'seats_left'                 => $left,
                'is_active'                  => (int) ($d['is_active'] ?? true),
            ];
        }

        // Prix « à partir de » : saisi, sinon le départ actif le moins cher
        $price = $in['price_from_millimes'] ?? null;
        $old   = $in['old_price_millimes'] ?? null;
        $active = array_column(array_filter($departures, fn ($x) => $x['is_active'] === 1), 'price_per_person_millimes');
        if ($price === null && $active) {
            $price = min($active);
        }
        if ($price !== null && $old !== null && $old <= $price) {
            $errors['old_price_millimes'] = "L'ancien prix doit être supérieur au prix actuel.";
        }

        if ($errors) {
            return [null, $errors];
        }

        return [[
            'trip' => [
                'type'                => $type,
                'slug'                => $in['slug'],
                'title'               => trim($in['title']),
                'destination_id'      => $destId,
                'region_id'           => $regionId,
                'summary'             => $this->nullable($in['summary'] ?? null),
                'description'         => $this->nullable($in['description'] ?? null),
                'departure_city'      => trim($in['departure_city'] ?? '') ?: 'Tunis',
                'duration_nights'     => $in['duration_nights'],
                'price_from_millimes' => $price,
                'old_price_millimes'  => $old,
                'currency'            => $in['currency'] ?? 'TND',
                'includes'            => $this->nullable($in['includes'] ?? null),
                'excludes'            => $this->nullable($in['excludes'] ?? null),
                'image_url'           => $this->nullable($in['image_url'] ?? null),
                'is_featured'         => (int) ($in['is_featured'] ?? false),
                'is_active'           => (int) ($in['is_active'] ?? true),
                'sort_order'          => $in['sort_order'] ?? 0,
                'i18n'                => $this->i18n($in['i18n'] ?? [], self::TRIP_I18N),
            ],
            'days'       => $days,
            'images'     => $images,
            'departures' => $departures,
        ], []];
    }

    private function constraints(): Assert\Collection
    {
        $req = fn (int $max) => [new Assert\NotBlank(), new Assert\Type('string'), new Assert\Length(max: $max)];
        $opt = fn (int $max) => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: $max)]);
        $money    = new Assert\Optional([new Assert\Type('integer'), new Assert\PositiveOrZero()]);
        $moneyReq = [new Assert\NotNull(), new Assert\Type('integer'), new Assert\PositiveOrZero()];
        $seats    = new Assert\Optional([new Assert\Type('integer'), new Assert\Range(min: 0, max: 1000)]);

        $day = new Assert\Collection(fields: [
            'day_number'  => [new Assert\NotNull(), new Assert\Type('integer'), new Assert\Range(min: 1, max: 365)],
            'title'       => $req(190),
            'description' => $opt(20000),
            'i18n'        => new Assert\Optional(new Assert\Type('array')),
        ], allowExtraFields: true);

        $image = new Assert\Collection(fields: [
            'url' => $req(500),
            'alt' => $opt(190),
        ], allowExtraFields: true);

        $departure = new Assert\Collection(fields: [
            'id'                         => new Assert\Optional([new Assert\Type('integer'), new Assert\Positive()]),
            'departure_date'             => [new Assert\NotBlank(), new Assert\Date()],
            'return_date'                => new Assert\Optional(new Assert\Date()),
            'price_per_person_millimes'  => $moneyReq,
            'single_supplement_millimes' => $money,
            'child_price_millimes'       => $money,
            'seats_total'                => $seats,
            'seats_left'                 => $seats,
            'is_active'                  => new Assert\Optional(new Assert\Type('bool')),
        ], allowExtraFields: true);

        return new Assert\Collection(fields: [
            'type'                => [new Assert\NotBlank(), new Assert\Choice(choices: self::TYPES)],
            'slug'                => [...$req(140), new Assert\Regex('/^[a-z0-9-]+$/', 'Minuscules, chiffres et tirets uniquement.')],
            'title'               => $req(190),
            'destination_id'      => new Assert\Optional([new Assert\Type('integer'), new Assert\Positive()]),
            'region_id'           => new Assert\Optional([new Assert\Type('integer'), new Assert\Positive()]),
            'summary'             => $opt(500),
            'description'         => $opt(60000),
            'departure_city'      => $opt(80),
            'duration_nights'     => [new Assert\NotNull(), new Assert\Type('integer'), new Assert\Range(min: 1, max: 365)],
            'price_from_millimes' => $money,
            'old_price_millimes'  => $money,
            'currency'            => new Assert\Optional(new Assert\Choice(choices: ['TND', 'EUR', 'USD'])),
            'includes'            => $opt(20000),
            'excludes'            => $opt(20000),
            'image_url'           => $opt(500),
            'is_featured'         => new Assert\Optional(new Assert\Type('bool')),
            'is_active'           => new Assert\Optional(new Assert\Type('bool')),
            'sort_order'          => new Assert\Optional([new Assert\Type('integer'), new Assert\Range(min: -1000, max: 30000)]),
            'i18n'                => new Assert\Optional(new Assert\Type('array')),
            'days'                => new Assert\Optional([new Assert\Type('array'), new Assert\All([$day])]),
            'images'              => new Assert\Optional([new Assert\Type('array'), new Assert\All([$image])]),
            'departures'          => new Assert\Optional([new Assert\Type('array'), new Assert\All([$departure])]),
        ], allowExtraFields: true);
    }

    private function nullable(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /** Garde seulement les langues et champs autorisés ; renvoie toujours un OBJET JSON ('{}' si vide). */
    private function i18n(mixed $raw, array $fields): string
    {
        $out = [];
        if (is_array($raw)) {
            foreach (self::LANGS as $lang) {
                if (!isset($raw[$lang]) || !is_array($raw[$lang])) {
                    continue;
                }
                foreach ($fields as $f) {
                    $v = $raw[$lang][$f] ?? null;
                    if (is_string($v) && trim($v) !== '') {
                        $out[$lang][$f] = trim($v);
                    }
                }
            }
        }
        return $out === [] ? '{}' : json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** "[days][0][title]" devient "days.0.title" */
    private function path(string $p): string
    {
        return trim(str_replace(['][', '[', ']'], ['.', '', ''], $p), '.');
    }
}