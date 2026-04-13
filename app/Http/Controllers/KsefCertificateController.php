<?php

namespace App\Http\Controllers;

use App\Http\Requests\KsefCertificateStoreRequest;
use App\Services\Contracts\KsefWorkspaceServiceInterface;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class KsefCertificateController extends Controller
{
    public function __construct(
        private readonly KsefWorkspaceServiceInterface $workspaceService,
    ) {}

    /**
     * Show the KSeF certificate setup page.
     */
    public function create(): Response
    {
        $profile = $this->workspaceService->findProfileForUser(request()->user());

        return Inertia::render('ksef/setup', [
            'hasOffline' => (bool) $profile?->offlineCertificate,
            'hasOnline' => (bool) $profile?->onlineCertificate,
            'nip' => $profile?->nip,
        ]);
    }

    /**
     * Store uploaded KSeF certificate files.
     */
    public function store(KsefCertificateStoreRequest $request): RedirectResponse
    {
        $offlineFiles = [
            'cert_path' => $request->file('offline_certificate')->getRealPath(),
            'key_path' => $request->file('offline_private_key')->getRealPath(),
            'cert_name' => $request->file('offline_certificate')->getClientOriginalName(),
            'key_name' => $request->file('offline_private_key')->getClientOriginalName(),
        ];

        $onlineFiles = [
            'cert_path' => $request->file('online_certificate')->getRealPath(),
            'key_path' => $request->file('online_private_key')->getRealPath(),
            'cert_name' => $request->file('online_certificate')->getClientOriginalName(),
            'key_name' => $request->file('online_private_key')->getClientOriginalName(),
        ];

        $this->workspaceService->storeCertificates($request->user(), $offlineFiles, $onlineFiles);

        return redirect()->route('dashboard')->with('status', 'Certyfikaty offline i online zostały zapisane.');
    }
}