<?php

namespace App\Entity;

use App\Repository\FacetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FacetRepository::class)]
class Facet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    /**
     * @var Collection<int, FacetValue>
     */
    #[ORM\OneToMany(targetEntity: FacetValue::class, mappedBy: 'facet', orphanRemoval: true)]
    private Collection $facetValues;

    public function __construct()
    {
        $this->facetValues = new ArrayCollection();
    }

    public function getId(): ?int
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
            $facetValue->setFacet($this);
        }

        return $this;
    }

    public function removeFacetValue(FacetValue $facetValue): static
    {
        if ($this->facetValues->removeElement($facetValue)) {
            // set the owning side to null (unless already changed)
            if ($facetValue->getFacet() === $this) {
                $facetValue->setFacet(null);
            }
        }

        return $this;
    }
}
