# OpenHunter

**开源的 AI 猎头 / 招聘系统** · An open-source, AI-assisted recruiting & headhunting system

[中文](#中文) · [English](#english)

---

## 中文

OpenHunter 是一套面向猎头顾问和企业 HR 的招聘系统，后端 PHP、前端 React（UmiJS + Ant Design）。
它最初是一家企业服务公司内部 CRM 的招聘模块，在生产环境跑过真实业务后抽离出来开源。

### 功能

- **简历收件与解析**：页面上传、候选人投递链接、IMAP 邮箱自动同步，PDF / DOCX 自动解析成结构化档案
- **人才库与检索**：自然语言检索（AI 拆条件）+ 向量相似度，候选人去重合并、收藏、保温提醒
- **职位与匹配**：AI 拆解 JD 要求，候选人逐条打分并给出匹配理由
- **推荐报告**：一键生成推荐信 / 推荐报告，支持模板与多语言翻译
- **项目生命周期**：客户 → 职位 → 推荐 → 面试 → 入职，商务条款与合同文件
- **企业库与关系网络**：以公司为主体挖人，可视化候选人与企业的关系
- **提示词版本化**：提示词有版本号，失败与人工纠正自动沉淀为回归用例，新版本在用例上对比更好才启用
- **AI 用量与成本**：每次调用落台账，页面可见单次与累计费用
- **三语界面**：简体中文 / English / Bahasa Indonesia

### 技术栈

| 层 | 选型 |
|---|---|
| 后端 | PHP 8.1+，PDO（SQLite 或 MySQL 8） |
| 前端 | React 18、UmiJS Max 4、Ant Design 5 |
| AI | 任意 OpenAI 兼容的 Chat / Embedding 接口 |
| 存储 | 本地磁盘，或阿里云 OSS 兼容对象存储 |

### 快速开始

需要 PHP 8.1+（pdo_sqlite 或 pdo_mysql、curl、mbstring、zip、openssl）和 Node 18+。

```bash
# 1. 后端
cd backend
cp .env.example .env            # 填 OPENHUNTER_TOKEN_SECRET（≥32 字符）
php scripts/install.php --apply --admin=admin --password='至少8位' --name='Admin'
php scripts/set_setting.php ocr.openai.api_key sk-xxxx      # 任意 OpenAI 兼容接口（写入 default 租户）
php scripts/set_setting.php ocr.openai.endpoint https://api.openai.com/v1/chat/completions
php scripts/set_setting.php ocr.openai.model gpt-4.1-mini
php -S 127.0.0.1:8001 -t .      # 开发用；生产请用 nginx + php-fpm，入口 api/handler.php

# 2. 前端
cd ../frontend
npm install && npm run dev      # 开发时 /api 代理到 127.0.0.1:8001
npm run build                   # 产物在 frontend/dist，由 nginx 托管并把 /api 转给 php-fpm

# 3. 定时任务（解析、匹配、通知、邮箱同步一条串完；for_each_tenant.php 对每个租户各跑一遍）
*/5 * * * * php /path/to/backend/scripts/for_each_tenant.php cron_recruit_run.php
0 9 * * 1-5 php /path/to/backend/scripts/for_each_tenant.php cron_recruit_warm_remind.php

# 4. 开新租户（独立库、独立管理员）
php scripts/tenant.php create acme "Acme Recruiting" boss 'at-least-8-chars'
php scripts/tenant.php list            # 另有 disable / enable <租户>
OPENHUNTER_TENANT=acme php scripts/set_setting.php ocr.openai.api_key sk-xxxx   # 给指定租户配置（CLI 默认 default）
```

- 初次安装 `install.php` 建的是 `default` 租户；`--tenant=<标识>` 可指定别的名字。
- 候选人投递链接末尾带 `?t=<租户>`，页面里复制出来的链接已自动带上。

- 没配 AI key 时流水线会自己跳过，页面仍可手工使用。
- 邮箱同步、OSS 对象存储、联网补全企业信息（Tavily / Firecrawl）都是可选项，配置项见各脚本文件头注释。
- 测试：`for t in backend/tests/*_test.php; do php $t; done`（用独立的 SQLite 库，设好 `OPENHUNTER_DB_PATH` 和 `OPENHUNTER_TOKEN_SECRET`）。

### 与原系统的差异

- 原 CRM 的「客户 / 商机 / 收款」换成了独立的 `recruit_clients` 表（见 `backend/includes/host.php`，要接入自己的客户系统改这一个文件）。商机、回款相关字段保留为空值，不影响使用。
- **多租户**：一个部署可以服务多家公司，每个租户一个独立数据库（SQLite 是 `openhunter-<租户>.db`，MySQL 是 `<库名>_<租户>`），数据、账号、AI key、邮箱配置互相看不到。登录时填「组织标识」（只有一个租户时可省略），登录 token 绑定租户。
- 角色：`admin` 全权限；`recruiter`（只看自己名下）、`recruit_manager`（看全部 + 管理）。

### 许可证

[GNU AGPL-3.0](LICENSE)。你可以自由使用、修改和分发；如果你把修改后的版本作为网络服务提供给他人使用，需要同样以 AGPL-3.0 公开修改后的源代码。

---

## English

OpenHunter is a recruiting system for headhunters and in-house HR teams, with a PHP backend and a React
(UmiJS + Ant Design) frontend. It started as the recruiting module of a company's internal CRM, ran real
production workloads there, and has been extracted into this standalone open-source project.

### Features

- **Resume intake & parsing**: upload, candidate apply links, IMAP mailbox sync; PDF / DOCX parsed into structured profiles
- **Talent pool & search**: natural-language search (LLM-decomposed filters) plus vector similarity; dedupe/merge, favorites, warm-up reminders
- **Jobs & matching**: LLM breaks a JD into requirements and scores each candidate against them, with reasons
- **Recommendation reports**: generate candidate recommendation letters from templates, with translation
- **Project lifecycle**: client → job → recommendation → interview → placement, with commercial terms and contract files
- **Company library & relationship network**: source by target company, visualize candidate–company links
- **Versioned prompts**: failures and human corrections become regression cases; a new prompt version is only promoted if it beats the current one on them
- **AI usage & cost tracking**: every call is logged, with per-call and cumulative cost
- **Three UI languages**: Simplified Chinese, English, Bahasa Indonesia

### Stack

| Layer | Choice |
|---|---|
| Backend | PHP 8.1+, PDO (SQLite or MySQL 8) |
| Frontend | React 18, UmiJS Max 4, Ant Design 5 |
| AI | Any OpenAI-compatible chat / embedding endpoint |
| Storage | Local disk, or Aliyun-OSS-compatible object storage |

### Quick start

Requires PHP 8.1+ (pdo_sqlite or pdo_mysql, curl, mbstring, zip, openssl) and Node 18+.

```bash
# 1. Backend
cd backend
cp .env.example .env            # set OPENHUNTER_TOKEN_SECRET (>= 32 chars)
php scripts/install.php --apply --admin=admin --password='at-least-8-chars' --name='Admin'
php scripts/set_setting.php ocr.openai.api_key sk-xxxx      # any OpenAI-compatible endpoint (writes to the `default` tenant)
php scripts/set_setting.php ocr.openai.endpoint https://api.openai.com/v1/chat/completions
php scripts/set_setting.php ocr.openai.model gpt-4.1-mini
php -S 127.0.0.1:8001 -t .      # dev only; use nginx + php-fpm in production (entry: api/handler.php)

# 2. Frontend
cd ../frontend
npm install && npm run dev      # /api is proxied to 127.0.0.1:8001
npm run build                   # output in frontend/dist, served by nginx with /api -> php-fpm

# 3. Cron (parse, match, notify and mailbox sync in one pass; for_each_tenant.php runs it once per tenant)
*/5 * * * * php /path/to/backend/scripts/for_each_tenant.php cron_recruit_run.php
0 9 * * 1-5 php /path/to/backend/scripts/for_each_tenant.php cron_recruit_warm_remind.php

# 4. Add a tenant (own database, own admin)
php scripts/tenant.php create acme "Acme Recruiting" boss 'at-least-8-chars'
php scripts/tenant.php list            # also: disable / enable <tenant>
OPENHUNTER_TENANT=acme php scripts/set_setting.php ocr.openai.api_key sk-xxxx   # configure a specific tenant (CLI defaults to `default`)
```

- First-time `install.php` creates the `default` tenant; pass `--tenant=<id>` for another name.
- Candidate apply links end with `?t=<tenant>`; links copied from the UI already include it.

- Without an AI key the pipeline skips itself; the UI still works manually.
- IMAP sync, OSS storage and web enrichment of company data (Tavily / Firecrawl) are optional; see each script's header comment.
- Tests: `for t in backend/tests/*_test.php; do php $t; done` (use a throwaway SQLite file via `OPENHUNTER_DB_PATH`, and set `OPENHUNTER_TOKEN_SECRET`).

### Differences from the original system

- The CRM's customers / opportunities / payments are replaced by a standalone `recruit_clients` table (`backend/includes/host.php` — the one file to change if you integrate your own client system). Opportunity and payment columns are kept as empty values.
- **Multi-tenant**: one deployment can serve several companies. Each tenant has its own database (SQLite `openhunter-<tenant>.db`, MySQL `<dbname>_<tenant>`), so data, accounts, AI keys and mailbox settings are fully separate. Users enter an "Organization ID" at login (optional when there is only one tenant); the login token is bound to the tenant.
- Roles: `admin` has everything; `recruiter` (own candidates only), `recruit_manager` (all + admin).

### License

[GNU AGPL-3.0](LICENSE). You may use, modify and redistribute it freely; if you offer a modified version to
others as a network service, you must publish your modified source under AGPL-3.0 as well.
