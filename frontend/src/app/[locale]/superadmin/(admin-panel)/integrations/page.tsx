'use client';

import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import Image from 'next/image';
import { fetchWithCsrf } from '@/lib/api';
import { 
  X, 
  SlidersHorizontal,
  AlertTriangle,
  Puzzle,
  AlertCircle
} from 'lucide-react';
import { toast } from 'sonner';
import { useTranslations } from 'next-intl';
import SimpleLoader from '@/components/ui/SimpleLoader';

interface IntegrationItem {
  id: string;
  label: string;
  desc: string;
  logo: string;
  needsEnv: boolean;
}

export default function IntegrationsPage() {
  const t = useTranslations('Superadmin');

  const [integrationsEnabled, setIntegrationsEnabled] = useState<Record<string, boolean>>({});
  const [envStatus, setEnvStatus] = useState<Record<string, boolean>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  // Confirmation states
  const [isConfirmOpen, setIsConfirmOpen] = useState(false);
  const [confirmTargetId, setConfirmTargetId] = useState<string | null>(null);
  const [confirmTargetName, setConfirmTargetName] = useState<string>('');
  const [confirmActionType, setConfirmActionType] = useState<'enable' | 'disable'>('enable');

  const fetchConfig = async () => {
    setLoading(true);
    try {
      const response = await fetchWithCsrf('/admin/integrations');
      if (response.ok) {
        const data = await response.json();
        setIntegrationsEnabled(data.integrations_enabled_config || {});
        setEnvStatus(data.env_status || {});
      }
    } catch {
      toast.error(t('integrations.saveFailed'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchConfig();
  }, []);

  const handleSaveSettings = async (updatedConfig = integrationsEnabled) => {
    setSaving(true);
    try {
      const response = await fetchWithCsrf('/admin/integrations', {
        method: 'PUT',
        body: JSON.stringify({
          integrations_enabled_config: updatedConfig
        })
      });

      if (response.ok) {
        const data = await response.json();
        toast.success(t('integrations.saveSuccess'));
        setIntegrationsEnabled(data.integrations_enabled_config || {});
      } else {
        throw new Error();
      }
    } catch {
      toast.error(t('integrations.saveFailed'));
    } finally {
      setSaving(false);
    }
  };

  const handleToggleClick = (id: string, name: string) => {
    const isCurrentlyEnabled = !!integrationsEnabled[id];
    setConfirmTargetId(id);
    setConfirmTargetName(name);
    setConfirmActionType(isCurrentlyEnabled ? 'disable' : 'enable');
    setIsConfirmOpen(true);
  };

  const handleConfirmAction = () => {
    if (!confirmTargetId) return;

    const updated = {
      ...integrationsEnabled,
      [confirmTargetId]: confirmActionType === 'enable'
    };
    setIntegrationsEnabled(updated);
    setIsConfirmOpen(false);

    // Save automatically on confirm
    handleSaveSettings(updated);
  };

  if (loading) {
    return <SimpleLoader message={t('integrations.loading')} />;
  }

  const integrationsList: IntegrationItem[] = [
    { id: 'google_sheets', label: t('integrations.providers.google_sheets'), desc: 'Sync client database lists directly into Google Sheets records', logo: '/google_sheets.webp', needsEnv: true },
    { id: 'google_calendar', label: t('integrations.providers.google_calendar'), desc: 'Manage calendar slot intervals, meetings and booking links', logo: '/google_calendar.webp', needsEnv: true },
    { id: 'zoom', label: t('integrations.providers.zoom'), desc: 'Instantly schedule and link Zoom meetings to client reservations', logo: '/zoom.png', needsEnv: true },
    { id: 'teams', label: t('integrations.providers.teams'), desc: 'Link Teams conference call codes to upcoming appointments', logo: '/teams.webp', needsEnv: true },
    { id: 'hubspot', label: t('integrations.providers.hubspot'), desc: 'Push contact lifecycle stages and sync deals metadata to HubSpot', logo: '/hubspot.png', needsEnv: true },
    { id: 'salesforce', label: t('integrations.providers.salesforce'), desc: 'Create leads and bi-directionally sync CRM pipelines with Salesforce', logo: '/salesforce.webp', needsEnv: true },
    { id: 'zoho', label: t('integrations.providers.zoho'), desc: 'Fetch accounts metrics and manage lists structures in Zoho CRM', logo: '/zoho_crm.png', needsEnv: true },
    { id: 'shopify', label: t('integrations.providers.shopify'), desc: 'Retrieve shopping carts details and track orders notifications', logo: '/shopify.webp', needsEnv: false },
    { id: 'woocommerce', label: t('integrations.providers.woocommerce'), desc: 'WooCommerce hooks for orders triggers and checkouts alerts', logo: '/woocommerce.webp', needsEnv: false },
  ];

  const confirmTargetInfo = integrationsList.find(p => p.id === confirmTargetId);

  return (
    <div className="w-full bg-white select-none text-black animate-fade-in font-sans p-8 space-y-6 flex-1 overflow-y-auto">
      
      {/* Unified Configurations List */}
      <div className="bg-white border border-[#E8E8E6] rounded-xl overflow-hidden shadow-[var(--shadow-card)] divide-y divide-[#E8E8E6]">
        {integrationsList.map((item) => {
          const isConfiguredInEnv = !item.needsEnv || !!envStatus[item.id];
          const isEnabled = isConfiguredInEnv && !!integrationsEnabled[item.id];
          
          return (
            <div 
              key={item.id} 
              className={`p-5 flex items-center justify-between hover:bg-[#FAFAFA]/45 transition-colors ${
                !isConfiguredInEnv ? 'opacity-85' : ''
              }`}
            >
              {/* Logo / Details */}
              <div className="flex items-center gap-4">
                <div className="h-11 w-11 rounded-lg border border-[#E8E8E6] bg-white flex items-center justify-center shrink-0 p-2 select-none">
                  <Image 
                    src={item.logo} 
                    alt={item.label} 
                    width={28} 
                    height={28} 
                    className="object-contain max-h-7 w-auto select-none" 
                  />
                </div>
                
                <div className="text-left">
                  <span className="text-xs font-bold text-zinc-955 block">{item.label}</span>
                  <span className="text-[10px] text-zinc-450 font-semibold block mt-0.5">{item.desc}</span>
                  {!isConfiguredInEnv && (
                    <span className="text-[9px] text-red-650 font-bold uppercase tracking-wider flex items-center gap-1 mt-1">
                      <AlertCircle className="h-3 w-3 shrink-0" />
                      <span>{t('integrations.envWarningText')}</span>
                    </span>
                  )}
                </div>
              </div>

              {/* Switch Control */}
              <div className="flex items-center select-none shrink-0">
                <button
                  type="button"
                  disabled={!isConfiguredInEnv}
                  onClick={() => handleToggleClick(item.id, item.label)}
                  className={`w-9 h-5 rounded-full p-0.5 transition-colors duration-200 focus:outline-none ${
                    !isConfiguredInEnv 
                      ? 'bg-zinc-100 cursor-not-allowed opacity-50' 
                      : isEnabled ? 'bg-zinc-955 cursor-pointer' : 'bg-zinc-200 cursor-pointer'
                  }`}
                >
                  <div 
                    className={`w-4 h-4 bg-white rounded-full shadow-md transform transition-transform duration-200 ${
                      isEnabled ? 'translate-x-4' : 'translate-x-0'
                    }`} 
                  />
                </button>
              </div>
            </div>
          );
        })}
      </div>

      {/* CONFIRMATION DIALOG */}
      {isConfirmOpen && confirmTargetInfo && createPortal(
        <div 
          className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 select-none animate-overlay-fade"
          onClick={() => setIsConfirmOpen(false)}
        >
          <div className="fixed inset-0" onClick={() => setIsConfirmOpen(false)} />
          
          <div 
            className="bg-white border border-[#E8E8E6] rounded-xl max-w-md w-full p-6 shadow-xl relative z-50 animate-modal-box text-left"
            onClick={(e) => e.stopPropagation()}
          >
            {/* Header Section (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6">
              <div className="flex items-center gap-2.5">
                <div className={`h-7 w-7 rounded border p-1.5 flex items-center justify-center shrink-0 ${
                  confirmActionType === 'disable'
                    ? 'bg-red-50 border-red-100'
                    : 'bg-[#FAFAFA] border-[#E8E8E6]'
                }`}>
                  {confirmActionType === 'disable' ? (
                    <AlertTriangle className="h-4 w-4 text-red-600 animate-pulse" />
                  ) : (
                    <Image 
                      src={confirmTargetInfo.logo} 
                      alt={confirmTargetInfo.label} 
                      width={16} 
                      height={16} 
                      className="object-contain select-none" 
                    />
                  )}
                </div>
                <div>
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">
                    {confirmActionType === 'enable' 
                      ? t('integrations.confirmEnableTitle') 
                      : t('integrations.confirmDisableTitle')}
                  </h3>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setIsConfirmOpen(false)}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none shrink-0"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            {/* Content Body */}
            <div className="space-y-4 pt-4">
              <p className="text-xs text-[#6B6B6B] leading-relaxed font-semibold">
                {confirmActionType === 'enable' 
                  ? t('integrations.confirmEnableText', { name: confirmTargetName }) 
                  : t('integrations.confirmDisableText', { name: confirmTargetName })}
              </p>
            </div>

            {/* Footer Buttons */}
            <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none">
              <button
                type="button"
                onClick={() => setIsConfirmOpen(false)}
                className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-semibold rounded-[6px] transition-all cursor-pointer"
              >
                {t('dashboard.cancel')}
              </button>
              <button
                type="button"
                onClick={handleConfirmAction}
                className={`h-9 px-4 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer ${
                  confirmActionType === 'enable'
                    ? 'bg-zinc-955 hover:bg-zinc-900'
                    : 'bg-red-650 hover:bg-red-750'
                }`}
              >
                {confirmActionType === 'enable' 
                  ? t('integrations.enable') 
                  : t('integrations.disable')}
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

    </div>
  );
}
