export function formatDate(value, withTime = false) {
    if (!value) return '—';

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    }).format(date);
}

export function formatMonth(value) {
    if (!value) return '';

    const [year, month] = String(value).split('-');

    return `${year}年${Number(month)}月`;
}

export function initials(name) {
    return (name ?? '?').slice(0, 1);
}

export function signedScore(score) {
    const value = Number(score ?? 0);

    return value > 0 ? `+${value}` : `${value}`;
}

export function groupScoreRange(min, max) {
    if (min === null || min === undefined || min === '') return '—';

    const low = Number(min);
    const high = Number(max ?? min);

    if (Number.isNaN(low) || Number.isNaN(high)) return '—';

    return low === high
        ? `每人 ${signedScore(low)} 分`
        : `每人 ${signedScore(low)} ~ ${signedScore(high)} 分`;
}

export function fileSize(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);

    return `${(bytes / 1024 ** index).toFixed(1)} ${units[index]}`;
}

export const sourceLabels = {
    personal: '个人申请',
    group: '集体申请',
    manual: '管理员录入',
};
