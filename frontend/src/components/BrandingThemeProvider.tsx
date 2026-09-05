'use client';

import React, { createContext, useContext, useState, useEffect } from 'react';
import { fetchWithCsrf } from '@/lib/api';
import { BRANDING_CONFIG } from '@/config/branding';

interface Branding {
  brandingName: string;
  logoUrl: string;
  primaryColor: string;
  accentColor: string;
}

interface BrandingContextType extends Branding {
  syncBranding: () => Promise<void>;
}

const BrandingContext = createContext<BrandingContextType>({
  brandingName: BRANDING_CONFIG.defaultName,
  logoUrl: BRANDING_CONFIG.defaultLogo,
  primaryColor: BRANDING_CONFIG.defaultPrimaryColor,
  accentColor: BRANDING_CONFIG.defaultAccentColor,
  syncBranding: async () => {},
});

export const useBranding = () => useContext(BrandingContext);

export default function BrandingThemeProvider({ children }: { children: React.ReactNode }) {
  const [branding, setBranding] = useState<Branding>({
    brandingName: BRANDING_CONFIG.defaultName,
    logoUrl: BRANDING_CONFIG.defaultLogo,
    primaryColor: BRANDING_CONFIG.defaultPrimaryColor,
    accentColor: BRANDING_CONFIG.defaultAccentColor,
  });

  const loadBranding = async () => {
    try {
      const res = await fetchWithCsrf('/platform/settings');
      if (res.ok) {
        const data = await res.json();
        const brandingName = data.branding_name || BRANDING_CONFIG.defaultName;
        const logoUrl = data.logo_url || BRANDING_CONFIG.defaultLogo;
        const primaryColor = BRANDING_CONFIG.defaultPrimaryColor;
        const accentColor = BRANDING_CONFIG.defaultAccentColor;

        setBranding({ brandingName, logoUrl, primaryColor, accentColor });

        // Update root CSS custom properties strictly using local brand colors
        document.documentElement.style.setProperty('--accent-green', BRANDING_CONFIG.styleTokens.accentGreen);
        document.documentElement.style.setProperty('--accent-green-2', BRANDING_CONFIG.styleTokens.accentGreenHover);
        document.documentElement.style.setProperty('--border-focus', BRANDING_CONFIG.styleTokens.borderFocus);
        document.documentElement.style.setProperty('--fg-primary', BRANDING_CONFIG.styleTokens.fgPrimary);
      }
    } catch (err) {
      console.error('Failed to load platform branding settings:', err);
    }
  };

  useEffect(() => {
    loadBranding();
  }, []);

  return (
    <BrandingContext.Provider value={{ ...branding, syncBranding: loadBranding }}>
      {children}
    </BrandingContext.Provider>
  );
}
