<?php

namespace App\Command;

use App\Billetterie\Billetterie;
use App\Billetterie\Seance;
use App\Live\TickAfrica;
use App\Live\TickAfricaUnavailable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tickafrica:event', description: 'Affiche un événement Tick Africa (dates, tarifs, places restantes), ou sans argument l\'état des séances du site')]
final class TickAfricaEventCommand extends Command
{
    public function __construct(
        private readonly TickAfrica $tickAfrica,
        private readonly Billetterie $billetterie,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('event', InputArgument::OPTIONAL, 'UUID de l\'événement');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('event');

        if ('' === $id) {
            $io->table(['Séance', 'Salle', 'État', 'Événement', 'Tarifs'], array_map(static fn (Seance $s): array => [
                $s->jourCourt().' '.$s->heure(),
                $s->salle['name'],
                $s->status.($s->stale ? ' (relevé ancien)' : '').($s->reason ? "\n".wordwrap(Seance::REASONS[$s->reason] ?? $s->reason, 40) : ''),
                $s->eventId ?: '—',
                implode("\n", array_map(static fn ($t): string => sprintf('%s · %d FCFA · %s', $t->name, $t->price, null === $t->remaining ? 'illimité' : $t->remaining.' restantes'), $s->tarifs)) ?: '—',
            ], array_values($this->billetterie->seances())));

            return Command::SUCCESS;
        }

        try {
            $event = $this->tickAfrica->event($id);
        } catch (TickAfricaUnavailable $e) {
            $io->error($e->getMessage().' Vérifiez l\'identifiant et la portée events:read de la clé.');

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Événement' => (string) ($event['name'] ?? '')],
            ['En vente' => ($event['is_on_sale'] ?? false) ? 'oui' : 'non'],
            ['Vendable par l\'API' => ($event['sellable_via_api'] ?? false) ? 'oui' : 'non (plan de salle)'],
        );

        $io->section('Dates');
        $io->table(['Identifiant', 'Nom', 'Début'], array_map(static fn (array $d): array => [
            (string) ($d['id'] ?? ''), (string) ($d['name'] ?? ''), (string) ($d['starts_at'] ?? ''),
        ], \is_array($event['dates'] ?? null) ? $event['dates'] : []));

        $io->section('Tarifs');
        $io->table(['Identifiant', 'Nom', 'Type', 'Prix', 'Restantes', 'En vente'], array_map(static fn (array $p): array => [
            (string) ($p['id'] ?? ''),
            (string) ($p['name'] ?? ''),
            (string) ($p['type'] ?? ''),
            (string) ($p['price'] ?? ''),
            null === ($p['available_quantity'] ?? null) ? 'illimité' : (string) $p['available_quantity'],
            ($p['is_available'] ?? false) ? 'oui' : 'non',
        ], \is_array($event['products'] ?? null) ? $event['products'] : []));

        $io->note('Pour vendre ces billets : /admin › Évènements, champ « Événement Tick Africa » = '.$id.'.');

        return Command::SUCCESS;
    }
}
