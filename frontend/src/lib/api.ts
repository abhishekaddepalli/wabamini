const BACKEND_URL = typeof window !== 'undefined'
  ? ''
  : (process.env.NEXT_PUBLIC_BACKEND_URL || 'http://127.0.0.1:8000');

/**
 * Helper to retrieve the XSRF-TOKEN cookie set by Laravel.
 */
function getXsrfToken(): string {
  if (typeof document === 'undefined') return '';
  const match = document.cookie.match(new RegExp('(^| )XSRF-TOKEN=([^;]+)'));
  if (match) {
    return decodeURIComponent(match[2]);
  }
  return '';
}

/**
 * Perform a fetch request to the Laravel backend, automatically
 * negotiating CSRF cookie initialization and header mapping for state-modifying requests.
 */
export async function fetchWithCsrf(path: string, options: RequestInit = {}) {
  const method = options.method?.toUpperCase() || 'GET';

  // State-modifying requests require CSRF cookie initialization
  if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
    await fetch(`${BACKEND_URL}/sanctum/csrf-cookie`, {
      method: 'GET',
      credentials: 'include',
    });
  }

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    ...(options.headers as Record<string, string>),
  };

  if (options.body instanceof FormData) {
    delete headers['Content-Type'];
  }

  // Extract the cookie set by Laravel and append it manually as the X-XSRF-TOKEN header
  const xsrfToken = getXsrfToken();
  if (xsrfToken) {
    headers['X-XSRF-TOKEN'] = xsrfToken;
  }

  // Include Bearer Token if saved locally to support token-based authentication fallback
  if (typeof window !== 'undefined') {
    const token = localStorage.getItem('whatsomni_token');
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const localeMatch = window.location.pathname.match(/^\/([a-z]{2})(\/|$)/);
    if (localeMatch && localeMatch[1]) {
      headers['Accept-Language'] = localeMatch[1];
    }
  }

  const response = await fetch(`${BACKEND_URL}/api${path}`, {
    ...options,
    credentials: 'include',
    headers,
  });

  const getCookieDomains = () => {
    if (typeof window === 'undefined') return [''];
    const host = window.location.hostname;
    return Array.from(new Set(['', `; domain=${host}`, `; domain=.${host}`]));
  };

  if (response.status === 401 && path.includes('/auth/me') && typeof document !== 'undefined') {
    console.warn(`[API 401] Unauthorized response received for path: ${path}`);
    getCookieDomains().forEach(d => {
      document.cookie = `whatsomni_logged_in=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT${d};`;
      document.cookie = `whatsomni_onboarding_completed=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT${d};`;
      document.cookie = `whatsomni_plan_selected=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT${d};`;
    });
  }

  if (response.ok && path === '/auth/logout' && typeof document !== 'undefined') {
    getCookieDomains().forEach(d => {
      document.cookie = `whatsomni_logged_in=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT${d};`;
      document.cookie = `whatsomni_onboarding_completed=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT${d};`;
      document.cookie = `whatsomni_plan_selected=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT${d};`;
    });
  }

  if (response.ok && path === '/admin/logout' && typeof document !== 'undefined') {
    getCookieDomains().forEach(d => {
      document.cookie = `whatsomni_admin_logged_in=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT${d};`;
    });
  }

  if (response.ok && path.includes('/auth/me') && typeof document !== 'undefined') {
    try {
      const clone = response.clone();
      clone.json().then(data => {
        const tenant = data.user?.tenant;
        if (tenant) {
          const onboardingComplete = (tenant.onboarding_step === 'complete' || tenant.onboarding_step === '3') ? '1' : '0';
          const planSelected = tenant.plan_id ? '1' : '0';
          getCookieDomains().forEach(d => {
            document.cookie = `whatsomni_onboarding_completed=${onboardingComplete}; path=/; max-age=31536000${d}`;
            document.cookie = `whatsomni_plan_selected=${planSelected}; path=/; max-age=31536000${d}`;
          });
        }
      }).catch(() => {});
    } catch (e) {
      console.error('Failed to sync onboarding cookies:', e);
    }
  }

  return response;
}

/**
 * Return the raw backend base URL for direct resources.
 */
export function getBackendUrl(): string {
  return BACKEND_URL;
}
