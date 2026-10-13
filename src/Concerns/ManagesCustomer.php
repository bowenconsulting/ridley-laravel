<?php

declare(strict_types=1);

namespace Ridley\Laravel\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Ridley\Laravel\Exceptions\CurrencyLocked;
use Ridley\Laravel\Exceptions\InvalidCustomer;
use Ridley\Laravel\Exceptions\NotFoundException;
use Ridley\Laravel\Resources\BalanceTransaction;
use Ridley\Laravel\Resources\Customer;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Support\Money;

/**
 * @mixin Model
 */
trait ManagesCustomer
{
    /**
     * The Ridley customer ID.
     */
    public function ridleyId(): ?string
    {
        $id = $this->ridleyCustomer?->ridley_id;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function hasRidleyId(): bool
    {
        return $this->ridleyId() !== null;
    }

    /**
     * @throws InvalidCustomer
     */
    protected function assertCustomerExists(): void
    {
        if (! $this->hasRidleyId()) {
            throw InvalidCustomer::notYetCreated($this);
        }
    }

    /**
     * Create the Ridley customer for this model.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidCustomer
     */
    public function createAsRidleyCustomer(array $options = []): Customer
    {
        if ($this->hasRidleyId()) {
            throw InvalidCustomer::exists($this);
        }

        $customer = Ridley::client()->customers()->create($this->ridleyCustomerPayload($options));

        $this->updateRidleyCustomerRecord(['ridley_id' => $customer->id()]);

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function updateRidleyCustomer(array $options = []): Customer
    {
        $this->assertCustomerExists();

        return Ridley::client()->customers()->update((string) $this->ridleyId(), $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function createOrGetRidleyCustomer(array $options = []): Customer
    {
        if ($this->hasRidleyId()) {
            return $this->asRidleyCustomer();
        }

        return $this->createAsRidleyCustomer($options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function updateOrCreateRidleyCustomer(array $options = []): Customer
    {
        if ($this->hasRidleyId()) {
            return $this->updateRidleyCustomer($options);
        }

        return $this->createAsRidleyCustomer($options);
    }

    /**
     * Push the model's name, email, phone, address and metadata to Ridley.
     */
    public function syncRidleyCustomerDetails(): Customer
    {
        return $this->updateRidleyCustomer($this->ridleyCustomerPayload());
    }

    public function asRidleyCustomer(): Customer
    {
        $this->assertCustomerExists();

        return Ridley::client()->customers()->retrieve((string) $this->ridleyId());
    }

    public function ridleyName(): ?string
    {
        $name = $this->getAttribute('name');

        return is_string($name) ? $name : null;
    }

    public function ridleyEmail(): ?string
    {
        $email = $this->getAttribute('email');

        return is_string($email) ? $email : null;
    }

    public function ridleyPhone(): ?string
    {
        $phone = $this->getAttribute('phone');

        return is_string($phone) ? $phone : null;
    }

    /**
     * @return array{line1?: string|null, line2?: string|null, city?: string|null, state?: string|null, postal_code?: string|null, country?: string|null}|null
     */
    public function ridleyAddress(): ?array
    {
        return null;
    }

    /**
     * Metadata stored on the Ridley customer.
     *
     * @return array<string, mixed>
     */
    public function ridleyMetadata(): array
    {
        return [
            'billable_type' => $this->getMorphClass(),
            'billable_id' => (string) $this->getKey(),
        ];
    }

    /**
     * Where this billable manages its billing: used by notifications and the
     * subscription/feature middleware. Override it when the URL depends on the
     * billable (e.g. an account slug in the path).
     */
    public function ridleyBillingUrl(): ?string
    {
        $url = config('ridley.notifications.billing_url') ?? config('ridley.billing_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function preferredCurrency(): string
    {
        return strtoupper((string) config('ridley.currency', 'USD'));
    }

    /**
     * The currency this billable is billed in: the locked currency, else {@see preferredCurrency()}.
     */
    public function billingCurrency(): string
    {
        $locked = $this->ridleyCustomer?->currency;

        return is_string($locked) && $locked !== '' ? strtoupper($locked) : $this->preferredCurrency();
    }

    /**
     * Whether the currency is locked (set by the first subscription or invoiced charge).
     */
    public function hasLockedCurrency(): bool
    {
        $locked = $this->ridleyCustomer?->currency;

        return is_string($locked) && $locked !== '';
    }

    /**
     * Lock the billing currency if it is not locked yet.
     */
    public function lockBillingCurrency(string $currency): void
    {
        if ($this->hasLockedCurrency() || $currency === '') {
            return;
        }

        $this->updateRidleyCustomerRecord(['currency' => strtoupper($currency)]);
    }

    /**
     * @throws CurrencyLocked
     */
    public function assertCurrencyAllowed(string $currency): void
    {
        if ($this->hasLockedCurrency() && strtoupper($currency) !== $this->billingCurrency()) {
            throw CurrencyLocked::for($this->billingCurrency(), strtoupper($currency));
        }
    }

    /**
     * The formatted balance; negative means credit owed to the customer.
     */
    public function balance(): string
    {
        return Ridley::formatAmount(Money::fromMinor($this->rawBalance()), $this->billingCurrency());
    }

    /**
     * The balance in minor units; negative means credit owed to the customer.
     */
    public function rawBalance(): int
    {
        if (! $this->hasRidleyId()) {
            return 0;
        }

        return $this->asRidleyCustomer()->rawBalance();
    }

    /**
     * Give the customer credit (applied to future invoices).
     *
     * @param  array<string, mixed>  $options
     */
    public function creditBalance(string|int|float $amount, ?string $description = null, array $options = []): BalanceTransaction
    {
        return $this->applyBalance('-'.ltrim(Money::decimal($amount), '-'), $description, $options);
    }

    /**
     * Add an amount the customer owes (charged on the next invoice).
     *
     * @param  array<string, mixed>  $options
     */
    public function debitBalance(string|int|float $amount, ?string $description = null, array $options = []): BalanceTransaction
    {
        return $this->applyBalance(ltrim(Money::decimal($amount), '-'), $description, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function applyBalance(string $amount, ?string $description, array $options): BalanceTransaction
    {
        $this->assertCustomerExists();

        return Ridley::client()->balanceTransactions()->create((string) $this->ridleyId(), array_filter(array_merge([
            'amount' => $amount,
            'currency' => $this->billingCurrency(),
            'description' => $description,
        ], $options), fn ($value) => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Collection<int, BalanceTransaction>
     */
    public function balanceTransactions(int $limit = 25, array $query = []): Collection
    {
        if (! $this->hasRidleyId()) {
            return collect();
        }

        return Ridley::client()->balanceTransactions()
            ->list((string) $this->ridleyId(), array_merge(['page' => ['size' => $limit]], $query))
            ->data();
    }

    /**
     * A short-lived billing portal URL for the customer.
     */
    public function billingPortalUrl(?string $returnUrl = null): string
    {
        $this->assertCustomerExists();

        return (string) Ridley::client()->portalSessions()->create([
            'customer' => $this->ridleyId(),
            'return_url' => $returnUrl ?? url('/'),
        ])->url;
    }

    public function redirectToBillingPortal(?string $returnUrl = null): RedirectResponse
    {
        return new RedirectResponse($this->billingPortalUrl($returnUrl));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function ridleyCustomerPayload(array $options = []): array
    {
        $payload = array_filter([
            'name' => $this->ridleyName(),
            'email' => $this->ridleyEmail(),
            'phone' => $this->ridleyPhone(),
            'address' => $this->ridleyAddress(),
        ], fn ($value) => $value !== null);

        $payload['metadata'] = array_merge($this->ridleyMetadata(), is_array($options['metadata'] ?? null) ? $options['metadata'] : []);

        unset($options['metadata']);

        return array_merge($payload, $options);
    }

    /**
     * Whether the Ridley customer still exists.
     */
    public function ridleyCustomerExists(): bool
    {
        if (! $this->hasRidleyId()) {
            return false;
        }

        try {
            $this->asRidleyCustomer();

            return true;
        } catch (NotFoundException) {
            return false;
        }
    }
}
