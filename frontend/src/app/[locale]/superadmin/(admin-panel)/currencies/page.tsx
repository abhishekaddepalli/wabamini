'use client';

import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { fetchWithCsrf } from '@/lib/api';
import { 
  Coins, 
  Plus, 
  Trash2, 
  X, 
  RefreshCw, 
  Search, 
  Star, 
  Building2, 
  CreditCard,
  Inbox
} from 'lucide-react';
import { toast } from 'sonner';
import { useTranslations } from 'next-intl';
import SimpleLoader from '@/components/ui/SimpleLoader';
import { DropdownSelect } from '@/components/ui/dropdown-select';

interface Currency {
  id: number;
  code: string;
  symbol: string;
  name: string;
  is_active: boolean;
  is_default: boolean;
  pricing_count?: number;
  tenant_count?: number;
  created_at?: string;
  updated_at?: string;
}

export default function CurrenciesPage() {
  const t = useTranslations('Superadmin');

  const [currencies, setCurrencies] = useState<Currency[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [actionLoadingId, setActionLoadingId] = useState<number | null>(null);

  // Add Currency Modal state
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [newCode, setNewCode] = useState('');
  const [newSymbol, setNewSymbol] = useState('');
  const [newName, setNewName] = useState('');
  const [newIsActive, setNewIsActive] = useState(true);
  const [newIsDefault, setNewIsDefault] = useState(false);
  const [addLoading, setAddLoading] = useState(false);

  // Delete confirmation Modal state
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [currencyToDelete, setCurrencyToDelete] = useState<Currency | null>(null);
  const [deleteLoading, setDeleteLoading] = useState(false);

  // Switch Primary Currency Modal state
  const [isSwitchPrimaryModalOpen, setIsSwitchPrimaryModalOpen] = useState(false);
  const [targetPrimaryCurrency, setTargetPrimaryCurrency] = useState<Currency | null>(null);
  const [switchPlans, setSwitchPlans] = useState<{ id: number; name: string; description: string | null; monthly_amount: string; yearly_amount: string }[]>([]);
  const [switchLoading, setSwitchLoading] = useState(false);
  const [fetchingPlans, setFetchingPlans] = useState(false);

  const fetchCurrencies = async () => {
    setLoading(true);
    try {
      const res = await fetchWithCsrf('/admin/currencies');
      if (res.ok) {
        const data = await res.json();
        setCurrencies(data.currencies || []);
      } else {
        toast.error(t('currencies.loadFailed'));
      }
    } catch {
      toast.error(t('currencies.loadFailed'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchCurrencies();
  }, []);

  const handleToggleActive = async (currency: Currency) => {
    if (currency.is_default && currency.is_active) {
      toast.error('Default platform currency cannot be deactivated.');
      return;
    }

    setActionLoadingId(currency.id);
    try {
      const res = await fetchWithCsrf(`/admin/currencies/${currency.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          is_active: !currency.is_active,
        }),
      });

      if (res.ok) {
        toast.success(t('currencies.updateSuccess'));
        fetchCurrencies();
      } else {
        const err = await res.json();
        toast.error(err.message || t('currencies.updateFailed'));
      }
    } catch {
      toast.error(t('currencies.updateFailed'));
    } finally {
      setActionLoadingId(null);
    }
  };

  const handleInitiateSetDefault = async (currency: Currency) => {
    if (currency.is_default) return;

    setTargetPrimaryCurrency(currency);
    setFetchingPlans(true);
    setIsSwitchPrimaryModalOpen(true);

    try {
      const res = await fetchWithCsrf('/admin/plans');
      if (res.ok) {
        const data = await res.json();
        const plansList = data.plans || [];
        const initialPlans = plansList.map((p: any) => {
          const existingMonthly = p.prices?.find((price: any) => price.billing_interval === 'month');
          const existingYearly = p.prices?.find((price: any) => price.billing_interval === 'year');
          return {
            id: p.id,
            name: p.name,
            description: p.description,
            monthly_amount: existingMonthly ? String(existingMonthly.amount / 100) : '29',
            yearly_amount: existingYearly ? String(existingYearly.amount / 100) : '290',
          };
        });
        setSwitchPlans(initialPlans);
      } else {
        toast.error(t('currencies.loadFailed'));
      }
    } catch {
      toast.error(t('currencies.loadFailed'));
    } finally {
      setFetchingPlans(false);
    }
  };

  const handleConfirmSwitchPrimary = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!targetPrimaryCurrency) return;

    setSwitchLoading(true);
    try {
      const planPricesPayload = switchPlans.map((p) => ({
        plan_id: p.id,
        monthly_amount: Math.round((Number(p.monthly_amount) || 0) * 100),
        yearly_amount: Math.round((Number(p.yearly_amount) || 0) * 100),
      }));

      const res = await fetchWithCsrf(`/admin/currencies/${targetPrimaryCurrency.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          is_default: true,
          is_active: true,
          plan_prices: planPricesPayload,
        }),
      });

      if (res.ok) {
        toast.success(t('currencies.primaryUpdated'));
        setIsSwitchPrimaryModalOpen(false);
        setTargetPrimaryCurrency(null);
        fetchCurrencies();
      } else {
        const err = await res.json();
        toast.error(err.message || t('currencies.updateFailed'));
      }
    } catch {
      toast.error(t('currencies.updateFailed'));
    } finally {
      setSwitchLoading(false);
    }
  };

  const handleCreateCurrency = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newCode.trim() || !newSymbol.trim() || !newName.trim()) {
      toast.error('Please fill in all currency fields.');
      return;
    }

    setAddLoading(true);
    try {
      const res = await fetchWithCsrf('/admin/currencies', {
        method: 'POST',
        body: JSON.stringify({
          code: newCode.trim().toUpperCase(),
          symbol: newSymbol.trim(),
          name: newName.trim(),
          is_active: newIsActive,
          is_default: newIsDefault,
        }),
      });

      if (res.ok) {
        toast.success(t('currencies.createSuccess'));
        setIsAddModalOpen(false);
        setNewCode('');
        setNewSymbol('');
        setNewName('');
        setNewIsActive(true);
        setNewIsDefault(false);
        fetchCurrencies();
      } else {
        const err = await res.json();
        toast.error(err.message || 'Failed to create currency.');
      }
    } catch {
      toast.error('Failed to create currency.');
    } finally {
      setAddLoading(false);
    }
  };

  const handleDeleteCurrency = async () => {
    if (!currencyToDelete) return;
    setDeleteLoading(true);
    try {
      const res = await fetchWithCsrf(`/admin/currencies/${currencyToDelete.id}`, {
        method: 'DELETE',
      });

      if (res.ok) {
        toast.success(t('currencies.deleteSuccess'));
        setIsDeleteModalOpen(false);
        setCurrencyToDelete(null);
        fetchCurrencies();
      } else {
        const err = await res.json();
        toast.error(err.message || t('currencies.deleteFailed'));
      }
    } catch {
      toast.error(t('currencies.deleteFailed'));
    } finally {
      setDeleteLoading(false);
    }
  };

  const filteredCurrencies = currencies.filter((c) => {
    const q = search.toLowerCase();
    const matchesSearch = 
      c.code.toLowerCase().includes(q) ||
      c.name.toLowerCase().includes(q) ||
      c.symbol.toLowerCase().includes(q);

    if (!matchesSearch) return false;
    if (statusFilter === 'active') return c.is_active;
    if (statusFilter === 'disabled') return !c.is_active;
    if (statusFilter === 'default') return c.is_default;
    return true;
  });

  if (loading) {
    return <SimpleLoader message={t('dashboard.loading')} />;
  }

  return (
    <div className="w-full text-zinc-750 selection:bg-zinc-100 select-none animate-fade-in flex flex-col min-h-0 flex-1">
      <div className="w-full p-8 space-y-6 flex flex-col min-h-0 flex-1 overflow-y-auto">
        
        {/* Search, Filter & Action Row */}
        <div className="flex justify-between items-center select-none">
          <div className="flex items-center gap-3">
            <div className="relative">
              <Search className="absolute left-3 top-2.5 h-4 w-4 text-zinc-400" />
              <input
                type="text"
                placeholder={t('dashboard.searchPlaceholder')}
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-9 pr-4 h-9 w-64 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs focus:outline-none focus:border-black font-semibold transition-colors"
              />
            </div>

            <DropdownSelect
              value={statusFilter}
              onChange={(val) => setStatusFilter(val)}
              placeholder="All Statuses"
              options={[
                { value: '', label: 'All Statuses' },
                { value: 'active', label: t('currencies.active') },
                { value: 'disabled', label: t('currencies.disabled') },
                { value: 'default', label: t('currencies.defaultBadge') },
              ]}
              className="w-auto min-w-[145px]"
            />
          </div>

          <button
            onClick={() => setIsAddModalOpen(true)}
            className="flex items-center gap-1.5 px-4 py-1.5 bg-zinc-955 hover:bg-zinc-900 text-white rounded-[6px] text-xs font-semibold shadow-3xs cursor-pointer transition-all"
          >
            <Plus className="h-3.5 w-3.5" />
            <span>{t('currencies.addCurrency')}</span>
          </button>
        </div>

        {/* Currencies Datatable */}
        {filteredCurrencies.length === 0 ? (
          <div className="p-16 text-center space-y-3 border border-[#E8E8E6] rounded-[10px] bg-white shadow-3xs">
            <Inbox className="h-10 w-10 text-zinc-300 mx-auto" />
            <p className="text-xs text-zinc-405 font-semibold">{t('currencies.noCurrencies')}</p>
          </div>
        ) : (
          <div className="border border-[#E8E8E6] rounded-[10px] overflow-hidden bg-white shadow-3xs">
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse text-xs select-none">
                <thead>
                  <tr className="bg-zinc-50 border-b border-[#E8E8E6] text-zinc-500 font-semibold text-[11px] tracking-tight uppercase">
                    <th className="px-6 py-3.5">{t('currencies.code')}</th>
                    <th className="px-6 py-3.5">{t('currencies.symbol')}</th>
                    <th className="px-6 py-3.5">Linked Plans</th>
                    <th className="px-6 py-3.5">Tenant Workspaces</th>
                    <th className="px-6 py-3.5 text-center">Default Base</th>
                    <th className="px-6 py-3.5 text-center">{t('dashboard.status')}</th>
                    <th className="px-6 py-3.5 text-right">{t('dashboard.actions')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#E8E8E6]">
                  {filteredCurrencies.map((curr) => (
                    <tr key={curr.id} className="hover:bg-zinc-50/50 transition-colors">
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className="h-8 w-8 rounded-[6px] bg-zinc-50 border border-[#E8E8E6] flex items-center justify-center font-mono font-bold text-xs text-zinc-955 shadow-3xs">
                            {curr.code}
                          </div>
                          <div>
                            <div className="flex items-center gap-1.5">
                              <span className="font-bold text-zinc-955">{curr.name}</span>
                              {curr.is_default && (
                                <span className="text-[9px] font-black uppercase bg-zinc-900 text-white px-1.5 py-0.5 rounded-[4px] tracking-wide">
                                  Default
                                </span>
                              )}
                            </div>
                            <span className="text-[10px] text-zinc-400 font-mono block mt-0.5">ISO {curr.code}</span>
                          </div>
                        </div>
                      </td>

                      <td className="px-6 py-4">
                        <span className="font-mono font-bold text-xs text-zinc-900 bg-zinc-50 border border-[#E8E8E6] px-2 py-1 rounded-[4px]">
                          {curr.symbol}
                        </span>
                      </td>

                      <td className="px-6 py-4">
                        <div className="flex items-center gap-1.5 text-zinc-650 font-semibold">
                          <CreditCard className="h-3.5 w-3.5 text-zinc-400" />
                          <span className="font-mono text-zinc-900 font-bold">{curr.pricing_count ?? 0}</span>
                          <span className="text-zinc-400 text-[11px]">plans</span>
                        </div>
                      </td>

                      <td className="px-6 py-4">
                        <div className="flex items-center gap-1.5 text-zinc-650 font-semibold">
                          <Building2 className="h-3.5 w-3.5 text-zinc-400" />
                          <span className="font-mono text-zinc-900 font-bold">{curr.tenant_count ?? 0}</span>
                          <span className="text-zinc-400 text-[11px]">workspaces</span>
                        </div>
                      </td>

                      <td className="px-6 py-4 text-center">
                        {curr.is_default ? (
                          <span className="inline-flex items-center gap-1 text-[11px] font-bold text-zinc-955">
                            <Star className="h-3.5 w-3.5 text-amber-500 fill-amber-500" />
                            <span>Primary</span>
                          </span>
                        ) : (
                          <button
                            onClick={() => handleInitiateSetDefault(curr)}
                            disabled={actionLoadingId === curr.id}
                            className="text-xs font-semibold text-zinc-700 hover:text-black border border-[#E8E8E6] bg-white rounded-[6px] px-2.5 py-1 transition-all shadow-3xs cursor-pointer"
                          >
                            {t('currencies.setDefault')}
                          </button>
                        )}
                      </td>

                      <td className="px-6 py-4 text-center">
                        <button
                          onClick={() => handleToggleActive(curr)}
                          disabled={actionLoadingId === curr.id || curr.is_default}
                          className={`text-[10px] font-black uppercase px-2 py-0.5 rounded-[4px] tracking-wide inline-flex items-center gap-1 border transition-all cursor-pointer ${
                            curr.is_active 
                              ? 'bg-[#E8FDE8] border-[#4AE54A]/25 text-emerald-800' 
                              : 'bg-zinc-100 border-zinc-200 text-zinc-650 hover:text-black'
                          } ${curr.is_default ? 'opacity-70 cursor-not-allowed' : ''}`}
                        >
                          {curr.is_active && <span className="h-1.5 w-1.5 bg-[#4AE54A] rounded-full animate-pulse" />}
                          {!curr.is_active && <span className="h-1.5 w-1.5 bg-zinc-400 rounded-full" />}
                          <span>{curr.is_active ? t('currencies.active') : t('currencies.disabled')}</span>
                        </button>
                      </td>

                      <td className="px-6 py-4 text-right">
                        {!curr.is_default && (
                          <button
                            onClick={() => {
                              setCurrencyToDelete(curr);
                              setIsDeleteModalOpen(true);
                            }}
                            className="p-1.5 text-zinc-400 hover:text-red-650 hover:bg-red-50 rounded-[4px] transition-colors cursor-pointer inline-flex items-center justify-center"
                            title="Delete Currency"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>

      {/* 1. ADD CURRENCY MODAL */}
      {isAddModalOpen && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 select-none animate-overlay-fade">
          <div className="fixed inset-0" onClick={() => setIsAddModalOpen(false)} />
          <div className="bg-white border border-[#E8E8E6] rounded-xl max-w-md w-full p-6 shadow-xl relative z-50 animate-modal-box text-left">
            {/* Header Section (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6">
              <div className="flex items-center gap-2.5">
                <div className="h-7 w-7 rounded bg-[#FAFAFA] border border-[#E8E8E6] p-1 flex items-center justify-center shrink-0">
                  <Coins className="h-4 w-4 text-[#6B6B6B]" />
                </div>
                <div>
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">{t('currencies.addCurrencyModalTitle')}</h3>
                </div>
              </div>
              <button 
                type="button"
                onClick={() => setIsAddModalOpen(false)}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none shrink-0"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            <form onSubmit={handleCreateCurrency} className="space-y-4 pt-4">
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1.5">
                  <label className="text-[10px] text-zinc-500 font-semibold uppercase tracking-wider block">
                    {t('currencies.code')}
                  </label>
                  <input
                    type="text"
                    maxLength={3}
                    value={newCode}
                    onChange={(e) => setNewCode(e.target.value.toUpperCase())}
                    placeholder="e.g. EUR"
                    className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs font-mono font-bold focus:outline-none focus:border-black uppercase transition-colors"
                    required
                  />
                </div>

                <div className="space-y-1.5">
                  <label className="text-[10px] text-zinc-500 font-semibold uppercase tracking-wider block">
                    {t('currencies.symbol')}
                  </label>
                  <input
                    type="text"
                    maxLength={5}
                    value={newSymbol}
                    onChange={(e) => setNewSymbol(e.target.value)}
                    placeholder="e.g. €"
                    className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs font-mono font-bold focus:outline-none focus:border-black transition-colors"
                    required
                  />
                </div>
              </div>

              <div className="space-y-1.5">
                <label className="text-[10px] text-zinc-500 font-semibold uppercase tracking-wider block">
                  {t('currencies.name')}
                </label>
                <input
                  type="text"
                  value={newName}
                  onChange={(e) => setNewName(e.target.value)}
                  placeholder="e.g. Euro"
                  className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs font-semibold focus:outline-none focus:border-black transition-colors"
                  required
                />
              </div>

              <div className="pt-2 border-t border-zinc-100 space-y-2">
                <label className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={newIsActive}
                    onChange={(e) => setNewIsActive(e.target.checked)}
                    className="accent-black h-4 w-4"
                  />
                  <span className="text-xs font-semibold text-zinc-700">Enable for subscription billing</span>
                </label>

                <label className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={newIsDefault}
                    onChange={(e) => {
                      setNewIsDefault(e.target.checked);
                      if (e.target.checked) setNewIsActive(true);
                    }}
                    className="accent-black h-4 w-4"
                  />
                  <span className="text-xs font-semibold text-zinc-700">{t('currencies.makeDefault')}</span>
                </label>
              </div>

              {/* Footer Buttons */}
              <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none">
                <button
                  type="button"
                  onClick={() => setIsAddModalOpen(false)}
                  className="h-9 px-4 border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black text-xs font-semibold rounded-[6px] transition-all cursor-pointer"
                >
                  {t('currencies.cancel')}
                </button>
                <button
                  type="submit"
                  disabled={addLoading}
                  className="h-9 px-4 bg-zinc-955 hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer flex items-center gap-1.5"
                >
                  {addLoading && <RefreshCw className="h-3.5 w-3.5 animate-spin text-white" />}
                  <span>{t('currencies.registerCurrency')}</span>
                </button>
              </div>
            </form>
          </div>
        </div>,
        document.body
      )}

      {/* 2. DELETE CONFIRMATION MODAL */}
      {isDeleteModalOpen && currencyToDelete && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 select-none animate-overlay-fade">
          <div className="fixed inset-0" onClick={() => setIsDeleteModalOpen(false)} />
          <div className="bg-white border border-[#E8E8E6] rounded-xl max-w-md w-full p-6 shadow-xl relative z-50 animate-modal-box text-left">
            {/* Header Section (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6">
              <div className="flex items-center gap-2.5">
                <div className="h-7 w-7 rounded bg-red-50 border border-red-100 p-1 flex items-center justify-center shrink-0">
                  <Trash2 className="h-4 w-4 text-red-650" />
                </div>
                <div>
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">{t('currencies.deleteConfirmTitle')}</h3>
                </div>
              </div>
              <button 
                type="button"
                onClick={() => setIsDeleteModalOpen(false)}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none shrink-0"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            <div className="space-y-4 pt-4">
              <p className="text-xs text-zinc-500 font-semibold leading-relaxed">
                {t('currencies.deleteConfirmText', { code: `${currencyToDelete.name} (${currencyToDelete.code})` })}
              </p>
            </div>

            <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none">
              <button
                type="button"
                onClick={() => setIsDeleteModalOpen(false)}
                className="h-9 px-4 border border-[#E8E8E6] bg-white text-zinc-650 hover:text-black text-xs font-semibold rounded-[6px] transition-all cursor-pointer shadow-3xs"
              >
                {t('currencies.cancel')}
              </button>
              <button
                type="button"
                onClick={handleDeleteCurrency}
                disabled={deleteLoading}
                className="h-9 px-4 bg-red-650 hover:bg-red-700 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer flex items-center gap-1.5"
              >
                {deleteLoading && <RefreshCw className="h-3.5 w-3.5 animate-spin text-white" />}
                <span>{t('currencies.confirmDelete')}</span>
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* 3. SWITCH PRIMARY CURRENCY & PLANS PRICING MODAL */}
      {isSwitchPrimaryModalOpen && targetPrimaryCurrency && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 select-none animate-overlay-fade">
          <div className="fixed inset-0" onClick={() => !switchLoading && setIsSwitchPrimaryModalOpen(false)} />
          <div className="bg-white border border-[#E8E8E6] rounded-xl max-w-lg w-full p-6 shadow-xl relative z-50 animate-modal-box text-left">
            {/* Header Section (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6">
              <div className="flex items-center gap-2.5">
                <div className="h-7 w-7 rounded bg-[#FAFAFA] border border-[#E8E8E6] p-1 flex items-center justify-center shrink-0">
                  <Star className="h-4 w-4 text-amber-500 fill-amber-500" />
                </div>
                <div>
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">
                    {t('currencies.switchPrimaryModalTitle', { code: targetPrimaryCurrency.code })}
                  </h3>
                </div>
              </div>
              <button 
                type="button"
                onClick={() => setIsSwitchPrimaryModalOpen(false)}
                disabled={switchLoading}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none shrink-0"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            <form onSubmit={handleConfirmSwitchPrimary} className="space-y-4 pt-4">
              <p className="text-xs text-zinc-500 font-semibold leading-relaxed">
                {t('currencies.switchPrimaryModalDesc', { 
                  name: targetPrimaryCurrency.name, 
                  symbol: targetPrimaryCurrency.symbol 
                })}
              </p>

              {/* Direct Plan Inputs List */}
              <div className="space-y-4 max-h-[50vh] overflow-y-auto pr-1">
                {fetchingPlans ? (
                  <div className="p-8 text-center">
                    <SimpleLoader />
                  </div>
                ) : switchPlans.length === 0 ? (
                  <p className="text-xs text-zinc-400 font-semibold text-center py-4">No subscription plans found.</p>
                ) : (
                  switchPlans.map((plan, idx) => (
                    <div key={plan.id} className="space-y-1.5 pt-3 first:pt-0 border-t border-zinc-100 first:border-0">
                      <div className="flex items-center justify-between">
                        <label className="text-xs font-bold text-zinc-900">{plan.name}</label>
                        {plan.description && (
                          <span className="text-[10px] text-zinc-400 font-medium truncate max-w-[200px]">{plan.description}</span>
                        )}
                      </div>

                      <div className="grid grid-cols-2 gap-3">
                        {/* Monthly Rate Input */}
                        <div className="space-y-1">
                          <span className="text-[9px] font-bold uppercase tracking-wider text-zinc-400 block">
                            {t('currencies.switchPrimaryMonthly', { symbol: targetPrimaryCurrency.symbol })}
                          </span>
                          <div className="relative flex items-center">
                            <span className="absolute left-3 text-zinc-400 font-bold font-mono text-xs pointer-events-none">
                              {targetPrimaryCurrency.symbol}
                            </span>
                            <input
                              type="number"
                              step="0.01"
                              min="0"
                              value={plan.monthly_amount}
                              onChange={(e) => {
                                const val = e.target.value;
                                setSwitchPlans((prev) => {
                                  const copy = [...prev];
                                  copy[idx].monthly_amount = val;
                                  return copy;
                                });
                              }}
                              placeholder="0.00"
                              className="w-full h-9 pl-7 pr-16 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs font-mono font-semibold text-zinc-900 focus:outline-none focus:border-black transition-colors text-right"
                              required
                            />
                            <span className="absolute right-3 text-[10px] font-bold text-zinc-400 pointer-events-none">/ month</span>
                          </div>
                        </div>

                        {/* Annual Rate Input */}
                        <div className="space-y-1">
                          <span className="text-[9px] font-bold uppercase tracking-wider text-zinc-400 block">
                            {t('currencies.switchPrimaryAnnual', { symbol: targetPrimaryCurrency.symbol })}
                          </span>
                          <div className="relative flex items-center">
                            <span className="absolute left-3 text-zinc-400 font-bold font-mono text-xs pointer-events-none">
                              {targetPrimaryCurrency.symbol}
                            </span>
                            <input
                              type="number"
                              step="0.01"
                              min="0"
                              value={plan.yearly_amount}
                              onChange={(e) => {
                                const val = e.target.value;
                                setSwitchPlans((prev) => {
                                  const copy = [...prev];
                                  copy[idx].yearly_amount = val;
                                  return copy;
                                });
                              }}
                              placeholder="0.00"
                              className="w-full h-9 pl-7 pr-16 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs font-mono font-semibold text-zinc-900 focus:outline-none focus:border-black transition-colors text-right"
                              required
                            />
                            <span className="absolute right-3 text-[10px] font-bold text-zinc-400 pointer-events-none">/ year</span>
                          </div>
                        </div>
                      </div>
                    </div>
                  ))
                )}
              </div>

              {/* Footer Buttons */}
              <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none">
                <button
                  type="button"
                  onClick={() => setIsSwitchPrimaryModalOpen(false)}
                  disabled={switchLoading}
                  className="h-9 px-4 border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black text-xs font-semibold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                >
                  {t('currencies.cancel')}
                </button>
                <button
                  type="submit"
                  disabled={switchLoading || fetchingPlans}
                  className="h-9 px-4 bg-zinc-955 hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-3xs transition-all cursor-pointer flex items-center gap-1.5"
                >
                  {switchLoading && <RefreshCw className="h-3.5 w-3.5 animate-spin text-white" />}
                  <span>{switchLoading ? t('currencies.updatingPrimary') : t('currencies.confirmSwitchPrimary')}</span>
                </button>
              </div>
            </form>
          </div>
        </div>,
        document.body
      )}

    </div>
  );
}
