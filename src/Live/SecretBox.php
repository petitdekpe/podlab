<?php

namespace App\Live;

/**
 * Chiffre les secrets enregistrés en base (clé API Tick Africa) avec une clé
 * dérivée d'APP_SECRET : une copie de la base seule ne livre pas la clé.
 * Changer APP_SECRET rend les secrets enregistrés illisibles (il faut les ressaisir).
 */
final class SecretBox
{
    private const PREFIX = 'v1:';

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $appSecret,
    ) {
    }

    /** Faux tant qu'APP_SECRET est vide ou trivial : on refuse alors d'enregistrer un secret. */
    public function isUsable(): bool
    {
        return \strlen(trim($this->appSecret)) >= 16;
    }

    public function seal(#[\SensitiveParameter] string $plain): string
    {
        if (!$this->isUsable()) {
            throw new \LogicException('APP_SECRET absent : impossible de chiffrer.');
        }
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    /** Le secret en clair, ou null s'il est illisible (APP_SECRET changé, valeur abîmée). */
    public function open(string $sealed): ?string
    {
        if (!$this->isUsable() || !str_starts_with($sealed, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($sealed, \strlen(self::PREFIX)), true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );

        return false === $plain ? null : $plain;
    }

    private function key(): string
    {
        return sodium_crypto_generichash('tickafrica-api-key|'.$this->appSecret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
