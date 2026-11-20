<?php

namespace App\Live;

/**
 * Supercode du superadmin.
 *
 * - ouvre l'espace d'administration (/admin) ;
 * - donne accès au direct à tout moment, sur autant d'appareils que voulu,
 *   même avant l'ouverture ou quand l'accès est fermé.
 *
 * Seule son empreinte (bcrypt) est conservée : le code lui-même n'est affiché
 * qu'une fois, à sa création.
 */
final class SuperCode
{
    private const PREFIX = 'SUPER';
    private const BODY_LENGTH = 16;
    // Sans 0/O ni 1/I/L pour éviter les erreurs de saisie
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly LiveSettings $settings)
    {
    }

    /** "super-ab12 cd34…" => "SUPERAB12CD34…" */
    public static function normalize(string $raw): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));
    }

    public function isConfigured(): bool
    {
        return '' !== $this->settings->superHash();
    }

    /** Un code saisi peut-il être le supercode ? (évite un calcul bcrypt pour les codes de billets) */
    public static function looksLikeSuperCode(string $normalized): bool
    {
        return str_starts_with($normalized, self::PREFIX) && strlen($normalized) === strlen(self::PREFIX) + self::BODY_LENGTH;
    }

    public function verify(string $normalized): bool
    {
        $hash = $this->settings->superHash();

        return '' !== $hash && self::looksLikeSuperCode($normalized) && password_verify($normalized, $hash);
    }

    /** Empreinte qui change dès que le supercode change : invalide les anciennes sessions. */
    public function fingerprint(): string
    {
        return sha1($this->settings->superHash());
    }

    /**
     * Crée un nouveau supercode, enregistre son empreinte et le retourne (format affichable).
     */
    public function rotate(): string
    {
        [$display, $hash] = self::generate();
        $this->settings->set('super_code_hash', $hash);

        return $display;
    }

    /** @return array{0: string, 1: string} [code affichable, empreinte] */
    public static function generate(): array
    {
        $body = '';
        for ($i = 0; $i < self::BODY_LENGTH; ++$i) {
            $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $normalized = self::PREFIX.$body;
        $display = self::PREFIX.'-'.implode('-', str_split($body, 4));

        return [$display, password_hash($normalized, \PASSWORD_BCRYPT, ['cost' => 12])];
    }
}
