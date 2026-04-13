import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { FileText, DollarSign, CheckCircle2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

// Mock data for invoices (in production, this would come from KSeF API)
const mockInvoices = [
    { id: 1, ksefNumber: 'KSF/2025/001/01/00123456', issuer: 'Firma A Sp. z o.o.', nip: '1234567890', amount: 5000, vat: 920, status: 'accepted', date: '2025-04-10' },
    { id: 2, ksefNumber: 'KSF/2025/001/01/00123457', issuer: 'Firma B Sp. z o.o.', nip: '9876543210', amount: 3500, vat: 642, status: 'pending', date: '2025-04-09' },
    { id: 3, ksefNumber: 'KSF/2025/001/01/00123458', issuer: 'Firma C Sp. z o.o.', nip: '5555555555', amount: 7200, vat: 1320, status: 'accepted', date: '2025-04-08' },
    { id: 4, ksefNumber: 'KSF/2025/001/01/00123459', issuer: 'Firma D Sp. z o.o.', nip: '1111111111', amount: 2100, vat: 385, status: 'rejected', date: '2025-04-07' },
    { id: 5, ksefNumber: 'KSF/2025/001/01/00123460', issuer: 'Firma E Sp. z o.o.', nip: '2222222222', amount: 4600, vat: 843, status: 'accepted', date: '2025-04-06' },
    { id: 6, ksefNumber: 'KSF/2025/001/01/00123461', issuer: 'Firma F Sp. z o.o.', nip: '3333333333', amount: 8900, vat: 1630, status: 'accepted', date: '2025-04-05' },
    { id: 7, ksefNumber: 'KSF/2025/001/01/00123462', issuer: 'Firma G Sp. z o.o.', nip: '4444444444', amount: 1200, vat: 220, status: 'pending', date: '2025-04-04' },
    { id: 8, ksefNumber: 'KSF/2025/001/01/00123463', issuer: 'Firma H Sp. z o.o.', nip: '5555666666', amount: 6700, vat: 1227, status: 'accepted', date: '2025-04-03' },
    { id: 9, ksefNumber: 'KSF/2025/001/01/00123464', issuer: 'Firma I Sp. z o.o.', nip: '6666777777', amount: 3300, vat: 605, status: 'accepted', date: '2025-04-02' },
    { id: 10, ksefNumber: 'KSF/2025/001/01/00123465', issuer: 'Firma J Sp. z o.o.', nip: '7777888888', amount: 4400, vat: 806, status: 'accepted', date: '2025-04-01' },
];

const stats = {
    totalInvoices: mockInvoices.length,
    totalAmount: mockInvoices.reduce((sum, inv) => sum + inv.amount, 0),
    totalVat: mockInvoices.reduce((sum, inv) => sum + inv.vat, 0),
    acceptedCount: mockInvoices.filter(inv => inv.status === 'accepted').length,
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'accepted':
            return 'bg-green-100 text-green-800';
        case 'pending':
            return 'bg-yellow-100 text-yellow-800';
        case 'rejected':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
};

const getStatusLabel = (status: string) => {
    switch (status) {
        case 'accepted':
            return 'Zaakceptowana';
        case 'pending':
            return 'Oczekująca';
        case 'rejected':
            return 'Odrzucona';
        default:
            return status;
    }
};

export default function Dashboard() {
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

                {/* Recent Invoices Table */}
                <Card className="border-0 shadow-sm ring-1 ring-black/5">
                    <CardHeader>
                        <CardTitle>Ostatnie faktury (10)</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <div className="grid gap-2">
                                {mockInvoices.slice(0, 10).map((invoice) => (
                                    <div key={invoice.id} className="grid grid-cols-1 gap-2 rounded-lg border border-slate-200 bg-white/50 p-3 text-sm md:grid-cols-7 md:gap-4">
                                        <div>
                                            <div className="text-xs text-muted-foreground">Numer KSeF</div>
                                            <div className="font-mono text-xs font-semibold">{invoice.ksefNumber}</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">Wysyłający</div>
                                            <div className="truncate font-medium">{invoice.issuer}</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">NIP</div>
                                            <div className="font-mono text-xs">{invoice.nip}</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">Netto</div>
                                            <div className="font-semibold text-green-700">{invoice.amount.toLocaleString()} zł</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">VAT</div>
                                            <div className="font-semibold text-blue-700">{invoice.vat.toLocaleString()} zł</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">Status</div>
                                            <Badge className={getStatusColor(invoice.status)}>
                                                {getStatusLabel(invoice.status)}
                                            </Badge>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">Data</div>
                                            <div className="text-xs text-muted-foreground">{invoice.date}</div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
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
