<?php

namespace App\Entity;

use App\Repository\ActionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Action de prospection : soit à faire (planifiée), soit réalisée.
 *
 * Une action est rattachée à un prospect (et éventuellement un contact).
 * À la clôture d'une action, le formulaire peut proposer de planifier
 * une nouvelle action (indépendante : plusieurs actions peuvent coexister
 * sur un même prospect sans lien hiérarchique).
 *
 * Statut dérivé :
 *  - réalisée si {@see $dateRealisee} non null
 *  - planifiée sinon
 *
 * Onglets de la liste :
 *  - En retard = planifiée & datePrevue <= aujourd'hui
 *  - À venir  = planifiée & (datePrevue > aujourd'hui ou null)
 *  - Réalisées = dateRealisee != null
 */
#[ORM\Entity(repositoryClass: ActionRepository::class)]
class Action
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Prospect::class, inversedBy: 'actions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Prospect $prospect = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $contact = null;

    /**
     * Date à laquelle l'action doit être faite (échéance d'une planifiée).
     * Conservée pour les actions déjà réalisées comme info de rappel initial.
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true, name: 'date_relance')]
    private ?\DateTime $datePrevue = null;

    /**
     * Date effective à laquelle l'action a été réalisée. Statut "réalisée" si non null.
     * Colonne SQL : `date` (conservée pour ne pas perdre les données existantes).
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true, name: 'date')]
    private ?\DateTime $dateRealisee = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $resultat = null;

    /**
     * Texte libre décrivant le type d'action (Appel, Mail, Visio…).
     * Pas de lien vers {@see ActionTypeDefaut} : ce dernier sert uniquement
     * de suggestion à la création, modifiable par l'utilisateur.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $typeAction = null;

    /**
     * Utilisateur initialement affecté à l'action (par défaut : créateur).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'a_fait_par_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $aFairePar = null;

    /**
     * Utilisateur ayant clôturé l'action (par défaut : clôtureur).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'realise_par_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $realisePar = null;

    #[ORM\Column]
    private ?\DateTime $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
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

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    public function getDatePrevue(): ?\DateTime
    {
        return $this->datePrevue;
    }

    public function setDatePrevue(?\DateTime $datePrevue): static
    {
        $this->datePrevue = $datePrevue;

        return $this;
    }

    public function getDateRealisee(): ?\DateTime
    {
        return $this->dateRealisee;
    }

    public function setDateRealisee(?\DateTime $dateRealisee): static
    {
        $this->dateRealisee = $dateRealisee;

        return $this;
    }

    public function getResultat(): ?string
    {
        return $this->resultat;
    }

    public function setResultat(?string $resultat): static
    {
        $this->resultat = $resultat;

        return $this;
    }

    public function getTypeAction(): ?string
    {
        return $this->typeAction;
    }

    public function setTypeAction(?string $typeAction): static
    {
        $this->typeAction = $typeAction;

        return $this;
    }

    public function getAFairePar(): ?User
    {
        return $this->aFairePar;
    }

    public function setAFairePar(?User $aFairePar): static
    {
        $this->aFairePar = $aFairePar;

        return $this;
    }

    public function getRealisePar(): ?User
    {
        return $this->realisePar;
    }

    public function setRealisePar(?User $realisePar): static
    {
        $this->realisePar = $realisePar;

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function estRealisee(): bool
    {
        return null !== $this->dateRealisee;
    }

    public function estPlanifiee(): bool
    {
        return null === $this->dateRealisee;
    }

    public function estEnRetard(?\DateTime $jour = null): bool
    {
        if (!$this->estPlanifiee() || null === $this->datePrevue) {
            return false;
        }

        $jour ??= new \DateTime('today');

        return $this->datePrevue <= $jour;
    }

    public function __toString(): string
    {
        if ($this->dateRealisee instanceof \DateTime) {
            return 'Action réalisée le '.$this->dateRealisee->format('d/m/Y');
        }
        if ($this->datePrevue instanceof \DateTime) {
            return 'Action prévue le '.$this->datePrevue->format('d/m/Y');
        }

        return 'Action #'.$this->id;
    }
}
