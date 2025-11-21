<?php

namespace App\Service;

use App\Entity\FilesystemFile;
use App\Entity\MediaFile;
use App\Entity\MediaTypeEnum;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

class ThumbnailGeneratorService
{
    /**
     * Define max-widths for each thumbnail size.
     */
    private const SIZES = [
        'small' => 160,
        'medium' => 480,
        'large' => 1024,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
        #[Autowire('%thumbnails_directory%')]
        private readonly string                 $thumbnailsDir,
    )
    {
    }

    /**
     * Main entry point for the command.
     *
     * @param SymfonyStyle $io
     * @param bool $force If true, regenerate even if already hasThumbnail / files exist
     */
    public function generateThumbnails(SymfonyStyle $io, bool $force = false): void
    {
        $io->writeln('<info>[thumbs]</info> Starting thumbnail generation');

        $repo = $this->em->getRepository(MediaFile::class);

        $qb = $repo->createQueryBuilder('m')
            ->leftJoin('m.filesystemFiles', 'f')
            ->addSelect('f')
            ->where('m.mediaType = :imageType')
            ->setParameter('imageType', MediaTypeEnum::IMAGE);

        if (!$force) {
            $qb->andWhere('(m.hasThumbnail = false OR m.hasThumbnail IS NULL)');
        }

        $query = $qb->getQuery();
        $iterable = $query->toIterable();

        $processed = 0;
        $success = 0;
        $skipped = 0;

        /** @var MediaFile $media */
        foreach ($iterable as $media) {
            ++$processed;

            $filesystemFile = $this->chooseSourceFile($media);
            if (!$filesystemFile) {
                $skipped++;
                $io->writeln(sprintf(
                    '<comment>[thumbs]</comment> Skipping media #%d (hash=%s), no active filesystem file',
                    $media->getId() ?? 0,
                    $media->getHash() ?? 'NULL'
                ));
                continue;
            }

            if ($this->generateForMediaAndFile($media, $filesystemFile, $io, $force)) {
                $media->setHasThumbnail(true);
                $success++;
            } else {
                $io->writeln(sprintf(
                    '<error>[thumbs]</error> Failed to generate thumbnail for media #%d (hash=%s)',
                    $media->getId() ?? 0,
                    $media->getHash() ?? 'NULL'
                ));
            }

            // Optional periodic flush to keep memory in check
            if (($processed % 50) === 0) {
                $this->em->flush();
                $this->em->clear(); // If you clear, be aware this detaches entities.
            }
        }

        // Final flush
        $this->em->flush();

        $io->success(sprintf(
            '[thumbs] Done. Processed: %d, success: %d, skipped: %d',
            $processed,
            $success,
            $skipped
        ));
    }

    /**
     * Pick a FilesystemFile to use as thumbnail source.
     * Simple strategy: first non-deleted file.
     */
    private function chooseSourceFile(MediaFile $media): ?FilesystemFile
    {
        $files = $media->getFilesystemFiles();

        /** @var FilesystemFile $file */
        foreach ($files as $file) {
            if ($file->isDeleted() === true) {
                continue;
            }

            return $file;
        }

        return null;
    }

    /**
     * Generate small/medium/large thumbnails for given media + file.
     */
    private function generateForMediaAndFile(
        MediaFile      $media,
        FilesystemFile $file,
        SymfonyStyle   $io,
        bool           $force
    ): bool
    {
        $sourcePath = $file->getPath();

        if (!is_file($sourcePath)) {
            $io->writeln(sprintf(
                '<comment>[thumbs]</comment> Source file missing: %s',
                $sourcePath
            ));
            $this->logger->warning('Thumbnail source file not found', [
                'path' => $sourcePath,
            ]);

            return false;
        }

        $hash = $media->getHash();
        if (!$hash) {
            // Fallback if hash is not set for some reason
            $hash = sha1($sourcePath);
        }

        $overallSuccess = true;

        foreach (self::SIZES as $label => $maxWidth) {
            $destDir = $this->thumbnailsDir . DIRECTORY_SEPARATOR . $label;
            if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                $io->writeln(sprintf(
                    '<error>[thumbs]</error> Cannot create directory: %s',
                    $destDir
                ));
                $this->logger->error('Failed to create thumbnail directory', [
                    'dir' => $destDir,
                ]);
                $overallSuccess = false;
                continue;
            }

            $destPath = $destDir . DIRECTORY_SEPARATOR . $hash . '.jpg';

            if (!$force && is_file($destPath)) {
                // already exists
                continue;
            }

            if (!$this->generateOneThumbnail($sourcePath, $destPath, $maxWidth)) {
                $overallSuccess = false;
            }
        }

        return $overallSuccess;
    }

    /**
     * Resize source image to fit within maxWidth (keeping aspect ratio) and write JPEG.
     */
    private function generateOneThumbnail(
        string $sourcePath,
        string $destPath,
        int    $maxWidth
    ): bool
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            $this->logger->warning('getimagesize failed for thumbnail', [
                'path' => $sourcePath,
            ]);
            return false;
        }

        [$width, $height, $type] = $info;

        if ($width <= 0 || $height <= 0) {
            $this->logger->warning('Invalid image dimensions for thumbnail', [
                'path' => $sourcePath,
                'width' => $width,
                'height' => $height,
            ]);
            return false;
        }

        // Compute target size preserving aspect ratio
        $scale = min(1.0, $maxWidth / $width);
        $targetWidth = (int)max(1, round($width * $scale));
        $targetHeight = (int)max(1, round($height * $scale));

        $src = $this->createImageFromType($sourcePath, $type);
        if (!$src) {
            return false;
        }

        $dst = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$dst) {
            imagedestroy($src);
            return false;
        }

        // Preserve transparency for PNG/GIF
        if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
            imagecolortransparent($dst, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        if (!imagecopyresampled(
            $dst,
            $src,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        )) {
            imagedestroy($src);
            imagedestroy($dst);
            return false;
        }

        // Always store as JPEG to keep it simple; adjust if you want WebP/PNG.
        $ok = imagejpeg($dst, $destPath, 85);

        imagedestroy($src);
        imagedestroy($dst);

        return $ok;
    }

    /**
     * Create a GD image resource depending on image type.
     */
    private function createImageFromType(string $path, int $type)
    {
        try {
            switch ($type) {
                case IMAGETYPE_JPEG:
                    return imagecreatefromjpeg($path);
                case IMAGETYPE_PNG:
                    return imagecreatefrompng($path);
                case IMAGETYPE_GIF:
                    return imagecreatefromgif($path);
                case IMAGETYPE_WEBP:
                    if (function_exists('imagecreatefromwebp')) {
                        return imagecreatefromwebp($path);
                    }
                    $this->logger->warning('WEBP not supported by GD for thumbnail', ['path' => $path]);
                    return null;
                default:
                    $this->logger->warning('Unsupported image type for thumbnail', [
                        'path' => $path,
                        'type' => $type,
                    ]);
                    return null;
            }
        } catch (Throwable $e) {
            $this->logger->error('Error creating image resource for thumbnail', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
