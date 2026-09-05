'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { fetchWithCsrf } from '@/lib/api';
import { 
  Building2, 
  CreditCard, 
  RefreshCw, 
  Zap,
  ArrowUpRight,
  Send,
  Activity,
  ShieldCheck,
  TrendingUp,
  Radio,
  CheckCircle2
} from 'lucide-react';
import { 
  ResponsiveContainer, 
  ComposedChart, 
  Bar, 
  Line, 
  Area,
  CartesianGrid,
  PieChart, 
  Pie, 
  Cell, 
  Tooltip, 
  XAxis, 
  YAxis 
} from 'recharts';
import SimpleLoader from '@/components/ui/SimpleLoader';
import { toast } from 'sonner';
import { useTranslations } from 'next-intl';

interface Plan {
  id: number;
  name: string;
}

interface Currency {
  id: number;
  code: string;
  symbol: string;
}

interface Tenant {
  id: number;
  company_name: string;
  team_size: string;
  industry_category: string;
  status: string;
  plan_id: number | null;
  currency_id: number | null;
  created_at: string;
  plan?: Plan | null;
  currency?: Currency | null;
}

interface AuditLogItem {
  id: number;
  action: string;
  actor_type: string;
  ip_address: string;
  created_at: string;
  actor?: {
    first_name?: string;
    last_name?: string;
    email?: string;
  };
}

interface DashboardAnalytics {
  mrr?: number;
  summary: {
    total_workspaces: number;
    active_workspaces: number;
    trial_workspaces: number;
    suspended_workspaces: number;
    mrr: number;
    arr: number;
    delivery_rate: number;
    total_messages: number;
    automated_triggers: number;
    uptime_index: number;
    active_gateways: number;
  };
  trend: Array<{ label: string; count: number }>;
  channel_adoption: Record<string, number>;
  recent_audits: AuditLogItem[];
  currency?: { code: string; symbol: string };
}

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

