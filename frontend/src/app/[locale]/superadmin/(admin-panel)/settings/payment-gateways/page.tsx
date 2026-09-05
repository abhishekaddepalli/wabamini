'use client';

import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { fetchWithCsrf } from '@/lib/api';
import { 
  CreditCard, 
  ShieldCheck, 
  Check, 
  X, 
  RefreshCw, 
  Loader2, 
  Eye, 
  EyeOff, 
  Copy, 
  ExternalLink,
  SlidersHorizontal,
  Settings,
  Lock,
  Zap,
  Key
} from 'lucide-react';
import SimpleLoader from '@/components/ui/SimpleLoader';
import { DropdownSelect } from '@/components/ui/dropdown-select';
import { toast } from 'sonner';
import { useTranslations } from 'next-intl';

interface GatewayItem {
  slug: string;
  name: string;
  description: string;
  logo: string;
  is_ready: boolean;
  docs_url: string;
  enabled: boolean;
  is_active: boolean;
  mode: string;
  publishable_key: string;
  has_secret: boolean;
  masked_secret: string;
  has_webhook_secret: boolean;
  masked_webhook_secret: string;
  webhook_url: string;
}

export default function PaymentGatewaysPage() {
  const t = useTranslations('Superadmin');

  const [loading, setLoading] = useState(true);
  const [mounted, setMounted] = useState(false);
  const [activeGateway, setActiveGateway] = useState<string>('stripe');
  const [gateways, setGateways] = useState<Record<string, GatewayItem>>({});

  // Manage Dialog states
  const [selectedGatewaySlug, setSelectedGatewaySlug] = useState<string | null>(null);
  const [formMode, setFormMode] = useState<'test' | 'live'>('test');
  const [formPublishableKey, setFormPublishableKey] = useState('');
  const [formSecretKey, setFormSecretKey] = useState('');
  const [formWebhookSecret, setFormWebhookSecret] = useState('');
  const [formEnabled, setFormEnabled] = useState(true);
  const [formIsActive, setFormIsActive] = useState(false);
  const [showSecretKey, setShowSecretKey] = useState(false);
  const [showWebhookSecret, setShowWebhookSecret] = useState(false);

  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [activatingSlug, setActivatingSlug] = useState<string | null>(null);
  const [copiedWebhook, setCopiedWebhook] = useState(false);

  const fetchGateways = async () => {
    setLoading(true);
    try {
      const res = await fetchWithCsrf('/admin/payment-gateways');
      if (res.ok) {
        const data = await res.json();
        setActiveGateway(data.active_gateway || 'stripe');
        setGateways(data.gateways || {});
      } else {
        toast.error(t('paymentGateways.loadFailed') || 'Failed to load payment gateways configuration.');
      }
    } catch {
      toast.error(t('paymentGateways.loadFailed') || 'Failed to load payment gateways configuration.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    setMounted(true);
    fetchGateways();
  }, []);

  // Keyboard Escape listener
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setSelectedGatewaySlug(null);
      }
    };
    if (selectedGatewaySlug) {
      window.addEventListener('keydown', handleKeyDown);
    }
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [selectedGatewaySlug]);

  const handleOpenManage = (slug: string) => {
    const gw = gateways[slug];
    if (!gw) return;

    setSelectedGatewaySlug(slug);
    setFormMode((gw.mode as 'test' | 'live') || 'test');
    setFormPublishableKey(gw.publishable_key || '');
    setFormSecretKey(gw.has_secret ? gw.masked_secret : '');
    setFormWebhookSecret(gw.has_webhook_secret ? gw.masked_webhook_secret : '');
    setFormEnabled(gw.enabled);
    setFormIsActive(gw.is_active);
    setShowSecretKey(false);
    setShowWebhookSecret(false);
    setCopiedWebhook(false);
  };

  const getGatewayName = (slug?: string | null, defaultName: string = '') => {
    if (!slug) return defaultName;
    try {
      const translated = t(`paymentGateways.gateways.${slug}.name`);
      return translated && !translated.startsWith('paymentGateways.') ? translated : defaultName;
    } catch {
      return defaultName;
    }
  };

  const getGatewayDescription = (slug?: string | null, defaultDesc: string = '') => {
    if (!slug) return defaultDesc;
    try {
      const translated = t(`paymentGateways.gateways.${slug}.description`);
      return translated && !translated.startsWith('paymentGateways.') ? translated : defaultDesc;
    } catch {
      return defaultDesc;
    }
  };

  const handleSetActiveGateway = async (slug: string) => {
    const targetGw = gateways[slug];
    if (!targetGw?.has_secret) {
      toast.error(t('paymentGateways.onlyConfiguredCanBeActive') || 'Only configured payment gateways can be selected as primary active gateway.');
      return;
    }

    if (activeGateway === slug) return;
    setActivatingSlug(slug);
    try {
      const res = await fetchWithCsrf('/admin/payment-gateways', {
        method: 'POST',
        body: JSON.stringify({
          active_gateway: slug,
        })
      });
      const data = await res.json();
      if (res.ok) {
        const gwName = getGatewayName(slug, gateways[slug]?.name || slug);
        toast.success(t('paymentGateways.activeSwitched', { name: gwName }) || `${gwName} is now set as the primary payment gateway.`);
        setActiveGateway(data.summary?.active_gateway || slug);
        setGateways(data.summary?.gateways || {});
      } else {
        toast.error(data.message || t('paymentGateways.switchFailed') || 'Failed to switch active gateway.');
      }
    } catch {
      toast.error(t('paymentGateways.switchFailed') || 'Failed to switch active gateway.');
    } finally {
      setActivatingSlug(null);
    }
  };

  const handleCopyWebhook = (url: string) => {
    navigator.clipboard.writeText(url);
    setCopiedWebhook(true);
    toast.success(t('paymentGateways.webhookCopied') || 'Webhook URL copied to clipboard.');
    setTimeout(() => setCopiedWebhook(false), 2000);
  };

  const handleTestConnection = async () => {
    if (!selectedGatewaySlug) return;
    setTesting(true);

    try {
      const isMaskedSecret = formSecretKey.includes('••••');
      const payload: any = {
        gateway: selectedGatewaySlug,
        credentials: {
          publishable_key: formPublishableKey,
          mode: formMode,
        }
      };

      if (!isMaskedSecret && formSecretKey.trim()) {
        payload.credentials.secret_key = formSecretKey.trim();
      }

      const res = await fetchWithCsrf('/admin/payment-gateways/test', {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      const data = await res.json();
      if (res.ok && data.success) {
        toast.success(data.message || t('paymentGateways.testSuccess') || 'Payment gateway connection verified successfully!');
      } else {
        toast.error(data.message || t('paymentGateways.testFailed') || 'Connection test failed. Please verify your API keys.');
      }
    } catch {
      toast.error(t('paymentGateways.testNetworkError') || 'Network error during connection test.');
    } finally {
      setTesting(false);
    }
  };

  const handleSaveConfig = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedGatewaySlug) return;

    const currentGw = gateways[selectedGatewaySlug];
    const gwName = currentGw?.name || selectedGatewaySlug;
    if (!formPublishableKey.trim() && !currentGw?.publishable_key) {
      const label = selectedGatewaySlug === 'razorpay' ? (t('paymentGateways.keyId') || 'Key ID') : (selectedGatewaySlug === 'paystack' || selectedGatewaySlug === 'flutterwave') ? (t('paymentGateways.publicKey') || 'Public Key') : (t('paymentGateways.publishableKey') || 'Publishable Key');
      toast.error(t('paymentGateways.enterKeyError', { name: gwName, key: label }) || `Please enter the ${gwName} ${label}.`);
      return;
    }

    if (!formSecretKey.trim() && !currentGw?.has_secret) {
      const label = selectedGatewaySlug === 'razorpay' ? (t('paymentGateways.keySecret') || 'Key Secret') : (t('paymentGateways.secretKey') || 'Secret Key');
      toast.error(t('paymentGateways.enterKeyError', { name: gwName, key: label }) || `Please enter the ${gwName} ${label}.`);
      return;
    }

    if (formIsActive && !currentGw?.has_secret && !formSecretKey.trim()) {
      toast.error(t('paymentGateways.cannotActivateWithoutSecret') || 'Cannot activate payment gateway without configuring API credentials.');
      return;
    }

    setSaving(true);
    try {
      const gatewayPayload: any = {
        enabled: formEnabled,
        mode: formMode,
        publishable_key: formPublishableKey.trim(),
        public_key: formPublishableKey.trim(),
        secret_key: formSecretKey.trim(),
        webhook_secret: formWebhookSecret.trim(),
      };

      if (selectedGatewaySlug === 'razorpay') {
        gatewayPayload.key_id = formPublishableKey.trim();
        gatewayPayload.key_secret = formSecretKey.trim();
      }

      const res = await fetchWithCsrf('/admin/payment-gateways', {
        method: 'POST',
        body: JSON.stringify({
          active_gateway: formIsActive ? selectedGatewaySlug : activeGateway,
          gateways: {
            [selectedGatewaySlug]: gatewayPayload
          }
        })
      });

      const data = await res.json();
      if (res.ok) {
        toast.success(t('paymentGateways.saveSuccess') || 'Payment gateway settings saved successfully.');
        setActiveGateway(data.summary?.active_gateway || (formIsActive ? selectedGatewaySlug : activeGateway));
        setGateways(data.summary?.gateways || {});
        setSelectedGatewaySlug(null);
      } else {
        toast.error(data.message || t('paymentGateways.saveFailed') || 'Failed to save gateway settings.');
      }
    } catch {
      toast.error(t('paymentGateways.saveFailed') || 'Failed to save gateway settings.');
    } finally {
      setSaving(false);
    }
  };

  const handleBackdropClick = (e: React.MouseEvent<HTMLDivElement>) => {
    if (e.target === e.currentTarget) {
      setSelectedGatewaySlug(null);
    }
  };

  if (loading) {
    return <SimpleLoader message={t('paymentGateways.loading') || 'Loading payment gateways...'} />;
  }

  const selectedGateway = selectedGatewaySlug ? gateways[selectedGatewaySlug] : null;
  const activeGwObj = gateways[activeGateway] || Object.values(gateways)[0];
  const isActiveLive = activeGwObj?.mode === 'live';
  const configuredGateways = Object.values(gateways).filter(g => g.has_secret);
  const configuredCount = configuredGateways.length;
  const totalCount = Object.keys(gateways).length;

  return (
    <div className="w-full select-none text-zinc-750 selection:bg-zinc-100 animate-fade-in font-sans flex-1 overflow-y-auto flex flex-col">
      
      {/* 2. THREE-COLUMN EXECUTIVE STATS / SUMMARY DIVIDER BAR (End-to-End Connected) */}
      <div className="w-full bg-white border-b border-[#E8E8E6] grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-[#E8E8E6] shrink-0">
        
        {/* Column 1: Active Gateway & Security Vault */}
        <div className="p-4 sm:px-6 sm:py-4 flex flex-col justify-between space-y-2.5 bg-gradient-to-b from-white to-[#FAFAFA]/40">
          <div className="flex items-center justify-between">
            <span className="text-[10px] font-bold text-zinc-400 uppercase tracking-wider block">
              {t('paymentGateways.activeProviderLabel') || 'Active Provider'}
            </span>
            {activeGwObj?.has_secret && (
              <span className="text-[9px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                {t('paymentGateways.primaryActive') || 'PRIMARY ACTIVE'}
              </span>
            )}
          </div>

          <div className="flex items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              <div className="h-8 w-8 rounded-lg overflow-hidden shrink-0 border border-[#E8E8E6] bg-[#FAFAFA] flex items-center justify-center shadow-3xs">
                {activeGwObj?.logo ? (
                  <img src={activeGwObj.logo} alt={activeGwObj.name} className="h-full w-full object-cover" />
                ) : (
                  <CreditCard className="h-4 w-4 text-black" />
                )}
              </div>
              <div>
                <div className="text-xs font-bold text-black uppercase tracking-wider">
                  {getGatewayName(activeGwObj?.slug, activeGwObj?.name || activeGateway)}
                </div>
                <div className="text-[10px] text-zinc-400 font-medium">
                  {activeGwObj?.slug ? `${activeGwObj.slug.toUpperCase()} Engine` : 'Payment Engine'}
                </div>
              </div>
            </div>

            {/* Quick Switch Dropdown - ONLY for configured gateways */}
            {configuredGateways.length > 1 && (
              <div className="w-36">
                <DropdownSelect
                  value={activeGateway}
                  onChange={(val) => handleSetActiveGateway(val)}
                  options={configuredGateways.map(g => ({
                    value: g.slug,
                    label: getGatewayName(g.slug, g.name),
                  }))}
                />
              </div>
            )}
          </div>

          <div className="flex items-center gap-1.5 text-[10px] text-zinc-400 font-medium pt-1.5 border-t border-zinc-100">
            <Lock className="h-3 w-3 text-emerald-600 shrink-0" />
            <span className="truncate">{t('paymentGateways.vaultEncrypted') || 'Hardware-grade AES-256 vault encryption'}</span>
          </div>
        </div>

        {/* Column 2: Gateway Environment Mode */}
        <div className="p-4 sm:px-6 sm:py-4 flex flex-col justify-between space-y-2.5 bg-gradient-to-b from-white to-[#FAFAFA]/40">
          <div>
            <span className="text-[10px] font-bold text-zinc-400 uppercase tracking-wider block">
              {t('paymentGateways.environmentModeLabel') || 'Environment Mode'}
            </span>
          </div>

          <div className="flex items-center gap-2.5">
            <span className={`h-2.5 w-2.5 rounded-full shrink-0 ${isActiveLive ? 'bg-emerald-500 shadow-sm shadow-emerald-200' : 'bg-amber-500 shadow-sm shadow-amber-200'}`} />
            <div>
              <div className="text-xs font-bold text-black uppercase tracking-wider">
                {isActiveLive 
                  ? (t('paymentGateways.modeLive') || 'Production / Live') 
                  : (t('paymentGateways.modeTest') || 'Sandbox / Test Mode')}
              </div>
              <div className="text-[10px] text-zinc-400 font-medium font-mono">
                {activeGwObj?.slug === 'razorpay'
                  ? (isActiveLive ? 'rzp_live_••••' : 'rzp_test_••••')
                  : (isActiveLive ? 'sk_live_••••' : 'sk_test_••••')} ({t('paymentGateways.credentialsKey') || 'credentials in use'})
              </div>
            </div>
          </div>

          <div className="flex items-center gap-1.5 text-[10px] text-zinc-400 font-medium pt-1.5 border-t border-zinc-100">
            <Zap className={`h-3 w-3 shrink-0 ${isActiveLive ? 'text-emerald-600' : 'text-amber-500'}`} />
            <span className="truncate">
              {isActiveLive 
                ? (t('paymentGateways.liveDesc') || 'Real customer cards accepted') 
                : (t('paymentGateways.sandboxDesc') || 'Safe simulation with test cards')}
            </span>
          </div>
        </div>

        {/* Column 3: Total Configured Gateways & Readiness */}
        <div className="p-4 sm:px-6 sm:py-4 flex flex-col justify-between space-y-2.5 bg-gradient-to-b from-white to-[#FAFAFA]/40">
          <div>
            <span className="text-[10px] font-bold text-zinc-400 uppercase tracking-wider block">
              {t('paymentGateways.configuredCountLabel') || 'Configured Gateways'}
            </span>
          </div>

          <div className="flex items-center justify-between">
            <div className="flex items-baseline gap-1.5">
              <span className="text-sm font-bold text-black">
                {configuredCount}
              </span>
              <span className="text-[11px] text-zinc-400 font-semibold">
                / {totalCount} {t('paymentGateways.gatewaysTotal') || 'Providers Active'}
              </span>
            </div>
            <div className="h-7 w-7 rounded-lg bg-zinc-50 border border-[#E8E8E6] flex items-center justify-center text-zinc-600 shadow-3xs">
              <ShieldCheck className="h-4 w-4 text-emerald-600" />
            </div>
          </div>

          <div className="flex items-center gap-1.5 text-[10px] text-zinc-400 font-medium pt-1.5 border-t border-zinc-100">
            <Check className="h-3 w-3 text-emerald-600 shrink-0" />
            <span className="truncate">{t('paymentGateways.webhookHealth') || 'Webhook endpoint verified & operational'}</span>
          </div>
        </div>

      </div>

      {/* Main Grid Content */}
      <div className="p-4 sm:p-6 md:p-8 space-y-6 flex-1">
        
        {/* 3. GATEWAYS GRID */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {Object.entries(gateways).map(([slug, gw]) => {
            const isConfigured = gw.has_secret;
            const isCurrentActive = gw.is_active;
            const localizedName = getGatewayName(gw.slug, gw.name);
            const localizedDescription = getGatewayDescription(gw.slug, gw.description);

            return (
              <div 
                key={gw.slug}
                className={`bg-white border rounded-xl p-5 shadow-3xs flex flex-col justify-between transition-all ${
                  isCurrentActive ? 'border-emerald-300 ring-1 ring-emerald-300/40' : 'border-[#E8E8E6] hover:border-zinc-300'
                }`}
              >
                <div>
                  {/* Header Row */}
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                      <div className="h-10 w-10 flex items-center justify-center rounded-xl overflow-hidden shrink-0 border border-[#E8E8E6] bg-[#FAFAFA]">
                        {gw.logo ? (
                          <img src={gw.logo} alt={localizedName} className="h-full w-full object-cover" />
                        ) : (
                          <CreditCard className="h-4 w-4 text-black" />
                        )}
                      </div>
                      <div>
                        <h3 className="text-xs font-bold text-black uppercase tracking-wider flex items-center gap-1.5">
                          {localizedName}
                          {isCurrentActive && (
                            <span className="h-2 w-2 rounded-full bg-emerald-500 inline-block" />
                          )}
                        </h3>
                        <p className="text-[10px] text-zinc-400 font-medium">
                          {gw.mode === 'live' ? 'Live Production' : 'Sandbox / Test Mode'}
                        </p>
                      </div>
                    </div>

                    <div>
                      {isCurrentActive ? (
                        <span className="text-[9px] font-bold px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1">
                          <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
                          {t('paymentGateways.activeBadge') || 'ACTIVE GATEWAY'}
                        </span>
                      ) : isConfigured ? (
                        <span className="text-[9px] font-bold px-2 py-0.5 rounded-full bg-zinc-50 text-zinc-600 border border-zinc-200">
                          {t('paymentGateways.configured') || 'Configured'}
                        </span>
                      ) : (
                        <span className="text-[9px] font-medium px-2 py-0.5 rounded-full bg-zinc-100 text-zinc-400 border border-zinc-200">
                          {t('paymentGateways.notConfigured') || 'Not Configured'}
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Description */}
                  <p className="text-xs text-zinc-600 mt-4 leading-relaxed">
                    {localizedDescription}
                  </p>
                </div>

                {/* Footer Action: Configure vs Manage */}
                <div className="pt-5 mt-5 border-t border-[#E8E8E6] flex items-center justify-between gap-2">
                  <a
                    href={gw.docs_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-[11px] text-zinc-400 hover:text-black font-semibold flex items-center gap-1 transition-colors shrink-0"
                  >
                    <span>{t('paymentGateways.docs') || 'Developer Docs'}</span>
                    <ExternalLink className="h-3 w-3" />
                  </a>

                  <div>
                    {isConfigured ? (
                      <button
                        type="button"
                        onClick={() => handleOpenManage(gw.slug)}
                        className="border border-[#E8E8E6] bg-white hover:bg-zinc-50 text-black h-8 px-3 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs flex items-center gap-1.5"
                      >
                        <SlidersHorizontal className="h-3.5 w-3.5 text-zinc-500" />
                        <span>{t('paymentGateways.manage') || 'Manage'}</span>
                      </button>
                    ) : (
                      <button
                        type="button"
                        onClick={() => handleOpenManage(gw.slug)}
                        className="border border-black bg-[#0A0A0A] hover:bg-zinc-800 text-white h-8 px-3 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs flex items-center gap-1.5"
                      >
                        <Settings className="h-3.5 w-3.5 text-zinc-300" />
                        <span>{t('paymentGateways.configure') || 'Configure'}</span>
                      </button>
                    )}
                  </div>
                </div>
              </div>
            );
          })}
        </div>

      </div>

      {/* EDIT CONFIG DIALOG MODAL (Identical implementation to Tenant AI Provider Manage Dialog) */}
      {mounted && selectedGatewaySlug && selectedGateway && typeof window !== 'undefined' && createPortal(
        <div 
          onClick={handleBackdropClick}
          className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 animate-overlay-fade select-none"
        >
          <form 
            onSubmit={handleSaveConfig}
            className="bg-white border border-[#E8E8E6] rounded-xl max-w-md w-full p-6 shadow-xl relative z-50 animate-modal-box text-left"
          >
            {/* Header Section (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6">
              <div className="flex items-center gap-2.5">
                <div className="h-7 w-7 rounded overflow-hidden shrink-0 border border-[#E8E8E6] bg-[#FAFAFA] flex items-center justify-center">
                  <img 
                    src={selectedGateway.logo || '/payments/stripe.png'} 
                    alt={`${selectedGateway.name} logo`} 
                    className="h-full w-full object-cover" 
                  />
                </div>
                <div>
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">
                    {selectedGateway.has_secret 
                      ? `${getGatewayName(selectedGateway.slug, selectedGateway.name)} ${t('paymentGateways.configuration') || 'CONFIGURATION'}`
                      : `${t('paymentGateways.configure') || 'CONFIGURE'} ${getGatewayName(selectedGateway.slug, selectedGateway.name)}`}
                  </h3>
                  <a 
                    href={selectedGateway.docs_url} 
                    target="_blank" 
                    rel="noreferrer"
                    className="text-[10px] text-zinc-500 hover:text-black hover:underline flex items-center gap-0.5 mt-0.5"
                  >
                    {t('paymentGateways.getApiCredentialsKey') || 'Get API credentials key'} <ExternalLink className="h-2.5 w-2.5" />
                  </a>
                </div>
              </div>
              <button 
                type="button"
                onClick={() => setSelectedGatewaySlug(null)}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            {/* Dialog Body */}
            <div className="space-y-4 pt-4">
              {/* Environment Mode Selector */}
              <div className="space-y-1.5 font-sans">
                <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">
                  {t('paymentGateways.environmentMode') || 'Environment Mode'}
                </label>
                <DropdownSelect
                  value={formMode}
                  onChange={(val) => setFormMode(val as 'test' | 'live')}
                  options={[
                    { 
                      value: 'test', 
                      label: selectedGatewaySlug === 'razorpay' 
                        ? 'Sandbox / Test Mode (rzp_test_...)' 
                        : selectedGatewaySlug === 'paystack'
                          ? 'Sandbox / Test Mode (sk_test_...)'
                          : 'Sandbox / Test Mode (sk_test_...)' 
                    },
                    { 
                      value: 'live', 
                      label: selectedGatewaySlug === 'razorpay' 
                        ? 'Production / Live Mode (rzp_live_...)' 
                        : selectedGatewaySlug === 'paystack'
                          ? 'Production / Live Mode (sk_live_...)'
                          : 'Production / Live Mode (sk_live_...)' 
                    }
                  ]}
                />
              </div>

              {/* Publishable Key / Key ID / Public Key */}
              <div className="space-y-1.5 font-sans">
                <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">
                  {selectedGatewaySlug === 'razorpay'
                    ? (t('paymentGateways.keyId') || 'Key ID')
                    : (selectedGatewaySlug === 'paystack' || selectedGatewaySlug === 'flutterwave')
                      ? (t('paymentGateways.publicKey') || 'Public Key')
                      : (t('paymentGateways.publishableKey') || 'Publishable Key')}
                </label>
                <div className="relative">
                  <Key className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[#A0A0A0]" />
                  <input 
                    type="text"
                    placeholder={selectedGatewaySlug === 'razorpay' ? 'rzp_test_...' : selectedGatewaySlug === 'flutterwave' ? 'FLWPUBK_TEST-...' : 'pk_test_...'}
                    value={formPublishableKey}
                    onChange={(e) => setFormPublishableKey(e.target.value)}
                    className="w-full h-9 pl-9 pr-10 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs focus:outline-none focus:border-black font-medium transition-colors font-mono"
                  />
                </div>
              </div>

              {/* Secret Key Input */}
              <div className="space-y-1.5 font-sans">
                <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">
                  {selectedGatewaySlug === 'razorpay'
                    ? (t('paymentGateways.keySecret') || 'Key Secret')
                    : (t('paymentGateways.secretKey') || 'Secret Key')} {selectedGateway.has_secret && (
                    <span className="text-emerald-600 font-bold ml-1">● {t('paymentGateways.configured') || 'Configured'}</span>
                  )}
                </label>
                <div className="relative">
                  <Key className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[#A0A0A0]" />
                  <input 
                    type={showSecretKey && formSecretKey !== '••••••••••••••••••••••••' ? "text" : "password"}
                    placeholder={selectedGateway.has_secret ? '••••••••••••••••••••••••' : (selectedGatewaySlug === 'razorpay' ? 'Key Secret' : selectedGatewaySlug === 'flutterwave' ? 'FLWSECK_TEST-...' : 'sk_test_...')}
                    value={formSecretKey}
                    onChange={(e) => setFormSecretKey(e.target.value)}
                    className="w-full h-9 pl-9 pr-10 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs focus:outline-none focus:border-black font-medium transition-colors font-mono"
                  />
                  {formSecretKey && (
                    <button
                      type="button"
                      onClick={() => {
                        if (formSecretKey.includes('••••')) {
                          setFormSecretKey('');
                          setShowSecretKey(true);
                        } else {
                          setShowSecretKey(!showSecretKey);
                        }
                      }}
                      className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700 cursor-pointer focus:outline-none"
                    >
                      {showSecretKey && !formSecretKey.includes('••••') ? (
                        <EyeOff className="h-4 w-4" />
                      ) : (
                        <Eye className="h-4 w-4" />
                      )}
                    </button>
                  )}
                </div>
                <p className="text-[9px] text-[#A0A0A0]">
                  {t('paymentGateways.securityDesc') || 'Your credentials are encrypted and securely stored using AES-256.'}
                </p>
              </div>

              {/* Webhook Secret Input */}
              <div className="space-y-1.5 font-sans">
                <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">
                  {t('paymentGateways.webhookSecret') || 'Signing Webhook Secret'} {selectedGateway.has_webhook_secret && (
                    <span className="text-emerald-600 font-bold ml-1">● {t('paymentGateways.configured') || 'Configured'}</span>
                  )}
                </label>
                <div className="relative">
                  <Lock className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[#A0A0A0]" />
                  <input 
                    type={showWebhookSecret && formWebhookSecret !== '••••••••••••••••••••••••' ? "text" : "password"}
                    placeholder={selectedGateway.has_webhook_secret ? '••••••••••••••••••••••••' : (selectedGatewaySlug === 'razorpay' ? 'Webhook Secret' : 'whsec_...')}
                    value={formWebhookSecret}
                    onChange={(e) => setFormWebhookSecret(e.target.value)}
                    className="w-full h-9 pl-9 pr-10 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs focus:outline-none focus:border-black font-medium transition-colors font-mono"
                  />
                  {formWebhookSecret && (
                    <button
                      type="button"
                      onClick={() => {
                        if (formWebhookSecret.includes('••••')) {
                          setFormWebhookSecret('');
                          setShowWebhookSecret(true);
                        } else {
                          setShowWebhookSecret(!showWebhookSecret);
                        }
                      }}
                      className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700 cursor-pointer focus:outline-none"
                    >
                      {showWebhookSecret && !formWebhookSecret.includes('••••') ? (
                        <EyeOff className="h-4 w-4" />
                      ) : (
                        <Eye className="h-4 w-4" />
                      )}
                    </button>
                  )}
                </div>
              </div>

              {/* Webhook Callback URL Box */}
              <div className="space-y-1.5 font-sans">
                <div className="flex items-center justify-between">
                  <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">
                    {getGatewayName(selectedGateway.slug, selectedGateway.name)} {t('paymentGateways.webhookUrlTitle') || 'Webhook URL'}
                  </label>
                  <button
                    type="button"
                    onClick={() => handleCopyWebhook(selectedGateway.webhook_url)}
                    className="text-[10px] text-zinc-500 hover:text-black font-semibold flex items-center gap-1 cursor-pointer"
                  >
                    {copiedWebhook ? <Check className="h-3 w-3 text-emerald-600" /> : <Copy className="h-3 w-3" />}
                    <span>{copiedWebhook ? (t('paymentGateways.copied') || 'Copied') : (t('paymentGateways.copyUrl') || 'Copy URL')}</span>
                  </button>
                </div>
                <div className="w-full py-2 px-3 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-[11px] font-mono text-zinc-700 break-all select-all">
                  {selectedGateway.webhook_url}
                </div>
              </div>

              {/* Set as Primary Active Gateway Switch */}
              <div className="pt-3 border-t border-[#E8E8E6] flex items-start gap-3">
                <div className="pt-0.5">
                  <input 
                    type="checkbox"
                    id="makeActiveGateway"
                    checked={formIsActive}
                    onChange={(e) => setFormIsActive(e.target.checked)}
                    className="h-4 w-4 rounded border-zinc-300 text-black focus:ring-black cursor-pointer accent-black"
                  />
                </div>
                <label htmlFor="makeActiveGateway" className="text-xs font-bold text-black cursor-pointer select-none">
                  <div>{t('paymentGateways.makeActiveTitle') || 'Set as Primary Active Gateway'}</div>
                  <div className="text-[10px] text-zinc-500 font-normal mt-0.5 leading-tight">
                    {t('paymentGateways.makeActiveDesc') || 'Route all platform workspace checkouts and subscription billing to this provider.'}
                  </div>
                </label>
              </div>
            </div>

            {/* Footer action strip nested with off-white bg fill */}
            <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-between select-none font-bold">
              <div>
                <button
                  type="button"
                  onClick={handleTestConnection}
                  disabled={testing}
                  className="flex items-center gap-1.5 px-3 py-2 text-zinc-700 hover:text-black font-semibold text-[10px] uppercase tracking-wider hover:bg-zinc-100 rounded transition-all cursor-pointer border border-transparent hover:border-zinc-200 disabled:opacity-50"
                >
                  {testing ? <RefreshCw className="h-3.5 w-3.5 animate-spin" /> : <Zap className="h-3.5 w-3.5 text-amber-500" />}
                  <span>{testing ? (t('paymentGateways.probing') || 'Testing...') : (t('paymentGateways.testConnection') || 'Test Connection')}</span>
                </button>
              </div>
              <div className="flex items-center gap-3">
                <button
                  type="button"
                  onClick={() => setSelectedGatewaySlug(null)}
                  className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                >
                  {t('paymentGateways.cancel') || t('cancel') || 'Cancel'}
                </button>
                <button 
                  type="submit"
                  disabled={saving}
                  className="h-9 px-4 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-sm transition-all cursor-pointer disabled:opacity-50 flex items-center justify-center min-w-[100px] gap-1.5"
                >
                  {saving && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
                  <span>{t('paymentGateways.saveChanges') || t('saveChanges') || 'Save Changes'}</span>
                </button>
              </div>
            </div>
          </form>
        </div>,
        document.body
      )}

    </div>
  );
}
