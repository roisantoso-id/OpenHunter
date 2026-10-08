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

### 状态

正在从原系统抽离，进度见 Pull Requests。首个可运行版本合入后，这里会补上安装步骤。

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

### Status

Extraction from the original system is in progress — see the open Pull Requests. Installation steps will be
added once the first runnable version lands.

### License

[GNU AGPL-3.0](LICENSE). You may use, modify and redistribute it freely; if you offer a modified version to
others as a network service, you must publish your modified source under AGPL-3.0 as well.
