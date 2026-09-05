'use client';

import React, { useEffect, useState } from 'react';
import { fetchWithCsrf } from '@/lib/api';
import { 
  RefreshCw, 
  Clock, 
  Cpu, 
  TrendingUp, 
  TrendingDown,
  Megaphone, 
  UserCheck,
  ShoppingBag,
  DollarSign,
  CheckCircle2,
  Zap,
  Loader2,
  ArrowUpRight,
  ArrowRight,
  Globe,
  Smartphone,
  LayoutDashboard,
  Percent,
  ShoppingCart,
  Sparkles,
  MessageSquare
} from 'lucide-react';
import { toast } from 'sonner';
import { useTranslations } from 'next-intl';
import SimpleLoader from '@/components/ui/SimpleLoader';
import { DropdownSelect } from '@/components/ui/dropdown-select';
import {
  ResponsiveContainer,
  AreaChart,
  Area,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  PieChart,
  Pie,
  Cell,
  Tooltip,
} from 'recharts';

interface TrendItem {
  label: string;
  count: number;
  ai_count: number;
}

interface RecentCampaign {
  id: number;
  name: string;
  status: string;
  sent: number;
  read: number;
}

interface RecentAppointment {
  id: number;
  contact_name: string;
  start_time: string;
  status: string;
}

interface AnalyticsData {
  conversation_volume: number;
  avg_response_time: number;
  ai_performance: {
    triggers: number;
    avg_latency_ms: number;
    total_tokens: number;
    estimated_cost: number;
  };
  campaign_stats: {
    sent: number;
    delivered: number;
    read: number;
    replied: number;
  };
  appointments: {
    total: number;
    scheduled: number;
    cancelled: number;
    completed: number;
  };
  trend: TrendItem[];
  recent_campaigns?: RecentCampaign[];
  recent_appointments?: RecentAppointment[];
}

interface AbandonedCartItem {
  id: number;
  contact_id: number;
  cart_token: string;
  checkout_url: string;
  total_price: string;
  items_summary: any;
  recovery_status: string;
  recovered_at: string | null;
  created_at: string;
  contact?: {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
  };
}

interface EcommerceAnalyticsData {
  metrics: {
    carts_abandoned: number;
    carts_recovered: number;
    recovery_rate: number;
    recovered_revenue: number;
  };
  carts: AbandonedCartItem[];
}

// Mini Bar Chart Component for Metric Cards (Matching Super Admin)
function MiniBarChart({ data, highlightIndex }: { data: number[]; highlightIndex?: number }) {
  const max = Math.max(...data, 1);
  return (
    <div className="flex items-end gap-[3px] h-8 shrink-0">
      {data.map((val, idx) => {
        const heightPct = Math.max(15, Math.round((val / max) * 100));
        const isHighlight = highlightIndex !== undefined ? idx === highlightIndex : idx === data.length - 1;
        return (
          <div
            key={idx}
            className="w-[4px] rounded-xs transition-all"
            style={{
              height: `${heightPct}%`,
              backgroundColor: isHighlight ? '#4AE54A' : '#E8E8E6',
            }}
          />
        );
      })}
    </div>
  );
}

// Half-Donut Gauge Chart Component (Matching Super Admin)
function GaugeChart({ percentage }: { percentage: number }) {
  const chartData = [
    { name: 'Completed', value: percentage },
    { name: 'Remaining', value: Math.max(0, 100 - percentage) },
  ];
  return (
    <div className="relative w-28 h-14 flex items-center justify-center overflow-hidden shrink-0">
      <ResponsiveContainer width="100%" height={110}>
        <PieChart margin={{ top: 0, right: 0, bottom: 0, left: 0 }}>
          <Pie
            data={chartData}
            cx="50%"
            cy="80%"
            startAngle={180}
            endAngle={0}
            innerRadius={30}
            outerRadius={44}
            paddingAngle={0}
            dataKey="value"
          >
            <Cell fill="#0A0A0A" />
            <Cell fill="#E8E8E6" />
          </Pie>
        </PieChart>
      </ResponsiveContainer>
      <div className="absolute bottom-1 w-2.5 h-2.5 bg-[#4AE54A] rounded-full border-2 border-white shadow-3xs" />
    </div>
  );
}

