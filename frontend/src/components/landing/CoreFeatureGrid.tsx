'use client';

import { motion } from 'framer-motion';
import { 
  MessageSquare, 
  Bot, 
  Brain, 
  GitBranch, 
  Users, 
  Megaphone, 
  Calendar, 
  ShoppingCart, 
  ShieldCheck
} from 'lucide-react';

interface FeatureItem {
  icon: React.ComponentType<any>;
  title: string;
  desc: string;
}

interface CoreFeatureGridProps {
  brandName?: string;
}

export function CoreFeatureGrid({ brandName = 'WhatsOmni' }: CoreFeatureGridProps) {
  const features: FeatureItem[] = [
    {
      icon: MessageSquare,
      title: 'Unified Inbox',
      desc: 'Every channel routed into one single, real-time thread view.',
    },
    {
      icon: Bot,
      title: 'AI Agents',
      desc: 'Qualify leads and answer customer inquiries autonomously 24/7.',
    },
    {
      icon: Brain,
      title: 'Smart Knowledge Base',
      desc: 'Grounded AI agents that answer accurately from your actual content.',
    },
    {
      icon: GitBranch,
      title: 'Visual Flow Builder',
      desc: 'Automate complex conversations visually with zero code required.',
    },
    {
      icon: Users,
      title: 'Built-in CRM',
      desc: 'Manage contact profiles, custom tags, and visual deal pipelines.',
    },
    {
      icon: Megaphone,
      title: 'Omnichannel Campaigns',
      desc: 'Schedule and launch recurring broadcasts across all channels.',
    },
    {
      icon: Calendar,
      title: 'Appointment Booking',
      desc: 'Let customers book sessions and confirm details automatically.',
    },
    {
      icon: ShoppingCart,
      title: 'Store Integrations',
      desc: 'Recover abandoned checkout carts and sync order details.',
    },
    {
      icon: ShieldCheck,
      title: 'Team & Access Roles',
      desc: 'Invite your support team with customized workspace permissions.',
    },
  ];

  return (
    <section id="features" className="relative bg-white pt-20 pb-20 sm:pt-28 sm:pb-28 border-t border-b border-[#E8E8E6] overflow-hidden">
      
      {/* Subtle Premium Blueprint Cross Grid Backdrop Pattern in Light Green Shade */}
      <div 
        className="absolute inset-0 pointer-events-none z-0 opacity-70" 
        style={{
          backgroundImage: 'linear-gradient(to right, #E2FDE2 1.2px, transparent 1.2px), linear-gradient(to bottom, #E2FDE2 1.2px, transparent 1.2px)',
          backgroundSize: '4rem 4rem'
        }} 
      />

      {/* Luminous, Soft Neon Green Spotlight Glow (Accent Touch) */}
      <div className="absolute inset-0 pointer-events-none z-0">
        <div
          className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[900px] h-[550px] rounded-full opacity-45"
          style={{
            background: 'radial-gradient(circle at 50% 50%, rgba(74,229,74,0.12) 0%, rgba(200,250,200,0.16) 45%, transparent 75%)',
            filter: 'blur(80px)',
          }}
        />
      </div>

      <div className="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        
        {/* Section Header (Core Capabilities Tag Removed) */}
        <motion.div 
          initial={{ opacity: 0, y: 16 }}
          whileInView={{ opacity: 1, y: 0 }}
          viewport={{ once: true }}
          transition={{ duration: 0.5 }}
          className="text-center max-w-2xl mx-auto mb-16 sm:mb-20"
        >
          <h2 className="text-3xl sm:text-4xl font-black text-[#0A0A0A] tracking-tight leading-tight">
            Everything You Need to Convert Leads
          </h2>
        </motion.div>

        {/* 3-Column Hairline-Separated Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 border-t border-l border-[#E8E8E6] bg-white/40 backdrop-blur-xs">
          {features.map((feature, idx) => {
            const Icon = feature.icon;
            return (
              <motion.div
                key={feature.title}
                initial={{ opacity: 0, y: 14 }}
                whileInView={{ opacity: 1, y: 0 }}
                viewport={{ once: true }}
                transition={{ duration: 0.45, delay: idx * 0.05 }}
                whileHover={{ scale: 1.015, y: -2, zIndex: 10 }}
                className="border-r border-b border-[#E8E8E6] p-8 sm:p-10 flex flex-col items-start space-y-4 bg-white/50 hover:bg-white hover:shadow-[0_8px_30px_rgba(0,0,0,0.03)] transition-all duration-300 group"
              >
                {/* Icon Container in Light Mode */}
                <div className="w-9 h-9 rounded-xl bg-[#FAF9F6] border border-[#E8E8E6] flex items-center justify-center text-[#0A0A0A] group-hover:bg-[#E8FDE8] group-hover:border-[#4AE54A]/40 group-hover:text-[#0A0A0A] transition-all duration-300 shadow-2xs">
                  <Icon className="w-5 h-5 stroke-[1.8]" />
                </div>
                
                <div className="space-y-2">
                  <h3 className="text-base sm:text-lg font-black text-[#0A0A0A] tracking-tight group-hover:text-[#4AE54A] transition-colors">
                    {feature.title}
                  </h3>
                  <p className="text-xs sm:text-sm text-[#4B4B4B] leading-relaxed font-normal">
                    {feature.desc}
                  </p>
                </div>
              </motion.div>
            );
          })}
        </div>

      </div>
    </section>
  );
}
