import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

if (typeof window !== 'undefined') {
  window.Pusher = Pusher;
}

export const echo = typeof window !== 'undefined' 
  ? new Echo({
      broadcaster: 'reverb',
      key: process.env.NEXT_PUBLIC_REVERB_APP_KEY || 'pnwnojgni5w8zuxrnhe5',
      wsHost: process.env.NEXT_PUBLIC_REVERB_HOST || 'localhost',
      wsPort: process.env.NEXT_PUBLIC_REVERB_PORT ? Number(process.env.NEXT_PUBLIC_REVERB_PORT) : 8080,
      wssPort: process.env.NEXT_PUBLIC_REVERB_PORT ? Number(process.env.NEXT_PUBLIC_REVERB_PORT) : 8080,
      forceTLS: process.env.NEXT_PUBLIC_REVERB_SCHEME === 'https',
      enabledTransports: ['ws', 'wss'],
      authorizer: (channel: { name: string }, options: any) => {
        return {
          authorize: (socketId: string, callback: (error: any, authData: any) => void) => {
            const isAdminChannel = channel.name.startsWith('admin.');
            const backendUrl = typeof window !== 'undefined' ? '' : (process.env.NEXT_PUBLIC_BACKEND_URL || 'http://127.0.0.1:8000');
            
            const authUrl = isAdminChannel
              ? '/broadcasting/auth'
              : '/api/broadcasting/auth';
            
            const headers: Record<string, string> = {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
            };

            if (isAdminChannel) {
              // Read XSRF-TOKEN cookie to support Laravel web middleware group CSRF check
              if (typeof document !== 'undefined') {
                const match = document.cookie.match(new RegExp('(^| )XSRF-TOKEN=([^;]+)'));
                const xsrfToken = match ? decodeURIComponent(match[2]) : '';
                if (xsrfToken) {
                  headers['X-XSRF-TOKEN'] = xsrfToken;
                }
              }
            } else {
              const token = localStorage.getItem('whatsomni_token');
              if (token) {
                headers['Authorization'] = `Bearer ${token}`;
              }
            }
            
            fetch(authUrl, {
              method: 'POST',
              headers,
              credentials: 'include',
              body: JSON.stringify({
                socket_id: socketId,
                channel_name: channel.name,
              }),
            })
            .then((res) => {
              if (!res.ok) {
                throw new Error('Unauthorized');
              }
              return res.json();
            })
            .then((data) => {
              callback(null, data);
            })
            .catch((err) => {
              callback(err, null);
            });
          },
        };
      },
    })
  : null;
