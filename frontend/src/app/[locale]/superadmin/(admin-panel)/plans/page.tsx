'use client';

import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { fetchWithCsrf } from '@/lib/api';
import { 
  Plus, 
  RefreshCw, 
  Settings2, 
  Trash2, 
  Archive, 
  Inbox, 
  X, 
  ArrowLeft,
  Coins,
  Users,
  Send,
  Zap,
  Sparkles,
  Bot,
  Workflow,
  Check,
  Database
} from 'lucide-react';
import { toast } from 'sonner';
import { useHeaderStore } from '@/store/useHeaderStore';
import { useTranslations } from 'next-intl';
import SimpleLoader from '@/components/ui/SimpleLoader';

interface PlanPrice {
  currency_id: number;
  amount: number;
  billing_interval: string;
  currency?: {
    code: string;
    symbol: string;
    name?: string;
  };
}

interface Plan {
  id: number;
  name: string;
  description: string | null;
  stripe_product_id: string | null;
  trial_days: number;
  max_team_members: number;
  max_campaigns: number;
  max_integrations: number;
  allowed_integrations?: string[] | null;
  own_crm_access: boolean;
  max_channels: number;
  allowed_channels?: Record<string, number | boolean> | null;
  max_automations: number;
  flow_credits: number;
  monthly_ai_tokens?: number;
  has_flow_templates?: boolean;
  sort_order: number;
  is_active: boolean;
  deleted_at?: string | null;
  prices: PlanPrice[];
}

interface Currency {
  id: number;
  code: string;
  symbol: string;
  name?: string;
  is_active: boolean;
  is_default?: boolean;
}

const AVAILABLE_CHANNELS = [
  { 
    key: 'whatsapp', 
    name: 'WhatsApp Cloud API', 
    desc: 'Official Meta Cloud API messaging line', 
    logo: '/channels/whatsapp.webp', 
    defaultMax: 2 
  },
  { 
    key: 'whatsapp_baileys', 
    name: 'WhatsApp Baileys', 
    desc: 'Unofficial QR session connection line', 
    logo: '/channels/whatsapp.webp', 
    defaultMax: 1 
  },
  { 
    key: 'telegram', 
    name: 'Telegram Bot', 
    desc: 'Telegram Bot API bots integration', 
    logo: '/channels/telegram.webp', 
    defaultMax: 5 
  },
  { 
    key: 'instagram', 
    name: 'Instagram Direct', 
    desc: 'Instagram Business direct messaging', 
    logo: '/channels/instagram.svg', 
    defaultMax: 2 
  },
  { 
    key: 'messenger', 
    name: 'Facebook Messenger', 
    desc: 'Facebook Page Messenger channels', 
    logo: '/channels/messenger.webp', 
    defaultMax: 2 
  },
  { 
    key: 'sms', 
    name: 'SMS Gateways', 
    desc: 'Twilio, Telnyx & Plivo SMS numbers', 
    logo: '/channels/sms.svg', 
    defaultMax: 2 
  },
  { 
    key: 'email', 
    name: 'Email SMTP / Resend', 
    desc: 'SMTP, IMAP & Resend mail accounts', 
    logo: '/channels/email.svg', 
    defaultMax: 2 
  },
];

const AVAILABLE_INTEGRATIONS = [
  { 
    key: 'google_sheets', 
    name: 'Google Sheets', 
    desc: 'Spreadsheet contacts import & live sync', 
    logo: '/google_sheets.webp',
  },
  { 
    key: 'shopify', 
    name: 'Shopify Store', 
    desc: 'E-commerce orders & cart recovery sync', 
    logo: '/shopify.webp',
  },
  { 
    key: 'woocommerce', 
    name: 'WooCommerce', 
    desc: 'WordPress shop catalog & checkout sync', 
    logo: '/woocommerce.webp',
  },
  { 
    key: 'zoom', 
    name: 'Zoom Meetings', 
    desc: 'Automated video appointment links', 
    logo: '/zoom.webp',
  },
  { 
    key: 'teams', 
    name: 'Microsoft Teams', 
    desc: 'Team meeting links & scheduling sync', 
    logo: '/teams.webp',
  },
  { 
    key: 'hubspot', 
    name: 'HubSpot CRM', 
    desc: 'Bi-directional contacts & deals sync', 
    logo: '/hubspot.png',
  },
  { 
    key: 'salesforce', 
    name: 'Salesforce CRM', 
    desc: 'Enterprise lead & pipeline sync', 
    logo: '/salesforce.webp',
  },
  { 
    key: 'zoho', 
    name: 'Zoho CRM', 
    desc: 'Customer relationship data & lead sync', 
    logo: '/zoho_crm.png',
  },
  { 
    key: 'zapier', 
    name: 'Zapier Webhooks', 
    desc: 'Connect 5,000+ apps via webhooks', 
    logo: '/zapier.webp',
  },
  { 
    key: 'n8n', 
    name: 'n8n Automations', 
    desc: 'Self-hosted workflow automations', 
    logo: '/n8n.webp',
  },
];

// Helper to render channel logo
const renderChannelLogo = (key: string, className = "h-5 w-5") => {
  switch (key) {
    case 'whatsapp':
    case 'whatsapp_baileys':
      return <img src="/channels/whatsapp.webp" alt="WhatsApp" className={`${className} object-contain`} />;
    case 'telegram':
      return <img src="/channels/telegram.webp" alt="Telegram" className={`${className} object-contain`} />;
    case 'instagram':
      return <img src="/channels/instagram.svg" alt="Instagram" className={`${className} object-contain`} />;
    case 'messenger':
      return <img src="/channels/messenger.webp" alt="Messenger" className={`${className} object-contain`} />;
    case 'sms':
      return <img src="/channels/sms.svg" alt="SMS" className={`${className} object-contain`} />;
    case 'email':
      return <img src="/channels/email.svg" alt="Email" className={`${className} object-contain`} />;
    default:
      return null;
  }
};

// Helper to render single integration logo
const renderIntegrationLogo = (key: string, className = "h-5 w-5") => {
  const found = AVAILABLE_INTEGRATIONS.find(i => i.key === key);
  if (!found) return null;
  return <img src={found.logo} alt={found.name} className={`${className} object-contain`} />;
};

// Helper to resolve effective allowed channels for a plan
const getPlanAllowedChannels = (allowedChannels?: Record<string, any> | null): string[] => {
  if (!allowedChannels || typeof allowedChannels !== 'object' || Object.keys(allowedChannels).length === 0) {
    return AVAILABLE_CHANNELS.map(c => c.key);
  }
  const enabledKeys = Object.keys(allowedChannels).filter(k => {
    const val = allowedChannels[k];
    return val !== false && val !== 0 && val !== undefined;
  });
  return enabledKeys.length > 0 ? enabledKeys : AVAILABLE_CHANNELS.map(c => c.key);
};

// Helper to resolve effective allowed individual integration keys for a plan
const getPlanAllowedIntegrations = (allowedIntegrations?: string[] | null): string[] => {
  if (!allowedIntegrations || !Array.isArray(allowedIntegrations) || allowedIntegrations.length === 0) {
    return AVAILABLE_INTEGRATIONS.map(i => i.key);
  }

  const expandedKeys = new Set<string>();
  allowedIntegrations.forEach(key => {
    if (key === 'ecommerce') {
      expandedKeys.add('shopify');
      expandedKeys.add('woocommerce');
    } else if (key === 'meetings') {
      expandedKeys.add('zoom');
      expandedKeys.add('teams');
    } else if (key === 'crm') {
      expandedKeys.add('hubspot');
      expandedKeys.add('salesforce');
      expandedKeys.add('zoho');
    } else if (key === 'webhooks') {
      expandedKeys.add('zapier');
      expandedKeys.add('n8n');
    } else {
      expandedKeys.add(key);
    }
  });

  return Array.from(expandedKeys);
};

