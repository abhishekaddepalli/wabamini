'use client';

import { useState, useEffect } from 'react';
import Image from 'next/image';
import { Link } from '@/i18n/routing';
import { motion, AnimatePresence } from 'framer-motion';
import { 
  Menu, 
  X, 
  ChevronDown, 
  ArrowRight,
  MessageSquare, 
  Bot, 
  Workflow, 
  Users, 
  Megaphone, 
  Calendar,
  ShoppingBag,
  BookOpen
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useBranding } from '@/components/BrandingThemeProvider';
import { BRANDING_CONFIG } from '@/config/branding';

interface LandingNavbarProps {
  brandName?: string;
  logoSrc?: string;
}

export function LandingNavbar({
  brandName,
  logoSrc,
}: LandingNavbarProps) {
  const { brandingName, logoUrl } = useBranding();
  const finalBrandName = brandName || brandingName || BRANDING_CONFIG.defaultName;
  const finalLogoSrc = logoSrc || logoUrl || BRANDING_CONFIG.defaultLogo;

  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [featuresDropdownOpen, setFeaturesDropdownOpen] = useState(false);
  const [channelsDropdownOpen, setChannelsDropdownOpen] = useState(false);

  // Redefined feature items matching exact sections explained on the landing page
  const featureItems = [
    { title: 'Shared Inbox', desc: 'Unified 6-channel inbox', icon: MessageSquare, href: '#unified-inbox' },
    { title: 'AI Agents', desc: 'Inbound & Outbound AI agents', icon: Bot, href: '#ai-agents' },
    { title: 'Knowledge Base', desc: 'AI dynamic context & RAG sources', icon: BookOpen, href: '#knowledge-base' },
    { title: 'Flow Builder', desc: 'Visual conversation canvas', icon: Workflow, href: '#flow-builder' },
    { title: 'CRM & Pipelines', desc: 'Lead deals & pipelines', icon: Users, href: '#crm-pipeline' },
    { title: 'Campaigns & Broadcasts', desc: 'Bulk messaging & triggers', icon: Megaphone, href: '#campaigns' },
    { title: 'Appointment Booking', desc: 'Meeting scheduling calendar', icon: Calendar, href: '#appointment-booking' },
    { title: 'Store Integrations', desc: 'Shopify & WooCommerce recovery', icon: ShoppingBag, href: '#store-integrations' },
  ];

  return (
    <div className="fixed top-0 left-0 right-0 z-50 pt-4 px-4 sm:px-6 lg:px-8 pointer-events-none">
      {/* Floating Translucent Capsule Navbar */}
      <header className="max-w-[1240px] mx-auto h-14 bg-white/85 backdrop-blur-xl border border-[#E8E8E6] rounded-full px-5 lg:px-7 flex items-center justify-between shadow-[0_4px_24px_rgba(0,0,0,0.04)] pointer-events-auto">
        
        {/* Left: Brand Logo & Trademark */}
        <Link href="/" className="flex items-center gap-2.5 focus:outline-none group">
          <img
            src={finalLogoSrc}
            alt={finalBrandName}
            className="h-7 w-auto object-contain transition-transform duration-200 group-hover:scale-105"
            onError={(e) => { e.currentTarget.src = '/logo.svg'; }}
          />
          <span className="text-lg font-black tracking-tight text-[#0A0A0A] flex items-center gap-1">
            {finalBrandName}
            <span className="text-xs font-bold text-[#6B6B6B] align-super">™</span>
            <span className="w-1.5 h-1.5 rounded-full bg-[#4AE54A] shadow-[0_0_8px_#4AE54A] animate-pulse ml-1" />
          </span>
        </Link>

        {/* Center: Desktop Navigation Text Links with Dropdown Chevrons */}
        <nav className="hidden md:flex items-center gap-6 text-xs font-semibold text-[#0A0A0A]">
          {/* Features Dropdown */}
          <div 
            className="relative"
            onMouseEnter={() => setFeaturesDropdownOpen(true)}
            onMouseLeave={() => setFeaturesDropdownOpen(false)}
          >
            <button className="flex items-center gap-1 hover:text-[#6B6B6B] py-2 transition-colors cursor-pointer">
              <span>Features</span>
              <ChevronDown className={`w-3.5 h-3.5 text-[#6B6B6B] transition-transform duration-200 ${featuresDropdownOpen ? 'rotate-180' : ''}`} />
            </button>

            <AnimatePresence>
              {featuresDropdownOpen && (
                <motion.div
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, y: 4 }}
                  className="absolute top-full left-1/2 -translate-x-1/2 mt-2 w-[540px] bg-white border border-[#E8E8E6] rounded-2xl shadow-xl p-4 grid grid-cols-2 gap-2 z-50 text-left"
                >
                  {featureItems.map((item) => {
                    const Icon = item.icon;
                    return (
                      <a
                        key={item.title}
                        href={item.href}
                        onClick={() => setFeaturesDropdownOpen(false)}
                        className="flex items-center gap-3 p-2.5 rounded-xl hover:bg-[#F5F5F5] transition-colors"
                      >
                        <div className="w-8 h-8 rounded-lg bg-[#E8FDE8] border border-[#4AE54A]/20 flex items-center justify-center text-[#0A0A0A] shrink-0">
                          <Icon className="w-4 h-4 text-[#0A0A0A]" />
                        </div>
                        <div>
                          <div className="text-xs font-bold text-[#0A0A0A]">{item.title}</div>
                          <div className="text-[10px] text-[#6B6B6B] mt-0.5 leading-tight">{item.desc}</div>
                        </div>
                      </a>
                    );
                  })}
                </motion.div>
              )}
            </AnimatePresence>
          </div>

          <a href="#ai-agents" className="hover:text-[#6B6B6B] transition-colors">
            AI Agents
          </a>
          <a href="#pricing" className="hover:text-[#6B6B6B] transition-colors">
            Pricing
          </a>
          <a href="#faq" className="hover:text-[#6B6B6B] transition-colors">
            FAQ
          </a>
        </nav>

        {/* Right: Rounded Pill Action Buttons */}
        <div className="hidden md:flex items-center gap-2.5">
          <Link href="/login">
            <Button
              variant="ghost"
              size="sm"
              className="h-8 px-4 rounded-full bg-white hover:bg-[#F5F5F5] text-[#0A0A0A] font-semibold text-xs border border-[#E8E8E6]"
            >
              Log In
            </Button>
          </Link>

          <Link href="/register">
            <Button
              size="sm"
              className="h-8 px-4.5 rounded-full bg-[#0A0A0A] hover:bg-[#262626] text-white font-bold text-xs shadow-xs border-0 flex items-center gap-1 group"
            >
              <span>Start Free</span>
              <ArrowRight className="w-3 h-3 text-white transition-transform group-hover:translate-x-0.5" />
            </Button>
          </Link>
        </div>

        {/* Mobile Toggle */}
        <div className="flex md:hidden items-center gap-2">
          <Link href="/register">
            <Button size="sm" className="h-7 px-3 rounded-full bg-[#0A0A0A] text-white font-bold text-[11px]">
              Start Free
            </Button>
          </Link>
          <button
            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            className="p-1.5 text-[#0A0A0A] hover:bg-[#F5F5F5] rounded-full border border-[#E8E8E6]"
          >
            {mobileMenuOpen ? <X className="w-4 h-4" /> : <Menu className="w-4 h-4" />}
          </button>
        </div>
      </header>

      {/* Mobile Drawer */}
      <AnimatePresence>
        {mobileMenuOpen && (
          <motion.div
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: 'auto' }}
            exit={{ opacity: 0, height: 0 }}
            className="md:hidden max-w-[1240px] mx-auto mt-2 bg-white border border-[#E8E8E6] rounded-2xl p-4 shadow-xl space-y-3"
          >
            <div className="flex flex-col space-y-2 text-xs font-semibold text-[#0A0A0A] text-left">
              <a href="#unified-inbox" onClick={() => setMobileMenuOpen(false)} className="p-2 hover:bg-[#F5F5F5] rounded-lg">Shared Inbox</a>
              <a href="#ai-agents" onClick={() => setMobileMenuOpen(false)} className="p-2 hover:bg-[#F5F5F5] rounded-lg">AI Agents</a>
              <a href="#knowledge-base" onClick={() => setMobileMenuOpen(false)} className="p-2 hover:bg-[#F5F5F5] rounded-lg">Knowledge Base</a>
              <a href="#flow-builder" onClick={() => setMobileMenuOpen(false)} className="p-2 hover:bg-[#F5F5F5] rounded-lg">Flow Builder</a>
              <a href="#crm-pipeline" onClick={() => setMobileMenuOpen(false)} className="p-2 hover:bg-[#F5F5F5] rounded-lg">CRM & Pipelines</a>
              <a href="#campaigns" onClick={() => setMobileMenuOpen(false)} className="p-2 hover:bg-[#F5F5F5] rounded-lg">Campaigns & Broadcasts</a>
              <a href="#appointment-booking" onClick={() => setMobileMenuOpen(false)} className="p-2 hover:bg-[#F5F5F5] rounded-lg">Appointment Booking</a>
              <a href="#store-integrations" onClick={() => setMobileMenuOpen(false)} className="p-2 hover:bg-[#F5F5F5] rounded-lg">Store Integrations</a>
            </div>
            <div className="pt-2 border-t border-[#E8E8E6] flex flex-col gap-2">
              <Link href="/login" onClick={() => setMobileMenuOpen(false)}>
                <Button variant="outline" className="w-full h-9 rounded-full text-xs font-semibold">Log In</Button>
              </Link>
              <Link href="/register" onClick={() => setMobileMenuOpen(false)}>
                <Button className="w-full h-9 rounded-full bg-[#0A0A0A] text-white font-bold text-xs">Start Free Trial</Button>
              </Link>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
