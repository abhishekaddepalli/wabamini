'use client';

import { useState } from 'react';
import { motion } from 'framer-motion';
import { 
  CheckCircle2, 
  Sparkles,
  Calendar,
  Clock,
  Globe
} from 'lucide-react';

interface AppointmentBookingSectionProps {
  brandName?: string;
}

export function AppointmentBookingSection({ brandName = 'WhatsOmni' }: AppointmentBookingSectionProps) {
  const [selectedSlot, setSelectedSlot] = useState<string>('3pm');
  const [selectedDate, setSelectedDate] = useState<string>('15');

  const bullets = [
    'Share a simple public link — no back-and-forth scheduling required',
    'Syncs with Google Calendar automatically to prevent double-bookings',
    'Video links (Zoom, Google Meet, Microsoft Teams) attached to every booking'
  ];

  const dates = [
    { day: '14', month: 'July', label: '14 July' },
    { day: '15', month: 'July', label: '15 July' },
    { day: '16', month: 'July', label: '16 July' }
  ];

  const slots = [
    { id: '10am', label: '10:00 AM' },
    { id: '11am', label: '11:00 AM' },
    { id: '1pm', label: '01:00 PM' },
    { id: '3pm', label: '03:00 PM' }
  ];

  return (
    <section id="appointment-booking" className="relative bg-white py-14 sm:py-16 overflow-hidden">
      
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
          
          {/* Left Column: Pure Raw Scheduling components directly on page (7 Columns) */}
          <motion.div 
            initial={{ opacity: 0, y: 20 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.6 }}
            className="lg:col-span-7 flex flex-col sm:flex-row gap-8 items-stretch justify-start w-full text-sans text-left"
          >
            
            {/* Component A: Raw Date Selection Panel (No backgrounds or borders) */}
            <div className="flex-1 flex flex-col justify-between max-w-[280px] mx-auto sm:mx-0 py-2">
              <div className="space-y-4">
                <div className="flex items-center gap-2 pb-2 border-b border-zinc-200">
                  <Calendar className="w-4 h-4 text-black" />
                  <span className="text-[10px] font-bold text-black uppercase tracking-wider">Choose Date</span>
                </div>
                <div className="flex flex-col gap-2.5">
                  {dates.map((d) => {
                    const isSelected = d.day === selectedDate;
                    return (
                      <button
                        key={d.day}
                        onClick={() => setSelectedDate(d.day)}
                        className={`w-full text-left p-3 rounded-xl border text-xs font-semibold transition-all cursor-pointer flex items-center justify-between ${
                          isSelected 
                            ? 'bg-black border-black text-white shadow-md font-bold' 
                            : 'bg-white border-zinc-200 text-zinc-650 hover:border-zinc-350 hover:bg-zinc-50/50'
                        }`}
                      >
                        <span>{d.label}</span>
                        {isSelected && <span className="w-1.5 h-1.5 rounded-full bg-[#4AE54A] shadow-[0_0_4px_#4AE54A]" />}
                      </button>
                    );
                  })}
                </div>
              </div>

              <div className="pt-4 border-t border-zinc-150 flex items-center gap-1.5 text-[8.5px] font-bold text-zinc-500 mt-6">
                <Globe className="w-3.5 h-3.5 text-zinc-400" />
                <span>Asia/Kolkata timezone</span>
              </div>
            </div>

            {/* Component B: Raw Time Slots & Book Panel (No backgrounds or borders) */}
            <div className="flex-grow flex flex-col justify-between max-w-[290px] mx-auto sm:mx-0 py-2">
              <div className="space-y-4">
                <div className="flex items-center gap-2 pb-2 border-b border-zinc-200">
                  <Clock className="w-4 h-4 text-black" />
                  <span className="text-[10px] font-bold text-black uppercase tracking-wider">Select Time</span>
                </div>
                <div className="grid grid-cols-2 gap-2.5 text-center text-[10px]">
                  {slots.map((slot) => {
                    const isSelected = slot.id === selectedSlot;
                    return (
                      <button
                        key={slot.id}
                        onClick={() => setSelectedSlot(slot.id)}
                        className={`p-3 border rounded-xl font-bold transition-all cursor-pointer ${
                          isSelected 
                            ? 'bg-black border-black text-white shadow-md' 
                            : 'bg-white border-zinc-200 text-zinc-650 hover:border-zinc-350 hover:bg-zinc-50/50'
                        }`}
                      >
                        {slot.label}
                      </button>
                    );
                  })}
                </div>
              </div>

              <div className="space-y-3 pt-4 border-t border-zinc-150 mt-6">
                <button className="w-full py-3 bg-black hover:bg-zinc-900 text-white font-black text-[9.5px] rounded-xl shadow-md transition-colors cursor-pointer uppercase tracking-wider text-center block">
                  Confirm Booking
                </button>
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
              <span>Appointment Booking</span>
            </div>

            {/* Heading */}
            <h2 className="text-3xl sm:text-4xl font-black text-[#0A0A0A] tracking-tight leading-tight">
              Let Customers Book Time <br />
              With You, Automatically
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
