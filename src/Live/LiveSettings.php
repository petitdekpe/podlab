<?php

namespace App\Live;

use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Paramètres généraux du site : valeur saisie dans l'administration (table
 * app_setting) ou, à défaut, valeur de l'environnement. Évènements et directs
 * ont leurs propres tables (voir Programme).
 */
final class LiveSettings implements ResetInterface
{
    public const TIMEZONE = 'Africa/Porto-Novo';
    /** Section « Les salles » de l'accueil */
    public const LIEUX_TITRE = 'Où nous retrouver';
    public const LIEUX_PHRASE = 'Fauteuils de cinéma, grand écran, son de salle : le cadre d\'une vraie première.';
    public const DEFAULT_TITLE = 'Edgar-Yves : Moi, à quatre pattes ?!';

    /** @var array<string, string|null>|null */
    private ?array $values = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LiveConfig $defaults,
        private readonly ?SecretBox $secrets = null,
    ) {
    }

    /** Clé API Tick Africa saisie dans /admin (déchiffrée), ou null. */
    public function tickafricaApiKey(): ?string
    {
        $sealed = (string) $this->get('tickafrica_api_key');
        if ('' === $sealed || null === $this->secrets) {
            return null;
        }

        return $this->secrets->open($sealed);
    }

    /** Une clé est enregistrée dans /admin mais ne se déchiffre plus (APP_SECRET changé). */
    public function tickafricaApiKeyUnreadable(): bool
    {
        return '' !== (string) $this->get('tickafrica_api_key') && null === $this->tickafricaApiKey();
    }

    /** @param string|null $apiKey null = retirer la clé de /admin (retour à TICKAFRICA_API_KEY) */
    public function setTickafricaApiKey(#[\SensitiveParameter] ?string $apiKey): void
    {
        if (null !== $apiKey && null === $this->secrets) {
            throw new \LogicException('Chiffrement indisponible.');
        }
        $this->set('tickafrica_api_key', null === $apiKey ? null : $this->secrets->seal($apiKey));
    }

    public function canStoreSecrets(): bool
    {
        return null !== $this->secrets && $this->secrets->isUsable();
    }

    /** URL de retour après paiement saisie dans /admin, ou ''. */
    public function returnUrl(): string
    {
        return trim((string) $this->get('return_url'));
    }

    /**
     * URL de retour par défaut : celle du site, toujours en https (derrière un proxy
     * qui termine le TLS, Symfony la génère sinon en http://, que Tick Africa refuserait).
     */
    public static function defaultReturnUrl(string $generated): string
    {
        $host = (string) parse_url($generated, \PHP_URL_HOST);
        if (\in_array($host, ['localhost', '127.0.0.1'], true)) {
            return $generated;
        }

        return preg_replace('#^http://#i', 'https://', $generated) ?? $generated;
    }

    public function lieuxTitre(): string
    {
        return (string) $this->get('lieux_titre') ?: self::LIEUX_TITRE;
    }

    /** '' : phrase retirée dans /admin */
    public function lieuxPhrase(): string
    {
        return $this->get('lieux_phrase') ?? self::LIEUX_PHRASE;
    }

    /** Identifiant de mesure Google Analytics (G-XXXXXXXXXX), ou '' : pas de mesure d'audience. */
    public function analyticsId(): string
    {
        return self::normalizeAnalyticsId((string) $this->get('analytics_id'));
    }

    public static function normalizeAnalyticsId(string $value): string
    {
        $value = strtoupper(trim($value));

        return preg_match('/^G-[A-Z0-9]{4,20}$/', $value) ? $value : '';
    }

    /** Identifiant du pixel Meta (Facebook, Instagram), ou '' : pas de pixel. */
    public function pixelId(): string
    {
        return self::normalizePixelId((string) $this->get('pixel_id'));
    }

    public static function normalizePixelId(string $value): string
    {
        $value = trim($value);

        return preg_match('/^[0-9]{10,20}$/D', $value) ? $value : '';
    }

    /**
     * N'accepte qu'un lien https. Pour Tickafrica, la couleur principale du site
     * est ajoutée si elle n'est pas déjà dans le lien. Retourne '' si invalide.
     */
    public static function normalizeEmbedUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ('' === $url || false === $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $host = strtolower($parts['host']);
        if (('tick.africa' === $host || str_ends_with($host, '.tick.africa')) && !str_contains($parts['query'] ?? '', 'primary=')) {
            $url .= (isset($parts['query']) ? '&' : '?').'primary=F5B82E';
        }

        return $url;
    }

    public static function normalizeUuid(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) ? $value : '';
    }

    /** Empreinte du supercode : celle de l'administration, sinon celle de l'environnement. */
    public function superHash(): string
    {
        return (string) ($this->get('super_code_hash') ?: $this->defaults->superCodeHash);
    }

    public function get(string $name): ?string
    {
        return $this->load()[$name] ?? null;
    }

    public function set(string $name, ?string $value): void
    {
        $setting = $this->em->find(Setting::class, $name);

        if (null === $setting) {
            $setting = new Setting($name, $value);
            $this->em->persist($setting);
        } else {
            $setting->setValue($value);
        }
        $this->em->flush();
        $this->values = null;
    }

    /** Accepte l'identifiant seul ou un lien YouTube complet (watch, youtu.be, live, embed). */
    public static function extractVideoId(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $value)) {
            return $value;
        }

        if (preg_match('~(?:[?&]v=|youtu\.be/|/live/|/embed/)([A-Za-z0-9_-]{11})~', $value, $m)) {
            return $m[1];
        }

        return '';
    }

    public function reset(): void
    {
        $this->values = null;
    }

    /** @return array<string, string|null> */
    private function load(): array
    {
        if (null === $this->values) {
            $this->values = [];
            foreach ($this->em->getRepository(Setting::class)->findAll() as $setting) {
                $this->values[$setting->getName()] = $setting->getValue();
            }
        }

        return $this->values;
    }
}
