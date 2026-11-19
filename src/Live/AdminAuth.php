<?php

namespace App\Live;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Session de l'espace d'administration (ouverte avec le supercode).
 */
final class AdminAuth
{
    private const KEY = 'admin_auth';
    private const IDLE_TIMEOUT = 7200; // 2 h d'inactivité

    public function __construct(private readonly SuperCode $superCode)
    {
    }

    public function login(SessionInterface $session): void
    {
        $session->migrate(true);
        $session->set(self::KEY, ['fp' => $this->superCode->fingerprint(), 'at' => time()]);
    }

    public function logout(SessionInterface $session): void
    {
        $session->remove(self::KEY);
        $session->migrate(true);
    }

    public function isAuthenticated(SessionInterface $session): bool
    {
        $data = $session->get(self::KEY);

        if (!\is_array($data) || !isset($data['fp'], $data['at'])
            || !hash_equals($this->superCode->fingerprint(), (string) $data['fp'])
            || time() - (int) $data['at'] > self::IDLE_TIMEOUT) {
            return false;
        }

        $session->set(self::KEY, ['fp' => $data['fp'], 'at' => time()]);

        return true;
    }
}
