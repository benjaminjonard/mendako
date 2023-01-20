<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Enum\Locale;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

readonly class LocaleListener
{
    public function __construct(
        private RequestStack $requestStack,
        private string $defaultLocale
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$request->hasPreviousSession()) {
            return;
        }

        $locale = $request->query->get('_locale');

        if ($locale && \in_array($locale, Locale::LOCALES, false)) {
            $request->getSession()->set('_locale', $locale);
            $request->setLocale($request->getSession()->get('_locale', $locale));
        } else {
            $request->setLocale($request->getSession()->get('_locale', $this->defaultLocale));
        }
    }

    public function onSecurityInteractiveLogin(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();

        if (null !== $user->getLocale()) {
            $this->requestStack->getSession()->set('_locale', $user->getLocale());
        }
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof User && $this->requestStack->getMainRequest()) {
            $this->requestStack->getSession()->set('_locale', $entity->getLocale());
        }
    }
}
