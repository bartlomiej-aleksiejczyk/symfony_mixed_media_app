<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'filesystem_file',
    indexes: [
        new ORM\Index(name: 'idx_files_hash_size', columns: ['hash', 'size'])
    ]
)]
class FilesystemFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // full path on filesystem, must be unique
    #[ORM\Column(type: 'string', length: 4096, unique: true)]
    private string $path;

    // content hash (e.g. sha256/sha1/md5)
    #[ORM\Column(type: 'string', length: 128)]
    private string $hash;

    // file size in bytes; bigint -> Doctrine maps to string by default
    #[ORM\Column(type: 'bigint')]
    private string $size;

    // timestamp of last scan this file was seen in
    #[ORM\Column(name: 'last_seen', type: Types::DATETIMETZ_MUTABLE)]
    private \DateTime $lastSeen;

    public function __construct(string $path, string $hash, int $size, \DateTime $scanTime)
    {
        $this->path = $path;
        $this->hash = $hash;
        $this->size = (string) $size;
        $this->lastSeen = $scanTime;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function setHash(string $hash): self
    {
        $this->hash = $hash;

        return $this;
    }

    public function getSize(): int
    {
        return (int) $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = (string) $size;

        return $this;
    }

    public function getLastSeen(): \DateTime
    {
        return $this->lastSeen;
    }

    public function setLastSeen(\DateTime $lastSeen): self
    {
        $this->lastSeen = $lastSeen;

        return $this;
    }

    /**
     * Helper for the scanner: update on a scan hit.
     */
    public function touchOnScan(string $hash, int $size, \DateTime $scanTime): void
    {
        $this->hash = $hash;
        $this->size = (string) $size;
        $this->lastSeen = $scanTime;
    }
}
