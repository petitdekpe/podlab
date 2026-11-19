<?php

namespace App\Command;

use App\Live\LiveCodes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:live:codes', description: 'Génère des codes d\'accès au direct en base de données, ou importe une liste existante')]
final class LiveCodesCommand extends Command
{
    public function __construct(private readonly LiveCodes $codes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('count', InputArgument::OPTIONAL, 'Nombre de codes à générer', '0')
            ->addOption('csv', null, InputOption::VALUE_REQUIRED, 'Écrit aussi les codes générés dans ce fichier CSV (numero;code), à remettre à Tickafrica')
            ->addOption('import', null, InputOption::VALUE_REQUIRED, 'Importe les codes d\'un fichier (un par ligne, ou CSV : numero;code). Les doublons sont ignorés');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = (int) $input->getArgument('count');
        $import = $input->getOption('import');

        if (null !== $import) {
            if (!is_file($import)) {
                $io->error('Fichier introuvable : '.$import);

                return Command::FAILURE;
            }
            $result = $this->codes->import($this->readCodes($import));
            $io->success(sprintf('%d code(s) importé(s), %d déjà présent(s), %d ligne(s) ignorée(s).', $result['added'], $result['skipped'], $result['invalid']));

            return Command::SUCCESS;
        }

        if ($count < 1 || $count > 5000) {
            $io->error('Indiquez un nombre de codes entre 1 et 5000, ou --import=fichier.');

            return Command::INVALID;
        }

        $created = $this->codes->generate($count);
        $io->success(sprintf('%d code(s) créé(s) en base de données.', count($created)));

        if ($csv = $input->getOption('csv')) {
            $dir = dirname($csv);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $rows = ['numero;code'];
            foreach ($created as $i => $code) {
                $rows[] = ($i + 1).';'.LiveCodes::format($code->getCode());
            }
            file_put_contents($csv, "\xEF\xBB\xBF".implode("\r\n", $rows)."\r\n");
            $io->writeln('Liste écrite dans : '.$csv);
        }

        return Command::SUCCESS;
    }

    /** @return \Generator<string> */
    private function readCodes(string $file): \Generator
    {
        $content = (string) file_get_contents($file);
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content; // BOM

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            // "12;PL-AB12-CD34" : on garde le dernier champ numérique-préfixé s'il y a un numéro
            if (preg_match('/^\s*\d+\s*[;,\t]\s*(.+)$/', $line, $m)) {
                $line = $m[1];
            }
            yield $line;
        }
    }
}
