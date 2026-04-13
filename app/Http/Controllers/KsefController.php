<?php

namespace App\Http\Controllers;

use App\Http\Requests\KsefMyInvoicesRequest;
use App\Services\KsefInvoiceService;
use App\Services\Contracts\KsefWorkspaceServiceInterface;
use App\Services\KsefService;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class KsefController extends Controller
{
    public function __construct(
        private KsefService $ksef,
        private KsefInvoiceService $invoiceService,
        private KsefWorkspaceServiceInterface $workspaceService,
    ) {}

    /**
     * Dashboard with KSeF connection status.
     */
    public function dashboard(): Response
    {
        $profile = $this->workspaceService->findProfileForUser(request()->user());
        $offline = $profile?->offlineCertificate;
        $online = $profile?->onlineCertificate;

        return Inertia::render('dashboard', [
            'ksefStatus' => $this->ksef->getStatus(),
            'certificatePanel' => [
                'hasOffline' => (bool) $offline,
                'hasOnline' => (bool) $online,
                'offline' => $offline ? [
                    'certFilename' => $offline->cert_filename,
                    'keyFilename' => $offline->key_filename,
                    'updatedAt' => $offline->updated_at?->format('Y-m-d H:i'),
                ] : null,
                'online' => $online ? [
                    'certFilename' => $online->cert_filename,
                    'keyFilename' => $online->key_filename,
                    'updatedAt' => $online->updated_at?->format('Y-m-d H:i'),
                ] : null,
            ],
        ]);
    }

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
     * Local DB invoices view (synced from KSeF).
     */
    public function myInvoices(KsefMyInvoicesRequest $request): Response
    {
        $filters = $request->validated();
        $paginator = $this->invoiceService->paginateForUser($request->user(), $filters);

        return Inertia::render('ksef/my-invoices', [
            'filters' => [
                'dateFrom' => $filters['dateFrom'] ?? null,
                'dateTo' => $filters['dateTo'] ?? null,
                'kind' => $filters['kind'] ?? '',
                'search' => $filters['search'] ?? '',
                'perPage' => $filters['perPage'] ?? 20,
            ],
            'invoices' => [
                'data' => $paginator->items(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Local DB invoices API.
     */
    public function myInvoicesData(KsefMyInvoicesRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $paginator = $this->invoiceService->paginateForUser($request->user(), $filters);

        return response()->json([
            'data' => $paginator->items(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
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
     * Keep current KSeF session alive on user activity.
     */
    public function keepAlive(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', 'in:offline,online'],
        ]);

        try {
            $meta = $this->ksef->keepAlive($request->input('type'));

            return response()->json([
                'success' => true,
                'validUntil' => $meta['validUntil'] ?? null,
                'type' => $meta['type'] ?? null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sesja KSeF wygasła. Połącz ponownie, podając hasło do klucza prywatnego.',
            ], 422);
        }
    }

    /**
     * Authenticate with KSeF API.
     */
    public function authenticate(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'in:offline,online'],
            'key_password' => ['required', 'string'],
        ]);

        try {
            $type = $request->string('type')->toString();
            $password = $request->string('key_password')->toString();
            $tokens = $this->ksef->authenticate($type, $password);

            // Automatyczne pobieranie nowych faktur po połączeniu
            $user = $request->user();
            $lastInvoice = \App\Models\KsefInvoice::where('user_id', $user->id)->orderByDesc('issue_date')->first();
            $dateFrom = $lastInvoice?->issue_date ? $lastInvoice->issue_date->format('Y-m-d') : now()->subYear()->format('Y-m-d');
            $dateTo = now()->format('Y-m-d');
            $filters = [
                'subjectType' => 'Subject1',
                'dateRange' => [
                    'dateType' => 'Invoicing',
                    'from' => $dateFrom . 'T00:00:00+00:00',
                    'to' => $dateTo . 'T23:59:59+00:00',
                ],
            ];
            try {
                $result = $this->ksef->searchInvoices($filters, 0, 100, 'Desc', $type);
                if (!empty($result['invoices'])) {
                    foreach ($result['invoices'] as $inv) {
                        \App\Models\KsefInvoice::updateOrCreate(
                            [
                                'user_id' => $user->id,
                                'ksef_id' => $inv['ksefReferenceNumber'],
                            ],
                            [
                                'reference_number' => $inv['referenceNumber'] ?? null,
                                'number' => $inv['invoiceNumber'] ?? null,
                                'issue_date' => $inv['invoiceIssueDate'] ?? null,
                                'sale_date' => $inv['invoiceSaleDate'] ?? null,
                                'due_date' => $inv['paymentDueDate'] ?? null,
                                'buyer_nip' => $inv['buyerNip'] ?? null,
                                'buyer_name' => $inv['buyerName'] ?? null,
                                'buyer_address' => $inv['buyerAddress'] ?? null,
                                'seller_nip' => $inv['sellerNip'] ?? null,
                                'seller_name' => $inv['sellerName'] ?? null,
                                'seller_address' => $inv['sellerAddress'] ?? null,
                                'total_gross' => $inv['totalGrossAmount'] ?? null,
                                'total_net' => $inv['totalNetAmount'] ?? null,
                                'total_vat' => $inv['totalVatAmount'] ?? null,
                                'currency' => $inv['currency'] ?? null,
                                'status' => 'new',
                                'raw_json' => json_encode($inv),
                            ]
                        );
                    }
                }
            } catch (\Throwable $syncError) {
                Log::warning('KSeF invoice sync after auth failed', [
                    'error' => $syncError->getMessage(),
                    'type' => $type,
                    'user_id' => $user->id,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Połączono z KSeF (' . $type . '). Nowe faktury zostały pobrane.',
                'validUntil' => $tokens['accessToken']['validUntil'] ?? null,
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $parsed = json_decode($body, true);
            $detail = $parsed['status']['description']
                ?? $parsed['message']
                ?? $parsed['exception']['exceptionDetailList'][0]['exceptionDescription']
                ?? $body;

            $apiDetails = $parsed['exception']['exceptionDetailList'][0]['details'] ?? null;
            if (is_array($apiDetails) && count($apiDetails) > 0) {
                $detail .= ' — ' . implode('; ', $apiDetails);
            }

            if (str_contains($detail, 'Brak przypisanych uprawnień')) {
                $detail .= ' (Sprawdź uprawnienia certyfikatu/NIP w KSeF dla wybranego typu: offline/online)';
            }

            if (str_contains($detail, 'Nieprawidłowy podpis')) {
                $detail .= ' (Sprawdź czy certyfikat i klucz są parą oraz czy hasło dotyczy tego klucza)';
            }

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
     * Clear cached KSeF session data.
     */
    public function clearSession(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', 'in:offline,online'],
        ]);

        $this->ksef->clearSession($request->input('type'));

        return response()->json([
            'success' => true,
            'message' => 'Sesja KSeF została skasowana.',
        ]);
    }

    /**
     * Search invoices by filters.
     */
    public function searchInvoices(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'nullable|in:offline,online',
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
                'Desc',
                $request->input('type', 'online'),
            );

            return response()->json($result);
        } catch (ClientException $e) {
            if ($e->getResponse()?->getStatusCode() === 429) {
                $body = $e->getResponse()->getBody()->getContents();
                $parsed = json_decode($body, true);
                $details = $parsed['status']['details'] ?? [];
                $detailText = is_array($details) && $details !== []
                    ? ' ' . implode(' ', $details)
                    : '';

                return response()->json([
                    'error' => 'Limit zapytań KSeF został przekroczony. Możesz wykonać maksymalnie 20 wyszukań na godzinę.' . $detailText,
                ], 429);
            }

            return response()->json([
                'error' => 'Błąd wyszukiwania: ' . $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Błąd wyszukiwania: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get single invoice XML.
     */
    public function getInvoice(Request $request, string $ksefNumber): \Illuminate\Http\Response|JsonResponse
    {
        try {
            $type = $request->input('type', 'online');
            $xml = $this->ksef->getInvoiceByKsefNumber($ksefNumber, $type);

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
    public function downloadInvoice(Request $request, string $ksefNumber): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        try {
            $type = $request->input('type', 'online');
            $xml = $this->ksef->getInvoiceByKsefNumber($ksefNumber, $type);

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
