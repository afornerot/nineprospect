<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vague de traitement interne (ancien champ "Sprint" de la feuille).
 * Orthogonale à la campagne publicitaire : une campagne couvre plusieurs vagues.
 */
#[ORM\Entity]
class Sprint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private ?int $numero = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $libelle = null;

    /**
     * Pipeline affecté à cette vague (étapes + statuts de la vague).
     * Nullable en base, mais toujours renseigné par le formulaire, l'import
     * et les fixtures (pipeline par défaut).
     */
    #[ORM\ManyToOne(targetEntity: Pipeline::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Pipeline $pipeline = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $dateDebut = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $dateFin = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * Présences des prospects dans cette vague (chacune porte son pipeline).
     *
     * @var Collection<int, ProspectSprint>
     */
    #[ORM\OneToMany(targetEntity: ProspectSprint::class, mappedBy: 'sprint', cascade: ['remove'], orphanRemoval: true)]
    private Collection $liens;

    public function __construct()
    {
        $this->liens = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?int
    {
        return $this->numero;
    }

    public function setNumero(int $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): static
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getPipeline(): ?Pipeline
    {
        return $this->pipeline;
    }

    /**
     * Affecte le pipeline de la vague. Aucun effet de bord : les valeurs
     * saisies restent en base et deviennent incohérentes si elles portent sur
     * des étapes de l'ancien pipeline. Le contrôleur demande confirmation
     * avant de les purger (`Sprint::purgerValeursHorsPipeline()`).
     */
    public function setPipeline(?Pipeline $pipeline): static
    {
        $this->pipeline = $pipeline;

        return $this;
    }

    /**
     * Nombre de valeurs saisies sur des étapes qui n'appartiennent pas au
     * pipeline donné (par défaut celui de la vague). Passer le pipeline cible
     * mesure ce qu'une bascule ferait perdre, avant de l'appliquer.
     */
    public function compterValeursHorsPipeline(?Pipeline $pipeline = null): int
    {
        $total = 0;
        foreach ($this->liens as $lien) {
            $total += $lien->compterValeursHorsPipeline($pipeline);
        }

        return $total;
    }

    /**
     * Supprime les valeurs saisies sur des étapes hors du pipeline donné (par
     * défaut celui de la vague). Opération destructive, à n'appeler qu'après
     * confirmation explicite.
     */
    public function purgerValeursHorsPipeline(?Pipeline $pipeline = null): int
    {
        $total = 0;
        foreach ($this->liens as $lien) {
            foreach ($lien->valeursHorsPipeline($pipeline) as $valeur) {
                $lien->getValeurs()->removeElement($valeur);
                ++$total;
            }
        }

        return $total;
    }

    public function getDateDebut(): ?\DateTime
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTime $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTime
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTime $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    /**
     * @return Collection<int, ProspectSprint>
     */
    public function getLiens(): Collection
    {
        return $this->liens;
    }

    public function __toString(): string
    {
        if (null === $this->libelle || '' === $this->libelle) {
            return 'Vague '.$this->numero;
        }

        return 'Vague '.$this->numero.' — '.$this->libelle;
    }
}
