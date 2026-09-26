<?php

namespace App\Entity;

use App\Repository\ContactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Une personne physique rattachée à un prospect.
 * Un prospect en a plusieurs ; l'un d'eux est marqué principal.
 */
#[ORM\Entity(repositoryClass: ContactRepository::class)]
class Contact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Prospect::class, inversedBy: 'contacts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Prospect $prospect = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $prenom = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nom = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomComplet = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $telephoneBrut = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $poste = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $linkedinUrl = null;

    #[ORM\Column]
    private ?bool $estPrincipal = false;

    /**
     * Identifiant de lead publicitaire (clé d'upsert à l'import).
     */
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $leadId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $sourceCreatedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $adId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $formId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $formName = null;

    /**
     * Campagne publicitaire d'origine (provenance exacte du lead).
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $campaignExternalId = null;

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

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(?string $prenom): static
    {
        $this->prenom = $prenom;
        $this->regenererNomComplet();

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = (null !== $nom && '' !== trim($nom)) ? mb_strtoupper(trim($nom)) : null;
        $this->regenererNomComplet();

        return $this;
    }

    public function getNomComplet(): ?string
    {
        return $this->nomComplet;
    }

    public function setNomComplet(string $nomComplet): static
    {
        $this->nomComplet = $nomComplet;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getTelephoneBrut(): ?string
    {
        return $this->telephoneBrut;
    }

    public function setTelephoneBrut(?string $telephoneBrut): static
    {
        $this->telephoneBrut = null !== $telephoneBrut ? trim($telephoneBrut) : null;

        // Synchronise la version lisible (INTERNATIONAL) dès qu'un brut est posé.
        $this->telephone = $this->formater($this->telephoneBrut);

        return $this;
    }

    /**
     * Reformatte un numéro en INTERNATIONAL via libphonenumber.
     * Retourne null si la chaîne est vide ou ne peut être parsée.
     */
    private function formater(?string $brut): ?string
    {
        if (null === $brut || '' === trim($brut)) {
            return null;
        }

        try {
            $parsed = PhoneNumberUtil::getInstance()->parse($brut, 'FR');

            return PhoneNumberUtil::getInstance()->format($parsed, PhoneNumberFormat::INTERNATIONAL);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getPoste(): ?string
    {
        return $this->poste;
    }

    public function setPoste(?string $poste): static
    {
        $this->poste = $poste;

        return $this;
    }

    public function getLinkedinUrl(): ?string
    {
        return $this->linkedinUrl;
    }

    public function setLinkedinUrl(?string $linkedinUrl): static
    {
        $this->linkedinUrl = $linkedinUrl;

        return $this;
    }

    public function isEstPrincipal(): ?bool
    {
        return $this->estPrincipal;
    }

    public function setEstPrincipal(?bool $estPrincipal): static
    {
        $this->estPrincipal = $estPrincipal;

        return $this;
    }

    public function getLeadId(): ?string
    {
        return $this->leadId;
    }

    public function setLeadId(?string $leadId): static
    {
        $this->leadId = $leadId;

        return $this;
    }

    public function getSourceCreatedAt(): ?\DateTime
    {
        return $this->sourceCreatedAt;
    }

    public function setSourceCreatedAt(?\DateTime $sourceCreatedAt): static
    {
        $this->sourceCreatedAt = $sourceCreatedAt;

        return $this;
    }

    public function getAdId(): ?string
    {
        return $this->adId;
    }

    public function setAdId(?string $adId): static
    {
        $this->adId = $adId;

        return $this;
    }

    public function getFormId(): ?string
    {
        return $this->formId;
    }

    public function setFormId(?string $formId): static
    {
        $this->formId = $formId;

        return $this;
    }

    public function getFormName(): ?string
    {
        return $this->formName;
    }

    public function setFormName(?string $formName): static
    {
        $this->formName = $formName;

        return $this;
    }

    public function getCampaignExternalId(): ?string
    {
        return $this->campaignExternalId;
    }

    public function setCampaignExternalId(?string $campaignExternalId): static
    {
        $this->campaignExternalId = $campaignExternalId;

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function getDisplayName(): string
    {
        return '' !== trim((string) $this->nomComplet) ? (string) $this->nomComplet : (string) $this->nom;
    }

    /**
     * Recalcule le nom complet à partir du nom et du prénom.
     * Toujours appelé après {@see setNom()} et {@see setPrenom()} pour
     * conserver la cohérence lors des modifications (l'utilisateur peut
     * corriger l'un puis l'autre).
     */
    private function regenererNomComplet(): void
    {
        $nom = trim((string) $this->nom);
        $prenom = trim((string) $this->prenom);
        $this->nomComplet = trim($nom.' '.$prenom);
    }

    /**
     * Contrainte de validation : nom OU prénom doit être renseigné
     * (les deux peuvent être vides ensemble, mais pas simultanément).
     */
    #[Callback]
    public function validateNomOuPrenom(ExecutionContextInterface $context): void
    {
        $prenom = trim((string) $this->prenom);
        $nom = trim((string) $this->nom);
        if ('' === $prenom && '' === $nom) {
            $context->buildViolation('Renseignez au moins le nom ou le prénom.')
                ->atPath('nom')
                ->addViolation();
        }
    }
}
