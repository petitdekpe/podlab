<?php

namespace App\Live;

use App\Entity\LiveCode;
use App\Repository\LiveCodeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Billets d'accès au direct, délivrés par Tick Africa.
 *
 * À sa première saisie, un code est validé auprès de Tick Africa (l'entrée y est
 * enregistrée, le billet est donc consommé), puis conservé ici : les connexions
 * suivantes, depuis cet appareil ou un autre, ne refont pas d'appel.
 */
final class LiveCodes
{
    /** Codes de billet, ou contenu de QR "CODE:signature" */
    private const INPUT_PATTERN = '/^[A-Za-z0-9:_+\/=.-]{4,200}$/';

    public function __construct(
        private readonly LiveCodeRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly TickAfrica $tickAfrica,
        private readonly LiveSettings $settings,
    ) {
    }

    /** Retire les espaces ; la casse est conservée : les codes Tick Africa y sont sensibles. */
    public static function clean(string $raw): string
    {
        return (string) preg_replace('/\s+/u', '', $raw);
    }

    /** "kR4mNpQ2:3fA9xQ7L…" (contenu du QR) => "kR4mNpQ2" (code imprimé) */
    public static function key(string $cleaned): string
    {
        return explode(':', $cleaned, 2)[0];
    }

    /** Billet déjà validé sur ce site. */
    public function find(string $code): ?LiveCode
    {
        return $this->repository->findOneByCode($code);
    }

    /**
     * Fait valider par Tick Africa un code jamais vu ici, et l'enregistre s'il est admis.
     *
     * @return LiveCode|TicketVerdict le billet admis, ou le refus de Tick Africa
     *
     * @throws TickAfricaUnavailable
     */
    public function redeem(string $input): LiveCode|TicketVerdict
    {
        if (!preg_match(self::INPUT_PATTERN, $input)) {
            return TicketVerdict::refused('invalid_code');
        }

        $eventId = $this->settings->tickafricaEventId();
        if ('' === $eventId) {
            // Sans événement, un billet de la salle serait accepté… et son entrée enregistrée
            throw new TickAfricaUnavailable('Événement Tick Africa du direct non configuré.');
        }

        $verdict = $this->tickAfrica->validate($input, $eventId);

        if (!$verdict->valid) {
            // Déjà entré… peut-être par ce site (saisie simultanée sur deux appareils, autre casse)
            if ('already_used' === $verdict->reason && null !== $verdict->code && null !== ($known = $this->find($verdict->code))) {
                return $known;
            }

            return $verdict;
        }

        $key = $verdict->code ?? self::key($input);
        $code = $this->find($key);

        if (null === $code) {
            $code = new LiveCode($key, $verdict->label());
            $this->em->persist($code);
            $this->em->flush();
        }

        return $code;
    }
}