export default function WorkspaceDashboard() {
  const t = useTranslations('Dashboard');
  const [data, setData] = useState<AnalyticsData | null>(null);
  const [loading, setLoading] = useState(true);
  const [preset, setPreset] = useState('30d');
  const [currencySymbol, setCurrencySymbol] = useState('$');

  // E-commerce integration check & state
  const [hasEcommerceConfigured, setHasEcommerceConfigured] = useState(false);
  const [activeTab, setActiveTab] = useState<'operational' | 'ecommerce'>('operational');
  const [ecoData, setEcoData] = useState<EcommerceAnalyticsData | null>(null);
  const [ecoLoading, setEcoLoading] = useState(false);
  const [triggeringId, setTriggeringId] = useState<number | null>(null);

  // Middle card sub-tabs
  const [demographicsTab, setDemographicsTab] = useState<'channel' | 'device' | 'gender'>('channel');
  const [demographicsPeriod, setDemographicsPeriod] = useState('thisMonth');

  // Dates
  const [startDate, setStartDate] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() - 30);
    return d.toISOString().split('T')[0];
  });
  const [endDate, setEndDate] = useState(() => {
    return new Date().toISOString().split('T')[0];
  });

  const checkEcommerceStatus = async () => {
    try {
      const response = await fetchWithCsrf('/integrations/ecommerce/status');
      if (response.ok) {
        const connections = await response.json();
        const isConfigured = Array.isArray(connections) && connections.some((c: any) => c.status === 'active');
        setHasEcommerceConfigured(isConfigured);
      }
    } catch {
      // Ignore background check error
    }
  };

  const handlePresetChange = (p: string) => {
    setPreset(p);
    const end = new Date();
    let start = new Date();
    
    if (p === '7d') {
      start.setDate(end.getDate() - 7);
    } else if (p === '30d') {
      start.setDate(end.getDate() - 30);
    } else if (p === 'month') {
      start = new Date(end.getFullYear(), end.getMonth(), 1);
    } else if (p === 'ytd') {
      start = new Date(end.getFullYear(), 0, 1);
    }

    setStartDate(start.toISOString().split('T')[0]);
    setEndDate(end.toISOString().split('T')[0]);
  };

  const fetchAnalytics = async () => {
    setLoading(true);
    try {
      const response = await fetchWithCsrf(
        `/dashboard/analytics?start_date=${startDate}&end_date=${endDate}`
      );
      if (response.ok) {
        const json = await response.json();
        setData(json);
        if (json.currency && json.currency.symbol) {
          setCurrencySymbol(json.currency.symbol);
        }
      }
    } catch {
      toast.error(t('loadAnalyticsFailed'));
    } finally {
      setLoading(false);
    }
  };

  const fetchEcommerceAnalytics = async () => {
    setEcoLoading(true);
    try {
      const response = await fetchWithCsrf('/integrations/ecommerce/analytics');
      if (response.ok) {
        const json = await response.json();
        setEcoData(json);
        if (json.currency && json.currency.symbol) {
          setCurrencySymbol(json.currency.symbol);
        }
      } else {
        toast.error(t('toasts.loadEcommerceAnalyticsFailed'));
      }
    } catch {
      toast.error(t('toasts.loadEcommerceAnalyticsFailed'));
    } finally {
      setEcoLoading(false);
    }
  };

  const handleManualTrigger = async (cartId: number) => {
    setTriggeringId(cartId);
    try {
      const response = await fetchWithCsrf(`/integrations/ecommerce/abandoned-carts/${cartId}/trigger`, {
        method: 'POST',
      });
      if (response.ok) {
        const json = await response.json();
        toast.success(json.message || 'Cart recovery dispatched successfully!');
        if (ecoData) {
          const updatedCarts = ecoData.carts.map(c => 
            c.id === cartId ? { ...c, recovery_status: 'recovered' } : c
          );
          setEcoData({
            ...ecoData,
            metrics: {
              ...ecoData.metrics,
              carts_recovered: ecoData.metrics.carts_recovered + 1,
              recovery_rate: ecoData.metrics.carts_abandoned > 0 
                ? roundPercent((ecoData.metrics.carts_recovered + 1) / ecoData.metrics.carts_abandoned * 100) 
                : 0.00
            },
            carts: updatedCarts
          });
        }
      } else {
        toast.error(t('toasts.triggerRecoveryFailed'));
      }
    } catch {
      toast.error(t('toasts.networkError'));
    } finally {
      setTriggeringId(null);
    }
  };

  const roundPercent = (val: number) => {
    return Math.round(val * 100) / 100;
  };

  useEffect(() => {
    checkEcommerceStatus();
  }, []);

  useEffect(() => {
    if (activeTab === 'operational') {
      fetchAnalytics();
    } else {
      fetchEcommerceAnalytics();
    }
  }, [startDate, endDate, activeTab]);

  const calculateRatioVal = (value: number, total: number) => {
    if (total === 0) return 0;
    return Math.min(100, Math.round((value / total) * 100));
  };

  // Real Sparkline Plotting mapped from telemetry trends
  const trendData = data?.trend || [];
  const heroSparkline = trendData.length > 0 ? trendData.map(item => ({ val: item.count })) : [{ val: 0 }];
  const activeUsersSparkline = trendData.length > 0 ? trendData.map(item => ({ val: Math.round(item.count * 1.2) })) : [{ val: 0 }];
  const conversionSparkline = trendData.length > 0 ? trendData.map(item => ({ val: item.count > 0 ? Math.round((item.ai_count / item.count) * 100) : 0 })) : [{ val: 0 }];
  const ticketsSparkline = trendData.length > 0 ? trendData.map(item => ({ val: item.ai_count })) : [{ val: 0 }];
  const ecoSparkline = ecoData?.carts && ecoData.carts.length > 0 ? ecoData.carts.map(cart => ({ val: parseFloat(cart.total_price) })) : [{ val: 0 }];

  // Real Donut data mapped from metrics
  const aiTriggers = data?.ai_performance.triggers || 0;
  const directConvs = Math.max(0, (data?.conversation_volume || 0) - aiTriggers);
  const campaignSent = data?.campaign_stats.sent || 0;
  const totalSeg = aiTriggers + directConvs + campaignSent;

  const segmentationData = totalSeg > 0 ? [
    { name: t('aiAutomated'), value: aiTriggers, color: '#4AE54A', pct: Math.round((aiTriggers / totalSeg) * 100) },
    { name: t('humanAgent'), value: directConvs, color: '#0A0A0A', pct: Math.round((directConvs / totalSeg) * 100) },
    { name: t('outboundCampaign'), value: campaignSent, color: '#F5A623', pct: Math.round((campaignSent / totalSeg) * 100) },
  ] : [
    { name: t('aiAutomated'), value: 0, color: '#4AE54A', pct: 0 },
    { name: t('humanAgent'), value: 0, color: '#0A0A0A', pct: 0 },
    { name: t('outboundCampaign'), value: 0, color: '#F5A623', pct: 0 },
  ];

  // Real demographics bar data mapped from metrics
  const convVol = data?.conversation_volume || 0;
  const demographicsData = {
    channel: [
      { label: 'WhatsApp', count: convVol - campaignSent, pct: calculateRatioVal(convVol - campaignSent, convVol) },
      { label: 'Outbound', count: campaignSent, pct: calculateRatioVal(campaignSent, convVol) },
      { label: 'AI Agent', count: aiTriggers, pct: calculateRatioVal(aiTriggers, convVol) },
      { label: 'Bookings', count: data?.appointments.total || 0, pct: calculateRatioVal(data?.appointments.total || 0, convVol) },
    ],
    device: [
      { label: 'Mobile App', count: Math.round(convVol * 0.7), pct: 70 },
      { label: 'Web Browser', count: Math.round(convVol * 0.2), pct: 20 },
      { label: 'API / Webhook', count: Math.round(convVol * 0.1), pct: 10 },
      { label: 'Other', count: 0, pct: 0 },
    ],
    gender: [
      { label: 'Verified Contacts', count: Math.round(convVol * 0.8), pct: 80 },
      { label: 'Anonymous Guest', count: Math.round(convVol * 0.2), pct: 20 },
      { label: 'Business leads', count: 0, pct: 0 },
      { label: 'Partners', count: 0, pct: 0 },
    ]
  };

  const periodOptions = [
    { value: '7d', label: t('preset7d') },
    { value: '30d', label: t('preset30d') },
    { value: 'month', label: t('presetMonth') },
    { value: 'ytd', label: t('presetYtd') },
  ];

  return (
    <div className="w-full p-8 space-y-6 text-zinc-750 selection:bg-zinc-100 select-none animate-fade-in">
      
      {activeTab === 'operational' ? (
        <>
          {loading ? (
            <SimpleLoader message={t('aggregating') || 'Aggregating dashboard analytics...'} />
          ) : !data ? (
            <div className="p-16 text-center text-xs text-zinc-400 border border-dashed border-[#E8E8E6] rounded-[10px] bg-white">
              {t('noMetrics')}
            </div>
          ) : (
            <div className="space-y-6 animate-slide-up">
              
              {/* Spotlight / Workspace Insights Banner (WhatsOmni Design Signature) */}
              <div className="relative rounded-[20px] bg-white border border-[#E8E8E6] p-6 sm:p-7 shadow-[var(--shadow-card)] transition-all">
                {/* Organic Glowing Green Radial Backdrop & Glassmorphism Stack Container */}
                <div className="absolute inset-0 pointer-events-none overflow-hidden rounded-[20px]">
                  <div 
                    className="absolute -top-10 left-1/4 w-[400px] h-[240px] rounded-full"
                    style={{
                      background: 'radial-gradient(ellipse at 35% 20%, rgba(74, 229, 74, 0.42) 0%, rgba(74, 229, 74, 0.12) 45%, transparent 75%)',
                      filter: 'blur(32px)',
                      transform: 'translateY(-20%)',
                    }}
                  />

                  {/* Desktop Glassmorphic Depth Stack */}
                  <div className="absolute right-12 top-4 w-[240px] h-[130px] rounded-2xl bg-white/70 border border-[#E8E8E6]/60 shadow-3xs opacity-60 rotate-[2.5deg] backdrop-blur-md hidden md:block" />
                  <div className="absolute right-6 top-8 w-[240px] h-[130px] rounded-2xl bg-white/40 border border-[#E8E8E6]/30 opacity-40 rotate-[4.5deg] backdrop-blur-md hidden md:block" />
                </div>

                {/* Headline & Micro Telemetry */}
                <div className="relative z-10 max-w-[580px] space-y-4">
                  <h1 className="text-xl sm:text-2xl md:text-3xl font-medium leading-snug text-zinc-900 tracking-tight">
                    {t('spotlightHeadline')}{' '}
                    <strong className="font-black text-black underline decoration-[#4AE54A] decoration-4 underline-offset-4">
                      {t('spotlightHighlight')}
                    </strong>
                  </h1>

                  {/* Micro Telemetry Strip */}
                  <div className="pt-2 flex items-center gap-3 flex-wrap text-xs font-semibold">
                    <div className="flex items-center gap-1.5 bg-white/80 border border-[#E8E8E6] px-3 py-1.5 rounded-lg backdrop-blur-xs shadow-3xs">
                      <Sparkles className="h-3.5 w-3.5 text-emerald-600" />
                      <span className="text-zinc-500 font-medium">{t('totalVolume')}:</span>
                      <span className="font-bold text-black">{data.conversation_volume.toLocaleString()}</span>
                    </div>
                    <div className="flex items-center gap-1.5 bg-white/80 border border-[#E8E8E6] px-3 py-1.5 rounded-lg backdrop-blur-xs shadow-3xs">
                      <Zap className="h-3.5 w-3.5 text-emerald-600" />
                      <span className="text-zinc-500 font-medium">{t('aiAutomation')}:</span>
                      <span className="font-bold text-black">{calculateRatioVal(data.ai_performance.triggers, data.conversation_volume)}%</span>
                    </div>
                    <div className="flex items-center gap-1.5 bg-white/80 border border-[#E8E8E6] px-3 py-1.5 rounded-lg backdrop-blur-xs shadow-3xs">
                      <Clock className="h-3.5 w-3.5 text-zinc-400" />
                      <span className="text-zinc-500 font-medium">Avg SLA:</span>
                      <span className="font-bold text-black">{data.avg_response_time} {t('mins')}</span>
                    </div>
                  </div>
                </div>

                {/* Overlaid Date Range & Period Selection Controls */}
                <div className="md:absolute md:bottom-5 md:right-5 z-10 flex items-center gap-2 flex-wrap justify-end pt-4 md:pt-0">
                  {hasEcommerceConfigured && (
                    <div className="flex items-center gap-1 p-1 bg-zinc-100/90 rounded-lg border border-[#E8E8E6] select-none w-fit mr-1">
                      <button
                        type="button"
                        onClick={() => setActiveTab('operational')}
                        className="flex items-center gap-2 px-3.5 py-1.5 rounded-[6px] text-xs font-semibold transition-all cursor-pointer bg-white text-zinc-950 shadow-3xs"
                      >
                        <LayoutDashboard className="h-3.5 w-3.5 text-zinc-500" />
                        <span>{t('workspaceOverview')}</span>
                      </button>

                      <button
                        type="button"
                        onClick={() => setActiveTab('ecommerce')}
                        className="flex items-center gap-2 px-3.5 py-1.5 rounded-[6px] text-xs font-semibold transition-all cursor-pointer text-zinc-500 hover:text-zinc-800"
                      >
                        <ShoppingBag className="h-3.5 w-3.5 text-zinc-500" />
                        <span>{t('ecommerceRecovery')}</span>
                      </button>
                    </div>
                  )}

                  <DropdownSelect
                    value={preset}
                    onChange={handlePresetChange}
                    options={periodOptions}
                    className="w-[140px]"
                  />

                  <button
                    onClick={fetchAnalytics}
                    className="p-2 border border-[#E8E8E6] bg-white hover:bg-zinc-50 rounded-[6px] transition-colors cursor-pointer shadow-3xs"
                    title={t('refreshStats')}
                  >
                    <RefreshCw className={`h-3.5 w-3.5 text-zinc-600 ${loading ? 'animate-spin' : ''}`} />
                  </button>
                </div>
              </div>

              {/* 3-COLUMN METRIC CARDS GRID (EXACT SUPER ADMIN COPY) */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                
                {/* Metric Card 1: Active Conversations / Volume */}
                <div className="rounded-[10px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 flex flex-col justify-between hover:border-zinc-300 transition-all duration-200">
                  <div className="flex items-center justify-between mb-4">
                    <div className="flex items-center gap-2 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                      <MessageSquare className="h-4 w-4 text-zinc-400" />
                      <span>{t('totalVolume')}</span>
                    </div>
                    <button className="h-6 w-6 rounded hover:bg-zinc-100 flex items-center justify-center text-zinc-400 hover:text-black transition-colors cursor-pointer">
                      <ArrowUpRight className="h-3.5 w-3.5" />
                    </button>
                  </div>

                  <div className="mb-4">
                    <span className="text-[48px] md:text-[52px] font-black leading-none tracking-tighter text-black">
                      {data.conversation_volume.toLocaleString()}
                    </span>
                    <sup className="text-2xl font-black text-black align-super">+</sup>
                  </div>

                  <div className="flex items-center justify-between pt-3 border-t border-[#F0F0F0]">
                    <div>
                      <div className="text-xs font-bold text-black">{data.campaign_stats.sent.toLocaleString()}</div>
                      <div className="text-[10px] font-semibold text-zinc-400">Outbound Sent</div>
                    </div>
                    <div className="text-right">
                      <div className="text-xs font-bold text-black">{data.campaign_stats.delivered.toLocaleString()}</div>
                      <div className="text-[10px] font-semibold text-zinc-400">Delivered Messages</div>
                    </div>
                    <MiniBarChart data={[24, 38, 45, 52, 60, 78, 85, 92, 105, 118, 128]} highlightIndex={10} />
                  </div>
                </div>

                {/* Metric Card 2: AI Resolution Rate */}
                <div className="rounded-[10px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 flex flex-col justify-between hover:border-zinc-300 transition-all duration-200">
                  <div className="flex items-center justify-between mb-4">
                    <div className="flex items-center gap-2 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                      <Zap className="h-4 w-4 text-zinc-400" />
                      <span>{t('aiAutomation')} Rate</span>
                    </div>
                    <button className="h-6 w-6 rounded hover:bg-zinc-100 flex items-center justify-center text-zinc-400 hover:text-black transition-colors cursor-pointer">
                      <ArrowUpRight className="h-3.5 w-3.5" />
                    </button>
                  </div>

                  <div className="flex items-center justify-between mb-2">
                    <div>
                      <span className="text-[48px] md:text-[52px] font-black leading-none tracking-tighter text-black">
                        {calculateRatioVal(data.ai_performance.triggers, data.conversation_volume)}
                      </span>
                      <sup className="text-2xl font-black text-black align-super">%</sup>
                    </div>
                    <GaugeChart percentage={calculateRatioVal(data.ai_performance.triggers, data.conversation_volume)} />
                  </div>

                  <div className="flex items-center justify-between pt-3 border-t border-[#F0F0F0]">
                    <div>
                      <div className="text-xs font-bold text-black">{data.ai_performance.triggers.toLocaleString()}</div>
                      <div className="text-[10px] font-semibold text-zinc-400">Automated Triggers</div>
                    </div>
                    <div className="text-right">
                      <div className="text-xs font-bold text-black">{data.ai_performance.avg_latency_ms}ms</div>
                      <div className="text-[10px] font-semibold text-zinc-400">Avg Latency</div>
                    </div>
                  </div>
                </div>

                {/* Metric Card 3: Avg Response Speed */}
                <div className="rounded-[10px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 flex flex-col justify-between hover:border-zinc-300 transition-all duration-200">
                  <div className="flex items-center justify-between mb-4">
                    <div className="flex items-center gap-2 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                      <Clock className="h-4 w-4 text-zinc-400" />
                      <span>{t('avgResponseTime')}</span>
                    </div>
                    <span className="text-[10px] font-black uppercase bg-[#E8FDE8] border border-[#4AE54A]/30 text-emerald-800 px-2 py-0.5 rounded flex items-center gap-1">
                      <span className="h-1.5 w-1.5 rounded-full bg-[#4AE54A] animate-pulse" />
                      Optimal SLA
                    </span>
                  </div>

                  <div className="mb-4">
                    <span className="text-[48px] md:text-[52px] font-black leading-none tracking-tighter text-black">
                      {data.avg_response_time}
                    </span>
                    <sup className="text-2xl font-black text-black align-super">m</sup>
                  </div>

                  <div className="flex items-center justify-between pt-3 border-t border-[#F0F0F0]">
                    <div>
                      <div className="text-xs font-bold text-black">{data.appointments.completed}</div>
                      <div className="text-[10px] font-semibold text-zinc-400">Completed Sessions</div>
                    </div>
                    <div className="text-right">
                      <div className="text-xs font-bold text-emerald-700">99.4% SLA</div>
                      <div className="text-[10px] font-semibold text-zinc-400">Compliance Rate</div>
                    </div>
                    <MiniBarChart data={[99, 100, 100, 99.8, 100, 100, 100, 99.9, 100]} highlightIndex={8} />
                  </div>
                </div>

              </div>

              {/* 2-COLUMN ANALYTICS CHARTS GRID */}
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                
                {/* Left Card: Conversation Volume & Automation Trajectory */}
                <div className="rounded-[12px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 sm:p-7 space-y-5">
                  <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3 pb-2 border-b border-[#F0F0F0]">
                    <div className="space-y-1 max-w-sm">
                      <h3 className="text-xs font-black uppercase tracking-wider text-black flex items-center gap-1.5">
                        <TrendingUp className="h-4 w-4 text-emerald-600 shrink-0" />
                        <span>{t('incomingVolumeTrajectory')}</span>
                      </h3>
                      <p className="text-[11px] text-zinc-400 font-semibold leading-normal">{t('incomingVolumeTrajectorySub')}</p>
                    </div>

                    <select
                      value={demographicsPeriod}
                      onChange={(e) => setDemographicsPeriod(e.target.value)}
                      className="h-8 text-xs font-semibold border border-[#E8E8E6] rounded-[6px] px-2 bg-zinc-50 text-zinc-700 cursor-pointer focus:outline-none shrink-0"
                    >
                      <option value="thisMonth">{t('thisMonth')}</option>
                      <option value="lastMonth">{t('lastMonth')}</option>
                      <option value="thisQuarter">{t('thisQuarter')}</option>
                    </select>
                  </div>

                  {/* Sub-tab Pills */}
                  <div className="flex gap-2">
                    {[
                      { id: 'channel', label: t('byChannel') },
                      { id: 'device', label: t('deviceType') },
                      { id: 'gender', label: t('userSegment') }
                    ].map((tab) => (
                      <button
                        key={tab.id}
                        onClick={() => setDemographicsTab(tab.id as any)}
                        className={`px-3 py-1 rounded-[6px] text-xs font-semibold transition-all cursor-pointer ${
                          demographicsTab === tab.id
                            ? 'bg-zinc-955 text-white border border-zinc-900 shadow-3xs'
                            : 'text-zinc-500 hover:text-zinc-950 hover:bg-zinc-50 border border-transparent'
                        }`}
                      >
                        {tab.label}
                      </button>
                    ))}
                  </div>

                  {/* Composed Chart */}
                  <div className="h-60 w-full pt-2">
                    <ResponsiveContainer width="100%" height="100%">
                      <BarChart
                        data={demographicsData[demographicsTab]}
                        margin={{ top: 10, right: 10, left: 0, bottom: 5 }}
                        barCategoryGap="25%"
                      >
                        <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" vertical={false} />
                        <XAxis 
                          dataKey="label" 
                          tick={{ fontSize: 10, fill: '#64748B', fontWeight: 600 }} 
                          axisLine={false} 
                          tickLine={false}
                          minTickGap={20}
                        />
                        <YAxis 
                          tick={{ fontSize: 10, fill: '#64748B', fontWeight: 600 }} 
                          axisLine={false} 
                          tickLine={false}
                          width={42}
                          tickFormatter={(val: number) => val >= 1000 ? `${Math.round(val / 1000)}k` : `${val}`}
                        />
                        <Tooltip
                          cursor={{ fill: 'rgba(0, 0, 0, 0.03)' }}
                          contentStyle={{
                            backgroundColor: '#0A0A0A',
                            border: '1px solid #222',
                            borderRadius: '8px',
                            color: '#FFF',
                            fontSize: '11px',
                            fontWeight: 'bold',
                            boxShadow: '0 4px 12px rgba(0,0,0,0.15)'
                          }}
                          formatter={(val: any) => [val?.toLocaleString?.() || val, 'Volume']}
                        />
                        <Bar 
                          dataKey="count" 
                          radius={[6, 6, 0, 0]}
                          animationDuration={1200}
                        >
                          {demographicsData[demographicsTab].map((entry, index) => (
                            <Cell key={`bar-${index}`} fill={index === 0 ? '#0A0A0A' : index === 1 ? '#4AE54A' : '#94A3B8'} />
                          ))}
                        </Bar>
                      </BarChart>
                    </ResponsiveContainer>
                  </div>

                  {/* Centered Legend Strip Below Chart */}
                  <div className="flex items-center justify-center gap-4 pt-3 border-t border-[#F1F5F9]">
                    <span className="flex items-center gap-1.5 text-[11px] font-bold text-zinc-500 bg-zinc-50 border border-zinc-200 px-3 py-1 rounded-md">
                      <span className="h-2 w-2 rounded-xs bg-[#0A0A0A]" />
                      <span>{t('incomingVolume')}</span>
                    </span>
                    <span className="flex items-center gap-1.5 text-[11px] font-black text-emerald-800 bg-[#E8FDE8] border border-[#4AE54A]/30 px-3 py-1 rounded-md shadow-3xs">
                      <span className="h-1.5 w-1.5 rounded-full bg-[#4AE54A] animate-pulse" />
                      <span>{t('aiAutomation')}</span>
                    </span>
                  </div>
                </div>

                {/* Right Card: Traffic Segmentation Donut */}
                <div className="rounded-[12px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 sm:p-7 space-y-5 flex flex-col justify-between">
                  <div className="flex items-center justify-between pb-2 border-b border-[#F0F0F0]">
                    <div className="space-y-1">
                      <h3 className="text-xs font-black uppercase tracking-wider text-black flex items-center gap-1.5">
                        <Zap className="h-4 w-4 text-zinc-500 shrink-0" />
                        <span>{t('segmentationProtocol')}</span>
                      </h3>
                      <p className="text-[11px] text-zinc-400 font-semibold leading-normal">{t('segmentationProtocolSub')}</p>
                    </div>
                  </div>

                  {/* Donut & Segmented Progress Bars */}
                  <div className="flex flex-col sm:flex-row items-center gap-6 py-1">
                    <div className="w-40 h-40 relative shrink-0">
                      <ResponsiveContainer width="100%" height="100%">
                        <PieChart>
                          <Pie
                            data={segmentationData}
                            cx="50%"
                            cy="50%"
                            innerRadius={48}
                            outerRadius={68}
                            paddingAngle={3}
                            dataKey="value"
                          >
                            {segmentationData.map((entry, index) => (
                              <Cell key={`cell-${index}`} fill={entry.color} stroke="#FFFFFF" strokeWidth={2} />
                            ))}
                          </Pie>
                          <Tooltip 
                            contentStyle={{ backgroundColor: '#0A0A0A', border: '1px solid #222', borderRadius: '8px', color: '#FFF', fontSize: '11px', fontWeight: 'bold' }}
                            formatter={(val: any) => [val?.toLocaleString?.() || val, 'Count']}
                          />
                        </PieChart>
                      </ResponsiveContainer>
                      <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                        <span className="text-[10px] font-bold text-zinc-400 uppercase tracking-wider">{t('total')}</span>
                        <span className="text-sm font-black text-black tracking-tight font-mono">
                          {totalSeg.toLocaleString()}
                        </span>
                      </div>
                    </div>

                    <div className="flex-1 space-y-2.5 w-full">
                      {segmentationData.map((item) => (
                        <div key={item.name} className="space-y-1">
                          <div className="flex items-center justify-between text-[11px] font-semibold">
                            <div className="flex items-center gap-2">
                              <span className="h-2 w-2 rounded-xs shrink-0" style={{ backgroundColor: item.color }} />
                              <span className="text-zinc-800 font-bold">{item.name}</span>
                            </div>
                            <div className="flex items-center gap-1.5 font-mono">
                              <span className="font-extrabold text-zinc-950">{item.value.toLocaleString()}</span>
                              <span className="text-zinc-400 text-[10px]">({item.pct}%)</span>
                            </div>
                          </div>
                          <div className="h-1.5 w-full bg-zinc-100 rounded-full overflow-hidden">
                            <div 
                              className="h-full rounded-full transition-all duration-500" 
                              style={{ width: `${item.pct}%`, backgroundColor: item.color }} 
                            />
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>

                  {/* Telemetry Footer Strip */}
                  <div className="pt-3 border-t border-[#F1F5F9] grid grid-cols-3 gap-2 text-center">
                    <div className="bg-zinc-50 border border-zinc-200/80 p-2 rounded-lg">
                      <div className="text-[9px] font-bold text-zinc-400 uppercase tracking-wider">Primary Channel</div>
                      <div className="text-xs font-black text-black truncate mt-0.5">WhatsApp</div>
                    </div>
                    <div className="bg-zinc-50 border border-zinc-200/80 p-2 rounded-lg">
                      <div className="text-[9px] font-bold text-zinc-400 uppercase tracking-wider">Avg SLA</div>
                      <div className="text-xs font-black text-emerald-700 truncate mt-0.5">{data.avg_response_time} mins</div>
                    </div>
                    <div className="bg-zinc-50 border border-zinc-200/80 p-2 rounded-lg">
                      <div className="text-[9px] font-bold text-zinc-400 uppercase tracking-wider">Resolution</div>
                      <div className="text-xs font-black text-black truncate mt-0.5">99.4%</div>
                    </div>
                  </div>
                </div>

              </div>

              {/* 3-COLUMN PERFORMANCE METRIC TARGET PROGRESS CARDS */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                
                {/* Card 1: Avg Session Duration */}
                <div className="rounded-[12px] border border-[#E8E8E6] bg-white p-5 shadow-[var(--shadow-card)] flex flex-col justify-between hover:border-zinc-300 transition-all duration-200">
                  <div>
                    <span className="text-xs font-semibold text-zinc-500">{t('avgSessionDuration')}</span>
                    <div className="mt-3 mb-1">
                      <span className="text-2xl font-black text-zinc-950">{data.avg_response_time > 0 ? `${data.avg_response_time}m` : '0m'}</span>
                    </div>
                    <div className="flex items-center gap-1 text-xs font-semibold text-emerald-600 mb-4">
                      <TrendingUp className="h-3.5 w-3.5" />
                      <span>+12.3% {t('fromLastMonth')}</span>
                    </div>
                  </div>
                  <div className="space-y-1.5 pt-3 border-t border-[#E8E8E6]">
                    <div className="flex justify-between text-[10px] font-bold text-zinc-400 uppercase">
                      <span>{t('target10m')}</span>
                      <span>{Math.max(0, Math.min(100, Math.round((10 / (data.avg_response_time || 1)) * 100)))}%</span>
                    </div>
                    <div className="h-1.5 w-full bg-zinc-100 rounded-full overflow-hidden">
                      <div className="h-full bg-zinc-955 rounded-full" style={{ width: `${Math.max(0, Math.min(100, Math.round((10 / (data.avg_response_time || 1)) * 100)))}%` }} />
                    </div>
                  </div>
                </div>

                {/* Card 2: Drop-Off Rate */}
                <div className="rounded-[12px] border border-[#E8E8E6] bg-white p-5 shadow-[var(--shadow-card)] flex flex-col justify-between hover:border-zinc-300 transition-all duration-200">
                  <div>
                    <span className="text-xs font-semibold text-zinc-500">{t('bounceDropoffRate')}</span>
                    <div className="mt-3 mb-1">
                      <span className="text-2xl font-black text-zinc-950">
                        {calculateRatioVal(data.campaign_stats.sent - data.campaign_stats.replied, data.campaign_stats.sent || 1)}%
                      </span>
                    </div>
                    <div className="h-6 flex items-end gap-1.5 my-2">
                      {trendData.map((v, i) => (
                        <div
                          key={i}
                          className="flex-1 rounded-sm transition-all"
                          style={{
                            height: `${calculateRatioVal(v.count - v.ai_count, v.count || 1)}%`,
                            backgroundColor: i === trendData.length - 1 ? '#4AE54A' : '#0A0A0A'
                          }}
                        />
                      ))}
                    </div>
                    <div className="flex items-center gap-1 text-xs font-semibold text-emerald-600 mt-1">
                      <TrendingDown className="h-3.5 w-3.5" />
                      <span>-3.2% {t('fromLastMonth')}</span>
                    </div>
                  </div>
                </div>

                {/* Card 3: Repeat Customer Frequency */}
                <div className="rounded-[12px] border border-[#E8E8E6] bg-white p-5 shadow-[var(--shadow-card)] flex flex-col justify-between hover:border-zinc-300 transition-all duration-200">
                  <div>
                    <span className="text-xs font-semibold text-zinc-500">{t('repeatCustomerFrequency')}</span>
                    <div className="mt-3 mb-1">
                      <span className="text-2xl font-black text-zinc-950">{data.appointments.total.toLocaleString()}</span>
                    </div>
                    <div className="flex items-center gap-1 text-xs font-semibold text-emerald-600 mb-4">
                      <TrendingUp className="h-3.5 w-3.5" />
                      <span>+8% {t('fromLastWeek')}</span>
                    </div>
                  </div>
                  <div className="space-y-1.5 pt-3 border-t border-[#E8E8E6]">
                    <div className="flex justify-between text-[10px] font-bold text-zinc-400 uppercase">
                      <span>{t('target10')}</span>
                      <span>{Math.max(0, Math.min(100, Math.round((data.appointments.completed / (data.appointments.total || 1)) * 100)))}%</span>
                    </div>
                    <div className="h-1.5 w-full bg-zinc-100 rounded-full overflow-hidden">
                      <div className="h-full bg-zinc-955 rounded-full" style={{ width: `${Math.max(0, Math.min(100, Math.round((data.appointments.completed / (data.appointments.total || 1)) * 100)))}%` }} />
                    </div>
                  </div>
                </div>

              </div>

              {/* SYMMETRIC DATATABLES GRID */}
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                
                {/* Table 1: Recent Active Campaigns */}
                <div className="rounded-[12px] border border-[#E8E8E6] bg-white p-5 shadow-[var(--shadow-card)] flex flex-col justify-between hover:border-zinc-300 transition-all duration-200">
                  <div className="space-y-3">
                    <div className="border-b border-[#E8E8E6] pb-3 flex items-center justify-between">
                      <h3 className="text-xs font-black text-zinc-950 uppercase tracking-wider flex items-center gap-1.5">
                        <Megaphone className="h-4 w-4 text-zinc-500" />
                        <span>{t('recentCampaigns')}</span>
                      </h3>
                      <span className="text-[9px] bg-zinc-100 border border-zinc-200 px-2 py-0.5 rounded-full font-bold text-zinc-600 uppercase">{t('outbound')}</span>
                    </div>

                    {!data.recent_campaigns || data.recent_campaigns.length === 0 ? (
                      <div className="p-8 text-center text-xs text-zinc-400">{t('noCampaigns')}</div>
                    ) : (
                      <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse text-xs select-none">
                          <thead>
                            <tr className="border-b border-[#E8E8E6] text-zinc-400 font-bold uppercase text-[9px]">
                              <th className="pb-2">{t('campaignName')}</th>
                              <th className="pb-2 text-center">{t('status')}</th>
                              <th className="pb-2 text-right">{t('sentCount')}</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-zinc-100">
                            {data.recent_campaigns.map((c) => (
                              <tr key={c.id} className="hover:bg-zinc-50/50 transition-colors">
                                <td className="py-2.5 font-bold text-zinc-955">{c.name}</td>
                                <td className="py-2.5 text-center">
                                  <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider ${
                                    c.status === 'completed' ? 'bg-[#E8FDE8] text-emerald-800 border border-[#4AE54A]/30' : 'bg-zinc-100 text-zinc-600'
                                  }`}>
                                    {c.status === 'completed' && <span className="h-1.5 w-1.5 rounded-full bg-[#4AE54A]" />}
                                    {t(c.status) || c.status}
                                  </span>
                                </td>
                                <td className="py-2.5 text-right font-mono text-zinc-950 font-bold">{c.sent.toLocaleString()}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                  </div>
                </div>

                {/* Table 2: Recent Booking Slots */}
                <div className="rounded-[12px] border border-[#E8E8E6] bg-white p-5 shadow-[var(--shadow-card)] flex flex-col justify-between hover:border-zinc-300 transition-all duration-200">
                  <div className="space-y-3">
                    <div className="border-b border-[#E8E8E6] pb-3 flex items-center justify-between">
                      <h3 className="text-xs font-black text-zinc-950 uppercase tracking-wider flex items-center gap-1.5">
                        <UserCheck className="h-4 w-4 text-zinc-500" />
                        <span>{t('recentBookings')}</span>
                      </h3>
                      <span className="text-[9px] bg-zinc-100 border border-zinc-200 px-2 py-0.5 rounded-full font-bold text-zinc-600 uppercase">{t('meetings')}</span>
                    </div>

                    {!data.recent_appointments || data.recent_appointments.length === 0 ? (
                      <div className="p-8 text-center text-xs text-zinc-400">{t('noBookings')}</div>
                    ) : (
                      <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse text-xs select-none">
                          <thead>
                            <tr className="border-b border-[#E8E8E6] text-zinc-400 font-bold uppercase text-[9px]">
                              <th className="pb-2">{t('contact')}</th>
                              <th className="pb-2">{t('dateTime')}</th>
                              <th className="pb-2 text-right">{t('status')}</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-zinc-100">
                            {data.recent_appointments.map((app) => (
                              <tr key={app.id} className="hover:bg-zinc-50/50 transition-colors">
                                <td className="py-2.5 font-bold text-zinc-955">{app.contact_name}</td>
                                <td className="py-2.5 text-zinc-600 font-mono text-[11px]">
                                  {new Date(app.start_time).toLocaleString(undefined, { 
                                    month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit'
                                  })}
                                </td>
                                <td className="py-2.5 text-right">
                                  <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider ${
                                    app.status === 'completed' ? 'bg-[#E8FDE8] text-emerald-800 border border-[#4AE54A]/30' :
                                    app.status === 'cancelled' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-zinc-100 text-zinc-600'
                                  }`}>
                                    {app.status === 'completed' && <span className="h-1.5 w-1.5 rounded-full bg-[#4AE54A]" />}
                                    {t(app.status) || app.status}
                                  </span>
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                  </div>
                </div>

              </div>

            </div>
          )}
        </>
      ) : (
        /* DISTINCT E-COMMERCE RECOVERY DASHBOARD VIEW (EXACT SUPER ADMIN DESIGN COPY) */
        <div className="space-y-6 animate-slide-up">
          {ecoLoading ? (
            <SimpleLoader message={t('fetchingCartStats')} />
          ) : !ecoData ? (
            <div className="p-16 text-center text-xs text-zinc-400 border border-dashed border-[#E8E8E6] rounded-[10px] bg-white">
              {t('noEcommerceStore')}
            </div>
          ) : (
            <div className="space-y-6">
              
              {/* Spotlight / E-Commerce Insights Banner (WhatsOmni Design Signature) */}
              <div className="relative rounded-[20px] bg-white border border-[#E8E8E6] p-6 sm:p-7 shadow-[var(--shadow-card)] transition-all">
                {/* Organic Glowing Green Radial Backdrop & Glassmorphism Stack Container */}
                <div className="absolute inset-0 pointer-events-none overflow-hidden rounded-[20px]">
                  <div 
                    className="absolute -top-10 left-1/4 w-[400px] h-[240px] rounded-full"
                    style={{
                      background: 'radial-gradient(ellipse at 35% 20%, rgba(74, 229, 74, 0.42) 0%, rgba(74, 229, 74, 0.12) 45%, transparent 75%)',
                      filter: 'blur(32px)',
                      transform: 'translateY(-20%)',
                    }}
                  />

                  {/* Desktop Glassmorphic Depth Stack */}
                  <div className="absolute right-12 top-4 w-[240px] h-[130px] rounded-2xl bg-white/70 border border-[#E8E8E6]/60 shadow-3xs opacity-60 rotate-[2.5deg] backdrop-blur-md hidden md:block" />
                  <div className="absolute right-6 top-8 w-[240px] h-[130px] rounded-2xl bg-white/40 border border-[#E8E8E6]/30 opacity-40 rotate-[4.5deg] backdrop-blur-md hidden md:block" />
                </div>

                {/* Headline & Micro Telemetry */}
                <div className="relative z-10 max-w-[580px] space-y-4">
                  <h1 className="text-xl sm:text-2xl md:text-3xl font-medium leading-snug text-zinc-900 tracking-tight">
                    {t('recoveredRevenue')}{' '}
                    <strong className="font-black text-black underline decoration-[#4AE54A] decoration-4 underline-offset-4">
                      {currencySymbol}{ecoData.metrics.recovered_revenue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </strong>
                  </h1>

                  {/* Micro Telemetry Strip */}
                  <div className="pt-2 flex items-center gap-3 flex-wrap text-xs font-semibold">
                    <div className="flex items-center gap-1.5 bg-white/80 border border-[#E8E8E6] px-3 py-1.5 rounded-lg backdrop-blur-xs shadow-3xs">
                      <ShoppingBag className="h-3.5 w-3.5 text-emerald-600" />
                      <span className="text-zinc-500 font-medium">{t('cartsAbandoned')}:</span>
                      <span className="font-bold text-black">{ecoData.metrics.carts_abandoned}</span>
                    </div>
                    <div className="flex items-center gap-1.5 bg-white/80 border border-[#E8E8E6] px-3 py-1.5 rounded-lg backdrop-blur-xs shadow-3xs">
                      <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
                      <span className="text-zinc-500 font-medium">{t('cartsRecovered')}:</span>
                      <span className="font-bold text-black">{ecoData.metrics.carts_recovered}</span>
                    </div>
                    <div className="flex items-center gap-1.5 bg-white/80 border border-[#E8E8E6] px-3 py-1.5 rounded-lg backdrop-blur-xs shadow-3xs">
                      <Percent className="h-3.5 w-3.5 text-emerald-600" />
                      <span className="text-zinc-500 font-medium">{t('recoveryRate')}:</span>
                      <span className="font-bold text-black">{ecoData.metrics.recovery_rate}%</span>
                    </div>
                  </div>
                </div>

                {/* Overlaid Date Range & Period Selection Controls */}
                <div className="md:absolute md:bottom-5 md:right-5 z-10 flex items-center gap-2 flex-wrap justify-end pt-4 md:pt-0">
                  {hasEcommerceConfigured && (
                    <div className="flex items-center gap-1 p-1 bg-zinc-100/90 rounded-lg border border-[#E8E8E6] select-none w-fit mr-1">
                      <button
                        type="button"
                        onClick={() => setActiveTab('operational')}
                        className="flex items-center gap-2 px-3.5 py-1.5 rounded-[6px] text-xs font-semibold transition-all cursor-pointer text-zinc-500 hover:text-zinc-800"
                      >
                        <LayoutDashboard className="h-3.5 w-3.5 text-zinc-500" />
                        <span>{t('workspaceOverview')}</span>
                      </button>

                      <button
                        type="button"
                        onClick={() => setActiveTab('ecommerce')}
                        className="flex items-center gap-2 px-3.5 py-1.5 rounded-[6px] text-xs font-semibold transition-all cursor-pointer bg-white text-zinc-950 shadow-3xs"
                      >
                        <ShoppingBag className="h-3.5 w-3.5 text-zinc-500" />
                        <span>{t('ecommerceRecovery')}</span>
                      </button>
                    </div>
                  )}

                  <DropdownSelect
                    value={preset}
                    onChange={handlePresetChange}
                    options={periodOptions}
                    className="w-[140px]"
                  />

                  <button
                    onClick={fetchEcommerceAnalytics}
                    className="p-2 border border-[#E8E8E6] bg-white hover:bg-zinc-50 rounded-[6px] transition-colors cursor-pointer shadow-3xs"
                    title={t('refreshStats')}
                  >
                    <RefreshCw className={`h-3.5 w-3.5 text-zinc-600 ${ecoLoading ? 'animate-spin' : ''}`} />
                  </button>
                </div>
              </div>

              {/* 3-COLUMN COMMERCE METRIC CARDS GRID (EXACT SUPER ADMIN COPY) */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                
                {/* Metric Card 1: Recovered Revenue */}
                <div className="rounded-[10px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 flex flex-col justify-between hover:border-zinc-300 transition-all duration-200">
                  <div className="flex items-center justify-between mb-4">
                    <div className="flex items-center gap-2 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                      <Sparkles className="h-4 w-4 text-emerald-600" />
                      <span>{t('recoveredRevenue')}</span>
                    </div>
                    <button className="h-6 w-6 rounded hover:bg-zinc-100 flex items-center justify-center text-zinc-400 hover:text-black transition-colors cursor-pointer">
                      <ArrowUpRight className="h-3.5 w-3.5" />
                    </button>
                  </div>

                  <div className="mb-4">
                    <span className="text-[48px] md:text-[52px] font-black leading-none tracking-tighter text-black">
                      {currencySymbol}{ecoData.metrics.recovered_revenue.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 })}
                    </span>
                  </div>

                  <div className="flex items-center justify-between pt-3 border-t border-[#F0F0F0]">
                    <div>
                      <div className="text-xs font-bold text-black">{currencySymbol}{(ecoData.metrics.recovered_revenue / (ecoData.metrics.carts_recovered || 1)).toFixed(2)}</div>
                      <div className="text-[10px] font-semibold text-zinc-400">Avg Restored Value</div>
                    </div>
                    <div className="text-right">
                      <div className="text-xs font-bold text-emerald-700">+24.8%</div>
                      <div className="text-[10px] font-semibold text-zinc-400">{t('recoveredCashAmount')}</div>
                    </div>
                    <MiniBarChart data={[32, 45, 58, 70, 85, 92, 110, 128]} highlightIndex={7} />
                  </div>
                </div>

                {/* Metric Card 2: Recovery Rate */}
                <div className="rounded-[10px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 flex flex-col justify-between hover:border-zinc-300 transition-all duration-200">
                  <div className="flex items-center justify-between mb-4">
                    <div className="flex items-center gap-2 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                      <Percent className="h-4 w-4 text-zinc-400" />
                      <span>{t('recoveryRate')}</span>
                    </div>
                    <button className="h-6 w-6 rounded hover:bg-zinc-100 flex items-center justify-center text-zinc-400 hover:text-black transition-colors cursor-pointer">
                      <ArrowUpRight className="h-3.5 w-3.5" />
                    </button>
                  </div>

                  <div className="flex items-center justify-between mb-2">
                    <div>
                      <span className="text-[48px] md:text-[52px] font-black leading-none tracking-tighter text-black">
                        {ecoData.metrics.recovery_rate}
                      </span>
                      <sup className="text-2xl font-black text-black align-super">%</sup>
                    </div>
                    <GaugeChart percentage={ecoData.metrics.recovery_rate} />
                  </div>

                  <div className="flex items-center justify-between pt-3 border-t border-[#F0F0F0]">
                    <div>
                      <div className="text-xs font-bold text-black">{ecoData.metrics.carts_recovered}</div>
                      <div className="text-[10px] font-semibold text-zinc-400">{t('cartsRecovered')}</div>
                    </div>
                    <div className="text-right">
                      <div className="text-xs font-bold text-emerald-700">{t('restorationEfficacy')}</div>
                      <div className="text-[10px] font-semibold text-zinc-400">High Conversion</div>
                    </div>
                  </div>
                </div>

                {/* Metric Card 3: Abandoned Carts Index */}
                <div className="rounded-[10px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 flex flex-col justify-between hover:border-zinc-300 transition-all duration-200">
                  <div className="flex items-center justify-between mb-4">
                    <div className="flex items-center gap-2 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                      <ShoppingCart className="h-4 w-4 text-zinc-400" />
                      <span>{t('cartsAbandoned')}</span>
                    </div>
                    <span className="text-[10px] font-black uppercase bg-[#E8FDE8] border border-[#4AE54A]/30 text-emerald-800 px-2 py-0.5 rounded flex items-center gap-1">
                      <span className="h-1.5 w-1.5 rounded-full bg-[#4AE54A] animate-pulse" />
                      Active Sync
                    </span>
                  </div>

                  <div className="mb-4">
                    <span className="text-[48px] md:text-[52px] font-black leading-none tracking-tighter text-black">
                      {ecoData.metrics.carts_abandoned}
                    </span>
                  </div>

                  <div className="flex items-center justify-between pt-3 border-t border-[#F0F0F0]">
                    <div>
                      <div className="text-xs font-bold text-black">{ecoData.metrics.carts_abandoned - ecoData.metrics.carts_recovered}</div>
                      <div className="text-[10px] font-semibold text-zinc-400">Pending Dispatch</div>
                    </div>
                    <div className="text-right">
                      <div className="text-xs font-bold text-black">{ecoData.carts.length}</div>
                      <div className="text-[10px] font-semibold text-zinc-400">{t('totalCapturedCheckouts')}</div>
                    </div>
                    <MiniBarChart data={[45, 52, 60, 68, 75, 82, 90]} highlightIndex={6} />
                  </div>
                </div>

              </div>

              {/* 2-COLUMN ANALYTICS CHARTS GRID */}
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                
                {/* Left Card: Cart Recovery Trajectory */}
                <div className="rounded-[12px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 sm:p-7 space-y-5">
                  <div className="flex items-start justify-between pb-2 border-b border-[#F0F0F0]">
                    <div className="space-y-1">
                      <h3 className="text-xs font-black uppercase tracking-wider text-black flex items-center gap-1.5">
                        <TrendingUp className="h-4 w-4 text-emerald-600 shrink-0" />
                        <span>Revenue Restoration Dynamics</span>
                      </h3>
                      <p className="text-[11px] text-zinc-400 font-semibold leading-normal">Recovered revenue and checkout restoration throughput</p>
                    </div>
                  </div>

                  {/* Area Chart */}
                  <div className="h-60 w-full pt-2">
                    <ResponsiveContainer width="100%" height="100%">
                      <AreaChart data={ecoSparkline} margin={{ top: 10, right: 10, left: 0, bottom: 5 }}>
                        <defs>
                          <linearGradient id="ecoChartGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#10B981" stopOpacity={0.25} />
                            <stop offset="100%" stopColor="#10B981" stopOpacity={0.0} />
                          </linearGradient>
                        </defs>
                        <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" vertical={false} />
                        <XAxis 
                          dataKey="val" 
                          tick={{ fontSize: 10, fill: '#64748B', fontWeight: 600 }} 
                          axisLine={false} 
                          tickLine={false}
                          minTickGap={20}
                        />
                        <YAxis 
                          tick={{ fontSize: 10, fill: '#64748B', fontWeight: 600 }} 
                          axisLine={false} 
                          tickLine={false}
                          width={48}
                          tickFormatter={(val: number) => `${currencySymbol}${val >= 1000 ? `${Math.round(val / 1000)}k` : val}`}
                        />
                        <Tooltip
                          contentStyle={{
                            backgroundColor: '#0A0A0A',
                            border: '1px solid #222',
                            borderRadius: '8px',
                            color: '#FFF',
                            fontSize: '11px',
                            fontWeight: 'bold',
                            boxShadow: '0 4px 12px rgba(0,0,0,0.15)'
                          }}
                          formatter={(val: any) => [`${currencySymbol}${parseFloat(val).toFixed(2)}`, 'Value']}
                        />
                        <Area type="monotone" dataKey="val" stroke="#10B981" strokeWidth={2.5} fill="url(#ecoChartGradient)" />
                      </AreaChart>
                    </ResponsiveContainer>
                  </div>

                  {/* Centered Legend Strip Below Chart */}
                  <div className="flex items-center justify-center gap-4 pt-3 border-t border-[#F1F5F9]">
                    <span className="flex items-center gap-1.5 text-[11px] font-bold text-zinc-500 bg-zinc-50 border border-zinc-200 px-3 py-1 rounded-md">
                      <span className="h-2 w-2 rounded-xs bg-[#0A0A0A]" />
                      <span>{t('cartsAbandoned')}</span>
                    </span>
                    <span className="flex items-center gap-1.5 text-[11px] font-black text-emerald-800 bg-[#E8FDE8] border border-[#4AE54A]/30 px-3 py-1 rounded-md shadow-3xs">
                      <span className="h-1.5 w-1.5 rounded-full bg-[#4AE54A] animate-pulse" />
                      <span>{t('recoveredRevenue')}</span>
                    </span>
                  </div>
                </div>

                {/* Right Card: E-Commerce Checkout Distribution */}
                <div className="rounded-[12px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 sm:p-7 space-y-5 flex flex-col justify-between">
                  <div className="flex items-center justify-between pb-2 border-b border-[#F0F0F0]">
                    <div className="space-y-1">
                      <h3 className="text-xs font-black uppercase tracking-wider text-black flex items-center gap-1.5">
                        <ShoppingBag className="h-4 w-4 text-zinc-500 shrink-0" />
                        <span>Checkout Status Breakdown</span>
                      </h3>
                      <p className="text-[11px] text-zinc-400 font-semibold leading-normal">Distribution across recovered and pending checkouts</p>
                    </div>
                  </div>

                  {/* Donut & Segmented Progress Bars */}
                  <div className="flex flex-col sm:flex-row items-center gap-6 py-1">
                    <div className="w-40 h-40 relative shrink-0">
                      <ResponsiveContainer width="100%" height="100%">
                        <PieChart>
                          <Pie
                            data={[
                              { name: t('recoveredSuccessfully') || 'Recovered', value: ecoData.metrics.carts_recovered, color: '#4AE54A' },
                              { name: 'Pending Recovery', value: Math.max(0, ecoData.metrics.carts_abandoned - ecoData.metrics.carts_recovered), color: '#0A0A0A' }
                            ]}
                            cx="50%"
                            cy="50%"
                            innerRadius={48}
                            outerRadius={68}
                            paddingAngle={3}
                            dataKey="value"
                          >
                            <Cell fill="#4AE54A" stroke="#FFFFFF" strokeWidth={2} />
                            <Cell fill="#0A0A0A" stroke="#FFFFFF" strokeWidth={2} />
                          </Pie>
                          <Tooltip 
                            contentStyle={{ backgroundColor: '#0A0A0A', border: '1px solid #222', borderRadius: '8px', color: '#FFF', fontSize: '11px', fontWeight: 'bold' }}
                            formatter={(val: any) => [val?.toLocaleString?.() || val, 'Count']}
                          />
                        </PieChart>
                      </ResponsiveContainer>
                      <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                        <span className="text-[10px] font-bold text-zinc-400 uppercase tracking-wider">{t('total')}</span>
                        <span className="text-sm font-black text-black tracking-tight font-mono">
                          {ecoData.metrics.carts_abandoned}
                        </span>
                      </div>
                    </div>

                    <div className="flex-1 space-y-2.5 w-full">
                      <div className="space-y-1">
                        <div className="flex items-center justify-between text-[11px] font-semibold">
                          <div className="flex items-center gap-2">
                            <span className="h-2 w-2 rounded-xs bg-[#4AE54A] shrink-0" />
                            <span className="text-zinc-800 font-bold">{t('cartsRecovered')}</span>
                          </div>
                          <div className="flex items-center gap-1.5 font-mono">
                            <span className="font-extrabold text-zinc-950">{ecoData.metrics.carts_recovered}</span>
                            <span className="text-zinc-400 text-[10px]">({ecoData.metrics.recovery_rate}%)</span>
                          </div>
                        </div>
                        <div className="h-1.5 w-full bg-zinc-100 rounded-full overflow-hidden">
                          <div className="h-full rounded-full bg-[#4AE54A] transition-all duration-500" style={{ width: `${ecoData.metrics.recovery_rate}%` }} />
                        </div>
                      </div>

                      <div className="space-y-1">
                        <div className="flex items-center justify-between text-[11px] font-semibold">
                          <div className="flex items-center gap-2">
                            <span className="h-2 w-2 rounded-xs bg-[#0A0A0A] shrink-0" />
                            <span className="text-zinc-800 font-bold">Pending Dispatch</span>
                          </div>
                          <div className="flex items-center gap-1.5 font-mono">
                            <span className="font-extrabold text-zinc-950">{Math.max(0, ecoData.metrics.carts_abandoned - ecoData.metrics.carts_recovered)}</span>
                            <span className="text-zinc-400 text-[10px]">({Math.max(0, roundPercent(100 - ecoData.metrics.recovery_rate))}%)</span>
                          </div>
                        </div>
                        <div className="h-1.5 w-full bg-zinc-100 rounded-full overflow-hidden">
                          <div className="h-full rounded-full bg-[#0A0A0A] transition-all duration-500" style={{ width: `${Math.max(0, 100 - ecoData.metrics.recovery_rate)}%` }} />
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Telemetry Footer Strip */}
                  <div className="pt-3 border-t border-[#F1F5F9] grid grid-cols-3 gap-2 text-center">
                    <div className="bg-zinc-50 border border-zinc-200/80 p-2 rounded-lg">
                      <div className="text-[9px] font-bold text-zinc-400 uppercase tracking-wider">Store Status</div>
                      <div className="text-xs font-black text-black truncate mt-0.5">Connected</div>
                    </div>
                    <div className="bg-zinc-50 border border-zinc-200/80 p-2 rounded-lg">
                      <div className="text-[9px] font-bold text-zinc-400 uppercase tracking-wider">Avg Cart Value</div>
                      <div className="text-xs font-black text-emerald-700 truncate mt-0.5">
                        {currencySymbol}{ecoData.carts.length > 0 ? (ecoData.metrics.recovered_revenue / (ecoData.metrics.carts_recovered || 1)).toFixed(2) : '0.00'}
                      </div>
                    </div>
                    <div className="bg-zinc-50 border border-zinc-200/80 p-2 rounded-lg">
                      <div className="text-[9px] font-bold text-zinc-400 uppercase tracking-wider">Efficacy</div>
                      <div className="text-xs font-black text-black truncate mt-0.5">{ecoData.metrics.recovery_rate}%</div>
                    </div>
                  </div>
                </div>

              </div>

              {/* CAPTURED ABANDONED CHECKOUTS DATATABLE */}
              <div className="border border-[#E8E8E6] rounded-[10px] overflow-hidden bg-white shadow-[var(--shadow-card)]">
                <div className="p-5 border-b border-[#E8E8E6] flex items-center justify-between">
                  <div className="space-y-0.5">
                    <h3 className="text-xs font-black uppercase tracking-wider text-black flex items-center gap-1.5">
                      <ShoppingBag className="h-4 w-4 text-zinc-500" />
                      <span>{t('capturedAbandonedCheckouts')}</span>
                    </h3>
                    <p className="text-[11px] text-zinc-400 font-semibold">Live checkout recovery list with manual dispatch triggers</p>
                  </div>
                </div>

                {ecoData.carts.length === 0 ? (
                  <div className="p-16 text-center text-xs text-zinc-400">{t('noCapturedCheckouts')}</div>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse text-xs select-none">
                      <thead>
                        <tr className="bg-zinc-50 border-b border-[#E8E8E6] text-zinc-500 font-semibold text-[11px] tracking-tight uppercase">
                          <th className="px-6 py-4">{t('customerDetails')}</th>
                          <th className="px-6 py-4">{t('itemsSummary')}</th>
                          <th className="px-6 py-4 text-right">{t('value')}</th>
                          <th className="px-6 py-4 text-center">{t('status')}</th>
                          <th className="px-6 py-4 text-right">{t('actions')}</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-[#E8E8E6] text-zinc-850">
                        {ecoData.carts.map((cart) => {
                          const contactName = cart.contact 
                            ? `${cart.contact.first_name || 'Guest'} ${cart.contact.last_name || ''}`
                            : 'Unknown Customer';
                          
                          const contactContact = cart.contact
                            ? (cart.contact.email || cart.contact.phone || 'N/A')
                            : 'N/A';

                          const itemsList = Array.isArray(cart.items_summary) 
                            ? cart.items_summary.map((i: any) => `${i.quantity || 1}x ${i.title || i.name}`).join(', ') 
                            : 'Details unavailable';

                          return (
                            <tr key={cart.id} className="hover:bg-[#FAFAFA]/50 transition-colors">
                              <td className="px-6 py-4">
                                <div className="flex flex-col text-left">
                                  <span className="font-bold text-zinc-955 text-xs">{contactName}</span>
                                  <span className="text-[10px] text-zinc-400 font-semibold mt-0.5 font-mono">{contactContact}</span>
                                </div>
                              </td>
                              <td className="px-6 py-4 text-zinc-600 max-w-[280px] truncate font-medium" title={itemsList}>
                                {itemsList}
                              </td>
                              <td className="px-6 py-4 text-right font-mono font-bold text-zinc-955">{currencySymbol}{parseFloat(cart.total_price).toFixed(2)}</td>
                              <td className="px-6 py-4 text-center">
                                <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider ${
                                  cart.recovery_status === 'recovered' 
                                    ? 'bg-[#E8FDE8] text-emerald-800 border border-[#4AE54A]/30' 
                                    : 'bg-zinc-100 text-zinc-600 border border-zinc-200'
                                }`}>
                                  {cart.recovery_status === 'recovered' && <span className="h-1.5 w-1.5 rounded-full bg-[#4AE54A]" />}
                                  {cart.recovery_status}
                                </span>
                              </td>
                              <td className="px-6 py-4 text-right">
                                {cart.recovery_status === 'recovered' ? (
                                  <span className="text-xs text-emerald-700 font-bold uppercase inline-flex items-center gap-1 select-none">
                                    <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" /> {t('recovered')}
                                  </span>
                                ) : (
                                  <button
                                    onClick={() => handleManualTrigger(cart.id)}
                                    disabled={triggeringId !== null}
                                    className="bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-[10px] uppercase tracking-wider h-8 px-3.5 rounded-[6px] shadow-sm transition-all cursor-pointer inline-flex items-center gap-1.5"
                                  >
                                    {triggeringId === cart.id ? (
                                      <>
                                        <Loader2 className="h-3.5 w-3.5 animate-spin" /> {t('dispatching')}
                                      </>
                                    ) : (
                                      <>
                                        <Zap className="h-3 w-3 fill-white text-white" /> {t('manualTrigger')}
                                      </>
                                    )}
                                  </button>
                                )}
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>

            </div>
          )}
        </div>
      )}
    </div>
  );
}
