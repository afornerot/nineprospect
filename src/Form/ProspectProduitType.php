<?php

namespace App\Form;

use App\Entity\Produit;
use App\Entity\Prospect;
use App\Entity\ProspectProduit;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use SortDirection;

class ProspectProduitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $prospect = $options['prospect'] ?? null;
        $excludedProduitId = $options['excluded_produit_id'] ?? null;

        $builder
            ->add('produit', EntityType::class, [
                'label' => 'Produit',
                'class' => Produit::class,
                'choice_label' => 'titre',
                'placeholder' => 'Sélectionnez un produit…',
                'query_builder' => static function (EntityRepository $er) use ($prospect, $excludedProduitId) {
                    $qb = $er->createQueryBuilder('c')->orderBy('c.titre', SortDirection::Ascending);

                    if ($prospect instanceof Prospect) {
                        $attachedProduitIds = [];
                        foreach ($prospect->getProspectProduits() as $pc) {
                            if (null !== $pc->getProduit() && (null === $excludedProduitId || $pc->getProduit()->getId() !== $excludedProduitId)) {
                                $attachedProduitIds[] = $pc->getProduit()->getId();
                            }
                        }
                        if (\count($attachedProduitIds) > 0) {
                            $qb->where($qb->expr()->notIn('c.id', $attachedProduitIds));
                        }
                    }

                    return $qb;
                },
            ])
            ->add('qualifie', ChoiceType::class, [
                'label' => 'Qualifié',
                'choices' => [
                    'Non' => null,
                    'Oui' => true,
                    'Hors produit' => false,
                ],
                'choice_value' => static fn (?bool $v) => null === $v ? '__null__' : ($v ? '1' : '0'),
                'placeholder' => false,
                'required' => false,
            ])
            ->add('qualification', TextType::class, [
                'label' => 'Qualification',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProspectProduit::class,
            'prospect' => null,
            'excluded_produit_id' => null,
        ]);
    }
}
