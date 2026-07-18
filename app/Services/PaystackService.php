<?php
// app/Services/PaystackService.php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class PaystackService
{
    private string $secretKey;
    private string $baseUrl = 'https://api.paystack.co';

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key');
    }

    public function initializePayment(
        string $email,
        float $amount,
        string $reference,
        array $metadata = []
    ): array {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}",
            'Content-Type'  => 'application/json',
        ])->post("{$this->baseUrl}/transaction/initialize", [
            'email'     => $email,
            'amount'    => (int)($amount * 100), // convert to kobo
            'reference' => $reference,
            'currency'  => 'NGN',
            'metadata'  => $metadata,
            'callback_url' => config('app.url') . '/api/v1/payments/callback',
        ]);

        return $response->json();
    }

    public function verifyPayment(string $reference): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}",
        ])->get("{$this->baseUrl}/transaction/verify/{$reference}");

        return $response->json();
    }
}