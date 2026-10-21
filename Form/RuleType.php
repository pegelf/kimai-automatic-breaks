<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Form;

use KimaiPlugin\AutomaticBreaksBundle\Calculation\Policy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

final class RuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('mode', ChoiceType::class, [
            'label' => 'automatic_breaks.mode',
            'help' => 'automatic_breaks.help.mode',
            'choices' => [
                'automatic_breaks.mode.capped' => Policy::MODE_CAPPED,
                'automatic_breaks.mode.fixed' => Policy::MODE_FIXED,
            ],
            'empty_data' => Policy::MODE_FIXED,
        ]);
        foreach (['threshold' => 'threshold', 'break' => 'total'] as $name => $label) {
            $minimum = $name === 'threshold' ? 1 : 0;
            $builder->add($name, IntegerType::class, [
                'label' => 'automatic_breaks.' . $label,
                'help' => 'automatic_breaks.help.' . $label,
                'constraints' => [new NotNull(), new Range(min: $minimum, max: 10080)],
                'attr' => ['min' => $minimum, 'max' => 10080, 'data-rule-' . $name => ''],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'messages', 'data_class' => null]);
    }
}
