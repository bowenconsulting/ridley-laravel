<?php

declare(strict_types=1);

namespace Ridley\Laravel\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Ridley\Laravel\Exceptions\NotFoundException;
use Ridley\Laravel\Resources\Invoice;
use Ridley\Laravel\Ridley;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @mixin Model
 */
trait ManagesInvoices
{
    /**
     * The customer's invoices, newest first (drafts excluded unless `$includePending`).
     *
     * @param  array<string, mixed>  $query
     * @return Collection<int, Invoice>
     */
    public function invoices(bool $includePending = false, array $query = []): Collection
    {
        if (! $this->hasRidleyId()) {
            return collect();
        }

        $filter = array_merge(['customer' => $this->ridleyId()], is_array($query['filter'] ?? null) ? $query['filter'] : []);

        return Ridley::client()->invoices()
            ->all(array_merge(['sort' => '-created_at', 'page' => ['size' => 100]], $query, ['filter' => $filter]))
            ->filter(fn (Invoice $invoice) => $includePending || ! $invoice->isDraft())
            ->values()
            ->collect();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Collection<int, Invoice>
     */
    public function invoicesIncludingPending(array $query = []): Collection
    {
        return $this->invoices(true, $query);
    }

    /**
     * Find an invoice that belongs to this customer.
     */
    public function findInvoice(string $id): ?Invoice
    {
        if (! $this->hasRidleyId()) {
            return null;
        }

        try {
            $invoice = Ridley::client()->invoices()->retrieve($id);
        } catch (NotFoundException) {
            return null;
        }

        return $invoice->customer === $this->ridleyId() ? $invoice : null;
    }

    /**
     * @throws NotFoundHttpException
     */
    public function findInvoiceOrFail(string $id): Invoice
    {
        return $this->findInvoice($id) ?? throw new NotFoundHttpException('Invoice not found.');
    }

    /**
     * Preview the customer's next invoice; null when there is nothing to invoice.
     *
     * @param  array<string, mixed>  $query  e.g. `subscription`, `subscription_items`.
     */
    public function upcomingInvoice(array $query = []): ?Invoice
    {
        if (! $this->hasRidleyId()) {
            return null;
        }

        try {
            return Ridley::client()->invoices()->upcoming(array_merge(['customer' => $this->ridleyId()], $query));
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * The invoice PDF bytes.
     */
    public function invoicePdf(string $id): string
    {
        $invoice = $this->findInvoiceOrFail($id);

        return Ridley::client()->invoices()->pdf((string) $invoice->id());
    }

    /**
     * Download the invoice PDF as an attachment (`{invoice_number}.pdf` by default).
     */
    public function downloadInvoice(string $id, ?string $filename = null): Response
    {
        $invoice = $this->findInvoiceOrFail($id);
        $pdf = Ridley::client()->invoices()->pdf((string) $invoice->id());

        $filename ??= ($invoice->number() ?? $invoice->id()).'.pdf';
        $filename = Str::endsWith(strtolower($filename), '.pdf') ? $filename : $filename.'.pdf';

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($pdf),
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $filename).'"',
            'Content-Transfer-Encoding' => 'binary',
        ]);
    }

    /**
     * Pay an open invoice now, optionally with a specific saved card.
     */
    public function payInvoice(string $id, ?string $paymentMethod = null): Invoice
    {
        $invoice = $this->findInvoiceOrFail($id);

        return Ridley::client()->invoices()->pay((string) $invoice->id(), array_filter(['payment_method' => $paymentMethod]));
    }
}
