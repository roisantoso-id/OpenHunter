export default function access(initialState: { currentUser?: API.CurrentUser } | undefined) {
  const { currentUser } = initialState ?? {};
  const modules = currentUser?.modules || [];
  const isAdmin = currentUser?.role === 'admin';

  return {
    isAdmin,
    // recruit = view / follow up / match / create projects; recruit_admin = claim/transfer ownership, merge candidates, settings
    canAccessRecruit: isAdmin || modules.includes('recruit'),
    canManageRecruit: isAdmin || modules.includes('recruit_admin'),
    // recruit_all: see all candidates/resumes + the performance dashboard; others only see their own
    canViewAllRecruit: isAdmin || modules.includes('recruit_all') || modules.includes('recruit_admin'),
    // Company library: explicit viewer list only (backend recruitCompanyCanView); admin is NOT granted automatically
    canViewRecruitCompanies: modules.includes('recruit_company_view'),
    // Recruiting settings (mailboxes, AI config, permissions)
    canAccessSettings: isAdmin || modules.includes('recruit_admin'),
  };
}