export default function PlansPage() {
  const t = useTranslations('Superadmin');
  const { setCustomHeader } = useHeaderStore();

  const [plans, setPlans] = useState<Plan[]>([]);
  const [currencies, setCurrencies] = useState<Currency[]>([]);
  const [loading, setLoading] = useState(true);
  const [view, setView] = useState<'list' | 'create' | 'edit'>('list');
  const [activeTab, setActiveTab] = useState<'active' | 'draft' | 'archived'>('active');
  const [mounted, setMounted] = useState(false);

  // Selected plan state
  const [selectedPlan, setSelectedPlan] = useState<Plan | null>(null);

  // Form fields
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [trialDays, setTrialDays] = useState(0);
  const [maxTeamMembers, setMaxTeamMembers] = useState(5);
  const [maxCampaigns, setMaxCampaigns] = useState(10);
  const [maxIntegrations, setMaxIntegrations] = useState(5);
  const [ownCrmAccess, setOwnCrmAccess] = useState(false);
  const [maxChannels, setMaxChannels] = useState(2);
  const [maxAutomations, setMaxAutomations] = useState(5);
  const [flowCredits, setFlowCredits] = useState(50);
  const [monthlyAiTokens, setMonthlyAiTokens] = useState(100000);
  const [sortOrder, setSortOrder] = useState(0);
  const [planPrices, setPlanPrices] = useState<Record<number, string>>({}); // currency_id -> monthly amount (string)
  const [planPricesYearly, setPlanPricesYearly] = useState<Record<number, string>>({}); // currency_id -> yearly amount (string)
  const [isActiveState, setIsActiveState] = useState(false);

  // Granular settings
  const [allowedChannels, setAllowedChannels] = useState<Record<string, { enabled: boolean; max: number }>>({});
  const [allowedIntegrations, setAllowedIntegrations] = useState<string[]>([]);
  const [hasFlowTemplates, setHasFlowTemplates] = useState<boolean>(true);

  const [defaultCurrency, setDefaultCurrency] = useState('');
  const primaryCurrency = currencies.find(c => c.is_default) || currencies.find(c => c.code === defaultCurrency) || currencies[0] || null;

  // Status transitions
  const [actionLoadingId, setActionLoadingId] = useState<number | null>(null);
  const [formLoading, setFormLoading] = useState(false);

  // Delete & Archive Confirmation states
  const [isConfirmOpen, setIsConfirmOpen] = useState(false);
  const [confirmType, setConfirmType] = useState<'archive' | 'unarchive' | 'delete' | 'deactivate'>('archive');
  const [confirmPlan, setConfirmPlan] = useState<Plan | null>(null);

  const fetchPlansAndCurrencies = async () => {
    setLoading(true);
    try {
      const [plansRes, currenciesRes] = await Promise.all([
        fetchWithCsrf('/admin/plans'),
        fetchWithCsrf('/admin/currencies'),
      ]);

      if (plansRes.ok && currenciesRes.ok) {
        const plansData = await plansRes.json();
        const currenciesData = await currenciesRes.json();

        setPlans(plansData.plans || []);
        if (plansData.default_currency) {
          setDefaultCurrency(plansData.default_currency);
        }
        
        const activeCurrencies = (currenciesData.currencies || []).filter((c: Currency) => c.is_active);
        setCurrencies(activeCurrencies);
      }
    } catch {
      toast.error(t('plans.messages.loadFailed'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    setMounted(true);
    fetchPlansAndCurrencies();
  }, []);

  // Sync Header elements
  useEffect(() => {
    if (view === 'list') {
      setCustomHeader(null);
    } else {
      const title = view === 'create' ? t('plans.createPlan') : t('plans.editPlan', { name: selectedPlan?.name || '' });

      setCustomHeader(
        <header className="h-16 border-b border-[#E8E8E6] px-8 flex items-center justify-between bg-white select-none shrink-0 w-full animate-fade-in">
          <div className="flex items-center gap-3">
            <button 
              type="button"
              onClick={() => {
                setView('list');
                setSelectedPlan(null);
              }}
              className="p-1 text-[#6B6B6B] hover:text-black transition-colors shrink-0 flex items-center justify-center cursor-pointer"
            >
              <ArrowLeft className="h-4.5 w-4.5 stroke-[2.5]" />
            </button>
            <h2 className="text-sm font-bold tracking-tight text-[#6B6B6B] uppercase">
              {title}
            </h2>
          </div>
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => {
                setView('list');
                setSelectedPlan(null);
              }}
              className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-semibold rounded-[6px] transition-all cursor-pointer flex items-center justify-center shadow-3xs"
            >
              {t('dashboard.cancel')}
            </button>
            {view === 'create' ? (
              <>
                <button
                  type="button"
                  onClick={() => handleCreateOrUpdatePlan(undefined, false)}
                  disabled={formLoading}
                  className="border border-[#E8E8E6] bg-white text-zinc-700 hover:text-black h-9 px-4 text-xs font-semibold rounded-[6px] transition-all cursor-pointer flex items-center gap-1.5 shadow-3xs"
                >
                  {formLoading && <RefreshCw className="h-3.5 w-3.5 animate-spin text-zinc-550" />}
                  <span>{t('plans.saveAsDraft')}</span>
                </button>
                <button
                  type="button"
                  onClick={() => handleCreateOrUpdatePlan(undefined, true)}
                  disabled={formLoading}
                  className="h-9 px-4 bg-zinc-955 hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer flex items-center gap-1.5"
                >
                  {formLoading && <RefreshCw className="h-3.5 w-3.5 animate-spin text-white" />}
                  <span>{t('plans.publish')}</span>
                </button>
              </>
            ) : (
              <button
                type="button"
                onClick={() => handleCreateOrUpdatePlan()}
                disabled={formLoading}
                className="h-9 px-4 bg-zinc-955 hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer flex items-center gap-1.5"
              >
                {formLoading && <RefreshCw className="h-3.5 w-3.5 animate-spin text-white" />}
                <span>{t('dashboard.saveChanges')}</span>
              </button>
            )}
          </div>
        </header>
      );
    }

    return () => {
      setCustomHeader(null);
    };
  }, [
    view,
    formLoading,
    selectedPlan,
    name,
    description,
    trialDays,
    maxTeamMembers,
    maxCampaigns,
    maxIntegrations,
    ownCrmAccess,
    maxChannels,
    maxAutomations,
    flowCredits,
    monthlyAiTokens,
    sortOrder,
    planPrices,
    planPricesYearly,
    isActiveState,
    allowedChannels,
    allowedIntegrations,
    hasFlowTemplates
  ]);

  const handleOpenCreate = () => {
    setName('');
    setDescription('');
    setTrialDays(0);
    setMaxTeamMembers(5);
    setMaxCampaigns(10);
    setMaxIntegrations(5);
    setOwnCrmAccess(false);
    setMaxChannels(2);
    setMaxAutomations(5);
    setFlowCredits(50);
    setMonthlyAiTokens(100000);
    setSortOrder(0);
    setIsActiveState(false);

    // Initial granular channels
    const initialChannels: Record<string, { enabled: boolean; max: number }> = {};
    AVAILABLE_CHANNELS.forEach(ch => {
      initialChannels[ch.key] = { enabled: true, max: ch.defaultMax };
    });
    setAllowedChannels(initialChannels);

    // Initial individual integrations (all enabled by default)
    setAllowedIntegrations(AVAILABLE_INTEGRATIONS.map(i => i.key));

    // Initial flow templates access
    setHasFlowTemplates(true);

    const initialPrices: Record<number, string> = {};
    const initialPricesYearly: Record<number, string> = {};
    if (primaryCurrency) {
      initialPrices[primaryCurrency.id] = '2499';
      initialPricesYearly[primaryCurrency.id] = '23990';
    }
    setPlanPrices(initialPrices);
    setPlanPricesYearly(initialPricesYearly);

    setView('create');
  };

  const handleOpenEdit = (plan: Plan) => {
    setSelectedPlan(plan);
    setName(plan.name);
    setDescription(plan.description || '');
    setTrialDays(plan.trial_days);
    setMaxTeamMembers(plan.max_team_members);
    setMaxCampaigns(plan.max_campaigns);
    setMaxIntegrations(plan.max_integrations);
    setOwnCrmAccess(plan.own_crm_access);
    setMaxChannels(plan.max_channels);
    setMaxAutomations(plan.max_automations);
    setFlowCredits(plan.flow_credits ?? 50);
    setMonthlyAiTokens(plan.monthly_ai_tokens ?? 100000);
    setSortOrder(plan.sort_order);
    setIsActiveState(plan.is_active);

    // Channels configuration
    const initialChannels: Record<string, { enabled: boolean; max: number }> = {};
    const existingAllowed = plan.allowed_channels;
    AVAILABLE_CHANNELS.forEach(ch => {
      if (existingAllowed && typeof existingAllowed === 'object') {
        const val = existingAllowed[ch.key];
        const isEnabled = val !== undefined && val !== false && val !== 0;
        const maxVal = typeof val === 'number' && val > 0 ? val : ch.defaultMax;
        initialChannels[ch.key] = { enabled: isEnabled, max: maxVal };
      } else {
        // Fallback for legacy plans
        initialChannels[ch.key] = { enabled: true, max: ch.defaultMax };
      }
    });
    setAllowedChannels(initialChannels);

    // Integrations configuration (expanding any legacy group keys to individual keys)
    if (plan.allowed_integrations && Array.isArray(plan.allowed_integrations)) {
      const rawList = plan.allowed_integrations;
      const expandedKeys = new Set<string>();
      
      rawList.forEach(key => {
        if (key === 'ecommerce') {
          expandedKeys.add('shopify');
          expandedKeys.add('woocommerce');
        } else if (key === 'meetings') {
          expandedKeys.add('zoom');
          expandedKeys.add('teams');
        } else if (key === 'crm') {
          expandedKeys.add('hubspot');
          expandedKeys.add('salesforce');
          expandedKeys.add('zoho');
        } else if (key === 'webhooks') {
          expandedKeys.add('zapier');
          expandedKeys.add('n8n');
        } else {
          expandedKeys.add(key);
        }
      });
      setAllowedIntegrations(Array.from(expandedKeys));
    } else {
      setAllowedIntegrations(AVAILABLE_INTEGRATIONS.map(i => i.key));
    }

    // Templates access
    setHasFlowTemplates(plan.has_flow_templates !== false);

    const initialPrices: Record<number, string> = {};
    const initialPricesYearly: Record<number, string> = {};
    if (primaryCurrency) {
      const monthlyMatch = plan.prices.find(p => p.currency_id === primaryCurrency.id && p.billing_interval === 'month') || plan.prices.find(p => p.billing_interval === 'month');
      const yearlyMatch = plan.prices.find(p => p.currency_id === primaryCurrency.id && p.billing_interval === 'year') || plan.prices.find(p => p.billing_interval === 'year');
      initialPrices[primaryCurrency.id] = monthlyMatch ? String(monthlyMatch.amount / 100) : '0';
      initialPricesYearly[primaryCurrency.id] = yearlyMatch ? String(yearlyMatch.amount / 100) : '0';
    }
    setPlanPrices(initialPrices);
    setPlanPricesYearly(initialPricesYearly);

    setView('edit');
  };

  const handleCreateOrUpdatePlan = async (e?: React.FormEvent, overrideActive?: boolean) => {
    if (e) e.preventDefault();
    setFormLoading(true);

    try {
      const pricesPayload: any[] = [];
      if (primaryCurrency) {
        const monthlyVal = planPrices[primaryCurrency.id] !== undefined ? planPrices[primaryCurrency.id] : '0';
        const yearlyVal = planPricesYearly[primaryCurrency.id] !== undefined ? planPricesYearly[primaryCurrency.id] : '0';
        pricesPayload.push({
          currency_id: primaryCurrency.id,
          amount: Math.round(Number(monthlyVal) * 100),
          billing_interval: 'month',
        });
        pricesPayload.push({
          currency_id: primaryCurrency.id,
          amount: Math.round(Number(yearlyVal) * 100),
          billing_interval: 'year',
        });
      }

      // Channels payload: map enabled channel keys to their numeric connection limit
      const channelsPayload: Record<string, number> = {};
      Object.entries(allowedChannels).forEach(([key, conf]) => {
        if (conf.enabled) {
          channelsPayload[key] = Math.max(1, Number(conf.max) || 1);
        }
      });

      const body = {
        name,
        description: description || null,
        trial_days: trialDays,
        max_team_members: maxTeamMembers,
        max_campaigns: maxCampaigns,
        max_integrations: maxIntegrations,
        allowed_integrations: allowedIntegrations,
        own_crm_access: ownCrmAccess,
        max_channels: maxChannels,
        allowed_channels: channelsPayload,
        has_flow_templates: hasFlowTemplates,
        max_automations: maxAutomations,
        flow_credits: flowCredits,
        monthly_ai_tokens: monthlyAiTokens,
        sort_order: sortOrder,
        is_active: overrideActive !== undefined ? overrideActive : isActiveState,
        prices: pricesPayload,
      };

      const url = view === 'create' ? '/admin/plans' : `/admin/plans/${selectedPlan?.id}`;
      const method = view === 'create' ? 'POST' : 'PUT';

      const response = await fetchWithCsrf(url, {
        method,
        body: JSON.stringify(body),
      });

      const data = await response.json();

      if (response.ok) {
        toast.success(view === 'create' ? t('plans.messages.createSuccess') : t('plans.messages.updateSuccess'));
        setView('list');
        setSelectedPlan(null);
        fetchPlansAndCurrencies();
      } else {
        throw new Error(data.message || 'Verification failed.');
      }
    } catch (err) {
      const msg = err instanceof Error ? err.message : t('plans.messages.createFailed');
      toast.error(msg);
    } finally {
      setFormLoading(false);
    }
  };

  const executeToggleActive = async (plan: Plan) => {
    setActionLoadingId(plan.id);
    try {
      const response = await fetchWithCsrf(`/admin/plans/${plan.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          name: plan.name,
          description: plan.description,
          trial_days: plan.trial_days,
          max_team_members: plan.max_team_members,
          max_campaigns: plan.max_campaigns,
          max_integrations: plan.max_integrations,
          allowed_integrations: plan.allowed_integrations,
          own_crm_access: plan.own_crm_access,
          max_channels: plan.max_channels,
          allowed_channels: plan.allowed_channels,
          has_flow_templates: plan.has_flow_templates !== false,
          max_automations: plan.max_automations,
          flow_credits: plan.flow_credits ?? 50,
          monthly_ai_tokens: plan.monthly_ai_tokens ?? 100000,
          sort_order: plan.sort_order,
          is_active: !plan.is_active,
          prices: plan.prices.map(p => ({
            currency_id: p.currency_id,
            amount: p.amount,
            billing_interval: p.billing_interval
          }))
        }),
      });

      if (response.ok) {
        toast.success(t('plans.messages.updateSuccess'));
        fetchPlansAndCurrencies();
      } else {
        throw new Error();
      }
    } catch {
      toast.error(t('plans.messages.updateFailed'));
    } finally {
      setActionLoadingId(null);
    }
  };

  const handleToggleActiveState = async (plan: Plan) => {
    if (plan.is_active) {
      setConfirmPlan(plan);
      setConfirmType('deactivate');
      setIsConfirmOpen(true);
      return;
    }
    await executeToggleActive(plan);
  };

  const handleConfirmAction = async () => {
    if (!confirmPlan) return;
    setActionLoadingId(confirmPlan.id);
    setIsConfirmOpen(false);

    try {
      if (confirmType === 'deactivate') {
        await executeToggleActive(confirmPlan);
      } else {
        let url = `/admin/plans/${confirmPlan.id}`;
        let method = 'DELETE'; // default for permanent delete

        if (confirmType === 'archive') {
          url = `/admin/plans/${confirmPlan.id}/archive`;
          method = 'POST';
        } else if (confirmType === 'unarchive') {
          url = `/admin/plans/${confirmPlan.id}/unarchive`;
          method = 'POST';
        }

        const response = await fetchWithCsrf(url, { method });
        if (response.ok) {
          toast.success(
            confirmType === 'archive' ? t('plans.messages.archiveSuccess') :
            confirmType === 'unarchive' ? t('plans.messages.unarchiveSuccess') :
            t('plans.messages.deleteSuccess')
          );
          fetchPlansAndCurrencies();
        } else {
          throw new Error();
        }
      }
    } catch {
      toast.error(
        confirmType === 'archive' ? t('plans.messages.archiveFailed') :
        confirmType === 'unarchive' ? t('plans.messages.unarchiveFailed') :
        confirmType === 'deactivate' ? t('plans.messages.updateFailed') :
        t('plans.messages.deleteFailed')
      );
    } finally {
      setActionLoadingId(null);
      setConfirmPlan(null);
    }
  };

  const filteredPlans = plans.filter((plan) => {
    if (activeTab === 'archived') {
      return Boolean(plan.deleted_at);
    }
    if (plan.deleted_at) {
      return false;
    }
    return activeTab === 'active' ? plan.is_active : !plan.is_active;
  });

  if (!mounted || loading) {
    return (
      <div className="flex-1 flex items-center justify-center p-16">
        <SimpleLoader />
      </div>
    );
  }

  return (
    <div className="flex-1 flex flex-col h-full bg-[#FAFAFA]">
      
      {/* 1. LIST VIEW */}
      {view === 'list' && (
        <div className="p-4 sm:p-6 md:p-8 max-w-7xl mx-auto w-full space-y-4 sm:space-y-6 animate-fade-in flex-1 overflow-y-auto">
          
          {/* Header Row */}
          <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 select-none">
            <div className="flex items-center gap-1 bg-[#F5F5F5] p-1 rounded-[8px] border border-[#E8E8E6] select-none overflow-x-auto">
              <button
                onClick={() => setActiveTab('active')}
                className={`px-3 py-1.5 text-xs font-bold rounded-[6px] transition-all cursor-pointer whitespace-nowrap ${
                  activeTab === 'active' 
                    ? 'bg-white text-zinc-955 shadow-3xs' 
                    : 'text-zinc-500 hover:text-zinc-800'
                }`}
              >
                {t('plans.activePlans')}
              </button>
              <button
                onClick={() => setActiveTab('draft')}
                className={`px-3 py-1.5 text-xs font-bold rounded-[6px] transition-all cursor-pointer whitespace-nowrap ${
                  activeTab === 'draft' 
                    ? 'bg-white text-zinc-955 shadow-3xs' 
                    : 'text-zinc-500 hover:text-zinc-800'
                }`}
              >
                {t('plans.draftPlans')}
              </button>
              <button
                onClick={() => setActiveTab('archived')}
                className={`px-3 py-1.5 text-xs font-bold rounded-[6px] transition-all cursor-pointer whitespace-nowrap ${
                  activeTab === 'archived' 
                    ? 'bg-white text-zinc-955 shadow-3xs' 
                    : 'text-zinc-500 hover:text-zinc-800'
                }`}
              >
                {t('plans.archivedPlans')}
              </button>
            </div>

            <button
              onClick={handleOpenCreate}
              className="h-9 px-4 bg-zinc-955 hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer flex items-center justify-center gap-1.5 self-start sm:self-auto shrink-0"
            >
              <Plus className="h-4 w-4" />
              <span>{t('plans.createPlan')}</span>
            </button>
          </div>

          {/* Offerings Grid */}
          {filteredPlans.length === 0 ? (
            <div className="border border-[#E8E8E6] bg-white rounded-[10px] p-16 text-center space-y-3 shadow-3xs">
              <Inbox className="h-10 w-10 text-zinc-300 mx-auto" />
              <p className="text-xs text-zinc-405 font-semibold">{t('plans.noPlansFound')}</p>
            </div>
          ) : (
              <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
              {filteredPlans.map((plan) => {
                const baseMonthly = plan.prices.find(p => p.currency_id === primaryCurrency?.id && p.billing_interval === 'month') || plan.prices.find(p => p.billing_interval === 'month');
                const baseYearly = plan.prices.find(p => p.currency_id === primaryCurrency?.id && p.billing_interval === 'year') || plan.prices.find(p => p.billing_interval === 'year');
                const currencySymbol = baseMonthly?.currency?.symbol || primaryCurrency?.symbol || '$';
                const currencyCode = baseMonthly?.currency?.code || primaryCurrency?.code || 'USD';

                const allowedChKeys = getPlanAllowedChannels(plan.allowed_channels);
                const allowedIntList = getPlanAllowedIntegrations(plan.allowed_integrations);

                return (
                  <div 
                    key={plan.id}
                    className="bg-white border border-[#E8E8E6] rounded-[10px] p-5 shadow-3xs hover:shadow-card-hover transition-all flex flex-col justify-between space-y-4 animate-fade-in"
                  >
                    <div className="space-y-3.5">
                      {/* Top Header: Name, Trial & Sort Order */}
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-2">
                            <h4 className="font-bold text-sm text-zinc-955 truncate">{plan.name}</h4>
                          </div>
                          {plan.description ? (
                            <p className="text-[11px] text-zinc-400 font-medium truncate mt-0.5">{plan.description}</p>
                          ) : (
                            <p className="text-[11px] text-zinc-350 font-normal italic mt-0.5">
                              {plan.trial_days > 0 ? `${plan.trial_days} days trial period` : 'Standard subscription'}
                            </p>
                          )}
                        </div>

                        <span className="text-[9px] font-mono font-bold text-zinc-400 bg-zinc-50 border border-zinc-200 px-1.5 py-0.5 rounded shrink-0">
                          #{plan.sort_order}
                        </span>
                      </div>

                      {/* Pricing Bar */}
                      <div className="flex items-baseline justify-between pb-3 border-b border-[#F0F0F0] select-none">
                        <div className="flex items-baseline gap-1">
                          {baseMonthly ? (
                            <>
                              <span className="text-2xl font-black text-zinc-955 font-mono tracking-tight">
                                {currencySymbol}{(baseMonthly.amount / 100).toLocaleString()}
                              </span>
                              <span className="text-[10px] text-zinc-400 font-bold uppercase tracking-wider">/mo</span>
                            </>
                          ) : (
                            <span className="text-xs text-zinc-400 font-semibold italic">Custom</span>
                          )}
                          {baseYearly && (
                            <span className="text-[10px] text-zinc-500 font-mono font-semibold ml-2">
                              ({currencySymbol}{(baseYearly.amount / 100).toLocaleString()}/yr)
                            </span>
                          )}
                        </div>
                      </div>

                      {/* Compact Quotas Specs (3x2 Micro-Grid) */}
                      <div className="grid grid-cols-3 gap-1.5 text-xs select-none">
                        <div className="bg-[#FAFAFA] border border-[#E8E8E6] rounded-[6px] px-2 py-1.5">
                          <span className="text-[9px] text-zinc-400 font-bold uppercase tracking-wider block">Seats</span>
                          <span className="font-mono font-bold text-zinc-900 text-xs">{plan.max_team_members}</span>
                        </div>
                        <div className="bg-[#FAFAFA] border border-[#E8E8E6] rounded-[6px] px-2 py-1.5">
                          <span className="text-[9px] text-zinc-400 font-bold uppercase tracking-wider block">Campaigns</span>
                          <span className="font-mono font-bold text-zinc-900 text-xs">{plan.max_campaigns}</span>
                        </div>
                        <div className="bg-[#FAFAFA] border border-[#E8E8E6] rounded-[6px] px-2 py-1.5">
                          <span className="text-[9px] text-zinc-400 font-bold uppercase tracking-wider block">Channels</span>
                          <span className="font-mono font-bold text-zinc-900 text-xs">{plan.max_channels}</span>
                        </div>
                        <div className="bg-[#FAFAFA] border border-[#E8E8E6] rounded-[6px] px-2 py-1.5">
                          <span className="text-[9px] text-zinc-400 font-bold uppercase tracking-wider block">Flows</span>
                          <span className="font-mono font-bold text-zinc-900 text-xs">{plan.max_automations}</span>
                        </div>
                        <div className="bg-[#FAFAFA] border border-[#E8E8E6] rounded-[6px] px-2 py-1.5">
                          <span className="text-[9px] text-zinc-400 font-bold uppercase tracking-wider block">AI Credits</span>
                          <span className="font-mono font-bold text-zinc-900 text-xs">{plan.flow_credits ?? 50}/mo</span>
                        </div>
                        <div className="bg-[#FAFAFA] border border-[#E8E8E6] rounded-[6px] px-2 py-1.5">
                          <span className="text-[9px] text-zinc-400 font-bold uppercase tracking-wider block">AI Tokens</span>
                          <span className="font-mono font-bold text-emerald-700 text-xs">
                            {plan.monthly_ai_tokens ? (plan.monthly_ai_tokens >= 1000000 ? `${(plan.monthly_ai_tokens / 1000000).toFixed(1)}M` : `${Math.round(plan.monthly_ai_tokens / 1000)}k`) : '100k'}/mo
                          </span>
                        </div>
                        <div className="bg-[#FAFAFA] border border-[#E8E8E6] rounded-[6px] px-2 py-1.5">
                          <span className="text-[9px] text-zinc-400 font-bold uppercase tracking-wider block">CRM Suite</span>
                          <span className={`text-[10px] font-bold ${plan.own_crm_access ? 'text-emerald-700 font-black' : 'text-zinc-400 font-medium'}`}>
                            {plan.own_crm_access ? 'Unlocked' : 'Locked'}
                          </span>
                        </div>
                      </div>

                      {/* Channels Allowed Cluster */}
                      <div className="space-y-1.5 pt-2 border-t border-[#F0F0F0]">
                        <div className="flex items-center justify-between">
                          <span className="text-[9px] font-bold text-zinc-400 uppercase tracking-wider">
                            Channels ({allowedChKeys.length > 0 ? allowedChKeys.length : AVAILABLE_CHANNELS.length})
                          </span>
                        </div>
                        <div className="flex items-center gap-1.5 flex-wrap">
                          {(allowedChKeys.length > 0 ? allowedChKeys : AVAILABLE_CHANNELS.map(c => c.key)).map((key) => {
                            const cap = plan.allowed_channels?.[key];
                            return (
                              <div
                                key={key}
                                className="h-6.5 w-6.5 flex items-center justify-center bg-zinc-50 border border-zinc-200/80 rounded-md p-1 shrink-0 shadow-3xs hover:border-black/30 transition-colors"
                                title={`${key} (Cap: ${cap ?? 'unlimited'})`}
                              >
                                {renderChannelLogo(key, "h-full w-full")}
                              </div>
                            );
                          })}
                        </div>
                      </div>

                      {/* Integrations Whitelist Cluster */}
                      <div className="space-y-1.5 pt-2 border-t border-[#F0F0F0]">
                        <div className="flex items-center justify-between">
                          <span className="text-[9px] font-bold text-zinc-400 uppercase tracking-wider">
                            Integrations ({allowedIntList.length})
                          </span>
                        </div>
                        <div className="flex items-center gap-1.5 flex-wrap">
                          {allowedIntList.length === 0 ? (
                            <span className="text-[10px] font-semibold text-zinc-400 italic">No external integrations</span>
                          ) : (
                            allowedIntList.map((intKey) => {
                              const found = AVAILABLE_INTEGRATIONS.find(i => i.key === intKey);
                              return (
                                <div
                                  key={intKey}
                                  className="h-6.5 w-6.5 flex items-center justify-center bg-zinc-50 border border-zinc-200/80 rounded-md p-1 shrink-0 shadow-3xs hover:border-black/30 transition-colors"
                                  title={found?.name || intKey}
                                >
                                  {renderIntegrationLogo(intKey, "h-full w-full")}
                                </div>
                              );
                            })
                          )}
                        </div>
                      </div>
                    </div>

                    {/* Action Bar Footer */}
                    <div className="border-t border-[#F0F0F0] pt-3.5 flex items-center justify-between gap-2 select-none">
                      <button
                        onClick={() => handleOpenEdit(plan)}
                        className="flex items-center justify-center gap-1.5 h-8 px-3 border border-[#E8E8E6] text-zinc-700 hover:text-black bg-white rounded-[6px] text-xs font-bold transition-all shadow-3xs cursor-pointer"
                      >
                        <Settings2 className="h-3.5 w-3.5" />
                        <span>{t('dashboard.manage')}</span>
                      </button>

                      <div className="flex items-center gap-1.5">
                        {/* Active/Draft toggling */}
                        {activeTab !== 'archived' && (
                          <button
                            onClick={() => handleToggleActiveState(plan)}
                            disabled={actionLoadingId === plan.id}
                            className={`flex items-center justify-center h-8 px-3 rounded-[6px] text-xs font-bold transition-all border cursor-pointer ${
                              plan.is_active 
                                ? 'bg-zinc-50 border-[#E8E8E6] text-[#6B6B6B] hover:text-black' 
                                : 'bg-zinc-900 border-zinc-900 text-white hover:bg-black'
                            }`}
                          >
                            {actionLoadingId === plan.id ? (
                              <RefreshCw className="h-3 w-3 animate-spin" />
                            ) : plan.is_active ? (
                              t('plans.deactivate')
                            ) : (
                              t('plans.activate')
                            )}
                          </button>
                        )}

                        {/* Archive/Unarchive buttons */}
                        {activeTab === 'archived' ? (
                          <>
                            <button
                              onClick={() => {
                                setConfirmPlan(plan);
                                setConfirmType('unarchive');
                                setIsConfirmOpen(true);
                              }}
                              className="flex items-center justify-center gap-1 h-8 px-3 bg-zinc-900 text-white hover:bg-black border border-zinc-900 text-xs font-bold rounded-[6px] transition-all cursor-pointer"
                            >
                              <RefreshCw className="h-3 w-3" />
                              <span>{t('plans.unarchive')}</span>
                            </button>
                            <button
                              onClick={() => {
                                setConfirmPlan(plan);
                                setConfirmType('delete');
                                setIsConfirmOpen(true);
                              }}
                              className="flex items-center justify-center h-8 w-8 text-zinc-400 hover:text-red-650 hover:bg-red-50 border border-transparent hover:border-red-100 rounded-[6px] transition-all cursor-pointer"
                              title={t('plans.deletePermanently')}
                            >
                              <Trash2 className="h-4 w-4" />
                            </button>
                          </>
                        ) : (
                          <button
                            onClick={() => {
                              setConfirmPlan(plan);
                              setConfirmType('archive');
                              setIsConfirmOpen(true);
                            }}
                            className="flex items-center justify-center h-8 w-8 text-zinc-400 hover:text-red-650 hover:bg-red-50 border border-transparent hover:border-red-100 rounded-[6px] transition-all cursor-pointer"
                            title={t('plans.archive')}
                          >
                            <Archive className="h-4 w-4" />
                          </button>
                        )}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}

      {/* 2. CREATE & EDIT VIEW (FULL-SCREEN FORM) */}
      {(view === 'create' || view === 'edit') && (
        <form onSubmit={handleCreateOrUpdatePlan} className="plan-form w-full bg-white animate-fade-in select-none flex-1 overflow-y-auto">
          
          {/* Section 1: Core Configuration Details */}
          <div className="px-8 py-8 border-b border-[#E8E8E6] grid grid-cols-3 gap-8">
            <div className="col-span-1">
              <h3 className="text-sm font-bold text-zinc-955 uppercase tracking-tight mb-1">{t('plans.planFeatures')}</h3>
              <p className="text-[11px] text-zinc-500 font-semibold leading-relaxed">{t('plans.planFeaturesDesc')}</p>
            </div>
            <div className="col-span-2 space-y-4">
              <div className="space-y-1.5">
                <label className="text-[10px] text-zinc-500 font-semibold uppercase tracking-wider block">{t('plans.planName')}</label>
                <input 
                  type="text"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder={t('plans.planNamePlaceholder')}
                  className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs focus:outline-none focus:border-black font-medium transition-colors"
                  required
                />
              </div>

              <div className="space-y-1.5">
                <label className="text-[10px] text-zinc-500 font-semibold uppercase tracking-wider block">{t('plans.planDescription')}</label>
                <textarea 
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder={t('plans.planDescPlaceholder')}
                  className="w-full min-h-[70px] py-2.5 px-3 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs focus:outline-none focus:border-black font-medium transition-colors resize-none"
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <label className="text-[10px] text-zinc-500 font-semibold uppercase tracking-wider block">{t('plans.trialPeriod')}</label>
                  <div className="relative flex items-center">
                    <input 
                      type="number"
                      value={trialDays}
                      onChange={(e) => setTrialDays(Number(e.target.value))}
                      className="w-full h-9 px-3 pr-12 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs focus:outline-none focus:border-black font-semibold transition-colors"
                      required
                      min={0}
                    />
                    <span className="absolute right-3 text-[10px] font-bold text-zinc-400 pointer-events-none">days</span>
                  </div>
                </div>
                <div className="space-y-1.5">
                  <label className="text-[10px] text-zinc-500 font-semibold uppercase tracking-wider block">{t('plans.sortOrder')}</label>
                  <input 
                    type="number"
                    value={sortOrder}
                    onChange={(e) => setSortOrder(Number(e.target.value))}
                    className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs focus:outline-none focus:border-black font-semibold transition-colors"
                    required
                  />
                </div>
              </div>
            </div>
          </div>

          {/* Section 2: Quotas & Resource Limitations */}
          <div className="px-8 py-8 border-b border-[#E8E8E6] grid grid-cols-3 gap-8">
            <div className="col-span-1">
              <h3 className="text-sm font-bold text-zinc-955 uppercase tracking-tight mb-1">{t('plans.quotaLimitations')}</h3>
              <p className="text-[11px] text-zinc-500 font-semibold leading-relaxed">
                Define the resource allocations, seat capacities, automation limits, and AI generation credits included in this subscription tier.
              </p>
            </div>
            <div className="col-span-2 grid grid-cols-2 gap-4">
              
              {/* Max Team Members */}
              <div className="bg-white border border-[#E8E8E6] rounded-xl p-4 flex items-center justify-between shadow-3xs transition-all hover:border-zinc-400">
                <div className="flex items-center gap-3">
                  <div className="h-9 w-9 rounded-lg bg-zinc-50 border border-zinc-200/80 flex items-center justify-center shrink-0 text-zinc-700 select-none shadow-3xs">
                    <Users className="h-4.5 w-4.5" />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-zinc-900 block mb-0.5">{t('plans.maxTeamMembers')}</label>
                    <span className="text-[11px] text-zinc-500 font-medium leading-tight block">{t('plans.maxSeatsDesc')}</span>
                  </div>
                </div>
                <div className="flex items-center gap-1.5 shrink-0 ml-3">
                  <input 
                    type="number"
                    min={1}
                    value={maxTeamMembers}
                    onChange={(e) => setMaxTeamMembers(Math.max(1, Number(e.target.value)))}
                    className="w-20 h-8 px-2.5 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-right text-xs font-mono font-bold focus:outline-none focus:border-black transition-colors"
                    required
                  />
                  <span className="text-[11px] font-semibold text-zinc-400">seats</span>
                </div>
              </div>

              {/* Max Campaigns */}
              <div className="bg-white border border-[#E8E8E6] rounded-xl p-4 flex items-center justify-between shadow-3xs transition-all hover:border-zinc-400">
                <div className="flex items-center gap-3">
                  <div className="h-9 w-9 rounded-lg bg-zinc-50 border border-zinc-200/80 flex items-center justify-center shrink-0 text-zinc-700 select-none shadow-3xs">
                    <Send className="h-4.5 w-4.5" />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-zinc-900 block mb-0.5">{t('plans.maxCampaigns')}</label>
                    <span className="text-[11px] text-zinc-500 font-medium leading-tight block">{t('plans.maxCampaignsDesc')}</span>
                  </div>
                </div>
                <div className="flex items-center gap-1.5 shrink-0 ml-3">
                  <input 
                    type="number"
                    min={0}
                    value={maxCampaigns}
                    onChange={(e) => setMaxCampaigns(Math.max(0, Number(e.target.value)))}
                    className="w-20 h-8 px-2.5 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-right text-xs font-mono font-bold focus:outline-none focus:border-black transition-colors"
                    required
                  />
                  <span className="text-[11px] font-semibold text-zinc-400">runs</span>
                </div>
              </div>

              {/* Max Channels (Global) */}
              <div className="bg-white border border-[#E8E8E6] rounded-xl p-4 flex items-center justify-between shadow-3xs transition-all hover:border-zinc-400">
                <div className="flex items-center gap-3">
                  <div className="h-9 w-9 rounded-lg bg-zinc-50 border border-zinc-200/80 flex items-center justify-center shrink-0 text-zinc-700 select-none shadow-3xs">
                    <Zap className="h-4.5 w-4.5" />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-zinc-900 block mb-0.5">Total Channel Limit</label>
                    <span className="text-[11px] text-zinc-500 font-medium leading-tight block">Maximum global connected messaging lines</span>
                  </div>
                </div>
                <div className="flex items-center gap-1.5 shrink-0 ml-3">
                  <input 
                    type="number"
                    min={1}
                    value={maxChannels}
                    onChange={(e) => setMaxChannels(Math.max(1, Number(e.target.value)))}
                    className="w-20 h-8 px-2.5 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-right text-xs font-mono font-bold focus:outline-none focus:border-black transition-colors"
                    required
                  />
                  <span className="text-[11px] font-semibold text-zinc-400">lines</span>
                </div>
              </div>

              {/* Max Automations */}
              <div className="bg-white border border-[#E8E8E6] rounded-xl p-4 flex items-center justify-between shadow-3xs transition-all hover:border-zinc-400">
                <div className="flex items-center gap-3">
                  <div className="h-9 w-9 rounded-lg bg-zinc-50 border border-zinc-200/80 flex items-center justify-center shrink-0 text-zinc-700 select-none shadow-3xs">
                    <Workflow className="h-4.5 w-4.5" />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-zinc-900 block mb-0.5">{t('plans.maxAutomations')}</label>
                    <span className="text-[11px] text-zinc-500 font-medium leading-tight block">{t('plans.maxAutomationsDesc')}</span>
                  </div>
                </div>
                <div className="flex items-center gap-1.5 shrink-0 ml-3">
                  <input 
                    type="number"
                    min={0}
                    value={maxAutomations}
                    onChange={(e) => setMaxAutomations(Math.max(0, Number(e.target.value)))}
                    className="w-20 h-8 px-2.5 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-right text-xs font-mono font-bold focus:outline-none focus:border-black transition-colors"
                    required
                  />
                  <span className="text-[11px] font-semibold text-zinc-400">flows</span>
                </div>
              </div>

              {/* Flow Credits (AI Generations) */}
              <div className="bg-white border border-[#E8E8E6] rounded-xl p-4 flex items-center justify-between shadow-3xs transition-all hover:border-zinc-400">
                <div className="flex items-center gap-3">
                  <div className="h-9 w-9 rounded-lg bg-zinc-50 border border-zinc-200/80 flex items-center justify-center shrink-0 text-zinc-700 select-none shadow-3xs">
                    <Sparkles className="h-4.5 w-4.5" />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-zinc-900 block mb-0.5">Flow AI Credits</label>
                    <span className="text-[11px] text-zinc-500 font-medium leading-tight block">Monthly generative AI workflow credits</span>
                  </div>
                </div>
                <div className="flex items-center gap-1.5 shrink-0 ml-3">
                  <input 
                    type="number"
                    min={0}
                    value={flowCredits}
                    onChange={(e) => setFlowCredits(Math.max(0, Number(e.target.value)))}
                    className="w-20 h-8 px-2.5 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-right text-xs font-mono font-bold focus:outline-none focus:border-black transition-colors"
                    required
                  />
                  <span className="text-[11px] font-semibold text-zinc-400">/mo</span>
                </div>
              </div>

              {/* Monthly AI Tokens */}
              <div className="bg-white border border-[#E8E8E6] rounded-xl p-4 flex items-center justify-between shadow-3xs transition-all hover:border-zinc-400">
                <div className="flex items-center gap-3">
                  <div className="h-9 w-9 rounded-lg bg-zinc-50 border border-zinc-200/80 flex items-center justify-center shrink-0 text-zinc-700 select-none shadow-3xs">
                    <Bot className="h-4.5 w-4.5" />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-zinc-900 block mb-0.5">{t('plans.monthlyAiTokens') || 'Monthly AI Tokens'}</label>
                    <span className="text-[11px] text-zinc-500 font-medium leading-tight block">{t('plans.monthlyAiTokensDesc') || 'Monthly LLM token quota for AI Chatbot, Agents & RAG'}</span>
                  </div>
                </div>
                <div className="flex items-center gap-1.5 shrink-0 ml-3">
                  <input 
                    type="number"
                    min={0}
                    step={10000}
                    value={monthlyAiTokens}
                    onChange={(e) => setMonthlyAiTokens(Math.max(0, Number(e.target.value)))}
                    className="w-24 h-8 px-2.5 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-right text-xs font-mono font-bold focus:outline-none focus:border-black transition-colors"
                    required
                  />
                  <span className="text-[11px] font-semibold text-zinc-400">tok/mo</span>
                </div>
              </div>

              {/* Native CRM Suite Access */}
              <div 
                onClick={() => setOwnCrmAccess(!ownCrmAccess)}
                className={`border rounded-xl p-4 flex items-center justify-between transition-all cursor-pointer shadow-3xs ${
                  ownCrmAccess 
                    ? 'bg-white border-[#E8E8E6] ring-1 ring-black/5' 
                    : 'bg-[#F9F9F8] border-[#EAEAEA] opacity-60 hover:opacity-100'
                }`}
              >
                <div className="flex items-center gap-3">
                  <div className="h-9 w-9 rounded-lg bg-zinc-50 border border-zinc-200/80 flex items-center justify-center shrink-0 text-zinc-700 select-none shadow-3xs">
                    <Database className="h-4.5 w-4.5" />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-zinc-900 block mb-0.5">{t('plans.ownCrmAccess')}</label>
                    <span className="text-[11px] text-zinc-500 font-medium leading-tight block">Contacts, Deals pipeline & Bookings suite</span>
                  </div>
                </div>

                <div className={`h-5 w-5 rounded-md flex items-center justify-center border transition-all shrink-0 ml-3 ${
                  ownCrmAccess 
                    ? 'bg-black border-black text-white' 
                    : 'bg-white border-zinc-300'
                }`}>
                  {ownCrmAccess && <Check className="h-3.5 w-3.5 stroke-[3]" />}
                </div>
              </div>

            </div>
          </div>

          {/* Section 3: Allowed Messaging Channels & Connection Caps */}
          <div className="px-8 py-8 border-b border-[#E8E8E6] grid grid-cols-3 gap-8">
            <div className="col-span-1">
              <h3 className="text-sm font-bold text-zinc-955 uppercase tracking-tight mb-1">Channels & Limits</h3>
              <p className="text-[11px] text-zinc-500 font-semibold leading-relaxed">
                Configure which channels are included in this plan and set the maximum allowed connection cap per channel type.
              </p>
            </div>
            <div className="col-span-2 space-y-3">
              <div className="grid grid-cols-2 gap-3.5">
                {AVAILABLE_CHANNELS.map((ch) => {
                  const channelState = allowedChannels[ch.key] || { enabled: true, max: ch.defaultMax };
                  const isEnabled = channelState.enabled;

                  return (
                    <div 
                      key={ch.key}
                      onClick={() => {
                        setAllowedChannels(prev => ({
                          ...prev,
                          [ch.key]: {
                            enabled: !isEnabled,
                            max: prev[ch.key]?.max || ch.defaultMax
                          }
                        }));
                      }}
                      className={`p-3.5 rounded-xl border transition-all cursor-pointer flex flex-col justify-between ${
                        isEnabled 
                          ? 'bg-white border-[#E8E8E6] shadow-3xs ring-1 ring-black/5' 
                          : 'bg-[#F9F9F8] border-[#EAEAEA] opacity-60 hover:opacity-100'
                      }`}
                    >
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                          <div className="h-9 w-9 flex items-center justify-center bg-zinc-50 border border-zinc-200/80 rounded-lg p-1.5 shrink-0 select-none shadow-3xs">
                            {renderChannelLogo(ch.key, "h-full w-full")}
                          </div>
                          <div>
                            <h4 className="text-xs font-bold text-zinc-900">{ch.name}</h4>
                            <p className="text-[10px] text-zinc-400 font-medium leading-tight mt-0.5">{ch.desc}</p>
                          </div>
                        </div>

                        <div className={`h-5 w-5 rounded-md flex items-center justify-center border transition-all shrink-0 ml-3 ${
                          isEnabled 
                            ? 'bg-black border-black text-white' 
                            : 'bg-white border-zinc-300'
                        }`}>
                          {isEnabled && <Check className="h-3.5 w-3.5 stroke-[3]" />}
                        </div>
                      </div>

                      {/* Connection Cap Input (Keeps uniform card height across grid) */}
                      <div 
                        className={`pt-2.5 mt-2 border-t border-zinc-100 flex items-center justify-between transition-opacity ${
                          isEnabled ? 'opacity-100' : 'opacity-30 pointer-events-none select-none'
                        }`}
                        onClick={(e) => e.stopPropagation()}
                      >
                        <span className="text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                          Max Connections Cap
                        </span>
                        <div className="flex items-center gap-1.5">
                          <input 
                            type="number"
                            min={1}
                            max={100}
                            disabled={!isEnabled}
                            value={channelState.max}
                            onChange={(e) => {
                              const val = Number(e.target.value);
                              setAllowedChannels(prev => ({
                                ...prev,
                                [ch.key]: {
                                  enabled: prev[ch.key]?.enabled ?? true,
                                  max: Math.max(1, val)
                                }
                              }));
                            }}
                            className="w-16 h-7 px-2 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-right text-xs font-mono font-bold focus:outline-none focus:border-black disabled:bg-zinc-100 disabled:text-zinc-400"
                            required
                          />
                          <span className="text-[10px] font-bold text-zinc-400">conn</span>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>

          {/* Section 4: Individual Allowed Integrations Whitelist */}
          <div className="px-8 py-8 border-b border-[#E8E8E6] grid grid-cols-3 gap-8">
            <div className="col-span-1">
              <h3 className="text-sm font-bold text-zinc-955 uppercase tracking-tight mb-1">Integrations Whitelist</h3>
              <p className="text-[11px] text-zinc-500 font-semibold leading-relaxed">
                Select which individual third-party integrations tenants on this plan are allowed to connect and utilize.
              </p>
            </div>
            <div className="col-span-2 space-y-3">
              <div className="grid grid-cols-2 gap-3.5">
                {AVAILABLE_INTEGRATIONS.map((intg) => {
                  const isSelected = allowedIntegrations.includes(intg.key);

                  return (
                    <div 
                      key={intg.key}
                      onClick={() => {
                        if (isSelected) {
                          setAllowedIntegrations(prev => prev.filter(k => k !== intg.key));
                        } else {
                          setAllowedIntegrations(prev => [...prev, intg.key]);
                        }
                      }}
                      className={`p-3.5 rounded-xl border transition-all cursor-pointer flex items-center justify-between ${
                        isSelected 
                          ? 'bg-white border-[#E8E8E6] shadow-3xs ring-1 ring-black/5' 
                          : 'bg-[#F9F9F8] border-[#EAEAEA] opacity-60 hover:opacity-100'
                      }`}
                    >
                      <div className="flex items-center gap-3">
                        <div className="h-9 w-9 flex items-center justify-center bg-zinc-50 border border-zinc-200/80 rounded-lg p-1.5 shrink-0 select-none shadow-3xs">
                          {renderIntegrationLogo(intg.key, "h-full w-full")}
                        </div>
                        <div>
                          <h4 className="text-xs font-bold text-zinc-900">{intg.name}</h4>
                          <p className="text-[10px] text-zinc-400 font-medium leading-tight mt-0.5">{intg.desc}</p>
                        </div>
                      </div>

                      <div className={`h-5 w-5 rounded-md flex items-center justify-center border transition-all shrink-0 ml-3 ${
                        isSelected 
                          ? 'bg-black border-black text-white' 
                          : 'bg-white border-zinc-300'
                      }`}>
                        {isSelected && <Check className="h-3.5 w-3.5 stroke-[3]" />}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>

          {/* Section 5: Pre-built Flow Templates Access */}
          <div className="px-8 py-8 border-b border-[#E8E8E6] grid grid-cols-3 gap-8">
            <div className="col-span-1">
              <h3 className="text-sm font-bold text-zinc-955 uppercase tracking-tight mb-1">Flow Templates Access</h3>
              <p className="text-[11px] text-zinc-500 font-semibold leading-relaxed">
                Determine whether tenants on this subscription plan can browse and instantiate ready-made automation flow blueprints.
              </p>
            </div>
            <div className="col-span-2">
              <div 
                onClick={() => setHasFlowTemplates(!hasFlowTemplates)}
                className={`p-4 rounded-xl border transition-all cursor-pointer flex items-center justify-between ${
                  hasFlowTemplates 
                    ? 'bg-white border-[#E8E8E6] shadow-3xs ring-1 ring-black/5' 
                    : 'bg-[#F9F9F8] border-[#EAEAEA] opacity-60 hover:opacity-100'
                }`}
              >
                <div className="flex items-center gap-3.5">
                  <div className="h-9 w-9 rounded-lg bg-zinc-50 border border-zinc-200/80 flex items-center justify-center shrink-0 text-zinc-800 select-none shadow-3xs">
                    <Workflow className="h-4.5 w-4.5" />
                  </div>
                  <div>
                    <h4 className="text-xs font-bold text-zinc-900">Pre-Built Automation Templates</h4>
                    <p className="text-[11px] text-zinc-500 font-medium leading-tight mt-0.5">
                      Allow subscribers to deploy official pre-configured blueprints (Support Triage, Lead Qualification, E-Commerce sync)
                    </p>
                  </div>
                </div>

                <div className={`h-5 w-5 rounded-md flex items-center justify-center border transition-all shrink-0 ml-3 ${
                  hasFlowTemplates 
                    ? 'bg-black border-black text-white' 
                    : 'bg-white border-zinc-300'
                }`}>
                  {hasFlowTemplates && <Check className="h-3.5 w-3.5 stroke-[3]" />}
                </div>
              </div>
            </div>
          </div>

          {/* Section 6: Pricing Settings */}
          <div className="px-8 py-8 border-b border-[#E8E8E6] grid grid-cols-3 gap-8">
            <div className="col-span-1">
              <h3 className="text-sm font-bold text-zinc-955 uppercase tracking-tight mb-1">{t('plans.pricingSettings')}</h3>
              <p className="text-[11px] text-zinc-500 font-semibold leading-relaxed">
                {t('plans.pricingSettingsDesc')}
              </p>
            </div>
            <div className="col-span-2">
              <div className="grid grid-cols-2 gap-4">
                {/* Monthly Rate */}
                <div className="space-y-1.5">
                  <label className="text-[10px] text-zinc-500 font-semibold uppercase tracking-wider block">
                    {t('plans.monthlyPrice')}
                  </label>
                  <div className="relative flex items-center">
                    <span className="absolute left-3 text-zinc-400 font-bold font-mono text-xs pointer-events-none">
                      {primaryCurrency?.symbol}
                    </span>
                    <input 
                      type="number"
                      step="0.01"
                      min="0"
                      value={planPrices[primaryCurrency?.id] || ''}
                      onChange={(e) => setPlanPrices(prev => ({ ...prev, [primaryCurrency?.id]: e.target.value }))}
                      placeholder="0.00"
                      className="w-full h-9 pl-7 pr-16 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs font-mono font-semibold text-zinc-900 focus:outline-none focus:border-black transition-colors"
                      required
                    />
                    <span className="absolute right-3 text-[10px] font-bold text-zinc-400 pointer-events-none">/ month</span>
                  </div>
                </div>

                {/* Annual Rate */}
                <div className="space-y-1.5">
                  <label className="text-[10px] text-zinc-500 font-semibold uppercase tracking-wider block">
                    {t('plans.annualPrice')}
                  </label>
                  <div className="relative flex items-center">
                    <span className="absolute left-3 text-zinc-400 font-bold font-mono text-xs pointer-events-none">
                      {primaryCurrency?.symbol}
                    </span>
                    <input 
                      type="number"
                      step="0.01"
                      min="0"
                      value={planPricesYearly[primaryCurrency?.id] || ''}
                      onChange={(e) => setPlanPricesYearly(prev => ({ ...prev, [primaryCurrency?.id]: e.target.value }))}
                      placeholder="0.00"
                      className="w-full h-9 pl-7 pr-16 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs font-mono font-semibold text-zinc-900 focus:outline-none focus:border-black transition-colors"
                      required
                    />
                    <span className="absolute right-3 text-[10px] font-bold text-zinc-400 pointer-events-none">/ year</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

        </form>
      )}

      {/* CONFIRMATION MODALS */}
      {isConfirmOpen && confirmPlan && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-3xs animate-fade-in p-4 select-none">
          <div className="bg-white border border-[#E8E8E6] rounded-[12px] p-6 max-w-sm w-full shadow-2xl space-y-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2 text-zinc-955">
                {confirmType === 'delete' ? (
                  <Trash2 className="h-5 w-5 text-red-650" />
                ) : confirmType === 'deactivate' ? (
                  <RefreshCw className="h-5 w-5 text-zinc-550" />
                ) : (
                  <Archive className="h-5 w-5 text-zinc-550" />
                )}
                <h4 className="font-bold text-sm">
                  {confirmType === 'delete' ? t('plans.confirmDeleteTitle') :
                   confirmType === 'archive' ? t('plans.confirmArchiveTitle') :
                   confirmType === 'unarchive' ? t('plans.confirmUnarchiveTitle') :
                   t('plans.confirmDeactivateTitle')}
                </h4>
              </div>
              <button 
                onClick={() => setIsConfirmOpen(false)}
                className="text-zinc-400 hover:text-black cursor-pointer"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            <p className="text-xs text-zinc-500 font-semibold leading-relaxed">
              {confirmType === 'delete' ? t('plans.confirmDeleteDesc', { name: confirmPlan.name }) :
               confirmType === 'archive' ? t('plans.confirmArchiveDesc', { name: confirmPlan.name }) :
               confirmType === 'unarchive' ? t('plans.confirmUnarchiveDesc', { name: confirmPlan.name }) :
               t('plans.confirmDeactivateDesc', { name: confirmPlan.name })}
            </p>

            <div className="flex items-center justify-end gap-2 pt-2">
              <button
                type="button"
                onClick={() => setIsConfirmOpen(false)}
                className="h-8 px-3 border border-[#E8E8E6] bg-white text-zinc-650 hover:text-black text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
              >
                {t('dashboard.cancel')}
              </button>
              <button
                type="button"
                onClick={handleConfirmAction}
                disabled={actionLoadingId === confirmPlan.id}
                className={`h-8 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer flex items-center gap-1.5 shadow-3xs ${
                  confirmType === 'delete' 
                    ? 'bg-red-650 hover:bg-red-700 text-white' 
                    : confirmType === 'deactivate'
                    ? 'bg-zinc-850 hover:bg-black text-white'
                    : 'bg-zinc-955 hover:bg-zinc-900 text-white'
                }`}
              >
                {actionLoadingId === confirmPlan.id && <RefreshCw className="h-3 w-3 animate-spin text-white" />}
                <span>
                  {confirmType === 'delete' ? t('plans.deletePermanently') :
                   confirmType === 'archive' ? t('plans.archive') :
                   confirmType === 'unarchive' ? t('plans.unarchive') :
                   t('plans.deactivate')}
                </span>
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

    </div>
  );
}
