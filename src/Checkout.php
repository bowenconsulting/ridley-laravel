<?php

declare(strict_types=1);

namespace Ridley\Laravel;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Ridley\Laravel\Resources\Session;

/**
 * A hosted checkout the customer must be redirected to.
 */
final readonly class Checkout implements Responsable
{
    public function __construct(public Session $session) {}

    public function id(): ?string
    {
        return $this->session->id();
    }

    public function url(): string
    {
        return (string) $this->session->url();
    }

    public function asRidleySession(): Session
    {
        return $this->session;
    }

    public function redirect(): RedirectResponse
    {
        return new RedirectResponse($this->url());
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): RedirectResponse
    {
        return $this->redirect();
    }
}
