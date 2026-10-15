<?php
// app/Services/PaystackService.php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\Patient;

class PaystackService
{
    private ?string $secretKey;
    private string $baseUrl = 'https://api.paystack.co';

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key') ?: null;
    }

    public function initializePayment(
        string $email,
        float $amount,
        string $reference,
        array $metadata = []
    ): array {
        if (!$this->secretKey) {
            return ['status' => false, 'message' => 'Payments are not configured yet.'];
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}",
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/transaction/initialize", [
                    'email' => $email,
                    'amount' => (int) ($amount * 100), // convert to kobo
                    'reference' => $reference,
                    'currency' => 'NGN',
                    'metadata' => $metadata,
                    'callback_url' => config('app.url') . '/api/v1/payments/callback',
                ]);

        return $response->json();
    }

    public function verifyPayment(string $reference): array
    {
        if (!$this->secretKey) {
            return ['status' => false, 'message' => 'Payments are not configured yet.'];
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}",
        ])->get("{$this->baseUrl}/transaction/verify/{$reference}");

        return $response->json() ?? ['status' => false];
    }

    // Add these methods to app/Services/PaystackService.php

    public function createCustomer(Patient $patient): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}",
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/customer", [
                    'email' => $patient->email,
                    'first_name' => $patient->first_name,
                    'last_name' => $patient->last_name,
                    'phone' => $patient->phone,
                ]);

        return $response->json();
    }

    public function getCustomer(string $emailOrCode): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}",
        ])->get("{$this->baseUrl}/customer/{$emailOrCode}");

        return $response->json();
    }

    public function createDedicatedAccount(string $customerCode, string $preferredBank = 'wema-bank'): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}",
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/dedicated_account", [
                    'customer' => $customerCode,
                    'preferred_bank' => $preferredBank,
                ]);

        return $response->json();
    }
}