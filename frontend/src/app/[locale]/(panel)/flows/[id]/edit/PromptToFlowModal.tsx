/* eslint-disable @typescript-eslint/no-explicit-any */
'use client';

import React, { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { 
  Sparkles, 
  X, 
  RefreshCw, 
  Zap, 
  Calendar, 
  UserCheck, 
  ListFilter, 
  ShoppingCart,
  AlertCircle,
  ArrowRight,
  CheckCircle2,
  Layers,
  MessageSquareHeart,
  HelpCircle,
  Rocket,
  GitBranch,
  Check,
  RotateCcw,
  MessageSquare,
  Clock,
  Tag,
  Share2,
  Database
} from 'lucide-react';
import { fetchWithCsrf } from '@/lib/api';
import { toast } from 'sonner';
import { useTranslations } from 'next-intl';
import Link from 'next/link';

interface PromptToFlowModalProps {
  flowId: string;
  isOpen: boolean;
  onClose: () => void;
  onFlowGenerated: (definition: { nodes: any[]; edges: any[] }, flowName?: string) => void;
}

interface SuggestionChip {
  id: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  prompt: string;
}

const SUGGESTION_CHIPS: SuggestionChip[] = [
  {
    id: 'appointment',
    label: 'Appointment Booking',
    icon: Calendar,
    prompt: 'Create an automated appointment booking workflow. Greet the customer, ask for their full name, email, and preferred service date/time. Check if they are a new or returning patient, book the appointment in the calendar, create a CRM deal, and send a booking confirmation summary.'
  },
  {
    id: 'lead',
    label: 'Lead Qualification',
    icon: ListFilter,
    prompt: 'Build a high-converting lead qualification flow for B2B services. Greet visitor, ask for their company size and budget range. Use AI conditional classification to evaluate if budget is above $1,000. If qualified, tag contact as "high-value-lead" and schedule a sales discovery call; otherwise, send product brochure link.'
  },
  {
    id: 'support',
    label: 'Support & Triage',
    icon: UserCheck,
    prompt: 'Create a customer support triage flow. Greet the user and display an interactive menu with 3 options: 1) Order Status & Tracking, 2) Technical Assistance, 3) Talk to Human Agent. If option 1, ask for order ID. If option 2, search knowledgebase with AI RAG. If option 3, transfer immediately to live human agent.'
  },
  {
    id: 'cart',
    label: 'Abandoned Cart',
    icon: ShoppingCart,
    prompt: 'Create an abandoned checkout recovery workflow. Evaluate the cart status, send a friendly reminder with items list, offer a 10% discount code if they complete purchase within 24 hours, and branch based on whether they click to complete order or ask a question.'
  },
  {
    id: 'onboarding',
    label: 'Customer Onboarding',
    icon: Rocket,
    prompt: 'Create a new customer onboarding sequence. Welcome the user, introduce key features of our platform, ask what goal they want to achieve first, and provide a direct guide or link tailored to their choice.'
  },
  {
    id: 'feedback',
    label: 'CSAT Survey',
    icon: MessageSquareHeart,
    prompt: 'Create a customer satisfaction (CSAT) survey flow. Thank the customer for their recent purchase or support interaction, ask them to rate experience on a scale of 1-5, and if rating is below 4, trigger an alert note and route to a senior support manager.'
  },
  {
    id: 'faq',
    label: 'Interactive FAQ',
    icon: HelpCircle,
    prompt: 'Create an interactive self-service FAQ menu for an omnichannel store. Provide list buttons for Pricing, Return Policy, Shipping Times, and Warranty. Answer each with AI knowledgebase search and offer live agent handoff if unsatisfied.'
  }
];

export function PromptToFlowModal({
  flowId,
  isOpen,
  onClose,
  onFlowGenerated
}: PromptToFlowModalProps) {
  const t = useTranslations('Flows');
  const tCommon = useTranslations('Common');
  
  const [mounted, setMounted] = useState(false);
  const [promptText, setPromptText] = useState('');
  const [isGenerating, setIsGenerating] = useState(false);
  const [generationStep, setGenerationStep] = useState(0);
  const [selectedChipId, setSelectedChipId] = useState<string | null>(null);
  const [credits, setCredits] = useState<{
    max_credits: number;
    used_credits: number;
    remaining_credits: number;
    plan_name: string;
    is_byok?: boolean;
    operational_model?: string;
  } | null>(null);

  // Preview stage state
  const [previewData, setPreviewData] = useState<{
    definition: { nodes: any[]; edges: any[] };
    flowName: string;
    description: string;
  } | null>(null);

  useEffect(() => {
    setMounted(true);
  }, []);

  // Fetch credits and reset state whenever modal opens
  useEffect(() => {
    if (isOpen) {
      fetchCredits();
      setSelectedChipId(null);
      setPreviewData(null);
      setIsGenerating(false);
    }
  }, [isOpen]);

  const fetchCredits = async () => {
    try {
      const res = await fetchWithCsrf('/flows/flow-credits');
      if (res.ok) {
        const data = await res.json();
        setCredits(data);
      }
    } catch (e) {
      console.error('Failed to fetch flow credits', e);
    }
  };

  // Exactly 2 processing points animation cycle
  useEffect(() => {
    let interval: NodeJS.Timeout;
    if (isGenerating) {
      setGenerationStep(1);
      interval = setInterval(() => {
        setGenerationStep((prev) => (prev < 2 ? prev + 1 : prev));
      }, 1600);
    } else {
      setGenerationStep(0);
    }
    return () => clearInterval(interval);
  }, [isGenerating]);

  if (!mounted || !isOpen) return null;

  const handleChipClick = (chip: SuggestionChip) => {
    setSelectedChipId(chip.id);
    setPromptText(chip.prompt);
  };

  const handleGenerate = async () => {
    if (!promptText.trim()) {
      toast.error('Please enter a description for the workflow you wish to generate.');
      return;
    }

    if (credits && !credits.is_byok && credits.remaining_credits <= 0) {
      toast.error('You have exhausted your Flow Credits limit for this billing cycle.');
      return;
    }

    setIsGenerating(true);

    try {
      const res = await fetchWithCsrf(`/flows/${flowId}/prompt-to-flow`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt: promptText.trim() })
      });

      const data = await res.json();

      if (res.ok && data.definition) {
        if (data.credits) {
          setCredits(prev => prev ? {
            ...prev,
            used_credits: data.credits.used_credits,
            remaining_credits: data.credits.remaining_credits
          } : null);
        }
        
        // Transition directly to Preview State
        setPreviewData({
          definition: data.definition,
          flowName: data.flow_name || 'AI Generated Flow',
          description: data.description || ''
        });
      } else {
        toast.error(data.message || 'Failed to generate flow with AI.');
        if (data.credits) {
          setCredits(prev => prev ? {
            ...prev,
            used_credits: data.credits.used_credits,
            remaining_credits: data.credits.remaining_credits
          } : null);
        }
      }
    } catch (err: any) {
      console.error('Prompt to Flow error:', err);
      toast.error('Connection error occurred while generating flow.');
    } finally {
      setIsGenerating(false);
    }
  };

  const handleApplyFlow = () => {
    if (!previewData) return;
    onFlowGenerated(previewData.definition, previewData.flowName);
    toast.success('Generated flow applied to canvas successfully!');
    onClose();
  };

  const handleDiscardPreview = () => {
    setPreviewData(null);
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey || !e.shiftKey)) {
      e.preventDefault();
      if (!isGenerating && promptText.trim()) {
        handleGenerate();
      }
    }
  };

  // Exactly 2 processing steps
  const steps = [
    'Understanding workflow intent & architecting node structure...',
    'Connecting decision branches & synthesizing visual canvas...'
  ];

  const getNodeIcon = (type: string) => {
    switch (type) {
      case 'inbound_message':
      case 'webhook_trigger':
      case 'outbound_campaign':
      case 'contact_created':
      case 'manual':
        return Zap;
      case 'send_message':
      case 'send_template':
        return MessageSquare;
      case 'ask_question':
      case 'interactive_menu':
        return ListFilter;
      case 'condition':
      case 'ai_condition':
        return GitBranch;
      case 'wait_delay':
        return Clock;
      case 'tag_contact':
        return Tag;
      case 'create_appointment':
        return Calendar;
      case 'human_handoff':
        return UserCheck;
      case 'webhook_dispatch':
      case 'n8n':
      case 'zapier':
        return Share2;
      case 'create_deal':
      case 'update_contact':
        return Database;
      default:
        return Layers;
    }
  };

  return createPortal(
    <div className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 animate-backdrop-fade select-none">
      <div 
        className="bg-white border border-[#E8E8E6] rounded-xl max-w-2xl w-full p-6 shadow-2xl relative z-50 animate-modal-entry text-left font-sans flex flex-col max-h-[90vh] overflow-hidden"
        role="dialog"
        aria-modal="true"
      >
        {/* Live Generation Full-Dialog Blurred Overlay */}
        {isGenerating && (
          <div className="absolute inset-0 z-30 bg-white/85 backdrop-blur-md flex flex-col items-center justify-center p-6 text-center animate-fade-in">
            {/* Live Process Card */}
            <div className="w-full max-w-md bg-white border border-[#E8E8E6] rounded-xl p-6 shadow-xl text-left space-y-4">
              <div className="flex items-center justify-between border-b border-[#E8E8E6] pb-3.5">
                <div className="flex items-center gap-2.5">
                  <span className="h-2 w-2 rounded-full bg-[#4AE54A] animate-ping shrink-0" />
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">
                    Synthesizing Workflow Architecture
                  </h3>
                </div>
                <span className="text-[10px] font-mono font-bold text-zinc-500 bg-zinc-50 px-2 py-0.5 rounded border border-[#E8E8E6]">
                  Step {Math.min(generationStep, 2)} of 2
                </span>
              </div>

              {/* Smooth Progress Bar */}
              <div className="h-1.5 w-full bg-zinc-100 rounded-full overflow-hidden relative">
                <div 
                  className="h-full bg-[#4AE54A] rounded-full transition-all duration-500 ease-out"
                  style={{ width: `${generationStep === 1 ? '55%' : '95%'}` }}
                />
              </div>

              {/* 2 Stepper Process Items */}
              <div className="space-y-3 pt-1">
                {steps.map((s, idx) => {
                  const isDone = generationStep > idx + 1;
                  const isCurrent = generationStep === idx + 1;
                  return (
                    <div 
                      key={`step-${idx}`}
                      className={`flex items-center gap-3 text-xs transition-all duration-200 ${
                        isDone 
                          ? 'text-emerald-800 font-semibold' 
                          : isCurrent 
                            ? 'text-zinc-950 font-bold' 
                            : 'text-zinc-400'
                      }`}
                    >
                      <div className="shrink-0 flex items-center justify-center">
                        {isDone ? (
                          <div className="h-4 w-4 rounded-full bg-[#E8FDE8] border border-[#4AE54A]/40 flex items-center justify-center">
                            <Check className="h-2.5 w-2.5 text-emerald-700 stroke-[3]" />
                          </div>
                        ) : isCurrent ? (
                          <div className="h-4 w-4 rounded-full bg-emerald-50 border border-[#4AE54A] flex items-center justify-center">
                            <div className="h-1.5 w-1.5 rounded-full bg-[#4AE54A] animate-ping" />
                          </div>
                        ) : (
                          <div className="h-4 w-4 rounded-full bg-zinc-200" />
                        )}
                      </div>
                      <span className="tracking-tight">{s}</span>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>
        )}

        {/* ----------------- STATE 1: PROMPT INPUT STAGE ----------------- */}
        {!previewData && (
          <>
            {/* Modal Header (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6 shrink-0">
              <div className="flex items-center gap-2.5 min-w-0">
                <div className="h-7 w-7 rounded bg-[#FAFAFA] border border-[#E8E8E6] p-1 flex items-center justify-center shrink-0">
                  <Sparkles className="h-4 w-4 text-[#4AE54A]" />
                </div>
                <div className="min-w-0">
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider truncate">
                    {t('promptToFlow') || 'Prompt to Flow'}
                  </h3>
                  <p className="text-[10px] text-zinc-500 truncate">
                    Describe your desired automation in plain English to build an end-to-end flow canvas
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={onClose}
                disabled={isGenerating}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none disabled:opacity-50 shrink-0"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            {/* Modal Scrollable Content Body */}
            <div className="py-4 space-y-4 max-h-[60vh] overflow-y-auto pr-1">
              {/* Credits Exhausted Alert (Only in Master Fixed Mode) */}
              {credits && !credits.is_byok && credits.remaining_credits <= 0 && (
                <div className="bg-amber-50 border border-amber-200 rounded-lg p-3.5 flex items-start gap-3 text-xs text-amber-900 shadow-3xs">
                  <AlertCircle className="h-4 w-4 text-amber-600 shrink-0 mt-0.5" />
                  <div className="flex-1">
                    <p className="font-bold text-amber-950">Flow Credits Exhausted</p>
                    <p className="text-[11px] text-amber-800 mt-0.5 leading-relaxed">
                      You have used all {credits.max_credits} flow generation credits included with your {credits.plan_name} plan this month.
                    </p>
                    <Link
                      href="/settings/billing"
                      className="inline-flex items-center gap-1 mt-2 text-xs font-bold text-amber-950 hover:text-black underline underline-offset-2"
                    >
                      <span>Upgrade Subscription Plan</span>
                      <ArrowRight className="h-3 w-3" />
                    </Link>
                  </div>
                </div>
              )}

              {/* Suggestion Chips Bar */}
              <div>
                <div className="flex items-center justify-between mb-2">
                  <span className="text-[10px] font-black uppercase tracking-widest text-zinc-400 block">
                    Quick Suggestions
                  </span>
                  <span className="text-[10px] text-zinc-400 font-medium">Click to populate prompt</span>
                </div>
                
                <div className="flex flex-wrap gap-1.5">
                  {SUGGESTION_CHIPS.map((chip) => {
                    const IconComponent = chip.icon;
                    const isSelected = selectedChipId === chip.id;
                    return (
                      <button
                        key={chip.id}
                        type="button"
                        onClick={() => handleChipClick(chip)}
                        disabled={isGenerating}
                        className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border transition-all cursor-pointer shadow-3xs ${
                          isSelected
                            ? 'bg-[#E8FDE8] border-[#4AE54A]/40 text-emerald-950 font-bold ring-1 ring-[#4AE54A]/30'
                            : 'bg-[#FAFAFA] border-[#E8E8E6] text-zinc-700 hover:bg-white hover:border-black/30 hover:text-black'
                        }`}
                      >
                        <IconComponent className={`h-3.5 w-3.5 shrink-0 ${isSelected ? 'text-[#4AE54A]' : 'text-zinc-500'}`} />
                        <span>{chip.label}</span>
                      </button>
                    );
                  })}
                </div>
              </div>

              {/* Prompt Instructions Input Area */}
              <div>
                <div className="flex justify-between items-center mb-1.5">
                  <span className="text-[10px] font-black uppercase tracking-widest text-zinc-400 block">
                    Workflow Instructions & Logic
                  </span>
                  <span className="text-[10px] font-mono text-zinc-400">
                    {promptText.length} / 4000
                  </span>
                </div>
                <textarea
                  rows={4}
                  value={promptText}
                  onChange={(e) => {
                    setPromptText(e.target.value);
                    setSelectedChipId(null);
                  }}
                  onKeyDown={handleKeyDown}
                  disabled={isGenerating}
                  placeholder="Describe your desired workflow in detail. Press Enter or click Generate Flow below. E.g. Greet customer, show menu with 3 options: Book Appointment, Pricing, or Support. If Appointment, collect email and schedule in calendar..."
                  className="w-full rounded-[8px] border border-[#E8E8E6] bg-[#FAFAFA] p-3.5 text-xs text-zinc-900 placeholder:text-zinc-400 focus:bg-white focus:border-black focus:outline-none transition-all resize-none shadow-3xs leading-relaxed font-normal"
                />
                <div className="flex justify-between items-center mt-1.5 text-[11px] text-zinc-400 font-medium">
                  <span>Press <kbd className="px-1.5 py-0.5 rounded bg-zinc-100 border border-[#E8E8E6] text-[10px] font-mono text-zinc-600">Enter</kbd> to generate</span>
                  <span>{credits?.is_byok ? 'BYOK Direct AI Generation' : '1 Flow Credit deducted on generation'}</span>
                </div>
              </div>
            </div>

            {/* Modal Action Buttons Footer */}
            <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-between select-none shrink-0">
              {/* Credit Balance Indicator */}
              <div className="flex items-center gap-2.5">
                {credits ? (
                  credits.is_byok ? (
                    <div className="inline-flex items-center gap-2 px-3 py-1.5 rounded-[6px] text-xs font-semibold bg-white border border-[#E8E8E6] text-zinc-900 shadow-3xs">
                      <Sparkles className="h-3.5 w-3.5 text-[#4AE54A]" />
                      <span className="text-[11px] text-zinc-500 font-medium">AI Mode:</span>
                      <span className="font-bold text-xs text-zinc-950">BYOK Provider Keys</span>
                    </div>
                  ) : (
                    <div 
                      className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-[6px] text-xs font-semibold border transition-all shadow-3xs ${
                        credits.remaining_credits > 0
                          ? 'bg-white border-[#E8E8E6] text-zinc-900'
                          : 'bg-rose-50 border-rose-200 text-rose-800'
                      }`}
                      title={`Plan: ${credits.plan_name} (${credits.used_credits} of ${credits.max_credits} credits used)`}
                    >
                      <div className="flex items-center gap-1.5">
                        <Zap className={`h-3.5 w-3.5 ${credits.remaining_credits > 0 ? 'text-[#4AE54A]' : 'text-rose-500'}`} />
                        <span className="text-[11px] text-zinc-500 font-medium">Credits:</span>
                        <span className="font-mono font-bold text-xs text-zinc-950">
                          {credits.remaining_credits} <span className="text-zinc-400 font-normal">/ {credits.max_credits}</span>
                        </span>
                      </div>
                      <span className="text-[10px] px-1.5 py-0.5 rounded bg-zinc-100 text-zinc-600 font-mono font-medium">
                        -1 per run
                      </span>
                    </div>
                  )
                ) : (
                  <div className="flex items-center gap-1.5 text-xs text-zinc-400">
                    <Layers className="h-3.5 w-3.5" />
                    <span>Generates complete workflow on canvas</span>
                  </div>
                )}
              </div>

              <div className="flex items-center gap-2.5">
                <button
                  type="button"
                  onClick={onClose}
                  disabled={isGenerating}
                  className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs disabled:opacity-50"
                >
                  {tCommon('cancel') || 'Cancel'}
                </button>
                <button
                  type="button"
                  onClick={handleGenerate}
                  disabled={isGenerating || !promptText.trim() || (credits !== null && !credits.is_byok && credits.remaining_credits <= 0)}
                  className="relative inline-flex items-center justify-center p-[1px] rounded-[6px] overflow-hidden group cursor-pointer shadow-sm hover:shadow-[0_0_14px_rgba(74,229,74,0.3)] transition-all duration-300 active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:shadow-none"
                >
                  {/* Continuously Rotating Conic Gradient Animated Border */}
                  <span className="absolute inset-[-250%] animate-border-rotate bg-[conic-gradient(from_0deg_at_50%_50%,#4AE54A_0%,#3DD43D_25%,#E8E8E6_50%,#86EFAC_75%,#4AE54A_100%)] opacity-90 group-hover:opacity-100 transition-opacity" />
                  
                  {/* Inner Button Surface */}
                  <span className="relative z-10 flex items-center gap-2 h-[34px] px-5 rounded-[5px] bg-[#0A0A0A] group-hover:bg-zinc-900 text-white text-xs font-bold transition-all duration-200">
                    <Sparkles className="h-3.5 w-3.5 text-white group-hover:scale-110 group-hover:rotate-12 transition-transform duration-200 shrink-0" />
                    <span>Generate Flow</span>
                  </span>
                </button>
              </div>
            </div>
          </>
        )}

        {/* ----------------- STATE 2: PREVIEW & APPLY STAGE ----------------- */}
        {previewData && (
          <>
            {/* Preview Header (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6 shrink-0">
              <div className="flex items-center gap-2.5 min-w-0">
                <div className="h-7 w-7 rounded bg-[#FAFAFA] border border-[#E8E8E6] p-1 flex items-center justify-center shrink-0">
                  <CheckCircle2 className="h-4 w-4 text-[#4AE54A]" />
                </div>
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <h3 className="text-xs font-bold text-black uppercase tracking-wider truncate">
                      Flow Architecture Preview
                    </h3>
                    <span className="text-[9px] uppercase font-bold px-1.5 py-0.5 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 font-mono shrink-0">
                      Generated Ready
                    </span>
                  </div>
                  <p className="text-[10px] text-zinc-500 truncate">
                    Review the synthesized workflow blocks before applying them to your canvas
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={onClose}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none shrink-0"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            {/* Preview Content Body */}
            <div className="py-4 space-y-4 max-h-[60vh] min-h-[300px] overflow-y-auto pr-1">
              {/* Flow Summary Card */}
              <div className="bg-[#FAFAFA] border border-[#E8E8E6] rounded-xl p-4 shadow-3xs">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold text-zinc-950 uppercase tracking-wide">
                    {previewData.flowName}
                  </span>
                  <div className="flex items-center gap-2">
                    <span className="text-[10px] font-mono font-bold px-2 py-0.5 rounded bg-white border border-[#E8E8E6] text-zinc-700 shadow-3xs">
                      {previewData.definition.nodes.length} Nodes
                    </span>
                    <span className="text-[10px] font-mono font-bold px-2 py-0.5 rounded bg-white border border-[#E8E8E6] text-zinc-700 shadow-3xs">
                      {previewData.definition.edges.length} Connections
                    </span>
                  </div>
                </div>
                {previewData.description && (
                  <p className="text-xs text-zinc-600 mt-2 leading-relaxed font-normal">
                    {previewData.description}
                  </p>
                )}
              </div>

              {/* Node Sequence List */}
              <div className="space-y-2">
                <span className="text-[10px] font-black uppercase tracking-widest text-zinc-400 block">
                  Synthesized Node Sequence ({previewData.definition.nodes.length})
                </span>

                <div className="space-y-2">
                  {previewData.definition.nodes.map((node: any, idx: number) => {
                    const NodeIcon = getNodeIcon(node.type);
                    const title = node.data?.title || node.type;
                    const snippet = node.data?.body || node.data?.questionText || node.data?.channel || '';

                    return (
                      <div 
                        key={node.id || idx}
                        className="bg-white border border-[#E8E8E6] rounded-lg p-3 shadow-3xs flex items-start justify-between gap-3 hover:border-zinc-400 transition-colors"
                      >
                        <div className="flex items-start gap-3 min-w-0">
                          <div className="h-7 w-7 rounded bg-[#FAFAFA] border border-[#E8E8E6] flex items-center justify-center shrink-0 mt-0.5">
                            <NodeIcon className="h-3.5 w-3.5 text-zinc-700" />
                          </div>
                          <div className="min-w-0">
                            <div className="flex items-center gap-2">
                              <span className="text-xs font-bold text-zinc-900 truncate">
                                {title}
                              </span>
                              <span className="text-[9px] font-mono uppercase px-1.5 py-0.2 rounded bg-zinc-100 text-zinc-500 shrink-0">
                                {node.type}
                              </span>
                            </div>
                            {snippet && (
                              <p className="text-[11px] text-zinc-500 truncate mt-0.5">
                                {snippet}
                              </p>
                            )}
                          </div>
                        </div>

                        <span className="text-[10px] font-mono text-zinc-400 shrink-0 mt-1">
                          #{idx + 1}
                        </span>
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>

            {/* Preview Action Footer */}
            <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-between select-none shrink-0">
              <button
                type="button"
                onClick={handleDiscardPreview}
                className="flex items-center gap-1.5 border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
              >
                <RotateCcw className="h-3.5 w-3.5" />
                <span>Edit Prompt</span>
              </button>

              <div className="flex items-center gap-2.5">
                <button
                  type="button"
                  onClick={onClose}
                  className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                >
                  Discard
                </button>
                <button
                  type="button"
                  onClick={handleApplyFlow}
                  className="h-9 px-5 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-sm transition-all flex items-center gap-2 cursor-pointer group"
                >
                  <Check className="h-3.5 w-3.5 text-[#4AE54A] group-hover:scale-110 transition-transform" />
                  <span>Apply to Flow Builder</span>
                </button>
              </div>
            </div>
          </>
        )}
      </div>
    </div>,
    document.body
  );
}
