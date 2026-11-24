<?php

namespace App\Twig;

use App\Live\LiveSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** analytics_id() : identifiant de mesure Google Analytics saisi dans /admin, ou ''. */
final class AnalyticsExtension extends AbstractExtension
{
    public function __construct(private readonly LiveSettings $settings)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('analytics_id', $this->analyticsId(...))];
    }

    public function analyticsId(): string
    {
        try {
            return $this->settings->analyticsId();
        } catch (\Throwable) {
            return ''; // base indisponible : la page s'affiche, sans mesure
        }
    }
}
