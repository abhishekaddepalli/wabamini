'use client';

import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { 
  UserPlus, 
  Trash2, 
  Shield, 
  RefreshCw, 
  X,
  Users
} from 'lucide-react';
import SimpleLoader from '@/components/ui/SimpleLoader';
import { fetchWithCsrf } from '@/lib/api';
import { toast } from 'sonner';
import { useTranslations } from 'next-intl';

interface CustomSelectProps {
  options: { value: string; label: string }[];
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
}

function CustomSelect({ options, value, onChange, placeholder = 'Select...' }: CustomSelectProps) {
  const [open, setOpen] = useState(false);
  const selectedOption = options.find(opt => opt.value.toString() === value.toString());

  return (
    <div className="relative inline-block w-full text-left font-sans text-xs">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        className="w-full flex items-center justify-between text-xs h-9 px-3 bg-[#FAFAFA]/50 border border-[#E8E8E6] rounded-[6px] text-zinc-900 focus:outline-none focus:ring-1 focus:ring-black cursor-pointer"
      >
        <span className="truncate">{selectedOption ? selectedOption.label : placeholder}</span>
        <svg className="h-3.5 w-3.5 text-zinc-400 shrink-0 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-30" onClick={() => setOpen(false)} />
          <div className="absolute left-0 mt-1 w-full rounded-md bg-white border border-[#E8E8E6] shadow-lg z-40 max-h-60 overflow-y-auto">
            <div className="p-1 space-y-0.5">
              {options.map((opt) => (
                <button
                  key={opt.value}
                  type="button"
                  onClick={() => {
                    onChange(opt.value);
                    setOpen(false);
                  }}
                  className={`w-full text-left px-2.5 py-1.5 rounded-[4px] text-xs font-medium ${
                    opt.value.toString() === value.toString()
                      ? 'bg-zinc-950 text-white' 
                      : 'text-zinc-700 hover:bg-zinc-50'
                  }`}
                >
                  {opt.label}
                </button>
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  );
}

interface UserPermission {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  role: {
    id: number;
    name: string;
    display_name: string;
    permissions: Record<string, string[]>;
  };
}

interface Invitation {
  id: number;
  email: string;
  role: {
    display_name: string;
  };
  token: string;
}

interface CustomRole {
  id: number;
  name: string;
  display_name: string;
  permissions: Record<string, string[]>;
  tenant_id?: number | null;
}

export default function TeamSettingsPage() {
  const t = useTranslations('Settings');
  const tCommon = useTranslations('Common');

  const [members, setMembers] = useState<UserPermission[]>([]);
  const [invitations, setInvitations] = useState<Invitation[]>([]);
  const [roles, setRoles] = useState<CustomRole[]>([]);
  const [loading, setLoading] = useState(true);
  const [planName, setPlanName] = useState('Trial');
  const [seatLimit, setSeatLimit] = useState(3);
  const [activeTab, setActiveTab] = useState<'members' | 'roles'>('members');

  // Invite Form State
  const [inviteModalOpen, setInviteModalOpen] = useState(false);
  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteRoleId, setInviteRoleId] = useState('');
  const [submittingInvite, setSubmittingInvite] = useState(false);

  // Custom Role Form State
  const [roleModalOpen, setRoleModalOpen] = useState(false);
  const [roleDisplayName, setRoleDisplayName] = useState('');
  const [rolePerms, setRolePerms] = useState<Record<string, string[]>>({
    inbox: [],
    contacts: [],
    campaigns: [],
    flows: [],
    settings: [],
  });
  const [submittingRole, setSubmittingRole] = useState(false);
  const [mounted, setMounted] = useState(false);

  // Member editing dropdown states
  const [activeEditingMemberId, setActiveEditingMemberId] = useState<number | null>(null);

  const loadAllData = async () => {
    try {
      const [membersRes, invitesRes, rolesRes, meRes] = await Promise.all([
        fetchWithCsrf('/members'),
        fetchWithCsrf('/invitations'),
        fetchWithCsrf('/roles'),
        fetchWithCsrf('/auth/me'),
      ]);

      if (membersRes.ok) {
        const d = await membersRes.json();
        setMembers(d.members || []);
      }
      if (invitesRes.ok) {
        const d = await invitesRes.json();
        setInvitations(d.invitations || []);
      }
      if (rolesRes.ok) {
        const d = await rolesRes.json();
        setRoles(d.roles || []);
      }
      if (meRes.ok) {
        const d = await meRes.json();
        const tenant = d.user?.tenant;
        if (tenant) {
          setPlanName(tenant.plan?.name || 'Trial');
          // Match trial or plan limit seats
          setSeatLimit(tenant.plan?.max_team_members || 3);
        }
      }
    } catch {
      toast.error(t('toasts.reloadContextFailed'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    setMounted(true);
    loadAllData();

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setInviteModalOpen(false);
        setRoleModalOpen(false);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, []);

  const handleBackdropClick = (e: React.MouseEvent<HTMLDivElement>) => {
    if (e.target === e.currentTarget) {
      setInviteModalOpen(false);
      setRoleModalOpen(false);
    }
  };

  const totalSeatsUsed = members.length + invitations.length;

  const handleSendInvite = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!inviteEmail || !inviteRoleId) {
      toast.error(t('toasts.completeRequiredFields'));
      return;
    }

    setSubmittingInvite(true);
    try {
      const res = await fetchWithCsrf('/invitations', {
        method: 'POST',
        body: JSON.stringify({
          email: inviteEmail,
          role_id: parseInt(inviteRoleId, 10),
        }),
      });

      const data = await res.json();
      if (res.ok) {
        toast.success(t('toasts.inviteSent'), {
          description: `An invite link was dispatched to ${inviteEmail}.`,
        });
        setInviteModalOpen(false);
        setInviteEmail('');
        setInviteRoleId('');
        loadAllData();
      } else {
        throw new Error(data.message || 'Invitation failed.');
      }
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Failed to send invite.';
      toast.error(msg);
    } finally {
      setSubmittingInvite(false);
    }
  };

  const handleCancelInvite = async (id: number) => {
    try {
      const res = await fetchWithCsrf(`/invitations/${id}`, { method: 'DELETE' });
      if (res.ok) {
        toast.success(t('toasts.inviteCancelled'));
        loadAllData();
      } else {
        toast.error(t('toasts.cancelInviteFailed'));
      }
    } catch {
      toast.error(t('toasts.cancelInviteReqFailed'));
    }
  };

  const handleRemoveMember = async (id: number) => {
    if (!confirm('Are you sure you want to remove this member? they will lose access instantly.')) return;
    try {
      const res = await fetchWithCsrf(`/members/${id}`, { method: 'DELETE' });
      const data = await res.json();
      if (res.ok) {
        toast.success(t('toasts.memberRemoved'));
        loadAllData();
      } else {
        toast.error(data.message || 'Failed to remove member.');
      }
    } catch {
      toast.error(t('toasts.requestFailed'));
    }
  };

  const handleUpdateMemberRole = async (memberId: number, roleId: number) => {
    try {
      const res = await fetchWithCsrf(`/members/${memberId}/role`, {
        method: 'POST',
        body: JSON.stringify({ role_id: roleId }),
      });
      const data = await res.json();
      if (res.ok) {
        toast.success(t('toasts.memberRoleUpdated'));
        setActiveEditingMemberId(null);
        loadAllData();
      } else {
        toast.error(data.message || t('toasts.changeRoleFailed'));
      }
    } catch {
      toast.error(t('toasts.changeRoleFailed'));
    }
  };


  const handleCreateRole = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!roleDisplayName) {
      toast.error(t('toasts.roleNameRequired'));
      return;
    }

    setSubmittingRole(true);
    try {
      const res = await fetchWithCsrf('/roles', {
        method: 'POST',
        body: JSON.stringify({
          display_name: roleDisplayName,
          permissions: rolePerms,
        }),
      });

      const data = await res.json();
      if (res.ok) {
        toast.success(t('toasts.roleCreated'));
        setRoleModalOpen(false);
        setRoleDisplayName('');
        setRolePerms({
          dashboard: ['view'],
          inbox: [],
          contacts: [],
          campaigns: [],
          flows: [],
          settings: [],
        });
        loadAllData();
      } else {
        throw new Error(data.message || 'Failed to create role.');
      }
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Create role failed.';
      toast.error(msg);
    } finally {
      setSubmittingRole(false);
    }
  };

  const handleDeleteRole = async (id: number) => {
    if (!confirm('Are you sure you want to delete this custom role? Associated members will be downgraded to Agents.')) return;
    try {
      const res = await fetchWithCsrf(`/roles/${id}`, { method: 'DELETE' });
      if (res.ok) {
        toast.success(t('toasts.roleDeleted'));
        loadAllData();
      } else {
        toast.error(t('toasts.deleteRoleFailed'));
      }
    } catch {
      toast.error(t('toasts.deleteRoleReqFailed'));
    }
  };


  const togglePermission = (module: string, action: string) => {
    setRolePerms(prev => {
      const current = prev[module] || [];
      const updated = current.includes(action) 
        ? current.filter(a => a !== action) 
        : [...current, action];
      return { ...prev, [module]: updated };
    });
  };

  if (loading) {
    return <SimpleLoader message={t('syncingTeam')} />;
  }

  return (
    <div className="w-full p-8 space-y-8 select-none font-sans text-black flex-1 overflow-y-auto">
      
      {/* Upper Statistics Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 items-stretch animate-fade-in">
        
        {/* Workspace Seat Allocation Card */}
        <div className="bg-white border border-[#E8E8E6] rounded-[10px] p-6 shadow-[var(--shadow-card)] flex flex-col justify-between transition-all hover:shadow-[var(--shadow-card-hover)] relative overflow-hidden group">
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2.5 text-xs font-semibold text-zinc-550 uppercase tracking-wider">
                <UserPlus className="h-4 w-4 text-zinc-400 group-hover:text-zinc-600 transition-colors" />
                <span>{t('teammateSeats')}</span>
              </div>
              <span className="text-[10px] font-bold text-zinc-400 bg-zinc-50 border border-zinc-150 px-2 py-0.5 rounded-md uppercase">
                {t('seatsPlanName', { plan: planName })}
              </span>
            </div>
            
            <div className="flex items-baseline gap-2">
              <span className="text-5xl font-black leading-none tracking-tighter text-zinc-955">
                {t('seatsUsedOfLimit', { used: totalSeatsUsed, limit: seatLimit })}
              </span>
              <span className="text-[10px] text-zinc-400 font-extrabold uppercase tracking-wider ml-1">{t('seatsFilled')}</span>
            </div>
            
            {/* Visual slots progress track */}
            <div className="space-y-1.5 pt-2">
              <div className="flex items-center justify-between text-[10px] text-zinc-400 font-bold uppercase tracking-wider">
                <span>{t('seatUtilization')}</span>
                <span>{t('seatUsedPercentage', { percentage: Math.round((totalSeatsUsed / seatLimit) * 100) })}</span>
              </div>
              <div className="flex gap-1.5">
                {Array.from({ length: seatLimit }).map((_, idx) => {
                  const isFilled = idx < totalSeatsUsed;
                  return (
                    <div
                      key={idx}
                      className={`h-2 flex-1 rounded-sm transition-all duration-350 ${
                        isFilled ? 'bg-[#4AE54A] shadow-[0_0_8px_rgba(74,229,74,0.6)]' : 'bg-zinc-100'
                      }`}
                    />
                  );
                })}
              </div>
            </div>
          </div>
          
          <div className="mt-5 border-t border-[#F0F0F0] pt-4 text-[10px] text-zinc-500 font-medium flex items-center gap-1.5">
            <span className="h-1.5 w-1.5 rounded-full bg-[#4AE54A] animate-pulse" />
            <span>{t('seatLimitNotice')}</span>
          </div>
        </div>

        {/* Access Security Configuration Card */}
        <div className="bg-white border border-[#E8E8E6] rounded-[10px] p-6 shadow-[var(--shadow-card)] flex flex-col justify-between transition-all hover:shadow-[var(--shadow-card-hover)] relative overflow-hidden group">
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2.5 text-xs font-semibold text-zinc-550 uppercase tracking-wider">
                <Shield className="h-4 w-4 text-zinc-400 group-hover:text-zinc-600 transition-colors" />
                <span>{t('accessSecurityMatrix')}</span>
              </div>
              <span className="text-[10px] font-bold text-emerald-605 bg-emerald-50 border border-emerald-150 px-2 py-0.5 rounded-md uppercase">
                {t('rbacEnabled')}
              </span>
            </div>
            
            <div className="flex items-baseline gap-2">
              <span className="text-5xl font-black leading-none tracking-tighter text-zinc-955">
                {roles.length}
              </span>
              <span className="text-[10px] text-zinc-400 font-extrabold uppercase tracking-wider ml-1">{t('definedClasses')}</span>
            </div>

            {/* Visual list of defined roles tags */}
            <div className="space-y-1.5 pt-2">
              <div className="text-[10px] text-zinc-400 font-bold uppercase tracking-wider">
                {t('activeSecurityGroups')}
              </div>
              <div className="flex flex-wrap gap-1.5">
                {roles.slice(0, 4).map(r => (
                  <span
                    key={r.id}
                    className="text-[9px] font-extrabold tracking-wide px-2.5 py-0.5 rounded-full bg-zinc-50 border border-zinc-200 text-zinc-600 uppercase"
                  >
                    {r.display_name}
                  </span>
                ))}
                {roles.length > 4 && (
                  <span className="text-[10px] font-bold text-zinc-400 self-center">
                    {t('moreRoles', { count: roles.length - 4 })}
                  </span>
                )}
              </div>
            </div>
          </div>

          <div className="mt-5 border-t border-[#F0F0F0] pt-4 text-[10px] text-zinc-500 font-medium flex items-center gap-1.5">
            <span className="h-1.5 w-1.5 rounded-full bg-zinc-300" />
            <span>{t('roleSecurityGroupsNotice')}</span>
          </div>
        </div>
      </div>

      {/* Main Tabs Navigation & Action Toolbar */}
      <div className="border border-[#E8E8E6] bg-white rounded-[10px] shadow-[var(--shadow-card)] overflow-hidden font-sans">
        
        {/* Navigation Tabs Header */}
        <div className="border-b border-[#E8E8E6] bg-[#FAFAFA] px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4 select-none">
          <div className="flex items-center gap-1 p-1 bg-zinc-100/90 rounded-lg border border-[#E8E8E6] select-none w-fit">
            <button
              type="button"
              onClick={() => setActiveTab('members')}
              className={`flex items-center gap-2 px-3.5 py-1.5 rounded-[6px] text-xs font-semibold transition-all cursor-pointer ${
                activeTab === 'members' 
                  ? 'bg-white text-zinc-950 shadow-3xs' 
                  : 'text-zinc-500 hover:text-zinc-800'
              }`}
            >
              <Users className="h-3.5 w-3.5 text-zinc-500" />
              <span>{t('membersDirectoryTitle', { count: members.length })}</span>
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('roles')}
              className={`flex items-center gap-2 px-3.5 py-1.5 rounded-[6px] text-xs font-semibold transition-all cursor-pointer ${
                activeTab === 'roles' 
                  ? 'bg-white text-zinc-950 shadow-3xs' 
                  : 'text-zinc-500 hover:text-zinc-800'
              }`}
            >
              <Shield className="h-3.5 w-3.5 text-zinc-500" />
              <span>{t('securityRolesTitle', { count: roles.length })}</span>
            </button>
          </div>

          <div>
            {activeTab === 'members' ? (
              <button
                disabled={totalSeatsUsed >= seatLimit}
                onClick={() => setInviteModalOpen(true)}
                className={`px-4 h-9 rounded-[6px] text-xs font-bold transition-all select-none shadow-sm cursor-pointer flex items-center gap-2 ${
                  totalSeatsUsed >= seatLimit
                    ? 'bg-zinc-100 text-zinc-400 cursor-not-allowed'
                    : 'bg-[#0A0A0A] hover:bg-zinc-900 text-white'
                }`}
              >
                <UserPlus className="h-4 w-4" />
                <span>{t('inviteMember')}</span>
              </button>
            ) : (
              <button
                onClick={() => setRoleModalOpen(true)}
                className="bg-[#0A0A0A] hover:bg-zinc-900 text-white px-4 h-9 rounded-[6px] text-xs font-bold transition-all select-none shadow-sm cursor-pointer flex items-center gap-2"
              >
                <Shield className="h-4 w-4" />
                <span>{t('addCustomRole')}</span>
              </button>
            )}
          </div>
        </div>

        {/* Tab Panel Body Content */}
        <div className="p-6">
          
          {/* TAB 1: MEMBERS DIRECTORY */}
          {activeTab === 'members' && (
            <div className="space-y-6">
              
              {/* Active Workspace Directory */}
              <div className="space-y-3">
                <div className="border border-[#E8E8E6] rounded-xl overflow-hidden bg-white">
                  <table className="w-full text-left border-collapse">
                    <thead>
                      <tr className="bg-neutral-50 border-b border-[#E8E8E6] text-[10px] font-bold text-zinc-500 uppercase tracking-wider select-none">
                        <th className="p-4 pl-6">{t('teammate')}</th>
                        <th className="p-4">{t('email')}</th>
                        <th className="p-4">{t('permissionRole')}</th>
                        <th className="p-4 pr-6 text-right">{tCommon('actions')}</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-[#F0F0F0] text-xs font-semibold text-zinc-700">
                      {members.map(member => (
                        <tr key={member.id} className="hover:bg-neutral-50/50">
                          {/* Teammate Identity */}
                          <td className="p-4 pl-6 flex items-center gap-3">
                            <div className="h-8 w-8 rounded-full bg-zinc-100 border border-zinc-200 flex items-center justify-center text-xs font-bold text-zinc-800 select-none shrink-0">
                              {((member.first_name?.[0] || '') + (member.last_name?.[0] || '')).toUpperCase() || 'M'}
                            </div>
                            <div>
                              <div className="text-xs font-extrabold text-zinc-950">{member.first_name} {member.last_name}</div>
                              <div className="text-[10px] text-zinc-400 font-medium">{t('activeSeat')}</div>
                            </div>
                          </td>
                          <td className="p-4 text-zinc-550 font-medium">{member.email}</td>
                          <td className="p-4">
                            {activeEditingMemberId === member.id ? (
                              <div className="absolute z-20 bg-white border border-[#E8E8E6] rounded-lg shadow-md p-1.5 flex items-center gap-1.5 w-48 mt-[-18px]">
                                <CustomSelect 
                                  options={roles.map(r => ({ value: r.id.toString(), label: r.display_name }))}
                                  value={member.role?.id?.toString() || ''}
                                  onChange={(val) => handleUpdateMemberRole(member.id, parseInt(val, 10))}
                                />
                                <button 
                                  onClick={() => setActiveEditingMemberId(null)}
                                  className="p-1.5 hover:bg-neutral-100 rounded text-zinc-400 cursor-pointer shrink-0 border border-transparent"
                                >
                                  <X className="h-3.5 w-3.5" />
                                </button>
                              </div>
                            ) : (
                              <button
                                disabled={member.role?.name === 'owner'}
                                onClick={() => setActiveEditingMemberId(member.id)}
                                className={`px-2.5 py-0.5 border rounded text-[10px] font-bold tracking-tight select-none transition-all flex items-center gap-1 ${
                                  member.role?.name === 'owner' 
                                    ? 'bg-emerald-50/50 border-emerald-250 text-emerald-700 font-extrabold' 
                                    : 'bg-zinc-50 border-zinc-200 text-zinc-700 hover:bg-zinc-100 hover:text-zinc-955 cursor-pointer'
                                }`}
                              >
                                <span>{member.role?.display_name || t('noRole')}</span>
                                {member.role?.name !== 'owner' && (
                                  <svg className="h-3 w-3 text-zinc-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                  </svg>
                                )}
                              </button>
                            )}
                          </td>
                          <td className="p-4 pr-6 text-right">
                            <button
                              disabled={member.role?.name === 'owner'}
                              onClick={() => handleRemoveMember(member.id)}
                              className={`p-2 rounded-lg transition-colors border border-transparent ${
                                member.role?.name === 'owner'
                                  ? 'text-zinc-200 cursor-not-allowed'
                                  : 'text-zinc-400 hover:text-red-650 hover:bg-red-50/60 cursor-pointer'
                              }`}
                            >
                              <Trash2 className="h-4 w-4" />
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>

              {/* Pending Invites List */}
              {invitations.length > 0 && (
                <div className="space-y-3 pt-6 border-t border-[#F0F0F0] font-sans">
                  <span className="text-[10px] font-black text-zinc-400 uppercase tracking-widest block pl-1">{t('pendingInvitations')}</span>
                  <div className="border border-[#E8E8E6] rounded-xl overflow-hidden bg-white">
                    <table className="w-full text-left border-collapse">
                      <thead>
                        <tr className="bg-neutral-50 border-b border-[#E8E8E6] text-[10px] font-bold text-zinc-500 uppercase tracking-wider select-none">
                          <th className="p-4 pl-6">{t('email')}</th>
                          <th className="p-4">{t('assignedRole')}</th>
                          <th className="p-4">{t('status')}</th>
                          <th className="p-4 pr-6 text-right">{tCommon('actions')}</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-[#F0F0F0] text-xs font-semibold text-zinc-700">
                        {invitations.map(invite => (
                          <tr key={invite.id} className="hover:bg-neutral-50/50">
                            <td className="p-4 pl-6 text-zinc-950 font-extrabold flex items-center gap-2">
                              <div className="h-8 w-8 rounded-full bg-zinc-50 border border-dashed border-zinc-300 flex items-center justify-center text-zinc-400 shrink-0">
                                @
                              </div>
                              <span>{invite.email}</span>
                            </td>
                            <td className="p-4">
                              <span className="bg-zinc-50 border border-zinc-200 text-zinc-700 px-2 py-0.5 rounded text-[10px] font-bold">
                                {invite.role.display_name}
                              </span>
                            </td>
                            <td className="p-4">
                              <span className="bg-amber-50 border border-amber-250 text-amber-700 px-2.5 py-0.5 rounded text-[10px] font-bold select-none">
                                {t('pendingVerification')}
                              </span>
                            </td>
                            <td className="p-4 pr-6 text-right">
                              <button
                                onClick={() => handleCancelInvite(invite.id)}
                                className="px-3 py-1 border border-red-100 hover:bg-red-50/50 text-red-650 hover:text-red-700 rounded-[6px] text-[11px] font-medium transition-all cursor-pointer"
                              >
                                {t('cancelInvitation')}
                              </button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}
            </div>
          )}
          
          {/* TAB 2: ROLES & PERMISSIONS */}
          {activeTab === 'roles' && (
            <div className="space-y-6">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {roles.map(role => {
                  const isSystemRole = !role.tenant_id;
                  return (
                    <div key={role.id} className="border border-[#E8E8E6] rounded-xl p-6 bg-white flex flex-col justify-between shadow-[var(--shadow-card)] space-y-5">
                      <div className="flex items-center justify-between">
                        <div className="space-y-1">
                          <h5 className="font-black text-sm text-zinc-950 uppercase tracking-wider">{role.display_name}</h5>
                           <span className={`text-[8px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full border ${
                            isSystemRole 
                              ? 'bg-zinc-50 border-zinc-200 text-zinc-500' 
                              : 'bg-emerald-50 border-emerald-200 text-emerald-700 font-extrabold'
                          }`}>
                            {isSystemRole ? t('systemBaseRole') : t('customWorkspaceRole')}
                          </span>
                        </div>
                        {!isSystemRole && (
                          <button 
                            onClick={() => handleDeleteRole(role.id)}
                            className="p-2 text-zinc-400 hover:text-red-650 hover:bg-red-50 rounded-lg transition-colors cursor-pointer border border-transparent"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        )}
                      </div>

                      <div className="border-t border-[#F0F0F0] pt-4 space-y-3 font-sans">
                        <span className="text-[10px] font-black text-zinc-400 uppercase tracking-widest block">{t('accessScopeControl')}</span>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-[11px] font-medium text-zinc-700">
                          {Object.entries(role.permissions || {}).map(([module, perms]) => {
                            const hasRead = perms.includes('read') || perms.includes('view');
                            const hasWrite = perms.includes('write');
                            
                            return (
                              <div key={module} className="bg-[#FAFAFA]/70 border border-[#E8E8E6] rounded-lg p-2.5 flex items-center justify-between">
                                <span className="font-bold text-zinc-900 capitalize">{module}</span>
                                <div className="flex items-center gap-1 font-bold">
                                  {perms.length === 0 ? (
                                    <span className="text-[9px] font-bold text-zinc-400 bg-zinc-50 border border-zinc-150 px-1.5 py-0.5 rounded">{t('noAccess')}</span>
                                  ) : (
                                    <>
                                      {hasRead && (
                                        <span className="text-[9px] font-bold text-zinc-700 bg-zinc-50 border border-zinc-200 px-1.5 py-0.5 rounded">{t('read')}</span>
                                      )}
                                      {hasWrite && (
                                        <span className="text-[9px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-150 px-1.5 py-0.5 rounded">{t('write')}</span>
                                      )}
                                    </>
                                  )}
                                </div>
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* 1. INVITE MEMBER MODAL */}
      {mounted && inviteModalOpen && typeof window !== 'undefined' && createPortal(
        <div 
          onClick={handleBackdropClick}
          className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 animate-overlay-fade select-none"
        >
          <div className="bg-white border border-[#E8E8E6] rounded-xl max-w-md w-full p-6 shadow-xl relative z-50 animate-modal-box text-left">
            {/* Header Section (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6">
              <div className="flex items-center gap-2.5">
                <div className="h-7 w-7 rounded bg-[#FAFAFA] border border-[#E8E8E6] p-1 flex items-center justify-center shrink-0">
                  <UserPlus className="h-4 w-4 text-zinc-700" />
                </div>
                <div>
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">{t('inviteWorkspaceTeammate')}</h3>
                </div>
              </div>
              <button 
                type="button"
                onClick={() => setInviteModalOpen(false)}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>
            
            <form onSubmit={handleSendInvite} className="space-y-4 pt-4">
              <div className="space-y-1.5">
                <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('teammateEmail')}</label>
                <input 
                  type="email"
                  required
                  value={inviteEmail}
                  onChange={(e) => setInviteEmail(e.target.value)}
                  placeholder="name@company.com"
                  className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs focus:outline-none focus:border-black font-medium transition-colors"
                />
              </div>

              <div className="space-y-1.5 font-semibold text-zinc-700 text-left">
                <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('assignedAccessRole')}</label>
                <CustomSelect 
                  options={roles.map(r => ({ value: r.id.toString(), label: r.display_name }))}
                  value={inviteRoleId}
                  onChange={(val) => setInviteRoleId(val)}
                  placeholder={t('selectRolePlaceholder')}
                />
              </div>

              {/* Action Buttons Footer Strip */}
              <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none font-bold">
                <button
                  type="button"
                  onClick={() => setInviteModalOpen(false)}
                  className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                >
                  {tCommon('cancel')}
                </button>
                <button
                  type="submit"
                  disabled={submittingInvite}
                  className="h-9 px-4 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-sm transition-all cursor-pointer disabled:opacity-50"
                >
                  {submittingInvite ? t('dispatching') : t('invite')}
                </button>
              </div>
            </form>
          </div>
        </div>,
        document.body
      )}

      {/* 2. CONFIGURE ROLE MODAL */}
      {mounted && roleModalOpen && typeof window !== 'undefined' && createPortal(
        <div 
          onClick={handleBackdropClick}
          className="fixed inset-0 bg-[#0A0A0A]/40 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 transition-all duration-300 animate-overlay-fade select-none"
        >
          <div className="bg-white border border-[#E8E8E6] rounded-xl max-w-md w-full p-6 shadow-xl relative z-50 animate-modal-box text-left">
            {/* Header Section (Connected End-to-End Divider) */}
            <div className="flex items-center justify-between border-b border-[#E8E8E6] p-5 -mx-6 -mt-6">
              <div className="flex items-center gap-2.5">
                <div className="h-7 w-7 rounded bg-[#FAFAFA] border border-[#E8E8E6] p-1 flex items-center justify-center shrink-0">
                  <Shield className="h-4 w-4 text-zinc-700" />
                </div>
                <div>
                  <h3 className="text-xs font-bold text-black uppercase tracking-wider">{t('createCustomRole')}</h3>
                </div>
              </div>
              <button 
                type="button"
                onClick={() => setRoleModalOpen(false)}
                className="h-7 w-7 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-900 hover:bg-zinc-100 active:bg-zinc-200 border border-transparent hover:border-zinc-200/80 transition-all cursor-pointer shadow-none hover:shadow-3xs focus:outline-none"
                aria-label="Close dialog"
              >
                <X className="h-4 w-4" />
              </button>
            </div>
            
            <form onSubmit={handleCreateRole} className="space-y-4 pt-4">
              <div className="space-y-1.5">
                <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('roleClassLabel')}</label>
                <input 
                  type="text"
                  required
                  value={roleDisplayName}
                  onChange={(e) => setRoleDisplayName(e.target.value)}
                  placeholder={t('roleClassPlaceholder')}
                  className="w-full h-9 px-3 bg-[#F5F5F5] border border-[#E8E8E6] rounded-md text-xs focus:outline-none focus:border-black font-medium transition-colors"
                />
              </div>

              <div className="space-y-1.5">
                <label className="text-[10px] text-[#6B6B6B] font-semibold uppercase tracking-wider block">{t('modulePermissionMatrix')}</label>
                <div className="border border-[#E8E8E6] rounded-md p-3 bg-[#FAFAFA]/30 space-y-2.5 max-h-56 overflow-y-auto font-semibold">
                  {Object.keys(rolePerms).map(module => (
                    <div key={module} className="flex items-center justify-between border-b last:border-0 pb-2 last:pb-0 border-[#E8E8E6]/60">
                      <span className="text-[10px] uppercase tracking-wider text-zinc-850 font-bold">{module}</span>
                      <div className="flex items-center gap-4">
                        <label className="flex items-center gap-1.5 text-[10px] text-zinc-500 cursor-pointer select-none font-semibold">
                          <input 
                            type="checkbox"
                            checked={rolePerms[module].includes('read')}
                            onChange={() => togglePermission(module, 'read')}
                            className="rounded border-[#E8E8E6] text-black focus:ring-black h-3.5 w-3.5 cursor-pointer accent-black"
                          />
                          <span>{t('read')}</span>
                        </label>
                        <label className="flex items-center gap-1.5 text-[10px] text-zinc-500 cursor-pointer select-none font-semibold">
                          <input 
                            type="checkbox"
                            checked={rolePerms[module].includes('write')}
                            onChange={() => togglePermission(module, 'write')}
                            className="rounded border-[#E8E8E6] text-black focus:ring-black h-3.5 w-3.5 cursor-pointer accent-black"
                          />
                          <span>{t('write')}</span>
                        </label>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              {/* Action Buttons Footer Strip */}
              <div className="bg-[#FAFAFA] border-t border-[#E8E8E6] p-5 -mx-6 -mb-6 mt-4 rounded-b-[12px] flex items-center justify-end gap-3 select-none font-bold">
                <button
                  type="button"
                  onClick={() => setRoleModalOpen(false)}
                  className="border border-[#E8E8E6] bg-white text-[#6B6B6B] hover:text-black h-9 px-4 text-xs font-bold rounded-[6px] transition-all cursor-pointer shadow-3xs"
                >
                  {tCommon('cancel')}
                </button>
                <button
                  type="submit"
                  disabled={submittingRole}
                  className="h-9 px-4 bg-[#0A0A0A] hover:bg-zinc-900 text-white font-bold text-xs rounded-[6px] shadow-sm transition-all cursor-pointer disabled:opacity-50"
                >
                  {submittingRole ? t('saving') : t('register')}
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
