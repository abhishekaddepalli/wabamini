'use client';

import React, { useEffect, useState } from 'react';
import { fetchWithCsrf } from '@/lib/api';
import { 
  Loader2, 
  Save, 
  Upload,
  Paintbrush,
  Sparkles,
  Eye,
  Check
} from 'lucide-react';
import SimpleLoader from '@/components/ui/SimpleLoader';
import { toast } from 'sonner';
import { useTranslations } from 'next-intl';
import { useBranding } from '@/components/BrandingThemeProvider';

export default function CustomizationSettingsPage() {
  const t = useTranslations('Superadmin');
  const { syncBranding } = useBranding();

  const [loading, setLoading] = useState(true);
  const [savingPlatform, setSavingPlatform] = useState(false);
  const [logoUploading, setLogoUploading] = useState(false);

  // Customization fields
  const [brandingName, setBrandingName] = useState('WhatsOmni');
  const [primaryColor, setPrimaryColor] = useState('#4AE54A');
  const [accentColor, setAccentColor] = useState('#0A0A0A');
  const [logoUrl, setLogoUrl] = useState('/logo.svg');

  // Predefined gorgeous palettes for quick select
  const primaryPresets = [
    { name: 'Neon Green', value: '#4AE54A' },
    { name: 'Royal Blue', value: '#3B82F6' },
    { name: 'Violet Glow', value: '#8B5CF6' },
    { name: 'Sunset Rose', value: '#EC4899' },
  ];

  const accentPresets = [
    { name: 'Obsidian Black', value: '#0A0A0A' },
    { name: 'Slate Gray', value: '#475569' },
    { name: 'Deep Navy', value: '#0F172A' },
  ];

  const fetchSettings = async () => {
    setLoading(true);
    try {
      const res = await fetchWithCsrf('/admin/settings');
      if (res.ok) {
        const d = await res.json();
        const settings = d.settings || {};
        setBrandingName(settings.branding_name || 'WhatsOmni');
        setPrimaryColor(settings.primary_color || '#4AE54A');
        setAccentColor(settings.accent_color || '#0A0A0A');
        setLogoUrl(settings.logo_url || '/logo.svg');
      }
    } catch {
      toast.error(t('settings.messages.loadBrandingFailed'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchSettings();
  }, []);

  const handleUpdatePlatform = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingPlatform(true);
    try {
      const res = await fetchWithCsrf('/admin/settings', {
        method: 'POST',
        body: JSON.stringify({
          settings: {
            branding_name: brandingName,
            primary_color: primaryColor,
            accent_color: accentColor,
            logo_url: logoUrl,
          }
        })
      });

      if (res.ok) {
        await syncBranding();
        toast.success(t('settings.saveSuccess'));
      } else {
        toast.error(t('settings.saveFailed'));
      }
    } catch {
      toast.error(t('settings.saveFailed'));
    } finally {
      setSavingPlatform(false);
    }
  };

  const handleLogoUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('logo', file);

    setLogoUploading(true);
    try {
      const res = await fetchWithCsrf('/admin/settings/logo', {
        method: 'POST',
        body: formData,
      });

      if (res.ok) {
        const data = await res.json();
        setLogoUrl(data.url);
        toast.success(t('settings.messages.logoSuccess'));
      } else {
        const errData = await res.json().catch(() => null);
        const errorMsg = errData?.message || (errData?.errors?.logo ? errData.errors.logo[0] : t('settings.messages.logoFailed'));
        toast.error(errorMsg);
      }
    } catch (err: any) {
      toast.error(err?.message || t('settings.messages.logoFailed'));
    } finally {
      setLogoUploading(false);
      e.target.value = '';
    }
  };

  if (loading) {
    return <SimpleLoader message={t('settings.loadingBranding')} />;
  }

  return (
    <div className="w-full select-none text-zinc-750 selection:bg-zinc-100 animate-fade-in font-sans flex-1 overflow-y-auto pb-16">
      
      {/* SECTION 1: Platform Branding Customization */}
      <div className="px-4 sm:px-6 md:px-8 py-6 sm:py-8 border-b border-[#E8E8E6] grid grid-cols-1 md:grid-cols-3 gap-6 md:gap-12">
        
        {/* Left Descriptive Column */}
        <div className="col-span-1 space-y-6">
          <div>
            <h3 className="text-sm font-bold text-zinc-955 uppercase tracking-tight mb-1">{t('settings.platformTitle')}</h3>
            <p className="text-[11px] text-zinc-500 font-semibold leading-relaxed">
              {t('settings.platformHelp')}
            </p>
          </div>
        </div>

        {/* Right Form Fields Column */}
        <form onSubmit={handleUpdatePlatform} className="col-span-1 md:col-span-2 space-y-8 text-left">
          
          {/* Brand Name */}
          <div className="space-y-1.5">
            <label className="text-[10px] text-zinc-500 font-semibold uppercase tracking-wider block">{t('settings.brandingName')}</label>
            <input
              type="text"
              required
              value={brandingName}
              onChange={(e) => setBrandingName(e.target.value)}
              className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs focus:outline-none focus:border-black font-medium transition-colors"
              placeholder="e.g. WhatsOmni"
            />
          </div>

          {/* Logo Uploader Area */}
          <div className="space-y-3 pt-2">
            <label className="text-[10px] text-zinc-500 font-semibold uppercase tracking-wider block">{t('settings.logoLabel')}</label>
            
            <div className="border border-dashed border-[#E8E8E6] hover:border-black rounded-lg p-5 bg-[#FAFAFA] flex items-center gap-6 transition-all duration-300">
              {/* Logo Preview Container */}
              <div className="h-16 w-16 rounded-lg border border-[#E8E8E6] bg-white flex items-center justify-center p-2.5 relative shrink-0 shadow-3xs">
                <img src={logoUrl} alt="Branding Logo" className="h-full w-auto object-contain max-w-full" />
                {logoUploading && (
                  <div className="absolute inset-0 bg-white/70 flex items-center justify-center rounded-lg">
                    <Loader2 className="h-4.5 w-4.5 animate-spin text-black" />
                  </div>
                )}
              </div>

              {/* Upload actions */}
              <div className="flex-1 space-y-2">
                <div className="flex items-center gap-3">
                  <input
                    type="file"
                    accept="image/*"
                    id="logo-upload-input-file"
                    className="hidden"
                    onChange={handleLogoUpload}
                  />
                  <label
                    htmlFor="logo-upload-input-file"
                    className="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-zinc-200 hover:bg-zinc-50 rounded-[6px] text-xs font-semibold shadow-3xs cursor-pointer transition-all"
                  >
                    <Upload className="h-3.5 w-3.5 text-zinc-400" />
                    <span>{t('settings.uploadLogo')}</span>
                  </label>
                </div>
                <p className="text-[10px] text-zinc-400 leading-normal">
                  {t('settings.uploadLogoHelp')}
                </p>
              </div>
            </div>
          </div>

          {/* Submit Action */}
          <div className="flex justify-end pt-4 border-t border-[#E8E8E6]">
            <button
              type="submit"
              disabled={savingPlatform}
              className="flex items-center gap-1.5 px-5 py-2 bg-zinc-955 hover:bg-zinc-900 text-white rounded-[6px] text-xs font-semibold shadow-2xs cursor-pointer transition-all disabled:opacity-50 min-w-[150px] justify-center"
            >
              {savingPlatform ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Save className="h-3.5 w-3.5" />}
              <span>{t('settings.saveChanges')}</span>
            </button>
          </div>
        </form>
      </div>

    </div>
  );
}
