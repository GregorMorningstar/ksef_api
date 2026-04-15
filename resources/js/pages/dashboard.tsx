import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import { FileText, DollarSign, CheckCircle2, LogIn, LogOut, KeyRound, Loader2, Wifi, WifiOff, ShieldCheck, Download, Settings2, Search, Trash2, Eye, ChevronRight } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { useCallback, useEffect, useRef, useState } from 'react';

type KsefStatus = {
    configured: boolean;
    authenticated: boolean;
    nip: string | null;
    session_type?: 'offline' | 'online' | null;
    session_valid_until?: string | null;
};

type InvoiceMetadata = {
    ksefNumber?: string;
    ksefReferenceNumber?: string;
    ksef_id?: string;
    invoiceNumber?: string;
    number?: string;
    invoicingDate?: string;
    invoicing_date?: string;
    acquisitionDate?: string;
    acquisition_date?: string;
    sellerName?: string;
    seller_name?: string;
    sellerNip?: string;
    seller_nip?: string;
    buyerName?: string;
    buyerNip?: string;
    grossValue?: number;
    netValue?: number;
    vatValue?: number;
    totalGrossAmount?: number;
    totalNetAmount?: number;
    totalVatAmount?: number;
    total_gross?: number;
    total_net?: number;
    total_vat?: number;
    currency?: string;
    invoiceType?: string;
    invoice_type?: string;
    formType?: string;
    status?: string;
};

type CertificateInfo = {
    certFilename: string;
    keyFilename: string;
    updatedAt: string | null;
};

type CertificatePanel = {
    hasOffline: boolean;
    hasOnline: boolean;
    offline: CertificateInfo | null;
    online: CertificateInfo | null;
};

type Props = {
    ksefStatus: KsefStatus;
    certificatePanel: CertificatePanel;
};

const toNumber = (value: unknown): number => {
    if (typeof value === 'number') {
        return value;
    }
    if (typeof value === 'string' && value !== '') {
        const parsed = Number(value);
        return Number.isNaN(parsed) ? 0 : parsed;
    }
    return 0;
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'accepted': return 'bg-green-100 text-green-800';
        case 'pending': return 'bg-yellow-100 text-yellow-800';
        case 'rejected': return 'bg-red-100 text-red-800';
        default: return 'bg-gray-100 text-gray-800';
    }
};

const getStatusLabel = (status: string) => {
    switch (status) {
        case 'accepted': return 'Zaakceptowana';
        case 'pending': return 'Oczekująca';
        case 'rejected': return 'Odrzucona';
        default: return status;
    }
};

const formatDate = (dateStr: string | null | undefined): string => {
    if (!dateStr) return '-';
    try {
        const date = new Date(dateStr);
        if (Number.isNaN(date.getTime())) return '-';
        return new Intl.DateTimeFormat('pl-PL', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        }).format(date);
    } catch {
        return '-';
    }
};

const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

function CertificateTypeCard({
    type,
    info,
}: {
    type: 'offline' | 'online';
    info: CertificateInfo | null;
}) {
    const label = type === 'offline' ? 'Offline' : 'Online';
    const accent = type === 'offline' ? 'text-blue-700 bg-blue-50' : 'text-green-700 bg-green-50';

    return (
        <div className="rounded-xl border border-slate-200 bg-white/80 p-4">
            <div className="mb-3 flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <div className={`rounded-full px-2.5 py-1 text-xs font-semibold ${accent}`}>
                        {label}
                    </div>
                    <span className="text-sm text-muted-foreground">
                        {info ? 'Certyfikat zapisany' : 'Brak certyfikatu'}
                    </span>
                </div>
                {info ? (
                    <Badge className="bg-emerald-100 text-emerald-800 hover:bg-emerald-100">Aktywny</Badge>
                ) : (
                    <Badge className="bg-amber-100 text-amber-800 hover:bg-amber-100">Wymagany</Badge>
                )}
            </div>

            {info ? (
                <div className="space-y-3 text-sm">
                    <div>
                        <div className="text-xs text-muted-foreground">Certyfikat</div>
                        <div className="font-mono text-xs font-medium text-slate-800">{info.certFilename}</div>
                    </div>
                    <div>
                        <div className="text-xs text-muted-foreground">Klucz</div>
                        <div className="font-mono text-xs font-medium text-slate-800">{info.keyFilename}</div>
                    </div>
                    <div className="text-xs text-muted-foreground">Zaktualizowano: {info.updatedAt ?? 'brak danych'}</div>
                    <div className="flex flex-wrap gap-2 pt-1">
                        <Button asChild size="sm" variant="outline">
                            <a href={`/ksef/setup/${type}/certificate`}>
                                <Download className="mr-2 h-3.5 w-3.5" />
                                Pobierz certyfikat
                            </a>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <a href={`/ksef/setup/${type}/key`}>
                                <Download className="mr-2 h-3.5 w-3.5" />
                                Pobierz klucz
                            </a>
                        </Button>
                    </div>
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Dodaj ten certyfikat na ekranie konfiguracji KSeF, aby odblokować pełną obsługę połączenia.
                </p>
            )}
        </div>
    );
}

