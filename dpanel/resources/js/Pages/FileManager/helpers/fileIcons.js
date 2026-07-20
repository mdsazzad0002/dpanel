export function iconClassForItem(item) {
    if (item?.type === 'dir') return 'bi-folder-fill text-amber-500';

    const ext = String(item?.name || '').split('.').pop()?.toLowerCase() || '';
    const map = {
        php: 'bi-filetype-php text-indigo-500',
        js: 'bi-filetype-js text-amber-500',
        ts: 'bi-filetype-tsx text-blue-600',
        jsx: 'bi-filetype-jsx text-cyan-500',
        tsx: 'bi-filetype-tsx text-cyan-600',
        vue: 'bi-filetype-vue text-emerald-500',
        html: 'bi-filetype-html text-orange-500',
        htm: 'bi-filetype-html text-orange-500',
        css: 'bi-filetype-css text-blue-500',
        scss: 'bi-filetype-scss text-pink-500',
        less: 'bi-filetype-css text-blue-400',
        json: 'bi-filetype-json text-amber-600',
        md: 'bi-filetype-md text-slate-600',
        txt: 'bi-file-earmark-text text-slate-500',
        log: 'bi-file-earmark-text text-slate-400',
        png: 'bi-file-earmark-image text-emerald-500',
        jpg: 'bi-file-earmark-image text-emerald-500',
        jpeg: 'bi-file-earmark-image text-emerald-500',
        gif: 'bi-file-earmark-image text-emerald-500',
        svg: 'bi-file-earmark-image text-emerald-500',
        webp: 'bi-file-earmark-image text-emerald-500',
        ico: 'bi-file-earmark-image text-emerald-500',
        zip: 'bi-file-earmark-zip text-amber-600',
        tar: 'bi-file-earmark-zip text-amber-600',
        gz: 'bi-file-earmark-zip text-amber-600',
        rar: 'bi-file-earmark-zip text-amber-600',
        '7z': 'bi-file-earmark-zip text-amber-600',
        pdf: 'bi-file-earmark-pdf text-red-500',
        doc: 'bi-file-earmark-word text-blue-600',
        docx: 'bi-file-earmark-word text-blue-600',
        xls: 'bi-file-earmark-excel text-green-600',
        xlsx: 'bi-file-earmark-excel text-green-600',
        csv: 'bi-filetype-csv text-green-500',
        ppt: 'bi-file-earmark-ppt text-orange-600',
        pptx: 'bi-file-earmark-ppt text-orange-600',
        sql: 'bi-database text-blue-500',
        db: 'bi-database text-blue-500',
        sqlite: 'bi-database text-blue-500',
        env: 'bi-file-earmark-lock text-slate-500',
        yml: 'bi-filetype-yml text-pink-500',
        yaml: 'bi-filetype-yml text-pink-500',
        xml: 'bi-filetype-xml text-orange-500',
        ini: 'bi-gear text-slate-500',
        conf: 'bi-gear text-slate-500',
        config: 'bi-gear text-slate-500',
        sh: 'bi-terminal text-emerald-600',
        bash: 'bi-terminal text-emerald-600',
        bat: 'bi-terminal text-slate-600',
        cmd: 'bi-terminal text-slate-600',
        ps1: 'bi-terminal text-blue-500',
        py: 'bi-filetype-py text-yellow-500',
        rb: 'bi-filetype-rb text-red-500',
        go: 'bi-filetype-go text-cyan-600',
        rs: 'bi-filetype-rs text-orange-600',
        java: 'bi-filetype-java text-red-600',
        c: 'bi-filetype-c text-blue-500',
        cpp: 'bi-filetype-cpp text-blue-500',
        h: 'bi-filetype-c text-blue-400',
        ai: 'bi-file-earmark-image text-orange-500',
        psd: 'bi-file-earmark-image text-blue-500',
        sketch: 'bi-file-earmark-image text-yellow-500',
        fig: 'bi-file-earmark-image text-purple-500',
        mp3: 'bi-file-earmark-music text-pink-500',
        wav: 'bi-file-earmark-music text-pink-500',
        mp4: 'bi-file-earmark-play text-purple-500',
        avi: 'bi-file-earmark-play text-purple-500',
        mov: 'bi-file-earmark-play text-purple-500',
        woff: 'bi-file-earmark-font text-slate-500',
        woff2: 'bi-file-earmark-font text-slate-500',
        ttf: 'bi-file-earmark-font text-slate-500',
        otf: 'bi-file-earmark-font text-slate-500',
    };

    return map[ext] || 'bi-file-earmark text-slate-400';
}

export function nameClassForItem(item) {
    if (item?.type === 'dir') return 'text-blue-600 dark:text-blue-400';
    return 'text-slate-800 dark:text-slate-200';
}

export function typeLabelForItem(item) {
    if (item?.type === 'dir') return 'folder';
    const ext = String(item?.name || '').split('.').pop()?.toLowerCase() || '';
    return ext || 'file';
}
