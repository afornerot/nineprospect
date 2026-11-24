<?php

namespace App\Form;

use App\Entity\Produit;
use App\Service\ScheduleApi;
use Bnine\MdEditorBundle\Form\Type\MarkdownType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProduitType extends AbstractType
{
    public function __construct(
        private readonly ScheduleApi $schedule,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('submit', SubmitType::class, [
                'label' => 'Valider',
                'attr' => ['class' => 'btn btn-success no-print me-1'],
            ])

            ->add('titre', TextType::class, [
                'label' => 'Titre',
            ])

            ->add('description', MarkdownType::class, [
                'label' => 'Description',
                'required' => false,
            ]);

        // Id de tâche schedule : pertinent seulement si l'intégration est active.
        if ($this->schedule->isConfigured()) {
            $builder->add('scheduleTaskId', TextType::class, [
                'label' => 'Id de tâche schedule',
                'required' => false,
                'help' => 'Identifiant de la tâche associée à ce produit dans schedule (planning ETP).',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Produit::class,
        ]);
    }
}
