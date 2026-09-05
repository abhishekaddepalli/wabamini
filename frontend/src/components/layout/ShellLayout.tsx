'use client';

import React, { useEffect, useState, useRef } from 'react';
import { createPortal } from 'react-dom';
import Image from 'next/image';
import { Link, usePathname, useRouter } from '@/i18n/routing';
import { fetchWithCsrf } from '@/lib/api';
import SimpleLoader from '@/components/ui/SimpleLoader';
import { 
  Users, 
  LogOut,
  Target,
  LayoutDashboard,
  Radio,
  Bot,
  Database,
  MessageSquare,
  Zap,
  Megaphone,
  Settings,
  Key,
  CreditCard,
  Activity,
  BookOpen,
  FileText,
  Calendar,
  UserCheck,
  ShieldAlert,
  Menu,
  X
} from 'lucide-react';
import { useBranding } from '@/components/BrandingThemeProvider';

interface WorkspaceProfile {
  id: number;
  name: string;
}

interface UserProfile {
  first_name: string;
  last_name: string;
  email: string;
}

interface ShellLayoutProps {
  children: React.ReactNode;
}

export default function ShellLayout({ children }: ShellLayoutProps) {
  const { brandingName, logoUrl } = useBranding();
  const router = useRouter();
  const pathname = usePathname();

  const [workspace, setWorkspace] = useState<WorkspaceProfile | null>(null);
  const [user, setUser] = useState<UserProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [logoutModalOpen, setLogoutModalOpen] = useState(false);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [mounted, setMounted] = useState(false);
  const [settingsExpanded, setSettingsExpanded] = useState(pathname.startsWith('/settings'));
  const [operationalModel, setOperationalModel] = useState<string>('byok');

  useEffect(() => {
    setMobileMenuOpen(false);
  }, [pathname]);

  useEffect(() => {
    if (pathname.startsWith('/settings')) {
      setSettingsExpanded(true);
    }
  }, [pathname]);

  useEffect(() => {
    setMounted(true);
    async function loadOperationalModel() {
      try {
        const res = await fetchWithCsrf('/settings/ai-providers');
        if (res.ok) {
          const data = await res.json();
          if (data.operational_model) setOperationalModel(data.operational_model);
        }
      } catch {}
    }
    loadOperationalModel();
  }, []);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setLogoutModalOpen(false);
      }
    };
    if (logoutModalOpen) {
      window.addEventListener('keydown', handleKeyDown);
    }
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [logoutModalOpen]);

  useEffect(() => {
    async function loadWorkspaceData() {
      try {
        const response = await fetchWithCsrf('/auth/me');
        if (response.ok) {
          const data = await response.json();
          setUser(data.user);

          // Onboarding Wizard redirect guard
          if (data.user && data.user.tenant && data.user.tenant.onboarding_step !== 'complete') {
            router.push('/onboarding');
            return;
          }

          if (data.user && data.user.tenant && data.user.tenant.onboarding_step === 'complete' && !data.user.tenant.plan_id) {
            router.push('/billing/plans');
            return;
          }

          if (data.user && data.user.workspaces && data.user.workspaces.length > 0) {
            setWorkspace(data.user.workspaces[0]);
          }
        }
      } catch (err) {
        console.error('Failed to load user workspace data', err);
      } finally {
        setLoading(false);
      }
    }
    loadWorkspaceData();
  }, []);

  const handleLogout = async () => {
    try {
      const response = await fetchWithCsrf('/auth/logout', { method: 'POST' });
      if (response.ok) {
        localStorage.removeItem('whatsomni_token');
        router.push('/login');
      }
    } catch (err) {
      console.error(err);
    }
  };

  interface NavItem {
    name: string;
    href: string;
    icon: React.ComponentType<{ className?: string }>;
    subitems?: { name: string; href: string; icon: React.ComponentType<{ className?: string }> }[];
  }

  interface NavGroup {
    title: string;
    items: NavItem[];
  }

  const navGroups: NavGroup[] = [
    {
      title: 'Analyze',
      items: [
        { name: 'Overview Panel', href: '/dashboard', icon: LayoutDashboard },
        { name: 'Shared Inbox', href: '/inbox', icon: MessageSquare },
      ]
    },
    {
      title: 'AI & Automation',
      items: [
        { name: 'AI Agents', href: '/agents', icon: Bot },
        { name: 'Knowledge Base', href: '/knowledge-base', icon: BookOpen },
      ]
    },
    {
      title: 'Communication & Marketing',
      items: [
        { name: 'Channels Connect', href: '/channels', icon: Radio },
        { name: 'Campaigns', href: '/campaigns', icon: Megaphone },
        { name: 'Templates', href: '/templates', icon: FileText },
      ]
    },
    {
      title: 'CRM & Audience',
      items: [
        { name: 'Contacts Directory', href: '/contacts', icon: Users },
        { name: 'Staff Directory', href: '/staff', icon: UserCheck },
        { name: 'CRM Pipeline', href: '/crm', icon: Target },
        { name: 'Calendar', href: '/calendar', icon: Calendar },
      ]
    },
    {
      title: 'Administration',
      items: [
        {
          name: 'Workspace Settings',
          href: '/settings',
          icon: Settings,
          subitems: [
            ...(operationalModel === 'byok' ? [
              { name: 'AI Providers', href: '/settings/ai-providers', icon: Key }
            ] : []),
            { name: 'Integrations', href: '/settings/integrations', icon: Database },
            { name: 'Team Members', href: '/settings/team', icon: Users },
            { name: 'Billing & Usage', href: '/settings/billing', icon: CreditCard },
            { name: 'Active Sessions', href: '/settings/sessions', icon: Activity },
          ]
        }
      ]
    }
  ];

  const getInitials = () => {
    if (!user) return 'WF';
    const f = user.first_name ? user.first_name[0] : '';
    const l = user.last_name ? user.last_name[0] : '';
    return (f + l).toUpperCase() || 'U';
  };

  if (loading) {
    return <SimpleLoader fullScreen={true} />;
  }

  return (
    <div className="min-h-screen bg-[#F4F4F2] flex flex-col md:flex-row relative">
      {/* Mobile Header Bar */}
      <header className="h-14 border-b border-[#E8E8E6] px-4 flex md:hidden items-center justify-between bg-white select-none shrink-0 z-20">
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={() => setMobileMenuOpen(true)}
            className="h-8.5 w-8.5 rounded-lg border border-[#E8E8E6] bg-white hover:bg-zinc-50 flex items-center justify-center text-zinc-700 transition-colors shadow-3xs cursor-pointer"
            aria-label="Open navigation"
          >
            <Menu className="h-4 w-4" />
          </button>
          <div className="flex items-center gap-2">
            <img 
              src={logoUrl || '/logo.svg'} 
              alt="Logo" 
              className="h-4.5 w-auto object-contain"
              onError={(e) => { e.currentTarget.src = '/logo.svg'; }}
            />
            <span className="text-xs font-black tracking-tight text-zinc-950 uppercase truncate max-w-[130px]">
              {brandingName || 'WhatsOmni'}
            </span>
          </div>
        </div>
      </header>

      {/* Mobile Slide-Over Navigation Drawer */}
      {mounted && mobileMenuOpen && (
        <div className="fixed inset-0 z-[9990] md:hidden flex select-none">
          <div 
            className="fixed inset-0 bg-black/40 backdrop-blur-xs"
            onClick={() => setMobileMenuOpen(false)}
          />
          <div className="relative w-[285px] max-w-[85vw] bg-white h-full shadow-2xl flex flex-col justify-between z-10 border-r border-[#E8E8E6]">
            <div className="h-14 px-4 border-b border-[#E8E8E6] flex items-center justify-between">
              <div className="flex items-center gap-2">
                <img 
                  src={logoUrl || '/logo.svg'} 
                  alt="Logo" 
                  className="h-4.5 w-auto object-contain"
                  onError={(e) => { e.currentTarget.src = '/logo.svg'; }}
                />
                <span className="text-xs font-black tracking-tight text-zinc-950 uppercase">
                  {brandingName || 'WhatsOmni'}
                </span>
              </div>
              <button
                type="button"
                onClick={() => setMobileMenuOpen(false)}
                className="h-8 w-8 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 transition-colors"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            <nav className="flex-1 p-3.5 space-y-3 overflow-y-auto">
              {navGroups.map((group) => (
                <div key={group.title} className="space-y-1">
                  <span className="text-[8px] font-black text-zinc-400 uppercase tracking-widest block px-1 mt-2 mb-1 first:mt-0">
                    {group.title}
                  </span>
                  <div className="space-y-0.5">
                    {group.items.map((item) => {
                      const Icon = item.icon;
                      const hasSubitems = !!item.subitems;
                      const isActive = hasSubitems
                        ? pathname === item.href || pathname.startsWith('/settings/')
                        : pathname === item.href || pathname?.startsWith(item.href + '/');

                      return (
                        <Link
                          key={item.name}
                          href={item.href}
                          onClick={() => setMobileMenuOpen(false)}
                          className={`flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-bold transition-all relative ${
                            isActive
                              ? 'bg-zinc-100 text-zinc-950 font-extrabold shadow-3xs'
                              : 'text-zinc-500 hover:text-zinc-900 hover:bg-zinc-50'
                          }`}
                        >
                          <Icon className={`h-4 w-4 shrink-0 ${isActive ? 'text-zinc-950' : 'text-zinc-400'}`} />
                          <span>{item.name}</span>
                        </Link>
                      );
                    })}
                  </div>
                </div>
              ))}
            </nav>

            <div className="p-3.5 border-t border-[#E8E8E6] bg-white space-y-2">
              <button 
                onClick={() => {
                  setMobileMenuOpen(false);
                  setLogoutModalOpen(true);
                }}
                className="w-full h-9 hover:bg-red-50 text-red-600 border border-red-200/60 rounded-lg text-xs font-extrabold flex items-center justify-center gap-2 cursor-pointer"
              >
                <LogOut className="h-4 w-4" />
                <span>Logout</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Left Navigation Sidebar Wrapper (Desktop) */}
      <aside className="hidden md:block sticky top-0 h-screen w-68 bg-[#F4F4F2] border-r border-[#E8E8E6] shrink-0">
        {/* Floating Card Design System */}
        <div className="m-4 p-6 bg-white border border-[#E8E8E6] rounded-[16px] h-[calc(100vh-32px)] shadow-[0_4px_24px_rgba(0,0,0,0.02)] flex flex-col justify-between">
          
          <div className="space-y-4">
            {/* Brand Logo Header block */}
            <div className="flex items-center gap-3 px-1 py-0.5">
              <div className="p-1.5 bg-zinc-50 border border-zinc-100 rounded-[8px] flex items-center justify-center">
                <img 
                  src={logoUrl || '/logo.svg'} 
                  alt="Logo" 
                  className="h-4.5 w-auto object-contain"
                  onError={(e) => { e.currentTarget.src = '/logo.svg'; }}
                />
              </div>
              <span className="text-sm font-black tracking-tight text-zinc-950 uppercase">
                {brandingName || 'WhatsOmni'}
              </span>
            </div>

            {/* User Profile & Workspace Context at Top */}
            <div className="p-2.5 bg-[#FAFAFA] border border-[#E8E8E6]/80 rounded-[8px] flex items-center shadow-3xs shrink-0 gap-2.5 min-w-0">
              <div className="h-7.5 w-7.5 rounded-full bg-gradient-to-tr from-emerald-600 to-emerald-500 text-white text-[10px] font-black flex items-center justify-center shrink-0 shadow-3xs select-none">
                {getInitials()}
              </div>
              <div className="flex flex-col min-w-0">
                <span className="text-[10px] font-black text-zinc-800 truncate leading-tight select-none">
                  {user ? `${user.first_name} ${user.last_name}` : 'WhatsOmni User'}
                </span>
                <span className="text-[8.5px] text-[#3DD43D] font-extrabold truncate tracking-tight mt-0.5 select-none uppercase">
                  {workspace?.name || 'Acme Workspace'}
                </span>
              </div>
            </div>

            {/* Navigation Menu Links list */}
            <div className="space-y-3 flex-1 min-h-0 flex flex-col">
              <span className="text-[8px] font-black text-zinc-400 uppercase tracking-widest block px-1 shrink-0">
                Workspace Menu
              </span>
              
              <nav className="space-y-3 overflow-y-auto flex-1 pr-1 select-none scrollbar-none min-h-0">
                {navGroups.map((group) => (
                  <div key={group.title} className="space-y-1">
                    <span className="text-[8px] font-black text-zinc-400 uppercase tracking-widest block px-1 mt-3.5 mb-1 first:mt-0">
                      {group.title}
                    </span>
                    <div className="space-y-0.5">
                      {group.items.map((item) => {
                        const Icon = item.icon;
                        const hasSubitems = !!item.subitems;
                        const isActive = hasSubitems
                          ? pathname === item.href || pathname.startsWith('/settings/')
                          : pathname === item.href || pathname?.startsWith(item.href + '/');

                        if (hasSubitems) {
                          return (
                            <div key={item.name} className="space-y-0.5">
                              <button
                                onClick={() => setSettingsExpanded(!settingsExpanded)}
                                className={`w-full flex items-center justify-between px-3 py-2 rounded-[8px] text-[11px] font-extrabold transition-all cursor-pointer select-none border ${
                                  isActive
                                    ? 'bg-zinc-50 border-[#E8E8E6] text-zinc-950 font-extrabold shadow-3xs'
                                    : 'border-transparent text-zinc-500 hover:text-zinc-900 hover:bg-zinc-50/70'
                                }`}
                              >
                                <div className="flex items-center gap-3">
                                  <Icon className={`h-4 w-4 shrink-0 ${isActive ? 'text-zinc-950 font-bold' : 'text-zinc-400'}`} />
                                  <span>{item.name}</span>
                                </div>
                                <svg 
                                  className={`h-3.5 w-3.5 text-zinc-400 transition-transform duration-200 ${settingsExpanded ? 'rotate-180 text-zinc-550' : ''}`}
                                  fill="none" 
                                  viewBox="0 0 24 24" 
                                  stroke="currentColor"
                                >
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 9l-7 7-7-7" />
                                </svg>
                              </button>

                              {settingsExpanded && item.subitems && (
                                <div className="pl-4 ml-4.5 space-y-1 mt-1 border-l border-zinc-200 relative">
                                  {item.subitems.map((sub) => {
                                    const SubIcon = sub.icon;
                                    const isSubActive = pathname === sub.href;
                                    return (
                                      <Link
                                        key={sub.name}
                                        href={sub.href}
                                        className={`group flex items-center justify-between py-1.5 text-[10px] font-bold transition-all relative ${
                                          isSubActive
                                            ? 'text-zinc-950 font-extrabold'
                                            : 'text-zinc-500 hover:text-zinc-900'
                                        }`}
                                      >
                                        <div className="flex items-center gap-2.5">
                                          {/* Custom tree dot */}
                                          <span className={`absolute -left-[21px] top-1/2 -translate-y-1/2 w-2 h-2 rounded-full border border-white transition-all duration-200 ${
                                            isSubActive ? 'bg-[#4AE54A] scale-110 shadow-[0_0_6px_rgba(74,229,74,0.6)]' : 'bg-zinc-300 group-hover:bg-zinc-400'
                                          }`} />
                                          <SubIcon className={`h-3.5 w-3.5 shrink-0 ${isSubActive ? 'text-zinc-950' : 'text-zinc-400 group-hover:text-zinc-600'}`} />
                                          <span>{sub.name}</span>
                                        </div>
                                      </Link>
                                    );
                                  })}
                                </div>
                              )}
                            </div>
                          );
                        }

                        return (
                          <Link
                            key={item.name}
                            href={item.href}
                            className={`flex items-center gap-3 px-3 py-2 rounded-[8px] text-[11px] font-extrabold transition-all relative border ${
                              isActive
                                ? 'bg-zinc-50 border-[#E8E8E6] text-zinc-950 font-extrabold shadow-3xs pl-4'
                                : 'border-transparent text-zinc-500 hover:text-zinc-900 hover:bg-zinc-50/70'
                            }`}
                          >
                            {isActive && (
                              <span className="absolute left-0 top-1/2 -translate-y-1/2 w-[3px] h-4 rounded-r-[2px] bg-[#4AE54A] shadow-[0_0_6px_rgba(74,229,74,0.6)]" />
                            )}
                            <Icon className={`h-4 w-4 shrink-0 transition-transform ${isActive ? 'scale-105 text-zinc-950' : 'text-zinc-400'}`} />
                            <span>{item.name}</span>
                          </Link>
                        );
                      })}
                    </div>
                  </div>
                ))}
              </nav>
            </div>
          </div>

          {/* Account Actions at bottom */}
          <div className="space-y-4 pt-4 border-t border-[#E8E8E6] shrink-0">
            {/* Logout button */}
            <button 
              onClick={() => setLogoutModalOpen(true)}
              className="w-full h-9 hover:bg-red-50/50 hover:border-red-100 border border-[#E8E8E6] rounded-[8px] text-[11px] font-extrabold flex items-center justify-center gap-2 text-red-600 hover:text-red-700 transition-all cursor-pointer shadow-2xs select-none"
            >
              <LogOut className="h-4 w-4" />
              <span>Logout Account</span>
            </button>

            {/* App Footer Tag */}
            <div className="text-[8px] text-zinc-400 font-black flex items-center justify-between px-1 tracking-wider uppercase pt-1">
              <span>WhatsOmni v1.0.0</span>
              <span className="h-1.5 w-1.5 rounded-full bg-[#4AE54A] shadow-[0_0_4px_rgba(74,229,74,0.4)]" />
            </div>
          </div>

        </div>
      </aside>

      {/* CONFIRM LOGOUT MODAL */}
      {mounted && logoutModalOpen && typeof window !== 'undefined' && createPortal(
        <div className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 animate-overlay-fade select-none">
          <div className="absolute inset-0" onClick={() => setLogoutModalOpen(false)} />
          <div className="bg-white border border-[#E8E8E6] rounded-xl max-w-sm w-full p-6 shadow-xl relative z-50 animate-modal-box text-left font-sans">
            {/* Header Section (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6">
              <div className="flex items-center gap-2.5">
                <div className="h-7 w-7 rounded bg-[#FAFAFA] border border-[#E8E8E6] p-1 flex items-center justify-center shrink-0">
                  <ShieldAlert className="h-4 w-4 text-red-600" />
                </div>
                <div>
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">Confirm Logout</h3>
                </div>
              </div>
              <button 
                type="button" 
                onClick={() => setLogoutModalOpen(false)}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none shrink-0"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            {/* Body Content */}
            <div className="space-y-4 pt-4">
              <p className="text-xs text-[#6B6B6B] leading-relaxed font-semibold">
                Are you sure you want to log out of your WhatsOmni account? Any unsaved changes in active sessions will be lost.
              </p>
            </div>

            {/* Footer Action Strip */}
            <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none">
              <button
                type="button"
                onClick={() => setLogoutModalOpen(false)}
                className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleLogout}
                className="h-9 px-4 bg-red-600 hover:bg-red-750 text-white font-bold text-xs rounded-[6px] shadow-sm transition-all cursor-pointer"
              >
                Log Out
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* Dynamic Page Content Wrapper */}
      <div className="flex-1 relative overflow-x-hidden min-h-screen bg-[#F4F4F2]">
        {children}
      </div>

    </div>
  );
}
