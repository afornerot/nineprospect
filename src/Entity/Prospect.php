<?php

namespace App\Entity;

use App\Repository\ProspectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une entreprise (ou un particulier) à prospecter.
 * Regroupe un ou plusieurs contacts ; porte le suivi commercial
 * (campagne, vagues de traitement, qualification, affectation).
 * Le pipeline (visio, démo, devis, signature) vit dans ProspectSprint,
 * une ligne par vague : un prospect peut être repris dans 0..n vagues.
 */
#[ORM\Entity(repositoryClass: ProspectRepository::class)]
class Prospect
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    /**
     * Clé de groupement normalisée (dérivée du nom d'entreprise), unique.
     */
    #[ORM\Column(length: 255, unique: true)]
    private ?string $cleEntreprise = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $ville = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Departement $departement = null;

    #[ORM\Column(nullable: true)]
    private ?bool $bureauEtudeInterne = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $siren = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $siret = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $naf = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $rcsRm = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $numTva = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $idDolibarr = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $linkedinUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $pays = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    private ?string $longitude = null;

    #[ORM\Column(nullable: true)]
    private ?bool $contacte = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $datePremierContact = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $quali = null;

    #[ORM\Column(nullable: true)]
    private ?bool $qualifie = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(nullable: true)]
    private ?float $montantDevis = null;

    /**
     * Campagne d'acquisition publicitaire qui a généré ce prospect.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Campagne $campagne = null;

    /**
     * Vagues de traitement concernant ce prospect (0..n).
     *
     * @var Collection<int, ProspectSprint>
     */
    #[ORM\OneToMany(targetEntity: ProspectSprint::class, mappedBy: 'prospect', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $sprints;

    /**
     * @var Collection<int, Contact>
     */
    #[ORM\OneToMany(targetEntity: Contact::class, mappedBy: 'prospect', cascade: ['remove'], orphanRemoval: true)]
    private Collection $contacts;

    /**
     * @var Collection<int, Action>
     */
    #[ORM\OneToMany(targetEntity: Action::class, mappedBy: 'prospect', cascade: ['remove'], orphanRemoval: true)]
    private Collection $actions;

    /**
     * Commerciaux affectés (un prospect peut en avoir plusieurs).
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    private Collection $users;

    #[ORM\Column]
    private ?bool $anomalie = false;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $anomalieMotif = null;

    #[ORM\Column]
    private ?\DateTime $createdAt = null;

    public function __construct()
    {
        $this->contacts = new ArrayCollection();
        $this->actions = new ArrayCollection();
        $this->users = new ArrayCollection();
        $this->sprints = new ArrayCollection();
        $this->createdAt = new \DateTime();
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

        return $this;
    }

    public function getCleEntreprise(): ?string
    {
        return $this->cleEntreprise;
    }

    public function setCleEntreprise(string $cleEntreprise): static
    {
        $this->cleEntreprise = $cleEntreprise;

        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;

        return $this;
    }

    public function getDepartement(): ?Departement
    {
        return $this->departement;
    }

    public function setDepartement(?Departement $departement): static
    {
        $this->departement = $departement;

        return $this;
    }

    public function getBureauEtudeInterne(): ?bool
    {
        return $this->bureauEtudeInterne;
    }

    public function setBureauEtudeInterne(?bool $bureauEtudeInterne): static
    {
        $this->bureauEtudeInterne = $bureauEtudeInterne;

        return $this;
    }

    public function getSiren(): ?string
    {
        return $this->siren;
    }

    public function setSiren(?string $siren): static
    {
        $this->siren = $siren;

        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): static
    {
        $this->siret = $siret;

        return $this;
    }

    public function getNaf(): ?string
    {
        return $this->naf;
    }

    public function setNaf(?string $naf): static
    {
        $this->naf = $naf;

        return $this;
    }

    public function getRcsRm(): ?string
    {
        return $this->rcsRm;
    }

    public function setRcsRm(?string $rcsRm): static
    {
        $this->rcsRm = $rcsRm;

        return $this;
    }

    public function getNumTva(): ?string
    {
        return $this->numTva;
    }

    public function setNumTva(?string $numTva): static
    {
        $this->numTva = $numTva;

        return $this;
    }

    public function getIdDolibarr(): ?string
    {
        return $this->idDolibarr;
    }

    public function setIdDolibarr(?string $idDolibarr): static
    {
        $this->idDolibarr = $idDolibarr;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getPays(): ?string
    {
        return $this->pays;
    }

    public function setPays(?string $pays): static
    {
        $this->pays = $pays;

        return $this;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(string|float|null $latitude): static
    {
        if (null === $latitude || '' === $latitude) {
            $this->latitude = null;
        } else {
            $this->latitude = (string) (is_string($latitude) ? (float) $latitude : $latitude);
        }

        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(string|float|null $longitude): static
    {
        if (null === $longitude || '' === $longitude) {
            $this->longitude = null;
        } else {
            $this->longitude = (string) (is_string($longitude) ? (float) $longitude : $longitude);
        }

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = null === $email || '' === trim($email) ? null : trim($email);

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = null === $telephone || '' === trim($telephone) ? null : trim($telephone);

        return $this;
    }

    public function getLinkedinUrl(): ?string
    {
        return $this->linkedinUrl;
    }

    public function setLinkedinUrl(?string $linkedinUrl): static
    {
        $this->linkedinUrl = null === $linkedinUrl || '' === trim($linkedinUrl) ? null : trim($linkedinUrl);

        return $this;
    }

    public function getSiteUrl(): ?string
    {
        return $this->siteUrl;
    }

    public function setSiteUrl(?string $siteUrl): static
    {
        $this->siteUrl = null === $siteUrl || '' === trim($siteUrl) ? null : trim($siteUrl);

        return $this;
    }

    public function getContacte(): ?bool
    {
        return $this->contacte;
    }

    /**
     * Met à jour « Contacté » en synchronisant la date du premier contact :
     *  - true  → datePremierContact = aujourd'hui (si vide, sinon préservée)
     *  - false → datePremierContact = null
     *  - null  → datePremierContact = null (état initial / valeur invalide à l'import)
     *
     * Le couple « Contacté » + « Date du premier contact » est piloté par le
     * switch de la liste prospects ; toute modification directe via le
     * formulaire d'édition doit passer par ce setter.
     */
    public function setContacte(?bool $contacte): static
    {
        $this->contacte = $contacte;
        if (true === $contacte) {
            if (null === $this->datePremierContact) {
                $this->datePremierContact = new \DateTime();
            }
        } else {
            $this->datePremierContact = null;
        }

        return $this;
    }

    public function getDatePremierContact(): ?\DateTime
    {
        return $this->datePremierContact;
    }

    public function setDatePremierContact(?\DateTime $datePremierContact): static
    {
        $this->datePremierContact = $datePremierContact;

        return $this;
    }

    public function getQuali(): ?string
    {
        return $this->quali;
    }

    public function setQuali(?string $quali): static
    {
        $this->quali = $quali;

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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getMontantDevis(): ?float
    {
        return $this->montantDevis;
    }

    public function setMontantDevis(?float $montantDevis): static
    {
        $this->montantDevis = $montantDevis;

        return $this;
    }

    public function getCampagne(): ?Campagne
    {
        return $this->campagne;
    }

    public function setCampagne(?Campagne $campagne): static
    {
        $this->campagne = $campagne;

        return $this;
    }

    /**
     * @return Collection<int, ProspectSprint>
     */
    public function getSprints(): Collection
    {
        return $this->sprints;
    }

    public function addSprint(ProspectSprint $lien): static
    {
        if (!$this->sprints->contains($lien)) {
            $this->sprints->add($lien);
            $lien->setProspect($this);
        }

        return $this;
    }

    public function removeSprint(ProspectSprint $lien): static
    {
        $this->sprints->removeElement($lien);

        return $this;
    }

    /**
     * Présence du prospect dans une vague donnée.
     */
    public function lienPour(Sprint $sprint): ?ProspectSprint
    {
        foreach ($this->sprints as $lien) {
            if ($lien->getSprint()?->getId() === $sprint->getId()) {
                return $lien;
            }
        }

        return null;
    }

    /**
     * Vague courante = dernière vague ajoutée (numéro le plus élevé, à défaut date d'ajout).
     */
    public function vagueCourante(): ?ProspectSprint
    {
        $courant = null;
        foreach ($this->sprints as $lien) {
            if (null === $courant) {
                $courant = $lien;
                continue;
            }

            $numero = $lien->getSprint()?->getNumero() ?? 0;
            $numeroCourant = $courant->getSprint()?->getNumero() ?? 0;
            if ($numero > $numeroCourant || ($numero === $numeroCourant && $lien->getDateAjout() > $courant->getDateAjout())) {
                $courant = $lien;
            }
        }

        return $courant;
    }

    /**
     * @return Collection<int, Contact>
     */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function addContact(Contact $contact): static
    {
        if (!$this->contacts->contains($contact)) {
            $this->contacts->add($contact);
            $contact->setProspect($this);
        }

        return $this;
    }

    public function removeContact(Contact $contact): static
    {
        $this->contacts->removeElement($contact);

        return $this;
    }

    /**
     * @return Collection<int, Action>
     */
    public function getActions(): Collection
    {
        return $this->actions;
    }

    /**
     * Prochaine action planifiée (non réalisée, date prévue la plus proche,
     * aujourd'hui inclus). null s'il n'y en a aucune.
     */
    public function getProchaineAction(): ?Action
    {
        $today = new \DateTime('today');
        $prochaine = null;
        foreach ($this->actions as $action) {
            if (null !== $action->getDateRealisee()) {
                continue;
            }
            $datePrevue = $action->getDatePrevue();
            if (null === $datePrevue || $datePrevue < $today) {
                continue;
            }
            if (null === $prochaine || $datePrevue < $prochaine->getDatePrevue()) {
                $prochaine = $action;
            }
        }

        return $prochaine;
    }

    public function addAction(Action $action): static
    {
        if (!$this->actions->contains($action)) {
            $this->actions->add($action);
            $action->setProspect($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
        }

        return $this;
    }

    public function removeUser(User $user): static
    {
        $this->users->removeElement($user);

        return $this;
    }

    public function isAnomalie(): ?bool
    {
        return $this->anomalie;
    }

    public function setAnomalie(?bool $anomalie): static
    {
        $this->anomalie = $anomalie;

        return $this;
    }

    public function getAnomalieMotif(): ?string
    {
        return $this->anomalieMotif;
    }

    public function setAnomalieMotif(?string $anomalieMotif): static
    {
        $this->anomalieMotif = mb_substr((string) $anomalieMotif, 0, 500);

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    /**
     * Contact marqué principal ; à défaut le premier de la liste.
     */
    public function getContactPrincipal(): ?Contact
    {
        foreach ($this->contacts as $contact) {
            if ($contact->isEstPrincipal()) {
                return $contact;
            }
        }

        foreach ($this->contacts as $contact) {
            return $contact;
        }

        return null;
    }

    /**
     * Cumule des motifs d'anomalie rencontrés à l'import.
     */
    public function addAnomalie(string $motif): static
    {
        $existant = (string) $this->anomalieMotif;
        $this->anomalieMotif = mb_substr('' === $existant ? $motif : $existant.' ; '.$motif, 0, 500);
        $this->anomalie = true;

        return $this;
    }
}
