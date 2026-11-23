<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Document commercial Dolibarr (devis, commande, facture) d'un prospect,
 * mémorisé localement : rafraîchi à l'ouverture de la fiche et par le cron
 * quotidien, ce qui permet de l'associer à un produit de nineprospect.
 *
 * Les libellés de statut sont figés au moment de la synchronisation (ils
 * reflètent l'état vu par la dernière synchro, pas un état vivant).
 */
#[ORM\Entity(repositoryClass: \App\Repository\ProspectDocumentRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_DOC_DOLIBARR', fields: ['type', 'dolibarrId'])]
class ProspectDocument
{
    public const TYPE_DEVIS = 'Devis';

    public const TYPE_COMMANDE = 'Commande';

    public const TYPE_FACTURE = 'Facture';

    /** Types associables à un produit (pas les factures). */
    public const TYPES_ASSOCIABLES = [self::TYPE_DEVIS, self::TYPE_COMMANDE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Prospect::class, inversedBy: 'documentsDolibarr')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Prospect $prospect = null;

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_DEVIS;

    /** rowid Dolibarr (propre à la table du type, d'où la clé avec `type`). */
    #[ORM\Column(length: 30)]
    private string $dolibarrId = '';

    #[ORM\Column(length: 100)]
    private string $ref = '';

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $dateDoc = null;

    /** Sous-type facture (Avoir, Acompte…), null sinon. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $typeLabel = null;

    #[ORM\Column(length: 50)]
    private string $statutLabel = '';

    #[ORM\Column(length: 20)]
    private string $statutCouleur = 'secondary';

    #[ORM\Column(nullable: true)]
    private ?float $totalHt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    /** Produit nineprospect associé à ce document (le cas échéant). */
    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Produit $produit = null;

    #[ORM\Column]
    private ?\DateTime $syncAt = null;

    public function __construct()
    {
        $this->syncAt = new \DateTime();
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getDolibarrId(): string
    {
        return $this->dolibarrId;
    }

    public function setDolibarrId(string $dolibarrId): static
    {
        $this->dolibarrId = $dolibarrId;

        return $this;
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    public function setRef(string $ref): static
    {
        $this->ref = $ref;

        return $this;
    }

    public function getDateDoc(): ?\DateTime
    {
        return $this->dateDoc;
    }

    public function setDateDoc(?\DateTime $dateDoc): static
    {
        $this->dateDoc = $dateDoc;

        return $this;
    }

    public function getTypeLabel(): ?string
    {
        return $this->typeLabel;
    }

    public function setTypeLabel(?string $typeLabel): static
    {
        $this->typeLabel = $typeLabel;

        return $this;
    }

    public function getStatutLabel(): string
    {
        return $this->statutLabel;
    }

    public function setStatutLabel(string $statutLabel): static
    {
        $this->statutLabel = $statutLabel;

        return $this;
    }

    public function getStatutCouleur(): string
    {
        return $this->statutCouleur;
    }

    public function setStatutCouleur(string $statutCouleur): static
    {
        $this->statutCouleur = $statutCouleur;

        return $this;
    }

    public function getTotalHt(): ?float
    {
        return $this->totalHt;
    }

    public function setTotalHt(?float $totalHt): static
    {
        $this->totalHt = $totalHt;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

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

    public function getSyncAt(): ?\DateTime
    {
        return $this->syncAt;
    }

    public function setSyncAt(\DateTime $syncAt): static
    {
        $this->syncAt = $syncAt;

        return $this;
    }
}
