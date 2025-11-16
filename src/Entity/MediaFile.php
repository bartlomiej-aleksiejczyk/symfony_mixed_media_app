<?php

namespace App\Entity;

use App\Repository\MediaFileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MediaFileRepository::class)]
class MediaFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mediaName = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $hash = null;

    #[ORM\Column(enumType: MediaTypeEnum::class )]
    private ?MediaTypeEnum $mediaType = null;

    /**
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'mediaFiles')]
    private Collection $tags;

    /**
     * @var Collection<int, FacetValue>
     */
    #[ORM\ManyToMany(targetEntity: FacetValue::class, inversedBy: 'mediaFiles')]
    private Collection $facetValues;

    #[ORM\Column]
    private ?bool $hasThumbnail = null;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $this->facetValues = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMediaName(): ?string
    {
        return $this->mediaName;
    }

    public function setMediaName(?string $mediaName): static
    {
        $this->mediaName = $mediaName;

        return $this;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(string $hash): static
    {
        $this->hash = $hash;

        return $this;
    }

    public function getMediaType(): ?MediaTypeEnum
    {
        return $this->mediaType;
    }

    public function setMediaType(MediaTypeEnum $mediaType): static
    {
        $this->mediaType = $mediaType;

        return $this;
    }

    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    /**
     * @return Collection<int, FacetValue>
     */
    public function getFacetValues(): Collection
    {
        return $this->facetValues;
    }

    public function addFacetValue(FacetValue $facetValue): static
    {
        if (!$this->facetValues->contains($facetValue)) {
            $this->facetValues->add($facetValue);
        }

        return $this;
    }

    public function removeFacetValue(FacetValue $facetValue): static
    {
        $this->facetValues->removeElement($facetValue);

        return $this;
    }

    public function hasThumbnail(): ?bool
    {
        return $this->hasThumbnail;
    }

    public function setHasThumbnail(bool $hasThumbnail): static
    {
        $this->hasThumbnail = $hasThumbnail;

        return $this;
    }
}