export default function Dashboard({ ksefStatus: initialStatus, certificatePanel }: Props) {
    const [status, setStatus] = useState<KsefStatus>(initialStatus);
    const [keyPassword, setKeyPassword] = useState('');
    const [authType, setAuthType] = useState<'offline' | 'online'>(
        initialStatus.session_type === 'offline' || initialStatus.session_type === 'online'
            ? initialStatus.session_type
            : (certificatePanel.hasOffline ? 'offline' : 'online')
    );
    const [authLoading, setAuthLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [successMsg, setSuccessMsg] = useState<string | null>(null);
    const [sessionValidUntil, setSessionValidUntil] = useState<string | null>(initialStatus.session_valid_until ?? null);
    const [sessionSecondsLeft, setSessionSecondsLeft] = useState<number | null>(null);
    const [invoices, setInvoices] = useState<InvoiceMetadata[]>([]);
    const [invoiceLoading, setInvoiceLoading] = useState(false);
    const [dateFrom, setDateFrom] = useState(() => {
        const d = new Date();
        d.setDate(d.getDate() - 30);
        return d.toISOString().split('T')[0];
    });
    const [dateTo, setDateTo] = useState(() => new Date().toISOString().split('T')[0]);
    const [subjectType, setSubjectType] = useState<'Subject1' | 'Subject2' | 'Subject3' | 'SubjectAuthorized'>('Subject1');
    const [newInvoiceLoading, setNewInvoiceLoading] = useState(false);
    const [lastNewCount, setLastNewCount] = useState<number | null>(null);
    const [currentInvoicePage, setCurrentInvoicePage] = useState(1);
    const hasFetchedForCurrentSession = useRef(initialStatus.authenticated);

    const itemsPerPage = 9;
    const totalPages = Math.ceil(invoices.length / itemsPerPage);
    const paginatedInvoices = invoices.slice(
        (currentInvoicePage - 1) * itemsPerPage,
        currentInvoicePage * itemsPerPage
    );

    const stats = {
        totalInvoices: invoices.length,
        totalAmount: invoices.reduce((sum, inv) => sum + toNumber(inv.total_net ?? inv.netValue ?? inv.totalNetAmount), 0),
        totalVat: invoices.reduce((sum, inv) => sum + toNumber(inv.total_vat ?? inv.vatValue ?? inv.totalVatAmount), 0),
        acceptedCount: invoices.filter((inv) => String(inv.status ?? '').toLowerCase() === 'accepted').length,
    };

    const fetchInvoices = useCallback(async () => {
        if (!status.authenticated) {
            setInvoices([]);
            return;
        }

        setInvoiceLoading(true);
        setError(null);
        try {
            const res = await fetch('/ksef/invoices/check', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    type: authType,
                    dateFrom,
                    dateTo,
                    subjectType,
                    pageOffset: 0,
                    pageSize: 50,
                }),
            });

            const data = await res.json();
            if (data.error) {
                setError(data.error);
                setInvoices([]);
                return;
            }

            setInvoices(Array.isArray(data.data) ? data.data : []);
            setCurrentInvoicePage(1); // Reset to first page
            if (data.synced) {
                setSuccessMsg(`Sprawdzono KSeF: pobrano ${data.synced.fetched ?? 0}, zapisano ${data.synced.saved ?? 0}.`);
            }
        } catch {
            setError('Nie udało się pobrać faktur z KSeF');
            setInvoices([]);
        } finally {
            setInvoiceLoading(false);
        }
    }, [authType, dateFrom, dateTo, subjectType, status.authenticated]);

    const handleSyncNewInvoices = useCallback(async () => {
        if (!status.authenticated) {
            setError('Musisz być połączony z KSeF, aby zsynchronizować nowe faktury.');
            return;
        }

        setNewInvoiceLoading(true);
        setError(null);
        setLastNewCount(null);
        try {
            const res = await fetch('/ksef/invoices/check-new', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    type: authType,
                }),
            });

            const data = await res.json();
            if (data.error) {
                setError(data.error);
                return;
            }

            if (data.success) {
                const count = data.newCount ?? data.synced?.saved ?? 0;
                setLastNewCount(count);
                setSuccessMsg(`Nowe faktury pobrane: zapisano ${count}.`);
                // Refresh invoice list with full check to show new ones
                void fetchInvoices();
            }
        } catch {
            setError('Nie udało się pobrać nowych faktur z KSeF');
        } finally {
            setNewInvoiceLoading(false);
        }
    }, [authType, status.authenticated, fetchInvoices]);

    const keepSessionAlive = useCallback(async () => {
        if (!status.authenticated) {
            return;
        }

        try {
            const res = await fetch('/ksef/keep-alive', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ type: authType }),
            });

            const data = await res.json();
            if (data.success) {
                if (data.validUntil) {
                    setSessionValidUntil(data.validUntil);
                }
                if (data.type === 'offline' || data.type === 'online') {
                    setAuthType(data.type);
                }
            } else {
                setStatus((s) => ({ ...s, authenticated: false }));
                setSessionValidUntil(null);
            }
        } catch {
            // keep-alive is best effort
        }
    }, [authType, status.authenticated]);

    // Refresh session time on page reload
    useEffect(() => {
        if (!status.authenticated) {
            return;
        }

        const refreshSessionTime = async () => {
            try {
                const res = await fetch('/ksef/status', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                    },
                });

                const data = await res.json();
                if (data.authenticated && data.session_valid_until) {
                    setSessionValidUntil(data.session_valid_until);
                }
            } catch {
                // Status check failed, but continue anyway
            }
        };

        void refreshSessionTime();
    }, []); // Run once on mount

    useEffect(() => {
        if (!sessionValidUntil) {
            setSessionSecondsLeft(null);
            return;
        }

        const updateLeft = () => {
            const endMs = new Date(sessionValidUntil).getTime();
            if (Number.isNaN(endMs)) {
                setSessionSecondsLeft(null);
                return;
            }

            const left = Math.max(0, Math.floor((endMs - Date.now()) / 1000));
            setSessionSecondsLeft(left);
        };

        updateLeft();
        const timer = window.setInterval(updateLeft, 1000);
        return () => window.clearInterval(timer);
    }, [sessionValidUntil]);

    useEffect(() => {
        if (status.authenticated && sessionSecondsLeft === 0) {
            setStatus((s) => ({ ...s, authenticated: false }));
            setSessionValidUntil(null);
            setError('Sesja KSeF wygasła. Połącz ponownie, podając hasło do klucza prywatnego.');
        }
    }, [sessionSecondsLeft, status.authenticated]);

    useEffect(() => {
        if (!status.authenticated) {
            hasFetchedForCurrentSession.current = false;
            return;
        }

        if (hasFetchedForCurrentSession.current) {
            return;
        }

        hasFetchedForCurrentSession.current = true;
        void fetchInvoices();
    }, [status.authenticated, fetchInvoices]);

    useEffect(() => {
        if (!status.authenticated) {
            return;
        }

        let lastPing = 0;
        const ping = () => {
            const now = Date.now();
            if (now - lastPing < 30000) {
                return;
            }
            lastPing = now;
            void keepSessionAlive();
        };

        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                ping();
            }
        };

        window.addEventListener('mousemove', ping);
        window.addEventListener('keydown', ping);
        window.addEventListener('click', ping);
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            window.removeEventListener('mousemove', ping);
            window.removeEventListener('keydown', ping);
            window.removeEventListener('click', ping);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [keepSessionAlive, status.authenticated]);

    const formatSessionLeft = (seconds: number | null) => {
        if (seconds === null) {
            return 'brak danych';
        }

        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;

        return `${hours.toString().padStart(2, '0')}:${minutes
            .toString()
            .padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    };

    const handleAuth = useCallback(async () => {
        if (!keyPassword.trim()) return;
        setError(null);
        setSuccessMsg(null);
        setAuthLoading(true);
        try {
            const res = await fetch('/ksef/authenticate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ type: authType, key_password: keyPassword }),
            });
            const data = await res.json();
            if (data.success) {
                setSuccessMsg(data.message ?? `Połączono z KSeF (${authType})`);
                setStatus((s) => ({ ...s, authenticated: true }));
                setSessionValidUntil(data.validUntil ?? null);
                setKeyPassword('');
            } else {
                setError(data.message ?? `Błąd KSeF (HTTP ${res.status})`);
            }
        } catch {
            setError('Błąd połączenia z serwerem — sprawdź czy serwer działa');
        } finally {
            setAuthLoading(false);
        }
    }, [authType, fetchInvoices, keyPassword]);

    const handleLogout = useCallback(async () => {
        setError(null);
        setSuccessMsg(null);
        try {
            const res = await fetch('/ksef/logout', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
            });
            const data = await res.json();
            if (data.success) {
                setSuccessMsg(data.message);
                setStatus((s) => ({ ...s, authenticated: false }));
                setSessionValidUntil(null);
                setSessionSecondsLeft(null);
                setInvoices([]);
            }
        } catch {
            setError('Błąd rozłączania');
        }
    }, []);

    const handleClearSession = useCallback(async () => {
        setError(null);
        setSuccessMsg(null);
        try {
            const res = await fetch('/ksef/session/clear', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ type: authType }),
            });

            const data = await res.json();
            if (data.success) {
                setSuccessMsg(data.message ?? 'Sesja KSeF została skasowana.');
            } else {
                setError(data.message ?? 'Nie udało się skasować sesji.');
            }

            // Always reset dashboard timer/session view after explicit user action.
            setStatus((s) => ({ ...s, authenticated: false }));
            setSessionValidUntil(null);
            setSessionSecondsLeft(null);
            setInvoices([]);
        } catch {
            setStatus((s) => ({ ...s, authenticated: false }));
            setSessionValidUntil(null);
            setSessionSecondsLeft(null);
            setInvoices([]);
            setError('Sesja lokalna została wyczyszczona, ale serwer nie odpowiedział.');
        }
    }, [authType]);

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div className="space-y-2">
                    <Heading
                        title="Dashboard KSeF"
                        description="Przegląd pobranych faktur i statystyki"
                    />
                </div>

                {/* KSeF Connection Panel */}
                <Card className={`border-0 shadow-sm ring-1 ${status.authenticated ? 'ring-green-300 bg-green-50/40' : 'ring-black/5'}`}>
                    <CardContent className="pt-5">
                        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                            {/* Status */}
                            <div className="flex items-center gap-3">
                                {status.authenticated ? (
                                    <>
                                        <div className="flex h-9 w-9 items-center justify-center rounded-full bg-green-100">
                                            <Wifi className="h-4 w-4 text-green-600" />
                                        </div>
                                        <div>
                                            <div className="font-semibold text-green-800">Połączono z KSeF</div>
                                            <div className="text-xs text-green-600">Sesja aktywna · NIP: {status.nip}</div>
                                            <div className="text-xs text-green-700">
                                                Sesja wygasa za: {formatSessionLeft(sessionSecondsLeft)}
                                            </div>
                                        </div>
                                    </>
                                ) : (
                                    <>
                                        <div className="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100">
                                            <WifiOff className="h-4 w-4 text-slate-500" />
                                        </div>
                                        <div>
                                            <div className="font-semibold text-slate-900">Nie połączono z KSeF</div>
                                            <div className="text-xs text-muted-foreground">Wybierz typ certyfikatu i podaj hasło do klucza prywatnego</div>
                                        </div>
                                    </>
                                )}
                            </div>

                            {/* Action */}
                            {status.authenticated ? (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={handleClearSession}
                                        className="border-orange-200 text-orange-700 hover:bg-orange-50 hover:text-orange-800"
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" />
                                        Kasuj sesję
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={handleLogout}
                                        className="border-red-200 text-red-600 hover:bg-red-50 hover:text-red-700"
                                    >
                                        <LogOut className="mr-2 h-4 w-4" />
                                        Rozłącz
                                    </Button>
                                </div>
                            ) : (
                                <div className="flex items-end gap-2">
                                    <div className="grid gap-1">
                                        <Label htmlFor="dashboard-auth-type" className="text-xs">
                                            Typ certyfikatu
                                        </Label>
                                        <Select value={authType} onValueChange={(value: 'offline' | 'online') => setAuthType(value)}>
                                            <SelectTrigger id="dashboard-auth-type" className="h-8 w-36 text-sm">
                                                <SelectValue placeholder="Wybierz typ" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="offline" disabled={!certificatePanel.hasOffline}>
                                                    Offline
                                                </SelectItem>
                                                <SelectItem value="online" disabled={!certificatePanel.hasOnline}>
                                                    Online
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor="dashboard-key-password" className="flex items-center gap-1 text-xs">
                                            <KeyRound className="h-3 w-3" />
                                            Hasło do klucza prywatnego
                                        </Label>
                                        <Input
                                            id="dashboard-key-password"
                                            type="password"
                                            placeholder="••••••••"
                                            value={keyPassword}
                                            onChange={(e) => setKeyPassword(e.target.value)}
                                            onKeyDown={(e) => e.key === 'Enter' && handleAuth()}
                                            className="h-8 w-64 text-sm"
                                        />
                                    </div>
                                    <Button
                                        size="sm"
                                        onClick={handleAuth}
                                        disabled={authLoading || !keyPassword.trim() || (authType === 'offline' ? !certificatePanel.hasOffline : !certificatePanel.hasOnline)}
                                        className="bg-teal-700 hover:bg-teal-800"
                                    >
                                        {authLoading ? (
                                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        ) : (
                                            <LogIn className="mr-2 h-4 w-4" />
                                        )}
                                        {authLoading ? 'Łączenie...' : 'Połącz z KSeF'}
                                    </Button>
                                </div>
                            )}
                        </div>

                        {/* Feedback messages */}
                        {error && (
                            <div className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
                                {error}
                            </div>
                        )}
                        {successMsg && (
                            <div className="mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700 ring-1 ring-green-200">
                                {successMsg}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card className="border-0 shadow-sm ring-1 ring-black/5">
                    <CardHeader className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div className="space-y-1.5">
                            <CardTitle className="flex items-center gap-2">
                                <ShieldCheck className="h-5 w-5 text-teal-700" />
                                <span>Zarządzanie certyfikatami <span className="ml-2 rounded bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700">KSeF</span></span>
                            </CardTitle>
                            <p className="text-sm text-muted-foreground">
                                Tutaj możesz zarządzać swoimi certyfikatami do podpisu i połączenia z KSeF.<br />
                                <span className="text-xs text-blue-700">Faktury są pobierane i wyszukiwane bezpośrednio w KSeF.</span>
                            </p>
                        </div>
                        <Button asChild className="bg-teal-700 hover:bg-teal-800" title="Przejdź do panelu certyfikatów">
                            <Link href="/ksef/setup">
                                <Settings2 className="mr-2 h-4 w-4" />
                                Otwórz panel certyfikatów
                            </Link>
                        </Button>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-2">
                        <CertificateTypeCard type="offline" info={certificatePanel.offline} />
                        <CertificateTypeCard type="online" info={certificatePanel.online} />
                        <div className="md:col-span-2 flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-700 ring-1 ring-slate-200">
                            <span>
                                Status kompletu: {certificatePanel.hasOffline && certificatePanel.hasOnline ? 'offline + online gotowe' : 'brak pełnego zestawu'}
                            </span>
                            {!certificatePanel.hasOffline || !certificatePanel.hasOnline ? (
                                <span className="text-amber-700">
                                    Brakujący certyfikat uzupełnij w panelu konfiguracji.
                                </span>
                            ) : null}
                        </div>
                    </CardContent>
                </Card>

                {/* Filtry faktur KSeF */}
                <Card className="border-0 shadow-sm ring-1 ring-black/5">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Search className="h-4 w-4" />
                            <span>Filtry faktur <span className="ml-1 rounded bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700">KSeF</span></span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 md:grid-cols-4">
                            <div className="grid gap-1">
                                <Label htmlFor="dateFrom">Data od</Label>
                                <Input id="dateFrom" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="dateTo">Data do</Label>
                                <Input id="dateTo" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="subjectType">Rodzaj</Label>
                                <Select value={subjectType} onValueChange={(value: 'Subject1' | 'Subject2' | 'Subject3' | 'SubjectAuthorized') => setSubjectType(value)}>
                                    <SelectTrigger id="subjectType">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="Subject1">Subject1</SelectItem>
                                        <SelectItem value="Subject2">Subject2</SelectItem>
                                        <SelectItem value="Subject3">Subject3</SelectItem>
                                        <SelectItem value="SubjectAuthorized">SubjectAuthorized</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex items-end gap-2">
                                <Button onClick={() => void fetchInvoices()} disabled={!status.authenticated || invoiceLoading} className="w-full bg-teal-700 hover:bg-teal-800" title="Wyszukaj i zapisz faktury z KSeF do bazy">
                                    {invoiceLoading ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Search className="mr-2 h-4 w-4" />}
                                    Sprawdź i zapisz
                                </Button>
                                <Button onClick={() => void handleSyncNewInvoices()} disabled={!status.authenticated || newInvoiceLoading} variant="outline" className="border-blue-200 text-blue-700 hover:bg-blue-50 hover:text-blue-800 font-semibold" title="Pobierz tylko nowe faktury z KSeF (od ostatniej synchronizacji)">
                                    {newInvoiceLoading ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Download className="mr-2 h-4 w-4" />}
                                    Wyszukaj tylko nowe
                                </Button>
                                {lastNewCount !== null && (
                                    <span className="ml-2 text-xs text-blue-700">Pobrano: <b>{lastNewCount}</b></span>
                                )}
                            </div>
                        </div>
                        <div className="mt-2 text-xs text-slate-500">
                            <span>Wszystkie faktury są wyszukiwane i pobierane bezpośrednio z KSeF, a następnie zapisywane w bazie danych.</span>
                        </div>
                    </CardContent>
                </Card>

                {/* Statistics Cards */}
                <div className="grid gap-4 md:grid-cols-4">
                    <Card className="border-0 shadow-sm ring-1 ring-black/5">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Liczba faktur</CardTitle>
                            <FileText className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.totalInvoices}</div>
                            <p className="text-xs text-muted-foreground">Pobranych z systemu</p>
                        </CardContent>
                    </Card>

                    <Card className="border-0 shadow-sm ring-1 ring-black/5">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Suma netto</CardTitle>
                            <DollarSign className="h-4 w-4 text-green-600" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.totalAmount.toLocaleString()} zł</div>
                            <p className="text-xs text-muted-foreground">Wartość wszystkich faktur</p>
                        </CardContent>
                    </Card>

                    <Card className="border-0 shadow-sm ring-1 ring-black/5">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Suma VAT</CardTitle>
                            <DollarSign className="h-4 w-4 text-blue-600" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.totalVat.toLocaleString()} zł</div>
                            <p className="text-xs text-muted-foreground">VAT do rozliczenia</p>
                        </CardContent>
                    </Card>

                    <Card className="border-0 shadow-sm ring-1 ring-black/5">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Zaakceptowane</CardTitle>
                            <CheckCircle2 className="h-4 w-4 text-green-600" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.acceptedCount}</div>
                            <p className="text-xs text-muted-foreground">Z {stats.totalInvoices} faktur</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Recent Invoices */}
                <Card className="border-0 shadow-sm ring-1 ring-black/5">
                    <CardHeader>
                        <CardTitle>Faktury z KSeF</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {invoiceLoading ? (
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Loader2 className="h-4 w-4 animate-spin" />
                                Pobieranie faktur z KSeF...
                            </div>
                        ) : invoices.length === 0 ? (
                            <div className="text-sm text-muted-foreground">Brak faktur dla wybranych filtrów.</div>
                        ) : (
                            <div className="flex flex-col gap-3">
                                {paginatedInvoices.map((invoice, index) => {
                                    const ksefId = invoice.ksef_id ?? invoice.ksefNumber ?? invoice.ksefReferenceNumber ?? '-';
                                    const invoiceDate = invoice.invoicing_date ?? invoice.invoicingDate ?? invoice.acquisition_date ?? invoice.acquisitionDate;
                                    return (
                                        <div key={`${ksefId}-${index}`} className="rounded-lg border border-slate-200 bg-gradient-to-r from-white/80 to-slate-50/50 p-4 transition-all hover:shadow-md hover:border-slate-300">
                                            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                                <div className="flex-1 space-y-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <div className="flex items-center gap-1">
                                                            <span className="text-xs font-semibold text-slate-500">KSeF:</span>
                                                            <span className="font-mono text-sm font-bold text-slate-900">{ksefId}</span>
                                                        </div>
                                                        <Badge variant="outline" className="bg-blue-50 text-blue-700 border-blue-200">
                                                            {invoice.invoice_type ?? invoice.invoiceType ?? invoice.formType ?? 'N/A'}
                                                        </Badge>
                                                    </div>
                                                    
                                                    <div className="grid gap-2 md:grid-cols-2">
                                                        <div>
                                                            <div className="text-xs text-muted-foreground font-medium">Sprzedawca</div>
                                                            <div className="text-sm font-semibold text-slate-900">{invoice.seller_name ?? invoice.sellerName ?? '-'}</div>
                                                            <div className="text-xs text-slate-600 font-mono">{invoice.seller_nip ?? invoice.sellerNip ?? '-'}</div>
                                                        </div>
                                                        <div>
                                                            <div className="text-xs text-muted-foreground font-medium">Kwoty</div>
                                                            <div className="flex gap-3 text-sm">
                                                                <div>
                                                                    <span className="text-slate-600">Netto: </span>
                                                                    <span className="font-bold text-green-700">{toNumber(invoice.total_net ?? invoice.netValue ?? invoice.totalNetAmount).toLocaleString('pl-PL')} zł</span>
                                                                </div>
                                                                <div>
                                                                    <span className="text-slate-600">VAT: </span>
                                                                    <span className="font-bold text-blue-700">{toNumber(invoice.total_vat ?? invoice.vatValue ?? invoice.totalVatAmount).toLocaleString('pl-PL')} zł</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div className="text-xs text-slate-600">
                                                        <span className="font-medium">Data faktury:</span> {formatDate(invoiceDate)}
                                                    </div>
                                                </div>
                                                
                                                <div className="flex flex-wrap gap-2 md:flex-col md:whitespace-nowrap">
                                                    <Button 
                                                        variant="outline" 
                                                        size="sm" 
                                                        className="border-blue-200 text-blue-700 hover:bg-blue-50 hover:text-blue-800"
                                                        title="Podgląd XML"
                                                    >
                                                        <Eye className="mr-1 h-3.5 w-3.5" />
                                                        <span className="text-xs">Podgląd</span>
                                                    </Button>
                                                    <Button 
                                                        asChild
                                                        variant="outline" 
                                                        size="sm" 
                                                        className="border-teal-200 text-teal-700 hover:bg-teal-50 hover:text-teal-800"
                                                        title="Przejdź do szczegółów faktury"
                                                    >
                                                        <Link href={`/ksef/invoices?id=${ksefId}`}>
                                                            <span className="text-xs">Szczegóły</span>
                                                            <ChevronRight className="ml-1 h-3.5 w-3.5" />
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}

                        {/* Pagination */}
                        {invoices.length > 0 && totalPages > 1 && (
                            <div className="mt-6 flex flex-col gap-3 items-center border-t pt-4">
                                <div className="flex items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={currentInvoicePage <= 1}
                                        onClick={() => setCurrentInvoicePage(p => Math.max(1, p - 1))}
                                    >
                                        ← Poprzednia
                                    </Button>
                                    <span className="text-sm text-muted-foreground font-medium">
                                        Strona {currentInvoicePage} / {totalPages}
                                    </span>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={currentInvoicePage >= totalPages}
                                        onClick={() => setCurrentInvoicePage(p => Math.min(totalPages, p + 1))}
                                    >
                                        Następna →
                                    </Button>
                                </div>
                                <div className="text-xs text-slate-500">
                                    Wyświetlane: {(currentInvoicePage - 1) * itemsPerPage + 1}–{Math.min(currentInvoicePage * itemsPerPage, invoices.length)} z {invoices.length} faktur
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
