<?php

namespace App\Entity;

use Symfony\Component\String\Slugger\AsciiSlugger;

use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Catégorie de prospect : taxonomie libre pour classer les Prospects.
 * Un Prospect peut avoir 0..n catégories (relation ManyToMany).
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Prospects qui portent cette catégorie.
     *
     * @var Collection<int, Prospect>
     */
    #[ORM\ManyToMany(targetEntity: Prospect::class, mappedBy: 'categories')]
    private Collection $prospects;

    public function __construct()
    {
        $this->prospects = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        if (null === $this->slug || '' === $this->slug) {
            $this->slug = $this->generateSlug($nom);
        }

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, Prospect>
     */
    public function getProspects(): Collection
    {
        return $this->prospects;
    }

    private function generateSlug(string $text): string
    {
        // AsciiSlugger translitere les accents (É -> e) avant de reduire
        // aux caracteres surs. Sans lui, '[^a-z0-9]+' supprimait la lettre
        // accentuee au lieu de la remplacer : "Mon Éducation Nationale"
        // devenait "mon-ducation-nationale".
        $slug = (new AsciiSlugger())->slug($text, '-')->lower()->toString();

        return '' === $slug ? 'category' : $slug;
    }

    public function __toString(): string
    {
        return $this->nom ?? 'Category '.$this->id;
    }
}
