<?php

namespace App\Command;

use App\Live\LiveSettings;
use App\Live\TickAfrica;
use App\Live\TickAfricaUnavailable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tickafrica:events', description: 'Liste vos événements Tick Africa et leur identifiant (à reporter dans TICKAFRICA_EVENT_ID ou dans /admin)')]
final class TickAfricaEventsCommand extends Command
{
    public function __construct(
        private readonly TickAfrica $tickAfrica,
        private readonly LiveSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('search', InputArgument::OPTIONAL, 'Filtre sur le nom de l\'événement', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $events = $this->tickAfrica->events((string) $input->getArgument('search'));
        } catch (TickAfricaUnavailable $e) {
            $io->error($e->getMessage().' Vérifiez TICKAFRICA_API_KEY et la portée events:read de la clé.');

            return Command::FAILURE;
        }

        if ([] === $events) {
            $io->warning('Aucun événement publié et en vente.');

            return Command::SUCCESS;
        }

        $current = $this->settings->tickafricaEventId();
        $io->table(['Identifiant', 'Événement', 'Début', ''], array_map(static fn (array $e): array => [
            (string) ($e['id'] ?? ''),
            (string) ($e['name'] ?? ''),
            (string) ($e['starts_at'] ?? ''),
            ($e['id'] ?? null) === $current ? '← direct' : '',
        ], $events));

        if ($this->tickAfrica->isTestMode()) {
            $io->note('Clé de test : seuls les billets de test seront acceptés.');
        }

        return Command::SUCCESS;
    }
}
