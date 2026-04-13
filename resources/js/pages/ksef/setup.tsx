import { Head, useForm } from '@inertiajs/react';
import { Download, Fingerprint, KeyRound, RefreshCw, ShieldCheck, Trash2, Upload } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type CertInfo = {
    cert_filename: string;
    key_filename: string;
    cert_path: string;
    key_path: string;
    updated_at: string;
};

type Props = {
    hasOffline: boolean;
    hasOnline: boolean;
    offlineCert: CertInfo | null;
    onlineCert: CertInfo | null;
    nip: string | null;
};

type CertType = 'offline' | 'online';

function ReplaceCertForm({ type }: { type: CertType }) {
    const certKey = `${type}_certificate` as const;
    const keyKey = `${type}_private_key` as const;
    const form = useForm<Record<string, File | null>>({
        [certKey]: null,
        [keyKey]: null,
    });

    const submit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        form.post(`/ksef/setup/${type}`, { forceFormData: true });
    };

    return (
        <form onSubmit={submit} className="space-y-3 border-t border-slate-200 pt-4 mt-4">
            <p className="text-xs font-medium text-slate-500 uppercase tracking-wide">
                Zastąp certyfikat {type}
            </p>
            <div className="grid gap-3 md:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor={`${type}_cert_new`}>Nowy certyfikat publiczny</Label>
                    <Input
                        id={`${type}_cert_new`}
                        type="file"
                        onChange={(e) => form.setData(certKey, e.target.files?.[0] ?? null)}
                    />
                    <InputError message={form.errors[certKey]} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${type}_key_new`}>Nowy klucz prywatny</Label>
                    <Input
                        id={`${type}_key_new`}
                        type="file"
                        onChange={(e) => form.setData(keyKey, e.target.files?.[0] ?? null)}
                    />
                    <InputError message={form.errors[keyKey]} />
                </div>
            </div>
            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={form.processing} variant="outline">
                    <RefreshCw className="mr-2 h-3.5 w-3.5" />
                    {form.processing ? 'Zapisywanie...' : 'Zastąp certyfikat'}
                </Button>
            </div>
        </form>
    );
}

function CertSection({
    type,
    color,
    certInfo,
}: {
    type: CertType;
    color: 'blue' | 'green';
    certInfo: CertInfo | null;
}) {
    const accentCls = color === 'blue' ? 'text-blue-600' : 'text-green-600';
    const label = type === 'offline' ? 'Offline' : 'Online';
    const hasCertificate = Boolean(certInfo);
    const deleteForm = useForm({});

    const handleDelete = () => {
        if (!hasCertificate || deleteForm.processing) {
            return;
        }

        const confirmed = window.confirm(`Czy na pewno chcesz usunąć certyfikat ${label}?`);
        if (!confirmed) {
            return;
        }

        deleteForm.delete(`/ksef/setup/${type}`);
    };

    return (
        <div className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/50 p-4">
            <div className="flex items-center gap-2 font-semibold text-slate-900">
                <ShieldCheck className={`h-5 w-5 ${accentCls}`} />
                Certyfikat {label}
            </div>

            {certInfo ? (
                <>
                    <div className="rounded-xl bg-white border border-slate-200 p-3 text-sm space-y-2">
                        <div className="flex items-center justify-between gap-4 flex-wrap">
                            <div className="space-y-0.5">
                                <div className="text-muted-foreground text-xs">Certyfikat publiczny</div>
                                <div className="font-mono text-xs font-medium text-slate-800">
                                    {certInfo.cert_filename}
                                </div>
                            </div>
                            <a
                                href={`/ksef/setup/${type}/certificate`}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
                            >
                                <Download className="h-3.5 w-3.5" />
                                Pobierz
                            </a>
                        </div>
                        <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            Ścieżka w bazie: {certInfo.cert_path}
                        </div>
                        <div className="flex items-center justify-between gap-4 flex-wrap border-t border-slate-100 pt-2">
                            <div className="space-y-0.5">
                                <div className="text-muted-foreground text-xs">Klucz prywatny</div>
                                <div className="font-mono text-xs font-medium text-slate-800">
                                    {certInfo.key_filename}
                                </div>
                            </div>
                            <a
                                href={`/ksef/setup/${type}/key`}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
                            >
                                <Download className="h-3.5 w-3.5" />
                                Pobierz
                            </a>
                        </div>
                        <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            Ścieżka w bazie: {certInfo.key_path}
                        </div>
                        <div className="text-xs text-muted-foreground border-t border-slate-100 pt-2">
                            Zaktualizowano: {certInfo.updated_at}
                        </div>
                    </div>
                </>
            ) : (
                <p className="text-sm text-muted-foreground italic">Brak certyfikatu dla tego typu.</p>
            )}

            <ReplaceCertForm type={type} />

            {hasCertificate ? (
                <div className="flex justify-end border-t border-slate-200 pt-4">
                    <Button
                        type="button"
                        size="sm"
                        variant="destructive"
                        onClick={handleDelete}
                        disabled={deleteForm.processing}
                    >
                        <Trash2 className="mr-2 h-3.5 w-3.5" />
                        {deleteForm.processing ? 'Usuwanie...' : `Usuń certyfikat ${label}`}
                    </Button>
                </div>
            ) : null}

            {!hasCertificate ? (
                <p className="text-xs text-muted-foreground">
                    Po zapisaniu pliki trafią do katalogu `storage/app/private/ksef/certificate/...`.
                </p>
            ) : null}
        </div>
    );
}

export default function KsefSetup({ hasOffline, hasOnline, offlineCert, onlineCert, nip }: Props) {
    const initialForm = useForm<{
        offline_certificate: File | null;
        offline_private_key: File | null;
        online_certificate: File | null;
        online_private_key: File | null;
    }>({
        offline_certificate: null,
        offline_private_key: null,
        online_certificate: null,
        online_private_key: null,
    });

    const submitInitial = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        initialForm.post('/ksef/setup', { forceFormData: true });
    };

    const bothPresent = hasOffline && hasOnline;
    const hasAnyCertificate = hasOffline || hasOnline;

    return (
        <>
            <Head title="Konfiguracja KSeF" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-6">
                <section className="rounded-3xl bg-[radial-gradient(circle_at_top_left,_rgba(19,78,74,0.16),_transparent_40%),linear-gradient(135deg,_#f8fafc_0%,_#ecfeff_100%)] p-8 shadow-sm ring-1 ring-black/5">
                    <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div className="space-y-3">
                            <div className="inline-flex w-fit items-center gap-2 rounded-full bg-teal-950 px-3 py-1 text-xs font-medium tracking-wide text-teal-50">
                                <ShieldCheck className="h-4 w-4" />
                                {bothPresent ? 'Zarządzanie certyfikatami' : 'Wymagany setup po logowaniu'}
                            </div>
                            <Heading
                                title={bothPresent ? 'Certyfikaty KSeF' : 'Dodaj certyfikaty KSeF'}
                                description={
                                    bothPresent
                                        ? 'Możesz pobrać lub zastąpić certyfikaty offline i online niezależnie. Stare pliki zostaną usunięte z dysku.'
                                        : 'Każdy użytkownik pracuje na własnym profilu KSeF. Wymagane są certyfikaty offline i online.'
                                }
                            />
                        </div>
                        <div className="rounded-2xl border border-teal-200/60 bg-white/80 px-4 py-3 text-sm shadow-sm backdrop-blur">
                            <div className="text-muted-foreground">Profil użytkownika</div>
                            <div className="mt-1 flex items-center gap-2 font-mono text-base font-semibold text-teal-950">
                                <Fingerprint className="h-4 w-4" />
                                {nip ?? 'Brak NIP'}
                            </div>
                        </div>
                    </div>
                </section>

                <Card className="border-0 shadow-sm ring-1 ring-black/5">
                    <CardHeader>
                        <CardTitle>{hasAnyCertificate ? 'Zapisane certyfikaty' : 'Dodaj certyfikaty'}</CardTitle>
                        <CardDescription>
                            Podgląd działa per typ certyfikatu. Pobieranie używa ścieżki zapisanej w bazie danych dla zalogowanego użytkownika.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-6">
                        <CertSection type="offline" color="blue" certInfo={offlineCert} />
                        <CertSection type="online" color="green" certInfo={onlineCert} />
                        <div className="flex items-center gap-2 rounded-2xl bg-slate-950 p-4 text-sm text-slate-300">
                            <KeyRound className="h-4 w-4 shrink-0 text-slate-400" />
                            Hasło do kluczy podasz przy łączeniu z KSeF – nie jest tutaj wymagane.
                        </div>
                    </CardContent>
                </Card>

                {!bothPresent ? (
                    <Card className="border-0 shadow-sm ring-1 ring-black/5">
                        <CardHeader>
                            <CardTitle>Pliki certyfikatów offline i online</CardTitle>
                            <CardDescription>
                                Pliki zostaną zapisane z losowymi nazwami i przypisane do Twojego profilu.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitInitial} className="grid gap-6">
                                <div className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/50 p-4">
                                    <div className="flex items-center gap-2 font-semibold text-slate-900">
                                        <ShieldCheck className="h-5 w-5 text-blue-600" />
                                        Certyfikat Offline
                                    </div>
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="grid gap-2 rounded-lg border border-dashed border-slate-300 bg-white p-3">
                                            <Label htmlFor="offline_certificate">Certyfikat publiczny (offline)</Label>
                                            <Input
                                                id="offline_certificate"
                                                type="file"
                                                onChange={(e) =>
                                                    initialForm.setData('offline_certificate', e.target.files?.[0] ?? null)
                                                }
                                            />
                                            <InputError message={initialForm.errors.offline_certificate} />
                                        </div>
                                        <div className="grid gap-2 rounded-lg border border-dashed border-slate-300 bg-white p-3">
                                            <Label htmlFor="offline_private_key">Klucz prywatny (offline)</Label>
                                            <Input
                                                id="offline_private_key"
                                                type="file"
                                                onChange={(e) =>
                                                    initialForm.setData('offline_private_key', e.target.files?.[0] ?? null)
                                                }
                                            />
                                            <InputError message={initialForm.errors.offline_private_key} />
                                        </div>
                                    </div>
                                </div>

                                <div className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/50 p-4">
                                    <div className="flex items-center gap-2 font-semibold text-slate-900">
                                        <ShieldCheck className="h-5 w-5 text-green-600" />
                                        Certyfikat Online
                                    </div>
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="grid gap-2 rounded-lg border border-dashed border-slate-300 bg-white p-3">
                                            <Label htmlFor="online_certificate">Certyfikat publiczny (online)</Label>
                                            <Input
                                                id="online_certificate"
                                                type="file"
                                                onChange={(e) =>
                                                    initialForm.setData('online_certificate', e.target.files?.[0] ?? null)
                                                }
                                            />
                                            <InputError message={initialForm.errors.online_certificate} />
                                        </div>
                                        <div className="grid gap-2 rounded-lg border border-dashed border-slate-300 bg-white p-3">
                                            <Label htmlFor="online_private_key">Klucz prywatny (online)</Label>
                                            <Input
                                                id="online_private_key"
                                                type="file"
                                                onChange={(e) =>
                                                    initialForm.setData('online_private_key', e.target.files?.[0] ?? null)
                                                }
                                            />
                                            <InputError message={initialForm.errors.online_private_key} />
                                        </div>
                                    </div>
                                </div>

                                <div className="flex items-center gap-2 rounded-2xl bg-slate-950 p-4 text-sm text-slate-300">
                                    <KeyRound className="h-4 w-4 shrink-0 text-slate-400" />
                                    Hasło do kluczy podasz dopiero przy łączeniu z KSeF – nie jest tutaj wymagane.
                                </div>

                                <div className="flex items-center justify-between gap-4">
                                    <div className="text-sm text-muted-foreground">
                                        Po zapisaniu obydwu certyfikatów dostęp do dashboardu zostanie odblokowany.
                                    </div>
                                    <Button type="submit" disabled={initialForm.processing} className="min-w-44">
                                        <Upload className="mr-2 h-4 w-4" />
                                        {initialForm.processing ? 'Zapisywanie...' : 'Zapisz certyfikaty'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                ) : null}
            </div>
        </>
    );
}

KsefSetup.layout = {
    breadcrumbs: [
        {
            title: 'Konfiguracja KSeF',
            href: '/ksef/setup',
        },
    ],
};