'use client';

import { useState, useEffect, useRef } from 'react';
import { motion } from 'framer-motion';
import { 
  CheckCircle2, 
  Sparkles,
  Play,
  Bot,
  Tag,
  MessageSquare,
  UserCheck,
  Briefcase
} from 'lucide-react';

interface FlowBuilderSectionProps {
  brandName?: string;
}

interface PathCoords {
  p1: string; // Inbound -> AI
  p2: string; // AI YES -> Tag
  p3: string; // AI NO -> Human Handoff
  p4: string; // Tag -> Send Message
  p5: string; // Tag -> Create Deal
}

export function FlowBuilderSection({ brandName = 'WhatsOmni' }: FlowBuilderSectionProps) {
  const bullets = [
    'Drag-and-drop builder — branch conversations based on what a customer says',
    'Automatically tag a contact, create a lead, book a meeting, or hand off to your team'
  ];

  const canvasRef = useRef<HTMLDivElement>(null);
  
  // Port Refs
  const inboundOutRef = useRef<HTMLDivElement>(null);
  const aiInRef = useRef<HTMLDivElement>(null);
  const aiYesRef = useRef<HTMLDivElement>(null);
  const aiNoRef = useRef<HTMLDivElement>(null);
  const tagInRef = useRef<HTMLDivElement>(null);
  const tagOutRef = useRef<HTMLDivElement>(null);
  const tagBottomRef = useRef<HTMLDivElement>(null);
  const msgInRef = useRef<HTMLDivElement>(null);
  const handoffInRef = useRef<HTMLDivElement>(null);
  const dealTopRef = useRef<HTMLDivElement>(null);

  const [paths, setPaths] = useState<PathCoords | null>(null);

  const updatePaths = () => {
    if (!canvasRef.current) return;
    const cRect = canvasRef.current.getBoundingClientRect();

    const getCenter = (ref: React.RefObject<HTMLDivElement | null>) => {
      if (!ref.current) return { x: 0, y: 0 };
      const r = ref.current.getBoundingClientRect();
      return {
        x: r.left + r.width / 2 - cRect.left,
        y: r.top + r.height / 2 - cRect.top
      };
    };

    const cInboundOut = getCenter(inboundOutRef);
    const cAiIn = getCenter(aiInRef);
    const cAiYes = getCenter(aiYesRef);
    const cAiNo = getCenter(aiNoRef);
    const cTagIn = getCenter(tagInRef);
    const cTagOut = getCenter(tagOutRef);
    const cTagBottom = getCenter(tagBottomRef);
    const cMsgIn = getCenter(msgInRef);
    const cHandoffIn = getCenter(handoffInRef);
    const cDealTop = getCenter(dealTopRef);

    // 1. Inbound -> AI Binary Decision
    const p1 = `M ${cInboundOut.x} ${cInboundOut.y} C ${cInboundOut.x + 60} ${cInboundOut.y}, ${cAiIn.x - 60} ${cAiIn.y}, ${cAiIn.x} ${cAiIn.y}`;

    // 2. AI YES -> Tag Contact
    const p2 = `M ${cAiYes.x} ${cAiYes.y} C ${cAiYes.x + 60} ${cAiYes.y}, ${cTagIn.x - 60} ${cTagIn.y}, ${cTagIn.x} ${cTagIn.y}`;

    // 3. AI NO -> Human Handoff (Curves down & left into Human Handoff)
    const p3 = `M ${cAiNo.x} ${cAiNo.y} C ${cAiNo.x + 60} ${cAiNo.y}, ${cAiNo.x + 60} ${cHandoffIn.y}, ${cHandoffIn.x} ${cHandoffIn.y}`;

    // 4. Tag Contact -> Send Message
    const p4 = `M ${cTagOut.x} ${cTagOut.y} C ${cTagOut.x + 60} ${cTagOut.y}, ${cMsgIn.x - 60} ${cMsgIn.y}, ${cMsgIn.x} ${cMsgIn.y}`;

    // 5. Tag Contact (Bottom) -> Create Deal (Top)
    const p5 = `M ${cTagBottom.x} ${cTagBottom.y} C ${cTagBottom.x} ${cTagBottom.y + 45}, ${cDealTop.x} ${cDealTop.y - 45}, ${cDealTop.x} ${cDealTop.y}`;

    setPaths({ p1, p2, p3, p4, p5 });
  };

  useEffect(() => {
    updatePaths();
    window.addEventListener('resize', updatePaths);
    
    // Additional delay to ensure fonts and layout finish rendering
    const timer = setTimeout(updatePaths, 150);

    return () => {
      window.removeEventListener('resize', updatePaths);
      clearTimeout(timer);
    };
  }, []);

  return (
    <section id="flow-builder" className="relative bg-white py-20 sm:py-24 border-b border-[#E8E8E6] overflow-hidden">
      
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
          className="space-y-5 mb-16 max-w-2xl mx-auto"
        >
          {/* Section Tag */}
          <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white border border-[#E8E8E6] text-xs font-bold text-[#0A0A0A] tracking-wider uppercase shadow-2xs">
            <Sparkles className="w-3.5 h-3.5 text-[#0A0A0A]" />
            <span>Visual Automations</span>
          </div>

          {/* Heading */}
          <h2 className="text-3xl sm:text-4xl font-black text-[#0A0A0A] tracking-tight leading-tight">
            Automate the Repetitive Stuff
          </h2>
        </motion.div>

        {/* Flow Canvas Container with Dynamic Pixel-Perfect Connection Lines */}
        <div ref={canvasRef} className="relative max-w-[1040px] mx-auto mb-16 py-4">
          
          {/* SVG Connection paths dynamically rendered from actual port coordinates */}
          {paths && (
            <svg className="absolute inset-0 w-full h-full pointer-events-none hidden lg:block overflow-visible z-0" fill="none">
              {/* Path 1: Trigger -> AI Condition */}
              <path
                d={paths.p1}
                stroke="#D4D4D0"
                strokeWidth="2.5"
                strokeLinecap="round"
              />
              {/* Path 2: AI Condition (Yes/Top) -> Tag Contact (Selected Edge) */}
              <path
                d={paths.p2}
                stroke="#4AE54A"
                strokeWidth="2.5"
                strokeLinecap="round"
              />
              {/* Path 3: AI Condition (No/Bottom) -> Human Handoff */}
              <path
                d={paths.p3}
                stroke="#D4D4D0"
                strokeWidth="2.5"
                strokeLinecap="round"
              />
              {/* Path 4: Tag Contact -> Send Message (Traversed Edge) */}
              <path
                d={paths.p4}
                stroke="#4AE54A"
                strokeWidth="2.5"
                strokeLinecap="round"
              />
              {/* Path 5: Tag Contact -> Create Deal */}
              <path
                d={paths.p5}
                stroke="#D4D4D0"
                strokeWidth="2.5"
                strokeLinecap="round"
              />
            </svg>
          )}

          {/* Grid Layout representing the flow hierarchy */}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-x-12 gap-y-16 items-start relative z-10">
            
            {/* Col 1, Row 1: Node 1 (Trigger) */}
            <motion.div 
              initial={{ opacity: 0, y: 12 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true }}
              transition={{ duration: 0.45 }}
              className="relative w-full max-w-[220px] mx-auto rounded-xl border border-[#E8E8E6] bg-white shadow-3xs text-left"
            >
              <div className="flex items-center gap-2.5 px-3.5 py-2.5 border-b border-[#E8E8E6] bg-[#E8FDE8]/50 rounded-t-xl select-none">
                <div className="p-1 rounded bg-emerald-50 border border-emerald-250 text-emerald-700 shrink-0">
                  <Play className="h-3 w-3" />
                </div>
                <span className="text-[10px] font-bold text-zinc-800 tracking-tight leading-snug">Inbound Message</span>
              </div>
              <div className="px-3.5 py-3.5 select-none space-y-1.5">
                <span className="text-[8px] font-black text-zinc-400 uppercase tracking-widest block">When customer sends message</span>
                <div className="bg-[#FAFAFA] border border-[#E8E8E6] rounded-lg p-2 flex items-center justify-between shadow-3xs">
                  <span className="text-[9px] font-bold text-zinc-855 truncate max-w-[110px]">WhatsApp Channel</span>
                  <span className="text-[8px] font-black uppercase text-emerald-600 bg-emerald-50 border border-emerald-100 px-1 rounded shrink-0">Trigger</span>
                </div>
              </div>
              
              {/* Output Port */}
              <div ref={inboundOutRef} className="absolute right-[-4px] top-1/2 -translate-y-1/2 w-2.5 h-2.5 rounded-full bg-[#4AE54A] border-2 border-white shadow-[0_0_0_0.5px_#C8C8C6] z-20" />
            </motion.div>

            {/* Col 2, Row 1: Node 2 (AI Decision) */}
            <motion.div 
              initial={{ opacity: 0, y: 12 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true }}
              transition={{ duration: 0.45, delay: 0.1 }}
              className="relative w-full max-w-[220px] mx-auto rounded-xl border border-[#E8E8E6] bg-white shadow-3xs text-left"
            >
              <div className="flex items-center gap-2.5 px-3.5 py-2.5 border-b border-[#E8E8E6] bg-purple-50/40 rounded-t-xl select-none">
                <div className="p-1 rounded bg-purple-50 border border-purple-200 text-purple-700 shrink-0">
                  <Bot className="h-3 w-3" />
                </div>
                <span className="text-[10px] font-bold text-zinc-850 tracking-tight leading-snug">AI Binary Decision</span>
              </div>
              <div className="px-3.5 py-3.5 select-none">
                <div className="bg-purple-50 border border-purple-100 text-purple-800 text-[9px] p-2.5 rounded-lg rounded-tl-none font-medium leading-normal shadow-3xs">
                  Does query mention "pricing", "plans", or "costs"?
                </div>
              </div>

              {/* Ports */}
              <div ref={aiInRef} className="absolute left-[-4px] top-1/2 -translate-y-1/2 w-2.5 h-2.5 rounded-full bg-[#4AE54A] border-2 border-white shadow-[0_0_0_0.5px_#C8C8C6] z-20" />
              <div ref={aiYesRef} className="absolute right-[-4px] top-[24px] w-2.5 h-2.5 rounded-full bg-[#4AE54A] border-2 border-white shadow-[0_0_0_0.5px_#C8C8C6] z-20" />
              <div ref={aiNoRef} className="absolute right-[-4px] bottom-[24px] w-2.5 h-2.5 rounded-full bg-[#4AE54A] border-2 border-white shadow-[0_0_0_0.5px_#C8C8C6] z-20" />
              <div className="absolute right-2 top-[22px] text-[7px] font-black uppercase text-[#4AE54A]">Yes</div>
              <div className="absolute right-2 bottom-[22px] text-[7px] font-black uppercase text-zinc-400">No</div>
            </motion.div>

            {/* Col 3, Row 1: Node 3 (CRM Tag Action) */}
            <motion.div 
              initial={{ opacity: 0, y: 12 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true }}
              transition={{ duration: 0.45, delay: 0.2 }}
              className="relative w-full max-w-[220px] mx-auto rounded-xl border border-[#E8E8E6] bg-white shadow-3xs text-left"
            >
              <div className="flex items-center gap-2.5 px-3.5 py-2.5 border-b border-[#E8E8E6] bg-indigo-50/40 rounded-t-xl select-none">
                <div className="p-1 rounded bg-indigo-50 border border-indigo-200 text-indigo-700 shrink-0">
                  <Tag className="h-3 w-3" />
                </div>
                <span className="text-[10px] font-bold text-zinc-800 tracking-tight leading-snug">Tag Contact</span>
              </div>
              <div className="px-3.5 py-3.5 select-none space-y-1.5">
                <span className="text-[8px] font-black text-zinc-400 uppercase tracking-widest block">Action: Add Tag</span>
                <div className="bg-[#FAFAFA] border border-[#E8E8E6] rounded-lg p-2 flex items-center justify-between shadow-3xs">
                  <span className="text-[9px] font-bold text-zinc-850 truncate max-w-[110px]">warm_lead</span>
                  <span className="text-[8px] font-black uppercase text-indigo-650 bg-indigo-50 border border-indigo-100 px-1 rounded shrink-0">CRM</span>
                </div>
              </div>

              {/* Ports */}
              <div ref={tagInRef} className="absolute left-[-4px] top-1/2 -translate-y-1/2 w-2.5 h-2.5 rounded-full bg-[#4AE54A] border-2 border-white shadow-[0_0_0_0.5px_#C8C8C6] z-20" />
              <div ref={tagOutRef} className="absolute right-[-4px] top-1/2 -translate-y-1/2 w-2.5 h-2.5 rounded-full bg-[#4AE54A] border-2 border-white shadow-[0_0_0_0.5px_#C8C8C6] z-20" />
              <div ref={tagBottomRef} className="absolute bottom-[-4px] left-1/2 -translate-x-1/2 w-2.5 h-2.5 rounded-full bg-[#4AE54A] border-2 border-white shadow-[0_0_0_0.5px_#C8C8C6] z-20" />
            </motion.div>

            {/* Col 4, Row 1: Node 4 (Message Action) */}
            <motion.div 
              initial={{ opacity: 0, y: 12 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true }}
              transition={{ duration: 0.45, delay: 0.3 }}
              className="relative w-full max-w-[220px] mx-auto rounded-xl border border-[#E8E8E6] bg-white shadow-3xs text-left"
            >
              <div className="flex items-center gap-2.5 px-3.5 py-2.5 border-b border-[#E8E8E6] bg-blue-50/40 rounded-t-xl select-none">
                <div className="p-1 rounded bg-blue-50 border border-blue-200 text-blue-700 shrink-0">
                  <MessageSquare className="h-3 w-3" />
                </div>
                <span className="text-[10px] font-bold text-zinc-800 tracking-tight leading-snug">Send Message</span>
              </div>
              <div className="px-3.5 py-3.5 select-none">
                <div className="bg-[#FAFAFA] border border-[#E8E8E6] text-zinc-800 text-[9px] p-2.5 rounded-lg rounded-tl-none font-medium leading-normal shadow-3xs">
                  "Our packages start from $29/mo. Sign up online for a 14-day free trial."
                </div>
              </div>

              {/* Input Port */}
              <div ref={msgInRef} className="absolute left-[-4px] top-1/2 -translate-y-1/2 w-2.5 h-2.5 rounded-full bg-[#4AE54A] border-2 border-white shadow-[0_0_0_0.5px_#C8C8C6] z-20" />
            </motion.div>

            {/* Col 1, Row 2: Node 6 (Human Handoff) */}
            <motion.div 
              initial={{ opacity: 0, y: 12 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true }}
              transition={{ duration: 0.45, delay: 0.5 }}
              className="relative w-full max-w-[220px] mx-auto rounded-xl border border-[#E8E8E6] bg-white shadow-3xs text-left"
            >
              <div className="flex items-center gap-2.5 px-3.5 py-2.5 border-b border-[#E8E8E6] bg-rose-50/40 rounded-t-xl select-none">
                <div className="p-1 rounded bg-rose-50 border border-rose-200 text-rose-700 shrink-0">
                  <UserCheck className="h-3 w-3" />
                </div>
                <span className="text-[10px] font-bold text-zinc-800 tracking-tight leading-snug">Human Handoff</span>
              </div>
              <div className="px-3.5 py-3.5 select-none">
                <div className="bg-[#FAFAFA] border border-[#E8E8E6] rounded-lg p-2.5 flex flex-col gap-1 shadow-3xs">
                  <span className="text-[8px] text-zinc-400 font-bold uppercase tracking-wider">Handoff Message</span>
                  <p className="text-[9.5px] text-zinc-750 font-semibold leading-relaxed">Routing to Account Executives...</p>
                </div>
              </div>

              {/* Input Port */}
              <div ref={handoffInRef} className="absolute left-[-4px] top-1/2 -translate-y-1/2 w-2.5 h-2.5 rounded-full bg-[#4AE54A] border-2 border-white shadow-[0_0_0_0.5px_#C8C8C6] z-20" />
            </motion.div>

            {/* Spacer */}
            <div className="hidden lg:block" />

            {/* Col 3, Row 2: Node 5 (CRM Create Deal Action) */}
            <motion.div 
              initial={{ opacity: 0, y: 12 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true }}
              transition={{ duration: 0.45, delay: 0.4 }}
              className="relative w-full max-w-[220px] mx-auto rounded-xl border border-[#E8E8E6] bg-white shadow-3xs text-left"
            >
              <div className="flex items-center gap-2.5 px-3.5 py-2.5 border-b border-[#E8E8E6] bg-orange-50/40 rounded-t-xl select-none">
                <div className="p-1 rounded bg-orange-50 border border-orange-200 text-orange-700 shrink-0">
                  <Briefcase className="h-3 w-3" />
                </div>
                <span className="text-[10px] font-bold text-zinc-800 tracking-tight leading-snug">Create Deal</span>
              </div>
              <div className="px-3.5 py-3.5 select-none">
                <div className="bg-[#FAFAFA] border border-[#E8E8E6] rounded-lg p-2.5 flex items-center justify-between shadow-3xs">
                  <div>
                    <p className="text-[7px] text-zinc-400 font-bold uppercase tracking-wider">CRM Deal Value</p>
                    <p className="text-[11px] font-black text-orange-600 truncate mt-0.5">$348.00</p>
                  </div>
                </div>
              </div>

              {/* Top Input Port */}
              <div ref={dealTopRef} className="absolute top-[-4px] left-1/2 -translate-x-1/2 w-2.5 h-2.5 rounded-full bg-[#4AE54A] border-2 border-white shadow-[0_0_0_0.5px_#C8C8C6] z-20" />
            </motion.div>

            {/* Spacer */}
            <div className="hidden lg:block" />

          </div>
        </div>

        {/* Bullets grid below canvas */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-4xl mx-auto pt-4 text-left">
          {bullets.map((bullet, idx) => (
            <div key={idx} className="flex items-start gap-3">
              <div className="w-5 h-5 rounded-full bg-[#E8FDE8] border border-[#4AE54A]/30 flex items-center justify-center shrink-0 mt-0.5 shadow-2xs">
                <CheckCircle2 className="w-3.5 h-3.5 text-[#0A0A0A]" />
              </div>
              <span className="text-xs sm:text-sm text-[#0A0A0A] font-semibold leading-relaxed">
                {bullet}
              </span>
            </div>
          ))}
        </div>

      </div>
    </section>
  );
}
