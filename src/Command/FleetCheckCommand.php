<?php
namespace App\Command;

use App\Service\AlertEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:fleet:check', description: 'Génère et met à jour les alertes de la flotte')]
final class FleetCheckCommand extends Command
{
    public function __construct(private AlertEngine $engine)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $r = $this->engine->run(true);
        if ($r === null) {
            $output->writeln('Un autre traitement est déjà en cours.');
            return Command::SUCCESS;
        }
        $output->writeln(sprintf('Alertes : %d créée(s), %d mise(s) à jour, %d résolue(s).', $r['created'], $r['updated'], $r['resolved']));
        return Command::SUCCESS;
    }
}