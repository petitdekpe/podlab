<?php

namespace App\Billetterie;

/**
 * Un type de billet Tick Africa d'une séance, avec sa disponibilité au moment de la lecture.
 */
final class Tarif
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $price,
        /** Places restantes ; null = illimité (et non épuisé) */
        public readonly ?int $remaining,
        public readonly bool $available,
    ) {
    }

    /**
     * @param array<string, mixed> $product élément de `products` de GET /events/{id}
     */
    public static function fromApi(array $product): ?self
    {
        $id = (string) ($product['id'] ?? '');
        if ('' === $id) {
            return null;
        }

        $remaining = isset($product['available_quantity']) && is_numeric($product['available_quantity'])
            ? max(0, (int) $product['available_quantity'])
            : null;

        return new self(
            $id,
            trim((string) ($product['name'] ?? '')) ?: 'Billet',
            (int) round((float) ($product['price'] ?? 0)),
            $remaining,
            (bool) ($product['is_available'] ?? false) && 0 !== $remaining,
        );
    }

    /** Nombre maximal de billets par commande sur ce tarif. */
    public function maxPerOrder(): int
    {
        return null === $this->remaining ? Commande::MAX_QUANTITY : max(0, min(Commande::MAX_QUANTITY, $this->remaining));
    }
}
