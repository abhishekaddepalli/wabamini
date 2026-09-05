'use client';

import { motion } from 'framer-motion';
import { 
  CheckCircle2, 
  Sparkles,
  CheckCircle
} from 'lucide-react';

interface StoreIntegrationsSectionProps {
  brandName?: string;
}

export function StoreIntegrationsSection({ brandName = 'WhatsOmni' }: StoreIntegrationsSectionProps) {
  const bullets = [
    'Connect your Shopify or WooCommerce store in just a single click',
    'Trigger automated abandoned checkout messages offering recovery coupons',
    'Resolve order status, tracking, and delivery queries instantly with RAG AI agents'
  ];

  return (
    <section id="store-integrations" className="relative bg-white py-20 sm:py-24 overflow-hidden">
      
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
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-8 items-center">
          
          {/* Left Column: Description & Bullet Points (5 Columns - Shifted Left) */}
          <motion.div 
            initial={{ opacity: 0, y: 16 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.5 }}
            className="lg:col-span-5 flex flex-col items-start text-left space-y-6 lg:order-1"
          >
            {/* Section Tag */}
            <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white border border-[#E8E8E6] text-xs font-bold text-[#0A0A0A] tracking-wider uppercase shadow-2xs">
              <Sparkles className="w-3.5 h-3.5 text-[#0A0A0A]" />
              <span>Store Integrations</span>
            </div>

            {/* Heading */}
            <h2 className="text-3xl sm:text-4xl font-black text-[#0A0A0A] tracking-tight leading-tight">
              Recover Sales You'd <br />
              Otherwise Lose
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

          {/* Right Column: Animated Raw E-commerce Action Widgets (7 Columns - Shifted Right, No containers, headers, footers) */}
          <div className="lg:col-span-7 flex flex-col sm:flex-row gap-8 items-center justify-start lg:justify-end w-full text-sans text-left lg:order-2">
            
            {/* Panel A: Animated Checkout Recovery (Message, Coupon & Recovered Badge) */}
            <div className="flex-grow flex flex-col gap-4 max-w-[310px] w-full mx-auto sm:mx-0 py-2">
              {/* Message Bubble (Animate from bottom) */}
              <motion.div 
                initial={{ opacity: 0, y: 12, scale: 0.96 }}
                whileInView={{ opacity: 1, y: 0, scale: 1 }}
                viewport={{ once: true }}
                transition={{ duration: 0.5, ease: 'easeOut' }}
                className="bg-white border border-[#E8E8E6] text-black text-xs sm:text-sm p-4 sm:p-5 rounded-2xl rounded-tl-none font-semibold leading-relaxed shadow-sm"
              >
                "Hey Marcus, we noticed you left items in your cart. Use code <span className="font-black text-black">SAVE10</span> for 10% off!"
              </motion.div>
              
              {/* Coupon Badge below (Animate with 0.3s delay) */}
              <motion.div 
                initial={{ opacity: 0, y: 12, scale: 0.96 }}
                whileInView={{ opacity: 1, y: 0, scale: 1 }}
                viewport={{ once: true }}
                transition={{ duration: 0.5, delay: 0.2, ease: 'easeOut' }}
                className="p-4 border border-[#4AE54A]/30 bg-white rounded-2xl flex items-center justify-between shadow-sm"
              >
                <div>
                  <p className="text-[7.5px] text-zinc-400 font-bold uppercase tracking-wider">Coupon Code</p>
                  <p className="text-sm font-black text-black mt-0.5">SAVE10</p>
                </div>
                <span className="text-[9px] font-black uppercase text-[#4AE54A] bg-[#E8FDE8] border border-[#4AE54A]/30 px-2.5 py-0.5 rounded">
                  10% Applied
                </span>
              </motion.div>

              {/* Cart Recovered Success Badge Component below Coupon (Animate with 0.5s delay) */}
              <motion.div 
                initial={{ opacity: 0, y: 12, scale: 0.96 }}
                whileInView={{ opacity: 1, y: 0, scale: 1 }}
                viewport={{ once: true }}
                transition={{ duration: 0.5, delay: 0.5, ease: 'easeOut' }}
                className="p-3.5 border border-[#4AE54A]/30 bg-[#E8FDE8]/30 rounded-2xl flex items-center gap-3 shadow-2xs"
              >
                <CheckCircle className="w-5 h-5 text-[#4AE54A] shrink-0" />
                <div>
                  <p className="text-xs font-black text-black leading-none">Cart Recovered</p>
                  <p className="text-[9px] text-zinc-550 font-bold mt-0.5">Revenue saved: $145.00</p>
                </div>
              </motion.div>
            </div>

            {/* Panel B: Animated Order Tracking Chat bubbles */}
            <div className="flex-grow flex flex-col gap-4 max-w-[320px] w-full mx-auto sm:mx-0 py-2">
              {/* User question bubble (Animate from right) */}
              <div className="flex justify-end w-full">
                <motion.div 
                  initial={{ opacity: 0, x: 12, scale: 0.96 }}
                  whileInView={{ opacity: 1, x: 0, scale: 1 }}
                  viewport={{ once: true }}
                  transition={{ duration: 0.5, ease: 'easeOut' }}
                  className="bg-[#0A0A0A] text-white text-xs sm:text-sm p-4 rounded-2xl rounded-tr-none font-semibold max-w-[240px] shadow-md text-left"
                >
                  "Where is my order #4820?"
                </motion.div>
              </div>
              
              {/* AI Agent reply bubble below (Animate with 0.4s delay) */}
              <motion.div 
                initial={{ opacity: 0, y: 12, scale: 0.96 }}
                whileInView={{ opacity: 1, y: 0, scale: 1 }}
                viewport={{ once: true }}
                transition={{ duration: 0.5, delay: 0.4, ease: 'easeOut' }}
                className="bg-[#E8FDE8]/30 border-2 border-[#4AE54A]/30 text-black text-xs sm:text-sm p-4 sm:p-5 rounded-2xl rounded-tl-none font-bold leading-relaxed shadow-sm"
              >
                "Your order #4820 has been shipped via DHL and is arriving tomorrow. Tracking: <span className="font-extrabold text-black">DHL-9428-A</span>."
              </motion.div>
            </div>

          </div>
        </div>
      </div>
    </section>
  );
}
