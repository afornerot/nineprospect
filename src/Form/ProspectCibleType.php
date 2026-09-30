<?php

namespace App\Form;

use App\Entity\Cible;
use App\Entity\Prospect;
use App\Entity\ProspectCible;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProspectCibleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $prospect = $options['prospect'] ?? null;
        $excludedCibleId = $options['excluded_cible_id'] ?? null;

        $builder
            ->add('cible', EntityType::class, [
                'label' => 'Cible',
                'class' => Cible::class,
                'choice_label' => 'titre',
                'placeholder' => 'Sélectionnez une cible…',
                'query_builder' => static function (EntityRepository $er) use ($prospect, $excludedCibleId) {
                    $qb = $er->createQueryBuilder('c')->orderBy('c.titre', 'ASC');

                    if ($prospect instanceof Prospect) {
                        $attachedCibleIds = [];
                        foreach ($prospect->getProspectCibles() as $pc) {
                            if (null !== $pc->getCible() && (null === $excludedCibleId || $pc->getCible()->getId() !== $excludedCibleId)) {
                                $attachedCibleIds[] = $pc->getCible()->getId();
                            }
                        }
                        if (\count($attachedCibleIds) > 0) {
                            $qb->where($qb->expr()->notIn('c.id', $attachedCibleIds));
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
                    'Hors cible' => false,
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
            'data_class' => ProspectCible::class,
            'prospect' => null,
            'excluded_cible_id' => null,
        ]);
    }
}
