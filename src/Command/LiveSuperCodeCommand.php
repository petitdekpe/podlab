<?php

namespace App\Command;

use App\Live\SuperCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:live:supercode', description: 'Crée un nouveau supercode (ouvre /admin et le direct)')]
final class LiveSuperCodeCommand extends Command
{
    public function __construct(private readonly SuperCode $superCode)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('hash-only', null, InputOption::VALUE_NONE, 'Ne touche pas à la base : affiche le code et la ligne LIVE_SUPERCODE_HASH à mettre dans .env.local');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('hash-only')) {
            [$display, $hash] = SuperCode::generate();
            $io->success('Supercode : '.$display);
            $io->writeln('À ajouter dans .env.local (gardez les apostrophes) :');
            $io->writeln("LIVE_SUPERCODE_HASH='".$hash."'");
            $io->note('Notez le supercode maintenant : seule son empreinte est conservée.');

            return Command::SUCCESS;
        }

        $io->success('Nouveau supercode : '.$this->superCode->rotate());
        $io->note('Notez-le maintenant : seule son empreinte est conservée. L\'ancien supercode ne fonctionne plus.');

        return Command::SUCCESS;
    }
}
