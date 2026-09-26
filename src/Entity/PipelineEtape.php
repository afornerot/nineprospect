<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Étape d'un pipeline (position + libellé). Les statuts possibles sont
 * globaux (enum PipelineStatut) et identiques pour toutes les étapes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pipeline_etape')]
#[ORM\UniqueConstraint(name: 'uniq_pipeline_etape_ordre', columns: ['pipeline_id', 'ordre'])]
class PipelineEtape
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pipeline::class, inversedBy: 'etapes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Pipeline $pipeline = null;

    #[ORM\Column(length: 100)]
    private ?string $nom = null;

    #[ORM\Column]
    private int $ordre = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPipeline(): ?Pipeline
    {
        return $this->pipeline;
    }

    public function setPipeline(?Pipeline $pipeline): static
    {
        $this->pipeline = $pipeline;

        return $this;
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

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->nom;
    }
}
