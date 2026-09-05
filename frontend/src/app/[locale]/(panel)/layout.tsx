'use client';

import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import Link from 'next/link';
import Image from 'next/image';
import { usePathname, useRouter } from '@/i18n/routing';
import { fetchWithCsrf } from '@/lib/api';
import SimpleLoader from '@/components/ui/SimpleLoader';
import { 
  Building2, 
  Settings2, 
  Users, 
  Megaphone, 
  Workflow, 
  LogOut, 
  Briefcase,
  Radio,
  ArrowLeft,
  MessageSquare,
  BookOpen,
  Sparkles,
  Bot,
  FileText,
  Calendar,
  HelpCircle,
  CreditCard,
  Link2,
  RefreshCw,
  ShieldAlert,
  UserCheck,
  Menu,
  X
} from 'lucide-react';
import { toast } from 'sonner';
import { motion, AnimatePresence } from 'framer-motion';
import { useHeaderStore } from '@/store/useHeaderStore';
import { useLocale, useTranslations } from 'next-intl';
import LanguageSwitcher from '@/components/LanguageSwitcher';
import NotificationBell from '@/components/layout/NotificationBell';
import { useBranding } from '@/components/BrandingThemeProvider';

export default function PanelLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const { brandingName, logoUrl } = useBranding();
  const pathname = usePathname();
  const router = useRouter();
  const locale = useLocale();
  
  const t = useTranslations();
  const tNav = useTranslations('Navigation');
  const tAgents = useTranslations('Agents');
  const tSettings = useTranslations('Settings');
  const tBilling = useTranslations('Billing');
  const tHelp = useTranslations('Help');
  const tCommon = useTranslations('Common');

  const isRtl = locale === 'ar' || locale === 'he';
  const { customHeader } = useHeaderStore();
  const [loading, setLoading] = useState(true);
  const [tenantName, setTenantName] = useState('Workspace Console');
  const [expandedGroup, setExpandedGroup] = useState<string | null>(null);
  const [userClosedGroups, setUserClosedGroups] = useState<Record<string, boolean>>({});
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [isImpersonated, setIsImpersonated] = useState(false);
  const [stopImpersonatingLoading, setStopImpersonatingLoading] = useState(false);
  const [mounted, setMounted] = useState(false);
  const [showLogoutModal, setShowLogoutModal] = useState(false);
  const [logoutLoading, setLogoutLoading] = useState(false);
  const [redirecting, setRedirecting] = useState(false);

  const [operationalModel, setOperationalModel] = useState<string>('byok');

  useEffect(() => {
    setMounted(true);
  }, []);

  useEffect(() => {
    setMobileMenuOpen(false);
  }, [pathname]);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setMobileMenuOpen(false);
        setShowLogoutModal(false);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, []);

  const checkAccessAndSync = async () => {
    try {
      const response = await fetchWithCsrf('/auth/me');
      if (response.ok) {
        const data = await response.json();
        const tenant = data.user?.tenant;
        setIsImpersonated(data.is_impersonated || false);
        
          if (tenant) {
            setTenantName(tenant.company_name || 'Workspace Console');
            
            // Route guards enforcement
            if (tenant.onboarding_step !== 'complete' && tenant.onboarding_step !== '3') {
              router.push('/onboarding');
              return;
            }
            
            // Allow bypassing plan check if path is /settings/billing and session_id is present
            const isBillingCallback = pathname.includes('/settings/billing') && typeof window !== 'undefined' && window.location.search.includes('session_id=');

            if (!tenant.plan_id && !pathname.startsWith('/billing/plans') && !pathname.startsWith('/settings') && !isBillingCallback) {
              router.push('/billing/plans');
              return;
            }
          }

          // Fetch AI operational model
          try {
            const aiRes = await fetchWithCsrf('/settings/ai-providers');
            if (aiRes.ok) {
              const aiData = await aiRes.json();
              if (aiData.operational_model) {
                setOperationalModel(aiData.operational_model);
              }
            }
          } catch {}

          // Dynamic User Language preference redirect
          if (data.user?.language && data.user.language !== locale) {
            document.cookie = `NEXT_LOCALE=${data.user.language}; path=/; max-age=31536000; SameSite=Lax`;
            window.location.href = `/${data.user.language}${pathname}`;
            return;
          }
      } else if (response.status === 401) {
        router.push('/login');
        return;
      }
    } catch {
      console.error('Failed to sync session contexts.');
    } finally {
      setLoading(false);
    }
  };

  const handleStopImpersonation = async () => {
    setStopImpersonatingLoading(true);
    try {
      const response = await fetchWithCsrf('/superadmin/impersonate/stop', {
        method: 'POST',
      });

      if (response.ok) {
        const data = await response.json();
        toast.success(data.message || 'Returned to administrator session.');
        if (data.redirect_url) {
          window.location.href = data.redirect_url;
        }
      } else {
        toast.error(t('toasts.stopImpersonationFailed'));
      }
    } catch {
      toast.error(t('toasts.connectionError'));
    } finally {
      setStopImpersonatingLoading(false);
    }
  };

  useEffect(() => {
    checkAccessAndSync();
    
    if (pathname.includes('/settings') && !pathname.includes('/settings/billing') && !pathname.includes('/settings/integrations')) {
      setExpandedGroup('Settings');
    } else if (pathname.includes('/help')) {
      setExpandedGroup('Help');
    } else {
      setExpandedGroup(null);
    }
  }, [pathname]);

  const handleLogout = async () => {
    setLogoutLoading(true);
    let isRedirecting = false;
    try {
      const response = await fetchWithCsrf('/auth/logout', {
        method: 'POST',
      });

      if (response.ok) {
        isRedirecting = true;
        setRedirecting(true);
        localStorage.removeItem('whatsomni_token');
        toast.success(t('toasts.logoutSuccess'));
        setShowLogoutModal(false);
        router.push('/login');
      }
    } catch {
      toast.error(t('toasts.logoutFailed'));
    } finally {
      if (!isRedirecting) {
        setLogoutLoading(false);
      }
    }
  };

  const navCategories = [
    {
      category: null,
      items: [
        {
          id: 'Dashboard',
          name: tNav('dashboard'),
          href: '/dashboard',
          icon: Building2,
        },
        {
          id: 'Inbox',
          name: tNav('inbox'),
          href: '/inbox',
          icon: MessageSquare,
        },
      ]
    },
    {
      category: 'AI & AUTOMATION',
      categoryKey: 'categoryAiAutomation',
      items: [
        {
          id: 'Chatbot',
          name: t.has('Chatbot.title') ? t('Chatbot.title') : 'AI ChatBot',
          href: '/chatbot',
          icon: Bot,
        },
        {
          id: 'Flows',
          name: tNav('flows') || 'Automation Flows',
          href: '/flows',
          icon: Workflow,
        },
        {
          id: 'Knowledge',
          name: tNav('knowledge') || 'Knowledge Base',
          href: '/knowledge-base',
          icon: BookOpen,
        },
      ]
    },
    {
      category: 'COMMUNICATION & MARKETING',
      categoryKey: 'categoryCommunication',
      items: [
        {
          id: 'Channels',
          name: tNav('channels'),
          href: '/channels',
          icon: Radio,
        },
        {
          id: 'Campaigns',
          name: tNav('campaigns'),
          href: '/campaigns',
          icon: Megaphone,
        },
        {
          id: 'Templates',
          name: tNav('templates'),
          href: '/templates',
          icon: FileText,
        },
      ]
    },
    {
      category: 'CRM & AUDIENCE',
      categoryKey: 'categoryCrm',
      items: [
        {
          id: 'Contacts',
          name: tNav('contacts'),
          href: '/contacts',
          icon: Users,
        },
        {
          id: 'Staff',
          name: tNav('staff') || 'Staff Directory',
          href: '/staff',
          icon: UserCheck,
        },
        {
          id: 'Deals',
          name: tNav('deals'),
          href: '/deals',
          icon: Briefcase,
        },
        {
          id: 'Calendar',
          name: tNav('calendar'),
          href: '/calendar',
          icon: Calendar,
        },
      ]
    },
    {
      category: 'SETTINGS & WORKSPACE',
      categoryKey: 'categorySettings',
      items: [
        {
          id: 'Billing',
          name: tBilling('title'),
          href: '/settings/billing',
          icon: CreditCard,
        },
        {
          id: 'Integrations',
          name: tNav('integrations'),
          href: '/settings/integrations',
          icon: Link2,
        },
        {
          id: 'Settings',
          name: tNav('settings'),
          href: '/settings/general',
          icon: Settings2,
          subItems: [
            {
              name: tSettings('generalTitle'),
              href: '/settings/general',
            },
            {
              name: tSettings('deviceSessions'),
              href: '/settings/sessions',
            },
            ...(operationalModel === 'byok' ? [
              {
                name: tSettings('aiProviders'),
                href: '/settings/ai-providers',
              }
            ] : []),
            {
              name: tSettings('teamAccess'),
              href: '/settings/team',
            },
          ]
        },
      ]
    },
    {
      category: 'SUPPORT',
      categoryKey: 'categorySupport',
      items: [
        {
          id: 'Help',
          name: tNav('help'),
          href: '/help/faq',
          icon: HelpCircle,
          subItems: [
            {
              name: tHelp('faqTitle'),
              href: '/help/faq',
            },
            {
              name: tHelp('ticketsTitle'),
              href: '/help/tickets',
            },
          ]
        },
      ]
    }
  ];

  // Render layout structure and use inline loader in page content area when loading is true

  const isEditor = pathname.includes('/flows/') && pathname.includes('/edit');

  if (isEditor) {
    return (
      <div className="h-screen w-screen bg-white font-sans text-black overflow-hidden flex flex-col">
        {children}
      </div>
    );
  }

  return (
    <div className="h-screen w-screen flex bg-white font-sans text-black overflow-hidden relative">
      {/* 1. Desktop Sidebar Navigation (Hidden on Mobile) */}
      <aside className={`hidden md:flex w-64 ${isRtl ? 'border-l' : 'border-r'} border-[#E8E8E6] bg-white flex-col shrink-0 select-none h-full`}>
        {/* Brand Header */}
        <div className="h-16 px-6 border-b border-[#E8E8E6] flex items-center gap-2.5">
          <img 
            src={logoUrl} 
            alt="Logo" 
            className="h-5 w-auto object-contain" 
            onError={(e) => { e.currentTarget.src = '/logo.svg'; }}
          />
          <span className="font-black text-sm tracking-tight uppercase">{brandingName}</span>
        </div>

        {/* Links Navigation */}
        <nav className="flex-1 px-4 py-4 space-y-4 overflow-y-auto">
          {navCategories.map((group, groupIdx) => (
            <div key={groupIdx} className="space-y-1">
              {group.category && (
                <div className="px-3 pt-2 pb-1">
                  <span className="text-[9px] font-black text-zinc-400 uppercase tracking-widest block">
                    {(() => {
                      if (!group.categoryKey) return group.category;
                      const val = tNav(group.categoryKey);
                      return (val.startsWith('Navigation.') || val.startsWith('NAVIGATION.')) ? group.category : val;
                    })()}
                  </span>
                </div>
              )}
              <div className="space-y-1">
                {group.items.map((link: any) => {
                  const hasSub = !!link.subItems;
                  const isSettingsPath = pathname.startsWith('/settings') && !pathname.startsWith('/settings/billing') && !pathname.startsWith('/settings/integrations');
                  const isHelpPath = pathname.startsWith('/help');

                  const isActive = hasSub 
                    ? (link.id === 'Settings' ? isSettingsPath : isHelpPath)
                    : (link.href === '/dashboard' ? pathname === '/dashboard' || pathname.endsWith('/dashboard') : pathname.startsWith(link.href));

                  const isGroupExpanded = expandedGroup === link.id || 
                    (userClosedGroups[link.id] !== true && ((link.id === 'Settings' && isSettingsPath) || (link.id === 'Help' && isHelpPath)));

                  const Icon = link.icon;

                  return (
                    <div key={link.id} className="space-y-1">
                      <Link 
                        href={link.href}
                        onClick={() => {
                          if (link.subItems) {
                            setUserClosedGroups(prev => ({ ...prev, [link.id]: false }));
                            setExpandedGroup(link.id);
                          } else {
                            setExpandedGroup(null);
                          }
                        }}
                        className={`flex items-center gap-3 pl-3.5 pr-3 py-2 rounded-lg text-xs font-bold tracking-tight transition-all relative ${
                          isActive
                            ? 'bg-zinc-50 text-black font-extrabold shadow-3xs' 
                            : 'text-[#6B6B6B] hover:bg-[#F5F5F5] hover:text-black'
                        }`}
                      >
                        {isActive && (
                          <motion.span
                            layoutId="sidebar-active-indicator"
                            className={`absolute ${isRtl ? 'right-0 rounded-l-full' : 'left-0 rounded-r-full'} top-2 bottom-2 w-[3px] bg-[#4AE54A]`}
                            transition={{ type: 'spring', stiffness: 380, damping: 30 }}
                          />
                        )}
                        <Icon className={`h-4 w-4 shrink-0 ${isActive ? 'text-black' : 'text-[#6B6B6B]'}`} />
                        <span>{link.name}</span>
                      </Link>
                      
                      <AnimatePresence initial={false}>
                        {hasSub && isGroupExpanded && (
                          <motion.div
                            key={link.id}
                            initial={{ height: 0, opacity: 0 }}
                            animate={{ height: 'auto', opacity: 1 }}
                            exit={{ height: 0, opacity: 0 }}
                            transition={{ duration: 0.2, ease: 'easeInOut' }}
                            className="pl-5 space-y-1 mt-1 ml-5 relative overflow-hidden"
                          >
                            {/* Vertical track line */}
                            <div className="absolute left-0 top-1.5 bottom-1.5 w-[1.5px] bg-zinc-100" />
                            {link.subItems.map((sub: any) => {
                              const isSubActive = pathname === sub.href || pathname.endsWith(sub.href);
                              return (
                                <Link
                                  key={sub.href}
                                  href={sub.href}
                                  onClick={() => {
                                    setUserClosedGroups(prev => ({ ...prev, [link.id]: false }));
                                    setExpandedGroup(link.id);
                                  }}
                                  className={`block py-1.5 text-[11.5px] font-semibold tracking-tight transition-all relative pl-4 select-none hover:translate-x-[2px] duration-200 ${
                                    isSubActive
                                      ? 'text-zinc-950 font-bold'
                                      : 'text-zinc-500 hover:text-zinc-900'
                                  }`}
                                >
                                  {isSubActive ? (
                                    <motion.span
                                      layoutId="sub-sidebar-active-indicator"
                                      className={`absolute ${isRtl ? 'right-[-1px]' : 'left-[-1px]'} top-2 bottom-2 w-[2px] bg-[#4AE54A] rounded-full`}
                                      transition={{ type: 'spring', stiffness: 380, damping: 30 }}
                                    />
                                  ) : (
                                    <span className={`absolute ${isRtl ? 'right-[-1.5px]' : 'left-[-1.5px]'} top-1/2 -translate-y-1/2 w-[3px] h-[3px] rounded-full bg-zinc-200 group-hover:bg-zinc-400 transition-colors`} />
                                  )}
                                  {sub.name}
                                </Link>
                              );
                            })}
                          </motion.div>
                        )}
                      </AnimatePresence>
                    </div>
                  );
                })}
              </div>
            </div>
          ))}
        </nav>

        {/* User Footer Profile */}
        <div className="p-5 border-t border-[#E8E8E6] bg-white">
          <div className="px-3 py-1.5 mb-3 bg-zinc-50 rounded-lg border border-[#E8E8E6]/60">
            <p className="text-[8px] font-black text-zinc-400 uppercase tracking-widest">
              {tCommon.has('activeWorkspace') ? tCommon('activeWorkspace') : 'Active Workspace'}
            </p>
            <p className="text-[10px] font-bold text-black truncate max-w-[170px]">{tenantName}</p>
          </div>
          <button 
            onClick={() => setShowLogoutModal(true)}
            className="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-xs font-bold text-[#6B6B6B] hover:bg-red-50 hover:text-red-600 transition-colors border border-transparent cursor-pointer"
          >
            <LogOut className="h-4 w-4" />
            {tCommon('logout')}
          </button>
        </div>
      </aside>

      {/* 2. Mobile Slide-Over Navigation Drawer */}
      <AnimatePresence>
        {mounted && mobileMenuOpen && (
          <div className="fixed inset-0 z-[9990] md:hidden flex select-none">
            {/* Backdrop */}
            <motion.div 
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              transition={{ duration: 0.2 }}
              className="fixed inset-0 bg-black/40 backdrop-blur-xs"
              onClick={() => setMobileMenuOpen(false)}
            />
            {/* Slide-over Drawer Panel */}
            <motion.div 
              initial={{ x: isRtl ? '100%' : '-100%' }}
              animate={{ x: 0 }}
              exit={{ x: isRtl ? '100%' : '-100%' }}
              transition={{ type: 'spring', damping: 25, stiffness: 280 }}
              className={`relative w-[285px] max-w-[85vw] bg-white h-full shadow-2xl flex flex-col justify-between z-10 ${isRtl ? 'ml-auto border-l' : 'mr-auto border-r'} border-[#E8E8E6]`}
            >
              {/* Drawer Brand Header */}
              <div className="h-14 px-4 border-b border-[#E8E8E6] flex items-center justify-between bg-white shrink-0">
                <div className="flex items-center gap-2.5">
                  <img 
                    src={logoUrl} 
                    alt="Logo" 
                    className="h-4.5 w-auto object-contain" 
                    onError={(e) => { e.currentTarget.src = '/logo.svg'; }}
                  />
                  <span className="font-black text-xs tracking-tight uppercase text-zinc-950">{brandingName}</span>
                </div>
                <button
                  type="button"
                  onClick={() => setMobileMenuOpen(false)}
                  className="h-8 w-8 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 transition-colors"
                  aria-label="Close navigation"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>

              {/* Navigation Links */}
              <nav className="flex-1 px-3 py-3 space-y-3 overflow-y-auto">
                {navCategories.map((group, groupIdx) => (
                  <div key={groupIdx} className="space-y-1">
                    {group.category && (
                      <div className="px-3 pt-2 pb-1">
                        <span className="text-[9px] font-black text-zinc-400 uppercase tracking-widest block">
                          {(() => {
                            if (!group.categoryKey) return group.category;
                            const val = tNav(group.categoryKey);
                            return (val.startsWith('Navigation.') || val.startsWith('NAVIGATION.')) ? group.category : val;
                          })()}
                        </span>
                      </div>
                    )}
                    <div className="space-y-0.5">
                      {group.items.map((link: any) => {
                        const hasSub = !!link.subItems;
                        const isSettingsPath = pathname.startsWith('/settings') && !pathname.startsWith('/settings/billing') && !pathname.startsWith('/settings/integrations');
                        const isHelpPath = pathname.startsWith('/help');

                        const isActive = hasSub 
                          ? (link.id === 'Settings' ? isSettingsPath : isHelpPath)
                          : (link.href === '/dashboard' ? pathname === '/dashboard' || pathname.endsWith('/dashboard') : pathname.startsWith(link.href));

                        const isGroupExpanded = expandedGroup === link.id || 
                          (userClosedGroups[link.id] !== true && ((link.id === 'Settings' && isSettingsPath) || (link.id === 'Help' && isHelpPath)));

                        const Icon = link.icon;

                        return (
                          <div key={link.id} className="space-y-0.5">
                            <Link 
                              href={link.href}
                              onClick={() => {
                                if (link.subItems) {
                                  setUserClosedGroups(prev => ({ ...prev, [link.id]: false }));
                                  setExpandedGroup(link.id);
                                } else {
                                  setExpandedGroup(null);
                                  setMobileMenuOpen(false);
                                }
                              }}
                              className={`flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-bold tracking-tight transition-all relative ${
                                isActive
                                  ? 'bg-zinc-100/80 text-black font-extrabold shadow-3xs' 
                                  : 'text-[#6B6B6B] hover:bg-[#F5F5F5] hover:text-black'
                              }`}
                            >
                              {isActive && (
                                <span className={`absolute ${isRtl ? 'right-0 rounded-l-full' : 'left-0 rounded-r-full'} top-2 bottom-2 w-[3px] bg-[#4AE54A]`} />
                              )}
                              <Icon className={`h-4 w-4 shrink-0 ${isActive ? 'text-black' : 'text-[#6B6B6B]'}`} />
                              <span>{link.name}</span>
                            </Link>
                            
                            {hasSub && isGroupExpanded && (
                              <div className="pl-4 space-y-1 mt-1 ml-4 relative border-l border-zinc-200">
                                {link.subItems.map((sub: any) => {
                                  const isSubActive = pathname === sub.href || pathname.endsWith(sub.href);
                                  return (
                                    <Link
                                      key={sub.href}
                                      href={sub.href}
                                      onClick={() => {
                                        setMobileMenuOpen(false);
                                      }}
                                      className={`block py-1.5 text-[11px] font-semibold tracking-tight transition-all pl-3 select-none ${
                                        isSubActive
                                          ? 'text-zinc-950 font-bold'
                                          : 'text-zinc-500 hover:text-zinc-900'
                                      }`}
                                    >
                                      {sub.name}
                                    </Link>
                                  );
                                })}
                              </div>
                            )}
                          </div>
                        );
                      })}
                    </div>
                  </div>
                ))}
              </nav>

              {/* Drawer Footer Profile & Sign Out */}
              <div className="p-3.5 border-t border-[#E8E8E6] bg-white shrink-0 space-y-2.5">
                <div className="px-3 py-1.5 bg-zinc-50 rounded-lg border border-[#E8E8E6]/60">
                  <p className="text-[8px] font-black text-zinc-400 uppercase tracking-widest">
                    {tCommon.has('activeWorkspace') ? tCommon('activeWorkspace') : 'Active Workspace'}
                  </p>
                  <p className="text-[10px] font-bold text-black truncate">{tenantName}</p>
                </div>
                <button 
                  onClick={() => {
                    setMobileMenuOpen(false);
                    setShowLogoutModal(true);
                  }}
                  className="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-xs font-bold text-red-600 hover:bg-red-50 transition-colors border border-red-200/60 cursor-pointer"
                >
                  <LogOut className="h-4 w-4" />
                  <span>{tCommon('logout')}</span>
                </button>
              </div>
            </motion.div>
          </div>
        )}
      </AnimatePresence>

      {/* 3. Main Content Area */}
      <main className="flex-1 flex flex-col min-w-0 bg-white h-full overflow-hidden">
        {/* Impersonation Banner */}
        {isImpersonated && (
          <div className="bg-zinc-950 text-white px-4 sm:px-8 py-2.5 sm:py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs font-bold tracking-tight select-none shrink-0 border-b border-zinc-800">
            <div className="flex items-center gap-2">
              <span className="h-1.5 w-1.5 bg-[#4AE54A] rounded-full animate-pulse shrink-0" />
              <span className="truncate">Impersonating <strong className="text-zinc-200">{tenantName}</strong></span>
            </div>
            <button
              onClick={handleStopImpersonation}
              disabled={stopImpersonatingLoading}
              className="flex items-center gap-1.5 px-2.5 py-1 bg-white text-black hover:bg-zinc-100 text-[10px] font-black uppercase tracking-wider rounded transition-all cursor-pointer disabled:opacity-50 shrink-0 self-start sm:self-auto"
            >
              {stopImpersonatingLoading ? (
                <RefreshCw className="h-3 w-3 animate-spin text-black" />
              ) : (
                'Stop'
              )}
            </button>
          </div>
        )}

        {/* Mobile Header Bar (< md) */}
        <header className="h-14 border-b border-[#E8E8E6] px-3.5 flex md:hidden items-center justify-between bg-white select-none shrink-0 z-10">
          <div className="flex items-center gap-2.5">
            <button
              type="button"
              onClick={() => setMobileMenuOpen(true)}
              className="h-8.5 w-8.5 rounded-lg border border-[#E8E8E6] bg-white hover:bg-zinc-50 flex items-center justify-center text-zinc-700 transition-colors shadow-3xs cursor-pointer"
              aria-label="Open mobile navigation menu"
            >
              <Menu className="h-4 w-4" />
            </button>
            <div className="flex items-center gap-2">
              <img 
                src={logoUrl} 
                alt="Logo" 
                className="h-4 w-auto object-contain" 
                onError={(e) => { e.currentTarget.src = '/logo.svg'; }}
              />
              <span className="font-black text-xs tracking-tight uppercase truncate max-w-[120px] text-zinc-950">
                {brandingName}
              </span>
            </div>
          </div>
          <div className="flex items-center gap-1.5">
            <NotificationBell />
            <LanguageSwitcher />
          </div>
        </header>

        {/* Desktop Header Bar (>= md) */}
        {customHeader ? (
          <div className="hidden md:block">
            {customHeader}
          </div>
        ) : (
          <header className="h-16 border-b border-[#E8E8E6] px-6 lg:px-8 hidden md:flex items-center justify-between bg-white select-none shrink-0">
            <h2 className="text-sm font-bold tracking-tight text-[#6B6B6B] uppercase truncate">
              {pathname.includes('/help/faq') ? tHelp('faqTitle') :
               pathname.includes('/help/tickets') ? tHelp('ticketsTitle') :
               pathname.includes('/settings/ai-providers') ? tSettings('aiProviders') :
               pathname.includes('/settings/integrations') ? tSettings('integrations') :
               pathname.includes('/settings/team') ? tSettings('teamAccess') :
               pathname.includes('/settings/billing') ? tBilling('title') :
               pathname.includes('/settings/sessions') ? tSettings('deviceSessions') :
               pathname.includes('/settings/general') ? tSettings('generalTitle') :
               pathname.includes('/settings') ? tNav('settings') : 
               pathname.includes('/contacts') ? tNav('contacts') : 
               pathname.includes('/deals') ? tNav('deals') :
               pathname.includes('/calendar') ? tNav('calendar') :
               pathname.includes('/staff') ? (tNav.has('staff') ? tNav('staff') : 'Staff Directory') :
               pathname.includes('/campaigns') ? tNav('campaigns') : 
               pathname.includes('/channels') ? tNav('channels') :
               pathname.includes('/flows') ? tNav('flows') : 
               pathname.includes('/inbox') ? tNav('inbox') : 
               pathname.includes('/knowledge-base') ? tNav('knowledge') :
               pathname.includes('/templates') ? tNav('templates') :
               pathname.includes('/chatbot') ? (t.has('Chatbot.title') ? t('Chatbot.title') : 'AI ChatBot') :
               pathname.includes('/agents') ? tAgents('title') : tNav('dashboard')}
            </h2>
            <div className="flex items-center gap-4">
              <NotificationBell />
              <LanguageSwitcher />
            </div>
          </header>
        )}

        {/* Custom Header on Mobile (< md) */}
        {customHeader && (
          <div className="md:hidden border-b border-[#E8E8E6] bg-white">
            {customHeader}
          </div>
        )}

        {loading ? (
          <div className="flex-1 flex items-center justify-center bg-white h-full w-full select-none">
            <SimpleLoader fullScreen={false} borderless={true} message={tSettings('syncingSession') || 'Syncing workspace session...'} />
          </div>
        ) : (
          <div className="flex-1 flex flex-col min-h-0 overflow-y-auto overflow-x-hidden bg-white">
            {children}
          </div>
        )}
      </main>

      {/* LOGOUT CONFIRMATION DIALOG PORTAL */}
      {showLogoutModal && mounted && createPortal(
        <div 
          className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 animate-overlay-fade select-none"
          onClick={() => setShowLogoutModal(false)}
        >
          <div 
            className="bg-white border border-[#E8E8E6] rounded-xl max-w-sm w-full p-6 shadow-xl relative z-50 animate-modal-box text-left font-sans"
            onClick={(e) => e.stopPropagation()}
          >
            {/* Header Section (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6">
              <div className="flex items-center gap-2.5">
                <div className="h-7 w-7 rounded bg-[#FAFAFA] border border-[#E8E8E6] p-1 flex items-center justify-center shrink-0">
                  <ShieldAlert className="h-4 w-4 text-red-600" />
                </div>
                <div>
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">
                    {tCommon.has('confirmLogoutTitle') ? tCommon('confirmLogoutTitle') : 'Confirm Sign-Out'}
                  </h3>
                </div>
              </div>
              <button 
                type="button" 
                onClick={() => setShowLogoutModal(false)}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none shrink-0"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            {/* Body */}
            <div className="space-y-4 pt-4">
              <p className="text-xs text-[#6B6B6B] leading-relaxed font-semibold">
                {tCommon.has('confirmLogoutText') ? tCommon('confirmLogoutText') : 'Are you sure you want to log out of your session? Any unsaved active changes may be lost.'}
              </p>
            </div>

            {/* Action Buttons Footer Strip */}
            <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none">
              <button
                type="button"
                onClick={() => setShowLogoutModal(false)}
                className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
              >
                {tCommon('cancel')}
              </button>
              <button
                type="button"
                onClick={handleLogout}
                disabled={logoutLoading}
                className="h-9 px-4 bg-red-600 hover:bg-red-750 text-white font-bold text-xs rounded-[6px] shadow-sm transition-all flex items-center justify-center min-w-[100px] cursor-pointer"
              >
                {logoutLoading ? <RefreshCw className="h-3.5 w-3.5 animate-spin text-zinc-400" /> : tCommon('logout')}
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {redirecting && (
        <div className="fixed inset-0 bg-white/80 backdrop-blur-md z-[9999] flex flex-col items-center justify-center space-y-4 select-none pointer-events-auto">
          <div className="w-10 h-10 border-4 border-[#0A0A0A] border-t-transparent rounded-full animate-spin" />
          <p className="text-sm font-bold text-black tracking-tight animate-pulse">Logging you out securely...</p>
        </div>
      )}
    </div>
  );
}
