<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Form;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CommandFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $command = $options['command'];
        /** @var Command $command */
        $defintion = $command->getDefinition();
        foreach ($defintion->getArguments() as $argument) {
            $default = $argument->getDefault();
            $builder
                ->add($argument->getName(), null, [
                    'help' => $argument->getDescription(),
                    'required' => $argument->isRequired(),
                    'attr' => [
                        'placeholder' => is_array($default) ? implode(',', $default) : $default,
                    ],
                ]);
        }

        foreach ($defintion->getOptions() as $option) {
            $conf = null;
            // no type?
            if ($option->isArray()) {
                $type = TextareaType::class;
                //                assert(false, 'option array not yet handled in ' . $option->getName());
                //                dd($option);
                continue; // @todo: add array to string converter, e.g.
            } elseif ($option->isNegatable()) {
                $type = ChoiceType::class;
                $choiceValue = null;
                $required = false;

                if (is_bool($option->getDefault())) {
                    $choiceValue = ($option->getDefault() ? '--'.$option->getName() : '--no-'.$option->getName());
                    $required = true;
                }

                $conf = [
                    'help' => $option->getDescription(),
                    'choices' => [
                        '--'.$option->getName() => true,
                        '--no-'.$option->getName() => false,
                    ],
                    'data' => $option->getDefault(),
                    'expanded' => true,
                    'required' => $required,
                    'attr' => [
                        'placeholder' => $choiceValue,
                        'style' => 'display:flex; gap: 1em;',
                    ],
                ];
            } elseif (is_bool($option->getDefault())) {
                $type = CheckboxType::class;
            } elseif (is_int($option->getDefault())) {
                $type = NumberType::class;
            } else {
                $type = TextType::class;
            }

            if ($conf) {
                $builder
                    ->add($option->getName(), $type, $conf);
            } else {
                $builder
                    ->add($option->getName(), $type, [
                        'help' => $option->getDescription(),
                        'required' => false,
                        'attr' => [
                            'placeholder' => $option->getDefault(),
                        ],
                    ]);
            }
        }

        if ($options['hasBus']) {
            $builder->add('asMessage', CheckboxType::class, [
                'label' => 'Via Message Bus',
                'help' => 'Run Command via consume:messages',
                'required' => false,
            ]);
        }

        $builder->add('submit', SubmitType::class, [
            'label' => 'Run Command',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'command' => null,
            'hasBus' => false,
            //            'data_class' => Command::class
            // Configure your form options here
        ]);
    }
}
