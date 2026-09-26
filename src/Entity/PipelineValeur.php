<?php

namespace App\Entity;

use App\Enum\PipelineStatut;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Valeur du pipeline pour un prospect dans une vague donnée (statut + date).
 * Une ligne n'existe que si la valeur sort du défaut « non démarré » ; les
 * lectures sans ligne renvoient donc PipelineStatut::NON_DEMARRE.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pipeline_valeur')]
#[ORM\UniqueConstraint(name: 'uniq_pipeline_valeur', columns: ['prospect_sprint_id', 'etape_id'])]
class PipelineValeur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProspectSprint::class, inversedBy: 'valeurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ProspectSprint $prospectSprint = null;

    #[ORM\ManyToOne(targetEntity: PipelineEtape::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?PipelineEtape $etape = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $statut = PipelineStatut::NON_DEMARRE;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $date = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProspectSprint(): ?ProspectSprint
    {
        return $this->prospectSprint;
    }

    public function setProspectSprint(?ProspectSprint $prospectSprint): static
    {
        $this->prospectSprint = $prospectSprint;

        return $this;
    }

    public function getEtape(): ?PipelineEtape
    {
        return $this->etape;
    }

    public function setEtape(?PipelineEtape $etape): static
    {
        $this->etape = $etape;

        return $this;
    }

    public function getStatut(): int
    {
        return $this->statut;
    }

    public function setStatut(int $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDate(): ?\DateTime
    {
        return $this->date;
    }

    public function setDate(?\DateTime $date): static
    {
        $this->date = $date;

        return $this;
    }
}
