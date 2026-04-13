import { Head, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';

type Invoice = {
    id: number;
    ksef_id: string;
    reference_number: string | null;
    number: string | null;
    issue_date: string | null;
    seller_name: string | null;
    seller_nip: string | null;
    buyer_name: string | null;
    total_net: number | string | null;
    total_vat: number | string | null;
    currency: string | null;
    payment_status: string | null;
};

type PaginationData = {
    data: Invoice[];
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
};

type Filters = {
    dateFrom: string | null;
    dateTo: string | null;
    kind: string;
    search: string;
    perPage: number;
};

type Props = {
    filters: Filters;
    invoices: PaginationData;
};

const toAmount = (value: number | string | null | undefined) => {
    const parsed = Number(value ?? 0);
    return Number.isNaN(parsed) ? 0 : parsed;
};

export default function MyInvoicesPage({ filters, invoices }: Props) {
    const [dateFrom, setDateFrom] = useState(filters.dateFrom ?? '');
    const [dateTo, setDateTo] = useState(filters.dateTo ?? '');
    const [kind, setKind] = useState(filters.kind ?? '');
    const [search, setSearch] = useState(filters.search ?? '');

    const totals = useMemo(() => {
        const net = invoices.data.reduce((sum, inv) => sum + toAmount(inv.total_net), 0);
        const vat = invoices.data.reduce((sum, inv) => sum + toAmount(inv.total_vat), 0);
        return { net, vat };
    }, [invoices.data]);

    const submit = () => {
        router.get(
            '/ksef/my-invoices',
            {
                dateFrom: dateFrom || undefined,
                dateTo: dateTo || undefined,
                kind: kind || undefined,
                search: search || undefined,
                perPage: filters.perPage || 20,
            },
            { preserveScroll: true }
        );
    };

    const goToPage = (page: number) => {
        router.get(
            '/ksef/my-invoices',
            {
                dateFrom: dateFrom || undefined,
                dateTo: dateTo || undefined,
                kind: kind || undefined,
                search: search || undefined,
                perPage: filters.perPage || 20,
                page,
            },
            { preserveScroll: true }
        );
    };

    return (
        <>
            <Head title="Moje Faktury" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <Heading title="Moje Faktury" description="Faktury zapisane lokalnie po synchronizacji z KSeF" />

                <Card className="border-0 shadow-sm ring-1 ring-black/5">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Search className="h-4 w-4" />
                            Wyszukiwanie
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 md:grid-cols-5">
                            <div className="grid gap-1">
                                <Label htmlFor="my-date-from">Data od</Label>
                                <Input id="my-date-from" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="my-date-to">Data do</Label>
                                <Input id="my-date-to" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="my-kind">Rodzaj</Label>
                                <Input id="my-kind" placeholder="np. VAT" value={kind} onChange={(e) => setKind(e.target.value)} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="my-search">Szukaj</Label>
                                <Input id="my-search" placeholder="Nr, KSeF, kontrahent" value={search} onChange={(e) => setSearch(e.target.value)} />
                            </div>
                            <div className="flex items-end">
                                <Button onClick={submit} className="w-full bg-teal-700 hover:bg-teal-800">
                                    <Search className="mr-2 h-4 w-4" />
                                    Szukaj
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card className="border-0 shadow-sm ring-1 ring-black/5">
                    <CardHeader>
                        <CardTitle>
                            Wyniki: {invoices.total} | Netto: {totals.net.toLocaleString()} | VAT: {totals.vat.toLocaleString()}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {invoices.data.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Brak faktur dla wybranych filtrów.</p>
                        ) : (
                            <div className="grid gap-2">
                                {invoices.data.map((invoice) => (
                                    <div key={invoice.id} className="grid grid-cols-1 gap-2 rounded-lg border border-slate-200 bg-white/50 p-3 text-sm md:grid-cols-8 md:gap-4">
                                        <div>
                                            <div className="text-xs text-muted-foreground">Numer KSeF</div>
                                            <div className="font-mono text-xs font-semibold">{invoice.ksef_id}</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">Numer faktury</div>
                                            <div className="font-medium">{invoice.number ?? '-'}</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">Sprzedawca</div>
                                            <div className="truncate">{invoice.seller_name ?? '-'}</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">NIP sprzedawcy</div>
                                            <div className="font-mono text-xs">{invoice.seller_nip ?? '-'}</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">Nabywca</div>
                                            <div className="truncate">{invoice.buyer_name ?? '-'}</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">Netto</div>
                                            <div className="font-semibold text-green-700">{toAmount(invoice.total_net).toLocaleString()} {invoice.currency ?? 'PLN'}</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">VAT</div>
                                            <div className="font-semibold text-blue-700">{toAmount(invoice.total_vat).toLocaleString()} {invoice.currency ?? 'PLN'}</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">Data</div>
                                            <div className="text-xs">{invoice.issue_date ?? '-'}</div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {invoices.lastPage > 1 ? (
                            <div className="mt-4 flex items-center justify-end gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={invoices.currentPage <= 1}
                                    onClick={() => goToPage(invoices.currentPage - 1)}
                                >
                                    Poprzednia
                                </Button>
                                <span className="text-sm text-muted-foreground">
                                    Strona {invoices.currentPage} / {invoices.lastPage}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={invoices.currentPage >= invoices.lastPage}
                                    onClick={() => goToPage(invoices.currentPage + 1)}
                                >
                                    Następna
                                </Button>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

MyInvoicesPage.layout = {
    breadcrumbs: [
        {
            title: 'Moje Faktury',
            href: '/ksef/my-invoices',
        },
    ],
};
