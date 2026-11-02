const ALLOWED_TAGS = new Set([
    'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'del',
    'ul', 'ol', 'li', 'blockquote', 'code', 'pre',
    'h1', 'h2', 'h3', 'h4',
    'a', 'span', 'div', 'section', 'article', 'header', 'footer',
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
]);

const DROP_TAGS = new Set([
    'script', 'style', 'iframe', 'object', 'embed', 'link', 'meta',
    'form', 'input', 'button', 'textarea', 'select', 'option',
    'svg', 'math', 'noscript', 'template',
]);

const ALLOWED_STYLES = new Set([
    'max-width', 'min-width', 'width', 'height', 'min-height', 'max-height',
    'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
    'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'background', 'background-color', 'background-image',
    'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
    'border-color', 'border-width', 'border-style', 'border-radius', 'box-shadow',
    'overflow', 'overflow-x', 'overflow-y',
    'position', 'top', 'right', 'bottom', 'left', 'z-index',
    'color', 'opacity',
    'font-family', 'font-size', 'font-weight', 'font-style', 'letter-spacing',
    'line-height', 'text-align', 'text-decoration', 'text-transform',
    'white-space', 'word-break', 'overflow-wrap', 'word-wrap',
    'display', 'flex', 'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink',
    'flex-basis', 'justify-content', 'align-items', 'align-self', 'align-content',
    'gap', 'row-gap', 'column-gap', 'order',
    'vertical-align', 'list-style', 'list-style-type', 'list-style-position',
]);

const DANGEROUS_STYLE =
    /url\s*\(|expression\s*\(|javascript:|vbscript:|@import|behavior\s*:|binding\s*:|image-set|cross-fade|\\|\/\*|\*\/|[\u0000-\u0008\u000b\u000c\u000e-\u001f]/i;

const HTML_PATTERN =
    /<\s*\/?\s*(?:p|br|hr|strong|b|em|i|u|s|del|ul|ol|li|blockquote|code|pre|h[1-4]|a|table|thead|tbody|tfoot|tr|th|td|caption|span|div|section|article|header|footer)\b[^>]*>/i;

export function isHtml(content = '') {
    return HTML_PATTERN.test(content);
}

function escapeText(text) {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function isSafeHref(value) {
    const compact = value.trim().replace(/[\u0000-\u0020\u007f]+/g, '').toLowerCase();
    return /^(https?:\/\/|mailto:|\/|#)/.test(compact);
}

function sanitizeStyle(style) {
    const declarations = [];

    style.split(';').forEach((declaration) => {
        const index = declaration.indexOf(':');
        if (index === -1) return;

        const property = declaration.slice(0, index).trim().toLowerCase();
        const value = declaration.slice(index + 1).trim();
        if (!property || !value) return;
        if (!ALLOWED_STYLES.has(property)) return;
        if (value.length > 300) return;
        if (/[<>{};\\]/.test(value) || DANGEROUS_STYLE.test(value)) return;

        const lower = value.toLowerCase();
        if (property === 'position' && !['relative', 'absolute'].includes(lower)) return;
        if (property === 'display' && !['flex', 'block', 'inline', 'inline-block', 'none', 'grid'].includes(lower)) return;
        if ((property === 'overflow' || property.startsWith('overflow-')) && !['visible', 'hidden', 'auto', 'scroll'].includes(lower)) return;
        if (property === 'z-index' && !/^-?\d{1,3}$/.test(value)) return;

        declarations.push(`${property}:${value}`);
    });

    return declarations.join(';');
}

function cleanElement(element, parent) {
    const tag = element.tagName.toLowerCase();

    if (DROP_TAGS.has(tag)) {
        element.remove();
        return;
    }

    if (!ALLOWED_TAGS.has(tag)) {
        const children = [...element.childNodes];
        const anchor = element.nextSibling;
        element.remove();
        children.forEach((child) => {
            parent.insertBefore(child, anchor);
            if (child.nodeType === Node.ELEMENT_NODE) {
                cleanElement(child, parent);
            }
        });
        return;
    }

    [...element.attributes].forEach((attribute) => {
        const name = attribute.name.toLowerCase();

        if (name === 'style') {
            const style = sanitizeStyle(attribute.value);
            if (style) {
                element.setAttribute('style', style);
            } else {
                element.removeAttribute('style');
            }
            return;
        }

        let allowed = false;

        if (tag === 'a' && (name === 'href' || name === 'title')) {
            allowed = name !== 'href' || isSafeHref(attribute.value);
        } else if ((tag === 'td' || tag === 'th') && (name === 'colspan' || name === 'rowspan')) {
            allowed = /^\d{1,3}$/.test(attribute.value.trim());
        }

        if (!allowed) {
            element.removeAttribute(attribute.name);
        }
    });

    if (tag === 'a' && element.getAttribute('href')) {
        element.setAttribute('rel', 'noopener noreferrer');
        if (/^https?:\/\//i.test(element.getAttribute('href'))) {
            element.setAttribute('target', '_blank');
        }
    }

    [...element.children].forEach((child) => cleanElement(child, element));
}

export function sanitizeHtml(content = '') {
    if (!content) return '';
    if (!isHtml(content)) {
        return escapeText(content).replace(/\r\n|\r|\n/g, '<br>');
    }

    const doc = new DOMParser().parseFromString(
        `<div id="__announcement_preview">${content}</div>`,
        'text/html',
    );
    const root = doc.getElementById('__announcement_preview');

    if (!root) return escapeText(content);

    [...root.children].forEach((child) => cleanElement(child, root));

    return root.innerHTML;
}

export function htmlToText(content = '') {
    if (!content) return '';
    if (!isHtml(content)) return content.replace(/\s+/g, ' ').trim();

    const doc = new DOMParser().parseFromString(content, 'text/html');
    doc.body.innerHTML = doc.body.innerHTML.replace(
        /<\s*(?:br|hr)\s*\/?>|<\s*\/\s*(?:p|div|section|article|header|footer|li|h[1-4]|tr|td|th|blockquote|pre)\s*>/gi,
        ' ',
    );

    return (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim();
}
