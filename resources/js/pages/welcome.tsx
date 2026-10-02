import { useEffect } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    ArrowUpRight,
    Bot,
    Brain,
    Clock,
    Lock,
    MemoryStick,
    Play,
    SquareTerminal,
} from 'lucide-react';
import FadingVideo from '@/components/fading-video';
import BlurText from '@/components/blur-text';
import { dashboard, login, register } from '@/routes';
import { index as chatRoute } from '@/routes/chat';
import { index as missionsRoute } from '@/routes/missions';
import { index as skillsRoute } from '@/routes/skills';

const blurIn = {
    initial: { filter: 'blur(10px)', opacity: 0, y: 20 },
    animate: { filter: 'blur(0px)', opacity: 1, y: 0 },
};

const transition = (delay: number) => ({
    duration: 0.8,
    delay,
    ease: 'easeOut' as const,
});

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth } = usePage().props;

    useEffect(() => {
        const html = document.documentElement;
        const body = document.body;
        const prevHtmlBg = html.style.background;
        const prevBodyBg = body.style.background;
        const prevBodyFont = body.style.fontFamily;
        const prevBodyColor = body.style.color;
        html.style.background = '#000';
        body.style.background = '#000';
        body.style.fontFamily = "'Barlow', sans-serif";
        body.style.color = '#fff';
        return () => {
            html.style.background = prevHtmlBg;
            body.style.background = prevBodyBg;
            body.style.fontFamily = prevBodyFont;
            body.style.color = prevBodyColor;
        };
    }, []);

    const launchHref = auth.user
        ? dashboard()
        : canRegister
          ? register()
          : login();
    const launchLabel = auth.user ? 'Launch Dashboard' : 'Get Started';

    const navLinks = [
        { label: 'Chat', href: chatRoute() },
        { label: 'Missions', href: missionsRoute() },
        { label: 'Skills', href: skillsRoute() },
        { label: 'Settings', href: login() },
    ];

    const capabilities = [
        {
            icon: Brain,
            tags: ['Chat', 'Missions', 'Agent Tasks', 'Orchestration'],
            title: 'Plan',
            body: 'Long-horizon autonomous engineering. Missions run orchestrator, worker, and validator roles \u2014 planning features, handing off context, and validating against a contract.',
        },
        {
            icon: SquareTerminal,
            tags: ['Files', 'Shell', 'Web', 'Skills'],
            title: 'Build',
            body: 'Read and write files, execute shell commands, and search the web from your own machine. Teach reusable skills your agent calls again and again.',
        },
        {
            icon: MemoryStick,
            tags: ['Memories', 'Triggers', 'Scheduled', 'Reports'],
            title: 'Remember',
            body: 'Persistent memory across sessions, event-driven triggers, cron scheduling, and proactive findings \u2014 an employee who shows up even when you don\u2019t.',
        },
    ];

    return (
        <>
            <Head title="Welcome">
                <link rel="preconnect" href="https://fonts.bunny.net" />
                <link
                    href="https://fonts.bunny.net/css?family=instrument-serif:400|barlow:300,400,500,600"
                    rel="stylesheet"
                />
            </Head>

            <div className="min-h-screen bg-black font-body">
                {/* ===== HERO ===== */}
                <section className="relative h-screen overflow-hidden bg-black">
                    <FadingVideo
                        src="https://d8j0ntlcm91z4.cloudfront.net/user_38xzZboKViGWJOttwIXH07lWA1P/hf_20260619_191346_9d19d66e-86a4-47f7-8dc6-712c1788c3b2.mp4"
                        className="absolute top-0 left-1/2 z-0 -translate-x-1/2 object-cover object-top"
                        style={{ width: '120%', height: '120%' }}
                    />

                    <div className="relative z-10 flex h-full flex-col">
                        {/* Navbar */}
                        <nav className="fixed top-4 right-0 left-0 z-50 flex items-center justify-between px-8 lg:px-16">
                            <Link
                                href={launchHref}
                                className="liquid-glass flex h-12 w-12 items-center justify-center rounded-full transition-transform hover:scale-105"
                            >
                                <Bot className="h-5 w-5 text-teal-400" />
                            </Link>
                            <div className="hidden items-center gap-2 md:flex">
                                <div className="liquid-glass rounded-full px-1.5 py-1.5">
                                    <div className="flex items-center gap-1">
                                        {navLinks.map((item) => (
                                            <Link
                                                key={item.label}
                                                href={item.href}
                                                className="rounded-full px-3 py-2 font-body text-sm font-medium text-white/90 transition-colors hover:text-white"
                                            >
                                                {item.label}
                                            </Link>
                                        ))}
                                        <Link
                                            href={launchHref}
                                            className="ml-1 flex items-center gap-1.5 rounded-full bg-white px-4 py-2 text-sm font-medium text-black transition-colors hover:bg-white/90"
                                        >
                                            {launchLabel}
                                            <ArrowUpRight className="h-4 w-4" />
                                        </Link>
                                    </div>
                                </div>
                            </div>
                            <div className="h-12 w-12" />
                        </nav>

                        {/* Main content */}
                        <div className="flex flex-1 flex-col items-center justify-center px-4 pt-24 text-center">
                            <motion.div
                                initial={blurIn.initial}
                                animate={blurIn.animate}
                                transition={transition(0.4)}
                                className="liquid-glass mb-6 rounded-full px-4 py-2"
                            >
                                <div className="flex items-center gap-2">
                                    <span className="rounded-full bg-teal-500 px-2 py-0.5 text-[11px] font-semibold text-black">
                                        Local
                                    </span>
                                    <span className="font-body text-sm text-white/90">
                                        Your data never leaves your machine
                                    </span>
                                </div>
                            </motion.div>

                            <div className="mt-6 max-w-4xl">
                                <BlurText
                                    text="Your AI Employee. Running 100% Locally."
                                    className="font-heading text-6xl leading-[0.8] tracking-[-4px] text-white italic md:text-7xl lg:text-[5.5rem]"
                                />
                            </div>

                            <motion.p
                                initial={blurIn.initial}
                                animate={blurIn.animate}
                                transition={transition(0.8)}
                                className="mt-4 max-w-2xl font-body text-sm leading-tight font-light text-white md:text-base"
                            >
                                LaraClaw is a self-hosted agent that plans,
                                executes, and remembers. Chat, launch missions,
                                schedule tasks, and teach skills \u2014 powered
                                by Ollama inference on your own hardware, no
                                cloud, no subscriptions.
                            </motion.p>

                            <motion.div
                                initial={blurIn.initial}
                                animate={blurIn.animate}
                                transition={transition(1.1)}
                                className="mt-6 flex items-center gap-6"
                            >
                                <Link
                                    href={launchHref}
                                    className="liquid-glass-strong flex items-center gap-2 rounded-full px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-white/10"
                                >
                                    {launchLabel}
                                    <ArrowUpRight className="h-4 w-4" />
                                </Link>
                                <Link
                                    href={chatRoute()}
                                    className="flex items-center gap-2 text-sm font-medium text-white/90 transition-colors hover:text-white"
                                >
                                    <Play className="h-4 w-4" />
                                    See it in action
                                </Link>
                            </motion.div>

                            <motion.div
                                initial={blurIn.initial}
                                animate={blurIn.animate}
                                transition={transition(1.3)}
                                className="mt-8 flex gap-4"
                            >
                                <div className="liquid-glass w-[220px] rounded-[1.25rem] p-5">
                                    <Clock className="h-5 w-5 text-teal-400" />
                                    <div className="mt-4 font-heading text-4xl leading-none tracking-[-1px] italic">
                                        24/7
                                    </div>
                                    <div className="mt-2 font-body text-sm font-light text-white/90">
                                        Scheduled tasks, triggers, and
                                        autonomous missions
                                    </div>
                                </div>
                                <div className="liquid-glass w-[220px] rounded-[1.25rem] p-5">
                                    <Lock className="h-5 w-5 text-teal-400" />
                                    <div className="mt-4 font-heading text-4xl leading-none tracking-[-1px] italic">
                                        100% Local
                                    </div>
                                    <div className="mt-2 font-body text-sm font-light text-white/90">
                                        Ollama inference on your hardware, zero
                                        cloud dependency
                                    </div>
                                </div>
                            </motion.div>
                        </div>

                        {/* Trust bar */}
                        <motion.div
                            initial={blurIn.initial}
                            animate={blurIn.animate}
                            transition={transition(1.4)}
                            className="flex flex-col items-center gap-4 pb-8"
                        >
                            <div className="liquid-glass rounded-full px-5 py-2 font-body text-sm text-white/80">
                                Works with any Ollama model
                            </div>
                            <div className="flex gap-12 md:gap-16">
                                {['qwen3', 'Llama', 'Mistral', 'Gemma'].map(
                                    (model) => (
                                        <span
                                            key={model}
                                            className="font-heading text-2xl tracking-tight text-white/40 italic md:text-3xl"
                                        >
                                            {model}
                                        </span>
                                    ),
                                )}
                            </div>
                        </motion.div>
                    </div>
                </section>

                {/* ===== CAPABILITIES ===== */}
                <section className="relative min-h-screen overflow-hidden bg-black">
                    <FadingVideo
                        src="https://d8j0ntlcm91z4.cloudfront.net/user_38xzZboKViGWJOttwIXH07lWA1P/hf_20260622_093722_ccfc7ebf-182f-419f-8a62-2dc02db7dd9d.mp4"
                        className="absolute inset-0 z-0 h-full w-full object-cover"
                    />

                    <div className="relative z-10 flex min-h-screen flex-col px-8 pt-24 pb-10 md:px-16 lg:px-20">
                        <div className="mb-auto">
                            <div className="mb-6 font-body text-sm text-white/80">
                                // Capabilities
                            </div>
                            <div className="font-heading text-6xl leading-[0.9] tracking-[-3px] italic md:text-7xl lg:text-[6rem]">
                                Plans, builds,
                                <br />
                                and remembers
                            </div>
                        </div>

                        <div className="mt-16 grid grid-cols-1 gap-6 md:grid-cols-3">
                            {capabilities.map((card) => {
                                const Icon = card.icon;
                                return (
                                    <div
                                        key={card.title}
                                        className="liquid-glass flex min-h-[360px] flex-col rounded-[1.25rem] p-6"
                                    >
                                        <div className="flex items-start justify-between">
                                            <div className="liquid-glass flex h-11 w-11 items-center justify-center rounded-[0.75rem]">
                                                <Icon className="h-5 w-5 text-teal-400" />
                                            </div>
                                            <div className="flex flex-wrap justify-end gap-1.5">
                                                {card.tags.map((tag) => (
                                                    <span
                                                        key={tag}
                                                        className="liquid-glass rounded-full px-3 py-1 font-body text-[11px] whitespace-nowrap text-white/90"
                                                    >
                                                        {tag}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>

                                        <div className="flex-1" />

                                        <div>
                                            <div className="font-heading text-3xl leading-none tracking-[-1px] italic md:text-4xl">
                                                {card.title}
                                            </div>
                                            <div className="mt-2 max-w-[32ch] font-body text-sm leading-snug font-light text-white/90">
                                                {card.body}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}
