<?php

namespace App\Billetterie;

use Symfony\Component\HttpFoundation\Request;

/**
 * Formulaire de réservation du site : saisie de l'acheteur et contrôles,
 * avant l'envoi à Tick Africa (qui revérifie tout : stock, prix, coordonnées).
 */
final class Commande
{
    public const MAX_QUANTITY = 10;

    /** @var array<string, string> champ => message */
    public array $errors = [];

    public function __construct(
        /** « cle-de-seance|uuid-du-tarif » : un seul choix porte la séance et le billet */
        public string $billet = '',
        public int $quantity = 1,
        public string $firstName = '',
        public string $lastName = '',
        public string $email = '',
        public string $phone = '',
        /** Une clé par tentative de commande : rejouer le formulaire ne crée pas un second paiement */
        public string $idempotencyKey = '',
    ) {
        if ('' === $this->idempotencyKey) {
            $this->idempotencyKey = self::newKey();
        }
    }

    public static function fromRequest(Request $request): self
    {
        $r = $request->request;
        $key = (string) $r->get('cle', '');

        return new self(
            trim((string) $r->get('billet', '')),
            (int) $r->get('quantite', 1),
            self::clean((string) $r->get('prenom', ''), 80),
            self::clean((string) $r->get('nom', ''), 80),
            strtolower(self::clean((string) $r->get('email', ''), 180)),
            self::clean((string) $r->get('telephone', ''), 30),
            preg_match('/^[0-9a-f-]{36}$/', $key) ? $key : '',
        );
    }

    public function seanceKey(): string
    {
        return explode('|', $this->billet, 2)[0];
    }

    public function tarifId(): string
    {
        return explode('|', $this->billet, 2)[1] ?? '';
    }

    /**
     * Contrôle la saisie au regard des séances en vente. Remplit $errors.
     *
     * @param array<string, Seance> $seances
     */
    public function validate(array $seances): bool
    {
        $this->errors = [];
        $seance = $seances[$this->seanceKey()] ?? null;
        $tarif = $seance?->tarif($this->tarifId());

        if (null === $seance || null === $tarif) {
            $this->errors['billet'] = 'Choisissez une séance.';
        } elseif (!$seance->onSale() || !$tarif->available) {
            $this->errors['billet'] = 'Cette séance n\'est plus en vente. Choisissez-en une autre.';
        } elseif ($this->quantity < 1 || $this->quantity > $tarif->maxPerOrder()) {
            $this->errors['quantite'] = $tarif->maxPerOrder() < self::MAX_QUANTITY
                ? sprintf('Il ne reste que %d place%s pour cette séance.', $tarif->maxPerOrder(), $tarif->maxPerOrder() > 1 ? 's' : '')
                : sprintf('De 1 à %d billets par commande.', self::MAX_QUANTITY);
        }

        if ('' === $this->firstName) {
            $this->errors['prenom'] = 'Indiquez votre prénom.';
        }
        if ('' === $this->lastName) {
            $this->errors['nom'] = 'Indiquez votre nom.';
        }
        if (!filter_var($this->email, \FILTER_VALIDATE_EMAIL)) {
            $this->errors['email'] = 'Indiquez une adresse e-mail valide : vos billets y seront envoyés.';
        }
        if ('' === self::normalizePhone($this->phone)) {
            $this->errors['telephone'] = 'Indiquez un numéro de téléphone valide (ex. 01 66 61 78 78).';
        }

        return [] === $this->errors;
    }

    /**
     * Corps de POST /orders.
     *
     * @return array<string, mixed>
     */
    public function toOrder(Seance $seance, string $returnUrl): array
    {
        $order = [
            'event_id' => $seance->eventId,
            'items' => [['product_id' => $this->tarifId(), 'quantity' => $this->quantity]],
            'customer' => [
                'first_name' => $this->firstName,
                'last_name' => $this->lastName,
                'email' => $this->email,
                'phone' => self::normalizePhone($this->phone),
            ],
            'metadata' => ['seance' => $seance->key, 'source' => 'podlab.beninbouge.com'],
        ];
        if ('' !== $returnUrl) {
            $order['return_url'] = $returnUrl;
        }

        return $order;
    }

    /**
     * Numéro au format international. Sans indicatif, on suppose le Bénin
     * (+229, numéros à 10 chiffres commençant par 01). '' si invalide.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[\s.\-()\/]/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }
        if (!str_starts_with($digits, '+')) {
            $digits = match (true) {
                1 === preg_match('/^01\d{8}$/', $digits) => '+229'.$digits,
                1 === preg_match('/^\d{8}$/', $digits) => '+22901'.$digits, // ancien format à 8 chiffres
                1 === preg_match('/^229\d{8,10}$/', $digits) => '+'.$digits,
                default => '',
            };
        }

        return preg_match('/^\+\d{8,15}$/', $digits) ? $digits : '';
    }

    public static function newKey(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr(\ord($b[6]) & 0x0F | 0x40);
        $b[8] = \chr(\ord($b[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function clean(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, $max);
    }
}
