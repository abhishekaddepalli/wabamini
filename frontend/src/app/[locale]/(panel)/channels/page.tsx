'use client';

import React, { useEffect, useState, useRef } from 'react';
import { createPortal } from 'react-dom';
import { useRouter } from '@/i18n/routing';
import { fetchWithCsrf } from '@/lib/api';
import { toast } from 'sonner';
import { useTranslations } from 'next-intl';
import { echo } from '@/lib/echo';
import SimpleLoader from '@/components/ui/SimpleLoader';
import { PlanLockModal } from '@/components/billing/PlanLockModal';
import { DropdownSelect } from '@/components/ui/dropdown-select';
import { 
  RefreshCw,
  Settings,
  Copy,
  AlertTriangle,
  Eye,
  EyeOff,
  X,
  Lock,
  Trash2,
  ShieldAlert,
  Loader2,
  Check
} from 'lucide-react';

interface WhatsAppConnection {
  connected: boolean;
  id?: number;
  name?: string;
  status?: string;
  phone_number_id?: string;
  whatsapp_business_account_id?: string;
  webhook_url?: string;
  webhook_verify_token?: string;
  webhook_secret?: string;
  raw_credentials?: Record<string, unknown>;
}

interface BaileysConnection {
  connected: boolean;
  id?: number;
  name?: string;
  status?: string;
  webhook_verify_token?: string;
}

interface InstagramConnection {
  connected: boolean;
  id?: number;
  name?: string;
  status?: string;
  instagram_business_account_id?: string;
  webhook_url?: string;
  webhook_verify_token?: string;
}

interface FacebookMessengerConnection {
  connected: boolean;
  id?: number;
  name?: string;
  status?: string;
  page_id?: string;
  webhook_url?: string;
  webhook_verify_token?: string;
}

interface TelegramConnection {
  connected: boolean;
  id?: number;
  name?: string;
  status?: string;
  bot_username?: string;
  webhook_url?: string;
  webhook_verify_token?: string;
  raw_credentials?: Record<string, unknown>;
}

interface SmsConnection {
  connected: boolean;
  id?: number;
  name?: string;
  status?: string;
  provider?: "twilio" | "vonage" | "plivo" | "messagebird" | "sinch" | "telnyx";
  webhook_url?: string;
  webhook_verify_token?: string;
  raw_credentials?: Record<string, unknown>;
  details?: {
    twilio_account_sid?: string;
    twilio_phone_number?: string;
    vonage_api_key?: string;
    vonage_phone_number?: string;
    plivo_phone_number?: string;
    messagebird_phone_number?: string;
    sinch_phone_number?: string;
    telnyx_phone_number?: string;
  };
}

interface EmailConnection {
  connected: boolean;
  id?: number;
  name?: string;
  status?: string;
  provider?: string;
  webhook_url?: string;
  webhook_verify_token?: string;
  raw_credentials?: Record<string, unknown>;
  details?: {
    email_address?: string;
    smtp_host?: string;
    smtp_port?: number;
    smtp_username?: string;
    smtp_encryption?: string;
    imap_host?: string;
    imap_port?: number;
    imap_username?: string;
    imap_encryption?: string;
  };
}

interface ChannelItem {
  slug: string;
  name: string;
  description: string;
  logoColor: string;
}

interface ActiveChannelItem {
  id: number;
  slug: string;
  name: string;
  providerTitle: string;
  status?: string;
  details: WhatsAppConnection | BaileysConnection | InstagramConnection | FacebookMessengerConnection | TelegramConnection | SmsConnection | EmailConnection | null;
}

const availableChannelsList: ChannelItem[] = [
  {
    slug: 'whatsapp_cloud',
    name: 'WhatsApp Cloud API',
    description: 'Official WhatsApp Cloud API connection. Send and receive messages via Meta Graph APIs, templates, and interactive media.',
    logoColor: 'text-emerald-500'
  },
  {
    slug: 'whatsapp_baileys',
    name: 'WhatsApp Baileys (Unofficial)',
    description: 'Connect unofficial numbers by scanning worker-emitted QR codes over WebSockets. Independent from Meta developer panels.',
    logoColor: 'text-cyan-500'
  },
  {
    slug: 'instagram',
    name: 'Instagram Business',
    description: 'Send and receive Direct Messages (DMs) and Story replies via Meta Login for Business OAuth and linked Instagram Accounts.',
    logoColor: 'text-pink-500'
  },
  {
    slug: 'messenger',
    name: 'Facebook Messenger',
    description: 'Send and receive messages and quick replies using Meta Login for Business OAuth and linked Facebook Pages.',
    logoColor: 'text-blue-600'
  },
  {
    slug: 'telegram',
    name: 'Telegram Bot',
    description: 'Connect official customer support bot profiles via HTTP bot API tokens and automatic webhook subscriptions.',
    logoColor: 'text-sky-400'
  },
  {
    slug: 'sms',
    name: 'SMS Channel',
    description: 'Connect Bring-Your-Own-Key gateways (Twilio and Vonage/Nexmo) for direct, reliable customer text message outreach.',
    logoColor: 'text-indigo-500'
  },
  {
    slug: 'email',
    name: 'Email Channel',
    description: 'Connect customer service mailboxes (SMTP/IMAP) for two-way threaded client conversation support.',
    logoColor: 'text-rose-500'
  }
];

