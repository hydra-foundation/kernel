<?php

declare(strict_types=1);

namespace Hydra\Kernel;

use Hydra\Http\Exceptions\HttpException;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\View\Contracts\ViewInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Base controller: response and view helpers every HTML controller wants.
 *
 * It ships here rather than being re-written in each app because it was byte-for-byte
 * identical across consumers — the same "framework, not skeleton" call as the rest
 * of this package. It's deliberately NOT in hydra/http: the {@see render()} helper
 * couples to the {@see ViewInterface} seam, and http stays view-agnostic (a JSON
 * API controller uses {@see Responder} with no view at all). The kernel — the
 * opinionated app baseline — is the right home, and it takes only the view SEAM,
 * so the app still binds whichever engine it likes.
 *
 * Apps extend it. A subclass with its own dependencies forwards the base args:
 *   public function __construct(Responder $respond, ViewInterface $view, private Repo $repo)
 *   { parent::__construct($respond, $view); }
 *
 * An app that wants project-wide controller helpers can put a thin
 * `App\Controllers\Controller extends \Hydra\Kernel\Controller` in the middle —
 * the extension point stays open without duplicating this.
 */
abstract class Controller
{
    public function __construct(
        protected readonly Responder $respond,
        protected readonly ViewInterface $view,
    ) {}

    /**
     * Render a template to an HTML response — the DX shortcut for the common
     * case, over view->render() followed by respond->html().
     *
     * Pass layout: false to return just the template's body (no surrounding
     * layout) — typically layout: !$htmx->isHtmx() to serve a full page on a
     * normal load and a bare fragment on an htmx swap from the same route.
     *
     * @param array<string, mixed> $data
     */
    protected function render(string $template, array $data = [], int|Status $status = Status::Ok, bool $layout = true): ResponseInterface
    {
        return $this->respond->html($this->view->render($template, $data, $layout), $status);
    }

    /**
     * Stop handling and signal an HTTP error condition. The pipeline's
     * ErrorHandlerMiddleware turns this into the response, so a controller can
     * bail out — abort(404), abort(403, 'not yours') — without building one.
     *
     * @return never
     */
    protected function abort(int|Status $status, string $message = ''): never
    {
        throw new HttpException($status instanceof Status ? $status->value : $status, $message);
    }
}
