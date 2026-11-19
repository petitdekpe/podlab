<?php

namespace App\Live;

use App\Entity\LiveCode;
use App\Repository\LiveCodeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Codes d'accès (un par billet), stockés en base de données.
 */
final class LiveCodes
{
    // Sans 0/O ni 1/I/L pour éviter les erreurs de saisie
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const PREFIX = 'PL';

    public function __construct(
        private readonly LiveCodeRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** "pl-ab12 cd34" => "PLAB12CD34" */
    public static function normalize(string $raw): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));
    }

    /** "PLAB12CD34" => "PL-AB12-CD34" */
    public static function format(string $normalized): string
    {
        return sprintf('%s-%s-%s', substr($normalized, 0, 2), substr($normalized, 2, 4), substr($normalized, 6));
    }

    public function find(string $normalized): ?LiveCode
    {
        return $this->repository->findOneByCode($normalized);
    }

    /**
     * Crée $count nouveaux codes.
     *
     * @return list<LiveCode>
     */
    public function generate(int $count): array
    {
        $taken = array_fill_keys($this->repository->allCodes(), true);
        $created = [];

        while (count($created) < $count) {
            $code = self::PREFIX;
            for ($i = 0; $i < 8; ++$i) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            if (isset($taken[$code])) {
                continue;
            }
            $taken[$code] = true;
            $entity = new LiveCode($code);
            $this->em->persist($entity);
            $created[] = $entity;
        }
        $this->em->flush();

        return $created;
    }

    /**
     * Importe des codes existants (ex. la liste remise à Tickafrica).
     * Chaque ligne : le code, éventuellement suivi d'un libellé après ";" ou ",".
     *
     * @param iterable<string> $lines
     *
     * @return array{added: int, skipped: int, invalid: int}
     */
    public function import(iterable $lines): array
    {
        $taken = array_fill_keys($this->repository->allCodes(), true);
        $added = $skipped = $invalid = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            $fields = preg_split('/[;,\t]/', $line, 2);
            // la ligne d'en-tête éventuelle ("code", "numero;code"…) est ignorée
            $code = self::normalize($fields[0]);
            $label = isset($fields[1]) ? trim($fields[1]) : null;

            if (!preg_match('/^[A-Z0-9]{6,16}$/', $code) || in_array(strtolower($fields[0]), ['code', 'numero', 'n°'], true)) {
                ++$invalid;
                continue;
            }
            if (isset($taken[$code])) {
                ++$skipped;
                continue;
            }
            $taken[$code] = true;
            $this->em->persist(new LiveCode($code, $label ?: null));
            ++$added;
        }
        $this->em->flush();

        return ['added' => $added, 'skipped' => $skipped, 'invalid' => $invalid];
    }
}
