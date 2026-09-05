'use client';

import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import Image from 'next/image';
import { fetchWithCsrf } from '@/lib/api';
import { 
  MessageSquare, 
  MessageCircle, 
  Mail, 
  Send, 
  X, 
  Smartphone,
  SlidersHorizontal,
  AlertTriangle
} from 'lucide-react';
import { toast } from 'sonner';
import { useTranslations } from 'next-intl';
import SimpleLoader from '@/components/ui/SimpleLoader';

export default function ChannelsPage() {
  const t = useTranslations('Superadmin');

  const [channels, setChannels] = useState<Record<string, boolean>>({});
  const [activeProvider, setActiveProvider] = useState('twilio');
  const [providerCreds, setProviderCreds] = useState<Record<string, Record<string, string>>>({});
  
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  // Modal / SMS Management States
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [tempActiveProvider, setTempActiveProvider] = useState('twilio');
  const [tempProviderCreds, setTempProviderCreds] = useState<Record<string, any>>({});

  // Confirmation Switch states
  const [isConfirmOpen, setIsConfirmOpen] = useState(false);
  const [confirmChannelId, setConfirmChannelId] = useState<string | null>(null);
  const [confirmChannelName, setConfirmChannelName] = useState<string>('');
  const [confirmChannelLogo, setConfirmChannelLogo] = useState<string | undefined>(undefined);
  const [confirmChannelIcon, setConfirmChannelIcon] = useState<React.ComponentType<any> | undefined>(undefined);
  const [confirmActionType, setConfirmActionType] = useState<'enable' | 'disable'>('enable');

  const fetchConfig = async () => {
    setLoading(true);
    try {
      const response = await fetchWithCsrf('/admin/channels');
      if (response.ok) {
        const data = await response.json();
        setChannels(data.channels_enabled || {});
        setActiveProvider(data.sms_providers_config?.active_provider || 'twilio');
        setProviderCreds(data.sms_providers_config?.providers || {});
      }
    } catch {
      toast.error(t('channels.saveFailed'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchConfig();
  }, []);

  const handleSaveSettings = async (updatedChannels = channels, updatedActiveProv = activeProvider, updatedCreds = providerCreds) => {
    setSaving(true);
    try {
      const response = await fetchWithCsrf('/admin/channels', {
        method: 'PUT',
        body: JSON.stringify({
          channels_enabled: updatedChannels,
          sms_providers_config: {
            active_provider: updatedActiveProv,
            providers: updatedCreds
          }
        })
      });

      if (response.ok) {
        const data = await response.json();
        toast.success(t('channels.saveSuccess'));
        setChannels(data.channels_enabled || {});
        setActiveProvider(data.sms_providers_config?.active_provider || 'twilio');
        setProviderCreds(data.sms_providers_config?.providers || {});
      } else {
        throw new Error();
      }
    } catch {
      toast.error(t('channels.saveFailed'));
    } finally {
      setSaving(false);
    }
  };

  const handleChannelToggleClick = (id: string, label: string, logo?: string, icon?: React.ComponentType<any>) => {
    const isCurrentlyEnabled = !!channels[id];
    setConfirmChannelId(id);
    setConfirmChannelName(label);
    setConfirmChannelLogo(logo);
    setConfirmChannelIcon(icon);
    setConfirmActionType(isCurrentlyEnabled ? 'disable' : 'enable');
    setIsConfirmOpen(true);
  };

  const handleConfirmSwitch = () => {
    if (!confirmChannelId) return;

    const updated = {
      ...channels,
      [confirmChannelId]: confirmActionType === 'enable'
    };
    setChannels(updated);
    setIsConfirmOpen(false);

    // Save automatically on confirm
    handleSaveSettings(updated, activeProvider, providerCreds);
  };

  const handleOpenManageModal = () => {
    setTempActiveProvider(activeProvider);
    const credsCopy: Record<string, any> = {};
    for (const key of Object.keys(providerCreds)) {
      credsCopy[key] = { ...providerCreds[key] };
      if (credsCopy[key].enabled === undefined) {
        credsCopy[key].enabled = key === activeProvider;
      }
    }
    setTempProviderCreds(credsCopy);
    setIsModalOpen(true);
  };

  const handleSaveModalSettings = () => {
    setProviderCreds(tempProviderCreds);
    setActiveProvider(tempActiveProvider);
    setIsModalOpen(false);
    handleSaveSettings(channels, tempActiveProvider, tempProviderCreds);
  };

  const handleProviderToggle = (provider: string) => {
    setTempProviderCreds(prev => {
      const current = !!prev[provider]?.enabled;
      const nextEnabled = !current;
      
      if (nextEnabled) {
        setTempActiveProvider(provider);
      }

      return {
        ...prev,
        [provider]: {
          ...(prev[provider] || {}),
          enabled: nextEnabled
        }
      };
    });
  };

  if (loading) {
    return <SimpleLoader message={t('channels.loading')} />;
  }

  const channelsList = [
    { id: 'whatsapp_cloud', label: t('channels.list.whatsapp_cloud.label'), desc: t('channels.list.whatsapp_cloud.desc'), logo: '/channels/whatsapp.webp' },
    { id: 'whatsapp_baileys', label: t('channels.list.whatsapp_baileys.label'), desc: t('channels.list.whatsapp_baileys.desc'), logo: '/channels/whatsapp.webp' },
    { id: 'instagram', label: t('channels.list.instagram.label'), desc: t('channels.list.instagram.desc'), logo: '/channels/instagram.svg' },
    { id: 'messenger', label: t('channels.list.messenger.label'), desc: t('channels.list.messenger.desc'), logo: '/channels/messenger.webp' },
    { id: 'telegram', label: t('channels.list.telegram.label'), desc: t('channels.list.telegram.desc'), logo: '/channels/telegram.webp' },
    { id: 'sms', label: t('channels.list.sms.label'), desc: t('channels.list.sms.desc'), icon: Smartphone, hasManage: true },
    { id: 'email', label: t('channels.list.email.label'), desc: t('channels.list.email.desc'), icon: Mail },
  ];

  const providerDetails = [
    { id: 'twilio', label: t('channels.providers.twilio'), logo: '/channels/twilio.svg' },
    { id: 'vonage', label: t('channels.providers.vonage'), logo: '/channels/vonage.webp' },
    { id: 'messagebird', label: t('channels.providers.messagebird'), logo: '/channels/message_bird.webp' },
    { id: 'plivo', label: t('channels.providers.plivo'), logo: '/channels/plivo.svg' },
    { id: 'sinch', label: t('channels.providers.sinch'), logo: '/channels/sinch.png' },
    { id: 'telnyx', label: t('channels.providers.telnyx'), logo: '/channels/telnyx.ico' },
  ];

  const ConfirmIconComponent = confirmChannelIcon;

  return (
    <div className="w-full bg-white select-none text-black animate-fade-in font-sans p-8 space-y-6 flex-1 overflow-y-auto">
      
      {/* Unified Configurations List */}
      <div className="bg-white border border-[#E8E8E6] rounded-xl overflow-hidden shadow-[var(--shadow-card)] divide-y divide-[#E8E8E6]">
        {channelsList.map((ch) => {
          const isEnabled = !!channels[ch.id];
          
          return (
            <div 
              key={ch.id} 
              className="p-5 flex items-center justify-between hover:bg-[#FAFAFA]/45 transition-colors"
            >
              {/* Logo / Details */}
              <div className="flex items-center gap-4">
                <div className="h-11 w-11 rounded-lg border border-[#E8E8E6] bg-white flex items-center justify-center shrink-0 p-2 select-none">
                  {ch.logo ? (
                    <Image 
                      src={ch.logo} 
                      alt={ch.label} 
                      width={28} 
                      height={28} 
                      className="object-contain max-h-7 w-auto select-none" 
                    />
                  ) : ch.icon ? (
                    <ch.icon className="h-5 w-5 text-zinc-650" />
                  ) : null}
                </div>
                
                <div className="text-left">
                  <span className="text-xs font-bold text-zinc-955 block">{ch.label}</span>
                  <span className="text-[10px] text-zinc-450 font-semibold block mt-0.5">{ch.desc}</span>
                </div>
              </div>

              {/* Action Operations */}
              <div className="flex items-center gap-3 select-none">
                {ch.hasManage && (
                  <button
                    type="button"
                    onClick={handleOpenManageModal}
                    className="h-8 px-3 border border-[#E8E8E6] hover:border-black/30 bg-white text-zinc-700 hover:text-black text-[10px] font-bold uppercase tracking-wider rounded-[6px] transition-all cursor-pointer flex items-center gap-1.5 shadow-3xs"
                  >
                    <SlidersHorizontal className="h-3 w-3" />
                    <span>{t('channels.manageProviders')}</span>
                  </button>
                )}

                {/* Switch Control */}
                <button
                  type="button"
                  onClick={() => handleChannelToggleClick(ch.id, ch.label, ch.logo, ch.icon)}
                  className={`w-9 h-5 rounded-full p-0.5 transition-colors duration-200 focus:outline-none cursor-pointer ${
                    isEnabled ? 'bg-zinc-955' : 'bg-zinc-200'
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

      {/* SMS MANAGEMENT CONFIGURATION MODAL */}
      {isModalOpen && createPortal(
        <div 
          className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 select-none animate-overlay-fade"
          onClick={() => setIsModalOpen(false)}
        >
          <div className="fixed inset-0" onClick={() => setIsModalOpen(false)} />
          
          <div 
            className="bg-white border border-[#E8E8E6] rounded-xl max-w-md w-full p-6 shadow-xl relative z-50 animate-modal-box text-left flex flex-col max-h-[85vh]"
            onClick={(e) => e.stopPropagation()}
          >
            {/* Header Section (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6">
              <div className="flex items-center gap-2.5">
                <div className="h-7 w-7 rounded bg-[#FAFAFA] border border-[#E8E8E6] p-1 flex items-center justify-center shrink-0">
                  <Smartphone className="h-4 w-4 text-[#6B6B6B]" />
                </div>
                <div>
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">{t('channels.smsCarriersConfig')}</h3>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setIsModalOpen(false)}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none shrink-0"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            {/* Scrollable Contents */}
            <div className="flex-1 overflow-y-auto py-5 space-y-4 pr-1">
              
              {/* Providers list inline - Toggles Only */}
              <div className="bg-white border border-[#E8E8E6] rounded-xl overflow-hidden divide-y divide-[#E8E8E6] shadow-3xs">
                {providerDetails.map((prov) => {
                  const isEnabled = !!tempProviderCreds[prov.id]?.enabled;
                  
                  return (
                    <div 
                      key={prov.id}
                      className="p-4 flex items-center justify-between hover:bg-[#FAFAFA]/40 transition-colors"
                    >
                      <div className="flex items-center gap-3">
                        <div className="h-8 w-8 rounded-lg bg-white border border-[#E8E8E6] flex items-center justify-center p-1.5 select-none shrink-0">
                          <Image 
                            src={prov.logo} 
                            alt={prov.label} 
                            width={24} 
                            height={24} 
                            className="object-contain max-h-5 select-none" 
                          />
                        </div>
                        <span className="text-xs font-bold text-zinc-955">{prov.label}</span>
                      </div>

                      {/* Switch Enable Control */}
                      <button
                        type="button"
                        onClick={() => handleProviderToggle(prov.id)}
                        className={`w-9 h-5 rounded-full p-0.5 transition-colors duration-200 focus:outline-none cursor-pointer ${
                          isEnabled ? 'bg-zinc-955' : 'bg-zinc-200'
                        }`}
                      >
                        <div 
                          className={`w-4 h-4 bg-white rounded-full shadow-md transform transition-transform duration-200 ${
                            isEnabled ? 'translate-x-4' : 'translate-x-0'
                          }`} 
                        />
                      </button>
                    </div>
                  );
                })}
              </div>
            </div>

            {/* Footer */}
            <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none shrink-0">
              <button
                type="button"
                onClick={() => setIsModalOpen(false)}
                className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-semibold rounded-[6px] transition-all cursor-pointer"
              >
                {t('dashboard.cancel')}
              </button>
              <button
                type="button"
                onClick={handleSaveModalSettings}
                className="h-9 px-4 bg-zinc-955 hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer"
              >
                {t('dashboard.saveChanges')}
              </button>
            </div>

          </div>
        </div>,
        document.body
      )}

      {/* CONFIRMATION SWITCH DIALOG */}
      {isConfirmOpen && createPortal(
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
                <div className={`h-7 w-7 rounded border p-1 flex items-center justify-center shrink-0 ${
                  confirmActionType === 'disable'
                    ? 'bg-red-50 border-red-100'
                    : 'bg-[#FAFAFA] border-[#E8E8E6]'
                }`}>
                  {confirmActionType === 'disable' ? (
                    <AlertTriangle className="h-4 w-4 text-red-600 animate-pulse" />
                  ) : confirmChannelLogo ? (
                    <Image 
                      src={confirmChannelLogo} 
                      alt={confirmChannelName} 
                      width={16} 
                      height={16} 
                      className="object-contain select-none" 
                    />
                  ) : ConfirmIconComponent ? (
                    <ConfirmIconComponent className="h-4 w-4 text-[#6B6B6B]" />
                  ) : null}
                </div>
                <div>
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">
                    {confirmActionType === 'enable' 
                      ? t('channels.confirmEnableTitle') 
                      : t('channels.confirmDisableTitle')}
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
                  ? t('channels.confirmEnableText', { name: confirmChannelName }) 
                  : t('channels.confirmDisableText', { name: confirmChannelName })}
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
                onClick={handleConfirmSwitch}
                className={`h-9 px-4 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer ${
                  confirmActionType === 'enable' 
                    ? 'bg-zinc-955 hover:bg-zinc-900' 
                    : 'bg-red-650 hover:bg-red-750'
                }`}
              >
                {confirmActionType === 'enable' 
                  ? t('channels.enable') 
                  : t('channels.disable')}
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

    </div>
  );
}
