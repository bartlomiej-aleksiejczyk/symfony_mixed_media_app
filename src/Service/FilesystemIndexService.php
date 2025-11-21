<?php

namespace App\Service;

use App\Entity\FilesystemFile;
use App\Entity\MediaFile;
use App\Entity\MediaTypeEnum;
use App\Entity\Tag;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;
use Throwable;

class FilesystemIndexService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection             $connection, // still used for path_label + tag cleanup
        private readonly LoggerInterface        $logger,
    )
    {
    }

    /**
     * Entry point called from the command.
     */
    public function indexDirectory(string $baseDir, SymfonyStyle $io): void
    {
        $start = microtime(true);
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

        $this->em->flush();

        $duration = microtime(true) - $start;
        $io->success(sprintf('[worker] cycle finished in %.2f seconds', $duration));
    }

    // ---------------------------------------------------------
    // 1) Filesystem scan -> FilesystemFile entities (+ MediaFile/Tag)
    // ---------------------------------------------------------

    private function nowUtcTruncated(): DateTime
    {
        $now = new DateTime('now', new DateTimeZone('UTC'));

        // Truncate to second
        return new DateTime(
            $now->format('Y-m-d H:i:s'),
            new DateTimeZone('UTC')
        );
    }

    private function scanFiles(
        string       $baseDir,
        DateTime     $scanTimestamp,
        SymfonyStyle $io
    ): void
    {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $baseDir,
                FilesystemIterator::SKIP_DOTS
            )
        );

        $filesystemRepo = $this->em->getRepository(FilesystemFile::class);
        $mediaRepo = $this->em->getRepository(MediaFile::class);
        $tagRepo = $this->em->getRepository(Tag::class);

        // simple caches to reduce queries
        /** @var array<string,MediaFile> $mediaByHash */
        $mediaByHash = [];
        /** @var array<string,Tag> $tagByName */
        $tagByName = [];

        /** @var SplFileInfo $fileInfo */
        foreach ($iter as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $fullPath = $fileInfo->getPathname();
            $size = $fileInfo->getSize();
            $modifiedTime = $this->fromFileMTime($fileInfo->getMTime());

            /** @var FilesystemFile|null $file */
            $file = $filesystemRepo->findOneBy(['path' => $fullPath]);

            $currentHash = null;

            if ($file === null) {
                // --- new file ---
                $hash = $this->computeFileHash($fullPath, $io);
                if ($hash === null) {
                    continue;
                }

                $file = new FilesystemFile($fullPath, $hash, $size, clone $scanTimestamp);
                $file->setModifiedTime($modifiedTime);
                $file->setIsDeleted(false);

                $currentHash = $hash;
                $this->em->persist($file);
            } else {
                // --- existing file ---
                // ensure resurrected files are not marked deleted
                $file->setIsDeleted(false);

                $dbSize = (int)$file->getSize();     // watch your return type in entity
                $dbModified = $file->getModifiedTime();

                $dbModifiedNorm = $dbModified ? $this->truncateToSecondUtc($dbModified) : null;
                $modifiedNorm = $this->truncateToSecondUtc($modifiedTime);

                if ($dbSize !== $size || $dbModifiedNorm != $modifiedNorm) {
                    // modified file: recompute hash, update entity
                    $hash = $this->computeFileHash($fullPath, $io);
                    if ($hash === null) {
                        continue;
                    }

                    $file->setHash($hash);
                    $file->setSize($size);
                    $file->setModifiedTime($modifiedTime);
                    $file->setLastSeen(clone $scanTimestamp);

                    $currentHash = $hash;
                } else {
                    // unchanged file: update last_seen only
                    $file->setLastSeen(clone $scanTimestamp);
                    $currentHash = $file->getHash();
                }
            }

            if ($currentHash === null) {
                continue;
            }

            // Link to MediaFile (by hash) using ORM
            if (!isset($mediaByHash[$currentHash])) {
                /** @var MediaFile|null $media */
                $media = $mediaRepo->findOneBy(['hash' => $currentHash]);
                if ($media === null) {
                    $media = new MediaFile();
                    $media->setHash($currentHash);
                    $media->setMediaType(MediaTypeEnum::UNDEFINED); // or infer from MIME later
                    $media->setHasThumbnail(false);
                    $this->em->persist($media);
                }

                $mediaByHash[$currentHash] = $media;
            }

            $media = $mediaByHash[$currentHash];
            $file->setMediaFile($media);

            // Sync path based tags for this MediaFile
            $this->syncPathTagsForMedia(
                $media,
                $baseDir,
                $fullPath,
                $io,
                $tagRepo,
                $tagByName
            );
        }
    }

    private function fromFileMTime(int $mtime): DateTime
    {
        $dt = (new DateTime('@' . $mtime))
            ->setTimezone(new DateTimeZone('UTC'));

        return $this->truncateToSecondUtc($dt);
    }

    private function truncateToSecondUtc(DateTime $dt): DateTime
    {
        return new DateTime(
            $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            new DateTimeZone('UTC')
        );
    }

    private function computeFileHash(string $path, SymfonyStyle $io): ?string
    {
        $hash = @hash_file('sha256', $path);
        if ($hash === false) {
            $io->writeln(sprintf(
                '<error>Error computing hash for file %s</error>',
                $path
            ));

            $this->logger->warning('Error computing file hash', ['path' => $path]);

            return null;
        }

        return $hash;
    }

    /**
     * Create/update tags based on directory structure and attach them to MediaFile.
     *
     * e.g. /root/funny/lighthearted/video.mp4 => tags "funny", "lighthearted"
     */
    private function syncPathTagsForMedia(
        MediaFile    $media,
        string       $baseDir,
        string       $fullPath,
        SymfonyStyle $io,
                     $tagRepo,
        array        &$tagByName
    ): void
    {
        $rel = Path::makeRelative($fullPath, $baseDir);

        // Outside root?
        if (str_starts_with($rel, '..')) {
            return;
        }

        $dir = Path::getDirectory($rel);
        if ($dir === '' || $dir === '.' || $dir === DIRECTORY_SEPARATOR) {
            return;
        }

        $segments = preg_split('~[/\\\\]+~', $dir, -1, PREG_SPLIT_NO_EMPTY);
        if (!$segments) {
            return;
        }

        foreach ($segments as $seg) {
            $name = trim($seg);
            if ($name === '' || $name === '.') {
                continue;
            }

            try {
                if (!isset($tagByName[$name])) {
                    /** @var Tag|null $tag */
                    $tag = $tagRepo->findOneBy(['name' => $name]);
                    if ($tag === null) {
                        $tag = new Tag();
                        $tag->setName($name);
                        // path-derived tags start unmanaged (FALSE),
                        // if you later promote them, you can set isManaged=TRUE via UI.
                        $tag->setIsManaged(false);
                        $this->em->persist($tag);
                    }

                    $tagByName[$name] = $tag;
                }

                $tag = $tagByName[$name];

                // Attach if not already present
                $media->addTag($tag);
            } catch (Throwable $e) {
                $io->writeln(sprintf(
                    '<comment>[tags]</comment> error syncing tag "%s" for %s: %s',
                    $name,
                    $fullPath,
                    $e->getMessage()
                ));
                $this->logger->warning('Error syncing path tag', [
                    'tag' => $name,
                    'path' => $fullPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Mark all files that were not seen this run as deleted.
     * (keeping data for potential restore / history)
     */
    private function cleanupDeletedFiles(DateTime $scanTimestamp): void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->update(FilesystemFile::class, 'f')
            ->set('f.isDeleted', ':deleted')
            ->where('f.lastSeen != :ts')
            ->setParameter('deleted', true)
            ->setParameter('ts', $scanTimestamp);

        $qb->getQuery()->execute();
    }

    /**
     * Delete only unmanaged tags that are not used by any media_file
     */
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
                    'name' => $name,
                    'count' => $count,
                ]);
            }

            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
