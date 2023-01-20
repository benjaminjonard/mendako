<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\Post;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController as SymfonyAbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractController extends SymfonyAbstractController
{
    public function createDeleteForm(
        string $url,
        $entity = null
    ): FormInterface {
        $params = [];
        if ($entity !== null) {
            $params['id'] = $entity->getId();
        }

        if ($entity instanceof Post) {
            $params['slug'] = $entity->getBoard()->getSlug();
        }

        if ($entity instanceof Board) {
            $params['slug'] = $entity->getSlug();
        }

        return $this->createFormBuilder()
            ->setAction($this->generateUrl($url, $params))
            ->setMethod(Request::METHOD_POST)
            ->getForm()
        ;
    }
}
