<?php

namespace App\Twig;

use App\Entity\Lieu;
use App\Site\PhotosLieux;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** lieu_photo(lieu) : adresse de la photo d'un lieu, ou '' s'il n'en a pas. */
final class LieuExtension extends AbstractExtension
{
    public function __construct(
        private readonly PhotosLieux $photos,
        private readonly Packages $assets,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('lieu_photo', $this->photo(...))];
    }

    public function photo(Lieu $lieu): string
    {
        return match (true) {
            !$this->photos->disponible($lieu) => '',
            $lieu->photoDuSite() => $this->assets->getUrl($lieu->photo),
            default => $this->urls->generate('app_lieu_photo', ['fichier' => $lieu->photo]),
        };
    }
}
