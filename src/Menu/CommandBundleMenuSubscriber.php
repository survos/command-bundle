<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class CommandBundleMenuSubscriber
{
    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $submenu = $menu->addChild('Commands');
        $submenu->addChild('Run Commands', ['route' => 'survos_commands']);
        $submenu->addChild('Processes', ['route' => 'survos_command_processes']);
    }
}
