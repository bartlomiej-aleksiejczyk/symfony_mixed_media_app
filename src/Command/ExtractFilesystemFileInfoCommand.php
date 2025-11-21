<?php

namespace App\Command;

use App\Service\FilesystemIndexService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:extract-file-info')]
class ExtractFilesystemFileInfoCommand extends Command
{
    public function __construct(
        private readonly FilesystemIndexService $indexService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'directory',
                InputArgument::REQUIRED,
                'Base media directory (same as Go worker directory)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $baseDir = rtrim($input->getArgument('directory'), DIRECTORY_SEPARATOR);

        if (!is_dir($baseDir)) {
            $io->error(sprintf('Directory "%s" does not exist', $baseDir));
            return Command::FAILURE;
        }

        $this->indexService->indexDirectory($baseDir, $io);

        return Command::SUCCESS;
    }
}
