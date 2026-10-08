declare namespace API {
  interface CurrentUser {
    id: number;
    username: string;
    name: string;
    role: string;
    email?: string;
    lang?: string;
    modules?: string[]; // accessible module keys
    team_id?: number;
    team_name?: string;
    avatar?: string;
  }

  interface LoginParams {
    username: string;
    password: string;
  }

  interface LoginResult {
    success: boolean;
    token?: string;
    user?: CurrentUser;
    message?: string;
  }
}

declare module 'pdfjs-dist/build/pdf.mjs';
declare module 'html2pdf.js';
