import { Head, useForm } from '@inertiajs/react';
import { Fingerprint, KeyRound, ShieldCheck, Upload } from 'lucide-react';
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

type Props = {
    hasOffline: boolean;
    hasOnline: boolean;
    nip: string | null;
};

export default function KsefSetup({ hasOffline, hasOnline, nip }: Props) {
    const form = useForm<{
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

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.post('/ksef/setup', {
            forceFormData: true,
        });
    };

    return (
        <>
            <Head title="Konfiguracja KSeF" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-6">
                <section className="rounded-3xl bg-[radial-gradient(circle_at_top_left,_rgba(19,78,74,0.16),_transparent_40%),linear-gradient(135deg,_#f8fafc_0%,_#ecfeff_100%)] p-8 shadow-sm ring-1 ring-black/5">
                    <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div className="space-y-3">
                            <div className="inline-flex w-fit items-center gap-2 rounded-full bg-teal-950 px-3 py-1 text-xs font-medium tracking-wide text-teal-50">
                                <ShieldCheck className="h-4 w-4" />
                                Wymagany setup po logowaniu
                            </div>
                            <Heading
                                title="Dodaj certyfikaty KSeF"
                                description="Każdy użytkownik pracuje teraz na własnym profilu KSeF. Wymagane są certyfikaty offline i online. Zanim wejdziesz do modułu faktur, zapisz obydwa."
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
                        <CardTitle>Pliki certyfikatów offline i online</CardTitle>
                        <CardDescription>
                            Pliki zostaną zapisane z losowymi nazwami i przypisane do Twojego profilu. Każdy typ (offline/online) będzie przechowywany osobnie.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="grid gap-6">
                            {/* Offline Certificates */}
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
                                            accept=".crt,.cer,.pem"
                                            onChange={(event) =>
                                                form.setData('offline_certificate', event.target.files?.[0] ?? null)
                                            }
                                        />
                                        <InputError message={form.errors.offline_certificate} />
                                    </div>

                                    <div className="grid gap-2 rounded-lg border border-dashed border-slate-300 bg-white p-3">
                                        <Label htmlFor="offline_private_key">Klucz prywatny (offline)</Label>
                                        <Input
                                            id="offline_private_key"
                                            type="file"
                                            accept=".key,.pem"
                                            onChange={(event) =>
                                                form.setData('offline_private_key', event.target.files?.[0] ?? null)
                                            }
                                        />
                                        <InputError message={form.errors.offline_private_key} />
                                    </div>
                                </div>
                            </div>

                            {/* Online Certificates */}
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
                                            accept=".crt,.cer,.pem"
                                            onChange={(event) =>
                                                form.setData('online_certificate', event.target.files?.[0] ?? null)
                                            }
                                        />
                                        <InputError message={form.errors.online_certificate} />
                                    </div>

                                    <div className="grid gap-2 rounded-lg border border-dashed border-slate-300 bg-white p-3">
                                        <Label htmlFor="online_private_key">Klucz prywatny (online)</Label>
                                        <Input
                                            id="online_private_key"
                                            type="file"
                                            accept=".key,.pem"
                                            onChange={(event) =>
                                                form.setData('online_private_key', event.target.files?.[0] ?? null)
                                            }
                                        />
                                        <InputError message={form.errors.online_private_key} />
                                    </div>
                                </div>
                            </div>

                            <div className="md:col-span-2 flex flex-col gap-3 rounded-2xl bg-slate-950 p-4 text-sm text-slate-100">
                                <div className="flex items-center gap-2 font-medium">
                                    <KeyRound className="h-4 w-4" />
                                    Hasło do kluczy podasz dopiero przy łączeniu z KSeF
                                </div>
                                <p className="text-slate-300">
                                    Hasła do kluczy nie są zapisywane na sztywno w konfiguracji aplikacji. Będą wymagane przy każdym połączeniu z usługą KSeF (offline lub online).
                                </p>
                            </div>

                            <div className="md:col-span-2 flex items-center justify-between gap-4">
                                <div className="text-sm text-muted-foreground">
                                    {hasOffline && hasOnline
                                        ? 'Wgranie nowych plików zastąpi poprzednie aktywne certyfikaty.'
                                        : 'Po zapisaniu obydwu certyfikatów dostęp do dashboardu i modułu KSeF zostanie odblokowany.'}
                                </div>
                                <Button type="submit" disabled={form.processing} className="min-w-44">
                                    <Upload className="mr-2 h-4 w-4" />
                                    {form.processing ? 'Zapisywanie...' : 'Zapisz certyfikaty'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
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