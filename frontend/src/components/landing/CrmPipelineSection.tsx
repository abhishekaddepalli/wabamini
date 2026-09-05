'use client';

import { motion } from 'framer-motion';
import { 
  CheckCircle2, 
  Sparkles,
  Mail,
  Phone,
  Tag,
  History
} from 'lucide-react';

interface CrmPipelineSectionProps {
  brandName?: string;
}

export function CrmPipelineSection({ brandName = 'WhatsOmni' }: CrmPipelineSectionProps) {
  const bullets = [
    'Every contact has a full history — messages, notes, tags, and deals in one place',
    'Drag deals through a pipeline you fully customize to match your team workflow',
    'Duplicate contacts are caught and merged automatically to keep leads clean'
  ];

  const activities = [
    { label: 'Inbound message from WhatsApp', time: '10:05 AM', status: 'completed' },
    { label: 'AI Auto-Tagged as "warm_lead"', time: '10:06 AM', status: 'completed' },
    { label: 'Deal created: $5,400 Value', time: '10:06 AM', status: 'completed' },
    { label: 'Google Calendar demo scheduled', time: 'Tomorrow 3:00 PM', status: 'pending' }
  ];

  return (
    <section id="crm-pipeline" className="relative bg-white py-14 sm:py-16 overflow-hidden">
      
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
          className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[1000px] h-[500px] rounded-full opacity-45"
          style={{
            background: 'radial-gradient(circle at 50% 50%, rgba(74,229,74,0.15) 0%, rgba(200,250,200,0.18) 45%, transparent 75%)',
            filter: 'blur(90px)',
          }}
        />
      </div>

      <div className="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-8 items-center">
          
          {/* Left Column: CRM Customer Profile & Activity History Cards (7 Columns) */}
          <motion.div 
            initial={{ opacity: 0, y: 20 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.6 }}
            className="lg:col-span-7 flex flex-col sm:flex-row gap-4 items-stretch justify-start w-full"
          >
            
            {/* Card A: Customer Profile Details */}
            <div className="flex-grow bg-white border border-[#E8E8E6] rounded-xl shadow-lg p-5 flex flex-col justify-between text-left space-y-4 max-w-[280px] mx-auto sm:mx-0">
              <div className="flex items-center gap-3">
                <div className="w-9 h-9 rounded-full bg-zinc-105 border border-zinc-200 flex items-center justify-center font-bold text-zinc-650 text-sm">
                  MB
                </div>
                <div>
                  <h4 className="text-xs font-black text-black leading-tight">Marcus Brody</h4>
                  <span className="inline-flex items-center gap-1 text-[8px] font-black uppercase text-[#4AE54A] bg-[#E8FDE8] border border-[#4AE54A]/30 px-2 py-0.5 rounded mt-0.5">
                    Lead Synced
                  </span>
                </div>
              </div>

              <div className="space-y-2 border-t border-b border-[#E8E8E6] py-3 text-[10px]">
                <div className="flex items-center gap-2 text-zinc-600">
                  <Mail className="w-3.5 h-3.5 text-zinc-400" />
                  <span className="truncate">marcus.brody@example.com</span>
                </div>
                <div className="flex items-center gap-2 text-zinc-600">
                  <Phone className="w-3.5 h-3.5 text-zinc-400" />
                  <span>+1 (555) 019-2834</span>
                </div>
                <div className="flex items-center gap-2 text-zinc-600">
                  <Tag className="w-3.5 h-3.5 text-zinc-400" />
                  <span className="bg-zinc-100 px-1.5 py-0.2 rounded border border-zinc-200 font-bold">active_prospect</span>
                </div>
              </div>

              <div className="flex justify-between items-center text-[10px]">
                <span className="text-zinc-400 font-semibold">Deal Value:</span>
                <span className="font-black text-black">$5,400</span>
              </div>
            </div>

            {/* Card B: Interaction History Log */}
            <div className="flex-grow bg-white border border-[#E8E8E6] rounded-xl shadow-lg p-5 flex flex-col justify-between text-left space-y-3 max-w-[290px] mx-auto sm:mx-0">
              <div className="flex items-center gap-2 pb-2 border-b border-[#E8E8E6]">
                <History className="w-3.5 h-3.5 text-black" />
                <span className="text-[10px] font-bold text-black uppercase tracking-wider">Activity History</span>
              </div>

              <div className="space-y-3 flex-1 flex flex-col justify-center">
                {activities.map((act, index) => (
                  <div key={index} className="flex gap-2.5 items-start">
                    <div className="mt-0.5 shrink-0">
                      {act.status === 'completed' ? (
                        <div className="w-3.5 h-3.5 rounded-full bg-[#E8FDE8] border border-[#4AE54A]/30 flex items-center justify-center">
                          <CheckCircle2 className="w-2.5 h-2.5 text-[#4AE54A]" />
                        </div>
                      ) : (
                        <div className="w-3.5 h-3.5 rounded-full border border-zinc-300 bg-white" />
                      )}
                    </div>
                    <div className="min-w-0">
                      <p className="text-[9px] font-semibold text-black leading-tight truncate max-w-[190px]">
                        {act.label}
                      </p>
                      <span className="text-[7.5px] text-zinc-400 block mt-0.5">{act.time}</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>

          </motion.div>

          {/* Right Column: Description & Bullet Points (5 Columns) */}
          <motion.div 
            initial={{ opacity: 0, y: 16 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.5 }}
            className="lg:col-span-5 flex flex-col items-start text-left space-y-6"
          >
            {/* Section Tag */}
            <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white border border-[#E8E8E6] text-xs font-bold text-[#0A0A0A] tracking-wider uppercase shadow-2xs">
              <Sparkles className="w-3.5 h-3.5 text-[#0A0A0A]" />
              <span>CRM & Pipeline</span>
            </div>

            {/* Heading */}
            <h2 className="text-3xl sm:text-4xl font-black text-[#0A0A0A] tracking-tight leading-tight">
              Know Every Customer, <br />
              Not Just Every Message
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
