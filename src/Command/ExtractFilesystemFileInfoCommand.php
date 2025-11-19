<?php

namespace App\Command;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;


#[AsCommand(name: 'app:extract-file-info')]
class ExtractFilesystemFileInfoCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
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

        $start         = microtime(true);
        $scanTimestamp = $this->nowUtcTruncated();

        $io->writeln(sprintf(
            '<info>[worker]</info> starting scan in "%s" at %s',
            $baseDir,
            $scanTimestamp->format(DATE_ATOM)
        ));

        $this->scanFiles($baseDir, $scanTimestamp, $io);
        $this->cleanupDeletedFiles($scanTimestamp);
        $this->cleanupUnusedTags();
        $this->rebuildPathLabels($baseDir);

        $duration = microtime(true) - $start;
        $io->success(sprintf('[worker] cycle finished in %.2f seconds', $duration));

        return Command::SUCCESS;
    }

    // ---------------------------------------------------------
    // 1) Filesystem scan -> filesystem_file
    // ---------------------------------------------------------
    private function scanFiles(
        string $baseDir,
        DateTimeImmutable $scanTimestamp,
        SymfonyStyle $io
    ): void {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $baseDir,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        $selectStmt = $this->connection->prepare(
            'SELECT id, hash, size, last_seen, modified_time 
             FROM filesystem_file 
             WHERE path = :path'
        );

        $insertStmt = $this->connection->prepare(
            'INSERT INTO filesystem_file (path, hash, size, last_seen, modified_time)
             VALUES (:path, :hash, :size, :last_seen, :modified_time)
             RETURNING id'
        );

        $updateLastSeenStmt = $this->connection->prepare(
            'UPDATE filesystem_file 
             SET last_seen = :last_seen 
             WHERE id = :id'
        );

        /** @var \SplFileInfo $fileInfo */
        foreach ($iter as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $fullPath     = $fileInfo->getPathname();
            $size         = $fileInfo->getSize();
            $modifiedTime = $this->fromFileMTime($fileInfo->getMTime());

            $row = $selectStmt
                ->executeQuery(['path' => $fullPath])
                ->fetchAssociative();

            $currentHash = null;
            $fileId      = null;

            if ($row === false) {
                // --- new file ---
                $hash = $this->computeFileHash($fullPath, $io);
                if ($hash === null) {
                    continue;
                }

                $fileId = (int) $insertStmt
                    ->executeQuery([
                        'path'          => $fullPath,
                        'hash'          => $hash,
                        'size'          => $size,
                        'last_seen'     => $scanTimestamp,
                        'modified_time' => $modifiedTime,
                    ])
                    ->fetchOne();

                $currentHash = $hash;
            } else {
                // --- existing file ---
                $fileId     = (int) $row['id'];
                $dbHash     = (string) $row['hash'];
                $dbSize     = (int) $row['size'];
                $dbModified = $this->fromDbTimestamp($row['modified_time']);

                $dbModifiedNorm = $this->truncateToSecondUtc($dbModified);
                $modifiedNorm   = $this->truncateToSecondUtc($modifiedTime);

                if ($dbSize !== $size || $dbModifiedNorm != $modifiedNorm) {
                    // modified file: recompute hash, insert new row
                    $hash = $this->computeFileHash($fullPath, $io);
                    if ($hash === null) {
                        continue;
                    }

                    $fileId = (int) $insertStmt
                        ->executeQuery([
                            'path'          => $fullPath,
                            'hash'          => $hash,
                            'size'          => $size,
                            'last_seen'     => $scanTimestamp,
                            'modified_time' => $modifiedTime,
                        ])
                        ->fetchOne();

                    $currentHash = $hash;
                } else {
                    // unchanged file: update last_seen only
                    $updateLastSeenStmt->executeStatement([
                        'last_seen' => $scanTimestamp,
                        'id'        => $fileId,
                    ]);
                    $currentHash = $dbHash;
                }
            }

            if ($currentHash !== null) {
                $this->syncPathTagsForFile($currentHash, $baseDir, $fullPath, $io);
            }
        }
    }

    private function cleanupDeletedFiles(DateTimeImmutable $scanTimestamp): void
    {
        $this->connection->executeStatement(
            'DELETE FROM filesystem_file WHERE last_seen != :ts',
            ['ts' => $scanTimestamp]
        );
    }

    // ---------------------------------------------------------
    // 2) Tag sync: path -> tag / media_file_tag
    // ---------------------------------------------------------
    private function syncPathTagsForFile(
        string $hash,
        string $baseDir,
        string $fullPath,
        SymfonyStyle $io
    ): void {
        // Similar to filepath.Rel(baseDir, fullPath)
        $rel = Path::makeRelative($fullPath, $baseDir);

        // Outside root?
        if (str_starts_with($rel, '..')) {
            return;
        }

        $dir = Path::getDirectory($rel);
        if ($dir === '' || $dir === '.' || $dir === DIRECTORY_SEPARATOR) {
            return;
        }

        // "funny/lighthearted" -> ["funny", "lighthearted"]
        $segments = preg_split('~[/\\\\]+~', $dir, -1, PREG_SPLIT_NO_EMPTY);
        if (!$segments) {
            return;
        }

        // media_file lookup by hash
        $mediaId = $this->connection->fetchOne(
            'SELECT id FROM media_file WHERE hash = :hash',
            ['hash' => $hash]
        );

        foreach ($segments as $seg) {
            $name = trim($seg);
            if ($name === '' || $name === '.') {
                continue;
            }

            try {
                // Path tags are always created as is_managed = FALSE,
                // ON CONFLICT: keep existing is_managed.
                $tagId = $this->connection->fetchOne(
                    'INSERT INTO tag (name, is_managed)
                     VALUES (:name, FALSE)
                     ON CONFLICT (name) DO UPDATE
                        SET name = EXCLUDED.name
                     RETURNING id',
                    ['name' => $name]
                );

                if ($tagId === false) {
                    continue;
                }

                if ($mediaId !== false) {
                    $this->connection->executeStatement(
                        'INSERT INTO media_file_tag (media_file_id, tag_id)
                         VALUES (:media_id, :tag_id)
                         ON CONFLICT (media_file_id, tag_id) DO NOTHING',
                        [
                            'media_id' => (int) $mediaId,
                            'tag_id'   => (int) $tagId,
                        ]
                    );
                }
            } catch (\Throwable $e) {
                $io->writeln(sprintf(
                    '<comment>[tags]</comment> error syncing tag "%s" for %s: %s',
                    $name,
                    $fullPath,
                    $e->getMessage()
                ));
            }
        }
    }

    private function cleanupUnusedTags(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM tag t
             WHERE t.is_managed = FALSE
               AND NOT EXISTS (
                   SELECT 1
                   FROM media_file_tag mft
                   WHERE mft.tag_id = t.id
               )'
        );
    }

    // ---------------------------------------------------------
    // 3) path_label rebuild (like RebuildPathCategories)
    // ---------------------------------------------------------
    private function rebuildPathLabels(string $mediaRoot): void
    {
        $counts = [];

        $result = $this->connection->executeQuery('SELECT path FROM filesystem_file');

        while ($row = $result->fetchAssociative()) {
            $fullPath = $row['path'];

            $rel = Path::makeRelative($fullPath, $mediaRoot);
            if (str_starts_with($rel, '..')) {
                // outside mediaRoot
                continue;
            }

            $dir = Path::getDirectory($rel);
            if ($dir === '' || $dir === '.' || $dir === DIRECTORY_SEPARATOR) {
                continue;
            }

            $segments = preg_split('~[/\\\\]+~', $dir, -1, PREG_SPLIT_NO_EMPTY);
            if (!$segments) {
                continue;
            }

            foreach ($segments as $seg) {
                $name = trim($seg);
                if ($name === '' || $name === '.') {
                    continue;
                }
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        $this->connection->beginTransaction();
        try {
            // Full rebuild
            $this->connection->executeStatement('DELETE FROM path_label');

            $insertStmt = $this->connection->prepare(
                'INSERT INTO path_label (name, item_count) VALUES (:name, :count)'
            );

            foreach ($counts as $name => $count) {
                $insertStmt->executeStatement([
                    'name'  => $name,
                    'count' => $count,
                ]);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------
    private function computeFileHash(string $path, SymfonyStyle $io): ?string
    {
        $hash = @hash_file('sha256', $path);
        if ($hash === false) {
            $io->writeln(sprintf(
                '<error>Error computing hash for file %s</error>',
                $path
            ));
            return null;
        }

        return $hash;
    }

    private function nowUtcTruncated(): DateTimeImmutable
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new DateTimeImmutable(
            $now->format('Y-m-d H:i:s'),
            new DateTimeZone('UTC')
        );
    }

    private function fromFileMTime(int $mtime): DateTimeImmutable
    {
        // mtime is already second-precision
        $dt = (new DateTimeImmutable('@' . $mtime))
            ->setTimezone(new DateTimeZone('UTC'));

        return $this->truncateToSecondUtc($dt);
    }

    private function fromDbTimestamp(string $value): DateTimeImmutable
    {
        // DBAL gives timestamptz as string; DateTimeImmutable parses it
        return new DateTimeImmutable($value);
    }

    private function truncateToSecondUtc(DateTimeImmutable $dt): DateTimeImmutable
    {
        return new DateTimeImmutable(
            $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            new DateTimeZone('UTC')
        );
    }
}
