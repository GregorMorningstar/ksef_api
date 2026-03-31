import { Head, router } from '@inertiajs/react';
import {
    CheckCircle2,
    Download,
    FileText,
    Loader2,
    LogIn,
    LogOut,
    Search,
    XCircle,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import { Badge } from '@/components/ui/badge';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type KsefStatus = {
    configured: boolean;
    authenticated: boolean;
    nip: string | null;
    environment: string;
    api_url: string;
    auth_method?: string;
};

type InvoiceMetadata = {
    ksefNumber?: string;
    invoiceNumber?: string;
    invoicingDate?: string;
    acquisitionDate?: string;
    sellerName?: string;
    sellerNip?: string;
    buyerName?: string;
    buyerNip?: string;
    grossValue?: number;
    netValue?: number;
    vatValue?: number;
    currency?: string;
    invoiceType?: string;
    formType?: string;
    [key: string]: unknown;
};

type SearchResult = {
    invoices: InvoiceMetadata[];
    hasMore: boolean;
    isTruncated: boolean;
};

export default function KsefInvoices({ status: initialStatus }: { status: KsefStatus }) {
    const [status, setStatus] = useState<KsefStatus>(initialStatus);
    const [loading, setLoading] = useState(false);
    const [authLoading, setAuthLoading] = useState(false);
    const [invoices, setInvoices] = useState<InvoiceMetadata[]>([]);
    const [searchResult, setSearchResult] = useState<SearchResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [successMsg, setSuccessMsg] = useState<string | null>(null);
    const [xmlPreview, setXmlPreview] = useState<string | null>(null);
    const [previewKsef, setPreviewKsef] = useState<string | null>(null);

    // Search form state
    const [dateFrom, setDateFrom] = useState(() => {
        const d = new Date();
        d.setMonth(d.getMonth() - 1);
        return d.toISOString().split('T')[0];
    });
    const [dateTo, setDateTo] = useState(() => new Date().toISOString().split('T')[0]);
    const [subjectType, setSubjectType] = useState('Subject1');
    const [sellerNip, setSellerNip] = useState('');
    const [ksefNumber, setKsefNumber] = useState('');
    const [invoiceNumber, setInvoiceNumber] = useState('');
    const [pageOffset, setPageOffset] = useState(0);

    const clearMessages = () => {
        setError(null);
        setSuccessMsg(null);
    };

    const handleAuth = useCallback(async () => {
        clearMessages();
        setAuthLoading(true);
        try {
            const res = await fetch('/ksef/authenticate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
            });
            const text = await res.text();
            let data: { success?: boolean; message?: string };
            try {
                data = JSON.parse(text);
            } catch {
                setError(`Błąd serwera (HTTP ${res.status})`);
                return;
            }
            if (data.success) {
                setSuccessMsg(data.message ?? 'Połączono');
                setStatus((s) => ({ ...s, authenticated: true }));
            } else {
                setError(data.message ?? `Błąd KSeF (HTTP ${res.status})`);
            }
        } catch (e) {
            setError('Błąd połączenia z serwerem — sprawdź czy serwer działa');
        } finally {
            setAuthLoading(false);
        }
    }, []);

    const handleLogout = useCallback(async () => {
        clearMessages();
        try {
            const res = await fetch('/ksef/logout', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
            });
            const data = await res.json();
            if (data.success) {
                setSuccessMsg(data.message);
                setStatus((s) => ({ ...s, authenticated: false }));
                setInvoices([]);
                setSearchResult(null);
            }
        } catch (e) {
            setError('Błąd rozłączania');
        }
    }, []);

    const handleSearch = useCallback(
        async (offset = 0) => {
            clearMessages();
            setLoading(true);
            setPageOffset(offset);
            try {
                const body: Record<string, unknown> = {
                    dateFrom,
                    dateTo,
                    subjectType,
                    pageOffset: offset,
                    pageSize: 50,
                };
                if (sellerNip) body.sellerNip = sellerNip;
                if (ksefNumber) body.ksefNumber = ksefNumber;
                if (invoiceNumber) body.invoiceNumber = invoiceNumber;

                const res = await fetch('/ksef/invoices/search', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN':
                            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify(body),
                });

                const data = await res.json();

                if (data.error) {
                    setError(data.error);
                } else {
                    setSearchResult(data);
                    setInvoices(data.invoices || []);
                    setSuccessMsg(`Znaleziono ${data.invoices?.length ?? 0} faktur`);
                }
            } catch (e) {
                setError('Błąd wyszukiwania');
            } finally {
                setLoading(false);
            }
        },
        [dateFrom, dateTo, subjectType, sellerNip, ksefNumber, invoiceNumber],
    );

    const handlePreview = useCallback(async (ksefNum: string) => {
        try {
            const res = await fetch(`/ksef/invoices/${encodeURIComponent(ksefNum)}`, {
                headers: {
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
            });
            const text = await res.text();
            setXmlPreview(text);
            setPreviewKsef(ksefNum);
        } catch {
            setError('Błąd podglądu faktury');
        }
    }, []);

    const handleDownload = useCallback((ksefNum: string) => {
        window.open(`/ksef/invoices/${encodeURIComponent(ksefNum)}/download`, '_blank');
    }, []);

    return (
        <>
            <Head title="KSeF - Faktury" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
                {/* Status Card */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="text-lg">KSeF - Krajowy System e-Faktur</CardTitle>
                                <CardDescription>
                                    Środowisko: <strong>{status.environment?.toUpperCase()}</strong>
                                    {status.nip && (
                                        <> | NIP: <strong>{status.nip}</strong></>
                                    )}
                                    {status.auth_method && (
                                        <> | Metoda: <strong>{status.auth_method === 'certificate' ? 'Certyfikat XAdES' : 'Token KSeF'}</strong></>
                                    )}
                                </CardDescription>
                            </div>
                            <div className="flex items-center gap-2">
                                {status.authenticated ? (
                                    <>
                                        <Badge className="bg-green-600">
                                            <CheckCircle2 className="mr-1 h-3 w-3" />
                                            Połączono
                                        </Badge>
                                        <Button variant="outline" size="sm" onClick={handleLogout}>
                                            <LogOut className="mr-1 h-4 w-4" />
                                            Rozłącz
                                        </Button>
                                    </>
                                ) : (
                                    <>
                                        <Badge variant="destructive">
                                            <XCircle className="mr-1 h-3 w-3" />
                                            Rozłączono
                                        </Badge>
                                        <Button
                                            size="sm"
                                            onClick={handleAuth}
                                            disabled={authLoading || !status.configured}
                                        >
                                            {authLoading ? (
                                                <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                                            ) : (
                                                <LogIn className="mr-1 h-4 w-4" />
                                            )}
                                            Połącz z KSeF
                                        </Button>
                                    </>
                                )}
                            </div>
                        </div>
                    </CardHeader>
                    {!status.configured && (
                        <CardContent>
                            <div className="rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                                Konfiguracja wymagana: ustaw <code>KSEF_NIP</code> i <code>KSEF_TOKEN</code> w
                                pliku <code>.env</code>
                            </div>
                        </CardContent>
                    )}
                </Card>

                {/* Messages */}
                {error && (
                    <div className="rounded-md bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-200">
                        {error}
                    </div>
                )}
                {successMsg && (
                    <div className="rounded-md bg-green-50 p-3 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-200">
                        {successMsg}
                    </div>
                )}

                {/* Search Form */}
                {status.authenticated && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Wyszukaj faktury</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="dateFrom">Data od</Label>
                                    <Input
                                        id="dateFrom"
                                        type="date"
                                        value={dateFrom}
                                        onChange={(e) => setDateFrom(e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="dateTo">Data do</Label>
                                    <Input
                                        id="dateTo"
                                        type="date"
                                        value={dateTo}
                                        onChange={(e) => setDateTo(e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label>Typ podmiotu</Label>
                                    <Select value={subjectType} onValueChange={setSubjectType}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Subject1">Sprzedawca (Subject1)</SelectItem>
                                            <SelectItem value="Subject2">Nabywca (Subject2)</SelectItem>
                                            <SelectItem value="Subject3">Inny (Subject3)</SelectItem>
                                            <SelectItem value="SubjectAuthorized">Upoważniony</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="sellerNip">NIP sprzedawcy</Label>
                                    <Input
                                        id="sellerNip"
                                        placeholder="np. 1234567890"
                                        value={sellerNip}
                                        onChange={(e) => setSellerNip(e.target.value)}
                                        maxLength={10}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="ksefNumber">Numer KSeF</Label>
                                    <Input
                                        id="ksefNumber"
                                        placeholder="np. 1234567890-20260101-..."
                                        value={ksefNumber}
                                        onChange={(e) => setKsefNumber(e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="invoiceNumber">Numer faktury</Label>
                                    <Input
                                        id="invoiceNumber"
                                        placeholder="np. FV/2026/03/001"
                                        value={invoiceNumber}
                                        onChange={(e) => setInvoiceNumber(e.target.value)}
                                    />
                                </div>
                                <div className="flex items-end">
                                    <Button
                                        onClick={() => handleSearch(0)}
                                        disabled={loading}
                                        className="w-full"
                                    >
                                        {loading ? (
                                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        ) : (
                                            <Search className="mr-2 h-4 w-4" />
                                        )}
                                        Szukaj
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Results Table */}
                {invoices.length > 0 && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">
                                    Wyniki ({invoices.length} faktur)
                                </CardTitle>
                                {searchResult?.hasMore && (
                                    <div className="flex gap-2">
                                        {pageOffset > 0 && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => handleSearch(pageOffset - 1)}
                                            >
                                                Poprzednia
                                            </Button>
                                        )}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => handleSearch(pageOffset + 1)}
                                        >
                                            Następna
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th className="px-2 py-2 font-medium">Numer KSeF</th>
                                            <th className="px-2 py-2 font-medium">Nr faktury</th>
                                            <th className="px-2 py-2 font-medium">Data</th>
                                            <th className="px-2 py-2 font-medium">Sprzedawca</th>
                                            <th className="px-2 py-2 font-medium">NIP sprzedawcy</th>
                                            <th className="px-2 py-2 font-medium">Kwota brutto</th>
                                            <th className="px-2 py-2 font-medium">Waluta</th>
                                            <th className="px-2 py-2 font-medium">Typ</th>
                                            <th className="px-2 py-2 font-medium">Akcje</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {invoices.map((inv, i) => (
                                            <tr
                                                key={inv.ksefNumber || i}
                                                className="border-b hover:bg-muted/50"
                                            >
                                                <td className="max-w-[200px] truncate px-2 py-2 font-mono text-xs">
                                                    {inv.ksefNumber || '-'}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {inv.invoiceNumber || '-'}
                                                </td>
                                                <td className="px-2 py-2 whitespace-nowrap">
                                                    {inv.invoicingDate
                                                        ? new Date(inv.invoicingDate).toLocaleDateString('pl-PL')
                                                        : '-'}
                                                </td>
                                                <td className="max-w-[200px] truncate px-2 py-2">
                                                    {inv.sellerName || '-'}
                                                </td>
                                                <td className="px-2 py-2 font-mono text-xs">
                                                    {inv.sellerNip || '-'}
                                                </td>
                                                <td className="px-2 py-2 text-right whitespace-nowrap">
                                                    {inv.grossValue != null
                                                        ? inv.grossValue.toLocaleString('pl-PL', {
                                                              minimumFractionDigits: 2,
                                                          })
                                                        : '-'}
                                                </td>
                                                <td className="px-2 py-2">{inv.currency || 'PLN'}</td>
                                                <td className="px-2 py-2">
                                                    <Badge variant="outline" className="text-xs">
                                                        {inv.invoiceType || inv.formType || '-'}
                                                    </Badge>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <div className="flex gap-1">
                                                        {inv.ksefNumber && (
                                                            <>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        handlePreview(inv.ksefNumber!)
                                                                    }
                                                                    title="Podgląd XML"
                                                                >
                                                                    <FileText className="h-4 w-4" />
                                                                </Button>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        handleDownload(inv.ksefNumber!)
                                                                    }
                                                                    title="Pobierz XML"
                                                                >
                                                                    <Download className="h-4 w-4" />
                                                                </Button>
                                                            </>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* XML Preview Modal */}
                {xmlPreview && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">
                                    Podgląd XML: {previewKsef}
                                </CardTitle>
                                <div className="flex gap-2">
                                    {previewKsef && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => handleDownload(previewKsef)}
                                        >
                                            <Download className="mr-1 h-4 w-4" />
                                            Pobierz
                                        </Button>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            setXmlPreview(null);
                                            setPreviewKsef(null);
                                        }}
                                    >
                                        Zamknij
                                    </Button>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <pre className="max-h-[500px] overflow-auto rounded-md bg-muted p-4 text-xs">
                                {xmlPreview}
                            </pre>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

KsefInvoices.layout = {
    breadcrumbs: [
        {
            title: 'KSeF Faktury',
            href: '/ksef',
        },
    ],
};
