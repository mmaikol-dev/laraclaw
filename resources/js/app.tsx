import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Component,   StrictMode } from 'react';
import type {ErrorInfo, ReactNode} from 'react';
import { createRoot  } from 'react-dom/client';
import type {Root} from 'react-dom/client';
import { TooltipProvider } from '@/components/ui/tooltip';
import '../css/app.css';
import { initializeTheme } from '@/hooks/use-appearance';

interface ReactMountableElement extends HTMLElement {
    _x_react_root?: Root;
}

class AppErrorBoundary extends Component<
    { children: ReactNode },
    { error: Error | null }
> {
    constructor(props: { children: ReactNode }) {
        super(props);
        this.state = { error: null };
    }

    static getDerivedStateFromError(error: Error) {
        return { error };
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        console.error('[LaraClaw] Uncaught render error:', error, info);
    }

    render() {
        if (this.state.error !== null) {
            return (
                <div style={{ padding: '2rem', fontFamily: 'monospace' }}>
                    <h2 style={{ marginBottom: '0.5rem' }}>
                        Something went wrong
                    </h2>
                    <p style={{ color: '#888', marginBottom: '1rem' }}>
                        {this.state.error.message}
                    </p>
                    <button
                        onClick={() => window.location.reload()}
                        style={{ padding: '0.5rem 1rem', cursor: 'pointer' }}
                    >
                        Reload page
                    </button>
                </div>
            );
        }

        return this.props.children;
    }
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        void navigator.serviceWorker
            .register('/sw.js')
            .catch((error: unknown) => {
                console.error(
                    '[LaraClaw] Service worker registration failed:',
                    error,
                );
            });
    });
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        // React 19 disallows calling createRoot() twice on the same container.
        // Inertia re-invokes setup() on every navigation, so cache the root on
        // the element and reuse it instead of re-creating it.
        const mountPoint = el as ReactMountableElement;

        if (!mountPoint._x_react_root) {
            mountPoint._x_react_root = createRoot(mountPoint);
        }

        mountPoint._x_react_root.render(
            <StrictMode>
                <AppErrorBoundary>
                    <TooltipProvider delayDuration={0}>
                        <App {...props} />
                    </TooltipProvider>
                </AppErrorBoundary>
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
