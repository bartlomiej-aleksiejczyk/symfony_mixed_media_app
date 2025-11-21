<?php

namespace App\Command;

use App\Service\ThumbnailGeneratorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-media-file-thumbnails',
    description: 'Generate small/medium/large thumbnails for media files'
)]
class GenerateMediaFileThumbnails extends Command
{
    public function __construct(
        private readonly ThumbnailGeneratorService $thumbnailGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Regenerate thumbnails even if already present / hasThumbnail = true'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool)$input->getOption('force');

        $this->thumbnailGenerator->generateThumbnails($io, $force);

        return Command::SUCCESS;
    }
}
