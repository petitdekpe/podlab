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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LiveConfig $defaults,
    ) {
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
