'use client';

import Image from 'next/image';

export function ChannelsStrip() {
  const channels = [
    { 
      name: 'WhatsApp', 
      logo: '/channels/whatsapp.webp',
    },
    { 
      name: 'Instagram', 
      logo: '/channels/instagram.svg',
    },
    { 
      name: 'Messenger', 
      logo: '/channels/messenger.webp',
    },
    { 
      name: 'Telegram', 
      logo: '/channels/telegram.webp',
    },
    { 
      name: 'SMS', 
      logo: '/channels/sms.svg',
    },
    { 
      name: 'Email', 
      logo: '/channels/email.svg',
    },
  ];

  return (
    <section id="channels" className="relative bg-white pt-10 pb-12 border-t border-[#E8E8E6]">
      <div className="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex flex-col items-center space-y-6">
          
          <div className="text-xs font-bold text-[#6B6B6B] uppercase tracking-wider">
            Supported Communication Channels
          </div>

          {/* Official Channel Logos & Names Row (WhatsApp, Instagram, Messenger, Telegram, SMS, Email) */}
          <div className="w-full flex flex-wrap items-center justify-center sm:justify-between gap-6 sm:gap-8">
            {channels.map((channel) => (
              <div 
                key={channel.name}
                className="group flex items-center gap-3 p-2 rounded-xl hover:bg-[#FAFAFA] transition-all duration-200 cursor-pointer"
              >
                <div className="relative w-8 h-8 flex items-center justify-center group-hover:scale-110 transition-transform">
                  <Image 
                    src={channel.logo}
                    alt={`${channel.name} Logo`}
                    width={32}
                    height={32}
                    className="w-7 h-7 object-contain"
                  />
                </div>
                <span className="text-base sm:text-lg font-black tracking-tight text-[#0A0A0A]">
                  {channel.name}
                </span>
              </div>
            ))}
          </div>

        </div>
      </div>
    </section>
  );
}
