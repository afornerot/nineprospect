<?php

namespace App\Entity;

use App\Repository\PipelineRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pipeline commercial : liste ordonnée d'étapes (visio, démo, devis, signature…).
 * Un pipeline est affecté à une vague de traitement ; chaque prospect n'en
 * porte donc que celui de sa vague courante.
 */
#[ORM\Entity(repositoryClass: PipelineRepository::class)]
#[ORM\Table(name: 'pipeline')]
class Pipeline
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $nom = null;

    #[ORM\Column]
    private bool $parDefaut = false;

    /**
     * Étapes du pipeline, dans l'ordre de traitement.
     *
     * @var Collection<int, PipelineEtape>
     */
    #[ORM\OneToMany(targetEntity: PipelineEtape::class, mappedBy: 'pipeline', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $etapes;

    public function __construct()
    {
        $this->etapes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function isParDefaut(): bool
    {
        return $this->parDefaut;
    }

    public function setParDefaut(bool $parDefaut): static
    {
        $this->parDefaut = $parDefaut;

        return $this;
    }

    /**
     * @return Collection<int, PipelineEtape>
     */
    public function getEtapes(): Collection
    {
        return $this->etapes;
    }

    public function addEtape(PipelineEtape $etape): static
    {
        if (!$this->etapes->contains($etape)) {
            $this->etapes->add($etape);
            $etape->setPipeline($this);
        }

        return $this;
    }

    public function removeEtape(PipelineEtape $etape): static
    {
        if ($this->etapes->removeElement($etape) && $etape->getPipeline() === $this) {
            $etape->setPipeline(null);
        }

        return $this;
    }

    public function nextOrdre(): int
    {
        $max = 0;
        foreach ($this->etapes as $etape) {
            $max = max($max, (int) $etape->getOrdre());
        }

        return $max + 1;
    }

    public function __toString(): string
    {
        return (string) $this->nom;
    }
}
