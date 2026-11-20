<?php

namespace App\Repository;

use App\Entity\LiveCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LiveCode>
 */
class LiveCodeRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LiveCode::class);
    }

    public function findOneByCode(string $code): ?LiveCode
    {
        return '' === $code ? null : $this->findOneBy(['code' => $code]);
    }

    /**
     * @param 'all'|'used'|'revoked' $status
     *
     * @return array{items: list<LiveCode>, total: int, pages: int}
     */
    public function search(string $q, string $status, int $page): array
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.id', 'ASC');

        if ('' !== $q) {
            $needle = '%'.mb_strtolower(preg_replace('/[^A-Za-z0-9 @._-]/', '', $q) ?? '').'%';
            $normalized = '%'.strtolower(preg_replace('/[^A-Za-z0-9]/', '', $q) ?? '').'%';
            $qb->andWhere('LOWER(c.code) LIKE :normalized OR LOWER(c.label) LIKE :needle')
                ->setParameter('normalized', $normalized)
                ->setParameter('needle', $needle);
        }

        match ($status) {
            'used' => $qb->andWhere('c.firstUsedAt IS NOT NULL AND c.revokedAt IS NULL'),
            'revoked' => $qb->andWhere('c.revokedAt IS NOT NULL'),
            default => null,
        };

        $total = (int) (clone $qb)->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        $items = $qb->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total, 'pages' => $pages];
    }

    /** @return list<LiveCode> */
    public function allOrdered(): array
    {
        return $this->findBy([], ['id' => 'ASC']);
    }

    /** @return array{total: int, used: int, revoked: int, active: int} */
    public function stats(\DateTimeImmutable $activeSince): array
    {
        $count = function (string $where, array $params = []): int {
            $qb = $this->createQueryBuilder('c')->select('COUNT(c.id)')->where($where);
            foreach ($params as $name => $value) {
                $qb->setParameter($name, $value);
            }

            return (int) $qb->getQuery()->getSingleScalarResult();
        };

        return [
            'total' => $count('1 = 1'),
            'used' => $count('c.firstUsedAt IS NOT NULL'),
            'revoked' => $count('c.revokedAt IS NOT NULL'),
            'active' => $count('c.lastSeenAt >= :since AND c.revokedAt IS NULL AND c.claimToken IS NOT NULL', ['since' => $activeSince]),
        ];
    }
}
