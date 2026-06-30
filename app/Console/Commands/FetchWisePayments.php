<?php

namespace Crater\Console\Commands;

use Carbon\Carbon;
use Crater\Models\Invoice;
use Crater\Models\Payment;
use Crater\Models\PaymentMethod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Vinkla\Hashids\Facades\Hashids;

class FetchWisePayments extends Command
{
    protected $signature = 'fetch:wise:payments';

    protected $description = 'Fetch Wise payment emails from Microsoft Graph and mark matching invoices as paid.';

    public function handle()
    {
        $token = $this->getMicrosoftToken();

        if (! $token) {
            Log::error('FetchWisePayments: Failed to obtain Microsoft Graph token.');
            $this->error('Failed to obtain Microsoft Graph access token.');
            return 1;
        }

        $messages = $this->fetchWiseEmails($token);

        if (empty($messages)) {
            $this->info('No new Wise payment emails found.');
            return 0;
        }

        foreach ($messages as $message) {
            $this->processMessage($message, $token);
        }

        return 0;
    }

    private function getMicrosoftToken(): ?string
    {
        $tenantId = config('services.microsoft.tenant_id');
        $clientId = config('services.microsoft.client_id');
        $clientSecret = config('services.microsoft.client_secret');

        $response = Http::asForm()->post(
            "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
            [
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'scope'         => 'https://graph.microsoft.com/.default',
            ]
        );

        if (! $response->successful()) {
            Log::error('FetchWisePayments: Token request failed.', ['status' => $response->status(), 'body' => $response->body()]);
            $this->error('Token error '.$response->status().': '.$response->body());
            return null;
        }

        return $response->json('access_token');
    }

    private function fetchWiseEmails(string $token): array
    {
        $mailbox = config('services.microsoft.mailbox', 'mail@simson.one');

        // Fetch unread emails from Wise in the last 2 days to avoid reprocessing old ones
        $since = Carbon::now()->subDays(2)->toIso8601String();

        $filter = urlencode(
            "isRead eq false and startsWith(subject, 'Money received') and receivedDateTime ge {$since}"
        );

        $url = "https://graph.microsoft.com/v1.0/users/{$mailbox}/messages?\$filter={$filter}&\$select=id,subject,body,from,receivedDateTime&\$top=50";

        $response = Http::withToken($token)->get($url);

        if (! $response->successful()) {
            Log::error('FetchWisePayments: Failed to fetch emails.', ['body' => $response->body()]);
            return [];
        }

        return $response->json('value') ?? [];
    }

    private function processMessage(array $message, string $token): void
    {
        // Convert HTML body to plain text
        $html = $message['body']['content'] ?? '';
        $body = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = preg_replace('/[ \t]+/', ' ', $body);
        $body = preg_replace('/\n{3,}/', "\n\n", $body);

        $messageId = $message['id'];
        $subject   = $message['subject'] ?? '';

        $this->info("Processing: {$subject}");

        // Extract reference number between "Reference:" and "Transfer Number:"
        $reference = $this->extractReference($body);

        if (! $reference) {
            Log::info('FetchWisePayments: No reference found.', ['subject' => $subject]);
            $this->warn("No reference found in: {$subject}");
            $this->markEmailAsRead($messageId, $token);
            return;
        }

        // Strip last 2 control digits to get the invoice number digits
        $invoiceDigits = substr($reference, 0, -2);

        $invoice = $this->findInvoice($invoiceDigits);

        if (! $invoice) {
            Log::info('FetchWisePayments: No matching unpaid invoice.', ['reference' => $reference, 'digits' => $invoiceDigits]);
            $this->warn("No unpaid invoice matched reference digits: {$invoiceDigits}");
            $this->markEmailAsRead($messageId, $token);
            return;
        }

        $amount = $this->extractAmount($body) ?? $invoice->due_amount;

        $this->recordPayment($invoice, $amount);
        $this->markEmailAsRead($messageId, $token);

        $this->info("Invoice {$invoice->invoice_number} marked as paid (amount: {$amount}).");
        Log::info('FetchWisePayments: Invoice marked paid.', [
            'invoice_number' => $invoice->invoice_number,
            'reference'      => $reference,
            'amount'         => $amount,
        ]);
    }

    private function extractReference(string $body): ?string
    {
        // Match the number that sits between "Reference:" and "Transfer Number:"
        if (preg_match('/Reference:\s*(\d+)\s*Transfer\s+Number:/is', $body, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function findInvoice(string $invoiceDigits): ?Invoice
    {
        $stripped = ltrim($invoiceDigits, '0');

        return Invoice::whereIn('paid_status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIALLY_PAID])
            ->get()
            ->first(function (Invoice $invoice) use ($stripped) {
                $invoiceNum = ltrim(preg_replace('/\D/', '', $invoice->invoice_number), '0');
                return $invoiceNum === $stripped;
            });
    }

    private function extractAmount(string $body): ?float
    {
        // Match "You received 80 EUR from ..."
        if (preg_match('/You\s+received\s+([\d.,]+)\s*(EUR|USD|GBP|€|\$|£)\s+from/i', $body, $matches)) {
            return $this->parseAmount($matches[1]);
        }

        return null;
    }

    private function parseAmount(string $raw): float
    {
        // European format: 1.234,56 → 1234.56
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $raw)) {
            return (float) str_replace(['.', ','], ['', '.'], $raw);
        }

        // US format: 1,234.56 → 1234.56
        return (float) str_replace(',', '', $raw);
    }

    private function recordPayment(Invoice $invoice, float $amount): void
    {
        $bankTransferMethod = PaymentMethod::where('name', 'like', '%bank%')
            ->orWhere('name', 'like', '%transfer%')
            ->orWhere('name', 'like', '%wise%')
            ->first();

        $paymentData = [
            'payment_date'      => Carbon::today()->format('Y-m-d'),
            'amount'            => $amount,
            'customer_id'       => $invoice->customer_id,
            'invoice_id'        => $invoice->id,
            'company_id'        => $invoice->company_id,
            'notes'             => 'Auto-recorded from Wise payment email.',
            'payment_method_id' => $bankTransferMethod?->id,
        ];

        $payment = Payment::create($paymentData);
        $payment->unique_hash = Hashids::connection(Payment::class)->encode($payment->id);
        $payment->save();

        $invoice->subtractInvoicePayment($amount);
    }

    private function markEmailAsRead(string $messageId, string $token): void
    {
        $mailbox = config('services.microsoft.mailbox', 'mail@simson.one');

        Http::withToken($token)
            ->patch("https://graph.microsoft.com/v1.0/users/{$mailbox}/messages/{$messageId}", [
                'isRead' => true,
            ]);
    }
}
