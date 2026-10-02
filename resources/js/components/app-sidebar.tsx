import { Link } from '@inertiajs/react';
import { BookOpen, BotMessageSquare, FolderGit2, FolderOpen, LayoutGrid, ListChecks, MessageSquare, Rocket, SlidersHorizontal, Wrench, Zap } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { index as agentTasks } from '@/routes/agent-tasks';
import { dashboard } from '@/routes';
import { edit as editAgentSettings } from '@/routes/agent-settings';
import { index as chat } from '@/routes/chat';
import { index as employee } from '@/routes/employee';
import { index as files } from '@/routes/files';
import { index as missions } from '@/routes/missions';
import { index as skills } from '@/routes/skills';
import { index as tasks } from '@/routes/tasks';
import { index as tools } from '@/routes/tools';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Chat',
        href: chat(),
        icon: MessageSquare,
    },
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Files',
        href: files(),
        icon: FolderOpen,
    },
    {
        title: 'Tasks',
        href: tasks(),
        icon: ListChecks,
    },
    {
        title: 'Agent Tasks',
        href: agentTasks(),
        icon: BotMessageSquare,
    },
    {
        title: 'Tools',
        href: tools(),
        icon: Wrench,
    },
    {
        title: 'Skills',
        href: skills(),
        icon: Zap,
    },
    {
        title: 'Missions',
        href: missions(),
        icon: Rocket,
    },
    {
        title: 'Employee',
        href: employee(),
        icon: BotMessageSquare,
    },
    {
        title: 'Agent settings',
        href: editAgentSettings(),
        icon: SlidersHorizontal,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
