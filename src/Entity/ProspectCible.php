<?php

namespace App\Entity;

use App\Repository\ProspectCibleRepository;
use Doctrine\DBAL\Types\Types;
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

    /**
     * Dernier changement de `qualifie` (non / oui / hors cible) : date et
     * auteur. Renseignés par App\EventListener\QualifieModificationListener
     * à chaque modification effective de la valeur (un enregistrement sans
     * changement de valeur ne les touche pas).
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $qualifieModifieAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'qualifie_modifie_par_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $qualifieModifiePar = null;

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

    /**
     * Date du dernier changement effectif de `qualifie` (null si la valeur
     * n'a jamais changé depuis la création du lien).
     */
    public function getQualifieModifieAt(): ?\DateTime
    {
        return $this->qualifieModifieAt;
    }

    public function setQualifieModifieAt(?\DateTime $qualifieModifieAt): static
    {
        $this->qualifieModifieAt = $qualifieModifieAt;

        return $this;
    }

    /**
     * Auteur du dernier changement effectif de `qualifie` (null pour un
     * changement système : import, formulaire public, commande).
     */
    public function getQualifieModifiePar(): ?User
    {
        return $this->qualifieModifiePar;
    }

    public function setQualifieModifiePar(?User $qualifieModifiePar): static
    {
        $this->qualifieModifiePar = $qualifieModifiePar;

        return $this;
    }
}
