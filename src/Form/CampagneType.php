<?php

namespace App\Form;

use App\Entity\Campagne;
use App\Entity\Prospect;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Campagne>
 */
class CampagneType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('submit', SubmitType::class, [
                'label' => 'Valider',
                'attr' => ['class' => 'btn btn-success no-print me-1'],
            ])

            ->add('externalId', TextType::class, [
                'label' => 'Identifiant Meta',
            ])

            ->add('nom', TextType::class, [
                'label' => 'Nom de la campagne',
            ])

            ->add('sourceLabel', TextType::class, [
                'label' => 'Source',
                'required' => false,
            ])

            ->add('budget', NumberType::class, [
                'label' => 'Budget (€)',
                'required' => false,
                'scale' => 2,
            ])

            ->add('dateDebut', DateType::class, [
                'label' => 'Début',
                'widget' => 'single_text',
                'required' => false,
            ])

            ->add('dateFin', DateType::class, [
                'label' => 'Fin',
                'widget' => 'single_text',
                'required' => false,
            ])

            ->add('notes', TextareaType::class, [
                'label' => 'Notes',
                'required' => false,
                'attr' => ['rows' => 4],
            ]);

        // Composition : rattachement de prospects à la campagne (un prospect
        // ne pouvant appartenir qu'à une seule campagne à la fois, le
        // formulaire permet d'ajouter et de retirer). Affiché uniquement en
        // modification, comme pour les vagues.
        if ($options['afficherProspects']) {
            $builder->add('prospects', EntityType::class, [
                'label' => 'Prospects de la campagne',
                'class' => Prospect::class,
                'choice_label' => 'nom',
                'multiple' => true,
                'required' => false,
                'mapped' => false,
                'attr' => ['class' => 'js-prospects-multi d-none'],
                'query_builder' => static fn (EntityRepository $er): QueryBuilder => $er->createQueryBuilder('p')
                    ->orderBy('p.nom', 'ASC'),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Campagne::class,
            'afficherProspects' => false,
        ]);
        $resolver->setAllowedTypes('afficherProspects', 'bool');
    }
}
