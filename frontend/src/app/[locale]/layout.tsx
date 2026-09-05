import { NextIntlClientProvider } from 'next-intl';
import { getMessages } from 'next-intl/server';
import { notFound } from 'next/navigation';
import { routing } from '@/i18n/routing';
import { 
  Plus_Jakarta_Sans, 
  Sarala, 
  Noto_Sans_Arabic, 
  Noto_Sans_JP, 
  Noto_Sans_Hebrew 
} from 'next/font/google';
import { Toaster } from 'sonner';
import NavigationProgressBar from '@/components/layout/NavigationProgressBar';
import CookieConsentBanner from '@/components/layout/CookieConsentBanner';
import BrandingThemeProvider from '@/components/BrandingThemeProvider';
import '../globals.css';

const plusJakartaSans = Plus_Jakarta_Sans({
  subsets: ['latin', 'latin-ext', 'vietnamese'],
  variable: '--font-plus-jakarta-sans',
  weight: ['400', '500', '600', '700', '800'],
});

const sarala = Sarala({
  subsets: ['devanagari'],
  variable: '--font-sarala',
  weight: ['400', '700'],
});

const notoArabic = Noto_Sans_Arabic({
  subsets: ['arabic'],
  variable: '--font-noto-arabic',
  weight: ['400', '500', '600', '700', '800'],
});

const notoJP = Noto_Sans_JP({
  subsets: ['latin'],
  variable: '--font-noto-jp',
  weight: ['400', '500', '700'],
});

const notoHebrew = Noto_Sans_Hebrew({
  subsets: ['hebrew'],
  variable: '--font-noto-hebrew',
  weight: ['400', '500', '600', '700', '800'],
});

export default async function LocaleLayout({
  children,
  params
}: {
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  // Validate that the incoming `locale` parameter is valid
  if (!routing.locales.includes(locale as typeof routing.locales[number])) {
    notFound();
  }

  // Get messages on the server side
  const messages = await getMessages();

  // Set RTL direction if Arabic or Hebrew
  const isRtl = locale === 'ar' || locale === 'he';

  // Map locale to its corresponding font variable name
  const fontVariables: Record<string, string> = {
    hi: 'var(--font-sarala)',
    ar: 'var(--font-noto-arabic)',
    ja: 'var(--font-noto-jp)',
    he: 'var(--font-noto-hebrew)',
  };

  const activeFontVar = fontVariables[locale]
    ? `${fontVariables[locale]}, var(--font-plus-jakarta-sans), sans-serif`
    : 'var(--font-plus-jakarta-sans), sans-serif';

  return (
    <html 
      lang={locale} 
      dir={isRtl ? 'rtl' : 'ltr'} 
      className={`${plusJakartaSans.variable} ${sarala.variable} ${notoArabic.variable} ${notoJP.variable} ${notoHebrew.variable}`}
      style={{
        ['--font-app-sans' as any]: activeFontVar,
      }}
      suppressHydrationWarning
    >
      <body className="font-sans antialiased bg-background text-foreground min-h-screen">
        <BrandingThemeProvider>
          <NextIntlClientProvider messages={messages}>
            <NavigationProgressBar />
            {children}
            <CookieConsentBanner />
            <Toaster position="top-right" richColors />
          </NextIntlClientProvider>
        </BrandingThemeProvider>
      </body>
    </html>
  );
}

