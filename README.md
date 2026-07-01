# Hydra Kernel

The framework's default composition root and HTTP plumbing. It exists to keep the
wiring that is identical across every Hydra app in **one place**, so a consumer's
`AppServiceProvider` holds only *policy* — not the boilerplate that used to be
copied into each app and drift.

## What it ships

- **`Kernel::application($container, $basePath)`** — builds the shared
  composition root: binds the container and `Environment`, then registers the
  standard provider stack in the correct order (`Session` → `Event` → `Auth` →
  `Authorization`). Returns the `Application` so the app chains its own providers.
  Adding a framework package to the stack is now a one-line edit here that every
  consumer picks up on `composer update`.
- **`HttpServiceProvider`** — binds the invariant HTTP plumbing: the PSR-17
  factories, the request provider, the emitter, the responder, the route cache,
  the router, the middleware pipeline, and the HTTP kernel. It takes the app's
  *policy* — the controller list, the middleware stack, the route-cache toggle and
  path — as plain constructor data, so it never needs to know an app's config
  types.

## Using it

A consumer's bootstrap collapses to composing these with its own provider:

```php
public static function application(string $basePath): Application
{
    $container = new Container(new \DI\Container);
    $routeCache = RouteConfig::fromEnvironment(new Environment($basePath))->cache;

    return Kernel::application($container, $basePath)
        ->register(new HttpServiceProvider(
            controllers: AppServiceProvider::CONTROLLERS,
            middleware:  AppServiceProvider::MIDDLEWARE,
            routeCacheEnabled: $routeCache,
            routeCachePath: $basePath . '/bootstrap/cache/routes.php',
        ))
        ->register(new AppServiceProvider);
}
```

The app's `AppServiceProvider` then binds only what is genuinely its own: config
value objects, the data layer, the user provider, the view, the logger, the two
config-needing middleware (`ForceHttps`, `ErrorHandler`), and its event listeners.

## The DI engine stays the app's choice

`Kernel::application()` takes an already-built `ContainerInterface` rather than
constructing one, so the app keeps ownership of its php-di (or other) container.
The kernel wires providers into it; it does not pick it.
