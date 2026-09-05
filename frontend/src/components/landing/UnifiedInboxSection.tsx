'use client';

import { useState } from 'react';
import { motion } from 'framer-motion';
import { 
  CheckCircle2, 
  Send, 
  Search,
  Plus,
  Sparkles
} from 'lucide-react';

interface ChatMessage {
  sender: 'customer' | 'agent' | 'system';
  text: string;
  time: string;
}

interface ChatThread {
  id: string;
  name: string;
  avatar: string;
  channel: 'whatsapp' | 'instagram' | 'telegram' | 'email';
  channelIcon: string;
  lastMessage: string;
  time: string;
  unread: boolean;
  messages: ChatMessage[];
}

export function UnifiedInboxSection() {
  const threads: ChatThread[] = [
    {
      id: 'thread-1',
      name: 'Sarah Jenkins',
      avatar: 'S',
      channel: 'whatsapp',
      channelIcon: '/channels/whatsapp.webp',
      lastMessage: 'Perfect, book me in for 3:00 PM.',
      time: 'Just now',
      unread: true,
      messages: [
        { sender: 'customer', text: 'Hi, are there cardiology slots available tomorrow?', time: '10:05 AM' },
        { sender: 'agent', text: 'Yes! We have Dr. Elena available at 11:00 AM and 3:00 PM.', time: '10:06 AM' },
        { sender: 'customer', text: 'Perfect, book me in for 3:00 PM.', time: 'Just now' },
      ],
    },
    {
      id: 'thread-2',
      name: 'Marcus Brody',
      avatar: 'M',
      channel: 'instagram',
      channelIcon: '/channels/instagram.svg',
      lastMessage: 'Is the brochure digital?',
      time: '14m ago',
      unread: false,
      messages: [
        { sender: 'customer', text: 'Hello, is the luxury villa walkthrough private?', time: '02:05 PM' },
        { sender: 'agent', text: 'Yes, it is a fully private agent-guided tour.', time: '02:08 PM' },
        { sender: 'customer', text: 'Is the brochure digital?', time: '14 mins ago' },
      ],
    },
    {
      id: 'thread-3',
      name: 'Elena Rostova',
      avatar: 'E',
      channel: 'telegram',
      channelIcon: '/channels/telegram.webp',
      lastMessage: 'API documentation link?',
      time: '1h ago',
      unread: false,
      messages: [
        { sender: 'customer', text: 'Can we sync leads via webhooks?', time: '11:15 AM' },
        { sender: 'agent', text: 'Yes, we support direct bi-directional webhooks and HubSpot OAuth sync.', time: '11:16 AM' },
        { sender: 'customer', text: 'API documentation link?', time: '1 hour ago' },
      ],
    },
  ];

  const [activeThreadId, setActiveThreadId] = useState('thread-1');
  const activeThread = threads.find((t) => t.id === activeThreadId) || threads[0];
  const [typedMessage, setTypedMessage] = useState('');
  const [statusTab, setStatusTab] = useState<'open' | 'pending' | 'resolved'>('open');

  const handleSendMessage = () => {
    if (!typedMessage.trim()) return;
    activeThread.messages.push({
      sender: 'agent',
      text: typedMessage,
      time: 'Just now',
    });
    activeThread.lastMessage = typedMessage;
    setTypedMessage('');
  };

  const bullets = [
    'WhatsApp, Instagram, Messenger, Telegram, SMS, and Email — all in a single thread list, tagged by channel',
    'Real-time updates, no refreshing required',
    'Assign conversations to teammates or route automatically',
    'Internal notes and @mentions to loop in a colleague without leaving the thread',
    'Start a new conversation proactively with any saved contact',
  ];

  return (
    <section id="unified-inbox" className="relative bg-white py-20 sm:py-24 border-b border-[#E8E8E6] overflow-hidden">
      
      {/* Subtle Premium Blueprint Cross Grid Backdrop Pattern in Light Green Shade */}
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

      {/* Innovative Orbiting Tech Rings using Primary Green */}
      <div className="absolute inset-0 pointer-events-none z-0 overflow-hidden">
        {/* Large Outer Ring */}
        <div className="absolute top-1/2 -right-24 -translate-y-1/2 w-[420px] h-[420px] rounded-full border border-[#4AE54A]/15 animate-[spin_50s_linear_infinite]" />
        
        {/* Inner Dashed Ring */}
        <div className="absolute top-1/2 -right-12 -translate-y-1/2 w-[280px] h-[280px] rounded-full border border-dashed border-[#4AE54A]/25 animate-[spin_25s_linear_infinite]" />
      </div>

      <div className="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-8 items-center">
          
          {/* Left Column: Description & Bullet Points */}
          <motion.div 
            initial={{ opacity: 0, y: 16 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.5 }}
            className="lg:col-span-5 flex flex-col items-start text-left space-y-6"
          >
            <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white border border-[#E8E8E6] text-xs font-bold text-[#0A0A0A] tracking-wide uppercase shadow-2xs">
              <Sparkles className="w-3.5 h-3.5 text-[#0A0A0A]" />
              <span>Unified Inbox</span>
            </div>

            <h2 className="text-3xl sm:text-4xl font-black text-[#0A0A0A] tracking-tight leading-tight">
              Every Conversation. <br />
              One Screen.
            </h2>

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

          {/* Right Column: Compact Tenant Panel Inbox UI Redesign (No Right Contact Sidebar) */}
          <motion.div 
            initial={{ opacity: 0, y: 20 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.6 }}
            className="lg:col-span-7 flex justify-end w-full lg:pl-8"
          >
            <div className="w-full max-w-[620px] bg-white border border-[#E8E8E6] rounded-xl shadow-xl overflow-hidden grid grid-cols-12 h-[420px] text-sans">
              
              {/* PANEL 1: Left Inbox Conversations List Sidebar (Cols: 5) */}
              <div className="col-span-5 border-r border-[#E8E8E6] flex flex-col h-full bg-white shrink-0 overflow-hidden">
                {/* Search & Actions Bar */}
                <div className="p-3 border-b border-[#E8E8E6] bg-white flex flex-col gap-2">
                  <div className="flex gap-2 items-center">
                    <div className="relative flex-1">
                      <Search className="absolute left-2 top-2 h-3 w-3 text-zinc-400" />
                      <input
                        type="text"
                        placeholder="Search chats..."
                        className="w-full pl-6 pr-2 py-1 h-7 text-[10px] border border-[#E8E8E6] rounded-md bg-[#F5F5F5] focus:outline-none focus:bg-white placeholder:text-zinc-400 font-medium"
                        readOnly
                      />
                    </div>
                    <button className="h-7 w-7 rounded-md border border-[#E8E8E6] bg-white flex items-center justify-center text-zinc-650 hover:bg-[#FAFAFA] hover:text-black transition-colors cursor-pointer shrink-0">
                      <Plus className="h-3.5 w-3.5" />
                    </button>
                  </div>
                </div>

                {/* Status Filter Tabs */}
                <div className="flex border-b border-[#E8E8E6] bg-white text-[9px]">
                  {['open', 'pending', 'resolved'].map((tab) => (
                    <button
                      key={tab}
                      onClick={() => setStatusTab(tab as any)}
                      className={`flex-1 text-center py-2 font-bold uppercase tracking-wider border-b-2 transition-all cursor-pointer ${
                        statusTab === tab 
                          ? 'border-black text-black' 
                          : 'border-transparent text-zinc-400 hover:text-zinc-600'
                      }`}
                    >
                      {tab}
                    </button>
                  ))}
                </div>

                {/* Chat List Log */}
                <div className="flex-1 overflow-y-auto divide-y divide-[#E8E8E6]/60 bg-white">
                  {threads.map((thread) => {
                    const isActive = thread.id === activeThreadId;
                    return (
                      <div
                        key={thread.id}
                        onClick={() => setActiveThreadId(thread.id)}
                        className={`flex gap-2.5 p-3 hover:bg-zinc-50 cursor-pointer transition-colors relative ${
                          isActive ? 'bg-[#F4F4F2]' : 'bg-white'
                        }`}
                      >
                        <div className="relative shrink-0">
                          <div className="h-7.5 w-7.5 rounded-full bg-zinc-100 flex items-center justify-center text-zinc-650 font-bold border border-zinc-200 text-[10px] shadow-2xs">
                            {thread.avatar}
                          </div>
                          <div className="absolute -bottom-1 -right-1 h-4 w-4 flex items-center justify-center bg-white rounded-full p-0.5 border border-[#E8E8E6] shadow-2xs">
                            <img
                              src={thread.channelIcon}
                              alt={thread.channel}
                              className="w-2.5 h-2.5 object-contain"
                            />
                          </div>
                        </div>

                        <div className="flex-1 min-w-0">
                          <div className="flex justify-between items-center mb-0.5">
                            <span className={`text-[10px] font-bold truncate ${thread.unread ? 'text-black' : 'text-zinc-800'}`}>
                              {thread.name}
                            </span>
                            <span className="text-[8px] text-zinc-400 shrink-0 font-medium">
                              {thread.time}
                            </span>
                          </div>
                          <p className={`text-[9px] truncate ${thread.unread ? 'text-black font-semibold' : 'text-zinc-500'}`}>
                            {thread.lastMessage}
                          </p>
                        </div>
                        {thread.unread && (
                          <div className="absolute right-3.5 bottom-3.5 h-1 w-1 rounded-full bg-black" />
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>

              {/* PANEL 2: Center Chat Stream Box (Cols: 7 - Expanded to fill mockup container) */}
              <div className="col-span-7 flex flex-col h-full bg-white min-w-0 overflow-hidden relative">
                {/* Header */}
                <div className="p-3 border-b border-[#E8E8E6] flex items-center justify-between bg-white shrink-0">
                  <div className="flex items-center gap-2">
                    <span className="text-[10px] font-bold text-black truncate">{activeThread.name}</span>
                  </div>
                </div>

                {/* Chat Log Feed */}
                <div className="flex-1 overflow-y-auto p-3 bg-white space-y-3">
                  {activeThread.messages.map((msg, index) => {
                    const isInbound = msg.sender === 'customer';
                    return (
                      <div
                        key={index}
                        className={`flex gap-2 max-w-[85%] ${isInbound ? 'mr-auto' : 'ml-auto flex-row-reverse'}`}
                      >
                        {isInbound && (
                          <div className="h-6 w-6 rounded-full bg-zinc-100 flex items-center justify-center text-zinc-500 font-bold border border-zinc-200 text-[9px] shrink-0">
                            {activeThread.avatar}
                          </div>
                        )}
                        <div className="flex flex-col">
                          <div
                            className={`p-2 rounded-xl text-[10px] leading-relaxed shadow-2xs ${
                              isInbound 
                                ? 'bg-[#F4F4F2] text-zinc-800 rounded-tl-none' 
                                : 'bg-black text-white rounded-tr-none'
                            }`}
                          >
                            {msg.text}
                          </div>
                          <span className={`text-[7px] text-zinc-400 mt-1 ${isInbound ? 'text-left' : 'text-right'}`}>
                            {msg.time}
                          </span>
                        </div>
                      </div>
                    );
                  })}
                </div>

                {/* Input Text Box */}
                <div className="p-2 border-t border-[#E8E8E6] bg-white flex items-center gap-1.5 shrink-0">
                  <input
                    type="text"
                    placeholder="Type a message..."
                    value={typedMessage}
                    onChange={(e) => setTypedMessage(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && handleSendMessage()}
                    className="flex-1 text-[10px] h-7 px-2 border rounded-md border-[#E8E8E6] focus:outline-none focus:border-zinc-950 transition-colors"
                  />
                  <button
                    onClick={handleSendMessage}
                    className="h-7 px-2.5 rounded-md bg-black hover:bg-zinc-900 text-white flex items-center justify-center shrink-0 shadow-2xs transition-colors cursor-pointer"
                  >
                    <Send className="w-3 h-3" />
                  </button>
                </div>
              </div>

            </div>
          </motion.div>

        </div>
      </div>
    </section>
  );
}
