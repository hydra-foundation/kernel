<?php

declare(strict_types=1);

namespace Hydra\Kernel;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\KernelInterface;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Http\Contracts\EmitterInterface;
use Hydra\Http\Contracts\ServerRequestProviderInterface;
use Hydra\Http\Emitter;
use Hydra\Http\HttpKernel;
use Hydra\Http\Pipeline;
use Hydra\Http\Responder;
use Hydra\Http\RouteCache;
use Hydra\Http\RouteScanner;
use Hydra\Http\Router;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Binds the framework's HTTP plumbing — the wiring that is identical in every
 * Hydra app and used to be copied into each one's AppServiceProvider (the drift
 * this package exists to end).
 *
 * It binds only invariants: the emitter, the responder, the route cache, the
 * router, the middleware pipeline, and the HTTP kernel. Everything here is
 * pure mechanism, and none of it names a PSR-7 vendor: the concrete PSR-17
 * factories and the request provider are supplied by a separately-registered
 * provider (nyholm's NyholmServiceProvider by default) — this provider only
 * consumes those interfaces from the container. Swapping PSR-7 libraries is a
 * composition-root change, not a kernel change.
 *
 * The two things that are NOT invariant — which controllers to route and which
 * middleware to run — are the app's policy, passed in as plain data at
 * construction. Taking them as constructor arguments (rather than resolving some
 * app config type) keeps this provider free of every app's config classes: it
 * never needs to know about `App\Config\RouteConfig`, only a bool and a path.
 *
 * The app's own AppServiceProvider still binds what only it can: config value
 * objects, the data layer, the user provider, the view, the logger, and the two
 * config-needing middleware (ForceHttps, ErrorHandler) — all resolved from the
 * container by the bindings here exactly as before.
 */
final class HttpServiceProvider extends ServiceProvider
{
    /**
     * @param list<class-string>  $controllers      scanned for #[Route] attributes
     * @param list<class-string>  $middleware       the stack, outermost first, each resolved via the container
     * @param bool                $routeCacheEnabled load the compiled cache instead of scanning (deploy-time perf)
     * @param string              $routeCachePath   where the route:cache command writes the compiled artifact
     */
    public function __construct(
        private readonly array $controllers,
        private readonly array $middleware,
        private readonly bool $routeCacheEnabled,
        private readonly string $routeCachePath,
    ) {}

    public function register(ContainerInterface $container): void
    {
        // Send the response.
        $container->singleton(EmitterInterface::class, fn () => new Emitter);

        // Response helper shared by controllers (via the base Controller).
        $container->singleton(Responder::class, function () use ($container) {
            return new Responder(
                $container->get(ResponseFactoryInterface::class),
                $container->get(StreamFactoryInterface::class),
            );
        });

        // The compiled route cache, bound once so the web path (read-only) and
        // the route:cache / route:cache:clear console commands (the writers)
        // agree on a single artifact location.
        $container->singleton(RouteCache::class, fn () => new RouteCache($this->routeCachePath));

        // Router, populated from controller #[Route] attributes. Routing misses
        // (404/405) are thrown as HttpExceptions and rendered by the pipeline's
        // ErrorHandlerMiddleware, so the router needs no response factory.
        $container->singleton(Router::class, function () use ($container) {
            $router = new Router($container);
            $router->loadRoutes($this->compileRoutes($container));
            return $router;
        });

        // The application handler: the middleware pipeline wrapping the router.
        // The stack is the app's class-string list, resolved here through the
        // container so each middleware gets full dependency injection.
        $container->singleton(RequestHandlerInterface::class, function () use ($container) {
            $middleware = array_map(
                fn (string $class) => $container->get($class),
                $this->middleware,
            );

            return new Pipeline($middleware, $container->get(Router::class));
        });

        // The HTTP kernel Application resolves and runs.
        $container->singleton(KernelInterface::class, function () use ($container) {
            return new HttpKernel(
                $container->get(ServerRequestProviderInterface::class),
                $container->get(RequestHandlerInterface::class),
                $container->get(EmitterInterface::class),
            );
        });
    }

    /**
     * The compiled route definitions for the Router. This path is READ-ONLY: it
     * never writes the cache during a request — writing belongs to the
     * `route:cache` console command, run at deploy time. With the cache off (the
     * default — dev wants #[Route] edits to take effect at once) it scans the
     * controllers on every boot. With it on it loads the cached file, or falls
     * back to a live scan when the cache is cold (a forgotten `route:cache`
     * degrades to uncached-but-correct, never broken).
     *
     * @return list<array{method: string, path: string, handler: array{0: class-string, 1: string}, middleware: list<class-string>}>
     */
    private function compileRoutes(ContainerInterface $container): array
    {
        $scan = fn (): array => (new RouteScanner)->scan($this->controllers);

        if (!$this->routeCacheEnabled) {
            return $scan();
        }

        return $container->get(RouteCache::class)->load() ?? $scan();
    }
}
