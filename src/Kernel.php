<?php

declare(strict_types=1);

namespace Hydra\Kernel;

use Hydra\Auth\AuthServiceProvider;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Event\EventServiceProvider;
use Hydra\Session\SessionServiceProvider;

/**
 * The framework's default composition root.
 *
 * Every Hydra app needs the same providers registered in the same order — the
 * session before auth (the guard reads the started session), the event
 * dispatcher before auth (the guard picks it up when resolved), authorization
 * after auth (the gate reads the guard). Encoding that sequence in each app's
 * bootstrap was exactly the wiring that drifted between consumers; owning it here
 * means a new framework package joins the stack in ONE place and every app gets
 * it on `composer update`.
 *
 * The app still owns what only it can: the php-di container instance (so the DI
 * engine stays the app's choice), its {@see HttpServiceProvider} policy (the
 * controller + middleware lists), and its own service provider. Those chain onto
 * the {@see Application} this returns.
 */
final class Kernel
{
    /**
     * Build the shared composition root: bind the container and environment, then
     * register the standard provider stack. Returns the {@see Application} so the
     * caller chains its HttpServiceProvider and AppServiceProvider.
     *
     * The caller constructs the {@see Environment} and passes it in — parsing
     * `.env` happens exactly once per request, in the app's bootstrap, and every
     * consumer (the bootstrap's own config reads and everything resolved from
     * the container) shares that one instance.
     *
     * Providers are registered, not booted — booting is the caller's lifecycle.
     * EVERY entrypoint must boot before doing real work (boot() is where
     * cross-provider wiring such as event-listener registration happens): the
     * HTTP path boots via {@see Application::run()}, and a console entrypoint
     * calls {@see Application::boot()} explicitly before dispatching a command.
     */
    public static function application(ContainerInterface $container, Environment $environment): Application
    {
        // The container must resolve itself (factories that need it ask for the
        // interface) and expose the environment every config object reads from.
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, $environment);

        return (new Application($container))
            ->register(new SessionServiceProvider)
            ->register(new EventServiceProvider)
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider);
    }
}
