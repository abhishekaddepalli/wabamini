'use client';

import { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { Link } from '@/i18n/routing';
import Image from 'next/image';
import { 
  ArrowRight, 
  CheckCheck,
  Sparkles,
  ChevronRight
} from 'lucide-react';
import { Button } from '@/components/ui/button';

interface MessagePair {
  id: string;
  channel: 'whatsapp' | 'instagram' | 'telegram';
  customer: {
    name: string;
    avatar: string;
    channelIcon: string;
    channelName: string;
    text: string;
    time: string;
    bubbleClass: string;
    textClass: string;
  };
  aiResponse: {
    name: string;
    avatar: string;
    text: string;
    time: string;
    bubbleClass: string;
    textClass: string;
    checkColorClass: string;
  };
}

interface HeroSectionProps {
  brandName?: string;
}

export function HeroSection({ brandName = 'WhatsOmni' }: HeroSectionProps) {
  // Authentic Platform Specific Chat Bubbles
  const messagePairs: MessagePair[] = [
    {
      id: 'pair-1',
      channel: 'instagram',
      customer: {
        name: 'Sophia Chen',
        avatar: 'https://images.unsplash.com/photo-1517841905240-472988babdf9?w=100&auto=format&fit=crop&q=80',
        channelIcon: '/channels/instagram.svg',
        channelName: 'Instagram DM',
        text: 'Hey! Is the 4-bedroom luxury villa in Grand Oaks available for a private walkthrough?',
        time: '02:20 PM',
        bubbleClass: 'bg-[#EFEFEF] rounded-2xl rounded-tl-xs',
        textClass: 'text-[#000000]',
      },
      aiResponse: {
        name: 'Grand Oaks AI Agent',
        avatar: '/logo.svg',
        text: 'Walkthrough reserved for 5:00 PM today! Senior Agent Mark assigned & digital brochure dispatched.',
        time: '02:20 PM',
        bubbleClass: 'bg-gradient-to-r from-[#8B5CF6] via-[#A855F7] to-[#3B82F6] rounded-2xl rounded-tr-xs shadow-md',
        textClass: 'text-white',
        checkColorClass: 'text-white/90',
      },
    },
    {
      id: 'pair-2',
      channel: 'whatsapp',
      customer: {
        name: 'David Miller',
        avatar: 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=100&auto=format&fit=crop&q=80',
        channelIcon: '/channels/whatsapp.webp',
        channelName: 'WhatsApp',
        text: 'Hi! I need to book a cardiology consultation with Dr. Elena for tomorrow morning.',
        time: '09:14 AM',
        bubbleClass: 'bg-white border border-[#E8E8E6] rounded-2xl rounded-tl-xs shadow-2xs',
        textClass: 'text-[#111B21]',
      },
      aiResponse: {
        name: 'Apex Health AI',
        avatar: '/logo.svg',
        text: 'Appointment confirmed for 11:00 AM tomorrow with Dr. Elena! Confirmation SMS & map link sent.',
        time: '09:14 AM',
        bubbleClass: 'bg-[#E7FFDB] border border-[#C2EBA2] rounded-2xl rounded-tr-xs shadow-2xs',
        textClass: 'text-[#111B21]',
        checkColorClass: 'text-[#128C7E]',
      },
    },
    {
      id: 'pair-3',
      channel: 'telegram',
      customer: {
        name: 'Alex Riviera',
        avatar: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=100&auto=format&fit=crop&q=80',
        channelIcon: '/channels/telegram.webp',
        channelName: 'Telegram',
        text: 'Can your AI sync WhatsApp Official Cloud API directly into our HubSpot CRM pipeline?',
        time: '04:05 PM',
        bubbleClass: 'bg-white border border-[#E0F2FE] rounded-2xl rounded-tl-xs shadow-2xs',
        textClass: 'text-[#000000]',
      },
      aiResponse: {
        name: 'CloudScale AI Agent',
        avatar: '/logo.svg',
        text: 'Yes! Bi-directional HubSpot OAuth sync enabled. Strategy call booked for 4:30 PM tomorrow.',
        time: '04:05 PM',
        bubbleClass: 'bg-[#EEF7FE] border border-[#BAE6FD] rounded-2xl rounded-tr-xs shadow-2xs',
        textClass: 'text-[#000000]',
        checkColorClass: 'text-[#0088CC]',
      },
    },
  ];

  const [activePairIdx, setActivePairIdx] = useState(0);

  useEffect(() => {
    const timer = setInterval(() => {
      setActivePairIdx((prev) => (prev + 1) % messagePairs.length);
    }, 4500);
    return () => clearInterval(timer);
  }, []);

  const currentPair = messagePairs[activePairIdx];

  return (
    <section className="relative overflow-hidden bg-white pt-24 pb-20 sm:pt-32 sm:pb-28 border-b border-[#E8E8E6]">
      {/* Soft Luminous Neon Green Radial Glow */}
      <div className="absolute inset-0 pointer-events-none z-0">
        <div
          className="absolute -top-24 left-1/2 -translate-x-1/2 w-[1000px] sm:w-[1400px] h-[650px] sm:h-[850px] rounded-full opacity-65"
          style={{
            background: 'radial-gradient(ellipse at 50% 30%, rgba(74,229,74,0.22) 0%, rgba(200,250,200,0.28) 45%, transparent 75%)',
            filter: 'blur(90px)',
          }}
        />
      </div>

      <div className="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-10 items-center">
          
          {/* Left Column: Headline, Description & Unified CTA Bar */}
          <motion.div 
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5 }}
            className="lg:col-span-6 flex flex-col items-start text-left space-y-6 sm:space-y-7"
          >
            {/* Headline */}
            <h1 className="text-3xl sm:text-4xl lg:text-[52px] font-black tracking-tight text-[#0A0A0A] leading-[1.12]">
              Turn Every Message <br />
              Into a Customer — <br />
              <span className="relative inline-block text-[#0A0A0A]">
                All From One Inbox
                <svg
                  className="absolute left-0 -bottom-1 w-full h-3 text-[#4AE54A]/70 -z-10"
                  viewBox="0 0 100 20"
                  preserveAspectRatio="none"
                >
                  <path
                    d="M0 15 Q 50 0 100 15"
                    stroke="currentColor"
                    strokeWidth="8"
                    fill="none"
                    strokeLinecap="round"
                  />
                </svg>
              </span>
            </h1>

            {/* Description */}
            <p className="text-xs sm:text-sm text-[#6B6B6B] leading-relaxed max-w-lg font-normal">
              {brandName} unifies <span className="font-semibold text-[#0A0A0A]">WhatsApp, Instagram, Messenger, Telegram, SMS, and Email</span> into one workspace — with AI agents, a built-in CRM, campaigns, and booking, so your team never misses a lead again.
            </p>

            {/* CTA Row */}
            <div className="flex flex-col space-y-2.5 pt-1 w-full sm:w-auto">
              <div className="flex flex-wrap items-center gap-3">
                <Link href="/register">
                  <Button 
                    size="sm"
                    className="h-10 px-5.5 rounded-full bg-[#0A0A0A] hover:bg-[#262626] text-white font-extrabold text-xs sm:text-sm shadow-xs transition-all duration-200 border-0 flex items-center gap-1.5 cursor-pointer group"
                  >
                    <span>Start Free Trial</span>
                    <ArrowRight className="w-3.5 h-3.5 text-white transition-transform group-hover:translate-x-1" />
                  </Button>
                </Link>

                <Link href="/login">
                  <Button 
                    variant="outline" 
                    size="sm"
                    className="h-10 px-4.5 rounded-full bg-white hover:bg-[#FAFAFA] border-[#E8E8E6] hover:border-[#0A0A0A] text-[#0A0A0A] font-bold text-xs sm:text-sm transition-all duration-200 flex items-center gap-1 shadow-2xs hover:shadow-xs cursor-pointer"
                  >
                    <span>Log In</span>
                    <span className="text-[#6B6B6B]">→</span>
                  </Button>
                </Link>
              </div>

              {/* Micro-copy */}
              <p className="text-[11px] text-[#6B6B6B] font-medium pt-0.5">
                Free plan available · Upgrade anytime · Cancel anytime
              </p>
            </div>

            {/* Social Proof Avatar Stack */}
            <div className="pt-2 flex items-center gap-3">
              <div className="flex -space-x-2.5 overflow-hidden p-0.5">
                <img
                  className="inline-block h-8 w-8 rounded-full ring-2 ring-white shadow-sm object-cover"
                  src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=100&auto=format&fit=crop&q=80"
                  alt="User 1"
                />
                <img
                  className="inline-block h-8 w-8 rounded-full ring-2 ring-white shadow-sm object-cover"
                  src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=100&auto=format&fit=crop&q=80"
                  alt="User 2"
                />
                <img
                  className="inline-block h-8 w-8 rounded-full ring-2 ring-white shadow-sm object-cover"
                  src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=100&auto=format&fit=crop&q=80"
                  alt="User 3"
                />
                <div className="inline-block h-8 w-8 rounded-full ring-2 ring-white bg-[#0A0A0A] text-white font-bold text-[10px] flex items-center justify-center shadow-sm">
                  +5k
                </div>
              </div>

              <div className="flex items-center gap-2">
                <div className="flex items-center gap-0.5 text-amber-400 text-xs">
                  {'★'.repeat(5)}
                </div>
                <span className="text-xs font-bold text-[#0A0A0A]">4.9/5 (50k+ Messages)</span>
              </div>
            </div>

          </motion.div>

          {/* Right Column: Platform Authentic Chat Bubbles */}
          <motion.div 
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.6, delay: 0.1 }}
            className="lg:col-span-6 flex justify-center lg:justify-end"
          >
            <div className="w-full max-w-[440px] space-y-4">
              <AnimatePresence mode="wait">
                <motion.div
                  key={currentPair.id}
                  initial={{ opacity: 0, y: 16, scale: 0.98 }}
                  animate={{ opacity: 1, y: 0, scale: 1 }}
                  exit={{ opacity: 0, y: -16, scale: 0.98 }}
                  transition={{ duration: 0.45, ease: [0.16, 1, 0.3, 1] }}
                  className="space-y-3.5"
                >
                  {/* 1. Customer Incoming Chat Bubble */}
                  <div className="flex items-start gap-2.5 max-w-[90%]">
                    <div className="relative shrink-0 mt-0.5">
                      <img
                        src={currentPair.customer.avatar}
                        alt={currentPair.customer.name}
                        className="w-8 h-8 rounded-full object-cover border border-[#E8E8E6]"
                      />
                    </div>

                    <div className={`${currentPair.customer.bubbleClass} p-3.5 text-left`}>
                      <div className="mb-1">
                        <span className={`text-[11px] font-bold ${currentPair.customer.textClass}`}>
                          {currentPair.customer.name}
                        </span>
                      </div>

                      <p className={`text-xs sm:text-sm leading-relaxed font-normal ${currentPair.customer.textClass}`}>
                        "{currentPair.customer.text}"
                      </p>

                      {/* Bottom Row: Channel Logo on Left, Timestamp on Right */}
                      <div className="mt-2 flex items-center justify-between pt-1 border-t border-black/5">
                        <div className="flex items-center gap-1">
                          <img
                            src={currentPair.customer.channelIcon}
                            alt={currentPair.customer.channelName}
                            className="w-3.5 h-3.5 rounded-full object-contain"
                          />
                          <span className="text-[9px] font-semibold opacity-70">
                            {currentPair.customer.channelName}
                          </span>
                        </div>
                        <span className="text-[9px] opacity-60 font-medium">
                          {currentPair.customer.time}
                        </span>
                      </div>
                    </div>
                  </div>

                  {/* 2. AI Outgoing Response Bubble */}
                  <motion.div 
                    initial={{ opacity: 0, y: 12, scale: 0.98 }}
                    animate={{ opacity: 1, y: 0, scale: 1 }}
                    transition={{ duration: 0.45, delay: 0.15, ease: [0.16, 1, 0.3, 1] }}
                    className="flex items-start gap-2.5 max-w-[90%] ml-auto flex-row-reverse"
                  >
                    <div className="w-8 h-8 rounded-full bg-[#0A0A0A] flex items-center justify-center shrink-0 mt-0.5 border border-[#E8E8E6] shadow-2xs">
                      <Image src="/logo.svg" alt="AI Agent" width={16} height={16} className="w-4 h-4" />
                    </div>

                    <div className={`${currentPair.aiResponse.bubbleClass} p-3.5 text-left`}>
                      <div className="mb-1">
                        <span className={`text-[11px] font-bold ${currentPair.aiResponse.textClass}`}>
                          {currentPair.aiResponse.name}
                        </span>
                      </div>

                      <p className={`text-xs sm:text-sm leading-relaxed font-normal ${currentPair.aiResponse.textClass}`}>
                        {currentPair.aiResponse.text}
                      </p>

                      {/* Bottom Row: Timestamp & Double Read Ticks */}
                      <div className="mt-2 flex items-center justify-end gap-1.5 pt-1 border-t border-black/5">
                        <span className={`text-[9px] font-medium opacity-80 ${currentPair.aiResponse.textClass}`}>
                          {currentPair.aiResponse.time}
                        </span>
                        <CheckCheck className={`w-3.5 h-3.5 ${currentPair.aiResponse.checkColorClass}`} />
                      </div>
                    </div>
                  </motion.div>

                </motion.div>
              </AnimatePresence>
            </div>
          </motion.div>

        </div>
      </div>
    </section>
  );
}
