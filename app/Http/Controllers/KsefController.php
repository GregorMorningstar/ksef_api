<?php

namespace App\Http\Controllers;

use App\Services\KsefService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class KsefController extends Controller
{
    public function __construct(
        private KsefService $ksef
    ) {}

    /**
     * Main KSeF invoices page.
     */
    public function index(): Response
    {
        return Inertia::render('ksef/invoices', [
            'status' => $this->ksef->getStatus(),
        ]);
    }

    /**
     * Get KSeF connection status.
     */
    public function status(): JsonResponse
    {
        return response()->json($this->ksef->getStatus());
    }

    /**
     * Authenticate with KSeF API.
     */
    public function authenticate(): JsonResponse
    {
        try {
            $tokens = $this->ksef->authenticate();

            return response()->json([
                'success' => true,
                'message' => 'Połączono z KSeF',
                'validUntil' => $tokens['accessToken']['validUntil'] ?? null,
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $parsed = json_decode($body, true);
            $detail = $parsed['status']['description'] ?? ($parsed['message'] ?? $body);

            Log::error('KSeF auth HTTP error', [
                'status' => $e->getResponse()?->getStatusCode(),
                'body' => $body,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Błąd KSeF API: ' . $detail,
            ], 422);
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Nie można połączyć z serwerem KSeF: ' . $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('KSeF auth error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Logout from KSeF.
     */
    public function logout(): JsonResponse
    {
        $this->ksef->logout();

        return response()->json([
            'success' => true,
            'message' => 'Rozłączono z KSeF',
        ]);
    }

    /**
     * Search invoices by filters.
     */
    public function searchInvoices(Request $request): JsonResponse
    {
        $request->validate([
            'dateFrom' => 'required|date',
            'dateTo' => 'required|date|after_or_equal:dateFrom',
            'subjectType' => 'required|in:Subject1,Subject2,Subject3,SubjectAuthorized',
            'pageOffset' => 'integer|min:0',
            'pageSize' => 'integer|min:10|max:250',
        ]);

        try {
            $filters = [
                'subjectType' => $request->input('subjectType', 'Subject1'),
                'dateRange' => [
                    'dateType' => 'Invoicing',
                    'from' => $request->input('dateFrom') . 'T00:00:00+00:00',
                    'to' => $request->input('dateTo') . 'T23:59:59+00:00',
                ],
            ];

            if ($request->filled('sellerNip')) {
                $filters['sellerNip'] = $request->input('sellerNip');
            }

            if ($request->filled('ksefNumber')) {
                $filters['ksefNumber'] = $request->input('ksefNumber');
            }

            if ($request->filled('invoiceNumber')) {
                $filters['invoiceNumber'] = $request->input('invoiceNumber');
            }

            $result = $this->ksef->searchInvoices(
                $filters,
                $request->input('pageOffset', 0),
                $request->input('pageSize', 50),
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Błąd wyszukiwania: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get single invoice XML.
     */
    public function getInvoice(string $ksefNumber): \Illuminate\Http\Response|JsonResponse
    {
        try {
            $xml = $this->ksef->getInvoiceByKsefNumber($ksefNumber);

            return response($xml, 200, [
                'Content-Type' => 'application/xml',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Błąd pobierania faktury: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Download invoice XML as file.
     */
    public function downloadInvoice(string $ksefNumber): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        try {
            $xml = $this->ksef->getInvoiceByKsefNumber($ksefNumber);

            return response()->streamDownload(function () use ($xml) {
                echo $xml;
            }, $ksefNumber . '.xml', [
                'Content-Type' => 'application/xml',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Błąd pobierania: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get session list.
     */
    public function sessions(Request $request): JsonResponse
    {
        try {
            $result = $this->ksef->getSessions(
                $request->input('type', 'Online'),
                $request->input('pageSize', 50),
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Błąd: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get invoices from a session.
     */
    public function sessionInvoices(string $referenceNumber): JsonResponse
    {
        try {
            $result = $this->ksef->getSessionInvoices($referenceNumber);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Błąd: ' . $e->getMessage(),
            ], 422);
        }
    }
}
