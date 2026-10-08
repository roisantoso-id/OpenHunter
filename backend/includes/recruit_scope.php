<?php
/**
 * 招聘数据范围：只看自己名下的招聘专员（recruitScopeOwner 返回自己 id 时）能看到哪些候选人。
 * = 归属是自己的 + 通过自己的职位投递链接投进来的（2026-09-26「链接主人也能看到」）。
 * 只管「能不能看到」；归属本身不变（先到先得），绩效 / 工作台 / 保温提醒仍按归属人算。
 * 链接投递的简历 origin='apply'、source_user_id=链接主人（includes/recruit_apply.php），所以按简历反查即可。
 */
function recruitVisibleSql(int $scope, string $alias = 'c'): string {
    return "($alias.owner_user_id=$scope OR EXISTS (SELECT 1 FROM recruit_resumes rv_ WHERE rv_.candidate_id=$alias.id AND rv_.origin='apply' AND rv_.source_user_id=$scope))";
}
