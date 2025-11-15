<?php

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TagRepository::class)]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $name = null;

    /**
     * @var Collection<int, MediaFile>
     */
    #[ORM\ManyToMany(targetEntity: MediaFile::class, mappedBy: 'tags')]
    private Collection $mediaFiles;

    #[ORM\Column]
    private ?bool $isManaged = null;

    public function __construct()
    {
        $this->mediaFiles = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Collection<int, MediaFile>
     */
    public function getMediaFiles(): Collection
    {
        return $this->mediaFiles;
    }

    public function addMediaFile(MediaFile $mediaFile): static
    {
        if (!$this->mediaFiles->contains($mediaFile)) {
            $this->mediaFiles->add($mediaFile);
            $mediaFile->addTag($this);
        }

        return $this;
    }

    public function removeMediaFile(MediaFile $mediaFile): static
    {
        if ($this->mediaFiles->removeElement($mediaFile)) {
            $mediaFile->removeTag($this);
        }

        return $this;
    }

    public function isManaged(): ?bool
    {
        return $this->isManaged;
    }

    public function setIsManaged(bool $isManaged): static
    {
        $this->isManaged = $isManaged;

        return $this;
    }

}