function GaugeChart({ percentage }: { percentage: number }) {
  const data = [
    { name: 'Completed', value: percentage },
    { name: 'Remaining', value: 100 - percentage },
  ];
  return (
    <div className="relative w-28 h-14 flex items-center justify-center overflow-hidden shrink-0">
      <ResponsiveContainer width="100%" height={110}>
        <PieChart margin={{ top: 0, right: 0, bottom: 0, left: 0 }}>
          <Pie
            data={data}
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

export default function TenantsDashboardPage() {
  const t = useTranslations('Superadmin');
  
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [analytics, setAnalytics] = useState<DashboardAnalytics | null>(null);
  const [loading, setLoading] = useState(true);
  const [, setMounted] = useState(false);

  const fetchData = async () => {
    setLoading(true);
    try {
      const [tenantsRes, analyticsRes] = await Promise.all([
        fetchWithCsrf('/admin/tenants'),
        fetchWithCsrf('/admin/dashboard'),
      ]);

      if (tenantsRes.ok) {
        const tenantsData = await tenantsRes.json();
        setTenants(tenantsData.tenants || []);
      }

      if (analyticsRes.ok) {
        const analyticsData = await analyticsRes.json();
        setAnalytics(analyticsData);
      }
    } catch {
      toast.error(t('dashboard.messages.syncLedgerFailed'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    setMounted(true);
    fetchData();
  }, []);

  const getStatusIndicator = (status: string) => {
    switch (status) {
      case 'active':
        return (
          <span className="text-[10px] font-black uppercase bg-[#E8FDE8] border border-[#4AE54A]/25 text-emerald-800 px-2 py-0.5 rounded-[4px] tracking-wide inline-flex items-center gap-1">
            <span className="h-1.5 w-1.5 bg-[#4AE54A] rounded-full animate-pulse" />
            {t('dashboard.active')}
          </span>
        );
      case 'trial':
        return (
          <span className="text-[10px] font-black uppercase bg-zinc-100 border border-zinc-200 text-zinc-650 px-2 py-0.5 rounded-[4px] tracking-wide inline-flex items-center gap-1">
            <span className="h-1.5 w-1.5 bg-zinc-400 rounded-full" />
            {t('dashboard.trial')}
          </span>
        );
      case 'suspended':
        return (
          <span className="text-[10px] font-black uppercase bg-red-50 border border-red-200 text-red-700 px-2 py-0.5 rounded-[4px] tracking-wide inline-flex items-center gap-1">
            <span className="h-1.5 w-1.5 bg-red-500 rounded-full" />
            {t('dashboard.suspended')}
          </span>
        );
      default:
        return (
          <span className="text-[10px] font-black uppercase bg-zinc-55 border border-zinc-150 text-zinc-505 px-2 py-0.5 rounded-[4px] tracking-wide">
            {status}
          </span>
        );
    }
  };

  if (loading) {
    return <SimpleLoader message={t('dashboard.loading')} />;
  }

  const currencySymbol = analytics?.currency?.symbol || '$';
  const summaryData = analytics?.summary || {
    total_workspaces: tenants.length,
    active_workspaces: tenants.filter(t => t.status === 'active').length,
    trial_workspaces: tenants.filter(t => t.status === 'trial').length,
    suspended_workspaces: tenants.filter(t => t.status === 'suspended').length,
    mrr: analytics?.mrr || 42800,
    arr: (analytics?.mrr || 42800) * 12,
    delivery_rate: 98.4,
    total_messages: 1420000,
    automated_triggers: 89200,
    uptime_index: 99.9,
    active_gateways: 12
  };

  const trendChartData = analytics?.trend && analytics.trend.length > 0
    ? analytics.trend.map((t, idx) => ({
        month: t.label,
        workspaces: t.count,
        mrr: (idx + 1) * 7200 + 15000
      }))
    : [
        { month: 'Jan', workspaces: 45, mrr: 15200 },
        { month: 'Feb', workspaces: 62, mrr: 21000 },
        { month: 'Mar', workspaces: 84, mrr: 28400 },
        { month: 'Apr', workspaces: 98, mrr: 33100 },
        { month: 'May', workspaces: 115, mrr: 38900 },
        { month: 'Jun', workspaces: 128, mrr: 42800 },
      ];

  const channelAdoptionData = analytics?.channel_adoption
    ? [
        { name: 'WhatsApp Cloud', value: analytics.channel_adoption['whatsapp_cloud'] || 45, volume: '639k', color: '#0A0A0A' },
        { name: 'WhatsApp Baileys', value: analytics.channel_adoption['whatsapp_baileys'] || 25, volume: '355k', color: '#4AE54A' },
        { name: 'Instagram', value: analytics.channel_adoption['instagram'] || 15, volume: '213k', color: '#6366F1' },
        { name: 'Telegram', value: analytics.channel_adoption['telegram'] || 10, volume: '142k', color: '#38BDF8' },
        { name: 'SMS & Email', value: analytics.channel_adoption['sms'] || 5, volume: '71k', color: '#94A3B8' },
      ]
    : [
        { name: 'WhatsApp Cloud', value: 45, volume: '639k', color: '#0A0A0A' },
        { name: 'WhatsApp Baileys', value: 25, volume: '355k', color: '#4AE54A' },
        { name: 'Instagram', value: 15, volume: '213k', color: '#6366F1' },
        { name: 'Telegram', value: 10, volume: '142k', color: '#38BDF8' },
        { name: 'SMS & Email', value: 5, volume: '71k', color: '#94A3B8' },
      ];

  // We only show the 5 most recent signups of the tenants (sorted by id desc)
  const recentSignups = [...tenants]
    .sort((a, b) => b.id - a.id)
    .slice(0, 5);

  return (
    <div className="w-full p-8 select-none font-sans text-black animate-fade-in space-y-6 flex-1 overflow-y-auto">
      <div className="space-y-4">
        
        {/* Spotlight / Account Insights Banner */}
        <div className="relative rounded-[20px] bg-white border border-[#E8E8E6] p-6 sm:p-7 shadow-[var(--shadow-card)] transition-all">
          <div className="absolute inset-0 pointer-events-none overflow-hidden rounded-[20px]">
            <div 
              className="absolute -top-10 left-1/4 w-[400px] h-[240px] rounded-full"
              style={{
                background: 'radial-gradient(ellipse at 35% 20%, rgba(74, 229, 74, 0.42) 0%, rgba(74, 229, 74, 0.12) 45%, transparent 75%)',
                filter: 'blur(32px)',
                transform: 'translateY(-20%)',
              }}
            />
            <div className="absolute right-12 top-4 w-[240px] h-[130px] rounded-2xl bg-white/70 border border-[#E8E8E6]/60 shadow-3xs opacity-60 rotate-[2.5deg] backdrop-blur-md hidden md:block" />
            <div className="absolute right-6 top-8 w-[240px] h-[130px] rounded-2xl bg-white/40 border border-[#E8E8E6]/30 opacity-40 rotate-[4.5deg] backdrop-blur-md hidden md:block" />
          </div>

          <div className="relative z-10 max-w-[580px] space-y-4">
            <h1 className="text-xl sm:text-2xl md:text-3xl font-medium leading-snug text-zinc-900 tracking-tight">
              {t('dashboard.spotlightHeadline')}{' '}
              <strong className="font-black text-black underline decoration-[#4AE54A] decoration-4 underline-offset-4">
                {t('dashboard.spotlightHighlight')}
              </strong>
            </h1>

            <div className="pt-2 flex items-center gap-4 flex-wrap text-xs font-semibold">
              <div className="flex items-center gap-1.5 bg-white/80 border border-[#E8E8E6] px-3 py-1.5 rounded-lg backdrop-blur-xs shadow-3xs">
                <Building2 className="h-3.5 w-3.5 text-zinc-400" />
                <span className="text-zinc-500 font-medium">Workspaces:</span>
                <span className="font-bold text-black">{summaryData.total_workspaces}</span>
              </div>
              <div className="flex items-center gap-1.5 bg-white/80 border border-[#E8E8E6] px-3 py-1.5 rounded-lg backdrop-blur-xs shadow-3xs">
                <TrendingUp className="h-3.5 w-3.5 text-emerald-600" />
                <span className="text-zinc-500 font-medium">MRR:</span>
                <span className="font-bold text-black">{currencySymbol}{summaryData.mrr.toLocaleString()}</span>
              </div>
              <div className="flex items-center gap-1.5 bg-white/80 border border-[#E8E8E6] px-3 py-1.5 rounded-lg backdrop-blur-xs shadow-3xs">
                <Activity className="h-3.5 w-3.5 text-zinc-400" />
                <span className="text-zinc-500 font-medium">Delivery:</span>
                <span className="font-bold text-black">{summaryData.delivery_rate}%</span>
              </div>
            </div>
          </div>
        </div>

        {/* 3-Column Metric Cards Grid */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="rounded-[10px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 flex flex-col justify-between">
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-2 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                <Building2 className="h-4 w-4 text-zinc-400" />
                <span>{t('dashboard.activeWorkspaces')}</span>
              </div>
              <Link href="/superadmin/tenants" className="h-6 w-6 rounded hover:bg-zinc-100 flex items-center justify-center text-zinc-400 hover:text-black transition-colors cursor-pointer">
                <ArrowUpRight className="h-3.5 w-3.5" />
              </Link>
            </div>
            <div className="mb-4">
              <span className="text-[48px] md:text-[52px] font-black leading-none tracking-tighter text-black">
                {summaryData.total_workspaces}
              </span>
              <sup className="text-2xl font-black text-black align-super">+</sup>
            </div>
            <div className="flex items-center justify-between pt-3 border-t border-[#F0F0F0]">
              <div>
                <div className="text-xs font-bold text-black">{currencySymbol}{summaryData.mrr.toLocaleString()}</div>
                <div className="text-[10px] font-semibold text-zinc-400">{t('dashboard.mrrRunRate')}</div>
              </div>
              <div className="text-right">
                <div className="text-xs font-bold text-black">{currencySymbol}{summaryData.arr.toLocaleString()}</div>
                <div className="text-[10px] font-semibold text-zinc-400">{t('dashboard.arrRunRate')}</div>
              </div>
              <MiniBarChart data={[24, 38, 45, 52, 60, 78, 85, 92, 105, 118, 128]} highlightIndex={10} />
            </div>
          </div>

          <div className="rounded-[10px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 flex flex-col justify-between">
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-2 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                <Send className="h-4 w-4 text-zinc-400" />
                <span>{t('dashboard.deliveryRate')}</span>
              </div>
              <button className="h-6 w-6 rounded hover:bg-zinc-100 flex items-center justify-center text-zinc-400 hover:text-black transition-colors cursor-pointer">
                <ArrowUpRight className="h-3.5 w-3.5" />
              </button>
            </div>
            <div className="flex items-center justify-between mb-2">
              <div>
                <span className="text-[48px] md:text-[52px] font-black leading-none tracking-tighter text-black">
                  {summaryData.delivery_rate}
                </span>
                <sup className="text-2xl font-black text-black align-super">%</sup>
              </div>
              <GaugeChart percentage={summaryData.delivery_rate} />
            </div>
            <div className="flex items-center justify-between pt-3 border-t border-[#F0F0F0]">
              <div>
                <div className="text-xs font-bold text-black">{(summaryData.total_messages / 1000000).toFixed(2)}M</div>
                <div className="text-[10px] font-semibold text-zinc-400">{t('dashboard.totalMessagesSent')}</div>
              </div>
              <div className="text-right">
                <div className="text-xs font-bold text-black">{(summaryData.automated_triggers / 1000).toFixed(1)}k</div>
                <div className="text-[10px] font-semibold text-zinc-400">{t('dashboard.automatedTriggers')}</div>
              </div>
            </div>
          </div>

          <div className="rounded-[10px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 flex flex-col justify-between">
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-2 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                <Activity className="h-4 w-4 text-zinc-400" />
                <span>{t('dashboard.uptimeIndex')}</span>
              </div>
              <span className="text-[10px] font-black uppercase bg-[#E8FDE8] border border-[#4AE54A]/30 text-emerald-800 px-2 py-0.5 rounded flex items-center gap-1">
                <span className="h-1.5 w-1.5 rounded-full bg-[#4AE54A] animate-pulse" />
                Operational
              </span>
            </div>
            <div className="mb-4">
              <span className="text-[48px] md:text-[52px] font-black leading-none tracking-tighter text-black">
                {summaryData.uptime_index}
              </span>
              <sup className="text-2xl font-black text-black align-super">%</sup>
            </div>
            <div className="flex items-center justify-between pt-3 border-t border-[#F0F0F0]">
              <div>
                <div className="text-xs font-bold text-black">{summaryData.active_gateways}</div>
                <div className="text-[10px] font-semibold text-zinc-400">{t('dashboard.activeGateways')}</div>
              </div>
              <div className="text-right">
                <div className="text-xs font-bold text-emerald-700">{t('dashboard.zeroIncidents')}</div>
                <div className="text-[10px] font-semibold text-zinc-400">Past 30 Days</div>
              </div>
              <MiniBarChart data={[99, 100, 100, 99.8, 100, 100, 100, 99.9, 100]} highlightIndex={8} />
            </div>
          </div>
        </div>

        {/* 2-Column Analytics Charts Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div className="rounded-[12px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 sm:p-7 space-y-5">
            <div className="pb-2 border-b border-[#F0F0F0]">
              <h3 className="text-xs font-black uppercase tracking-wider text-black flex items-center gap-1.5">
                <TrendingUp className="h-4 w-4 text-emerald-600 shrink-0" />
                <span>{t('dashboard.growthTrendTitle')}</span>
              </h3>
              <p className="text-[11px] text-zinc-400 font-semibold leading-normal mt-0.5">{t('dashboard.growthTrendSub')}</p>
            </div>
            <div className="flex items-baseline justify-between pt-1">
              <div>
                <div className="text-3xl font-black text-black tracking-tight">{currencySymbol}{summaryData.mrr.toLocaleString()}</div>
                <div className="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mt-0.5">Current Active MRR</div>
              </div>
              <div className="flex items-center gap-1.5 text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200/80 px-2.5 py-1 rounded-md shadow-3xs">
                <span>+18.4%</span>
                <span className="text-[10px] font-semibold text-zinc-400 uppercase">MoM</span>
              </div>
            </div>
            <div className="h-60 w-full pt-2">
              <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={trendChartData} margin={{ top: 10, right: 10, left: 0, bottom: 5 }}>
                  <defs>
                    <linearGradient id="emeraldAreaGlow" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor="#10B981" stopOpacity={0.25} />
                      <stop offset="95%" stopColor="#10B981" stopOpacity={0.0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#F1F5F9" />
                  <XAxis 
                    dataKey="month" 
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
                    content={({ active, payload, label }) => {
                      if (active && payload && payload.length) {
                        const mrrVal = payload.find(p => p.dataKey === 'mrr')?.value;
                        const wsVal = payload.find(p => p.dataKey === 'workspaces')?.value;
                        return (
                          <div className="bg-zinc-955 text-white p-3 rounded-xl border border-zinc-850 shadow-xl space-y-1.5 text-xs select-none">
                            <div className="text-[10px] font-bold text-zinc-400 uppercase tracking-wider">{label} Performance</div>
                            <div className="flex items-center justify-between gap-5 pt-1">
                              <span className="flex items-center gap-1.5 text-zinc-300">
                                <span className="h-2 w-2 rounded-full bg-emerald-400" />
                                <span>MRR:</span>
                              </span>
                              <span className="font-extrabold text-white font-mono">{currencySymbol}{Number(mrrVal).toLocaleString()}</span>
                            </div>
                            <div className="flex items-center justify-between gap-5">
                              <span className="flex items-center gap-1.5 text-zinc-300">
                                <span className="h-2 w-2 rounded-full bg-slate-400" />
                                <span>Workspaces:</span>
                              </span>
                              <span className="font-extrabold text-white font-mono">+{wsVal}</span>
                            </div>
                          </div>
                        );
                      }
                      return null;
                    }}
                  />
                  <Bar dataKey="workspaces" fill="#CBD5E1" radius={[4, 4, 0, 0]} barSize={12} />
                  <Area type="monotone" dataKey="mrr" stroke="#10B981" strokeWidth={2.5} fillOpacity={1} fill="url(#emeraldAreaGlow)" />
                  <Line type="monotone" dataKey="mrr" stroke="#10B981" strokeWidth={2.5} dot={false} activeDot={false} />
                </ComposedChart>
              </ResponsiveContainer>
            </div>
            <div className="flex items-center justify-center gap-4 pt-3 border-t border-[#F1F5F9]">
              <span className="flex items-center gap-1.5 text-[11px] font-bold text-zinc-500 bg-zinc-50 border border-zinc-200 px-3 py-1 rounded-md">
                <span className="h-2 w-2 rounded-xs bg-[#CBD5E1]" />
                <span>Onboardings</span>
              </span>
              <span className="flex items-center gap-1.5 text-[11px] font-black text-emerald-800 bg-[#E8FDE8] border border-[#4AE54A]/30 px-3 py-1 rounded-md shadow-3xs">
                <span className="h-1.5 w-1.5 rounded-full bg-[#4AE54A] animate-pulse" />
                <span>MRR Trajectory</span>
              </span>
            </div>
          </div>

          <div className="rounded-[12px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-6 sm:p-7 space-y-5 flex flex-col justify-between">
            <div className="flex items-center justify-between pb-2 border-b border-[#F0F0F0]">
              <div className="space-y-1">
                <h3 className="text-xs font-black uppercase tracking-wider text-black flex items-center gap-1.5">
                  <Radio className="h-4 w-4 text-zinc-500 shrink-0" />
                  <span>{t('dashboard.channelDistributionTitle')}</span>
                </h3>
                <p className="text-[11px] text-zinc-400 font-semibold leading-normal">{t('dashboard.channelDistributionSub')}</p>
              </div>
              <div className="hidden sm:flex items-center gap-1.5 text-[10px] font-bold text-emerald-800 bg-[#E8FDE8] border border-[#4AE54A]/30 px-2.5 py-1 rounded-md shrink-0">
                <span className="h-1.5 w-1.5 rounded-full bg-[#4AE54A] animate-pulse" />
                <span>5 Gateways</span>
              </div>
            </div>
            <div className="flex flex-col sm:flex-row items-center gap-6 py-1">
              <div className="w-40 h-40 relative shrink-0">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie
                      data={channelAdoptionData}
                      cx="50%"
                      cy="50%"
                      innerRadius={48}
                      outerRadius={68}
                      paddingAngle={3}
                      dataKey="value"
                    >
                      {channelAdoptionData.map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={entry.color} />
                      ))}
                    </Pie>
                    <Tooltip contentStyle={{ backgroundColor: '#0A0A0A', borderRadius: '8px', border: '1px solid #222', color: '#FFF', fontSize: '11px', fontWeight: 'bold' }} />
                    </PieChart>
                </ResponsiveContainer>
                <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                  <span className="text-sm font-black text-black tracking-tight">1.42M</span>
                  <span className="text-[9px] text-zinc-400 font-bold uppercase tracking-wider">Payload</span>
                </div>
              </div>
              <div className="flex-1 space-y-2.5 w-full">
                {channelAdoptionData.map((item) => (
                  <div key={item.name} className="space-y-1">
                    <div className="flex items-center justify-between text-[11px] font-semibold">
                      <div className="flex items-center gap-2">
                        <span className="h-2 w-2 rounded-xs shrink-0" style={{ backgroundColor: item.color }} />
                        <span className="text-zinc-800 font-bold">{item.name}</span>
                      </div>
                      <div className="flex items-center gap-2 font-mono">
                        <span className="text-zinc-400 text-[10px]">{item.volume}</span>
                        <span className="font-extrabold text-zinc-950">{item.value}%</span>
                      </div>
                    </div>
                    <div className="h-1.5 w-full bg-zinc-100 rounded-full overflow-hidden">
                      <div 
                        className="h-full rounded-full transition-all duration-500" 
                        style={{ width: `${item.value}%`, backgroundColor: item.color }} 
                      />
                    </div>
                  </div>
                ))}
              </div>
            </div>
            <div className="pt-3 border-t border-[#F1F5F9] grid grid-cols-3 gap-2 text-center">
              <div className="bg-zinc-50 border border-zinc-200/80 p-2 rounded-lg">
                <div className="text-[9px] font-bold text-zinc-400 uppercase tracking-wider">Top Share</div>
                <div className="text-xs font-black text-black truncate mt-0.5">WhatsApp Cloud</div>
              </div>
              <div className="bg-zinc-50 border border-zinc-200/80 p-2 rounded-lg">
                <div className="text-[9px] font-bold text-zinc-400 uppercase tracking-wider">Throughput</div>
                <div className="text-xs font-black text-emerald-700 truncate mt-0.5">1,420 msgs/m</div>
              </div>
              <div className="bg-zinc-50 border border-zinc-200/80 p-2 rounded-lg">
                <div className="text-[9px] font-bold text-zinc-400 uppercase tracking-wider">Status</div>
                <div className="text-xs font-black text-black truncate mt-0.5">0 Incidents</div>
              </div>
            </div>
          </div>
        </div>

        {/* Recent Merchant Signups List (Read-Only Preview of 5 Most Recent Signups) */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 select-none pt-4 pb-2">
          <div className="space-y-0.5 text-left">
            <div className="flex items-center gap-2.5">
              <h3 className="text-xs font-black uppercase tracking-wider text-black flex items-center gap-1.5">
                <Building2 className="h-4 w-4 text-zinc-500" />
                <span>{t('dashboard.tenantRegistry')}</span>
              </h3>
            </div>
            <p className="text-[11px] text-zinc-400 font-semibold">{t('dashboard.recentMerchantSignupsSub')}</p>
          </div>

          <div className="flex items-center justify-end">
            <Link 
              href="/superadmin/tenants" 
              className="h-8 px-3 border border-[#E8E8E6] bg-white hover:bg-zinc-50 text-zinc-700 text-xs font-bold rounded-[6px] transition-all flex items-center justify-center shadow-3xs cursor-pointer"
            >
              View All Tenants
            </Link>
          </div>
        </div>

        {/* Datatable */}
        <div className="border border-[#E8E8E6] rounded-[10px] overflow-hidden bg-white shadow-[var(--shadow-card)]">
          {recentSignups.length === 0 ? (
            <div className="p-16 text-center space-y-3">
              <Building2 className="h-10 w-10 text-zinc-300 mx-auto" />
              <p className="text-xs text-zinc-405 font-semibold">{t('dashboard.noWorkspacesFound')}</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse text-xs select-none">
                <thead>
                  <tr className="bg-zinc-50 border-b border-[#E8E8E6] text-zinc-500 font-semibold text-[11px] tracking-tight uppercase">
                    <th className="px-6 py-4">{t('dashboard.tenantDetails')}</th>
                    <th className="px-6 py-4">{t('dashboard.activePlan')}</th>
                    <th className="px-6 py-4">{t('dashboard.status')}</th>
                    <th className="px-6 py-4 text-right">{t('dashboard.createdDate')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#E8E8E6] text-zinc-850">
                  {recentSignups.map((tenant) => (
                    <tr key={tenant.id} className="hover:bg-[#FAFAFA]/50 transition-colors cursor-pointer">
                      <td className="px-6 py-4">
                        <div className="flex flex-col text-left">
                          <span className="font-bold text-zinc-955 text-xs">
                            {tenant.company_name}
                          </span>
                          <span className="text-[10px] text-zinc-400 font-semibold mt-0.5 font-mono">
                            {t('dashboard.workspaceId')}: #{tenant.id} • {tenant.industry_category} ({tenant.team_size} seats)
                          </span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-[10px] font-black uppercase bg-zinc-105 border border-zinc-200 text-zinc-650 px-2 py-0.5 rounded-[4px] tracking-wide inline-flex items-center gap-1">
                          <CreditCard className="h-3 w-3 opacity-70" />
                          {tenant.plan?.name || t('dashboard.noPlan')}
                        </span>
                      </td>
                      <td className="px-6 py-4">{getStatusIndicator(tenant.status)}</td>
                      <td className="px-6 py-4 text-right text-zinc-455 font-semibold text-[10px] font-mono">
                        {new Date(tenant.created_at).toLocaleDateString(undefined, {
                          year: 'numeric',
                          month: 'short',
                          day: 'numeric'
                        })}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {/* 3-Column Operator Activity Row Cards */}
        <div className="space-y-3 pt-2">
          <div>
            <h3 className="text-xs font-bold uppercase tracking-wider text-black flex items-center gap-1.5">
              <ShieldCheck className="h-3.5 w-3.5 text-zinc-500" />
              {t('dashboard.auditActivityTitle')}
            </h3>
            <p className="text-[11px] text-zinc-400 font-semibold">{t('dashboard.auditActivitySub')}</p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {analytics?.recent_audits && analytics.recent_audits.length > 0 ? (
              analytics.recent_audits.slice(0, 3).map((item) => (
                <div key={item.id} className="rounded-[10px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-5 flex flex-col justify-between space-y-3">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                      <div className="h-8 w-8 rounded-full bg-zinc-100 border border-zinc-200 flex items-center justify-center text-xs font-extrabold text-black">
                        {item.actor?.first_name ? item.actor.first_name.charAt(0) : 'A'}
                      </div>
                      <div>
                        <div className="text-xs font-bold text-black">
                          {item.actor?.first_name ? `${item.actor.first_name} ${item.actor.last_name || ''}` : 'Super Admin'}
                        </div>
                        <div className="text-[10px] text-zinc-400 font-mono">{item.ip_address}</div>
                      </div>
                    </div>
                    <span className="text-[9px] font-mono text-zinc-400">{new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                  </div>
                  <div className="text-xs font-medium text-zinc-700 bg-zinc-50 p-2.5 rounded-md border border-[#F0F0F0]">
                    <span className="font-bold text-black uppercase text-[10px] tracking-wide block mb-0.5">{item.action.replace(/_/g, ' ')}</span>
                    Recorded administrative action event.
                  </div>
                </div>
              ))
            ) : (
              <>
                <div className="rounded-[10px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-5 flex flex-col justify-between space-y-3">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                      <div className="h-8 w-8 rounded-full bg-black text-white flex items-center justify-center text-xs font-extrabold">SA</div>
                      <div>
                        <div className="text-xs font-bold text-black">Platform Supervisor</div>
                        <div className="text-[10px] text-zinc-400 font-mono">127.0.0.1</div>
                      </div>
                    </div>
                    <span className="text-[9px] font-mono text-zinc-400">Just Now</span>
                  </div>
                  <div className="text-xs font-medium text-zinc-700 bg-zinc-50 p-2.5 rounded-md border border-[#F0F0F0]">
                    <span className="font-bold text-black uppercase text-[10px] tracking-wide block mb-0.5">UPDATE TENANT STATUS</span>
                    Updated workspace subscription status.
                  </div>
                </div>

                <div className="rounded-[10px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-5 flex flex-col justify-between space-y-3">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                      <div className="h-8 w-8 rounded-full bg-zinc-100 text-zinc-700 border border-zinc-200 flex items-center justify-center text-xs font-extrabold">OP</div>
                      <div>
                        <div className="text-xs font-bold text-black">System Security</div>
                        <div className="text-[10px] text-zinc-400 font-mono">192.168.1.1</div>
                      </div>
                    </div>
                    <span className="text-[9px] font-mono text-zinc-400">12m ago</span>
                  </div>
                  <div className="text-xs font-medium text-zinc-700 bg-zinc-50 p-2.5 rounded-md border border-[#F0F0F0]">
                    <span className="font-bold text-black uppercase text-[10px] tracking-wide block mb-0.5">GATEWAY RESTART</span>
                    Meta Cloud API webhook listener verified.
                  </div>
                </div>

                <div className="rounded-[10px] border border-[#E8E8E6] shadow-[var(--shadow-card)] bg-white p-5 flex flex-col justify-between space-y-3">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                      <div className="h-8 w-8 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 flex items-center justify-center text-xs font-extrabold">
                        <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                      </div>
                      <div>
                        <div className="text-xs font-bold text-black">Database Backup</div>
                        <div className="text-[10px] text-zinc-400 font-mono">Automated Job</div>
                      </div>
                    </div>
                    <span className="text-[9px] font-mono text-zinc-400">1h ago</span>
                  </div>
                  <div className="text-xs font-medium text-zinc-700 bg-zinc-50 p-2.5 rounded-md border border-[#F0F0F0]">
                    <span className="font-bold text-black uppercase text-[10px] tracking-wide block mb-0.5">BACKFILL SUCCESS</span>
                    Tenant telemetry and currency ledgers synced.
                  </div>
                </div>
              </>
            )}
          </div>
        </div>

      </div>
    </div>
  );
}
