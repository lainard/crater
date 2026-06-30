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
            Log::error('FetchWisePayments: Token request failed.', ['body' => $response->body()]);
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
            "isRead eq false and from/emailAddress/address eq 'no-reply@wise.com' and receivedDateTime ge {$since}"
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
        $body = strip_tags($message['body']['content'] ?? '');
        $messageId = $message['id'];
        $subject = $message['subject'] ?? '';

        $this->info("Processing: {$subject}");

        // Extract invoice number from email body — looks for patterns like INV-0001 or invoice number followed by digits
        $invoiceNumber = $this->extractInvoiceNumber($body, $subject);

        if (! $invoiceNumber) {
            Log::info('FetchWisePayments: No invoice number found.', ['subject' => $subject]);
            $this->warn("No invoice number found in: {$subject}");
            $this->markEmailAsRead($messageId, $token);
            return;
        }

        $invoice = Invoice::where('invoice_number', $invoiceNumber)
            ->whereIn('paid_status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIALLY_PAID])
            ->first();

        if (! $invoice) {
            Log::info('FetchWisePayments: No matching unpaid invoice found.', ['invoice_number' => $invoiceNumber]);
            $this->warn("No unpaid invoice found for: {$invoiceNumber}");
            $this->markEmailAsRead($messageId, $token);
            return;
        }

        $amount = $this->extractAmount($body);

        if (! $amount) {
            // Fall back to full invoice amount
            $amount = $invoice->due_amount;
        }

        $this->recordPayment($invoice, $amount);
        $this->markEmailAsRead($messageId, $token);

        $this->info("Invoice {$invoiceNumber} marked as paid (amount: {$amount}).");
        Log::info('FetchWisePayments: Invoice marked paid.', ['invoice_number' => $invoiceNumber, 'amount' => $amount]);
    }

    private function extractInvoiceNumber(string $body, string $subject): ?string
    {
        $text = $subject . ' ' . $body;

        // Match patterns like INV-0001, INV0001, or a bare reference number
        if (preg_match('/\b(INV[-\s]?\d+)\b/i', $text, $matches)) {
            return strtoupper(preg_replace('/\s+/', '', $matches[1]));
        }

        // Match "reference: XXX" or "ref: XXX" or "invoice number: XXX"
        if (preg_match('/(?:reference|ref|invoice\s+(?:number|no\.?|#))[:\s]+([A-Z0-9\-]+)/i', $text, $matches)) {
            return strtoupper(trim($matches[1]));
        }

        return null;
    }

    private function extractAmount(string $body): ?float
    {
        // Match currency amounts like "EUR 1,234.56" or "1234.56 EUR" or "€1234.56"
        if (preg_match('/(?:EUR|€|USD|\$|GBP|£)\s*([\d,]+\.?\d*)/i', $body, $matches)) {
            return (float) str_replace(',', '', $matches[1]);
        }

        if (preg_match('/([\d,]+\.?\d*)\s*(?:EUR|€|USD|\$|GBP|£)/i', $body, $matches)) {
            return (float) str_replace(',', '', $matches[1]);
        }

        return null;
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
