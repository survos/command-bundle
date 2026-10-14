<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Menu;

use Survos\MenuBundle\Attribute\AsMenuListener;
use Survos\MenuBundle\Event\MenuEvent;
use Survos\MenuBundle\MenuSlot;

class CommandBundleMenuSubscriber
{
    #[AsMenuListener(MenuSlot::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $submenu = $menu->addChild('Commands');
        $submenu->addChild('Run Commands', ['route' => 'survos_commands']);
        $submenu->addChild('Processes', ['route' => 'survos_command_processes']);
    }
}
