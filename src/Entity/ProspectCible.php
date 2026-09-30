<?php

namespace App\Entity;

use App\Repository\ProspectCibleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProspectCibleRepository::class)]
class ProspectCible
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Prospect::class, inversedBy: 'prospectCibles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Prospect $prospect = null;

    #[ORM\ManyToOne(targetEntity: Cible::class, inversedBy: 'prospectCibles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Cible $cible = null;

    #[ORM\Column(nullable: true)]
    private ?bool $qualifie = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $qualification = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProspect(): ?Prospect
    {
        return $this->prospect;
    }

    public function setProspect(?Prospect $prospect): static
    {
        $this->prospect = $prospect;

        return $this;
    }

    public function getCible(): ?Cible
    {
        return $this->cible;
    }

    public function setCible(?Cible $cible): static
    {
        $this->cible = $cible;

        return $this;
    }

    public function getQualifie(): ?bool
    {
        return $this->qualifie;
    }

    public function setQualifie(?bool $qualifie): static
    {
        $this->qualifie = $qualifie;

        return $this;
    }

    public function getQualification(): ?string
    {
        return $this->qualification;
    }

    public function setQualification(?string $qualification): static
    {
        $this->qualification = $qualification;

        return $this;
    }
}
