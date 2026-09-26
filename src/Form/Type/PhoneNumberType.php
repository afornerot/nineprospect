<?php

namespace App\Form\Type;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Champ texte téléphone avec validation et formatage via libphonenumber.
 *
 * Options :
 *  - default_region : code pays par défaut pour interpréter les numéros
 *    nationaux (ex. "FR", "CH", "BE"). Défaut : "FR".
 *  - format : format de stockage (E164, INTERNATIONAL, NATIONAL…).
 *    Défaut : E164 (forme canonique sans espaces, pratique en BDD).
 *
 * À l'affichage : la valeur stockée est formatée en INTERNATIONAL pour
 * faciliter la lecture et l'édition.
 * À la soumission : la valeur saisie est parsée puis reformatée selon
 * $format ; un numéro invalide lève une erreur de transformation.
 *
 * @extends AbstractType<string>
 */
class PhoneNumberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new class($options['default_region'], $options['format']) implements DataTransformerInterface {
            public function __construct(
                private readonly string $defaultRegion,
                private readonly PhoneNumberFormat $format,
            ) {
            }

            public function transform(mixed $value): mixed
            {
                if (null === $value || '' === $value) {
                    return '';
                }

                $util = PhoneNumberUtil::getInstance();
                try {
                    $parsed = $util->parse((string) $value, $this->defaultRegion);
                } catch (NumberParseException) {
                    // Affiche la valeur brute si elle n'est pas parseable.
                    return (string) $value;
                }

                return $util->format($parsed, PhoneNumberFormat::INTERNATIONAL);
            }

            public function reverseTransform(mixed $value): mixed
            {
                if (null === $value || '' === trim((string) $value)) {
                    return null;
                }

                $util = PhoneNumberUtil::getInstance();
                try {
                    $parsed = $util->parse((string) $value, $this->defaultRegion);

                    return $util->format($parsed, $this->format);
                } catch (NumberParseException $e) {
                    throw new TransformationFailedException(sprintf('Numéro de téléphone invalide : %s', $e->getMessage()), 0, $e);
                }
            }
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = 'tel';
        $view->vars['attr']['placeholder'] = $options['placeholder'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'default_region' => 'FR',
            'format' => PhoneNumberFormat::E164,
            'invalid_message' => 'Numéro de téléphone invalide.',
            'placeholder' => '06 12 34 56 78',
        ]);
        $resolver->setAllowedTypes('default_region', 'string');
        $resolver->setAllowedValues('format', [
            PhoneNumberFormat::E164,
            PhoneNumberFormat::INTERNATIONAL,
            PhoneNumberFormat::NATIONAL,
            PhoneNumberFormat::RFC3966,
        ]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
