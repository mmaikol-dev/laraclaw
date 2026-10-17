import { Head, router } from '@inertiajs/react';
import {
    CheckCircle2,
    CircleDashed,
    Clapperboard,
    ExternalLink,
    Film,
    RefreshCw,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import {
    index as mediaRoute,
    rescan as rescanRoute,
    status as statusRoute,
} from '@/routes/media';
import type { BreadcrumbItem } from '@/types';

type MediaAssetItem = {
    id: number;
    slug: string;
    title: string | null;
    filename: string;
    path: string;
    platform: string;
    status: 'posted' | 'unposted';
    posted_at: string | null;
    external_id: string | null;
    external_url: string | null;
    size_bytes: number | null;
    source: string | null;
};

type Filter = 'all' | 'unposted' | 'posted';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Media', href: mediaRoute() }];

const PLATFORM_LABELS: Record<string, string> = {
    clipped: 'Clipped',
    instagram: 'Instagram',
    youtube: 'YouTube',
    facebook: 'Facebook',
};

function formatBytes(bytes: number | null): string {
    if (!bytes) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

export default function MediaIndex({
    assets,
    summary,
    mediaRoot,
}: {
    assets: MediaAssetItem[];
    summary: { posted: number; unposted: number };
    platforms: string[];
    mediaRoot: string;
}) {
    const [filter, setFilter] = useState<Filter>('all');
    const [busy, setBusy] = useState<number | null>(null);

    const visible = assets.filter((asset) =>
        filter === 'all' ? true : asset.status === filter,
    );

    const rescan = () =>
        router.post(rescanRoute().url, {}, { preserveScroll: true });

    const toggle = (asset: MediaAssetItem) => {
        setBusy(asset.id);
        router.patch(
            statusRoute({ mediaAsset: asset.id }).url,
            { status: asset.status === 'posted' ? 'unposted' : 'posted' },
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Media" />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
                <Card className="border-none bg-gradient-to-r from-slate-950 via-slate-900 to-fuchsia-950 text-white shadow-lg">
                    <CardHeader className="gap-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex items-center gap-3">
                                <div className="rounded-2xl bg-white/10 p-3">
                                    <Clapperboard className="size-6" />
                                </div>
                                <div>
                                    <CardTitle className="font-serif text-2xl">
                                        Media Library
                                    </CardTitle>
                                    <CardDescription className="text-slate-300">
                                        Every clip under{' '}
                                        <span className="font-mono">
                                            {mediaRoot}
                                        </span>
                                        , with what has been posted and what has
                                        not.
                                    </CardDescription>
                                </div>
                            </div>
                            <Button variant="secondary" onClick={rescan}>
                                <RefreshCw className="size-4" />
                                Rescan folder
                            </Button>
                        </div>
                    </CardHeader>
                </Card>

                <div className="grid gap-4 md:grid-cols-3">
                    {[
                        {
                            label: 'Total clips',
                            value: assets.length,
                            Icon: Film,
                        },
                        {
                            label: 'Unposted',
                            value: summary.unposted,
                            Icon: CircleDashed,
                        },
                        {
                            label: 'Posted',
                            value: summary.posted,
                            Icon: CheckCircle2,
                        },
                    ].map(({ label, value, Icon }) => (
                        <Card key={label}>
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardDescription>{label}</CardDescription>
                                <Icon className="size-4 text-muted-foreground" />
                                <CardTitle className="text-3xl">
                                    {value}
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                </div>

                <div className="flex items-center justify-between">
                    <ToggleGroup
                        type="single"
                        value={filter}
                        onValueChange={(value) =>
                            value && setFilter(value as Filter)
                        }
                        variant="outline"
                    >
                        <ToggleGroupItem value="all">All</ToggleGroupItem>
                        <ToggleGroupItem value="unposted">
                            Unposted
                        </ToggleGroupItem>
                        <ToggleGroupItem value="posted">Posted</ToggleGroupItem>
                    </ToggleGroup>
                    <p className="text-sm text-muted-foreground">
                        {visible.length} of {assets.length} clips
                    </p>
                </div>

                {visible.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-sm text-muted-foreground">
                            No clips to show. Cut a clip with the agent, or drop
                            files into the media folders and rescan.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 xl:grid-cols-2">
                        {visible.map((asset) => (
                            <Card key={asset.id}>
                                <CardHeader className="gap-3">
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="space-y-1">
                                            <CardTitle className="font-mono text-base">
                                                {asset.slug}
                                            </CardTitle>
                                            <CardDescription>
                                                {asset.title ?? asset.filename}
                                            </CardDescription>
                                        </div>
                                        <Badge
                                            variant={
                                                asset.status === 'posted'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {asset.status === 'posted'
                                                ? 'Posted'
                                                : 'Unposted'}
                                        </Badge>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Badge variant="outline">
                                            {PLATFORM_LABELS[asset.platform] ??
                                                asset.platform}
                                        </Badge>
                                        <Badge variant="outline">
                                            {formatBytes(asset.size_bytes)}
                                        </Badge>
                                        <Badge
                                            variant="outline"
                                            className="font-mono"
                                        >
                                            {asset.path}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="text-sm text-muted-foreground">
                                        {asset.status === 'posted' &&
                                        asset.posted_at ? (
                                            <span>
                                                Posted{' '}
                                                {new Date(
                                                    asset.posted_at,
                                                ).toLocaleString()}
                                                {asset.external_url ? (
                                                    <a
                                                        href={
                                                            asset.external_url
                                                        }
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="ml-2 inline-flex items-center gap-1 text-primary underline-offset-4 hover:underline"
                                                    >
                                                        View{' '}
                                                        <ExternalLink className="size-3" />
                                                    </a>
                                                ) : null}
                                            </span>
                                        ) : (
                                            <span>Not posted yet</span>
                                        )}
                                    </div>
                                    <Button
                                        variant={
                                            asset.status === 'posted'
                                                ? 'outline'
                                                : 'default'
                                        }
                                        size="sm"
                                        disabled={busy === asset.id}
                                        onClick={() => toggle(asset)}
                                    >
                                        {asset.status === 'posted'
                                            ? 'Mark unposted'
                                            : 'Mark posted'}
                                    </Button>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
