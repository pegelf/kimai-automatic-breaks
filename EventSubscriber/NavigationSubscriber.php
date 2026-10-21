<?php

namespace KimaiPlugin\AutomaticBreaksBundle\EventSubscriber;

use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class NavigationSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConfigureMainMenuEvent::class => ['configure', 100]];
    }

    public function configure(ConfigureMainMenuEvent $event): void
    {
        // Match Kimai's menu visibility; the settings controller separately requires a fresh login.
        if ($this->security->isGranted('IS_AUTHENTICATED_REMEMBERED') && $this->security->isGranted('system_configuration')) {
            $event->getSystemMenu()->addChild(new MenuItemModel(
                'automatic_breaks',
                'automatic_breaks.title',
                'automatic_breaks_settings',
                [],
                'fas fa-mug-saucer',
            ));
        }
    }
}
