import { Form, Head } from '@inertiajs/react';
import { KeyRound, Lock, Save } from 'lucide-react';
import { useState } from 'react';
import CredentialsController from '@/actions/App/Http/Controllers/Settings/CredentialsController';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit } from '@/routes/credentials';
import type { BreadcrumbItem } from '@/types';

type Props = {
    path: string;
    exists: boolean;
    content: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Credentials',
        href: edit(),
    },
];

export default function Credentials({ path, exists, content }: Props) {
    const [draft, setDraft] = useState(content);
    const [dirty, setDirty] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Credentials" />

            <h1 className="sr-only">Credentials</h1>

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Credential file"
                        description="View and edit the plain-text file the social and API tools read their tokens from."
                    />

                    <Alert>
                        <Lock className="h-4 w-4" />
                        <AlertTitle>
                            Stored on disk, outside the database
                        </AlertTitle>
                        <AlertDescription>
                            This file lives at{' '}
                            <code className="font-mono text-xs">{path}</code>
                            {!exists &&
                                ' and will be created when you save for the first time'}
                            . It is read by the Instagram, Facebook, YouTube,
                            WhatsApp and other tools whenever a token is not
                            passed directly to the tool call.
                        </AlertDescription>
                    </Alert>

                    <Form
                        {...CredentialsController.update.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setDirty(false)}
                    >
                        {({ errors, processing, wasSuccessful }) => (
                            <div className="space-y-4">
                                <Card>
                                    <CardHeader>
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                                                <KeyRound className="h-5 w-5" />
                                            </div>
                                            <div>
                                                <CardTitle>
                                                    {path.split('/').pop()}
                                                </CardTitle>
                                                <CardDescription>
                                                    {exists
                                                        ? 'One KEY=value pair per line. Lines starting with # are ignored.'
                                                        : 'File does not exist yet — it will be created on save.'}
                                                </CardDescription>
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="content">
                                                Contents
                                            </Label>
                                            <textarea
                                                id="content"
                                                name="content"
                                                value={draft}
                                                onChange={(event) => {
                                                    setDraft(
                                                        event.target.value,
                                                    );
                                                    setDirty(true);
                                                }}
                                                spellCheck={false}
                                                rows={20}
                                                placeholder={
                                                    '# Example\nINSTAGRAM_ACCESS_TOKEN=\nFACEBOOK_ACCESS_TOKEN=\nYOUTUBE_REFRESH_TOKEN='
                                                }
                                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50"
                                            />
                                        </div>

                                        {Object.entries(errors).map(
                                            ([field, message]) => (
                                                <p
                                                    key={field}
                                                    className="text-sm text-destructive"
                                                >
                                                    {message}
                                                </p>
                                            ),
                                        )}
                                    </CardContent>
                                </Card>

                                <div className="flex items-center gap-3">
                                    <Button
                                        type="submit"
                                        disabled={processing || !dirty}
                                    >
                                        <Save className="h-4 w-4" />
                                        {processing ? 'Saving…' : 'Save file'}
                                    </Button>
                                    {wasSuccessful && !dirty && (
                                        <span className="text-sm text-muted-foreground">
                                            Saved to {path}
                                        </span>
                                    )}
                                    {dirty && (
                                        <span className="text-sm text-muted-foreground">
                                            Unsaved changes
                                        </span>
                                    )}
                                </div>
                            </div>
                        )}
                    </Form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
