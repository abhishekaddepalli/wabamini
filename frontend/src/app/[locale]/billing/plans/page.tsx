'use client';

import React, { useEffect, useState, useTransition, Suspense, useMemo } from 'react';
import Image from 'next/image';
import { useRouter } from '@/i18n/routing';
import { useSearchParams } from 'next/navigation';
import { 
  RefreshCw, 
  LogOut, 
  UserPlus, 
  Megaphone, 
  Radio, 
  Zap, 
  CreditCard
} from 'lucide-react';
import SimpleLoader from '@/components/ui/SimpleLoader';
import { fetchWithCsrf } from '@/lib/api';
import { toast } from 'sonner';
import { useTranslations } from 'next-intl';
import { SubscriptionPlanCard, PlanDetails } from '@/components/billing/SubscriptionPlanCard';

function PricingSelectionScreen() {
  const t = useTranslations();
  const tPricing = useTranslations('PricingPage');
  const tPlanCard = useTranslations('PlanCard');
  const router = useRouter();
  const searchParams = useSearchParams();
  const [plans, setPlans] = useState<PlanDetails[]>([]);
  const [currentPlanId, setCurrentPlanId] = useState<number | null>(null);
  const [currentPlanAmount, setCurrentPlanAmount] = useState<number | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [redirecting, setRedirecting] = useState<boolean>(false);
  const [actionLoadingId, setActionLoadingId] = useState<string | null>(null);
  const [billingInterval, setBillingInterval] = useState<'month' | 'year'>('month');
  const [isPending, startTransition] = useTransition();

  const maxSavingsPercent = useMemo(() => {
    let maxSaved = 0;
    for (const p of plans) {
      const m = p.prices?.find(pr => pr.interval === 'month');
      const y = p.prices?.find(pr => pr.interval === 'year');
      if (m && y && m.amount > 0) {
        const mAnnual = m.amount * 12;
        if (y.amount < mAnnual) {
          const saved = Math.round(((mAnnual - y.amount) / mAnnual) * 100);
          if (saved > maxSaved) maxSaved = saved;
        }
      }
    }
    return maxSaved;
  }, [plans]);

  const loadPlans = async () => {
    try {
      const sessionId = searchParams.get('session_id') 
        || searchParams.get('razorpay_subscription_id') 
        || searchParams.get('razorpay_payment_id')
        || searchParams.get('paystack_reference')
        || searchParams.get('reference')
        || searchParams.get('trxref')
        || searchParams.get('transaction_id')
        || searchParams.get('tx_ref')
        || searchParams.get('flutterwave_reference');

      const queryParams = new URLSearchParams();
      searchParams.forEach((val, key) => {
        if (val && val !== '{CHECKOUT_SESSION_ID}') {
          queryParams.set(key, val);
        }
      });

      const queryString = queryParams.toString();
      const subUrl = queryString ? `/billing/subscription?${queryString}` : '/billing/subscription';

      const [plansRes, subRes] = await Promise.all([
        fetchWithCsrf('/billing/plans'),
        fetchWithCsrf(subUrl).catch(() => null),
      ]);

      if (plansRes && plansRes.ok) {
        const data = await plansRes.json();
        setPlans(data.plans || []);
      }

      if (subRes && subRes.ok) {
        const subData = await subRes.json();
        if (subData?.plan?.id) {
          setCurrentPlanId(subData.plan.id);
          if (subData?.price?.interval) {
            setBillingInterval(subData.price.interval as 'month' | 'year');
          }
          if (subData?.price?.amount !== undefined) {
            setCurrentPlanAmount(subData.price.amount);
          }

          // If we just verified a checkout session/callback, refresh user session and navigate to dashboard
          if (sessionId) {
            await fetchWithCsrf('/auth/me');
            toast.success('Subscription activated successfully!', {
              description: `Welcome to the ${subData.plan.name} plan.`,
            });
            router.push('/dashboard');
            return;
          }
        }
      }

      if (searchParams.get('checkout') === 'cancelled') {
        toast.warning('Checkout was cancelled.');
      }
    } catch {
      toast.error('Failed to retrieve subscription plan tiers.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadPlans();
  }, [searchParams]);

  const loadRazorpayScript = (): Promise<boolean> => {
    return new Promise((resolve) => {
      if (typeof window === 'undefined') return resolve(false);
      if ((window as any).Razorpay) return resolve(true);
      const script = document.createElement('script');
      script.src = 'https://checkout.razorpay.com/v1/checkout.js';
      script.onload = () => resolve(true);
      script.onerror = () => resolve(false);
      document.body.appendChild(script);
    });
  };

  const loadPaystackScript = (): Promise<boolean> => {
    return new Promise((resolve) => {
      if (typeof window === 'undefined') return resolve(false);
      if ((window as any).PaystackPop) return resolve(true);
      const script = document.createElement('script');
      script.src = 'https://js.paystack.co/v1/inline.js';
      script.onload = () => resolve(true);
      script.onerror = () => resolve(false);
      document.body.appendChild(script);
    });
  };

  const handleCheckout = (stripePriceId?: string, planId?: number, priceDbId?: number) => {
    if (!planId && !stripePriceId && !priceDbId) return;

    const actionKey = stripePriceId || (priceDbId ? `price_plan_${planId}_${priceDbId}` : `price_plan_${planId}`);
    setActionLoadingId(actionKey);

    startTransition(async () => {
      try {
        const response = await fetchWithCsrf('/billing/checkout', {
          method: 'POST',
          body: JSON.stringify({
            stripe_price_id: stripePriceId,
            price_id: priceDbId,
            plan_id: planId
          }),
        });

        const data = await response.json();

        if (response.ok) {
          if (data.immediate) {
            toast.success('Subscription plan changed successfully', {
              description: data.message,
            });
            await loadPlans();
            router.push('/dashboard');
          } else if (data.gateway === 'paystack') {
            const checkoutUrl = data.authorization_url || data.checkout_url;
            if (checkoutUrl) {
              window.location.href = checkoutUrl;
            } else {
              throw new Error('Could not initialize Paystack checkout session.');
            }
          } else if (data.gateway === 'flutterwave') {
            const checkoutUrl = data.authorization_url || data.checkout_url;
            if (checkoutUrl) {
              window.location.href = checkoutUrl;
            } else {
              throw new Error('Could not initialize Flutterwave checkout session.');
            }
          } else if (data.subscription_id && data.gateway === 'razorpay') {
            const isLoaded = await loadRazorpayScript();
            if (!isLoaded) {
              throw new Error('Could not load Razorpay checkout SDK. Please check your internet connection.');
            }

            const options = {
              key: data.key_id,
              subscription_id: data.subscription_id,
              name: data.name || 'WhatsOmni',
              description: data.description || 'Recurring Subscription',
              image: '/favicon.ico',
              prefill: data.customer || {},
              theme: data.theme || { color: '#000000' },
              handler: async function (res: any) {
                try {
                  setActionLoadingId(actionKey);
                  const verifyRes = await fetchWithCsrf('/billing/razorpay/verify', {
                    method: 'POST',
                    body: JSON.stringify({
                      razorpay_payment_id: res.razorpay_payment_id,
                      razorpay_subscription_id: res.razorpay_subscription_id,
                      razorpay_signature: res.razorpay_signature,
                      plan_id: planId,
                    }),
                  });
                  const verifyData = await verifyRes.json();
                  if (verifyRes.ok) {
                    toast.success('Recurring subscription activated successfully!');
                    await loadPlans();
                    window.location.href = '/dashboard';
                  } else {
                    toast.error(verifyData.message || 'Signature verification failed.');
                  }
                } catch {
                  toast.error('Payment verification failed.');
                } finally {
                  setActionLoadingId(null);
                }
              },
              modal: {
                ondismiss: function () {
                  setActionLoadingId(null);
                }
              }
            };

            const rzp = new (window as any).Razorpay(options);
            rzp.open();
          } else if (data.checkout_url) {
            window.location.href = data.checkout_url;
          }
        } else {
          throw new Error(data.message || 'Unable to establish checkout flow.');
        }
      } catch (err) {
        const msg = err instanceof Error ? err.message : 'Checkout failed.';
        toast.error(msg);
      } finally {
        setActionLoadingId(null);
      }
    });
  };

  const handleLogout = async () => {
    try {
      const response = await fetchWithCsrf('/auth/logout', { method: 'POST' });
      if (response.ok) {
        setRedirecting(true);
        localStorage.removeItem('whatsomni_token');
        router.push('/login');
      }
    } catch {
      toast.error(t('toasts.logoutFailed'));
    }
  };

  if (loading) {
    return <SimpleLoader fullScreen={true} message={tPricing('loadingOptions')} />;
  }

  if (redirecting) {
    return (
      <div className="fixed inset-0 bg-white/80 backdrop-blur-md z-50 flex flex-col items-center justify-center space-y-4 select-none pointer-events-auto">
        <div className="w-10 h-10 border-4 border-[#0A0A0A] border-t-transparent rounded-full animate-spin" />
        <p className="text-sm font-bold text-black tracking-tight animate-pulse">{tPricing('loggingOut')}</p>
      </div>
    );
  }

  return (
    <div className="min-h-screen w-screen bg-[#FAFAFA] flex flex-col justify-between p-4 md:p-6 lg:p-8 font-sans relative overflow-x-hidden select-none">
      {/* Floating Logout Button */}
      <button 
        onClick={handleLogout}
        title={tPricing('logoutSession')}
        className="absolute top-6 right-6 z-40 p-2 text-zinc-400 hover:text-red-650 hover:bg-zinc-100 rounded-lg transition-all cursor-pointer"
      >
        <LogOut className="h-5 w-5" />
      </button>

      {/* Pricing Header Area */}
      <div className="w-full max-w-6xl mx-auto flex-1 flex flex-col justify-center py-10 relative z-10">
        <div className="text-center space-y-3 mb-10">
          <Image src="/logo.svg" alt="WhatsOmni Logo" width={40} height={32} className="h-9 w-auto mx-auto mb-3" />
          <h1 className="text-3xl sm:text-4xl font-extrabold tracking-tight text-zinc-950">
            {tPricing('choosePlanTitle')}
          </h1>
          <p className="text-sm text-zinc-500 max-w-lg mx-auto">
            {tPricing('choosePlanSubtitle')}
          </p>

          {/* Monthly / Yearly Toggle */}
          <div className="inline-flex p-1 bg-zinc-100 border border-zinc-200/80 rounded-full select-none mt-6">
            <button
              type="button"
              onClick={() => setBillingInterval('month')}
              className={`px-5 py-2 text-xs font-bold rounded-full transition-all cursor-pointer ${
                billingInterval === 'month' 
                  ? 'bg-black text-white shadow-xs' 
                  : 'text-zinc-500 hover:text-zinc-900'
              }`}
            >
              {tPricing('monthlyBilling')}
            </button>
            <button
              type="button"
              onClick={() => setBillingInterval('year')}
              className={`px-5 py-2 text-xs font-bold rounded-full transition-all cursor-pointer flex items-center gap-1.5 ${
                billingInterval === 'year' 
                  ? 'bg-black text-white shadow-xs' 
                  : 'text-zinc-500 hover:text-zinc-900'
              }`}
            >
              <span>{tPricing('annualBilling')}</span>
              {maxSavingsPercent > 0 && (
                <span className="text-[9px] font-extrabold px-2 py-0.5 rounded-full bg-[#4AE54A] text-black">
                  {tPlanCard('saveDiscount', { percent: maxSavingsPercent })}
                </span>
              )}
            </button>
          </div>
        </div>

        {/* Dynamic Multi-Column Plan Grid */}
        <div className={`grid gap-6 mx-auto w-full items-stretch ${
          plans.length === 1 
            ? 'grid-cols-1 max-w-sm' 
            : plans.length === 2 
              ? 'grid-cols-1 md:grid-cols-2 max-w-2xl' 
              : 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3 max-w-6xl'
        }`}>
          {plans.map((plan, index) => {
            const isPopular = index === 1 || plan.name.toLowerCase().includes('growth') || plan.name.toLowerCase().includes('pro');
            const isCurrent = currentPlanId === plan.id;
            const currentPlanInList = plans.find(p => p.id === currentPlanId);
            const currentPriceForInterval = currentPlanInList?.prices.find(p => p.interval === billingInterval);
            const effCurrentPlanAmount = currentPriceForInterval?.amount ?? currentPlanAmount;
            const hasActiveSubscription = Boolean(currentPlanId);
            const activePrice = plan.prices.find(p => p.interval === billingInterval) || plan.prices[0];
            const effectivePriceId = activePrice?.stripe_price_id || (activePrice?.id ? `price_plan_${plan.id}_${activePrice.id}` : `price_plan_${plan.id}`);
            const isCardLoading = actionLoadingId === effectivePriceId || actionLoadingId === String(plan.id);

            return (
              <SubscriptionPlanCard
                key={plan.id}
                plan={plan}
                billingInterval={billingInterval}
                isCurrent={isCurrent}
                currentPlanAmount={effCurrentPlanAmount}
                currentPlanId={currentPlanId}
                hasActiveSubscription={hasActiveSubscription}
                isPopular={isPopular}
                isLoading={isCardLoading}
                onSelect={(priceId, planId, priceDbId) => handleCheckout(priceId, planId, priceDbId)}
              />
            );
          })}
        </div>
      </div>
    </div>
  );
}

export default function PricingSelectionPage() {
  return (
    <Suspense fallback={<SimpleLoader fullScreen={true} />}>
      <PricingSelectionScreen />
    </Suspense>
  );
}
