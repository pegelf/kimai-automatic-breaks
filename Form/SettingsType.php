<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Form;

use App\Entity\Team;
use App\Form\Type\YesNoType;
use Doctrine\ORM\EntityRepository;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\Policy;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Range;

final class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('enabled', YesNoType::class, ['label' => 'active', 'help' => 'automatic_breaks.help.enabled']);
        $builder->add('rules', CollectionType::class, [
            'entry_type' => RuleType::class,
            'entry_options' => ['label' => false],
            'allow_add' => true,
            'allow_delete' => true,
            'constraints' => [new Count(min: 1, max: Policy::MAX_RULES)],
            'label' => false,
        ]);
        foreach (['maximumWork' => 'maximum', 'resetGap' => 'reset', 'workdayGap' => 'workday'] as $name => $label) {
            $minimum = $name === 'workdayGap' ? 1 : 0;
            $builder->add($name, IntegerType::class, [
                'label' => 'automatic_breaks.' . $label,
                'help' => 'automatic_breaks.help.' . $label,
                'constraints' => [new Range(min: $minimum, max: 10080)],
                'attr' => ['min' => $minimum, 'max' => 10080],
            ]);
        }
        $builder->add('excludedTeams', EntityType::class, [
            'class' => Team::class,
            'choice_label' => 'name',
            'choice_translation_domain' => false,
            'label' => 'automatic_breaks.excluded',
            'help' => 'automatic_breaks.help.excluded',
            'multiple' => true,
            'required' => false,
            'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('team')->orderBy('team.name', 'ASC'),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'messages', 'data_class' => null]);
    }
}
