<?php

namespace App\Live;

use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Paramètres effectifs du direct : valeur saisie dans l'administration
 * (table app_setting) ou, à défaut, valeur de l'environnement.
 */
final class LiveSettings
{
    public const TIMEZONE = 'Africa/Porto-Novo';
    public const DEFAULT_TITLE = 'Edgar-Yves : Moi, à quatre pattes ?!';

    /** @var array<string, string|null>|null */
    private ?array $values = null;

    /** Séances du site dont l'événement Tick Africa se règle dans /admin */
    public const SEANCES = ['marina-22', 'marina-28', 'wologuede-28'];

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

    /**
     * Événement Tick Africa d'une séance saisi dans /admin : « uuid » ou
     * « uuid:uuid-tarif,uuid-tarif ». '' s'il n'est pas défini.
     */
    public function seanceLink(string $seance): string
    {
        return trim((string) $this->get('seance_'.$seance));
    }

    /**
     * Capacité totale de la salle pour une séance, saisie dans /admin ; null si
     * non renseignée. Sert au bilan /ventes : le remplissage se calcule sur la
     * salle entière et non sur les seuls billets mis en vente chez Tick Africa.
     */
    public function seanceCapacity(string $seance): ?int
    {
        $valeur = trim((string) $this->get('capacite_'.$seance));

        return ctype_digit($valeur) && (int) $valeur > 0 ? (int) $valeur : null;
    }

    public function title(): string
    {
        $title = trim((string) $this->get('title'));

        return '' !== $title ? $title : self::DEFAULT_TITLE;
    }

    public function youtubeId(): string
    {
        return self::extractVideoId((string) ($this->get('youtube') ?? $this->defaults->youtube));
    }

    public function opensAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable((string) ($this->get('opens_at') ?: $this->defaults->opensAt));
    }

    public function endsAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable((string) ($this->get('ends_at') ?: $this->defaults->endsAt));
    }

    /** Interrupteur général : à faux, aucun billet ne donne accès (le supercode reste valable). */
    public function enabled(): bool
    {
        return '0' !== $this->get('enabled');
    }

    public function watermark(): bool
    {
        return '0' !== $this->get('watermark');
    }

    /** Billetterie en salle complète (filtre « Sold out » sur le site). Par défaut : oui. */
    public function soldOut(): bool
    {
        return '0' !== $this->get('soldout');
    }

    /** Lien de la billetterie des accès au direct (affichée dans la fenêtre pop-up), ou '' s'il n'est pas défini. */
    public function liveTicketUrl(): string
    {
        $url = self::normalizeEmbedUrl((string) $this->get('live_ticket_url'));

        return '' !== $url ? $url : $this->defaultLiveTicketUrl();
    }

    /** Lien par défaut (variable LIVE_TICKETS_URL), utilisé tant qu'aucun lien n'est saisi dans l'administration. */
    public function defaultLiveTicketUrl(): string
    {
        return self::normalizeEmbedUrl($this->defaults->liveTicketUrl);
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

    /** UUID de l'événement Tick Africa du direct (administration, sinon TICKAFRICA_EVENT_ID), ou ''. */
    public function tickafricaEventId(): string
    {
        $id = self::normalizeUuid((string) $this->get('tickafrica_event_id'));

        return '' !== $id ? $id : self::normalizeUuid($this->tickafricaEventIdDefault());
    }

    /** Valeur par défaut (variable TICKAFRICA_EVENT_ID). */
    public function tickafricaEventIdDefault(): string
    {
        return $this->defaults->tickafricaEventId;
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
