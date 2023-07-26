<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Entity\User;
use App\Enum\PaginationType;
use App\Enum\Theme;
use App\Service\LocaleHelper;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserType extends AbstractType
{
    public function __construct(
        private LocaleHelper $localeHelper
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdition = $builder->getData()->getCreatedAt() !== null;

        $builder
            ->add('username', TextType::class, [
                'attr' => ['length' => 255],
                'required' => true,
            ])
            ->add('email', EmailType::class, [
                'attr' => ['length' => 255],
                'required' => true,
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'required' => !$isEdition,
                'invalid_message' => 'error.password.not_matching',
            ])
            ->add('timezone', TimezoneType::class, [
                'required' => true,
            ])
            ->add('locale', ChoiceType::class, [
                'choices' => array_flip($this->localeHelper->getLocaleLabels()),
                'required' => true,
            ])
        ;

        if ($isEdition) {
            $builder
                ->add('theme', ChoiceType::class, [
                    'choices' => array_flip(Theme::getThemeLabels()),
                    'required' => true,
                ])
                ->add('paginationType', ChoiceType::class, [
                    'choices' => array_flip(PaginationType::getPaginationTypeLabels()),
                    'required' => true,
                ])
            ;
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
