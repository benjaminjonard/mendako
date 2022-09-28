<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Entity\Post;
use App\Form\DataTransformer\StringToTagTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\RouterInterface;

class PostType extends AbstractType
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly StringToTagTransformer $stringToTagTransformer,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdition = !($builder->getData()->getCreatedAt() === null);

        $builder
            ->add('file', FileType::class, [
                'required' => !$isEdition,
                'label' => false,
            ])
            ->add('tags', TextareaType::class, [
                'required' => true
            ])
            ->add('setAsBoardThumbnail', CheckboxType::class, [
                'required' => false,
                'mapped' => false
            ])
        ;

        $builder->get('tags')->addModelTransformer($this->stringToTagTransformer);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Post::class,
        ]);
    }
}