const renderWebhookInfo = (webhookUrl: string, verifyToken?: string, t?: any) => {
  return (
    <div className="bg-[#FAFAFA] border border-[#E8E8E6] p-3.5 rounded-lg space-y-3 mb-4 text-xs font-medium text-zinc-700">
      <div className="space-y-1.5">
        <span className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('callbackLabel') || 'Callback Webhook URL'}</span>
        <div className="flex items-center gap-2">
          <input 
            type="text" 
            readOnly 
            value={webhookUrl} 
            className="flex-1 h-8 px-2.5 bg-white border border-[#E8E8E6] rounded-md font-mono text-[11px] select-all outline-none text-zinc-900"
          />
          <button 
            type="button" 
            onClick={() => {
              navigator.clipboard.writeText(webhookUrl);
              toast.success(t('toastWebhookUrlCopied') || 'Webhook URL copied!');
            }}
            className="h-8 px-2.5 bg-white border border-[#E8E8E6] hover:bg-zinc-50 hover:text-black rounded-md transition-all cursor-pointer shadow-3xs flex items-center justify-center text-zinc-600 shrink-0"
          >
            <Copy className="h-3.5 w-3.5" />
          </button>
        </div>
      </div>
      {verifyToken && (
        <div className="space-y-1.5">
          <span className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('verifyTokenLabel') || 'Webhook Verify Token'}</span>
          <div className="flex items-center gap-2">
            <input 
              type="text" 
              readOnly 
              value={verifyToken} 
              className="flex-1 h-8 px-2.5 bg-white border border-[#E8E8E6] rounded-md font-mono text-[11px] select-all outline-none text-zinc-900"
            />
            <button 
              type="button" 
              onClick={() => {
                navigator.clipboard.writeText(verifyToken);
                toast.success(t('toastVerifyTokenCopied') || 'Verify Token copied!');
              }}
              className="h-8 px-2.5 bg-white border border-[#E8E8E6] hover:bg-zinc-50 hover:text-black rounded-md transition-all cursor-pointer shadow-3xs flex items-center justify-center text-zinc-600 shrink-0"
            >
              <Copy className="h-3.5 w-3.5" />
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

const getSmsProviderLogo = (provider: string, className = "h-4 w-4") => {
  switch (provider) {
    case 'twilio':
      return <img src="/channels/twilio.svg" alt="Twilio" className={`${className} object-contain`} />;
    case 'vonage':
      return <img src="/channels/vonage.webp" alt="Vonage" className={`${className} object-contain`} />;
    case 'plivo':
      return <img src="/channels/plivo.svg" alt="Plivo" className={`${className} object-contain`} />;
    case 'messagebird':
      return <img src="/channels/message_bird.webp" alt="MessageBird" className={`${className} object-contain`} />;
    case 'sinch':
      return <img src="/channels/sinch.png" alt="Sinch" className={`${className} object-contain`} />;
    case 'telnyx':
      return <img src="/channels/telnyx.ico" alt="Telnyx" className={`${className} object-contain`} />;
    default:
      return <span className={`h-2 w-2 rounded-full bg-zinc-400 shrink-0`} />;
  }
};

const renderChannelLogo = (slug: string, className = "h-5 w-5", smsProviderName?: string) => {
  if (slug === 'sms') {
    if (smsProviderName) {
      return getSmsProviderLogo(smsProviderName, className);
    }
    return <img src="/channels/twilio.svg" alt="SMS" className={`${className} object-contain`} />;
  }
  switch (slug) {
    case 'whatsapp_cloud':
    case 'whatsapp_baileys':
      return <img src="/channels/whatsapp.webp" alt="WhatsApp" className={`${className} object-contain`} />;
    case 'instagram':
      return <img src="/channels/instagram.svg" alt="Instagram" className={`${className} object-contain`} />;
    case 'messenger':
      return <img src="/channels/messenger.webp" alt="Messenger" className={`${className} object-contain`} />;
    case 'telegram':
      return <img src="/channels/telegram.webp" alt="Telegram" className={`${className} object-contain`} />;
    case 'email':
      return (
        <svg className={`${className} text-rose-500`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
          <polyline points="22,6 12,13 2,6" />
        </svg>
      );
    default:
      return (
        <svg className={`${className} text-zinc-400`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
        </svg>
      );
  }
};

export default function ChannelsPage() {
  const t = useTranslations('Channels');
  const tCommon = useTranslations('Common');
  const tPlanLock = useTranslations('PlanLock');
  const router = useRouter();
  const [loading, setLoading] = useState(true);
  const [mounted, setMounted] = useState(false);

  // Subscription Plan Context & Gate States
  const [userPlan, setUserPlan] = useState<any>(null);
  const [lockModalOpen, setLockModalOpen] = useState(false);
  const [lockedChannelInfo, setLockedChannelInfo] = useState<{ title: string; desc: string; name: string } | null>(null);

  // Multi-Connection Arrays per Channel Provider
  const [whatsappConnections, setWhatsappConnections] = useState<WhatsAppConnection[]>([]);
  const [baileysConnections, setBaileysConnections] = useState<BaileysConnection[]>([]);
  const [instagramConnections, setInstagramConnections] = useState<InstagramConnection[]>([]);
  const [messengerConnections, setMessengerConnections] = useState<FacebookMessengerConnection[]>([]);
  const [telegramConnections, setTelegramConnections] = useState<TelegramConnection[]>([]);
  const [smsConnections, setSmsConnections] = useState<SmsConnection[]>([]);
  const [emailConnections, setEmailConnections] = useState<EmailConnection[]>([]);

  // WhatsApp Cloud API connection details
  const [whatsappConnected, setWhatsappConnected] = useState(false);
  const [whatsappDetails, setWhatsappDetails] = useState<WhatsAppConnection | null>(null);
  
  // WhatsApp Baileys connection details
  const [baileysConnected, setBaileysConnected] = useState(false);
  const [baileysDetails, setBaileysDetails] = useState<BaileysConnection | null>(null);
  const [baileysQr, setBaileysQr] = useState<string>('');
  const [baileysNameInput, setBaileysNameInput] = useState('');
  const [showBaileysDisclaimer, setShowBaileysDisclaimer] = useState(false);
  const [baileysConnecting, setBaileysConnecting] = useState(false);
  const [baileysActive, setBaileysActive] = useState(true);
  const [baileysNicknameRequired, setBaileysNicknameRequired] = useState(false);

  // Instagram connection details
  const [instagramConnected, setInstagramConnected] = useState(false);
  const [instagramDetails, setInstagramDetails] = useState<InstagramConnection | null>(null);
  const [instagramConnecting, setInstagramConnecting] = useState(false);

  // Facebook Messenger connection details
  const [messengerConnected, setMessengerConnected] = useState(false);
  const [messengerDetails, setMessengerDetails] = useState<FacebookMessengerConnection | null>(null);
  const [messengerConnecting, setMessengerConnecting] = useState(false);

  // Telegram connection details
  const [telegramConnected, setTelegramConnected] = useState(false);
  const [telegramDetails, setTelegramDetails] = useState<TelegramConnection | null>(null);
  const [telegramConnecting, setTelegramConnecting] = useState(false);
  const [telegramNameInput, setTelegramNameInput] = useState('');
  const [telegramTokenInput, setTelegramTokenInput] = useState('');

  // SMS connection details
  const [smsConnected, setSmsConnected] = useState(false);
  const [smsDetails, setSmsDetails] = useState<SmsConnection | null>(null);
  const [smsConnecting, setSmsConnecting] = useState(false);
  
  // Email connection details
  const [emailConnected, setEmailConnected] = useState(false);
  const [emailDetails, setEmailDetails] = useState<EmailConnection | null>(null);
  const [emailActive, setEmailActive] = useState(true);
  const [emailConnecting, setEmailConnecting] = useState(false);
  const [emailStep, setEmailStep] = useState<number>(1);
  const emailProvider = 'smtp';

  const [emailNameInput, setEmailNameInput] = useState('');
  const [emailAddressInput, setEmailAddressInput] = useState('');
  const [emailSmtpHostInput, setEmailSmtpHostInput] = useState('');
  const [emailSmtpPortInput, setEmailSmtpPortInput] = useState('587');
  const [emailSmtpUserInput, setEmailSmtpUserInput] = useState('');
  const [emailSmtpPassInput, setEmailSmtpPassInput] = useState('');
  const [emailSmtpEncInput, setEmailSmtpEncInput] = useState<'none' | 'ssl' | 'tls'>('tls');

  const [emailImapHostInput, setEmailImapHostInput] = useState('');
  const [emailImapPortInput, setEmailImapPortInput] = useState('993');
  const [emailImapUserInput, setEmailImapUserInput] = useState('');
  const [emailImapPassInput, setEmailImapPassInput] = useState('');
  const [emailImapEncInput, setEmailImapEncInput] = useState<'none' | 'ssl' | 'tls'>('ssl');
  
  const [emailSmtpEncDropdownOpen, setEmailSmtpEncDropdownOpen] = useState(false);
  const [emailImapEncDropdownOpen, setEmailImapEncDropdownOpen] = useState(false);

  const [smsNameInput, setSmsNameInput] = useState('');
  const [smsStep, setSmsStep] = useState<number>(1);
  const [smsProvider, setSmsProvider] = useState<'twilio' | 'vonage' | 'plivo' | 'messagebird' | 'sinch' | 'telnyx'>('twilio');
  const [smsProviderDropdownOpen, setSmsProviderDropdownOpen] = useState(false);
  const [smsTwilioSidInput, setSmsTwilioSidInput] = useState('');
  const [smsTwilioTokenInput, setSmsTwilioTokenInput] = useState('');
  const [smsTwilioPhoneInput, setSmsTwilioPhoneInput] = useState('');
  const [smsVonageKeyInput, setSmsVonageKeyInput] = useState('');
  const [smsVonageSecretInput, setSmsVonageSecretInput] = useState('');
  const [smsVonagePhoneInput, setSmsVonagePhoneInput] = useState('');
  const [smsPlivoAuthIdInput, setSmsPlivoAuthIdInput] = useState('');
  const [smsPlivoAuthTokenInput, setSmsPlivoAuthTokenInput] = useState('');
  const [smsPlivoPhoneInput, setSmsPlivoPhoneInput] = useState('');
  const [smsMessagebirdKeyInput, setSmsMessagebirdKeyInput] = useState('');
  const [smsMessagebirdPhoneInput, setSmsMessagebirdPhoneInput] = useState('');
  const [smsSinchPlanIdInput, setSmsSinchPlanIdInput] = useState('');
  const [smsSinchTokenInput, setSmsSinchTokenInput] = useState('');
  const [smsSinchPhoneInput, setSmsSinchPhoneInput] = useState('');
  const [smsTelnyxKeyInput, setSmsTelnyxKeyInput] = useState('');
  const [smsTelnyxPhoneInput, setSmsTelnyxPhoneInput] = useState('');

  // Dialog controls
  const [selectedChannelSlug, setSelectedChannelSlug] = useState<string | null>(null);
  const [editingConnectionId, setEditingConnectionId] = useState<number | null>(null);
  const [togglingConnectionId, setTogglingConnectionId] = useState<number | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [disconnectTarget, setDisconnectTarget] = useState<{ slug: string; id?: number; name?: string } | null>(null);

  // Field visibility states for passwords/keys
  const [showTelegramToken, setShowTelegramToken] = useState(false);
  const [showSmsTwilioToken, setShowSmsTwilioToken] = useState(false);
  const [showSmsVonageSecret, setShowSmsVonageSecret] = useState(false);
  const [showSmsPlivoToken, setShowSmsPlivoToken] = useState(false);
  const [showSmsMessagebirdKey, setShowSmsMessagebirdKey] = useState(false);
  const [showSmsSinchToken, setShowSmsSinchToken] = useState(false);
  const [showSmsTelnyxKey, setShowSmsTelnyxKey] = useState(false);
  const [showEmailSmtpPass, setShowEmailSmtpPass] = useState(false);
  const [showEmailImapPass, setShowEmailImapPass] = useState(false);
  const [showWhatsAppToken, setShowWhatsAppToken] = useState(false);
  
  // WhatsApp Form controls
  const [nameInput, setNameInput] = useState('');
  const [phoneInput, setPhoneInput] = useState('');
  const [wabaInput, setWabaInput] = useState('');
  const [tokenInput, setTokenInput] = useState('');
  const [secretInput, setSecretInput] = useState('');

  // Active toggles simulated state
  const [whatsappActive, setWhatsappActive] = useState(true);
  const [instagramActive, setInstagramActive] = useState(true);
  const [messengerActive, setMessengerActive] = useState(true);
  const [telegramActive, setTelegramActive] = useState(true);
  const [smsActive, setSmsActive] = useState(true);

  // References for cleanup
  const baileysIntervalRef = useRef<NodeJS.Timeout | null>(null);
  const baileysConnectionIdRef = useRef<number | null>(null);

  const fetchWhatsAppStatus = async () => {
    try {
      const res = await fetchWithCsrf('/integrations/whatsapp/status');
      if (res.ok) {
        const data = await res.json();
        setWhatsappConnected(data.connected);
        setWhatsappDetails(data.connected ? data : null);
        setWhatsappConnections(data.connections || (data.connected ? [data] : []));
      }
    } catch {}
  };

  const fetchBaileysStatus = async () => {
    try {
      const res = await fetchWithCsrf('/integrations/baileys/status');
      if (res.ok) {
        const data = await res.json();
        setBaileysConnected(data.connected);
        setBaileysDetails(data.connected ? data : null);
        setBaileysConnections(data.connections || (data.connected ? [data] : []));
      }
    } catch {}
  };

  const fetchInstagramStatus = async () => {
    try {
      const res = await fetchWithCsrf('/integrations/instagram/status');
      if (res.ok) {
        const data = await res.json();
        setInstagramConnected(data.connected);
        setInstagramDetails(data.connected ? data : null);
        setInstagramConnections(data.connections || (data.connected ? [data] : []));
      }
    } catch {}
  };

  const fetchMessengerStatus = async () => {
    try {
      const res = await fetchWithCsrf('/integrations/messenger/status');
      if (res.ok) {
        const data = await res.json();
        setMessengerConnected(data.connected);
        setMessengerDetails(data.connected ? data : null);
        setMessengerConnections(data.connections || (data.connected ? [data] : []));
      }
    } catch {}
  };

  const fetchTelegramStatus = async () => {
    try {
      const res = await fetchWithCsrf('/integrations/telegram/status');
      if (res.ok) {
        const data = await res.json();
        setTelegramConnected(data.connected);
        setTelegramDetails(data.connected ? data : null);
        setTelegramConnections(data.connections || (data.connected ? [data] : []));
      }
    } catch {}
  };

  const fetchSmsStatus = async () => {
    try {
      const res = await fetchWithCsrf('/integrations/sms/status');
      if (res.ok) {
        const data = await res.json();
        setSmsConnected(data.connected);
        setSmsDetails(data.connected ? data : null);
        setSmsConnections(data.connections || (data.connected ? [data] : []));
      }
    } catch {}
  };

  const fetchEmailStatus = async () => {
    try {
      const res = await fetchWithCsrf('/integrations/email/status');
      if (res.ok) {
        const data = await res.json();
        setEmailConnected(data.connected);
        setEmailDetails(data.connected ? data : null);
        setEmailConnections(data.connections || (data.connected ? [data] : []));
      }
    } catch {}
  };



  // Global enabled channels from super admin configs
  const [globalChannelsEnabled, setGlobalChannelsEnabled] = useState<Record<string, boolean>>({
    whatsapp_cloud: true,
    whatsapp_baileys: true,
    instagram: true,
    messenger: true,
    telegram: true,
    sms: true,
    email: true,
  });

  // Global enabled SMS gateways from super admin configs
  const [globalSmsProvidersEnabled, setGlobalSmsProvidersEnabled] = useState<Record<string, boolean>>({
    twilio: true,
    vonage: false,
    plivo: false,
    messagebird: false,
    sinch: false,
    telnyx: false,
  });

  const fetchGlobalChannelsEnabled = async () => {
    try {
      const res = await fetch('/api/platform/settings');
      if (res.ok) {
        const data = await res.json();
        if (data.channels_enabled) {
          setGlobalChannelsEnabled(data.channels_enabled);
        }
        if (data.sms_providers_enabled) {
          setGlobalSmsProvidersEnabled(data.sms_providers_enabled);
          const enabledKeys = Object.keys(data.sms_providers_enabled).filter(
            k => data.sms_providers_enabled[k]
          );
          if (enabledKeys.length > 0) {
            setSmsProvider(enabledKeys[0] as any);
          }
        }
      }
    } catch {}
  };

  useEffect(() => {
    const fetchAll = async () => {
      setLoading(true);
      try {
        const [meRes] = await Promise.all([
          fetchWithCsrf('/auth/me'),
          fetchGlobalChannelsEnabled(),
          fetchWhatsAppStatus(),
          fetchBaileysStatus(),
          fetchInstagramStatus(),
          fetchMessengerStatus(),
          fetchTelegramStatus(),
          fetchSmsStatus(),
          fetchEmailStatus()
        ]);
        if (meRes && meRes.ok) {
          const meData = await meRes.json();
          setUserPlan(meData.user?.tenant?.plan || null);
        }
      } catch {}
      setLoading(false);
    };
    fetchAll();

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setSelectedChannelSlug(null);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
      // Clean up any remaining intervals on unmount
      if (baileysIntervalRef.current) {
        clearInterval(baileysIntervalRef.current);
      }
      if (echo && baileysConnectionIdRef.current) {
        echo.leave(`channel-connection.${baileysConnectionIdRef.current}`);
      }
    };
  }, []);

  useEffect(() => {
    setMounted(true);
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setSelectedChannelSlug(null);
        setEditingConnectionId(null);
        setShowBaileysDisclaimer(false);
        setDisconnectTarget(null);
        setLockModalOpen(false);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, []);

  const isChannelLocked = (slug: string) => {
    if (!userPlan) return { locked: false, reason: null };

    // 1. Total channels cap
    if (userPlan.max_channels === 0) {
      return {
        locked: true,
        reason: tPlanLock('channelLockedDesc')
      };
    }

    const totalActive = activeChannels.length;
    if (userPlan.max_channels > 0 && totalActive >= userPlan.max_channels) {
      return {
        locked: true,
        reason: tPlanLock('channelCapacityReached', { max: userPlan.max_channels })
      };
    }

    // 2. Granular allowed channels
    if (userPlan.allowed_channels) {
      const channelKey = slug === 'whatsapp_cloud' ? 'whatsapp' : slug;
      if (typeof userPlan.allowed_channels === 'object') {
        const val = userPlan.allowed_channels[channelKey] ?? userPlan.allowed_channels[slug];
        if (val === false || val === 0 || val === '0' || val === undefined) {
          return {
            locked: true,
            reason: tPlanLock('channelLockedDesc')
          };
        }
        if (typeof val === 'number' && val > 0) {
          const currentCount = getProviderConnectionCount(slug);
          if (currentCount >= val) {
            return {
              locked: true,
              reason: tPlanLock('channelPerCapReached', { max: val, plan: userPlan.name })
            };
          }
        }
      } else if (Array.isArray(userPlan.allowed_channels)) {
        if (!userPlan.allowed_channels.includes(channelKey) && !userPlan.allowed_channels.includes(slug)) {
          return {
            locked: true,
            reason: tPlanLock('channelLockedDesc')
          };
        }
      }
    }

    return { locked: false, reason: null };
  };

  const handleBackdropClick = (e: React.MouseEvent<HTMLDivElement>) => {
    if (e.target === e.currentTarget) {
      setSelectedChannelSlug(null);
    }
  };

  const handleSelectChannel = async (slug: string, bypassDisclaimer = false, connectionToEdit?: any) => {
    if (!connectionToEdit) {
      const lockStatus = isChannelLocked(slug);
      if (lockStatus.locked) {
        const nameMap: Record<string, string> = {
          whatsapp_cloud: tPlanLock('channelNames.whatsapp_cloud'),
          whatsapp_baileys: tPlanLock('channelNames.whatsapp_baileys'),
          instagram: tPlanLock('channelNames.instagram'),
          messenger: tPlanLock('channelNames.messenger'),
          telegram: tPlanLock('channelNames.telegram'),
          sms: tPlanLock('channelNames.sms'),
          email: tPlanLock('channelNames.email')
        };
        setLockedChannelInfo({
          title: tPlanLock('lockedFeature'),
          desc: lockStatus.reason || tPlanLock('channelLockedDesc'),
          name: nameMap[slug] || slug
        });
        setLockModalOpen(true);
        return;
      }
    }

    if (slug === 'whatsapp_baileys' && !baileysConnected && !bypassDisclaimer && !connectionToEdit) {
      setShowBaileysDisclaimer(true);
      return;
    }
    setSelectedChannelSlug(slug);
    setEditingConnectionId(connectionToEdit?.id || null);

    // Reset toggle visibility states
    setShowTelegramToken(false);
    setShowSmsTwilioToken(false);
    setShowSmsVonageSecret(false);
    setShowSmsPlivoToken(false);
    setShowSmsMessagebirdKey(false);
    setShowSmsSinchToken(false);
    setShowSmsTelnyxKey(false);
    setShowEmailSmtpPass(false);
    setShowEmailImapPass(false);
    setShowWhatsAppToken(false);

    if (slug === 'sms') {
      setSmsStep(connectionToEdit ? 2 : 1);
      setSmsProviderDropdownOpen(false);
    }
    if (slug === 'email') {
      setEmailStep(connectionToEdit ? 2 : 1);
    }

    if (connectionToEdit) {
      if (slug === 'whatsapp_cloud') {
        setNameInput(connectionToEdit.name || '');
        setPhoneInput(connectionToEdit.phone_number_id || '');
        setWabaInput(connectionToEdit.whatsapp_business_account_id || '');
        setSecretInput(connectionToEdit.webhook_secret || '');
        setTokenInput(connectionToEdit.raw_credentials?.system_user_access_token || '');
      } else if (slug === 'whatsapp_baileys') {
        setBaileysNameInput(connectionToEdit.name || '');
        setBaileysQr('');
        setBaileysNicknameRequired(false);
      } else if (slug === 'telegram') {
        setTelegramNameInput(connectionToEdit.name || '');
        setTelegramTokenInput(connectionToEdit.raw_credentials?.token || '');
      } else if (slug === 'sms') {
        setSmsNameInput(connectionToEdit.name || '');
        const provider = connectionToEdit.provider || 'twilio';
        setSmsProvider(provider);
        const raw = connectionToEdit.raw_credentials || {};
        if (provider === 'twilio') {
          setSmsTwilioSidInput(raw.twilio_account_sid || '');
          setSmsTwilioTokenInput(raw.twilio_auth_token || '');
          setSmsTwilioPhoneInput(raw.twilio_phone_number || '');
        } else if (provider === 'vonage') {
          setSmsVonageKeyInput(raw.vonage_api_key || '');
          setSmsVonageSecretInput(raw.vonage_api_secret || '');
          setSmsVonagePhoneInput(raw.vonage_phone_number || '');
        } else if (provider === 'plivo') {
          setSmsPlivoAuthIdInput(raw.plivo_auth_id || '');
          setSmsPlivoAuthTokenInput(raw.plivo_auth_token || '');
          setSmsPlivoPhoneInput(raw.plivo_phone_number || '');
        } else if (provider === 'messagebird') {
          setSmsMessagebirdKeyInput(raw.messagebird_api_key || '');
          setSmsMessagebirdPhoneInput(raw.messagebird_phone_number || '');
        } else if (provider === 'sinch') {
          setSmsSinchPlanIdInput(raw.sinch_service_plan_id || '');
          setSmsSinchTokenInput(raw.sinch_api_token || '');
          setSmsSinchPhoneInput(raw.sinch_phone_number || '');
        } else if (provider === 'telnyx') {
          setSmsTelnyxKeyInput(raw.telnyx_api_key || '');
          setSmsTelnyxPhoneInput(raw.telnyx_phone_number || '');
        }
      } else if (slug === 'email') {
        setEmailNameInput(connectionToEdit.name || '');
        const raw = connectionToEdit.raw_credentials || {};
        setEmailAddressInput(raw.email_address || raw.email || '');
        setEmailSmtpHostInput(raw.smtp_host || '');
        setEmailSmtpPortInput(String(raw.smtp_port || '587'));
        setEmailSmtpUserInput(raw.smtp_username || '');
        setEmailSmtpPassInput(raw.smtp_password || '');
        setEmailSmtpEncInput(raw.smtp_encryption || 'tls');
        setEmailImapHostInput(raw.imap_host || '');
        setEmailImapPortInput(String(raw.imap_port || '993'));
        setEmailImapUserInput(raw.imap_username || '');
        setEmailImapPassInput(raw.imap_password || '');
        setEmailImapEncInput(raw.imap_encryption || 'ssl');
      }
    } else {
      setNameInput('');
      setPhoneInput('');
      setWabaInput('');
      setTokenInput('');
      setSecretInput('');
      setTelegramNameInput('');
      setTelegramTokenInput('');
      setSmsNameInput('');
      setSmsProvider('twilio');
      setSmsTwilioSidInput('');
      setSmsTwilioTokenInput('');
      setSmsTwilioPhoneInput('');
      setSmsVonageKeyInput('');
      setSmsVonageSecretInput('');
      setSmsVonagePhoneInput('');
      setSmsPlivoAuthIdInput('');
      setSmsPlivoAuthTokenInput('');
      setSmsPlivoPhoneInput('');
      setSmsMessagebirdKeyInput('');
      setSmsMessagebirdPhoneInput('');
      setSmsSinchPlanIdInput('');
      setSmsSinchTokenInput('');
      setSmsSinchPhoneInput('');
      setSmsTelnyxKeyInput('');
      setSmsTelnyxPhoneInput('');
      setEmailNameInput('');
      setEmailAddressInput('');
      setEmailSmtpHostInput('');
      setEmailSmtpPortInput('587');
      setEmailSmtpUserInput('');
      setEmailSmtpPassInput('');
      setEmailSmtpEncInput('tls');
      setEmailImapHostInput('');
      setEmailImapPortInput('993');
      setEmailImapUserInput('');
      setEmailImapPassInput('');
      setEmailImapEncInput('ssl');

      setBaileysNameInput('');
      setBaileysQr('');
      setBaileysNicknameRequired(false);

      if (slug === 'whatsapp_baileys') {
        setTimeout(() => {
          triggerConnectBaileys();
        }, 50);
      }
    }
  };

  const handleConnectWhatsApp = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!nameInput || !phoneInput || !wabaInput || !tokenInput) {
      toast.error(t('toastFillRequiredFields'));
      return;
    }
    setIsSaving(true);
    try {
      const res = await fetchWithCsrf('/integrations/whatsapp/connect', {
        method: 'POST',
        body: JSON.stringify({
          id: editingConnectionId || undefined,
          name: nameInput,
          phone_number_id: phoneInput,
          whatsapp_business_account_id: wabaInput,
          system_user_access_token: tokenInput,
          webhook_secret: secretInput,
        })
      });
      if (res.ok) {
        toast.success(t('toastWhatsappConnectSuccess'));
        await fetchWhatsAppStatus();
        setSelectedChannelSlug(null);
        setEditingConnectionId(null);
      } else {
        const err = await res.json();
        toast.error(err.message || t('toastWhatsappConnectFailed'));
      }
    } catch {
      toast.error(t('toastNetworkError'));
    } finally {
      setIsSaving(false);
    }
  };

  const handleDisconnectWhatsApp = async (id?: number) => {
    try {
      const url = id ? `/integrations/whatsapp/disconnect/${id}` : '/integrations/whatsapp/disconnect';
      const res = await fetchWithCsrf(url, {
        method: 'DELETE'
      });
      if (res.ok) {
        toast.success(t('toastWhatsappDisconnectSuccess'));
        await fetchWhatsAppStatus();
        setSelectedChannelSlug(null);
      } else {
        toast.error(t('toastWhatsappDisconnectFailed'));
      }
    } catch {
      toast.error(t('toastNetworkError'));
    }
  };

  const triggerConnectBaileys = async (id?: number) => {
    setBaileysConnecting(true);
    setBaileysQr('');
    setBaileysNicknameRequired(false);
    try {
      const res = await fetchWithCsrf('/integrations/baileys/connect', {
        method: 'POST',
        body: JSON.stringify({ id: id || undefined, name: 'WhatsApp Baileys' })
      });
      if (res.ok) {
        const data = await res.json();
        const connectionId = data.id as number;
        baileysConnectionIdRef.current = connectionId;

        // Set a timeout to stop loading if QR is not received
        const qrTimeout = setTimeout(() => {
          setBaileysConnecting((prev) => {
            if (prev) return false;
            return prev;
          });
        }, 15000);

        // Listen to Reverb Echo channel for QR
        if (echo) {
          echo.channel(`channel-connection.${connectionId}`)
            .listen('.whatsapp.baileys.qr', (eventData: { qr: string }) => {
              console.log('[Echo] Received Baileys QR code:', eventData.qr);
              setBaileysQr(eventData.qr);
              setBaileysConnecting(false);
              clearTimeout(qrTimeout);
            });
        }

        // Start polling to detect successful scan state updates
        const interval = setInterval(async () => {
          try {
            const statusRes = await fetchWithCsrf('/integrations/baileys/status');
            if (statusRes.ok) {
              const statusData = await statusRes.json();
              if (statusData.connected && statusData.status === 'connected') {
                await fetchBaileysStatus();
                setBaileysQr('');
                setBaileysNameInput('');
                setBaileysNicknameRequired(true);
                
                if (baileysIntervalRef.current) {
                  clearInterval(baileysIntervalRef.current);
                }
                if (echo) {
                  echo.leave(`channel-connection.${connectionId}`);
                }
              }
            }
          } catch {}
        }, 3000);

        baileysIntervalRef.current = interval;
      } else {
        const err = await res.json();
        toast.error(err.message || 'Failed to initialize Baileys session.');
        setBaileysConnecting(false);
        setSelectedChannelSlug(null);
      }
    } catch {
      toast.error(t('toastNetworkError'));
      setBaileysConnecting(false);
      setSelectedChannelSlug(null);
    }
  };

  const handleSaveBaileysNickname = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!baileysNameInput) {
      toast.error(t('toastBaileysNameRequired'));
      return;
    }
    setIsSaving(true);
    try {
      const res = await fetchWithCsrf('/integrations/baileys/connect', {
        method: 'POST',
        body: JSON.stringify({ id: baileysConnectionIdRef.current || undefined, name: baileysNameInput })
      });
      if (res.ok) {
        toast.success(t('toastBaileysConnectSuccess'));
        await fetchBaileysStatus();
        setBaileysNicknameRequired(false);
        setSelectedChannelSlug(null);
      } else {
        const err = await res.json();
        toast.error(err.message || t('toastSaveBaileysNicknameFailed'));
      }
    } catch {
      toast.error(t('toastNetworkError'));
    } finally {
      setIsSaving(false);
    }
  };

  const handleDisconnectBaileys = async (id?: number) => {
    try {
      const url = id ? `/integrations/baileys/disconnect/${id}` : '/integrations/baileys/disconnect';
      const res = await fetchWithCsrf(url, {
        method: 'DELETE'
      });
      if (res.ok) {
        toast.success(t('toastBaileysDisconnectSuccess'));
        await fetchBaileysStatus();
        setBaileysNameInput('');
        setBaileysQr('');
        setSelectedChannelSlug(null);
        
        if (baileysIntervalRef.current) {
          clearInterval(baileysIntervalRef.current);
        }
        if (echo && baileysConnectionIdRef.current) {
          echo.leave(`channel-connection.${baileysConnectionIdRef.current}`);
        }
      } else {
        toast.error(t('toastBaileysDisconnectFailed'));
      }
    } catch {
      toast.error(t('toastNetworkError'));
    }
  };

  const handleDisconnectInstagram = async (id?: number) => {
    setIsSaving(true);
    try {
      const url = id ? `/integrations/instagram/disconnect/${id}` : '/integrations/instagram/disconnect';
      const res = await fetchWithCsrf(url, {
        method: 'DELETE'
      });
      if (res.ok) {
        toast.success(t('toastInstagramDisconnectSuccess'));
        await fetchInstagramStatus();
        setSelectedChannelSlug(null);
      } else {
        toast.error(t('toastInstagramDisconnectFailed'));
      }
    } catch {
      toast.error(t('toastNetworkError'));
    } finally {
      setIsSaving(false);
    }
  };

  const handleDisconnectMessenger = async (id?: number) => {
    setIsSaving(true);
    try {
      const url = id ? `/integrations/messenger/disconnect/${id}` : '/integrations/messenger/disconnect';
      const res = await fetchWithCsrf(url, {
        method: 'DELETE'
      });
      if (res.ok) {
        toast.success(t('toastMessengerDisconnectSuccess'));
        await fetchMessengerStatus();
        setSelectedChannelSlug(null);
      } else {
        toast.error(t('toastMessengerDisconnectFailed'));
      }
    } catch {
      toast.error(t('toastNetworkError'));
    } finally {
      setIsSaving(false);
    }
  };

  const handleConnectTelegram = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!telegramNameInput || !telegramTokenInput) {
      toast.error(t('toastTelegramCredentialsRequired'));
      return;
    }
    setTelegramConnecting(true);
    try {
      const res = await fetchWithCsrf('/integrations/telegram/connect', {
        method: 'POST',
        body: JSON.stringify({ id: editingConnectionId || undefined, name: telegramNameInput, token: telegramTokenInput })
      });
      if (res.ok) {
        toast.success(t('toastTelegramConnectSuccess'));
        await fetchTelegramStatus();
        setSelectedChannelSlug(null);
        setEditingConnectionId(null);
      } else {
        const err = await res.json();
        toast.error(err.message || t('toastTelegramConnectFailed'));
      }
    } catch {
      toast.error(t('toastNetworkError'));
    } finally {
      setTelegramConnecting(false);
    }
  };

  const handleDisconnectTelegram = async (id?: number) => {
    setIsSaving(true);
    try {
      const url = id ? `/integrations/telegram/disconnect/${id}` : '/integrations/telegram/disconnect';
      const res = await fetchWithCsrf(url, {
        method: 'DELETE'
      });
      if (res.ok) {
        toast.success(t('toastTelegramDisconnectSuccess'));
        await fetchTelegramStatus();
        setTelegramNameInput('');
        setTelegramTokenInput('');
        setSelectedChannelSlug(null);
      } else {
        toast.error(t('toastTelegramDisconnectFailed'));
      }
    } catch {
      toast.error(t('toastNetworkError'));
    } finally {
      setIsSaving(false);
    }
  };

  const handleConnectSms = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!smsNameInput) {
      toast.error(t('toastSmsNameRequired'));
      return;
    }

    const payload: Record<string, string | number | boolean | undefined> = {
      id: editingConnectionId || undefined,
      name: smsNameInput,
      provider: smsProvider
    };

    if (smsProvider === 'twilio') {
      if (!smsTwilioSidInput || !smsTwilioTokenInput || !smsTwilioPhoneInput) {
        toast.error(t('toastTwilioCredentialsRequired'));
        return;
      }
      payload.twilio_account_sid = smsTwilioSidInput;
      payload.twilio_auth_token = smsTwilioTokenInput;
      payload.twilio_phone_number = smsTwilioPhoneInput;
    } else if (smsProvider === 'vonage') {
      if (!smsVonageKeyInput || !smsVonageSecretInput || !smsVonagePhoneInput) {
        toast.error(t('toastVonageCredentialsRequired'));
        return;
      }
      payload.vonage_api_key = smsVonageKeyInput;
      payload.vonage_api_secret = smsVonageSecretInput;
      payload.vonage_phone_number = smsVonagePhoneInput;
    } else if (smsProvider === 'plivo') {
      if (!smsPlivoAuthIdInput || !smsPlivoAuthTokenInput || !smsPlivoPhoneInput) {
        toast.error(t('toastPlivoCredentialsRequired'));
        return;
      }
      payload.plivo_auth_id = smsPlivoAuthIdInput;
      payload.plivo_auth_token = smsPlivoAuthTokenInput;
      payload.plivo_phone_number = smsPlivoPhoneInput;
    } else if (smsProvider === 'messagebird') {
      if (!smsMessagebirdKeyInput || !smsMessagebirdPhoneInput) {
        toast.error(t('toastMessagebirdCredentialsRequired'));
        return;
      }
      payload.messagebird_api_key = smsMessagebirdKeyInput;
      payload.messagebird_phone_number = smsMessagebirdPhoneInput;
    } else if (smsProvider === 'sinch') {
      if (!smsSinchPlanIdInput || !smsSinchTokenInput || !smsSinchPhoneInput) {
        toast.error(t('toastSinchCredentialsRequired'));
        return;
      }
      payload.sinch_service_plan_id = smsSinchPlanIdInput;
      payload.sinch_api_token = smsSinchTokenInput;
      payload.sinch_phone_number = smsSinchPhoneInput;
    } else if (smsProvider === 'telnyx') {
      if (!smsTelnyxKeyInput || !smsTelnyxPhoneInput) {
        toast.error(t('toastTelnyxCredentialsRequired'));
        return;
      }
      payload.telnyx_api_key = smsTelnyxKeyInput;
      payload.telnyx_phone_number = smsTelnyxPhoneInput;
    }

    setSmsConnecting(true);
    try {
      const res = await fetchWithCsrf('/integrations/sms/connect', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      if (res.ok) {
        toast.success(t('toastSmsConnectSuccess', { provider: smsProvider.toUpperCase() }));
        await fetchSmsStatus();
        setSelectedChannelSlug(null);
        setEditingConnectionId(null);
      } else {
        const err = await res.json();
        toast.error(err.message || t('toastSmsConnectFailed'));
      }
    } catch {
      toast.error(t('toastNetworkError'));
    } finally {
      setSmsConnecting(false);
    }
  };

  const handleDisconnectSms = async (id?: number) => {
    setIsSaving(true);
    try {
      const url = id ? `/integrations/sms/disconnect/${id}` : '/integrations/sms/disconnect';
      const res = await fetchWithCsrf(url, {
        method: 'DELETE'
      });
      if (res.ok) {
        toast.success(t('toastSmsDisconnectSuccess'));
        await fetchSmsStatus();
        setSelectedChannelSlug(null);
      } else {
        toast.error(t('toastSmsDisconnectFailed'));
      }
    } catch {
      toast.error(t('toastNetworkError'));
    } finally {
      setIsSaving(false);
    }
  };

  const handleConnectEmail = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!emailNameInput.trim()) {
      toast.error(t('toastEmailNameRequired'));
      return;
    }

    const payload: Record<string, string | number | boolean | undefined> = {
      id: editingConnectionId || undefined,
      name: emailNameInput,
      provider: emailProvider
    };

    if (emailProvider === 'smtp') {
      if (!emailAddressInput || !emailSmtpHostInput || !emailSmtpPortInput || !emailSmtpUserInput || !emailSmtpPassInput ||
          !emailImapHostInput || !emailImapPortInput || !emailImapUserInput || !emailImapPassInput) {
        toast.error(t('toastEmailCredentialsRequired'));
        return;
      }
      payload.email_address = emailAddressInput;
      payload.smtp_host = emailSmtpHostInput;
      payload.smtp_port = parseInt(emailSmtpPortInput, 10);
      payload.smtp_username = emailSmtpUserInput;
      payload.smtp_password = emailSmtpPassInput;
      payload.smtp_encryption = emailSmtpEncInput;
      
      payload.imap_host = emailImapHostInput;
      payload.imap_port = parseInt(emailImapPortInput, 10);
      payload.imap_username = emailImapUserInput;
      payload.imap_password = emailImapPassInput;
      payload.imap_encryption = emailImapEncInput;
    }

    setEmailConnecting(true);
    try {
      const res = await fetchWithCsrf('/integrations/email/connect', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      if (res.ok) {
        toast.success(t('toastEmailConnectSuccess', { name: emailNameInput }));
        await fetchEmailStatus();
        setSelectedChannelSlug(null);
        setEditingConnectionId(null);
      } else {
        const err = await res.json();
        toast.error(err.message || t('toastEmailConnectFailed'));
      }
    } catch {
      toast.error(t('toastNetworkError'));
    } finally {
      setEmailConnecting(false);
    }
  };

  const handleDisconnectEmail = async (id?: number) => {
    setIsSaving(true);
    try {
      const url = id ? `/integrations/email/disconnect/${id}` : '/integrations/email/disconnect';
      const res = await fetchWithCsrf(url, {
        method: 'DELETE'
      });
      if (res.ok) {
        toast.success(t('toastEmailDisconnectSuccess'));
        await fetchEmailStatus();
        setSelectedChannelSlug(null);
      } else {
        toast.error(t('toastEmailDisconnectFailed'));
      }
    } catch {
      toast.error(t('toastNetworkError'));
    } finally {
      setIsSaving(false);
    }
  };



  const handleToggleConnectionStatus = async (item: ActiveChannelItem) => {
    const isCurrentlyActive = item.status === 'connected' || item.status === 'active';
    const nextStatus = isCurrentlyActive ? 'disabled' : 'connected';
    setTogglingConnectionId(item.id);

    try {
      const res = await fetchWithCsrf(`/channels/${item.id}/toggle`, {
        method: 'PATCH',
        body: JSON.stringify({ status: nextStatus })
      });
      if (res.ok) {
        toast.success(nextStatus === 'connected' ? (t('toastConnectionEnabled') || 'Connection enabled') : (t('toastConnectionDisabled') || 'Connection disabled'));
        if (item.slug === 'whatsapp_cloud') await fetchWhatsAppStatus();
        else if (item.slug === 'whatsapp_baileys') await fetchBaileysStatus();
        else if (item.slug === 'instagram') await fetchInstagramStatus();
        else if (item.slug === 'messenger') await fetchMessengerStatus();
        else if (item.slug === 'telegram') await fetchTelegramStatus();
        else if (item.slug === 'sms') await fetchSmsStatus();
        else if (item.slug === 'email') await fetchEmailStatus();
      } else {
        toast.error(t('toastUpdateStatusFailed') || 'Failed to update connection status');
      }
    } catch {
      toast.error(t('toastNetworkError') || 'Network error');
    } finally {
      setTogglingConnectionId(null);
    }
  };

  if (loading) {
    return <SimpleLoader message={t('details.syncing') || 'Syncing channel integrations...'} />;
  }

  // Active channels flattened list across all providers (supports multi-connection per provider)
  const activeChannels: ActiveChannelItem[] = [];

  if (globalChannelsEnabled['whatsapp_cloud'] !== false) {
    whatsappConnections.forEach((conn) => {
      activeChannels.push({
        id: conn.id || 0,
        slug: 'whatsapp_cloud',
        name: conn.name || 'WhatsApp Cloud API',
        providerTitle: 'WhatsApp Cloud API',
        status: conn.status || 'connected',
        details: conn,
      });
    });
  }

  if (globalChannelsEnabled['whatsapp_baileys'] !== false) {
    baileysConnections.forEach((conn) => {
      activeChannels.push({
        id: conn.id || 0,
        slug: 'whatsapp_baileys',
        name: conn.name || 'WhatsApp Baileys',
        providerTitle: 'WhatsApp Baileys (Unofficial)',
        status: conn.status || 'connected',
        details: conn,
      });
    });
  }

  if (globalChannelsEnabled['instagram'] !== false) {
    instagramConnections.forEach((conn) => {
      activeChannels.push({
        id: conn.id || 0,
        slug: 'instagram',
        name: conn.name || 'Instagram Account',
        providerTitle: 'Instagram Business',
        status: conn.status || 'connected',
        details: conn,
      });
    });
  }

  if (globalChannelsEnabled['messenger'] !== false) {
    messengerConnections.forEach((conn) => {
      activeChannels.push({
        id: conn.id || 0,
        slug: 'messenger',
        name: conn.name || 'Facebook Page',
        providerTitle: 'Facebook Messenger',
        status: conn.status || 'connected',
        details: conn,
      });
    });
  }

  if (globalChannelsEnabled['telegram'] !== false) {
    telegramConnections.forEach((conn) => {
      activeChannels.push({
        id: conn.id || 0,
        slug: 'telegram',
        name: conn.name || 'Telegram Bot',
        providerTitle: 'Telegram Bot',
        status: conn.status || 'connected',
        details: conn,
      });
    });
  }

  if (globalChannelsEnabled['sms'] !== false) {
    smsConnections.forEach((conn) => {
      activeChannels.push({
        id: conn.id || 0,
        slug: 'sms',
        name: conn.name || 'SMS Gateway',
        providerTitle: 'SMS Channel',
        status: conn.status || 'connected',
        details: conn,
      });
    });
  }

  if (globalChannelsEnabled['email'] !== false) {
    emailConnections.forEach((conn) => {
      activeChannels.push({
        id: conn.id || 0,
        slug: 'email',
        name: conn.name || 'Email Mailbox',
        providerTitle: 'Email Channel',
        status: conn.status || 'connected',
        details: conn,
      });
    });
  }

  const getProviderConnectionCount = (slug: string) => {
    switch (slug) {
      case 'whatsapp_cloud': return whatsappConnections.length;
      case 'whatsapp_baileys': return baileysConnections.length;
      case 'instagram': return instagramConnections.length;
      case 'messenger': return messengerConnections.length;
      case 'telegram': return telegramConnections.length;
      case 'sms': return smsConnections.length;
      case 'email': return emailConnections.length;
      default: return 0;
    }
  };

  const availableGateways = availableChannelsList.filter(c => globalChannelsEnabled[c.slug] !== false);

  return (
    <div className="w-full p-4 sm:p-6 md:p-8 space-y-6 sm:space-y-8 select-none font-sans text-[#0A0A0A] flex-1 overflow-y-auto">

      {/* 1. Configured / Active Channels Datatable */}
      {activeChannels.length > 0 ? (
        <div className="space-y-3">
          <div className="space-y-1">
            <span className="text-[10px] font-black text-zinc-400 uppercase tracking-widest block">{t('activeTitle')}</span>
            <p className="text-[10px] text-zinc-500 font-medium">{t('configuredConversationalLines')}</p>
          </div>

          <div className="border border-[#E8E8E6] rounded-xl overflow-hidden bg-white shadow-3xs">
            <div className="overflow-x-auto scrollbar-thin">
              <table className="w-full text-left border-collapse min-w-[580px]">
                <thead>
                  <tr className="bg-neutral-50 border-b border-[#E8E8E6] text-[10px] font-bold text-zinc-500 uppercase tracking-wider">
                    <th className="p-4">{t('channel')}</th>
                    <th className="p-4">{t('connectionDetails')}</th>
                    <th className="p-4">{tCommon('status') || 'Status'}</th>
                    <th className="p-4 text-right">{tCommon('actions')}</th>
                  </tr>
                </thead>
              <tbody className="divide-y divide-[#F0F0F0] text-xs font-semibold text-zinc-700">
                {activeChannels.map((item) => {
                  const isEnabled = item.status === 'connected' || item.status === 'active';
                  const isToggling = togglingConnectionId === item.id;
                  return (
                    <tr key={`${item.slug}-${item.id}`} className="hover:bg-neutral-50/50">
                      <td className="p-4 flex items-center gap-3">
                        <div className="h-8 w-8 flex items-center justify-center bg-zinc-50 border border-zinc-150 rounded-lg p-1 shrink-0">
                          {renderChannelLogo(item.slug, "h-5 w-5", item.slug === 'sms' ? (item.details as SmsConnection)?.provider : undefined)}
                        </div>
                        <div>
                          <div className="flex items-center gap-1.5">
                            <span className="text-zinc-955 font-bold block">
                              {item.name || item.providerTitle}
                            </span>
                            <span className="text-[10px] font-mono text-zinc-400 font-medium">
                              #{item.id}
                            </span>
                          </div>
                          <span className="text-[10px] text-zinc-400 font-medium">{item.providerTitle}</span>
                        </div>
                      </td>
                      <td className="p-4">
                        {item.slug === 'whatsapp_cloud' ? (
                          <div className="flex flex-col gap-0.5">
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">Phone ID:</span>
                              <span className="font-mono font-bold text-zinc-900 text-[11px]">{(item.details as WhatsAppConnection)?.phone_number_id || 'N/A'}</span>
                            </div>
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">Account ID:</span>
                              <span className="font-mono font-semibold text-zinc-800 text-[10.5px]">{(item.details as WhatsAppConnection)?.whatsapp_business_account_id || 'N/A'}</span>
                            </div>
                          </div>
                        ) : item.slug === 'whatsapp_baileys' ? (
                          <div className="flex flex-col gap-0.5">
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">Driver:</span>
                              <span className="font-bold text-zinc-900 text-[11px]">Node-Baileys</span>
                            </div>
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">Session:</span>
                              <span className="font-semibold text-zinc-800 text-[10.5px]">Active Instance</span>
                            </div>
                          </div>
                        ) : item.slug === 'instagram' ? (
                          <div className="flex flex-col gap-0.5">
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">Account ID:</span>
                              <span className="font-mono font-bold text-zinc-900 text-[11px]">{(item.details as InstagramConnection)?.instagram_business_account_id || 'Linked'}</span>
                            </div>
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">Provider:</span>
                              <span className="font-semibold text-zinc-800 text-[10.5px]">Meta Graph API</span>
                            </div>
                          </div>
                        ) : item.slug === 'messenger' ? (
                          <div className="flex flex-col gap-0.5">
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">Page ID:</span>
                              <span className="font-mono font-bold text-zinc-900 text-[11px]">{(item.details as FacebookMessengerConnection)?.page_id || 'Linked'}</span>
                            </div>
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">Provider:</span>
                              <span className="font-semibold text-zinc-800 text-[10.5px]">Meta Graph API</span>
                            </div>
                          </div>
                        ) : item.slug === 'telegram' ? (
                          <div className="flex flex-col gap-0.5">
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">Bot Username:</span>
                              <span className="font-bold text-zinc-900 text-[11px]">@{(item.details as TelegramConnection)?.bot_username || 'Bot'}</span>
                            </div>
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">Provider:</span>
                              <span className="font-semibold text-zinc-800 text-[10.5px]">Telegram Bot API</span>
                            </div>
                          </div>
                        ) : item.slug === 'sms' ? (
                          <div className="flex flex-col gap-0.5">
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">Gateway:</span>
                              <span className="font-bold text-zinc-900 capitalize text-[11px]">{(item.details as SmsConnection)?.provider || 'SMS'}</span>
                            </div>
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">Phone:</span>
                              <span className="font-mono font-bold text-zinc-900 text-[10.5px]">
                                {(item.details as SmsConnection)?.details?.twilio_phone_number ||
                                 (item.details as SmsConnection)?.details?.vonage_phone_number ||
                                 (item.details as SmsConnection)?.details?.plivo_phone_number ||
                                 (item.details as SmsConnection)?.details?.messagebird_phone_number ||
                                 (item.details as SmsConnection)?.details?.sinch_phone_number ||
                                 (item.details as SmsConnection)?.details?.telnyx_phone_number ||
                                 'Configured'}
                              </span>
                            </div>
                          </div>
                        ) : (
                          <div className="flex flex-col gap-0.5">
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">Address:</span>
                              <span className="font-bold text-zinc-900 text-[11px]">{(item.details as EmailConnection)?.details?.email_address || 'Connected'}</span>
                            </div>
                            <div className="flex items-center gap-1.5 text-[10px]">
                              <span className="text-zinc-400 font-medium">SMTP Host:</span>
                              <span className="font-mono font-semibold text-zinc-800 text-[10.5px]">{(item.details as EmailConnection)?.details?.smtp_host || 'SMTP'}</span>
                            </div>
                          </div>
                        )}
                      </td>
                      <td className="p-4">
                        <div className="flex items-center gap-2.5">
                          <button
                            type="button"
                            disabled={isToggling}
                            onClick={() => handleToggleConnectionStatus(item)}
                            className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${
                              isEnabled ? 'bg-[#0A0A0A]' : 'bg-zinc-200'
                            } ${isToggling ? 'opacity-50 cursor-wait' : ''}`}
                          >
                            <span
                              className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow-xs ring-0 transition duration-200 ease-in-out ${
                                isEnabled ? 'translate-x-4' : 'translate-x-0'
                              }`}
                            />
                          </button>
                          <span className={`text-[10px] font-bold uppercase tracking-wider ${
                            isEnabled ? 'text-zinc-955' : 'text-zinc-400'
                          }`}>
                            {isEnabled ? (tCommon('enabled') || 'Enabled') : (tCommon('disabled') || 'Disabled')}
                          </span>
                        </div>
                      </td>
                      <td className="p-4 text-right space-x-2">
                        <button
                          onClick={() => handleSelectChannel(item.slug, false, item.details)}
                          className="px-2.5 py-1 border border-zinc-150 hover:bg-zinc-50 text-zinc-600 hover:text-zinc-900 rounded-[6px] text-[11px] font-medium transition-all cursor-pointer inline-flex items-center"
                        >
                          {tCommon('manage') || 'Manage'}
                        </button>
                        <button
                          onClick={() => setDisconnectTarget({ slug: item.slug, id: item.id, name: item.name })}
                          className="px-2.5 py-1 border border-red-100 hover:bg-red-50/50 text-red-600 hover:text-red-700 rounded-[6px] text-[11px] font-medium transition-all cursor-pointer inline-flex items-center"
                        >
                          {t('disconnectBtn') || 'Disconnect'}
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      </div>
      ) : (
        <div className="w-full border border-dashed border-[#E8E8E6] rounded-2xl p-12 text-center bg-white space-y-4 select-none">
          <div className="h-12 w-12 rounded-full bg-zinc-50 border border-[#E8E8E6] flex items-center justify-center mx-auto text-zinc-400">
            <Settings className="h-5 w-5" />
          </div>
          <div className="space-y-1.5">
            <h3 className="text-sm font-bold text-black uppercase tracking-wider">{t('noActiveChannels')}</h3>
            <p className="text-xs text-[#6B6B6B] max-w-sm mx-auto leading-relaxed">
              {t('noActiveChannelsDesc')}
            </p>
          </div>
        </div>
      )}

      {/* 2. Available Channels Grid (Matches AI Providers styles & Supports Multi-Connection) */}
      <div className="space-y-4 pt-4 border-t border-[#F0F0F0]">
        <div className="space-y-1">
          <span className="text-[10px] font-black text-zinc-400 uppercase tracking-widest block">{t('availableTitle')}</span>
          <p className="text-[10px] text-zinc-500 font-medium">{t('activateExtraConversational')}</p>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {availableGateways.map((c) => {
            const count = getProviderConnectionCount(c.slug);
            const lockStatus = isChannelLocked(c.slug);
            const isLocked = lockStatus.locked;
            return (
              <div 
                key={c.slug}
                className={`border rounded-2xl p-5 bg-white text-left flex flex-col justify-between shadow-3xs transition-all duration-200 ${
                  isLocked 
                    ? 'border-zinc-200/80 opacity-90' 
                    : 'border-[#E8E8E6]/60 hover:shadow-2xs hover:border-zinc-300'
                }`}
              >
                <div>
                  {/* Header: Logo */}
                  <div className="flex items-start mb-4">
                    <div className="h-10 w-10 flex items-center justify-center bg-zinc-50 border border-zinc-100 rounded-xl p-1.5 shrink-0">
                      {renderChannelLogo(c.slug, "h-6 w-6")}
                    </div>
                  </div>

                  <div className="space-y-3">
                    <div>
                      <h4 className="font-extrabold text-xs text-black uppercase tracking-wider">
                        {c.slug === 'whatsapp_cloud' ? t('whatsappCloud') :
                         c.slug === 'whatsapp_baileys' ? t('whatsappBaileys') :
                         c.slug === 'instagram' ? t('instagram') :
                         c.slug === 'messenger' ? t('messenger') :
                         c.slug === 'telegram' ? t('telegram') :
                         c.slug === 'sms' ? t('sms') :
                         c.slug === 'email' ? t('email') : c.name}
                      </h4>
                      <p className="text-[11px] text-[#6B6B6B] mt-1.5 leading-relaxed min-h-[48px] line-clamp-3">
                        {c.slug === 'whatsapp_cloud' ? t('whatsappCloudDesc') :
                         c.slug === 'whatsapp_baileys' ? t('whatsappBaileysDesc') :
                         c.slug === 'instagram' ? t('instagramDesc') :
                         c.slug === 'messenger' ? t('messengerDesc') :
                         c.slug === 'telegram' ? t('telegramDesc') :
                         c.slug === 'sms' ? t('smsDesc') :
                         c.slug === 'email' ? t('emailDesc') : c.description}
                      </p>
                    </div>
                  </div>
                </div>

                <div className="border-t border-[#F0F0F0] pt-3.5 mt-5">
                  {isLocked ? (
                    <button
                      type="button"
                      onClick={() => {
                        const nameMap: Record<string, string> = {
                          whatsapp_cloud: tPlanLock('channelNames.whatsapp_cloud'),
                          whatsapp_baileys: tPlanLock('channelNames.whatsapp_baileys'),
                          instagram: tPlanLock('channelNames.instagram'),
                          messenger: tPlanLock('channelNames.messenger'),
                          telegram: tPlanLock('channelNames.telegram'),
                          sms: tPlanLock('channelNames.sms'),
                          email: tPlanLock('channelNames.email')
                        };
                        setLockedChannelInfo({
                          title: tPlanLock('lockedFeature'),
                          desc: lockStatus.reason || tPlanLock('channelLockedDesc'),
                          name: nameMap[c.slug] || c.name
                        });
                        setLockModalOpen(true);
                      }}
                      className="w-full py-2 bg-zinc-955 hover:bg-black text-white rounded-[6px] text-[10px] font-bold tracking-wider uppercase transition-all cursor-pointer shadow-3xs text-center flex items-center justify-center gap-1.5"
                    >
                      <Lock className="h-3 w-3" />
                      <span>{tPlanLock('upgradeToUnlock')}</span>
                    </button>
                  ) : (
                    <button
                      onClick={() => handleSelectChannel(c.slug, false, null)}
                      className="w-full py-2 border border-zinc-200 hover:bg-zinc-50 text-zinc-700 hover:text-black rounded-[6px] text-[10px] font-semibold tracking-wider uppercase transition-all cursor-pointer shadow-3xs text-center flex items-center justify-center gap-1.5"
                    >
                      <span>+</span>
                      <span>{count > 0 ? tPlanLock('addAnotherConnection') : (t('configure') || 'Connect Account')}</span>
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* 3. DIALOG MODAL (Platform Consistent Modal Pattern) */}
      {selectedChannelSlug && mounted && typeof document !== 'undefined' && createPortal(
        <div 
          onClick={handleBackdropClick}
          className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 animate-overlay-fade select-none"
        >
          <div className="fixed inset-0" onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }} />
          <div 
            onClick={(e) => e.stopPropagation()}
            className={`bg-white border border-[#E8E8E6] rounded-xl w-full p-6 shadow-xl relative z-50 animate-modal-box text-left transition-all ${
              selectedChannelSlug === 'email' && emailStep === 2 ? 'max-w-2xl' : 'max-w-md'
            }`}
          >
            {/* Modal Header (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6">
              <div className="flex items-center gap-2.5 min-w-0 pr-3">
                <div className="h-7 w-7 rounded bg-[#FAFAFA] border border-[#E8E8E6] p-1 flex items-center justify-center shrink-0">
                  {renderChannelLogo(selectedChannelSlug, "h-4 w-4")}
                </div>
                <div className="min-w-0">
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider truncate">
                    {editingConnectionId ? (
                      `Edit Connection (#${editingConnectionId})`
                    ) : (
                      selectedChannelSlug === 'whatsapp_cloud' ? t('configureWhatsappCloud') :
                      selectedChannelSlug === 'whatsapp_baileys' ? t('configureWhatsappBaileys') :
                      selectedChannelSlug === 'instagram' ? t('configureInstagramDirect') :
                      selectedChannelSlug === 'messenger' ? t('configureFacebookMessenger') :
                      selectedChannelSlug === 'telegram' ? t('configureTelegramBot') :
                      selectedChannelSlug === 'sms' ? t('configureSmsGateway') :
                      t('configureEmailMailbox')
                    )}
                  </h3>
                  <span className="text-[10px] text-zinc-500 block mt-0.5 truncate font-medium">
                    {editingConnectionId ? (
                      'Update credentials and configuration for this channel line.'
                    ) : (
                      selectedChannelSlug === 'whatsapp_cloud' ? t('descWhatsappCloud') :
                      selectedChannelSlug === 'whatsapp_baileys' ? t('descWhatsappBaileys') :
                      selectedChannelSlug === 'instagram' ? t('descInstagramDirect') :
                      selectedChannelSlug === 'messenger' ? t('descFacebookMessenger') :
                      selectedChannelSlug === 'telegram' ? t('descTelegramBot') :
                      selectedChannelSlug === 'sms' ? t('descSmsGateway') :
                      t('descEmailMailbox')
                    )}
                  </span>
                </div>
              </div>
              <button
                type="button"
                onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none shrink-0"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            {/* Modal Body */}
            <div className="pt-4">
              
              {/* WhatsApp Cloud API Dialog */}
              {selectedChannelSlug === 'whatsapp_cloud' && (
                <form onSubmit={handleConnectWhatsApp} className="space-y-4">
                  {whatsappConnected && whatsappDetails?.webhook_url && (
                    renderWebhookInfo(whatsappDetails.webhook_url, whatsappDetails.webhook_verify_token, t)
                  )}

                  <div className="grid grid-cols-2 gap-3.5">
                    <div className="space-y-1.5 col-span-2">
                      <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('connectionName')}</label>
                      <input
                        type="text"
                        required
                        placeholder={t('connectionNamePlaceholder')}
                        value={nameInput}
                        onChange={(e) => setNameInput(e.target.value)}
                        className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                      />
                    </div>
                    <div className="space-y-1.5">
                      <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('phoneNumberId')}</label>
                      <input
                        type="text"
                        required
                        placeholder={t('phoneNumberIdPlaceholder')}
                        value={phoneInput}
                        onChange={(e) => setPhoneInput(e.target.value)}
                        className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                      />
                    </div>
                    <div className="space-y-1.5">
                      <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('wabaAccountId')}</label>
                      <input
                        type="text"
                        required
                        placeholder={t('wabaAccountIdPlaceholder')}
                        value={wabaInput}
                        onChange={(e) => setWabaInput(e.target.value)}
                        className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                      />
                    </div>
                    <div className="space-y-1.5 col-span-2">
                      <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('webhookClientSecret') || 'Webhook Client Secret'} <span className="text-zinc-400 lowercase">({t('optional') || 'optional'})</span></label>
                      <input
                        type="text"
                        placeholder={t('hmacVerificationKeyPlaceholder')}
                        value={secretInput}
                        onChange={(e) => setSecretInput(e.target.value)}
                        className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                      />
                    </div>
                    <div className="space-y-1.5 col-span-2">
                      <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('permanentAccessToken')}</label>
                      <div className="relative">
                        <input
                          type={showWhatsAppToken ? "text" : "password"}
                          required
                          placeholder="EAAGb3eSZC..."
                          value={tokenInput}
                          onChange={(e) => setTokenInput(e.target.value)}
                          className="w-full h-9 pl-3 pr-10 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                        />
                        <button
                          type="button"
                          onClick={() => setShowWhatsAppToken(!showWhatsAppToken)}
                          className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700 cursor-pointer focus:outline-none"
                        >
                          {showWhatsAppToken ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                        </button>
                      </div>
                    </div>
                  </div>

                  <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-between select-none font-bold">
                    <div>
                      {editingConnectionId && (
                        <button
                          type="button"
                          onClick={() => {
                            const target = activeChannels.find(c => c.id === editingConnectionId);
                            if (target) {
                              setDisconnectTarget({
                                id: target.id,
                                slug: target.slug,
                                name: target.name
                              });
                              setSelectedChannelSlug(null);
                              setEditingConnectionId(null);
                            }
                          }}
                          className="flex items-center gap-1.5 px-3 py-1.5 text-red-650 hover:text-red-750 font-semibold text-[10px] uppercase tracking-wider hover:bg-red-50 rounded transition-all cursor-pointer border border-transparent hover:border-red-100"
                        >
                          <Trash2 className="h-3.5 w-3.5" />
                          <span>{t('disconnect') || 'Disconnect'}</span>
                        </button>
                      )}
                    </div>
                    <div className="flex items-center gap-3">
                      <button
                        type="button"
                        onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }}
                        className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                      >
                        {tCommon('cancel') || 'Cancel'}
                      </button>
                      <button
                        type="submit"
                        disabled={isSaving}
                        className="h-9 px-4 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer flex items-center justify-center min-w-[100px] gap-1.5 disabled:opacity-50"
                      >
                        {isSaving ? <RefreshCw className="h-3.5 w-3.5 animate-spin" /> : editingConnectionId ? (tCommon('updateConnection') || 'Update Connection') : (tCommon('connect') || 'Connect')}
                      </button>
                    </div>
                  </div>
                </form>
              )}

              {/* WhatsApp Baileys Dialog */}
              {selectedChannelSlug === 'whatsapp_baileys' && (
                baileysConnected ? (
                  baileysNicknameRequired ? (
                    <form onSubmit={handleSaveBaileysNickname} className="space-y-4">
                      <div className="space-y-2">
                        <h4 className="text-xs font-bold text-emerald-800 uppercase tracking-wider">{t('deviceLinkedSuccessfully')}</h4>
                        <p className="text-[11px] text-zinc-500 font-semibold leading-relaxed">
                          {t('enterNicknameDesc') || 'Please enter a nickname to identify this WhatsApp channel.'}
                        </p>
                      </div>
                      <div className="space-y-1.5">
                        <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('connectionNickname')}</label>
                        <input
                          type="text"
                          required
                          placeholder={t('connectionNicknamePlaceholder')}
                          value={baileysNameInput}
                          onChange={(e) => setBaileysNameInput(e.target.value)}
                          className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                        />
                      </div>
                      <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none font-bold">
                        <button
                          type="submit"
                          disabled={isSaving}
                          className="h-9 px-4 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer flex items-center justify-center min-w-[100px] gap-1.5 disabled:opacity-50"
                        >
                          {isSaving ? <RefreshCw className="h-3.5 w-3.5 animate-spin" /> : tCommon('save') || 'Save & Complete'}
                        </button>
                      </div>
                    </form>
                  ) : (
                    <div className="space-y-4 text-xs select-none">
                      <div className="bg-[#E8FDE8] border border-[#3DD43D]/30 p-4 rounded-lg flex items-center gap-3">
                        <span className="h-2.5 w-2.5 rounded-full bg-[#4AE54A] animate-pulse shrink-0" />
                        <div>
                          <span className="font-bold text-emerald-900 uppercase block tracking-wider text-[10px]">{t('connectedSuccessfully')}</span>
                          <span className="text-emerald-700 font-semibold mt-0.5 block">{t('unofficialLinkedDesc')}</span>
                        </div>
                      </div>
                      <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end select-none font-bold">
                        <button
                          type="button"
                          onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }}
                          className="h-9 px-4 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer"
                        >
                          {tCommon('done') || 'Done'}
                        </button>
                      </div>
                    </div>
                  )
                ) : (
                  <div className="space-y-4">
                    {baileysQr ? (
                      <div className="border border-[#E8E8E6] rounded-lg p-5 bg-zinc-50/50 flex flex-col items-center justify-center space-y-3.5 select-none animate-fade-in text-center">
                        <span className="text-[9px] text-[#A0A0A0] font-black uppercase tracking-wider">{t('scanQrCodeInWhatsapp')}</span>
                        <div className="h-44 w-44 bg-white border border-[#E8E8E6] rounded-lg p-2 flex items-center justify-center select-none shadow-3xs mx-auto">
                          <img 
                            src={baileysQr} 
                            alt="Linking QR Code" 
                            className="h-full w-full object-contain"
                          />
                        </div>
                        <p className="text-[10px] text-zinc-500 font-semibold leading-relaxed max-w-xs mx-auto">
                          {t('baileysScanInstructions') || 'Open WhatsApp → Linked Devices → Link a Device. Scan the QR above to persist the auth session.'}
                        </p>
                      </div>
                    ) : (
                      baileysConnecting ? (
                        <div className="border border-dashed border-[#E8E8E6] rounded-lg p-10 flex flex-col items-center justify-center space-y-2 select-none text-center">
                          <RefreshCw className="h-5 w-5 animate-spin text-[#A0A0A0] mx-auto" />
                          <span className="text-[10px] text-zinc-500 font-semibold">{t('contactingWorkerQr')}</span>
                        </div>
                      ) : (
                        <div className="border border-dashed border-[#E8E8E6] rounded-lg p-10 flex flex-col items-center justify-center space-y-2 select-none text-center">
                          <span className="text-[10px] text-zinc-500 font-semibold text-rose-600">{t('failedLoadQr')}</span>
                        </div>
                      )
                    )}
                    <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end select-none font-bold">
                      <button
                        type="button"
                        onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }}
                        className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                      >
                        {tCommon('cancel') || 'Cancel'}
                      </button>
                    </div>
                  </div>
                )
              )}

              {/* Instagram Dialog */}
              {selectedChannelSlug === 'instagram' && (
                instagramConnected ? (
                  <div className="space-y-4 text-xs select-none">
                    <div className="bg-[#E8FDE8] border border-[#3DD43D]/30 p-4 rounded-lg flex items-center gap-3">
                      <span className="h-2.5 w-2.5 rounded-full bg-[#4AE54A] animate-pulse shrink-0" />
                      <div>
                        <span className="font-bold text-emerald-900 uppercase block tracking-wider text-[10px]">{t('connectedSuccessfully') || 'Connected successfully'}</span>
                        <span className="text-emerald-700 font-semibold mt-0.5 block">
                          {t('instagramLinkedDesc', { accountName: instagramDetails?.name || 'Sandbox Account' })}
                        </span>
                      </div>
                    </div>
                    <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end select-none font-bold">
                      <button
                        type="button"
                        onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }}
                        className="h-9 px-4 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer"
                      >
                        {tCommon('done') || 'Done'}
                      </button>
                    </div>
                  </div>
                ) : (
                  <div className="space-y-4 font-sans select-none">
                    <p className="text-xs text-zinc-500 font-medium leading-relaxed">
                      Connect your Instagram Business account to manage direct messages, automated replies, and customer interactions seamlessly.
                    </p>
                    <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none font-bold">
                      <button
                        type="button"
                        onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }}
                        className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                      >
                        {tCommon('cancel') || 'Cancel'}
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          window.location.href = '/api/integrations/instagram/oauth/redirect';
                        }}
                        className="h-9 px-4 bg-[#1877F2] hover:bg-[#166FE5] text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all flex items-center justify-center gap-2 cursor-pointer"
                      >
                        {t('connectFacebook') || 'Connect via Facebook Login'}
                      </button>
                    </div>
                  </div>
                )
              )}

              {/* Facebook Messenger Dialog */}
              {selectedChannelSlug === 'messenger' && (
                messengerConnected ? (
                  <div className="space-y-4 text-xs select-none">
                    <div className="bg-[#E8FDE8] border border-[#3DD43D]/30 p-4 rounded-lg flex items-center gap-3">
                      <span className="h-2.5 w-2.5 rounded-full bg-[#4AE54A] animate-pulse shrink-0" />
                      <div>
                        <span className="font-bold text-emerald-900 uppercase block tracking-wider text-[10px]">{t('connectedSuccessfully') || 'Connected successfully'}</span>
                        <span className="text-emerald-700 font-semibold mt-0.5 block">
                          {t('messengerLinkedDesc', { pageName: messengerDetails?.name || 'Sandbox Page' })}
                        </span>
                      </div>
                    </div>
                    <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end select-none font-bold">
                      <button
                        type="button"
                        onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }}
                        className="h-9 px-4 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer"
                      >
                        {tCommon('done') || 'Done'}
                      </button>
                    </div>
                  </div>
                ) : (
                  <div className="space-y-4 font-sans select-none">
                    <p className="text-xs text-zinc-500 font-medium leading-relaxed">
                      Connect your Facebook Business Page to receive and reply to client inquiries in real time across the platform.
                    </p>
                    <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none font-bold">
                      <button
                        type="button"
                        onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }}
                        className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                      >
                        {tCommon('cancel') || 'Cancel'}
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          window.location.href = '/api/integrations/messenger/oauth/redirect';
                        }}
                        className="h-9 px-4 bg-[#1877F2] hover:bg-[#166FE5] text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all flex items-center justify-center gap-2 cursor-pointer"
                      >
                        {t('connectFacebook') || 'Connect via Facebook Login'}
                      </button>
                    </div>
                  </div>
                )
              )}

              {/* Telegram Dialog */}
              {selectedChannelSlug === 'telegram' && (
                <form onSubmit={handleConnectTelegram} className="space-y-4 font-sans select-none">
                  {telegramConnected && telegramDetails?.webhook_url && (
                    renderWebhookInfo(telegramDetails.webhook_url, telegramDetails.webhook_verify_token, t)
                  )}

                  <div className="space-y-3.5">
                    <div className="space-y-1.5">
                      <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('connectionName')}</label>
                      <input
                        type="text"
                        required
                        value={telegramNameInput}
                        onChange={(e) => setTelegramNameInput(e.target.value)}
                        placeholder={t('mySupportBotPlaceholder')}
                        className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                      />
                    </div>

                    <div className="space-y-1.5">
                      <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('botToken')}</label>
                      <div className="relative">
                        <input
                          type={showTelegramToken ? "text" : "password"}
                          required
                          value={telegramTokenInput}
                          onChange={(e) => setTelegramTokenInput(e.target.value)}
                          placeholder="123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ"
                          className="w-full h-9 pl-3 pr-10 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                        />
                        <button
                          type="button"
                          onClick={() => setShowTelegramToken(!showTelegramToken)}
                          className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700 cursor-pointer focus:outline-none"
                        >
                          {showTelegramToken ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                        </button>
                      </div>
                    </div>
                  </div>

                  <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-between select-none font-bold">
                    <div>
                      {editingConnectionId && (
                        <button
                          type="button"
                          onClick={() => {
                            const target = activeChannels.find(c => c.id === editingConnectionId);
                            if (target) {
                              setDisconnectTarget({
                                id: target.id,
                                slug: target.slug,
                                name: target.name
                              });
                              setSelectedChannelSlug(null);
                              setEditingConnectionId(null);
                            }
                          }}
                          className="flex items-center gap-1.5 px-3 py-1.5 text-red-650 hover:text-red-750 font-semibold text-[10px] uppercase tracking-wider hover:bg-red-50 rounded transition-all cursor-pointer border border-transparent hover:border-red-100"
                        >
                          <Trash2 className="h-3.5 w-3.5" />
                          <span>{t('disconnect') || 'Disconnect'}</span>
                        </button>
                      )}
                    </div>
                    <div className="flex items-center gap-3">
                      <button
                        type="button"
                        onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }}
                        className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                      >
                        {tCommon('cancel') || 'Cancel'}
                      </button>
                      <button
                        type="submit"
                        disabled={telegramConnecting}
                        className="h-9 px-4 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer flex items-center justify-center min-w-[100px] gap-1.5 disabled:opacity-50"
                      >
                        {telegramConnecting ? <RefreshCw className="h-3.5 w-3.5 animate-spin" /> : editingConnectionId ? (tCommon('updateConnection') || 'Update Connection') : (tCommon('connect') || 'Connect')}
                      </button>
                    </div>
                  </div>
                </form>
              )}

              {/* SMS Dialog */}
              {selectedChannelSlug === 'sms' && (
                <form onSubmit={handleConnectSms} className="space-y-4 font-sans select-none">
                  {smsStep === 1 ? (
                    <>
                      <div className="space-y-3.5">
                        <div className="space-y-1.5">
                          <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('connectionName')}</label>
                          <input
                            type="text"
                            required
                            value={smsNameInput}
                            onChange={(e) => setSmsNameInput(e.target.value)}
                            placeholder={t('smsGatewayLinePlaceholder')}
                            className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                          />
                        </div>

                        <div className="space-y-1.5">
                          <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('smsProvider')}</label>
                          <DropdownSelect
                            options={[
                              { value: 'twilio', label: 'Twilio' },
                              { value: 'vonage', label: 'Vonage' },
                              { value: 'plivo', label: 'Plivo' },
                              { value: 'messagebird', label: 'MessageBird' },
                              { value: 'sinch', label: 'Sinch' },
                              { value: 'telnyx', label: 'Telnyx' }
                            ].filter(p => globalSmsProvidersEnabled[p.value] !== false)}
                            value={smsProvider}
                            onChange={(val) => setSmsProvider(val as any)}
                            placeholder={t('selectSmsProvider') || 'Select SMS Gateway Provider'}
                          />
                        </div>
                      </div>

                      <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none font-bold">
                        <button
                          type="button"
                          onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }}
                          className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                        >
                          {tCommon('cancel') || 'Cancel'}
                        </button>
                        <button
                          type="button"
                          onClick={() => {
                            if (!smsNameInput.trim()) {
                                toast.error(t('toastSmsNameRequired'));
                                return;
                            }
                            setSmsStep(2);
                          }}
                          className="h-9 px-4 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer flex items-center justify-center min-w-[100px]"
                        >
                          {t('nextStep')}
                        </button>
                      </div>
                    </>
                  ) : (
                    <>
                      {smsConnected && smsDetails?.webhook_url && (
                        renderWebhookInfo(smsDetails.webhook_url, smsDetails.webhook_verify_token, t)
                      )}

                      <div className="space-y-3.5">
                        {smsProvider === 'twilio' && (
                          <>
                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('accountSid')}</label>
                              <input
                                type="text"
                                required
                                value={smsTwilioSidInput}
                                onChange={(e) => setSmsTwilioSidInput(e.target.value)}
                                placeholder="AC..."
                                className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                              />
                            </div>

                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('authToken')}</label>
                              <div className="relative">
                                <input
                                  type={showSmsTwilioToken ? "text" : "password"}
                                  required
                                  value={smsTwilioTokenInput}
                                  onChange={(e) => setSmsTwilioTokenInput(e.target.value)}
                                  placeholder={t('twilioTokenPlaceholder')}
                                  className="w-full h-9 pl-3 pr-10 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                                />
                                <button
                                  type="button"
                                  onClick={() => setShowSmsTwilioToken(!showSmsTwilioToken)}
                                  className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700 cursor-pointer focus:outline-none"
                                >
                                  {showSmsTwilioToken ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                </button>
                              </div>
                            </div>

                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('twilioPhone')}</label>
                              <input
                                type="text"
                                required
                                value={smsTwilioPhoneInput}
                                onChange={(e) => setSmsTwilioPhoneInput(e.target.value)}
                                placeholder={t('twilioPhonePlaceholder')}
                                className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                              />
                            </div>
                          </>
                        )}

                        {smsProvider === 'vonage' && (
                          <>
                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('apiKey')}</label>
                              <input
                                type="text"
                                required
                                value={smsVonageKeyInput}
                                onChange={(e) => setSmsVonageKeyInput(e.target.value)}
                                placeholder={t('vonageKeyPlaceholder')}
                                className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                              />
                            </div>

                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('apiSecret')}</label>
                              <div className="relative">
                                <input
                                  type={showSmsVonageSecret ? "text" : "password"}
                                  required
                                  value={smsVonageSecretInput}
                                  onChange={(e) => setSmsVonageSecretInput(e.target.value)}
                                  placeholder={t('vonageSecretPlaceholder')}
                                  className="w-full h-9 pl-3 pr-10 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                                />
                                <button
                                  type="button"
                                  onClick={() => setShowSmsVonageSecret(!showSmsVonageSecret)}
                                  className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700 cursor-pointer focus:outline-none"
                                >
                                  {showSmsVonageSecret ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                </button>
                              </div>
                            </div>

                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('senderIdOrPhone') || 'Sender ID / Phone'}</label>
                              <input
                                type="text"
                                required
                                value={smsVonagePhoneInput}
                                onChange={(e) => setSmsVonagePhoneInput(e.target.value)}
                                placeholder={t('vonageSmsPlaceholder')}
                                className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                              />
                            </div>
                          </>
                        )}

                        {smsProvider === 'plivo' && (
                          <>
                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('authId')}</label>
                              <input
                                type="text"
                                required
                                value={smsPlivoAuthIdInput}
                                onChange={(e) => setSmsPlivoAuthIdInput(e.target.value)}
                                placeholder={t('plivoAuthIdPlaceholder')}
                                className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                              />
                            </div>

                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('authToken')}</label>
                              <div className="relative">
                                <input
                                  type={showSmsPlivoToken ? "text" : "password"}
                                  required
                                  value={smsPlivoAuthTokenInput}
                                  onChange={(e) => setSmsPlivoAuthTokenInput(e.target.value)}
                                  placeholder={t('plivoAuthTokenPlaceholder')}
                                  className="w-full h-9 pl-3 pr-10 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                                />
                                <button
                                  type="button"
                                  onClick={() => setShowSmsPlivoToken(!showSmsPlivoToken)}
                                  className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700 cursor-pointer focus:outline-none"
                                >
                                  {showSmsPlivoToken ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                </button>
                              </div>
                            </div>

                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('plivoPhone')}</label>
                              <input
                                type="text"
                                required
                                value={smsPlivoPhoneInput}
                                onChange={(e) => setSmsPlivoPhoneInput(e.target.value)}
                                placeholder={t('plivoPhonePlaceholder')}
                                className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                              />
                            </div>
                          </>
                        )}

                        {smsProvider === 'messagebird' && (
                          <>
                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('apiKey')}</label>
                              <div className="relative">
                                <input
                                  type={showSmsMessagebirdKey ? "text" : "password"}
                                  required
                                  value={smsMessagebirdKeyInput}
                                  onChange={(e) => setSmsMessagebirdKeyInput(e.target.value)}
                                  placeholder={t('messagebirdKeyPlaceholder')}
                                  className="w-full h-9 pl-3 pr-10 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                                />
                                <button
                                  type="button"
                                  onClick={() => setShowSmsMessagebirdKey(!showSmsMessagebirdKey)}
                                  className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700 cursor-pointer focus:outline-none"
                                >
                                  {showSmsMessagebirdKey ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                </button>
                              </div>
                            </div>

                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('senderIdOrPhone') || 'Sender ID / Phone'}</label>
                              <input
                                type="text"
                                required
                                value={smsMessagebirdPhoneInput}
                                onChange={(e) => setSmsMessagebirdPhoneInput(e.target.value)}
                                placeholder={t('messagebirdSmsPlaceholder')}
                                className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                              />
                            </div>
                          </>
                        )}

                        {smsProvider === 'sinch' && (
                          <>
                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('servicePlanId')}</label>
                              <input
                                type="text"
                                required
                                value={smsSinchPlanIdInput}
                                onChange={(e) => setSmsSinchPlanIdInput(e.target.value)}
                                placeholder={t('sinchPlanIdPlaceholder')}
                                className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                              />
                            </div>

                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('apiToken')}</label>
                              <div className="relative">
                                <input
                                  type={showSmsSinchToken ? "text" : "password"}
                                  required
                                  value={smsSinchTokenInput}
                                  onChange={(e) => setSmsSinchTokenInput(e.target.value)}
                                  placeholder={t('sinchTokenPlaceholder')}
                                  className="w-full h-9 pl-3 pr-10 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                                />
                                <button
                                  type="button"
                                  onClick={() => setShowSmsSinchToken(!showSmsSinchToken)}
                                  className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700 cursor-pointer focus:outline-none"
                                >
                                  {showSmsSinchToken ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                </button>
                              </div>
                            </div>

                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('sinchPhone')}</label>
                              <input
                                type="text"
                                required
                                value={smsSinchPhoneInput}
                                onChange={(e) => setSmsSinchPhoneInput(e.target.value)}
                                placeholder={t('sinchPhonePlaceholder')}
                                className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                              />
                            </div>
                          </>
                        )}

                        {smsProvider === 'telnyx' && (
                          <>
                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('apiKey')}</label>
                              <div className="relative">
                                <input
                                  type={showSmsTelnyxKey ? "text" : "password"}
                                  required
                                  value={smsTelnyxKeyInput}
                                  onChange={(e) => setSmsTelnyxKeyInput(e.target.value)}
                                  placeholder={t('telnyxKeyPlaceholder')}
                                  className="w-full h-9 pl-3 pr-10 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                                />
                                <button
                                  type="button"
                                  onClick={() => setShowSmsTelnyxKey(!showSmsTelnyxKey)}
                                  className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700 cursor-pointer focus:outline-none"
                                >
                                  {showSmsTelnyxKey ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                </button>
                              </div>
                            </div>

                            <div className="space-y-1.5">
                              <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block font-sans">{t('telnyxPhone')}</label>
                              <input
                                type="text"
                                required
                                value={smsTelnyxPhoneInput}
                                onChange={(e) => setSmsTelnyxPhoneInput(e.target.value)}
                                placeholder={t('telnyxPhonePlaceholder')}
                                className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                              />
                            </div>
                          </>
                        )}
                      </div>

                      <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-between select-none font-bold">
                        <div>
                          {editingConnectionId && (
                            <button
                              type="button"
                              onClick={() => {
                                const target = activeChannels.find(c => c.id === editingConnectionId);
                                if (target) {
                                  setDisconnectTarget({
                                    id: target.id,
                                    slug: target.slug,
                                    name: target.name
                                  });
                                  setSelectedChannelSlug(null);
                                  setEditingConnectionId(null);
                                }
                              }}
                              className="flex items-center gap-1.5 px-3 py-1.5 text-red-650 hover:text-red-750 font-semibold text-[10px] uppercase tracking-wider hover:bg-red-50 rounded transition-all cursor-pointer border border-transparent hover:border-red-100"
                            >
                              <Trash2 className="h-3.5 w-3.5" />
                              <span>{t('disconnect') || 'Disconnect'}</span>
                            </button>
                          )}
                        </div>
                        <div className="flex items-center gap-3">
                          {smsConnected ? (
                            <button
                              type="button"
                              onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }}
                              className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                            >
                              {tCommon('cancel') || 'Cancel'}
                            </button>
                          ) : (
                            <button
                              type="button"
                              onClick={() => setSmsStep(1)}
                              className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                            >
                              {tCommon('back') || 'Back'}
                            </button>
                          )}
                          <button
                            type="submit"
                            disabled={smsConnecting}
                            className="h-9 px-4 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer flex items-center justify-center min-w-[100px] gap-1.5 disabled:opacity-50"
                          >
                            {smsConnecting ? <RefreshCw className="h-3.5 w-3.5 animate-spin" /> : editingConnectionId ? (tCommon('updateConnection') || 'Update Connection') : (tCommon('connect') || 'Connect')}
                          </button>
                        </div>
                      </div>
                    </>
                  )}
                </form>
              )}

              {/* Email Dialog */}
              {selectedChannelSlug === 'email' && (
                <form onSubmit={handleConnectEmail} className="space-y-4 font-sans select-none">
                    {emailStep === 1 ? (
                      <>
                        <div className="space-y-3.5">
                          <div className="space-y-1.5">
                            <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('connectionName')}</label>
                            <input
                              type="text"
                              required
                              value={emailNameInput}
                              onChange={(e) => setEmailNameInput(e.target.value)}
                              placeholder={t('supportMailboxPlaceholder')}
                              className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                            />
                          </div>
                        </div>

                        <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none font-bold">
                          <button
                            type="button"
                            onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }}
                            className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                          >
                            {tCommon('cancel') || 'Cancel'}
                          </button>
                          <button
                            type="button"
                            onClick={() => {
                              if (!emailNameInput.trim()) {
                                toast.error(t('toastEmailNameRequired'));
                                return;
                              }
                              setEmailStep(2);
                            }}
                            className="h-9 px-4 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer flex items-center justify-center min-w-[100px]"
                          >
                            {t('nextStep')}
                          </button>
                        </div>
                      </>
                    ) : (
                      <>
                        {emailConnected && emailDetails?.webhook_url && (
                          <div className="space-y-1.5 mb-4 text-xs font-medium text-zinc-700 w-full col-span-2">
                            <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('inboundParserWebhook') || 'Inbound Parser Webhook'}</label>
                            <p className="text-[11px] text-zinc-500 font-medium leading-relaxed">
                              {t('inboundParserDesc') || 'To receive emails automatically via Inbound Parse, configure your MX records to point to our parser domain and register this webhook target:'}
                            </p>
                            <div className="flex items-center gap-2 mt-1 max-w-xl">
                              <input 
                                type="text" 
                                readOnly 
                                value={emailDetails.webhook_url} 
                                className="flex-1 h-8 px-2.5 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md font-mono text-[11px] select-all outline-none text-zinc-900"
                              />
                              <button 
                                type="button" 
                                onClick={() => {
                                  navigator.clipboard.writeText(emailDetails.webhook_url || '');
                                  toast.success(t('toastInboundParseWebhookCopied') || 'Inbound Parse webhook URL copied!');
                                }}
                                className="h-8 px-2.5 bg-white border border-[#E8E8E6] hover:bg-zinc-50 hover:text-black rounded-md transition-all cursor-pointer shadow-3xs flex items-center justify-center text-zinc-600 shrink-0"
                              >
                                <Copy className="h-3.5 w-3.5" />
                              </button>
                            </div>
                          </div>
                        )}

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-5 pb-2">
                          {emailProvider === 'smtp' && (
                            <>
                              {/* SMTP CONFIG */}
                              <div className="space-y-3 border border-[#E8E8E6] rounded-lg p-3.5 bg-zinc-50/40">
                                <span className="text-[10px] font-bold text-zinc-900 uppercase tracking-wider block pb-1 border-b border-[#E8E8E6]">{t('smtpSettings')}</span>
                                <div className="space-y-2.5">
                                  <div className="space-y-1">
                                    <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('emailAddress')}</label>
                                    <input
                                      type="email"
                                      required
                                      value={emailAddressInput}
                                      onChange={(e) => setEmailAddressInput(e.target.value)}
                                      placeholder="support@domain.com"
                                      className="w-full h-8 px-2.5 bg-white border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                                    />
                                  </div>

                                  <div className="space-y-1">
                                    <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('smtpHost')}</label>
                                    <input
                                      type="text"
                                      required
                                      value={emailSmtpHostInput}
                                      onChange={(e) => setEmailSmtpHostInput(e.target.value)}
                                      placeholder="smtp.mailgun.org"
                                      className="w-full h-8 px-2.5 bg-white border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                                    />
                                  </div>

                                  <div className="grid grid-cols-3 gap-2">
                                    <div className="space-y-1 col-span-1">
                                      <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('smtpPort')}</label>
                                      <input
                                        type="text"
                                        required
                                        value={emailSmtpPortInput}
                                        onChange={(e) => setEmailSmtpPortInput(e.target.value)}
                                        placeholder="587"
                                        className="w-full h-8 px-2.5 bg-white border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                                      />
                                    </div>
                                    <div className="space-y-1 col-span-2">
                                      <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('encryption')}</label>
                                      <DropdownSelect
                                        options={[
                                          { value: 'none', label: 'NONE' },
                                          { value: 'ssl', label: 'SSL' },
                                          { value: 'tls', label: 'TLS' },
                                        ]}
                                        value={emailSmtpEncInput}
                                        onChange={(val) => setEmailSmtpEncInput(val as any)}
                                        placeholder="Encryption"
                                      />
                                    </div>
                                  </div>

                                  <div className="space-y-1">
                                    <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('smtpUsername')}</label>
                                    <input
                                      type="text"
                                      required
                                      value={emailSmtpUserInput}
                                      onChange={(e) => setEmailSmtpUserInput(e.target.value)}
                                      placeholder="Username"
                                      className="w-full h-8 px-2.5 bg-white border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                                    />
                                  </div>

                                  <div className="space-y-1">
                                    <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('smtpPassword')}</label>
                                    <div className="relative">
                                      <input
                                        type={showEmailSmtpPass ? "text" : "password"}
                                        required
                                        value={emailSmtpPassInput}
                                        onChange={(e) => setEmailSmtpPassInput(e.target.value)}
                                        placeholder="SMTP Password"
                                        className="w-full h-8 pl-2.5 pr-8 bg-white border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                                      />
                                      <button
                                        type="button"
                                        onClick={() => setShowEmailSmtpPass(!showEmailSmtpPass)}
                                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700 cursor-pointer focus:outline-none"
                                      >
                                        {showEmailSmtpPass ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
                                      </button>
                                    </div>
                                  </div>
                                </div>
                              </div>

                              {/* IMAP CONFIG */}
                              <div className="space-y-3 border border-[#E8E8E6] rounded-lg p-3.5 bg-zinc-50/40">
                                <span className="text-[10px] font-bold text-zinc-900 uppercase tracking-wider block pb-1 border-b border-[#E8E8E6]">{t('imapSettings')}</span>
                                <div className="space-y-2.5">
                                  <div className="space-y-1">
                                    <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('imapHost')}</label>
                                    <input
                                      type="text"
                                      required
                                      value={emailImapHostInput}
                                      onChange={(e) => setEmailImapHostInput(e.target.value)}
                                      placeholder="imap.gmail.com"
                                      className="w-full h-8 px-2.5 bg-white border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                                    />
                                  </div>

                                  <div className="grid grid-cols-3 gap-2">
                                    <div className="space-y-1 col-span-1">
                                      <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('imapPort')}</label>
                                      <input
                                        type="text"
                                        required
                                        value={emailImapPortInput}
                                        onChange={(e) => setEmailImapPortInput(e.target.value)}
                                        placeholder="993"
                                        className="w-full h-8 px-2.5 bg-white border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                                      />
                                    </div>
                                    <div className="space-y-1 col-span-2">
                                      <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('encryption')}</label>
                                      <DropdownSelect
                                        options={[
                                          { value: 'none', label: 'NONE' },
                                          { value: 'ssl', label: 'SSL' },
                                          { value: 'tls', label: 'TLS' },
                                        ]}
                                        value={emailImapEncInput}
                                        onChange={(val) => setEmailImapEncInput(val as any)}
                                        placeholder="Encryption"
                                      />
                                    </div>
                                  </div>

                                  <div className="space-y-1">
                                    <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('imapUsername')}</label>
                                    <input
                                      type="text"
                                      required
                                      value={emailImapUserInput}
                                      onChange={(e) => setEmailImapUserInput(e.target.value)}
                                      placeholder="Username"
                                      className="w-full h-8 px-2.5 bg-white border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black"
                                    />
                                  </div>

                                  <div className="space-y-1">
                                    <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('imapPassword')}</label>
                                    <div className="relative">
                                      <input
                                        type={showEmailImapPass ? "text" : "password"}
                                        required
                                        value={emailImapPassInput}
                                        onChange={(e) => setEmailImapPassInput(e.target.value)}
                                        placeholder="IMAP Password"
                                        className="w-full h-8 pl-2.5 pr-8 bg-white border border-[#E8E8E6] focus:border-black rounded-md text-xs font-medium placeholder:text-zinc-400 outline-none transition-colors text-black font-mono"
                                      />
                                      <button
                                        type="button"
                                        onClick={() => setShowEmailImapPass(!showEmailImapPass)}
                                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-700 cursor-pointer focus:outline-none"
                                      >
                                        {showEmailImapPass ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
                                      </button>
                                    </div>
                                  </div>
                                </div>
                              </div>
                            </>
                          )}
                        </div>

                        <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-between select-none font-bold">
                          <div>
                            {editingConnectionId && (
                              <button
                                type="button"
                                onClick={() => {
                                  const target = activeChannels.find(c => c.id === editingConnectionId);
                                  if (target) {
                                    setDisconnectTarget({
                                      id: target.id,
                                      slug: target.slug,
                                      name: target.name
                                    });
                                    setSelectedChannelSlug(null);
                                    setEditingConnectionId(null);
                                  }
                                }}
                                className="flex items-center gap-1.5 px-3 py-1.5 text-red-650 hover:text-red-750 font-semibold text-[10px] uppercase tracking-wider hover:bg-red-50 rounded transition-all cursor-pointer border border-transparent hover:border-red-100"
                              >
                                <Trash2 className="h-3.5 w-3.5" />
                                <span>{t('disconnect') || 'Disconnect'}</span>
                              </button>
                            )}
                          </div>
                          <div className="flex items-center gap-3">
                            {emailConnected ? (
                              <button
                                type="button"
                                onClick={() => { setSelectedChannelSlug(null); setEditingConnectionId(null); }}
                                className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                              >
                                {tCommon('cancel') || 'Cancel'}
                              </button>
                            ) : (
                              <button
                                type="button"
                                onClick={() => setEmailStep(1)}
                                className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                              >
                                {tCommon('back') || 'Back'}
                              </button>
                            )}
                            <button
                              type="submit"
                              disabled={emailConnecting}
                              className="h-9 px-4 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer flex items-center justify-center min-w-[100px] gap-1.5 disabled:opacity-50"
                            >
                              {emailConnecting ? <RefreshCw className="h-3.5 w-3.5 animate-spin" /> : editingConnectionId ? (tCommon('updateConnection') || 'Update Connection') : (tCommon('connect') || 'Connect')}
                            </button>
                          </div>
                        </div>
                      </>
                    )}
                  </form>
              )}

            </div>

          </div>
        </div>,
        document.body
      )}

      {/* 4. BAN-RISK ALERT DIALOG (MANDATORY REGULATORY DISCLAIMER) */}
      {showBaileysDisclaimer && mounted && typeof document !== 'undefined' && createPortal(
        <div 
          onClick={() => setShowBaileysDisclaimer(false)}
          className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 animate-overlay-fade select-none"
        >
          <div className="fixed inset-0" onClick={() => setShowBaileysDisclaimer(false)} />
          <div 
            onClick={(e) => e.stopPropagation()}
            className="bg-white border border-[#E8E8E6] rounded-xl max-w-md w-full p-6 shadow-xl relative z-50 animate-modal-box text-left select-none font-sans"
          >
            {/* Header Section (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6">
              <div className="flex items-center gap-2.5">
                <div className="h-7 w-7 rounded bg-red-50 border border-red-200 p-1 flex items-center justify-center shrink-0">
                  <AlertTriangle className="h-4 w-4 text-red-500" />
                </div>
                <div>
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">{t('banRiskDisclaimerTitle')}</h3>
                </div>
              </div>
              <button 
                type="button" 
                onClick={() => setShowBaileysDisclaimer(false)}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none shrink-0"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            <div className="space-y-4 pt-4">
              <p className="text-xs text-[#6B6B6B] leading-relaxed font-semibold">
                {t('banRiskDisclaimerDesc')}
              </p>
            </div>

            <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none">
              <button
                type="button"
                onClick={() => setShowBaileysDisclaimer(false)}
                className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
              >
                {tCommon('cancel') || 'Cancel'}
              </button>
              <button
                type="button"
                onClick={() => {
                  setShowBaileysDisclaimer(false);
                  handleSelectChannel('whatsapp_baileys', true);
                }}
                className="bg-red-650 hover:bg-red-700 text-white rounded-[6px] text-xs font-bold shadow-3xs transition-all cursor-pointer h-9 px-4 flex items-center justify-center"
              >
                {t('acknowledgeAndLink')}
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* Disconnect Confirmation Alert Dialog */}
      {disconnectTarget && mounted && typeof document !== 'undefined' && createPortal(
        <div 
          onClick={() => setDisconnectTarget(null)}
          className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 animate-overlay-fade select-none"
        >
          <div className="fixed inset-0" onClick={() => setDisconnectTarget(null)} />
          <div 
            onClick={(e) => e.stopPropagation()}
            className="bg-white border border-[#E8E8E6] rounded-xl max-w-md w-full p-6 shadow-xl relative z-50 animate-modal-box text-left select-none font-sans"
          >
            {/* Header Section (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6">
              <div className="flex items-center gap-2.5">
                <div className="h-7 w-7 rounded bg-red-50 border border-red-200 p-1 flex items-center justify-center shrink-0">
                  <ShieldAlert className="h-4 w-4 text-red-600" />
                </div>
                <div>
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">{t('disconnectChannelTitle')}</h3>
                </div>
              </div>
              <button 
                type="button" 
                onClick={() => setDisconnectTarget(null)}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none shrink-0"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            <div className="space-y-4 pt-4">
              <p className="text-xs text-[#6B6B6B] leading-relaxed font-semibold">
                {disconnectTarget.name ? `Are you sure you want to disconnect "${disconnectTarget.name}"?` : t('disconnectChannelDesc')}
              </p>
            </div>

            <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none">
              <button
                type="button"
                onClick={() => setDisconnectTarget(null)}
                className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
              >
                {tCommon('cancel') || 'Cancel'}
              </button>
              <button
                type="button"
                onClick={async () => {
                  const target = disconnectTarget;
                  setDisconnectTarget(null);
                  if (target.slug === 'whatsapp_cloud') await handleDisconnectWhatsApp(target.id);
                  else if (target.slug === 'whatsapp_baileys') await handleDisconnectBaileys(target.id);
                  else if (target.slug === 'telegram') await handleDisconnectTelegram(target.id);
                  else if (target.slug === 'instagram') await handleDisconnectInstagram(target.id);
                  else if (target.slug === 'messenger') await handleDisconnectMessenger(target.id);
                  else if (target.slug === 'sms') await handleDisconnectSms(target.id);
                  else if (target.slug === 'email') await handleDisconnectEmail(target.id);
                }}
                className="bg-red-650 hover:bg-red-700 text-white rounded-[6px] text-xs font-bold shadow-3xs transition-all cursor-pointer h-9 px-4 flex items-center justify-center min-w-[120px]"
              >
                {t('confirmDisconnect')}
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* Plan Feature Gate Modal */}
      <PlanLockModal
        isOpen={lockModalOpen}
        onClose={() => setLockModalOpen(false)}
        title={lockedChannelInfo?.title}
        description={lockedChannelInfo?.desc}
        featureName={lockedChannelInfo?.name}
        planName={userPlan?.name}
      />

    </div>
  );
}
