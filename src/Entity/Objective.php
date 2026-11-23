<?php

namespace App\Entity;

use App\Repository\ObjectiveRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Objectif commercial sur une tranche de dates et un produit : nombre de
 * prospects qualifiés, de RDV, de propositions, de commandes, montant de
 * commandes ou nombre de jours.
 */
#[ORM\Entity(repositoryClass: ObjectiveRepository::class)]
#[ORM\Table(name: 'objective')]
class Objective
{
    public const TYPE_PROSPECTS_QUALIFIES = 'prospects_qualifies';
    public const TYPE_RDV = 'rdv';
    public const TYPE_PROPOSITIONS = 'propositions';
    public const TYPE_COMMANDES = 'commandes';
    public const TYPE_MONTANT_COMMANDES = 'montant_commandes';
    public const TYPE_JOURS = 'jours';

    public const TYPES = [
        self::TYPE_PROSPECTS_QUALIFIES => 'Prospects qualifiés',
        self::TYPE_RDV => 'RDV',
        self::TYPE_PROPOSITIONS => 'Propositions',
        self::TYPE_COMMANDES => 'Commandes',
        self::TYPE_MONTANT_COMMANDES => 'Montant commandes',
        self::TYPE_JOURS => 'Jours',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $startDate = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $endDate = null;

    #[ORM\Column(length: 30)]
    private string $type = self::TYPE_PROSPECTS_QUALIFIES;

    /** Cible : un nombre, ou un montant en € pour MONTANT_COMMANDES. */
    #[ORM\Column(type: 'float')]
    private float $target = 0;

    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Produit $produit = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeInterface $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeInterface $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getTarget(): float
    {
        return $this->target;
    }

    public function setTarget(float $target): static
    {
        $this->target = $target;

        return $this;
    }

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): static
    {
        $this->produit = $produit;

        return $this;
    }

    /** Libellé du type ('Prospects qualifiés', 'RDV'...). */
    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
