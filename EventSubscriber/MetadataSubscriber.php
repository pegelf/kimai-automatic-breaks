<?php

namespace KimaiPlugin\AutomaticBreaksBundle\EventSubscriber;

use App\Entity\TimesheetMeta;
use App\Event\TimesheetMetaDefinitionEvent;
use App\Event\TimesheetMetaDisplayEvent;
use App\Form\Type\YesNoType;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\BreakCalculator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;

final class MetadataSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [TimesheetMetaDisplayEvent::class => 'display', TimesheetMetaDefinitionEvent::class => 'define'];
    }

    public function define(TimesheetMetaDefinitionEvent $event): void
    {
        foreach ([BreakCalculator::ORIGINAL, BreakCalculator::ADDED, BreakCalculator::STATUS, BreakCalculator::REVIEW, BreakCalculator::NOTICE] as $name) {
            $meta = $event->getEntity()->getMetaField($name);
            if ($meta !== null) {
                // Core removes untyped metadata from the in-memory collection while building forms.
                // Keep our bookkeeping intact without changing the editable native break field.
                $meta->setType(HiddenType::class);
                $meta->setOptions(['disabled' => true]);
            }
        }
    }

    public function display(TimesheetMetaDisplayEvent $event): void
    {
        $meta = new TimesheetMeta();
        $meta->setName(BreakCalculator::ADDED);
        $meta->setLabel('automatic_breaks.added');
        $meta->setType(NumberType::class);
        $meta->setIsVisible(true);
        $event->addField($meta);
        $review = new TimesheetMeta();
        $review->setName(BreakCalculator::REVIEW);
        $review->setLabel('automatic_breaks.review');
        $review->setType(YesNoType::class);
        $review->setIsVisible(true);
        $event->addField($review);
    }
}
