<?php

namespace App\Entity;

use App\Repository\FacetValueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FacetValueRepository::class)]
class FacetValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $value = null;

    #[ORM\ManyToOne(inversedBy: 'facetValues')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Facet $facet = null;

    /**
     * @var Collection<int, MediaFile>
     */
    #[ORM\ManyToMany(targetEntity: MediaFile::class, mappedBy: 'facetValues')]
    private Collection $mediaFiles;

    public function __construct()
    {
        $this->mediaFiles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getFacet(): ?Facet
    {
        return $this->facet;
    }

    public function setFacet(?Facet $facet): static
    {
        $this->facet = $facet;

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
            $mediaFile->addFacetValue($this);
        }

        return $this;
    }

    public function removeMediaFile(MediaFile $mediaFile): static
    {
        if ($this->mediaFiles->removeElement($mediaFile)) {
            $mediaFile->removeFacetValue($this);
        }

        return $this;
    }
}
