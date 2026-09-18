import { NextResponse } from 'next/server';
import type { NextRequest } from 'next/server';
import createMiddleware from 'next-intl/middleware';
import { routing } from './i18n/routing';

const intlMiddleware = createMiddleware(routing);

export default function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;
  
  // Clean path to remove locale prefix (e.g. /en/settings -> /settings)
  let pathWithoutLocale = pathname;
  const locales = ['en', 'es', 'fr', 'pt', 'id', 'he', 'ar', 'vi', 'hi'];
  const localeMatch = pathname.match(/^\/([a-z]{2})(?:\/|$)/);
  if (localeMatch && locales.includes(localeMatch[1])) {
    pathWithoutLocale = pathname.replace(/^\/[a-z]{2}/, '');
    if (pathWithoutLocale === '') {
      pathWithoutLocale = '/';
    }
  }

  // Check if public logged-in indicator cookie is present
  const hasSession = request.cookies.has('whatsomni_logged_in');
  const hasAdminSession = request.cookies.has('whatsomni_admin_logged_in');

  // Allow verified=1 bypass for email verification redirects
  const isVerifiedRedirect = request.nextUrl.searchParams.get('verified') === '1';

  // Protected paths
  const isProtected = pathWithoutLocale.startsWith('/onboarding') || 
                      pathWithoutLocale.startsWith('/settings') ||
                      pathWithoutLocale.startsWith('/contacts') ||
                      pathWithoutLocale.startsWith('/inbox') ||
                      pathWithoutLocale.startsWith('/crm') ||
                      pathWithoutLocale.startsWith('/billing') ||
                      pathWithoutLocale.startsWith('/channels') ||
                      pathWithoutLocale.startsWith('/dashboard') ||
                      pathWithoutLocale.startsWith('/deals') ||
                      pathWithoutLocale.startsWith('/campaigns');

  const isAdminProtected = pathWithoutLocale.startsWith('/superadmin') && pathWithoutLocale !== '/superadmin/login';

  // If the user has a session, route them to their appropriate workspace status screen
  if (hasSession) {
    const isOnboardingComplete = request.cookies.get('whatsomni_onboarding_completed')?.value === '1';
    const isPlanSelected = request.cookies.get('whatsomni_plan_selected')?.value === '1';

    const isAuthPage = pathWithoutLocale === '/login' || pathWithoutLocale === '/register';
    const isRootPage = pathWithoutLocale === '/';
    const isDashboardPage = pathWithoutLocale.startsWith('/dashboard') || 
                            pathWithoutLocale.startsWith('/settings') ||
                            pathWithoutLocale.startsWith('/contacts') ||
                            pathWithoutLocale.startsWith('/campaigns');
    const isOnboardingPage = pathWithoutLocale.startsWith('/onboarding');
    const isPlansPage = pathWithoutLocale.startsWith('/billing/plans');

    // 1. If on auth pages or root index, redirect them to dashboard/onboarding
    if (isAuthPage || isRootPage) {
      if (isOnboardingComplete && isPlanSelected) {
        return NextResponse.redirect(new URL('/dashboard', request.url));
      } else if (!isOnboardingComplete) {
        return NextResponse.redirect(new URL('/onboarding', request.url));
      } else {
        return NextResponse.redirect(new URL('/dashboard', request.url));
      }
    }

    // 2. If attempting to access onboarding page but already completed, route to dashboard
    if (isOnboardingPage && isOnboardingComplete) {
      return NextResponse.redirect(new URL('/dashboard', request.url));
    }
  }

  const isAdminAuthPage = pathWithoutLocale === '/superadmin/login';
  if (isAdminAuthPage && hasAdminSession) {
    const adminDashboardUrl = new URL('/superadmin/dashboard', request.url);
    return NextResponse.redirect(adminDashboardUrl);
  }

  if (isProtected && !hasSession && !isVerifiedRedirect) {
    const loginUrl = new URL('/login', request.url);
    return NextResponse.redirect(loginUrl);
  }

  if (isAdminProtected && !hasAdminSession) {
    const adminLoginUrl = new URL('/superadmin/login', request.url);
    return NextResponse.redirect(adminLoginUrl);
  }

  return intlMiddleware(request);
}

export const config = {
  // Match all pathnames except for the ones starting with:
  // - healthz (Internal healthcheck endpoint)
  // - api (API routes)
  // - sanctum ( Sanctum CSRF / session endpoint)
  // - broadcasting ( Laravel Reverb authentication endpoint)
  // - _next (Next.js internal files)
  // - static files with extensions (e.g. logo.svg, favicon.ico)
  matcher: ['/((?!healthz|api|sanctum|broadcasting|_next|.*\\..*).*)']
};
