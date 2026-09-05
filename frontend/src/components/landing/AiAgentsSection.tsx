'use client';

import { motion } from 'framer-motion';
import { 
  CheckCircle2, 
  Sparkles,
  Bot
} from 'lucide-react';

interface AiAgentsSectionProps {
  brandName?: string;
}

export function AiAgentsSection({ brandName = 'WhatsOmni' }: AiAgentsSectionProps) {
  const conversation = [
    { 
      sender: 'customer', 
      text: 'Hi, I have been feeling a sharp chest pain since morning.', 
      time: '10:05 AM' 
    },
    { 
      sender: 'agent', 
      text: 'I understand that can be concerning. Grounded in our clinic directory, I recommend booking an immediate session with our Cardiologist, Dr. Elena.', 
      time: '10:06 AM' 
    },
    { 
      sender: 'customer', 
      text: 'Are there slots open tomorrow?', 
      time: '10:06 AM' 
    },
    { 
      sender: 'agent', 
      text: 'Yes! Dr. Elena has open appointments tomorrow at 11:00 AM and 3:00 PM. Would you like me to reserve the 11:00 AM slot for you?', 
      time: '10:07 AM' 
    }
  ];

  const bullets = [
    'Set up an agent for any channel — it answers using your own knowledge base, not generic guesses',
    'Automatically hands off to a real person when a conversation needs a human touch',
    'Outbound agents can reach out first to follow up on leads, remind about bookings, or re-engage',
    'Full transparency — always see exactly what the AI said and why',
  ];

  return (
    <section id="ai-agents" className="relative bg-white py-20 sm:py-24 border-b border-[#E8E8E6] overflow-hidden">
      
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

      <div className="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-8 items-center">
          
          {/* Left Column: Direct Customer & AI Chat bubbles (Reduced Max-Width to 480px for a compact look) */}
          <motion.div 
            initial={{ opacity: 0, y: 20 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.6 }}
            className="lg:col-span-7 flex flex-col gap-4 w-full max-w-[480px] mx-auto lg:mx-0 order-2 lg:order-1"
          >
            {conversation.map((msg, index) => {
              const isCustomer = msg.sender === 'customer';
              return (
                <motion.div
                  key={index}
                  initial={{ opacity: 0, y: 8 }}
                  whileInView={{ opacity: 1, y: 0 }}
                  viewport={{ once: true }}
                  transition={{ duration: 0.45, delay: index * 0.1 }}
                  className={`flex gap-3 max-w-[90%] ${isCustomer ? 'mr-auto' : 'ml-auto flex-row-reverse'}`}
                >
                  {/* Left avatar icon indicator for bot */}
                  {!isCustomer && (
                    <div className="w-8 h-8 rounded-full bg-[#E8FDE8] border border-[#4AE54A]/30 flex items-center justify-center text-xs font-bold shrink-0 shadow-2xs">
                      <Bot className="w-4 h-4 text-black" />
                    </div>
                  )}
                  {isCustomer && (
                    <div className="w-8 h-8 rounded-full bg-zinc-100 border border-zinc-200 flex items-center justify-center text-[10px] font-bold text-zinc-500 shrink-0 shadow-2xs">
                      C
                    </div>
                  )}
                  <div className="flex flex-col">
                    <div
                      className={`p-3.5 rounded-2xl text-xs leading-relaxed shadow-2xs ${
                        isCustomer 
                          ? 'bg-[#F5F5F5] text-black border border-[#E8E8E6] rounded-tl-none' 
                          : 'bg-[#E8FDE8] border border-[#4AE54A]/30 text-[#0A0A0A] rounded-tr-none font-semibold'
                      }`}
                    >
                      {msg.text}
                    </div>
                    <span className={`text-[8px] text-zinc-400 mt-1 ${isCustomer ? 'text-left' : 'text-right'}`}>
                      {msg.time}
                    </span>
                  </div>
                </motion.div>
              );
            })}
          </motion.div>

          {/* Right Column: Description & Bullet Points (5 Columns) */}
          <motion.div 
            initial={{ opacity: 0, y: 16 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.5 }}
            className="lg:col-span-5 flex flex-col items-start text-left space-y-6 order-1 lg:order-2"
          >
            {/* Section Tag */}
            <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white border border-[#E8E8E6] text-xs font-bold text-[#0A0A0A] tracking-wider uppercase shadow-2xs">
              <Sparkles className="w-3.5 h-3.5 text-[#0A0A0A]" />
              <span>AI Agents</span>
            </div>

            {/* Heading */}
            <h2 className="text-3xl sm:text-4xl font-black text-[#0A0A0A] tracking-tight leading-tight">
              Let AI Handle the First <br />
              (or Every) Reply
            </h2>

            {/* Bullet List */}
            <ul className="space-y-4 pt-2">
              {bullets.map((bullet, idx) => (
                <li key={idx} className="flex items-start gap-3">
                  <div className="w-5 h-5 rounded-full bg-[#E8FDE8] border border-[#4AE54A]/30 flex items-center justify-center shrink-0 mt-0.5 shadow-2xs">
                    <CheckCircle2 className="w-3.5 h-3.5 text-[#0A0A0A]" />
                  </div>
                  <span className="text-xs sm:text-sm text-[#0A0A0A] font-semibold leading-relaxed">
                    {bullet}
                  </span>
                </li>
              ))}
            </ul>
          </motion.div>

        </div>
      </div>
    </section>
  );
}
