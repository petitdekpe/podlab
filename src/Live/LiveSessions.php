<?php

namespace App\Live;

use App\Entity\LiveCode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * - Un code n'est utilisable que sur UN appareil à la fois : le dernier
 *   appareil connecté garde la main (son jeton est enregistré sur le billet),
 *   les autres sont déconnectés au prochain signal de présence.
 * - Limitation des essais de codes par adresse IP (cache de l'application).
 */
final class LiveSessions
{
    private const FAIL_WINDOW = 600;
    private const MAX_FAILS = 8;
    /** Le signal de présence arrive toutes les 20 s : on n'écrit en base qu'une fois par minute. */
    private const TOUCH_EVERY = 55;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Donne la main à un nouvel appareil et retourne son jeton. */
    public function claim(LiveCode $code): string
    {
        $token = bin2hex(random_bytes(16));
        $code->setClaimToken($token);
        $code->markUsed(new \DateTimeImmutable());
        $this->em->flush();

        return $token;
    }

    public function isCurrent(LiveCode $code, string $token): bool
    {
        $current = $code->getClaimToken();

        return !$code->isRevoked() && null !== $current && hash_equals($current, $token);
    }

    public function touch(LiveCode $code): void
    {
        $now = new \DateTimeImmutable();
        $last = $code->getLastSeenAt();

        if (null === $last || $now->getTimestamp() - $last->getTimestamp() >= self::TOUCH_EVERY) {
            $code->setLastSeenAt($now);
            $this->em->flush();
        }
    }

    public function release(LiveCode $code, string $token): void
    {
        if ($this->isCurrent($code, $token)) {
            $code->setClaimToken(null);
            $this->em->flush();
        }
    }

    public function isThrottled(string $key): bool
    {
        $item = $this->cache->getItem($this->failKey($key));

        return $item->isHit() && (int) $item->get() >= self::MAX_FAILS;
    }

    public function registerFailure(string $key): void
    {
        $item = $this->cache->getItem($this->failKey($key));
        $item->set(($item->isHit() ? (int) $item->get() : 0) + 1)->expiresAfter(self::FAIL_WINDOW);
        $this->cache->save($item);
    }

    public function clearFailures(string $key): void
    {
        $this->cache->deleteItem($this->failKey($key));
    }

    private function failKey(string $key): string
    {
        return 'live_fail_'.sha1($key);
    }
}
