/**
 * PDF 原件逐页画成白底图片（「预览要把阴影部分去掉」）。
 * 不用 iframe：浏览器自带的 PDF 查看器有深灰底色和工具栏，占地方又不好看。
 * 放大（「能放得很大看细节」）：− / + / 适合宽度 / 全屏，Ctrl/⌘ + 滚轮缩放；
 * 放大后按新尺寸重新渲染（不是把小图拉伸），字一直是清晰的。
 * pdfjs 懒加载，worker 走 CDN（与 components/ItkValidator/extract.ts 同一做法）。
 */
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Spin, Button, Space, Tooltip } from 'antd';
import { ZoomInOutlined, ZoomOutOutlined, ColumnWidthOutlined, FullscreenOutlined, FullscreenExitOutlined } from '@ant-design/icons';
import { useIntl } from '@umijs/max';

let pdfjsPromise: Promise<any> | null = null;
const loadPdfjs = () => {
  if (!pdfjsPromise) {
    pdfjsPromise = (async () => {
      // @ts-ignore
      const pdfjs: any = await import('pdfjs-dist/build/pdf.mjs');
      pdfjs.GlobalWorkerOptions.workerSrc = `https://unpkg.com/pdfjs-dist@${pdfjs.version || '4.8.69'}/build/pdf.worker.min.mjs`;
      return pdfjs;
    })();
  }
  return pdfjsPromise;
};

const MAX_PAGES = 10;
const ZOOMS = [0.5, 0.75, 1, 1.25, 1.5, 2, 2.5, 3];

