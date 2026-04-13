<?php

namespace App\Http\Controllers;

use App\Http\Requests\KsefCertificateStoreRequest;
use App\Services\Contracts\KsefWorkspaceServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $offline = $profile?->offlineCertificate;
        $online = $profile?->onlineCertificate;

        return Inertia::render('ksef/setup', [
            'hasOffline' => (bool) $offline,
            'hasOnline' => (bool) $online,
            'offlineCert' => $offline ? [
                'cert_filename' => $offline->cert_filename,
                'key_filename' => $offline->key_filename,
                'cert_path' => $offline->cert_path,
                'key_path' => $offline->key_path,
                'updated_at' => $offline->updated_at?->format('Y-m-d H:i'),
            ] : null,
            'onlineCert' => $online ? [
                'cert_filename' => $online->cert_filename,
                'key_filename' => $online->key_filename,
                'cert_path' => $online->cert_path,
                'key_path' => $online->key_path,
                'updated_at' => $online->updated_at?->format('Y-m-d H:i'),
            ] : null,
            'nip' => $profile?->nip,
        ]);
    }

    /**
     * Store uploaded KSeF certificate files (initial setup – both offline + online required).
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

    /**
     * Replace a single certificate type (offline or online).
     */
    public function update(Request $request, string $type): RedirectResponse
    {
        abort_unless(in_array($type, ['offline', 'online']), 404);

        $request->validate([
            $type . '_certificate' => ['required', 'file'],
            $type . '_private_key' => ['required', 'file'],
        ]);

        $files = [
            'cert_path' => $request->file($type . '_certificate')->getRealPath(),
            'key_path' => $request->file($type . '_private_key')->getRealPath(),
            'cert_name' => $request->file($type . '_certificate')->getClientOriginalName(),
            'key_name' => $request->file($type . '_private_key')->getClientOriginalName(),
        ];

        $this->workspaceService->updateCertificate($request->user(), $files, $type);

        return back()->with('status', 'Certyfikat ' . $type . ' został zaktualizowany.');
    }

    /**
     * Delete certificate files and records for selected type.
     */
    public function destroy(Request $request, string $type): RedirectResponse
    {
        abort_unless(in_array($type, ['offline', 'online']), 404);

        $this->workspaceService->deleteCertificate($request->user(), $type);

        return back()->with('status', 'Certyfikat ' . $type . ' został usunięty.');
    }

    /**
     * Securely download a certificate or key file belonging to the authenticated user.
     */
    public function download(Request $request, string $type, string $fileType): StreamedResponse
    {
        abort_unless(in_array($type, ['offline', 'online']), 404);
        abort_unless(in_array($fileType, ['certificate', 'key']), 404);

        $profile = $this->workspaceService->findProfileForUser($request->user());
        $cert = $type === 'offline' ? $profile?->offlineCertificate : $profile?->onlineCertificate;

        abort_if(!$cert, 404);

        $path = $fileType === 'certificate' ? $cert->cert_path : $cert->key_path;
        $name = $fileType === 'certificate' ? $cert->cert_filename : $cert->key_filename;

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $name);
    }
}