import { Head } from '@inertiajs/react';
import {
    CheckCircle2,
    Download,
    FileText,
    KeyRound,
    Loader2,
    LogIn,
    LogOut,
    Search,
    XCircle,
    Eye,
    ChevronRight,
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

type DbInvoice = {
    id: number;
    ksef_id: string;
    reference_number: string | null;
    number: string | null;
    issue_date: string | null;
    invoicing_date: string | null;
    seller_name: string | null;
    seller_nip: string | null;
    buyer_name: string | null;
    buyer_nip: string | null;
    total_gross: number | null;
    total_net: number | null;
    total_vat: number | null;
    currency: string | null;
    invoice_type: string | null;
};

type PaginationState = {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
};

type ParsedInvoicePreview = {
    sellerName: string;
    sellerNip: string;
    buyerName: string;
    buyerNip: string;
    issueDate: string;
    paymentDueDate: string;
    net: string;
    vat: string;
    gross: string;
};

const extractTextByLocalName = (xmlDoc: Document, names: string[]): string => {
    const all = Array.from(xmlDoc.getElementsByTagName('*'));
    for (const node of all) {
        if (names.includes(node.localName)) {
            const value = node.textContent?.trim();
            if (value) return value;
        }
    }
    return '-';
};

const parseInvoicePreview = (xml: string): ParsedInvoicePreview => {
    try {
        const doc = new DOMParser().parseFromString(xml, 'application/xml');

        return {
            sellerName: extractTextByLocalName(doc, ['SellerName', 'SprzedawcaNazwa', 'P_3A', 'Nazwa']),
            sellerNip: extractTextByLocalName(doc, ['SellerIdentifier', 'SprzedawcaNIP', 'P_5B', 'NIP']),
            buyerName: extractTextByLocalName(doc, ['BuyerName', 'NabywcaNazwa', 'P_3B', 'Nazwa']),
            buyerNip: extractTextByLocalName(doc, ['BuyerIdentifier', 'NabywcaNIP', 'P_5A', 'NIP']),
            issueDate: extractTextByLocalName(doc, ['IssueDate', 'DataWystawienia', 'P_1']),
            paymentDueDate: extractTextByLocalName(doc, ['PaymentDueDate', 'TerminPlatnosci', 'P_6']),
            net: extractTextByLocalName(doc, ['TotalNet', 'Netto', 'P_13_1']),
            vat: extractTextByLocalName(doc, ['TotalVat', 'VAT', 'P_14_1']),
            gross: extractTextByLocalName(doc, ['TotalGross', 'Brutto', 'P_15']),
        };
    } catch {
        return {
            sellerName: '-',
            sellerNip: '-',
            buyerName: '-',
            buyerNip: '-',
            issueDate: '-',
            paymentDueDate: '-',
            net: '-',
            vat: '-',
            gross: '-',
        };
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

export default function KsefInvoices({ status: initialStatus }: { status: KsefStatus }) {
    const [status, setStatus] = useState<KsefStatus>(initialStatus);
    const [loading, setLoading] = useState(false);
    const [authLoading, setAuthLoading] = useState(false);
    const [invoices, setInvoices] = useState<DbInvoice[]>([]);
    const [currentDBPage, setCurrentDBPage] = useState(1);
    const pageSize = 9; // 3x3 grid
    const totalDBPages = Math.ceil(invoices.length / pageSize);
    const paginatedDBInvoices = invoices.slice(
        (currentDBPage - 1) * pageSize,
        currentDBPage * pageSize
    );
    const [pagination, setPagination] = useState<PaginationState>({
        currentPage: 1,
        lastPage: 1,
        perPage: 20,
        total: 0,
    });
    const [error, setError] = useState<string | null>(null);
    const [successMsg, setSuccessMsg] = useState<string | null>(null);
    const [xmlPreview, setXmlPreview] = useState<string | null>(null);
    const [previewKsef, setPreviewKsef] = useState<string | null>(null);
    const [previewMeta, setPreviewMeta] = useState<DbInvoice | null>(null);
    const [previewParsed, setPreviewParsed] = useState<ParsedInvoicePreview | null>(null);

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
    const [keyPassword, setKeyPassword] = useState('');
    const [authType, setAuthType] = useState<'offline' | 'online'>('online');

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
                body: JSON.stringify({ type: authType, key_password: keyPassword }),
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
                setKeyPassword('');
            } else {
                setError(data.message ?? `Błąd KSeF (HTTP ${res.status})`);
            }
        } catch (e) {
            setError('Błąd połączenia z serwerem — sprawdź czy serwer działa');
        } finally {
            setAuthLoading(false);
        }
    }, [authType, keyPassword]);

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
                setPagination({ currentPage: 1, lastPage: 1, perPage: 20, total: 0 });
            }
        } catch (e) {
            setError('Błąd rozłączania');
        }
    }, []);

    const handleCheckInvoices = useCallback(
        async (page = 1, offset = 0) => {
            clearMessages();
            setLoading(true);
            setPageOffset(offset);
            try {
                const body: Record<string, unknown> = {
                    type: authType,
                    dateFrom,
                    dateTo,
                    subjectType,
                    pageOffset: offset,
                    pageSize: 50,
                    page,
                    perPage: pagination.perPage,
                };
                if (sellerNip) body.sellerNip = sellerNip;
                if (ksefNumber) body.ksefNumber = ksefNumber;
                if (invoiceNumber) body.invoiceNumber = invoiceNumber;

                const res = await fetch('/ksef/invoices/check', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN':
                            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify(body),
                });

                const data = await res.json();

                if (data.error || data.success === false) {
                    setError(data.error);
                } else {
                    setInvoices(data.data || []);
                    setPagination({
                        currentPage: data.currentPage ?? 1,
                        lastPage: data.lastPage ?? 1,
                        perPage: data.perPage ?? 20,
                        total: data.total ?? 0,
                    });
                    const saved = data?.synced?.saved ?? 0;
                    const fetched = data?.synced?.fetched ?? 0;
                    setSuccessMsg(`Sprawdzono KSeF: pobrano ${fetched}, zapisano ${saved}.`);
                }
            } catch (e) {
                setError('Błąd pobierania faktur');
            } finally {
                setLoading(false);
            }
        },
        [authType, dateFrom, dateTo, subjectType, sellerNip, ksefNumber, invoiceNumber, pagination.perPage],
    );

    const handlePreview = useCallback(async (invoice: DbInvoice) => {
        const ksefNum = invoice.ksef_id;
        if (!ksefNum) return;
        try {
            const res = await fetch(`/ksef/invoices/${encodeURIComponent(ksefNum)}?type=${authType}`, {
                headers: {
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
            });
            const text = await res.text();
            setXmlPreview(text);
            setPreviewKsef(ksefNum);
            setPreviewMeta(invoice);
            setPreviewParsed(parseInvoicePreview(text));
        } catch {
            setError('Błąd podglądu faktury');
        }
    }, [authType]);

    const handleDownload = useCallback((ksefNum: string) => {
        window.open(`/ksef/invoices/${encodeURIComponent(ksefNum)}/download?type=${authType}`, '_blank');
    }, [authType]);

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
                                Konfiguracja wymagana: dodaj certyfikat użytkownika na ekranie setupu KSeF.
                            </div>
                        </CardContent>
                    )}
                    {!status.authenticated && status.configured && (
                        <CardContent className="pt-0">
                            <div className="grid gap-2 md:max-w-xl">
                                <div className="grid gap-1.5 md:grid-cols-2 md:gap-3">
                                    <div className="grid gap-1">
                                        <Label>Typ certyfikatu</Label>
                                        <Select value={authType} onValueChange={(v: 'offline' | 'online') => setAuthType(v)}>
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="offline">Offline</SelectItem>
                                                <SelectItem value="online">Online</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor="keyPassword">Hasło do klucza prywatnego</Label>
                                        <div className="relative flex-1">
                                            <KeyRound className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                            <Input
                                                id="keyPassword"
                                                type="password"
                                                value={keyPassword}
                                                onChange={(e) => setKeyPassword(e.target.value)}
                                                className="pl-9"
                                                placeholder="Podaj hasło do klucza"
                                            />
                                        </div>
                                    </div>
                                </div>
                                <div className="text-xs text-muted-foreground">Podgląd i pobieranie faktur działa dla wybranego typu sesji ({authType}).</div>
                                <div className="flex gap-2">
                                    <div className="relative flex-1">
                                        <Button size="sm" onClick={handleAuth} disabled={authLoading || !keyPassword}>
                                            {authLoading ? <Loader2 className="mr-1 h-4 w-4 animate-spin" /> : <LogIn className="mr-1 h-4 w-4" />}
                                            Połącz ({authType})
                                        </Button>
                                    </div>
                                </div>
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
                            <CardTitle className="text-base">Sprawdź faktury i zapisz do bazy</CardTitle>
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
                                        onClick={() => handleCheckInvoices(1, 0)}
                                        disabled={loading}
                                        className="w-full"
                                    >
                                        {loading ? (
                                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        ) : (
                                            <Search className="mr-2 h-4 w-4" />
                                        )}
                                        Sprawdź faktury
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
                                    Wyniki z bazy ({pagination.total} faktur)
                                </CardTitle>
                                <div className="text-xs text-muted-foreground">
                                    Strona {pagination.currentPage} z {pagination.lastPage}
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-3 md:grid-cols-3">
                                {paginatedDBInvoices.map((inv, i) => (
                                    <div key={inv.ksef_id || i} className="rounded-lg border border-slate-200 bg-gradient-to-r from-white/80 to-slate-50/50 p-4 transition-all hover:shadow-md hover:border-slate-300">
                                        <div className="space-y-2.5">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <div className="flex items-center gap-1">
                                                    <span className="text-xs font-semibold text-slate-500">KSeF:</span>
                                                    <span className="font-mono text-sm font-bold text-slate-900">{inv.ksef_id || '-'}</span>
                                                </div>
                                                <Badge variant="outline" className="bg-blue-50 text-blue-700 border-blue-200 text-xs">
                                                    {inv.invoice_type || '-'}
                                                </Badge>
                                            </div>
                                            
                                            <div className="space-y-1">
                                                <div className="text-xs text-muted-foreground font-medium">Numer faktury</div>
                                                <div className="text-sm font-semibold text-slate-900">{inv.number || '-'}</div>
                                            </div>

                                            <div className="space-y-1">
                                                <div className="text-xs text-muted-foreground font-medium">Sprzedawca</div>
                                                <div className="text-sm font-semibold text-slate-900 truncate">{inv.seller_name || '-'}</div>
                                                <div className="text-xs text-slate-600 font-mono">{inv.seller_nip || '-'}</div>
                                            </div>
                                            
                                            <div className="grid grid-cols-2 gap-2 text-sm">
                                                <div>
                                                    <span className="text-slate-600">Brutto:</span>
                                                    <div className="font-bold text-green-700">{inv.total_gross != null ? inv.total_gross.toLocaleString('pl-PL', { minimumFractionDigits: 2 }) : '-'} {inv.currency || 'PLN'}</div>
                                                </div>
                                                <div>
                                                    <span className="text-slate-600">Netto:</span>
                                                    <div className="font-bold text-blue-700">{inv.total_net != null ? inv.total_net.toLocaleString('pl-PL', { minimumFractionDigits: 2 }) : '-'} {inv.currency || 'PLN'}</div>
                                                </div>
                                            </div>

                                            <div className="text-xs text-slate-600 border-t pt-2">
                                                <span className="font-medium">Data:</span> {formatDate(inv.invoicing_date)}
                                            </div>

                                            <div className="flex flex-wrap gap-2 pt-2">
                                                {inv.ksef_id && (
                                                    <>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="border-blue-200 text-blue-700 hover:bg-blue-50 hover:text-blue-800"
                                                            onClick={() => handlePreview(inv)}
                                                            title="Podgląd XML"
                                                        >
                                                            <Eye className="mr-1 h-3.5 w-3.5" />
                                                            <span className="text-xs">Podgląd</span>
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="border-teal-200 text-teal-700 hover:bg-teal-50 hover:text-teal-800"
                                                            onClick={() => handleDownload(inv.ksef_id)}
                                                            title="Pobierz XML"
                                                        >
                                                            <Download className="mr-1 h-3.5 w-3.5" />
                                                            <span className="text-xs">Pobierz</span>
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {/* Client-side Pagination for DB Results */}
                            {invoices.length > 0 && totalDBPages > 1 && (
                                <div className="mt-6 flex flex-col gap-3 items-center border-t pt-4">
                                    <div className="flex items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={currentDBPage <= 1}
                                            onClick={() => setCurrentDBPage(p => Math.max(1, p - 1))}
                                        >
                                            ← Poprzednia
                                        </Button>
                                        <span className="text-sm text-muted-foreground font-medium">
                                            Strona {currentDBPage} / {totalDBPages}
                                        </span>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={currentDBPage >= totalDBPages}
                                            onClick={() => setCurrentDBPage(p => Math.min(totalDBPages, p + 1))}
                                        >
                                            Następna →
                                        </Button>
                                    </div>
                                    <div className="text-xs text-slate-500">
                                        Wyświetlane: {(currentDBPage - 1) * pageSize + 1}–{Math.min(currentDBPage * pageSize, invoices.length)} z {invoices.length} faktur
                                    </div>
                                </div>
                            )}
                            <div className="mt-4 flex items-center justify-end gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={loading || pagination.currentPage <= 1}
                                    onClick={() => handleCheckInvoices(pagination.currentPage - 1, pageOffset)}
                                >
                                    Poprzednia
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={loading || pagination.currentPage >= pagination.lastPage}
                                    onClick={() => handleCheckInvoices(pagination.currentPage + 1, pageOffset)}
                                >
                                    Następna
                                </Button>
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
                            {previewMeta && previewParsed && (
                                <div className="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50/60 p-4 md:grid-cols-2">
                                    <div>
                                        <div className="text-xs text-muted-foreground">Sprzedawca</div>
                                        <div className="font-semibold">{previewMeta.seller_name ?? previewParsed.sellerName}</div>
                                        <div className="font-mono text-xs">NIP: {previewMeta.seller_nip ?? previewParsed.sellerNip}</div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">Nabywca</div>
                                        <div className="font-semibold">{previewMeta.buyer_name ?? previewParsed.buyerName}</div>
                                        <div className="font-mono text-xs">NIP: {previewMeta.buyer_nip ?? previewParsed.buyerNip}</div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">Numer faktury</div>
                                        <div>{previewMeta.number ?? '-'}</div>
                                        <div className="text-xs text-muted-foreground">Data: {previewMeta.invoicing_date ?? previewParsed.issueDate}</div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">Płatność</div>
                                        <div>Termin: {previewParsed.paymentDueDate}</div>
                                        <div className="text-xs text-muted-foreground">Typ: {previewMeta.invoice_type ?? '-'}</div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">Netto</div>
                                        <div className="font-semibold">{previewMeta.total_net ?? previewParsed.net} {previewMeta.currency ?? 'PLN'}</div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">VAT / Brutto</div>
                                        <div className="font-semibold">{previewMeta.total_vat ?? previewParsed.vat} / {previewMeta.total_gross ?? previewParsed.gross} {previewMeta.currency ?? 'PLN'}</div>
                                    </div>
                                </div>
                            )}
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
