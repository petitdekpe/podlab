<?php

namespace App\Command;

use App\Live\LiveCodes;
use App\Live\TickAfrica;
use App\Live\TickAfricaUnavailable;
use App\Programme\Programme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tickafrica:check', description: 'Vérifie un billet auprès de Tick Africa SANS enregistrer d\'entrée (le billet reste utilisable)')]
final class TickAfricaCheckCommand extends Command
{
    public function __construct(
        private readonly TickAfrica $tickAfrica,
        private readonly Programme $programme,
        private readonly LiveCodes $codes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('code', InputArgument::REQUIRED, 'Code du billet (casse comprise)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = LiveCodes::clean((string) $input->getArgument('code'));
        $direct = $this->programme->directActuel();
        $eventId = $direct?->tickafricaEventId ?? '';

        if ('' === $eventId) {
            $io->warning('Aucun événement du direct configuré : la vérification porte sur tous vos événements.');
        }

        try {
            $verdict = $this->tickAfrica->check($code, $eventId);
        } catch (TickAfricaUnavailable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Valable' => $verdict->valid ? 'oui' : 'non'],
            ['Motif' => $verdict->reason],
            ['Message' => $verdict->message ?: '—'],
            ['Porteur' => $verdict->holder ?? '—'],
            ['Billet' => $verdict->ticketType ?? '—'],
            ['Événement' => $verdict->eventName ?? '—'],
            ['Entré le' => $verdict->enteredAt ?? '—'],
            ['Déjà validé sur ce site' => null !== $direct && null !== $this->codes->find($direct, $verdict->code ?? LiveCodes::key($code)) ? 'oui' : 'non'],
        );

        return $verdict->valid ? Command::SUCCESS : Command::FAILURE;
    }
}
