'use client';

import { motion } from 'framer-motion';
import { 
  Sparkles, 
  Clock, 
  ShieldCheck, 
  BarChart3,
  Calendar,
  Eye,
  CheckCircle2
} from 'lucide-react';

interface CampaignsSectionProps {
  brandName?: string;
}

export function CampaignsSection({ brandName = 'WhatsOmni' }: CampaignsSectionProps) {
  const cards = [
    {
      icon: Clock,
      title: 'Send on your schedule',
      desc: 'Deliver immediate announcements, schedule campaigns for maximum impact, or establish recurring broadcasts.',
      widget: (
        <div className="border border-[#E8E8E6] rounded-xl p-4 bg-[#FAFAFA]/50 space-y-3.5 text-left text-[10px]">
          <span className="text-[8px] font-bold text-zinc-400 uppercase tracking-wider block">Scheduling Mode</span>
          <div className="space-y-2">
            <div className="flex items-center justify-between p-2 border border-[#E8E8E6] bg-white rounded-md shadow-3xs">
              <span className="font-semibold text-zinc-550">Send Immediately</span>
              <span className="w-2.5 h-2.5 rounded-full bg-zinc-200" />
            </div>
            <div className="flex items-center justify-between p-2 border border-[#E8E8E6] bg-white rounded-md shadow-3xs">
              <span className="font-bold text-black">Scheduled Broadcast</span>
              <div className="flex items-center gap-1 bg-[#E8FDE8] border border-[#4AE54A]/30 px-1.5 py-0.5 rounded text-[8px] font-bold text-black">
                <Calendar className="w-2.5 h-2.5" />
                <span>Tomorrow 9 AM</span>
              </div>
            </div>
            <div className="flex items-center justify-between p-2 border border-[#E8E8E6] bg-white rounded-md shadow-3xs">
              <span className="font-semibold text-zinc-555">Recurring Interval</span>
              <span className="w-2.5 h-2.5 rounded-full bg-zinc-200" />
            </div>
          </div>
        </div>
      )
    },
    {
      icon: ShieldCheck,
      title: 'Stay compliant',
      desc: 'Automatic opt-out management enforces compliance guidelines instantly across every broadcast channel.',
      widget: (
        <div className="border border-[#E8E8E6] rounded-xl p-4 bg-[#FAFAFA]/50 space-y-3.5 text-left text-[10px]">
          <span className="text-[8px] font-bold text-zinc-400 uppercase tracking-wider block">Compliance Protection</span>
          <div className="p-3 border border-[#E8E8E6] bg-white rounded-lg shadow-3xs space-y-2.5">
            <div className="flex items-center justify-between">
              <span className="font-bold text-black">Opt-Out Safeguard</span>
              <span className="text-[8px] font-bold bg-[#E8FDE8] border border-[#4AE54A]/30 px-2 py-0.5 rounded text-black uppercase tracking-wider">
                Active
              </span>
            </div>
            <p className="text-[9px] text-zinc-500 leading-normal">
              Reply <span className="font-black text-black">"STOP"</span> to unsubscribe. Opt-outs are automatically filtered to prevent compliance penalties.
            </p>
          </div>
        </div>
      )
    },
    {
      icon: BarChart3,
      title: 'See what\'s working',
      desc: 'Monitor delivery, read, and message reply metrics update in real-time as campaign results stream in.',
      widget: (
        <div className="border border-[#E8E8E6] rounded-xl p-4 bg-[#FAFAFA]/50 space-y-3 text-left text-[10px]">
          <span className="text-[8px] font-bold text-zinc-400 uppercase tracking-wider block">Real-time Analytics</span>
          <div className="space-y-2">
            <div className="space-y-1">
              <div className="flex justify-between text-[9px] font-bold">
                <span className="text-zinc-500">Delivery Success</span>
                <span className="text-black font-black">99.8%</span>
              </div>
              <div className="w-full bg-zinc-200 h-1 rounded-full overflow-hidden">
                <div className="bg-[#4AE54A] h-full rounded-full w-[99.8%]" />
              </div>
            </div>
            <div className="space-y-1">
              <div className="flex justify-between text-[9px] font-bold">
                <span className="text-zinc-500">Read Rate</span>
                <span className="text-black font-black">87.2%</span>
              </div>
              <div className="w-full bg-zinc-200 h-1 rounded-full overflow-hidden">
                <div className="bg-[#4AE54A] h-full rounded-full w-[87.2%]" />
              </div>
            </div>
          </div>
        </div>
      )
    }
  ];

  return (
    <section id="campaigns" className="relative bg-white py-20 sm:py-24 border-b border-[#E8E8E6] overflow-hidden">
      
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
          className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[900px] h-[550px] rounded-full opacity-40"
          style={{
            background: 'radial-gradient(circle at 50% 50%, rgba(74,229,74,0.14) 0%, rgba(200,250,200,0.18) 45%, transparent 75%)',
            filter: 'blur(90px)',
          }}
        />
      </div>

      <div className="max-w-[1140px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center">
        
        {/* Section Header */}
        <motion.div 
          initial={{ opacity: 0, y: 16 }}
          whileInView={{ opacity: 1, y: 0 }}
          viewport={{ once: true }}
          transition={{ duration: 0.5 }}
          className="space-y-5 mb-16 max-w-2xl mx-auto"
        >
          {/* Section Tag */}
          <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white border border-[#E8E8E6] text-xs font-bold text-[#0A0A0A] tracking-wider uppercase shadow-2xs">
            <Sparkles className="w-3.5 h-3.5 text-[#0A0A0A]" />
            <span>Broadcast Campaigns</span>
          </div>

          {/* Heading */}
          <h2 className="text-3xl sm:text-4xl font-black text-[#0A0A0A] tracking-tight leading-tight">
            Reach the Right People, <br />
            at the Right Time
          </h2>
        </motion.div>

        {/* 3-Column Feature Cards Layout with Hairline Separators */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-8 md:gap-0 text-left md:divide-x md:divide-[#E8E8E6]">
          {cards.map((card, idx) => {
            const Icon = card.icon;
            return (
              <motion.div
                key={idx}
                initial={{ opacity: 0, y: 16 }}
                whileInView={{ opacity: 1, y: 0 }}
                viewport={{ once: true }}
                transition={{ duration: 0.5, delay: idx * 0.1 }}
                className="flex flex-col justify-between space-y-6 px-4 lg:px-8 py-2.5"
              >
                <div className="space-y-4">
                  {/* Icon wrapper */}
                  <div className="w-9 h-9 rounded-xl bg-[#FAF9F6] border border-[#E8E8E6] flex items-center justify-center text-[#0A0A0A] shadow-2xs">
                    <Icon className="w-5 h-5 stroke-[1.8]" />
                  </div>
                  
                  {/* Title & Desc */}
                  <div className="space-y-2">
                    <h3 className="text-base sm:text-lg font-black text-[#0A0A0A] tracking-tight">
                      {card.title}
                    </h3>
                    <p className="text-xs sm:text-sm text-[#4B4B4B] leading-relaxed font-normal">
                      {card.desc}
                    </p>
                  </div>
                </div>

                {/* Integrated Feature Mockup Widget */}
                <div className="pt-2">
                  {card.widget}
                </div>
              </motion.div>
            );
          })}
        </div>

      </div>
    </section>
  );
}
