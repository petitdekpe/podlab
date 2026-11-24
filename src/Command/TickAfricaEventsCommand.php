<?php

namespace App\Command;

use App\Live\TickAfrica;
use App\Live\TickAfricaUnavailable;
use App\Programme\Programme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tickafrica:events', description: 'Liste vos événements Tick Africa et leur identifiant (à reporter dans /admin)')]
final class TickAfricaEventsCommand extends Command
{
    public function __construct(
        private readonly TickAfrica $tickAfrica,
        private readonly Programme $programme,
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

        $usages = [];
        foreach ($this->programme->tousEvenements() as $evenement) {
            $usages[$evenement->tickafricaEventId][] = $evenement->libelle();
        }
        try {
            foreach ($this->programme->tousDirects() as $direct) {
                $usages[$direct->tickafricaEventId][] = 'direct : '.$direct->titre;
            }
        } catch (\Throwable) {
            $io->warning('Base injoignable : directs non affichés.');
        }
        $io->table(['Identifiant', 'Événement', 'Début', 'Utilisé sur le site'], array_map(static fn (array $e): array => [
            (string) ($e['id'] ?? ''),
            (string) ($e['name'] ?? ''),
            (string) ($e['starts_at'] ?? ''),
            implode("\n", $usages[strtolower((string) ($e['id'] ?? ''))] ?? []),
        ], $events));

        if ($this->tickAfrica->isTestMode()) {
            $io->note('Clé de test : seuls les billets de test seront acceptés.');
        }

        return Command::SUCCESS;
    }
}
