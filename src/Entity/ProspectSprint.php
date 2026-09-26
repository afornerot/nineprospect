<?php

namespace App\Entity;

use App\Enum\PipelineStatut;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Présence d'un prospect dans une vague de traitement (0..n vagues par prospect).
 * Le pipeline commercial (étapes + statuts) est porté par la vague ; le lien
 * ne stocke que les valeurs saisies, étape par étape.
 */
#[ORM\Entity]
#[ORM\Table(name: 'prospect_sprint')]
#[ORM\UniqueConstraint(name: 'uniq_prospect_sprint', columns: ['prospect_id', 'sprint_id'])]
class ProspectSprint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Prospect::class, inversedBy: 'sprints')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Prospect $prospect = null;

    #[ORM\ManyToOne(targetEntity: Sprint::class, inversedBy: 'liens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Sprint $sprint = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $dateAjout = null;

    /**
     * Valeurs du pipeline saisies pour cette vague (les étapes sans ligne
     * restent « non démarré »).
     *
     * @var Collection<int, PipelineValeur>
     */
    #[ORM\OneToMany(targetEntity: PipelineValeur::class, mappedBy: 'prospectSprint', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $valeurs;

    public function __construct()
    {
        $this->dateAjout = new \DateTime();
        $this->valeurs = new ArrayCollection();
    }

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

    public function getSprint(): ?Sprint
    {
        return $this->sprint;
    }

    public function setSprint(?Sprint $sprint): static
    {
        $this->sprint = $sprint;

        return $this;
    }

    public function getDateAjout(): ?\DateTime
    {
        return $this->dateAjout;
    }

    public function setDateAjout(?\DateTime $dateAjout): static
    {
        $this->dateAjout = $dateAjout;

        return $this;
    }

    /**
     * @return Collection<int, PipelineValeur>
     */
    public function getValeurs(): Collection
    {
        return $this->valeurs;
    }

    public function valeurPour(PipelineEtape $etape): ?PipelineValeur
    {
        foreach ($this->valeurs as $valeur) {
            if ($valeur->getEtape() === $etape) {
                return $valeur;
            }
            if (null !== $valeur->getEtape() && null !== $etape->getId() && $valeur->getEtape()->getId() === $etape->getId()) {
                return $valeur;
            }
        }

        return null;
    }

    public function statutPour(PipelineEtape $etape): int
    {
        return $this->valeurPour($etape)?->getStatut() ?? PipelineStatut::NON_DEMARRE;
    }

    public function datePour(PipelineEtape $etape): ?\DateTime
    {
        return $this->valeurPour($etape)?->getDate();
    }

    /**
     * Enregistre la valeur d'une étape ; retire la ligne si la valeur revient
     * au défaut (« non démarré » sans date) pour ne stocker que l'utile.
     */
    public function definirValeur(PipelineEtape $etape, int $statut, ?\DateTime $date): static
    {
        $valeur = $this->valeurPour($etape);
        $auDefaut = PipelineStatut::NON_DEMARRE === $statut && null === $date;

        if (null === $valeur) {
            if (!$auDefaut) {
                $valeur = new PipelineValeur();
                $valeur->setEtape($etape);
                $valeur->setProspectSprint($this);
                $valeur->setStatut($statut);
                $valeur->setDate($date);
                $this->valeurs->add($valeur);
            }

            return $this;
        }

        if ($auDefaut) {
            $this->valeurs->removeElement($valeur);

            return $this;
        }

        $valeur->setStatut($statut)->setDate($date);

        return $this;
    }

    /**
     * Ne change que le statut d'une étape (date conservée). Crée ou supprime
     * la PipelineValeur si elle passe à NON_DEMARRE.
     */
    /**
     * Met à jour le statut (et la date du jour) d'une étape via le cycle
     * de la liste prospects. Comportement :
     *  - statut = NON_DEMARRE : la PipelineValeur (et donc sa date) est supprimée
     *  - autre statut : la PipelineValeur est créée si absente (date = today)
     *    ou mise à jour avec date = today (la date est posée à chaque clic,
     *    écrasant la précédente — le clic est une action datée).
     */
    public function definirStatut(PipelineEtape $etape, int $statut): static
    {
        $valeur = $this->valeurPour($etape);
        if (PipelineStatut::NON_DEMARRE === $statut) {
            if (null !== $valeur) {
                $this->valeurs->removeElement($valeur);
            }

            return $this;
        }

        if (null === $valeur) {
            $valeur = new PipelineValeur();
            $valeur->setEtape($etape);
            $valeur->setProspectSprint($this);
            $valeur->setStatut($statut);
            $this->valeurs->add($valeur);
        } else {
            $valeur->setStatut($statut);
        }
        $valeur->setDate(new \DateTime());

        return $this;
    }
}
