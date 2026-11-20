<?php

namespace App\Live;

/**
 * Réponse de Tick Africa sur un billet (POST /tickets/validate ou /tickets/check).
 *
 * Un refus n'est pas une erreur de l'API : valid vaut false et reason donne le motif
 * (invalid_code, wrong_event, cancelled, not_activated, wrong_day, too_early,
 * already_used, test_ticket).
 */
final class TicketVerdict
{
    public function __construct(
        public readonly bool $valid,
        public readonly string $reason,
        public readonly string $message = '',
        /** Code du billet tel que Tick Africa le connaît */
        public readonly ?string $code = null,
        public readonly ?string $holder = null,
        public readonly ?string $ticketType = null,
        public readonly ?string $eventId = null,
        public readonly ?string $eventName = null,
        public readonly ?string $enteredAt = null,
    ) {
    }

    public static function refused(string $reason): self
    {
        return new self(false, $reason);
    }

    /** @throws TickAfricaUnavailable si la réponse n'a pas la forme attendue */
    public static function fromApi(mixed $data): self
    {
        if (!\is_array($data) || !\is_bool($data['valid'] ?? null) || !\is_string($data['reason'] ?? null)) {
            throw new TickAfricaUnavailable('Réponse inattendue de Tick Africa.');
        }

        $ticket = \is_array($data['ticket'] ?? null) ? $data['ticket'] : [];
        $str = static fn (mixed $v): ?string => \is_string($v) && '' !== trim($v) ? trim($v) : null;

        return new self(
            $data['valid'],
            $data['reason'],
            (string) $str($data['message'] ?? null),
            $str($ticket['code'] ?? null),
            $str($ticket['holder'] ?? null),
            $str($ticket['ticket_type'] ?? null),
            $str($ticket['event_id'] ?? null),
            $str($ticket['event_name'] ?? null),
            $str($ticket['entered_at'] ?? null),
        );
    }

    /** Libellé enregistré sur le billet : "Awa Diallo · Pass VIP" */
    public function label(): ?string
    {
        $label = implode(' · ', array_filter([$this->holder, $this->ticketType]));

        return '' === $label ? null : $label;
    }

    /** Message affiché au spectateur dont le billet est refusé. */
    public function publicMessage(): string
    {
        return match ($this->reason) {
            'invalid_code' => 'Ce code n\'est pas reconnu. Recopiez-le tel qu\'il figure sur votre billet Tick Africa, en respectant les majuscules et les minuscules.',
            'wrong_event' => 'Ce billet ne donne pas accès au direct : il faut un billet « accès au direct » (streaming).',
            'cancelled' => 'Ce billet a été annulé ou remboursé.',
            'not_activated' => 'Ce billet n\'est pas encore activé. Contactez les organisateurs.',
            'test_ticket' => 'Ce billet est un billet de test : il ne donne pas accès au direct.',
            'wrong_day' => 'Ce billet n\'est pas valable aujourd\'hui.',
            'too_early' => 'Les billets ne peuvent pas encore être validés. Réessayez à l\'ouverture du direct.',
            'already_used' => 'Ce billet a déjà été utilisé. S\'il s\'agit bien du vôtre, contactez les organisateurs.',
            default => '' !== $this->message ? $this->message : 'Ce billet n\'est pas valide.',
        };
    }
}
