'use client';

import { useEffect, useState, useMemo } from 'react';
import { motion } from 'framer-motion';
import { Check, Sparkles, AlertCircle } from 'lucide-react';
import { fetchWithCsrf } from '@/lib/api';

interface PlanPriceDetails {
  stripe_price_id: string;
  amount: number;
  interval: string;
  currency_code: string;
  currency_symbol: string;
}

interface PlanDetails {
  id: number;
  name: string;
  description: string;
  max_team_members: number;
  max_campaigns: number;
  max_integrations: number;
  own_crm_access: boolean;
  max_channels: number;
  max_automations: number;
  prices: PlanPriceDetails[];
}

export function PricingSection() {
  const [plans, setPlans] = useState<PlanDetails[]>([]);
  const [billingInterval, setBillingInterval] = useState<'month' | 'year'>('month');
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<boolean>(false);

  useEffect(() => {
    async function loadPlans() {
      try {
        const response = await fetchWithCsrf('/public/plans');
        if (response.ok) {
          const data = await response.json();
          if (data && data.plans && data.plans.length > 0) {
            setPlans(data.plans);
          } else {
            setError(true);
          }
        } else {
          setError(true);
        }
      } catch {
        setError(true);
      } finally {
        setLoading(false);
      }
    }
    loadPlans();
  }, []);

  // Standard premium fallback plans if DB plans are empty or seeding
  const fallbackPlans: PlanDetails[] = [
    {
      id: 1,
      name: 'Free',
      description: 'Ideal for getting started with visual messaging',
      max_team_members: 1,
      max_campaigns: 1,
      max_integrations: 1,
      own_crm_access: false,
      max_channels: 1,
      max_automations: 2,
      prices: [
        { stripe_price_id: 'free_m', amount: 0, interval: 'month', currency_code: 'USD', currency_symbol: '$' },
        { stripe_price_id: 'free_y', amount: 0, interval: 'year', currency_code: 'USD', currency_symbol: '$' }
      ]
    },
    {
      id: 2,
      name: 'Starter',
      description: 'Perfect for growing teams automating customer support',
      max_team_members: 3,
      max_campaigns: 5,
      max_integrations: 3,
      own_crm_access: true,
      max_channels: 3,
      max_automations: 5,
      prices: [
        { stripe_price_id: 'starter_m', amount: 29, interval: 'month', currency_code: 'USD', currency_symbol: '$' },
        { stripe_price_id: 'starter_y', amount: 23 * 12, interval: 'year', currency_code: 'USD', currency_symbol: '$' }
      ]
    },
    {
      id: 3,
      name: 'Pro Enterprise',
      description: 'Advanced custom controls for high-volume operations',
      max_team_members: 15,
      max_campaigns: 50,
      max_integrations: 10,
      own_crm_access: true,
      max_channels: 10,
      max_automations: 30,
      prices: [
        { stripe_price_id: 'pro_m', amount: 99, interval: 'month', currency_code: 'USD', currency_symbol: '$' },
        { stripe_price_id: 'pro_y', amount: 79 * 12, interval: 'year', currency_code: 'USD', currency_symbol: '$' }
      ]
    }
  ];

  const activePlans = plans.length > 0 ? plans : fallbackPlans;

  const maxSavingsPercent = useMemo(() => {
    let maxSaved = 0;
    for (const p of activePlans) {
      const m = p.prices?.find(pr => pr.interval === 'month');
      const y = p.prices?.find(pr => pr.interval === 'year');
      if (m && y && m.amount > 0) {
        const mAnnual = m.amount * 12;
        if (y.amount < mAnnual) {
          const saved = Math.round(((mAnnual - y.amount) / mAnnual) * 100);
          if (saved > maxSaved) maxSaved = saved;
        }
      }
    }
    return maxSaved;
  }, [activePlans]);

  return (
    <section id="pricing" className="relative bg-white py-20 sm:py-24 border-b border-[#E8E8E6] overflow-hidden">
      
      {/* Subtle Premium Blueprint Cross Grid Backdrop Pattern */}
      <div 
        className="absolute inset-0 pointer-events-none z-0 opacity-60" 
        style={{
          backgroundImage: 'linear-gradient(to right, #E2FDE2 1.2px, transparent 1.2px), linear-gradient(to bottom, #E2FDE2 1.2px, transparent 1.2px)',
          backgroundSize: '4rem 4rem'
        }} 
      />

      {/* Luminous, Soft Neon Green Spotlight Glow */}
      <div className="absolute inset-0 pointer-events-none z-0">
        <div
          className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[1000px] h-[600px] rounded-full opacity-45"
          style={{
            background: 'radial-gradient(circle at 50% 50%, rgba(74,229,74,0.15) 0%, rgba(200,250,200,0.18) 45%, transparent 75%)',
            filter: 'blur(90px)',
          }}
        />
      </div>

      <div className="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center">
        
        {/* Section Header */}
        <motion.div 
          initial={{ opacity: 0, y: 16 }}
          whileInView={{ opacity: 1, y: 0 }}
          viewport={{ once: true }}
          transition={{ duration: 0.5 }}
          className="space-y-5 mb-10 max-w-2xl mx-auto"
        >
          {/* Section Tag */}
          <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white border border-[#E8E8E6] text-xs font-bold text-[#0A0A0A] tracking-wider uppercase shadow-2xs">
            <Sparkles className="w-3.5 h-3.5 text-[#0A0A0A]" />
            <span>Pricing Options</span>
          </div>

          {/* Heading */}
          <h2 className="text-3xl sm:text-4xl font-black text-[#0A0A0A] tracking-tight leading-tight">
            Simple Pricing. Start Free.
          </h2>
        </motion.div>

        {/* Monthly / Yearly Toggle */}
        <div className="flex items-center justify-center gap-3.5 mb-14">
          <span className={`text-xs font-bold transition-colors ${billingInterval === 'month' ? 'text-black' : 'text-zinc-400'}`}>
            Monthly Billing
          </span>
          
          <button 
            onClick={() => setBillingInterval(prev => prev === 'month' ? 'year' : 'month')}
            className="w-11 h-6 rounded-full bg-black relative p-0.5 transition-colors duration-200 focus:outline-none cursor-pointer"
          >
            <div 
              className={`w-5 h-5 rounded-full bg-white transition-transform duration-200 ${
                billingInterval === 'year' ? 'translate-x-5' : 'translate-x-0'
              }`}
            />
          </button>
          
          <div className="flex items-center gap-1.5">
            <span className={`text-xs font-bold transition-colors ${billingInterval === 'year' ? 'text-black' : 'text-zinc-400'}`}>
              Yearly Billing
            </span>
            {maxSavingsPercent > 0 && (
              <span className="text-[9px] font-black uppercase tracking-wider text-emerald-600 bg-emerald-50 border border-emerald-100 px-1.5 py-0.5 rounded">
                Save {maxSavingsPercent}%
              </span>
            )}
          </div>
        </div>

        {/* Pricing Cards Grid */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-8 items-stretch max-w-[1040px] mx-auto text-left">
          {activePlans.map((plan, index) => {
            // Find price detail matching active interval
            const price = plan.prices.find(p => p.interval === billingInterval) || plan.prices[0];
            const displayAmount = billingInterval === 'year' ? Math.round(price.amount / 12) : price.amount;
            const isStarred = plan.name.toLowerCase().includes('starter');

            return (
              <motion.div
                key={plan.id}
                initial={{ opacity: 0, y: 20 }}
                whileInView={{ opacity: 1, y: 0 }}
                viewport={{ once: true }}
                transition={{ duration: 0.5, delay: index * 0.1 }}
                className={`relative flex flex-col justify-between p-6 sm:p-8 rounded-2xl bg-white border transition-all duration-300 ${
                  isStarred 
                    ? 'border-black shadow-lg scale-102 ring-1 ring-black' 
                    : 'border-[#E8E8E6] shadow-3xs hover:border-zinc-350'
                }`}
              >
                {/* Visual Accent Badge */}
                {isStarred && (
                  <span className="absolute -top-3 left-6 inline-flex items-center gap-1 text-[8.5px] font-black uppercase bg-[#E8FDE8] border border-[#4AE54A] px-2.5 py-0.5 rounded-full text-black shadow-2xs">
                    ★ Most Popular
                  </span>
                )}

                <div className="space-y-6">
                  {/* Plan Identification */}
                  <div>
                    <h3 className="text-lg font-black text-black tracking-tight">{plan.name}</h3>
                    <p className="text-xs text-zinc-400 font-semibold mt-1 min-h-[32px]">{plan.description}</p>
                  </div>

                  {/* Plan Price */}
                  <div className="flex items-baseline gap-1">
                    <span className="text-3xl sm:text-4xl font-black text-black">
                      {price.currency_symbol}{displayAmount}
                    </span>
                    <span className="text-xs text-zinc-400 font-semibold uppercase tracking-wider">
                      / month
                    </span>
                  </div>

                  {/* Plan Limits features */}
                  <ul className="space-y-3.5 border-t border-[#E8E8E6] pt-6 text-xs font-semibold text-black">
                    <li className="flex items-center gap-2.5">
                      <Check className="w-4 h-4 text-[#4AE54A] shrink-0" />
                      <span>{plan.max_team_members} Team Member{plan.max_team_members > 1 && 's'}</span>
                    </li>
                    <li className="flex items-center gap-2.5">
                      <Check className="w-4 h-4 text-[#4AE54A] shrink-0" />
                      <span>{plan.max_campaigns} Broadcast Campaign{plan.max_campaigns > 1 && 's'}</span>
                    </li>
                    <li className="flex items-center gap-2.5">
                      <Check className="w-4 h-4 text-[#4AE54A] shrink-0" />
                      <span>{plan.max_channels} Connected Channel{plan.max_channels > 1 && 's'}</span>
                    </li>
                    <li className="flex items-center gap-2.5">
                      <Check className="w-4 h-4 text-[#4AE54A] shrink-0" />
                      <span>{plan.max_automations} Flow Automation{plan.max_automations > 1 && 's'}</span>
                    </li>
                    <li className="flex items-center gap-2.5">
                      <Check className="w-4 h-4 text-[#4AE54A] shrink-0" />
                      <span>{plan.max_integrations} App Integration{plan.max_integrations > 1 && 's'}</span>
                    </li>
                    <li className="flex items-center gap-2.5">
                      <Check className="w-4 h-4 text-[#4AE54A] shrink-0" />
                      <span>{plan.own_crm_access ? 'Full CRM Pipeline access' : 'No CRM Pipeline access'}</span>
                    </li>
                  </ul>
                </div>

                {/* Pricing Card CTA Button */}
                <div className="pt-8">
                  <a
                    href="/register"
                    className={`w-full py-3 rounded-xl font-black text-center text-xs transition-all uppercase tracking-wider block shadow-2xs ${
                      isStarred 
                        ? 'bg-black text-white hover:bg-zinc-900' 
                        : 'bg-[#FAF9F6] text-black border border-[#E8E8E6] hover:bg-[#F4F4F2]'
                    }`}
                  >
                    {plan.name.toLowerCase().includes('free') ? 'Start Free' : 'Start Free Trial'}
                  </a>
                </div>
              </motion.div>
            );
          })}
        </div>

        {/* Small Print disclaimer */}
        <p className="text-[10px] text-zinc-400 font-bold mt-12 max-w-lg mx-auto leading-relaxed uppercase tracking-wider">
          All plans include the shared inbox, AI agents, and campaigns. Upgrade or downgrade anytime from your billing settings.
        </p>

      </div>
    </section>
  );
}