/** onWiden：外层（简历详情抽屉）提供时，「适合宽度」同时把抽屉加到最宽（「点这个把宽度放得更大」） */
const PdfPages: React.FC<{ blob: Blob; onError?: () => void; onWiden?: () => void }> = ({ blob, onError, onWiden }) => {
  const intl = useIntl();
  const t = (id: string) => intl.formatMessage({ id });
  const viewport = useRef<HTMLDivElement>(null);
  const box = useRef<HTMLDivElement>(null);
  const docRef = useRef<any>(null);
  const [loading, setLoading] = useState(true);
  const [zoom, setZoom] = useState(1);            // 1 = 适合宽度
  const [full, setFull] = useState(false);
  const [vpw, setVpw] = useState(0);              // 预览区宽度：抽屉拖宽/加宽后跟着重新适配

  // 加载文档（换文件才重新加载）
  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      try {
        const pdfjs = await loadPdfjs();
        const doc = await pdfjs.getDocument({ data: await blob.arrayBuffer() }).promise;
        if (!cancelled) docRef.current = doc;
      } catch {
        if (!cancelled) onError?.();
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [blob, onError]);

  // 按当前缩放 / 全屏 重新画
  const render = useCallback(async (signal: { cancelled: boolean }) => {
    const doc = docRef.current;
    const el = box.current;
    const vp = viewport.current;
    if (!doc || !el || !vp) return;
    const base = Math.max(300, vp.clientWidth - 24);
    const width = base * zoom;
    const ratio = window.devicePixelRatio || 1;
    const frag = document.createDocumentFragment();
    for (let i = 1; i <= Math.min(doc.numPages, MAX_PAGES); i++) {
      const page = await doc.getPage(i);
      if (signal.cancelled) return;
      const v1 = page.getViewport({ scale: 1 });
      const view = page.getViewport({ scale: (width / v1.width) * ratio });
      const canvas = document.createElement('canvas');
      canvas.width = view.width;
      canvas.height = view.height;
      canvas.style.width = `${width}px`;
      canvas.style.display = 'block';
      canvas.style.margin = '0 auto 10px';
      canvas.style.boxShadow = '0 1px 4px rgba(0,0,0,.12)';
      await page.render({ canvasContext: canvas.getContext('2d'), viewport: view }).promise;
      if (signal.cancelled) return;
      frag.appendChild(canvas);
    }
    el.innerHTML = '';
    el.appendChild(frag);
  }, [zoom]);

  useEffect(() => {
    if (loading) return undefined;
    const signal = { cancelled: false };
    const h = setTimeout(() => render(signal), 120);   // 连点缩放 / 拖宽过程中只画最后一次
    return () => { signal.cancelled = true; clearTimeout(h); };
  }, [loading, render, full, vpw]);

  // 预览区宽度变化（拖抽屉边缘、点加宽）→ 重新按新宽度画
  useEffect(() => {
    const el = viewport.current;
    if (!el || typeof ResizeObserver === 'undefined') return undefined;
    const ro = new ResizeObserver((es) => { const w = Math.round(es[0].contentRect.width); setVpw((p) => (Math.abs(p - w) > 8 ? w : p)); });
    ro.observe(el);
    return () => ro.disconnect();
  }, [full]);

  const step = (dir: 1 | -1) => setZoom((z) => {
    const i = ZOOMS.findIndex((x) => x >= z - 0.001);
    return ZOOMS[Math.max(0, Math.min(ZOOMS.length - 1, (i < 0 ? ZOOMS.length - 1 : i) + dir))];
  });
  /* Ctrl/⌘ + 滚轮缩放。⛔ 必须原生监听且 passive:false：React 的 onWheel 是 passive，
     preventDefault 拦不住浏览器自己的整页缩放 */
  useEffect(() => {
    const el = viewport.current;
    if (!el) return undefined;
    const h = (e: WheelEvent) => {
      if (!(e.ctrlKey || e.metaKey)) return;
      e.preventDefault();
      step(e.deltaY < 0 ? 1 : -1);
    };
    el.addEventListener('wheel', h, { passive: false });
    return () => el.removeEventListener('wheel', h);
  }, [full]);
  useEffect(() => {
    if (!full) return undefined;
    const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') setFull(false); };
    window.addEventListener('keydown', esc);
    return () => window.removeEventListener('keydown', esc);
  }, [full]);

  const toolbar = (
    <div style={{ display: 'flex', justifyContent: 'center', padding: '6px 0', borderBottom: '1px solid #f0f0f0', background: '#fff', position: 'sticky', top: 0, zIndex: 2 }}>
      <Space size={4}>
        <Tooltip title={t('pages.recruit.pdf.zoomOut')}><Button size="small" icon={<ZoomOutOutlined />} disabled={zoom <= ZOOMS[0]} onClick={() => step(-1)} /></Tooltip>
        <span style={{ minWidth: 48, textAlign: 'center', fontSize: 12, color: '#595959' }}>{Math.round(zoom * 100)}%</span>
        <Tooltip title={t('pages.recruit.pdf.zoomIn')}><Button size="small" icon={<ZoomInOutlined />} disabled={zoom >= ZOOMS[ZOOMS.length - 1]} onClick={() => step(1)} /></Tooltip>
        <Tooltip title={t(onWiden ? 'pages.recruit.pdf.fitWide' : 'pages.recruit.pdf.fit')}>
          <Button size="small" icon={<ColumnWidthOutlined />} onClick={() => { setZoom(1); onWiden?.(); }} />
        </Tooltip>
        <Tooltip title={t(full ? 'pages.recruit.pdf.exitFull' : 'pages.recruit.pdf.full')}>
          <Button size="small" icon={full ? <FullscreenExitOutlined /> : <FullscreenOutlined />} onClick={() => setFull(!full)} />
        </Tooltip>
      </Space>
    </div>
  );

  const body = (
    <div ref={viewport} style={{ flex: 1, overflow: 'auto', background: '#f5f5f5', padding: 12 }}>
      {loading && <Spin style={{ display: 'block', margin: '80px auto' }} />}
      <div ref={box} />
    </div>
  );

  /* 全屏：盖住整个窗口（Esc 退出），比抽屉左半边大得多。
     ⛔ 必须 portal 到 body：抽屉面板有 transform，里面的 position:fixed 只会盖住抽屉本身 */
  const frame = { display: 'flex', flexDirection: 'column' as const, background: '#fff' };
  return full
    ? createPortal(<div style={{ ...frame, position: 'fixed', inset: 0, zIndex: 2000 }}>{toolbar}{body}</div>, document.body)
    : <div style={{ ...frame, height: '100%' }}>{toolbar}{body}</div>;
};

export default PdfPages;
