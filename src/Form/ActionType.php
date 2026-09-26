<?php

namespace App\Form;

use App\Entity\Action;
use App\Entity\Contact;
use App\Entity\Prospect;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @extends AbstractType<Action>
 */
class ActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('submit', SubmitType::class, [
                'label' => 'Valider',
                'attr' => ['class' => 'btn btn-success no-print'],
            ])

            ->add('prospect', EntityType::class, [
                'label' => 'Prospect',
                'class' => Prospect::class,
                'choice_label' => 'nom',
                'placeholder' => '— Choisir un prospect —',
                'required' => true,
                'attr' => ['class' => 'js-action-prospect select2'],
            ])

            ->add('contact', EntityType::class, [
                'label' => 'Contact',
                'class' => Contact::class,
                'choice_label' => 'nomComplet',
                'placeholder' => '—',
                'required' => false,
                'query_builder' => static function (\Doctrine\ORM\EntityRepository $er) use ($options) {
                    $qb = $er->createQueryBuilder('c')
                        ->orderBy('c.nom', 'ASC')
                        ->addOrderBy('c.prenom', 'ASC');
                    if (null !== $options['prospectFiltre']) {
                        $qb->andWhere('c.prospect = :pid')
                            ->setParameter('pid', $options['prospectFiltre']);
                    }

                    return $qb;
                },
                'attr' => ['class' => 'js-action-contact select2'],
            ])

            ->add('typeAction', TextType::class, [
                'label' => 'Type d\'action',
                'required' => true,
                'constraints' => [new NotBlank(['message' => 'Renseignez un type d\'action.'])],
                'attr' => [
                    'list' => 'js-action-type-suggestions',
                    'autocomplete' => 'off',
                    'placeholder' => 'Appel, Mail, Visio…',
                ],
                'help' => 'Obligatoire. Saisissez librement (suggestions paramétrables via Types d\'action).',
            ])

            ->add('aFairePar', EntityType::class, [
                'label' => 'À faire par',
                'class' => User::class,
                'choice_label' => 'username',
                'placeholder' => '—',
                'required' => false,
                'query_builder' => static fn (UserRepository $er) => $er->createQueryBuilder('u')->orderBy('u.username', 'ASC'),
            ])

            ->add('datePrevue', DateType::class, [
                'label' => 'À faire le',
                'widget' => 'single_text',
                'required' => false,
            ])

            ->add('dateRealisee', DateType::class, [
                'label' => 'Réalisée le',
                'widget' => 'single_text',
                'required' => false,
            ])

            ->add('realisePar', EntityType::class, [
                'label' => 'Réalisé par',
                'class' => User::class,
                'choice_label' => 'username',
                'placeholder' => '—',
                'required' => false,
                'query_builder' => static fn (UserRepository $er) => $er->createQueryBuilder('u')->orderBy('u.username', 'ASC'),
            ])

            ->add('resultat', TextareaType::class, [
                'label' => 'Résultat',
                'required' => false,
                'attr' => ['rows' => 4],
            ])

            ->add('redirect', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'off'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Action::class,
            'prospectFiltre' => null,
            'constraints' => [
                new Callback(static function (Action $action, ExecutionContextInterface $ctx): void {
                    if (null === $action->getDatePrevue() && null === $action->getDateRealisee()) {
                        $ctx->buildViolation('Renseignez une date : « À faire le » ou « Réalisée le ».')
                            ->atPath('datePrevue')
                            ->addViolation();
                    }
                }),
            ],
        ]);
        $resolver->setAllowedTypes('prospectFiltre', ['null', 'int']);
    }
}
