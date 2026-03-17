<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Menu;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CommandBundleMenuSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Only subscribe if tabler-bundle's MenuEvent exists
        if (!class_exists(\Survos\TablerBundle\Event\MenuEvent::class)) {
            return [];
        }

        return [
            \Survos\TablerBundle\Event\MenuEvent::NAVBAR_MENU => 'onNavbarMenu',
        ];
    }

    public function onNavbarMenu($event): void
    {
        $menu = $event->getMenu();

        // Add Commands submenu
        $submenu = $this->addSubmenu($menu, 'Commands');
        $submenu->addChild('run_commands', [
            'route' => 'survos_commands',
            'label' => 'Run Commands',
        ]);
    }

    private function addSubmenu($menu, string $label, ?string $icon = null): mixed
    {
        $submenu = $menu->addChild($label);
        if ($icon) {
            $submenu->setAttribute('icon', $icon);
        }
        return $submenu;
    }
}
