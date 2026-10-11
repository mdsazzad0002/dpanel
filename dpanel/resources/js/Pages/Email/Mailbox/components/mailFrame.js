// HTML mail is shown in a sandboxed iframe (no scripts) so its own styles
// cannot leak into the panel. Shared by the reader and conversation cards.
export const mailDocument = (html) => (html
    ? `<!doctype html><html><head><meta charset="utf-8">`
        + `<meta http-equiv="Content-Security-Policy" content="script-src 'none'; object-src 'none'; frame-src 'none'; form-action 'none'">`
        + `<base target="_blank">`
        + `<style>html,body{margin:0;padding:0;background:#fff;color:#1f2937;}body{padding:20px 24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;word-wrap:break-word;overflow-wrap:break-word;}img{max-width:100%;height:auto;}table{max-width:100%;}pre{white-space:pre-wrap;}a{color:#2563eb;}</style>`
        + `</head><body>${html}</body></html>`
    : '');
