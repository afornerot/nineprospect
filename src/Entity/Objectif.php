<?php

namespace App\Entity;

use App\Repository\ObjectifRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ObjectifRepository::class)]
#[ORM\Table(name: 'objectif')]
class Objectif
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $startDate = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $endDate = null;

    /** TYPE_OBJECTIF : prospect, rdv, proposition, commande, montant, heure */
    #[ORM\Column(type: 'string', length: 50)]
    private string $product = '';

    #[ORM\Column(type: 'integer')]
    private int $targetQuantity = 0;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->startDate;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->endDate;
    }

    public function getProduct(): string
    {
        return $this->product;
    }

    public function getTargetQuantity(): int
    {
        return $this->targetQuantity;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setStartDate(\DateTimeInterface $date): static
    {
        $this->startDate = $date;

        return $this;
    }

    public function setEndDate(\DateTimeInterface $date): static
    {
        $this->endDate = $date;

        return $this;
    }

    public function setProduct(string $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function setTargetQuantity(int $qty): static
    {
        $this->targetQuantity = $qty;

        return $this;
    }

    public function setDescription(?string $desc): static
    {
        $this->description = $desc;

        return $this;
    }
}
