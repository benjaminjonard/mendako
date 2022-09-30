<?php

declare(strict_types=1);

namespace App\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class SeenListener
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $route = $event->getRequest()->get('_route');

        if ($route === 'app_post_show') {
            $id = $event->getRequest()->get('id');
            $sql = "UPDATE men_post SET seen_counter = seen_counter + 1 WHERE id = ?";
            $stmt = $this->managerRegistry->getManager()->getConnection()->prepare($sql);
            $stmt->bindParam(1, $id);
            $stmt->execute();
        }
    }
}
