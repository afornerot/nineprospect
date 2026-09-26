<?php

namespace App\Entity;

use App\Repository\ActionTypeDefautRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Type d'action paramétrable (ex. Appel, Mail, Visio, RDV…).
 *
 * Sert de source pour pré-remplir le champ libre {@see Action::$typeAction}
 * lors de la création d'une action : si une ligne existe ici, son libellé
 * est copié comme valeur initiale. L'utilisateur reste libre de saisir un
 * texte différent — ce champ n'est PAS un lien, juste un helper.
 */
#[ORM\Entity(repositoryClass: ActionTypeDefautRepository::class)]
#[ORM\Table(name: 'action_type_defaut')]
class ActionTypeDefaut
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $libelle = null;

    /**
     * Position dans la liste (tri ascendant).
     */
    #[ORM\Column]
    private ?int $ordre = 0;

    /**
     * Type actif : un type inactif n'est plus proposé comme suggestion,
     * mais reste consultable.
     */
    #[ORM\Column]
    private ?bool $actif = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getOrdre(): ?int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function isActif(): ?bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->libelle;
    }
}
