<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Entity\Post;
use App\Form\DataTransformer\StringToTagTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Trimmed-down PostType for the Tag validation queue: only the tags field. Reuses PostType's
 * StringToTagTransformer, so accepted suggestions keep their category and new tags are created on the fly.
 */
class TagValidationType extends AbstractType
{
    public function __construct(
        private readonly StringToTagTransformer $stringToTagTransformer,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tags', TextareaType::class, [
                'required' => false,
            ])
        ;

        $builder->get('tags')->addModelTransformer($this->stringToTagTransformer);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Post::class,
        ]);
    }
}
