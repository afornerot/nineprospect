<?php

namespace App\Form;

use App\Entity\Pipeline;
use App\Entity\Prospect;
use App\Entity\Sprint;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Sprint>
 */
class SprintType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('submit', SubmitType::class, [
                'label' => 'Valider',
                'attr' => ['class' => 'btn btn-success no-print me-1'],
            ])

            ->add('numero', IntegerType::class, [
                'label' => 'Numéro de vague',
                'attr' => $options['numeroLectureSeule'] ? ['readonly' => true] : [],
            ])

            ->add('pipeline', EntityType::class, [
                'label' => 'Pipeline',
                'class' => Pipeline::class,
                'choice_label' => 'nom',
                'required' => true,
            ])

            ->add('libelle', TextType::class, [
                'label' => 'Libellé',
                'required' => false,
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

        // Composition de la vague : ajouté uniquement sur la page de
        // modification (la création renvoie dessus juste après le POST).
        if ($options['afficherProspects']) {
            $builder->add('prospects', EntityType::class, [
                'label' => 'Prospects de la vague',
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
            'data_class' => Sprint::class,
            'afficherProspects' => false,
            'numeroLectureSeule' => false,
        ]);
        $resolver->setAllowedTypes('afficherProspects', 'bool');
        $resolver->setAllowedTypes('numeroLectureSeule', 'bool');
    }
}
